<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Service\Wizard;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareRbac\Service\Invitation\InvitationService;
use Espo\Modules\TogareRbac\Service\Mfa\MfaPolicyResolver;
use Espo\ORM\EntityManager;

/**
 * WizardService — orquestra os 4 passos do wizard pós-primeiro-login (FR34).
 *
 * Cada método é idempotente. Audit log dual-write em `togare_audit_log`
 * (AuditLogContract) + TogareLogger JSON (R5 dual-write).
 *
 * Story 2.6 + patches code review 2026-04-26.
 */
final class WizardService
{
    public const MAX_INVITEES_PER_BATCH = 20;
    private const HEX_COLOR_REGEX = '/^#[0-9A-Fa-f]{6}$/';
    private const STARTED_DEDUP_WINDOW_SECONDS = 3600;
    private const DEFAULT_PRIMARY_COLOR = '#0a4d8c';

    /** P17: allowlist dos 8 roles seedados — evita exibir roles custom do admin. */
    private const SEEDED_ROLE_NAMES = [
        'Sócio/Admin',
        'Advogado',
        'Assistente/Estagiário',
        'Secretária',
        'Financeiro',
        'Marketing',
        'RH-lite',
        'Cliente-portal',
    ];

    /** P5: tipos MIME permitidos para logotipo. */
    private const ALLOWED_LOGO_MIMES = ['image/png', 'image/jpeg', 'image/jpg', 'image/svg+xml'];
    private const MAX_LOGO_SIZE_BYTES = 524288; // 512 KB

    public function __construct(
        private readonly EntityManager $em,
        private readonly Config $config,
        private readonly ConfigWriter $configWriter,
        private readonly AuditLogContract $auditLog,
        private readonly MfaPolicyResolver $mfaResolver,
        private readonly InvitationService $invitationService,
    ) {
    }

    /**
     * @return array{
     *   wizardCompleted: bool,
     *   wizardCompletedAt: ?string,
     *   companyName: ?string,
     *   companyLogoId: ?string,
     *   togarePrimaryColor: string,
     *   roles: list<array{id: string, name: string}>,
     *   currentStep: int
     * }
     */
    public function getState(User $user): array
    {
        $this->maybeLogStarted($user);

        $companyName = $this->config->get('companyName');
        $companyLogoId = $this->config->get('companyLogoId');
        $primaryColor = $this->config->get('togarePrimaryColor');

        // P24: validar no read path; fallback se valor corrompido.
        if (!\is_string($primaryColor) || $primaryColor === '' || \preg_match(self::HEX_COLOR_REGEX, $primaryColor) !== 1) {
            $primaryColor = self::DEFAULT_PRIMARY_COLOR;
        }

        return [
            'wizardCompleted' => (bool) $user->get('togareWizardCompleted'),
            'wizardCompletedAt' => $user->get('togareWizardCompletedAt'),
            'companyName' => \is_string($companyName) ? $companyName : null,
            'companyLogoId' => \is_string($companyLogoId) && $companyLogoId !== '' ? $companyLogoId : null,
            'togarePrimaryColor' => $primaryColor,
            'roles' => $this->fetchSeededRoles(),
            'currentStep' => $this->inferCurrentStep($companyName, $companyLogoId, $primaryColor),
        ];
    }

