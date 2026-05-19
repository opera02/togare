<?php

declare(strict_types=1);

namespace Espo\Modules\TogarePortalUi\Tools\PortalAccess;

use Espo\Core\Mail\EmailFactory;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\Portal;
use Espo\Entities\PortalRole;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\ORM\EntityManager;
use Espo\Tools\UserSecurity\Password\RecoveryService;
use Psr\Log\LoggerInterface;

/**
 * Provisionamento de acesso ao Portal do Cliente (Story 7a.2, FR26).
 *
 * Mecanismo: LINK ÚNICO NATIVO (decisão Felipe 2026-05-17). Não gera nem
 * envia senha. Cria/reusa o usuário de Portal vinculado ao `Cliente`,
 * gera um PasswordChangeRequest NATIVO do EspoCRM (link de uso único) e
 * envia um e-mail pt-BR (copy aprovada no Gate A2) com esse link. O
 * cliente clica → tela NATIVA `?entryPoint=changePassword` (NoAuth) →
 * define a própria senha (hashing 100% nativo) → autentica no PortalSplash.
 *
 * Resiliência (CLAUDE.md): a falha de envio de e-mail NUNCA aborta a
 * criação do acesso — o acesso fica criado, o link existe e pode ser
 * reenviado. NENHUMA senha em claro é gerada/persistida/logada (NFR8).
 *
 * A5 (retro Épico 6 — 1 Controller fino): exposto por Api action class
 * própria do módulo (`Api\PostProvision`, pattern nativo
 * `Tools/UserSecurity/Api/*`); não toca o `Controllers/Cliente.php` do
 * togare-core.
 */
class ProvisionService
{
    /** Nome canônico do PortalRole seedado pelo AfterInstall. */
    public const PORTAL_ROLE_NAME = 'Cliente do Portal (Togare)';

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly RecoveryService $recoveryService,
        private readonly EmailFactory $emailFactory,
        private readonly EmailSender $emailSender,
        private readonly Config $config,
        private readonly Language $defaultLanguage,
        private readonly AuditLogContract $auditLog,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return array{userId: string, userName: string, requestId: string, link: string, emailSent: bool, reused: bool}
     *
     * @throws NotFound  Cliente inexistente.
     * @throws Error     Pré-condições de plataforma ausentes (Portal/Role)
     *                   ou Cliente sem e-mail.
     */
    public function provisionForCliente(string $clienteId): array
    {
        $cliente = $this->entityManager->getEntityById('Cliente', $clienteId);

        if (!$cliente) {
            throw new NotFound("Cliente não encontrado.");
        }

        $emailAddress = trim((string) $cliente->get('email'));

        if ($emailAddress === '') {
            throw new Error("O Cliente não tem e-mail cadastrado — cadastre um e-mail antes de liberar o acesso ao Portal.");
        }

        $portal = $this->resolvePortal();
        $portalRole = $this->resolvePortalRole();

        [$user, $reused] = $this->findOrCreatePortalUser($cliente, $emailAddress, $portal, $portalRole);

        // URL do Portal (login SPA) — usada como `url` do request para o
        // redirect pós-criação de senha levar o cliente ao Portal.
        $portalUrl = $this->resolvePortalUrl($portal);

        // PasswordChangeRequest NATIVO: salva o request + agenda o job de
        // limpeza; NÃO envia e-mail (enviamos o nosso, pt-BR, best-effort).
        $request = $this->recoveryService->createRequestForNewUser($user, $portalUrl);

        $requestId = $request->getRequestId();

        // CRÍTICO (bug smoke browser Felipe 2026-05-17): o entry point
        // `changePassword` (NoAuth, página standalone) tem de ser servido
        // pela RAIZ DO SITE — `{siteUrl}/?entryPoint=changePassword&id=…`.
        // O core do EspoCRM usa `$portal->getUrl()` para portal users
        // assumindo deploy de domínio único; no Togare o `/portal/<id>` é
        // reverse-proxied (Caddy → Apache) e NÃO serve entry point:
        //  - `…/portal/<id>/?entryPoint=…` → loop de redirect → HTTP 414;
        //  - `…/portal/<id>?entryPoint=…`  → Caddy reescreve, perde o
        //    entryPoint → 404 + assets em `/portal/client/`.
        // Provado por HTTP: só a raiz (`{siteUrl}/?entryPoint=…`) responde
        // 200 com `data-base-path=""` e assets em `client/lib/` corretos.
        // O contexto de Portal NÃO é necessário nessa página (NoAuth); o
        // login do Portal é passo separado (link + {userName} no e-mail).
        $siteUrlRoot = rtrim((string) $this->config->get('siteUrl'), '/');

        if ($siteUrlRoot === '') {
            throw new Error("siteUrl não configurado — não é possível montar o link de criação de senha.");
        }

        $link = $siteUrlRoot . '/?entryPoint=changePassword&id=' . $requestId;
        $userName = (string) $user->get('userName');

        $emailSent = $this->sendProvisionEmailBestEffort(
            $emailAddress,
            (string) ($cliente->get('name') ?: $emailAddress),
            $userName,
            $link,
        );

        $this->auditLog->log(
            'portal.acesso_provisionado',
            'Cliente',
            $clienteId,
            [
                'portalUserId' => $user->getId(),
                'reused' => $reused,
                'emailSent' => $emailSent,
            ],
        );

        return [
            'userId' => $user->getId(),
            'userName' => $userName,
            'requestId' => $requestId,
            'link' => $link,
            'emailSent' => $emailSent,
            'reused' => $reused,
        ];
    }

