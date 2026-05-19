<?php

declare(strict_types=1);

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Entities\PortalRole;
use Espo\Modules\TogarePortalUi\Tools\PortalAccess\ProvisionService;
use Espo\ORM\EntityManager;

/**
 * Hook de instalação do togare-portal-ui (Story 7a.1).
 *
 * Faz duas coisas idempotentes:
 *
 *  1. Seeda os DEFAULTS CURADOS do PortalSplash em config global
 *     (togarePortalSplashPrimaryColor + togarePortalSplashWelcome) —
 *     SÓ quando ausentes; nunca sobrescreve customização do admin.
 *     Assim o splash já tem branding válido (nunca campo vazio) mesmo
 *     antes do Sócio/Admin abrir "Portal → Aparência" (AC1).
 *
 *  2. Publica o layout do painel admin para a entity STOCK `Settings`
 *     em `data/layouts/Settings/portalAppearance.json`. Settings é
 *     entity STOCK do EspoCRM — layout de stock entity NÃO é resolvido
 *     a partir de `Resources/layouts` de módulo (bug B25 / memória
 *     feedback_espocrm_layout_data_precedence). Tem que ir para `data/`.
 *     Idempotente: só (re)escreve se ausente ou divergente.
 *
 *  3. (Story 7a.2) Semeia o PortalRole canônico
 *     `Cliente do Portal (Togare)` concedendo SOMENTE `read:own` em
 *     `Processo` (create/edit/delete = no). É esse nível `own` que aciona
 *     o filtro `PortalOnlyCliente` (list/search/related) e o
 *     `OwnershipChecker` (by-id + audit cross-cliente) — A4. Idempotente:
 *     cria se ausente; se presente, garante a entrada de `Processo` sem
 *     duplicar o papel nem sobrescrever ajustes manuais de outras scopes.
 *
 * Config singleton (A6 N/A). O PortalRole é semeado aqui (não migration)
 * e é idempotente (reinstalação não duplica) — pattern validado
 * togare-core 6.3/6.5 + 7a.1.
 */
class AfterInstall
{
    private const DEFAULT_COLOR = '#0d47a1';
    private const DEFAULT_WELCOME = 'Olá. Aqui você acompanha o andamento do seu processo.';

    public function run(Container $container): void
    {
        $this->ensureCuratedDefaults($container);
        $this->ensurePortalAppearanceLayout();
        $this->ensurePortalRole($container);
        $this->ensureRecoveryRequestLifetimeValid($container);
    }

    /**
     * Normaliza `passwordChangeRequest{NewUser,ExistingUser}Lifetime`
     * (Story 7a.2 — bug do smoke browser Felipe 2026-05-17).
     *
     * O core do EspoCRM faz `DateTime::modify('+' . $lifetime)`. Se o
     * valor for um NÚMERO PURO (ex.: `168`), `modify('+168')` é formato
     * INVÁLIDO → o offset não é aplicado → o job `RemoveRecoveryRequest`
     * é agendado para AGORA → o daemon apaga a solicitação de senha no
     * mesmo segundo em que ela é criada (link nasce morto, "solicitação
     * não encontrada"). Quebra QUALQUER convite de novo usuário, não só
     * o Togare.
     *
     * Idempotente e conservador: só reescreve quando o valor NÃO é um
     * modificador relativo válido do PHP. Número puro `N` → `'N hours'`
     * (preserva a intenção de quem digitou "168" pensando em horas);
     * vazio/ausente/inválido → `'7 days'`. Valores já válidos (ex.:
     * `'2 days'`) são preservados intactos.
     */
    private function ensureRecoveryRequestLifetimeValid(Container $container): void
    {
        try {
            $config = $container->getByClass(Config::class);
            $injectableFactory = $container->getByClass(InjectableFactory::class);
            $configWriter = $injectableFactory->create(ConfigWriter::class);
        } catch (\Throwable $e) {
            echo "[togare-portal-ui] AVISO: ConfigWriter indisponível — verifique manualmente passwordChangeRequestNewUserLifetime (deve ser tipo '7 days', não '168').\n";
            return;
        }

        $keys = [
            'passwordChangeRequestNewUserLifetime' => '7 days',
            'passwordChangeRequestExistingUserLifetime' => '2 days',
        ];

        $changed = false;

        foreach ($keys as $key => $fallback) {
            $value = $config->get($key);

            if ($value !== null && $value !== '' && $this->isValidRelativeTime((string) $value)) {
                continue; // já válido — preserva
            }

            $normalized = $fallback;

            if (is_numeric($value) && (int) $value > 0) {
                // Intenção mais provável: número de HORAS (ex.: 168 = 7 dias).
                $normalized = ((int) $value) . ' hours';
            }

            $configWriter->set($key, $normalized);
            $changed = true;

            echo "[togare-portal-ui] Corrigido $key: "
                . var_export($value, true) . " → '$normalized' "
                . "(valor inválido p/ DateTime::modify quebrava o link de senha).\n";
        }

        if (! $changed) {
            echo "[togare-portal-ui] passwordChangeRequest*Lifetime já válidos — skip (idempotente).\n";
            return;
        }

        try {
            $configWriter->save();
        } catch (\Throwable $e) {
            echo "[togare-portal-ui] AVISO: falha ao salvar correção de lifetime: " . $e->getMessage() . "\n";
        }
    }