    /**
     * Passo 1 — Identidade do escritório.
     *
     * @throws BadRequest se companyName vazio, logo inexistente, MIME inválido ou tamanho excedido.
     */
    public function applyOrgInfo(User $user, string $companyName, ?string $companyLogoFileId): void
    {
        $companyName = \trim($companyName);
        if ($companyName === '') {
            throw BadRequest::createWithBody(
                'Informe o nome do escritório.',
                (string) \json_encode(['reason' => 'company_name_empty'], JSON_UNESCAPED_UNICODE),
            );
        }

        // P5: validar existência, MIME e tamanho do Attachment.
        if ($companyLogoFileId !== null && $companyLogoFileId !== '') {
            $attachment = $this->em->getEntityById('Attachment', $companyLogoFileId);
            if ($attachment === null) {
                throw BadRequest::createWithBody(
                    'Arquivo de logo não encontrado. Reenvie o logotipo.',
                    (string) \json_encode(['reason' => 'logo_attachment_not_found', 'fileId' => $companyLogoFileId], JSON_UNESCAPED_UNICODE),
                );
            }

            $mimeType = \is_object($attachment) && \method_exists($attachment, 'get')
                ? $attachment->get('type')
                : null;
            if (!\in_array($mimeType, self::ALLOWED_LOGO_MIMES, true)) {
                throw BadRequest::createWithBody(
                    'Tipo de arquivo inválido. Use PNG, JPG ou SVG.',
                    (string) \json_encode(['reason' => 'logo_invalid_mime', 'type' => $mimeType], JSON_UNESCAPED_UNICODE),
                );
            }

            $size = \is_object($attachment) && \method_exists($attachment, 'get')
                ? (int) $attachment->get('size')
                : 0;
            if ($size > self::MAX_LOGO_SIZE_BYTES) {
                throw BadRequest::createWithBody(
                    'Logotipo muito grande. Máximo: 512KB.',
                    (string) \json_encode(['reason' => 'logo_too_large', 'sizeBytes' => $size], JSON_UNESCAPED_UNICODE),
                );
            }
        }

        $changes = [];
        $current = $this->config->get('companyName');
        if ($current !== $companyName) {
            $this->configWriter->set('companyName', $companyName);
            $changes['companyName'] = ['from' => $current, 'to' => $companyName];
        }

        $currentLogo = $this->config->get('companyLogoId');
        if ($currentLogo !== $companyLogoFileId) {
            $this->configWriter->set('companyLogoId', $companyLogoFileId);
            $changes['companyLogoId'] = ['from' => $currentLogo, 'to' => $companyLogoFileId];
        }

        // P4: só persiste e loga step quando algo mudou (idempotência AC3).
        if ($changes !== []) {
            $this->configWriter->save();
            $this->logStep($user, 1, [
                'companyName' => $companyName,
                'companyLogoId' => $companyLogoFileId,
                'changes' => $changes,
            ]);
        }
    }

    /**
     * Passo 2 — Cor primária.
     *
     * @throws BadRequest se hex inválido.
     */
    public function applyPrimaryColor(User $user, string $hexColor): void
    {
        // P21: normalizar para minúsculas antes de validar e persistir.
        $hexColor = \strtolower($hexColor);

        if (\preg_match(self::HEX_COLOR_REGEX, $hexColor) !== 1) {
            throw BadRequest::createWithBody(
                'Cor inválida. Use formato #RRGGBB.',
                (string) \json_encode(['reason' => 'invalid_hex_color', 'value' => $hexColor], JSON_UNESCAPED_UNICODE),
            );
        }

        $current = $this->config->get('togarePrimaryColor');
        // P4: só persiste e loga step quando algo mudou.
        if ($current !== $hexColor) {
            $this->configWriter->set('togarePrimaryColor', $hexColor);
            $this->configWriter->save();
            $this->logStep($user, 2, [
                'togarePrimaryColor' => $hexColor,
                'previousColor' => \is_string($current) ? $current : null,
            ]);
        }
    }