    private function resolvePortal(): Portal
    {
        $portal = $this->entityManager
            ->getRDBRepository(Portal::ENTITY_TYPE)
            ->where(['isActive' => true])
            ->order('createdAt')
            ->findOne();

        if (!$portal) {
            throw new Error("Nenhum Portal ativo configurado. Configure o Portal do Cliente antes de liberar acessos.");
        }

        /** @var Portal */
        return $portal;
    }

    private function resolvePortalRole(): PortalRole
    {
        $role = $this->entityManager
            ->getRDBRepository(PortalRole::ENTITY_TYPE)
            ->where(['name' => self::PORTAL_ROLE_NAME])
            ->findOne();

        if (!$role) {
            throw new Error(
                "PortalRole '" . self::PORTAL_ROLE_NAME . "' ausente. " .
                "Reinstale/atualize o módulo togare-portal-ui (o AfterInstall semeia esse papel)."
            );
        }

        /** @var PortalRole */
        return $role;
    }

    /**
     * Idempotente: se o Cliente já tem um usuário de Portal vinculado,
     * reusa-o (re-provisionar = reenviar o link). Senão cria um novo.
     *
     * @return array{0: User, 1: bool} [user, reused]
     */
    private function findOrCreatePortalUser(
        \Espo\ORM\Entity $cliente,
        string $emailAddress,
        Portal $portal,
        PortalRole $portalRole,
    ): array {
        $existing = $this->entityManager
            ->getRDBRepository(User::ENTITY_TYPE)
            ->where([
                'togareClienteId' => $cliente->getId(),
                'type' => User::TYPE_PORTAL,
                'isActive' => true,
            ])
            ->findOne();

        if ($existing) {
            $this->entityManager
                ->getRDBRepository(User::ENTITY_TYPE)
                ->getRelation($existing, 'portals')
                ->relate($portal);

            $this->entityManager
                ->getRDBRepository(User::ENTITY_TYPE)
                ->getRelation($existing, 'portalRoles')
                ->relate($portalRole);

            /** @var User $existing */
            return [$existing, true];
        }

        /** @var User $user */
        $user = $this->entityManager->getRDBRepository(User::ENTITY_TYPE)->getNew();

        $user->set([
            'userName' => $this->generateUniqueUserName($emailAddress),
            'name' => (string) ($cliente->get('name') ?: $emailAddress),
            'type' => User::TYPE_PORTAL,
            'isActive' => true,
            'emailAddress' => $emailAddress,
            'togareClienteId' => $cliente->getId(),
            'portalsIds' => [$portal->getId()],
            'portalRolesIds' => [$portalRole->getId()],
        ]);

        // Sem 'password': o usuário define a senha pela tela nativa de
        // criação (hashing nativo). NFR8 — nada em claro.
        $this->entityManager->saveEntity($user);

        return [$user, false];
    }