    /**
     * `true` se `$v` é um modificador relativo aceito por
     * `DateTime::modify('+' . $v)` (ex.: "7 days", "168 hours", "3 hours").
     * Número puro ("168") NÃO é válido.
     */
    private function isValidRelativeTime(string $v): bool
    {
        $v = trim($v);

        if ($v === '' || is_numeric($v)) {
            return false;
        }

        try {
            $dt = new \DateTime('now');
            $before = $dt->getTimestamp();
            // @ suprime warning; modify() retorna false em formato inválido.
            $result = @$dt->modify('+' . $v);

            return $result !== false && $dt->getTimestamp() !== $before;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Semeia/garante o PortalRole canônico do Portal do Cliente (Story
     * 7a.2, A4). Idempotente: nunca duplica o papel; só (re)garante a
     * permissão mínima `Processo: read=own` (create/edit/delete=no).
     */
    private function ensurePortalRole(Container $container): void
    {
        try {
            $entityManager = $container->getByClass(EntityManager::class);
        } catch (\Throwable $e) {
            echo "[togare-portal-ui] AVISO: EntityManager indisponível — crie o PortalRole 'Cliente do Portal (Togare)' manualmente (read:own em Processo).\n";
            return;
        }

        $name = ProvisionService::PORTAL_ROLE_NAME;

        $desiredProcesso = (object) [
            'read' => 'own',
            'create' => 'no',
            'edit' => 'no',
            'delete' => 'no',
        ];

        try {
            /** @var PortalRole|null $role */
            $role = $entityManager
                ->getRDBRepository(PortalRole::ENTITY_TYPE)
                ->where(['name' => $name])
                ->findOne();

            if (!$role) {
                /** @var PortalRole $role */
                $role = $entityManager->getRDBRepository(PortalRole::ENTITY_TYPE)->getNew();
                $role->set('name', $name);
                $role->set('data', (object) ['Processo' => $desiredProcesso]);
                $entityManager->saveEntity($role);

                echo "[togare-portal-ui] PortalRole '$name' criado (read:own em Processo).\n";

                return;
            }

            $data = $role->get('data');
            $data = is_object($data) ? $data : (object) [];

            $current = $data->Processo ?? null;

            if (
                is_object($current) &&
                ($current->read ?? null) === 'own' &&
                ($current->create ?? null) === 'no' &&
                ($current->edit ?? null) === 'no' &&
                ($current->delete ?? null) === 'no'
            ) {
                echo "[togare-portal-ui] PortalRole '$name' já presente e correto — skip (idempotente).\n";

                return;
            }

            $data->Processo = $desiredProcesso;
            $role->set('data', $data);
            $entityManager->saveEntity($role);

            echo "[togare-portal-ui] PortalRole '$name' atualizado (garantida permissão read:own em Processo).\n";
        } catch (\Throwable $e) {
            echo "[togare-portal-ui] AVISO: falha ao semear o PortalRole '$name': " . $e->getMessage() . "\n";
        }
    }

    /**
     * Seeda defaults curados só quando ausentes (preserva admin).
     */
    private function ensureCuratedDefaults(Container $container): void
    {
        try {
            $config = $container->getByClass(Config::class);
            $injectableFactory = $container->getByClass(InjectableFactory::class);
            $configWriter = $injectableFactory->create(ConfigWriter::class);
        } catch (\Throwable $e) {
            echo "[togare-portal-ui] AVISO: ConfigWriter indisponível — configure o PortalSplash manualmente em Admin → Portal → Aparência.\n";
            return;
        }

        $changed = false;

        $currentColor = $config->get('togarePortalSplashPrimaryColor');
        if ($currentColor === null || $currentColor === '') {
            $configWriter->set('togarePortalSplashPrimaryColor', self::DEFAULT_COLOR);
            $changed = true;
        }

        $currentWelcome = $config->get('togarePortalSplashWelcome');
        if ($currentWelcome === null || $currentWelcome === '') {
            $configWriter->set('togarePortalSplashWelcome', self::DEFAULT_WELCOME);
            $changed = true;
        }

        if (! $changed) {
            echo "[togare-portal-ui] Defaults do PortalSplash já presentes — skip (customização preservada).\n";
            return;
        }

        try {
            $configWriter->save();
            echo "[togare-portal-ui] Defaults curados do PortalSplash seedados (cor + frase de boas-vindas).\n";
        } catch (\Throwable $e) {
            echo "[togare-portal-ui] AVISO: falha ao salvar defaults do PortalSplash: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Publica o layout do painel admin (stock entity Settings → data/).
     */
    private function ensurePortalAppearanceLayout(): void
    {
        $candidates = [
            'custom/Espo/Modules/TogarePortalUi/Resources/layouts/Settings/portalAppearance.json',
            '/var/www/html/custom/Espo/Modules/TogarePortalUi/Resources/layouts/Settings/portalAppearance.json',
        ];

        $sourceFile = null;
        foreach ($candidates as $c) {
            if (is_file($c)) {
                $sourceFile = $c;
                break;
            }
        }

        if ($sourceFile === null) {
            echo "[togare-portal-ui] AVISO: layout portalAppearance fonte não encontrado — painel admin pode não renderizar os campos.\n";
            return;
        }

        $source = file_get_contents($sourceFile);
        if ($source === false) {
            echo "[togare-portal-ui] AVISO: não foi possível ler o layout portalAppearance fonte.\n";
            return;
        }

        $destDir = 'data/layouts/Settings';
        $destFile = $destDir . '/portalAppearance.json';

        if (! is_dir($destDir)) {
            $destDir2 = '/var/www/html/data/layouts/Settings';
            if (is_dir('/var/www/html/data')) {
                $destDir = $destDir2;
                $destFile = $destDir . '/portalAppearance.json';
            }
        }

        if (is_file($destFile) && file_get_contents($destFile) === $source) {
            echo "[togare-portal-ui] Layout portalAppearance já publicado e idêntico — skip.\n";
            return;
        }

        if (! is_dir($destDir)) {
            if (! @mkdir($destDir, 0775, true) && ! is_dir($destDir)) {
                echo "[togare-portal-ui] AVISO: não foi possível criar $destDir — publique o layout manualmente.\n";
                return;
            }
        }

        if (@file_put_contents($destFile, $source) === false) {
            echo "[togare-portal-ui] AVISO: falha ao publicar o layout portalAppearance em $destFile.\n";
            return;
        }

        echo "[togare-portal-ui] Layout portalAppearance publicado em $destFile.\n";
    }
}