    /**
     * Passo 3 — Confirmar/renomear roles.
     *
     * P7: pré-valida o mapa INTEIRO antes de qualquer save.
     * P6: captura PDOException em saveEntity para colisão concorrente.
     * P33: retorna renamed[] e skipped[] para feedback no frontend.
     * P35: naming wizard.role_renamed.skipped (padronizado).
     *
     * @param array<string, string> $roleRenameMap [oldName => newName, ...]
     * @return array{renamed: list<array{oldName: string, newName: string}>, skipped: list<string>}
     * @throws BadRequest se tentar renomear "Sócio/Admin", newName vazio ou colisão.
     */
    public function confirmRoles(User $user, array $roleRenameMap): array
    {
        // P7: Fase 1 — validar o mapa inteiro antes de qualquer persistência.
        $toProcess = [];
        $newNamesInBatch = [];

        foreach ($roleRenameMap as $oldName => $newName) {
            $oldName = (string) $oldName;
            $newName = \is_string($newName) ? \trim($newName) : '';

            if ($oldName === MfaPolicyResolver::ROLE_NAME_SOCIO_ADMIN) {
                throw BadRequest::createWithBody(
                    "O role 'Sócio/Admin' é reservado e não pode ser renomeado.",
                    (string) \json_encode(['reason' => 'role_socio_admin_reserved'], JSON_UNESCAPED_UNICODE),
                );
            }

            if ($oldName === $newName) {
                continue;
            }

            if ($newName === '') {
                throw BadRequest::createWithBody(
                    'Nome de role inválido ou já existe.',
                    (string) \json_encode(['reason' => 'role_new_name_empty', 'oldName' => $oldName], JSON_UNESCAPED_UNICODE),
                );
            }

            if (\in_array($newName, $newNamesInBatch, true)) {
                throw BadRequest::createWithBody(
                    'Nomes de role duplicados no mesmo lote.',
                    (string) \json_encode(['reason' => 'role_duplicate_new_names', 'newName' => $newName], JSON_UNESCAPED_UNICODE),
                );
            }

            $collision = $this->em->getRDBRepository('Role')
                ->where(['name' => $newName, 'deleted' => false])
                ->count();
            if ($collision > 0) {
                throw BadRequest::createWithBody(
                    'Nome de role inválido ou já existe.',
                    (string) \json_encode(['reason' => 'role_new_name_collision', 'newName' => $newName], JSON_UNESCAPED_UNICODE),
                );
            }

            $newNamesInBatch[] = $newName;
            $toProcess[] = ['old' => $oldName, 'new' => $newName];
        }

        // Fase 2: persistir (todas as validações já passaram).
        $renamed = [];
        $skipped = [];

        foreach ($toProcess as $entry) {
            $existing = $this->em->getRDBRepository('Role')
                ->where(['name' => $entry['old'], 'deleted' => false])
                ->findOne();

            if ($existing === null) {
                // P33/P35: role não encontrado — pode ter sido renomeado/deletado concorrentemente.
                $skipped[] = $entry['old'];
                TogareLogger::event(
                    'warning',
                    'wizard.role_renamed.skipped',
                    \sprintf("Role '%s' não encontrado — rename ignorado.", $entry['old']),
                    ['oldName' => $entry['old'], 'newName' => $entry['new']],
                );
                continue;
            }

            $existing->set('name', $entry['new']);
            try {
                // P6: captura colisão concorrente que passou pelo check acima.
                $this->em->saveEntity($existing);
            } catch (\PDOException $e) {
                throw BadRequest::createWithBody(
                    'Nome de role inválido ou já existe.',
                    (string) \json_encode(['reason' => 'role_new_name_collision_concurrent', 'newName' => $entry['new']], JSON_UNESCAPED_UNICODE),
                );
            }

            $actorId = $user->getId();
            $this->auditLog->log('wizard.role_renamed', 'Role', $existing->getId(), [
                'oldName' => $entry['old'],
                'newName' => $entry['new'],
                'actorUserId' => $actorId,
            ]);

            TogareLogger::event(
                'info',
                'wizard.role_renamed',
                \sprintf("Role '%s' renomeado para '%s'.", $entry['old'], $entry['new']),
                ['oldName' => $entry['old'], 'newName' => $entry['new'], 'actorUserId' => $actorId],
            );

            $renamed[] = ['oldName' => $entry['old'], 'newName' => $entry['new']];
        }

        $this->logStep($user, 3, [
            'renamed' => $renamed,
            'renamedCount' => \count($renamed),
            'skipped' => $skipped,
        ]);

        return ['renamed' => $renamed, 'skipped' => $skipped];
    }