    private function generateUniqueUserName(string $emailAddress): string
    {
        $base = strtolower((string) preg_replace('/[^a-z0-9._-]/i', '', explode('@', $emailAddress)[0]));

        if ($base === '') {
            $base = 'cliente';
        }

        $candidate = $base;
        $i = 1;

        while (
            $this->entityManager
                ->getRDBRepository(User::ENTITY_TYPE)
                ->where(['userName' => $candidate])
                ->findOne()
        ) {
            $candidate = $base . '.' . $i;
            $i++;
        }

        return $candidate;
    }

    private function resolvePortalUrl(Portal $portal): string
    {
        // Core (RecoveryService::send) chama loadUrlField ANTES de getUrl()
        // — sem isso a URL pode vir null e cai-se num fallback que ignora
        // customUrl/customId do Portal. Replicar para o link bater 100%
        // com o que o EspoCRM nativo geraria.
        try {
            $this->entityManager
                ->getRDBRepositoryByClass(Portal::class)
                ->loadUrlField($portal);
        } catch (\Throwable $e) {
            // getter abaixo ainda tenta lazy-load; segue para fallback.
        }

        $url = $portal->getUrl();

        if (is_string($url) && $url !== '') {
            return $url;
        }

        $siteUrl = (string) $this->config->get('siteUrl');

        if ($siteUrl === '') {
            throw new Error("siteUrl não configurado — não é possível montar o link de acesso ao Portal.");
        }

        return rtrim($siteUrl, '/') . '/portal/' . $portal->getId();
    }

    /**
     * Envia o e-mail de provisionamento (copy A2, pt-BR) de forma
     * best-effort: qualquer falha é registrada e o método retorna false,
     * mas NUNCA propaga exceção (o acesso já está criado; o link pode ser
     * reenviado). CLAUDE.md: nunca bloquear o fluxo por dependência externa.
     */
    private function sendProvisionEmailBestEffort(
        string $emailAddress,
        string $clienteName,
        string $userName,
        string $link,
    ): bool {
        try {
            $office = (string) ($this->config->get('siteName')
                ?: $this->config->get('companyName')
                ?: 'o escritório');

            $phone = (string) ($this->config->get('togarePortalSplashPhone') ?: '');

            $l = $this->defaultLanguage;

            $subject = str_replace(
                '{escritorio}',
                $office,
                $l->translateLabel('provisionEmailSubject', 'messages', 'PortalAccess'),
            );

            $body = strtr(
                $l->translateLabel('provisionEmailBody', 'messages', 'PortalAccess'),
                [
                    '{nome}' => $clienteName,
                    '{escritorio}' => $office,
                    '{userName}' => $userName,
                    '{link}' => $link,
                    '{telefone}' => $phone !== '' ? $phone : $l->translateLabel(
                        'provisionEmailPhoneFallback',
                        'messages',
                        'PortalAccess',
                    ),
                ],
            );

            $email = $this->emailFactory->create();
            $email->setSubject($subject);
            $email->setBody($body);
            $email->addToAddress($emailAddress);
            $email->set(['isSystem' => true]);

            $this->emailSender->send($email);

            return true;
        } catch (\Throwable $e) {
            $this->log->error(
                '[togare-portal-ui] Falha best-effort ao enviar e-mail de provisionamento de Portal: '
                . $e->getMessage()
            );

            $this->auditLog->log(
                'portal.acesso_provisionado_email_falhou',
                'User',
                null,
                ['erro' => $e->getMessage()],
            );

            return false;
        }
    }
}