    /**
     * Passo 4 — Convidar usuários em batch (≤ MAX_INVITEES_PER_BATCH).
     *
     * P22: detecta duplicatas dentro do lote antes de processar.
     * P23: captura \Throwable no loop (não só BadRequest).
     *
     * @param list<array{userName: string, emailAddress: string, firstName: string, lastName: string, roleIds: list<string>}> $invitees
     * @return array{succeeded: list<array{userName: string, userId: string}>, failed: list<array{userName: string, reason: string}>}
     */
    public function inviteBatch(User $user, array $invitees): array
    {
        $count = \count($invitees);

        if ($count > self::MAX_INVITEES_PER_BATCH) {
            throw BadRequest::createWithBody(
                \sprintf('Máximo de %d convites por lote. Convide os demais após concluir o wizard.', self::MAX_INVITEES_PER_BATCH),
                (string) \json_encode(['reason' => 'batch_size_exceeded', 'limit' => self::MAX_INVITEES_PER_BATCH, 'received' => $count], JSON_UNESCAPED_UNICODE),
            );
        }

        // P22: detectar duplicatas dentro do lote antes de processar qualquer linha.
        $seenUserNames = [];
        $seenEmails = [];
        foreach ($invitees as $idx => $invitee) {
            $uNorm = \strtolower(isset($invitee['userName']) ? (string) $invitee['userName'] : '');
            $eNorm = \strtolower(isset($invitee['emailAddress']) ? (string) $invitee['emailAddress'] : '');
            if ($uNorm !== '' && \in_array($uNorm, $seenUserNames, true)) {
                throw BadRequest::createWithBody(
                    \sprintf('Linha %d: userName duplicado no lote.', $idx + 1),
                    (string) \json_encode(['reason' => 'invitee_duplicate_username', 'index' => $idx], JSON_UNESCAPED_UNICODE),
                );
            }
            if ($eNorm !== '' && \in_array($eNorm, $seenEmails, true)) {
                throw BadRequest::createWithBody(
                    \sprintf('Linha %d: e-mail duplicado no lote.', $idx + 1),
                    (string) \json_encode(['reason' => 'invitee_duplicate_email', 'index' => $idx], JSON_UNESCAPED_UNICODE),
                );
            }
            if ($uNorm !== '') {
                $seenUserNames[] = $uNorm;
            }
            if ($eNorm !== '') {
                $seenEmails[] = $eNorm;
            }
        }

        // Validação fail-fast antes de processar.
        foreach ($invitees as $idx => $invitee) {
            $userName = isset($invitee['userName']) ? (string) $invitee['userName'] : '';
            $email = isset($invitee['emailAddress']) ? (string) $invitee['emailAddress'] : '';
            $roleIds = isset($invitee['roleIds']) && \is_array($invitee['roleIds']) ? $invitee['roleIds'] : [];

            if ($userName === '') {
                throw BadRequest::createWithBody(
                    \sprintf('Linha %d: nome de usuário obrigatório.', $idx + 1),
                    (string) \json_encode(['reason' => 'invitee_username_empty', 'index' => $idx], JSON_UNESCAPED_UNICODE),
                );
            }

            if (\filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw BadRequest::createWithBody(
                    \sprintf('Linha %d: e-mail inválido.', $idx + 1),
                    (string) \json_encode(['reason' => 'invitee_email_invalid', 'index' => $idx, 'email' => $email], JSON_UNESCAPED_UNICODE),
                );
            }

            if ($roleIds === []) {
                throw BadRequest::createWithBody(
                    \sprintf('Linha %d: selecione ao menos um role.', $idx + 1),
                    (string) \json_encode(['reason' => 'invitee_role_required', 'index' => $idx], JSON_UNESCAPED_UNICODE),
                );
            }
        }

        $succeeded = [];
        $failed = [];

        foreach ($invitees as $invitee) {
            $userName = (string) $invitee['userName'];
            try {
                $created = $this->invitationService->invite(
                    userName: $userName,
                    emailAddress: (string) $invitee['emailAddress'],
                    firstName: isset($invitee['firstName']) ? (string) $invitee['firstName'] : '',
                    lastName: isset($invitee['lastName']) ? (string) $invitee['lastName'] : '',
                    roleIds: \array_values(\array_map('strval', (array) $invitee['roleIds'])),
                );
                $createdId = $created->getId();
                $succeeded[] = ['userName' => $userName, 'userId' => $createdId ?? ''];
            } catch (BadRequest $e) {
                $failed[] = ['userName' => $userName, 'reason' => $e->getMessage()];
            } catch (\Throwable $e) {
                // P23: captura erros inesperados sem abortar o batch.
                $failed[] = ['userName' => $userName, 'reason' => 'unexpected_error'];
                TogareLogger::event('error', 'wizard.inviteBatch.error', $e->getMessage(), ['userName' => $userName]);
            }
        }

        $this->logStep($user, 4, [
            'invitedCount' => \count($succeeded),
            'failedCount' => \count($failed),
        ]);

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * Marca wizard como concluído (ou pulado).
     *
     * P8: idempotente — skip completo se flag já verdadeira.
     * P19: inclui durationSec no audit context.
     */
    public function markCompleted(User $user, bool $skipped = false): void
    {
        // P8: idempotência — evita re-save e re-audit.
        if ((bool) $user->get('togareWizardCompleted')) {
            return;
        }

        // P38: guard userId.
        $userId = $user->getId();
        if ($userId === null || $userId === '') {
            return;
        }

        // P19: duração desde wizard.started.
        $durationSec = $this->computeWizardDuration($userId);

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $user->set('togareWizardCompleted', true);
        $user->set('togareWizardCompletedAt', $now);
        $this->em->saveEntity($user);

        $event = $skipped ? 'wizard.skipped' : 'wizard.completed';

        $this->auditLog->log($event, 'User', $userId, [
            'skipped' => $skipped,
            'completedAt' => $now,
            'durationSec' => $durationSec,
        ]);

        TogareLogger::event(
            'info',
            $event,
            \sprintf("Wizard %s para userId=%s.", $skipped ? 'pulado' : 'concluído', $userId),
            ['userId' => $userId, 'skipped' => $skipped, 'durationSec' => $durationSec],
        );
    }

    /** P19: calcula duração em segundos desde wizard.started para o usuário. */
    private function computeWizardDuration(string $userId): ?int
    {
        try {
            $pdo = $this->em->getPDO();
            $stmt = $pdo->prepare(
                'SELECT occurred_at FROM togare_audit_log WHERE event = :event AND entity_type = :etype AND entity_id = :eid ORDER BY occurred_at ASC LIMIT 1'
            );
            $stmt->execute([':event' => 'wizard.started', ':etype' => 'User', ':eid' => $userId]);
            $startedAt = $stmt->fetchColumn();
            if ($startedAt === false) {
                return null;
            }
            $start = new \DateTimeImmutable((string) $startedAt, new \DateTimeZone('UTC'));
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            return (int) $now->getTimestamp() - $start->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * P17: retorna apenas os 8 roles seedados (allowlist) — ignora roles custom.
     *
     * @return list<array{id: string, name: string}>
     */
    private function fetchSeededRoles(): array
    {
        $rows = $this->em->getRDBRepository('Role')
            ->where(['deleted' => false, 'name' => self::SEEDED_ROLE_NAMES])
            ->order('name', 'ASC')
            ->find();

        $roles = [];
        foreach ($rows as $row) {
            $id = $row->getId();
            $name = $row->get('name');
            if (!\is_string($id) || !\is_string($name)) {
                continue;
            }
            $roles[] = ['id' => $id, 'name' => $name];
        }

        return $roles;
    }

    private function inferCurrentStep(mixed $companyName, mixed $companyLogoId, string $primaryColor): int
    {
        if (!\is_string($companyName) || $companyName === '' || $companyLogoId === null || $companyLogoId === '') {
            return 1;
        }
        if ($primaryColor === self::DEFAULT_PRIMARY_COLOR) {
            return 2;
        }

        return 3;
    }

    private function maybeLogStarted(User $user): void
    {
        // P37: skip se wizard já foi concluído.
        if ((bool) $user->get('togareWizardCompleted')) {
            return;
        }

        // P38: guard userId.
        $userId = $user->getId();
        if ($userId === null || $userId === '') {
            return;
        }

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-' . self::STARTED_DEDUP_WINDOW_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');

        try {
            $pdo = $this->em->getPDO();
            $stmt = $pdo->prepare(
                'SELECT 1 FROM togare_audit_log WHERE event = :event AND entity_type = :etype AND entity_id = :eid AND occurred_at >= :cutoff LIMIT 1'
            );
            $stmt->execute([
                ':event' => 'wizard.started',
                ':etype' => 'User',
                ':eid' => $userId,
                ':cutoff' => $cutoff,
            ]);
            if ($stmt->fetchColumn() !== false) {
                return;
            }
        } catch (\PDOException $e) {
            // P9: tabela pode não existir em testes — skip silencioso.
            return;
        } catch (\Throwable $e) {
            // P9: outros erros: logar warn mas prosseguir para registrar wizard.started.
            TogareLogger::event('warning', 'wizard.started.check.error', $e->getMessage(), ['userId' => $userId]);
        }

        $startedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $this->auditLog->log('wizard.started', 'User', $userId, [
            'startedAt' => $startedAt,
        ]);

        // P18: dual-write TogareLogger (conformidade R5).
        TogareLogger::event(
            'info',
            'wizard.started',
            \sprintf("Wizard iniciado para userId=%s.", $userId),
            ['userId' => $userId, 'startedAt' => $startedAt],
        );
    }

    /**
     * P38: logStep só executa se userId válido.
     *
     * @param array<string, mixed> $context
     */
    private function logStep(User $user, int $step, array $context): void
    {
        $userId = $user->getId();
        if ($userId === null || $userId === '') {
            return;
        }

        $payload = ['step' => $step, 'actorUserId' => $userId] + $context;

        $this->auditLog->log('wizard.step_completed', 'User', $userId, $payload);

        TogareLogger::event(
            'info',
            'wizard.step_completed',
            \sprintf("Wizard step %d concluído para userId=%s.", $step, $userId),
            $payload,
        );
    }
}
