<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Service\Mfa;

use DateTimeImmutable;
use Espo\Core\Utils\PasswordHash;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;

/**
 * Gerencia backup codes MFA one-time-use para o fallback do TOTP.
 *
 * Formato: 8 chars do alfabeto sem ambiguidade visual (abcdefghkmnpqrstuvwxyz23456789),
 * separados em 2 blocos de 4 com hífen (ex.: "abcd-ef12").
 * Stored: bcrypt hash via PasswordHash injetado (cost 12 — TogarePasswordHash da 2.2).
 * Consumed: soft-delete (used=1, used_at=now). deleted=1 somente no regenerate.
 *
 * Story 2.3 — AC6, AC10.
 */
class BackupCodeService
{
    private const ALPHABET = 'abcdefghkmnpqrstuvwxyz23456789';
    private const CODE_CHARS = 8;

    public function __construct(
        private readonly EntityManager $em,
        private readonly PasswordHash $passwordHash,
        private readonly AuditLogContract $auditLog,
    ) {
    }

    /**
     * Gera $count novos backup codes para o usuário.
     *
     * @return list<string> códigos em plaintext com hífen (exibir uma única vez)
     */
    public function generate(User $user, int $count = 8): array
    {
        $userId = (string) $user->getId();
        $plainCodes = [];
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        for ($i = 0; $i < $count; $i++) {
            $raw = $this->generateRaw();
            $formatted = \substr($raw, 0, 4) . '-' . \substr($raw, 4, 4);
            // Normaliza para hash: remove hífen, lowercase
            $normalized = \strtolower(\str_replace('-', '', $raw));
            $hash = $this->passwordHash->hash($normalized);

            $entity = $this->em->getNewEntity('TogareMfaBackupCode');
            $entity->set([
                'id' => $this->generateId(),
                'userId' => $userId,
                'codeHash' => $hash,
                'used' => false,
                'usedAt' => null,
                'createdAt' => $now,
            ]);
            $this->em->saveEntity($entity, ['skipHooks' => true]);

            $plainCodes[] = $formatted;
        }

        TogareLogger::event(
            'info',
            'mfa.backup_codes.generated',
            \sprintf("Backup codes MFA gerados para user '%s'.", (string) $user->get('userName')),
            ['userId' => $userId, 'count' => $count],
        );

        // Dual-write em togare_audit_log (Story 2.4 — FR37).
        $this->auditLog->log(
            'mfa.backup_codes.generated',
            'User',
            $userId,
            ['userName' => $user->get('userName'), 'count' => $count],
        );

        return $plainCodes;
    }

    /**
     * Tenta consumir um backup code.
     *
     * @return bool true se o code foi válido e consumido; false caso contrário
     */
    public function consume(User $user, string $code): bool
    {
        $userId = (string) $user->getId();
        $normalized = \strtolower(\str_replace('-', '', $code));

        $rows = $this->em->getRDBRepository('TogareMfaBackupCode')
            ->where([
                'userId' => $userId,
                'deleted' => false,
                'used' => false,
            ])
            ->find();

        foreach ($rows as $row) {
            $storedHash = (string) $row->get('codeHash');
            if (! $this->passwordHash->verify($normalized, $storedHash)) {
                continue;
            }

            // Código válido — marcar como usado atomicamente via UPDATE WHERE used=0.
            // Previne race condition: se outro request já consumiu, rowCount=0 → continuar loop.
            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
            $stmt = $this->em->getPDO()->prepare(
                'UPDATE togare_mfa_backup_code SET used = 1, used_at = ? WHERE id = ? AND used = 0 AND deleted = 0'
            );
            $stmt->execute([$now, (string) $row->getId()]);
            if ($stmt->rowCount() === 0) {
                continue;
            }

            $remaining = $this->countRemaining($userId);

            TogareLogger::event(
                'info',
                'mfa.backup_codes.consumed',
                \sprintf("Backup code MFA consumido para user '%s'.", (string) $user->get('userName')),
                ['userId' => $userId, 'codeId' => $row->getId(), 'remaining' => $remaining],
            );

            // Dual-write (Story 2.4 — FR37).
            $this->auditLog->log(
                'mfa.backup_codes.consumed',
                'User',
                $userId,
                [
                    'userName' => $user->get('userName'),
                    'codeId' => (string) $row->getId(),
                    'remaining' => $remaining,
                ],
            );

            return true;
        }

        return false;
    }

    /**
     * Soft-deleta todos os codes ativos do usuário e gera novos.
     *
     * @return list<string> novos códigos em plaintext
     */
    public function regenerate(User $user, int $count = 8): array
    {
        $userId = (string) $user->getId();

        // Snapshot dos codes ativos antes de gerar os novos.
        $active = $this->em->getRDBRepository('TogareMfaBackupCode')
            ->where([
                'userId' => $userId,
                'deleted' => false,
            ])
            ->find();

        $activeRows = [];
        foreach ($active as $row) {
            $activeRows[] = $row;
        }

        // Gerar novos codes primeiro — se falhar, os antigos permanecem válidos (sem lockout).
        $newCodes = $this->generate($user, $count);

        // Só soft-delete os antigos após geração bem-sucedida.
        foreach ($activeRows as $row) {
            $row->set('deleted', true);
            $this->em->saveEntity($row, ['skipHooks' => true]);
        }

        TogareLogger::event(
            'info',
            'mfa.backup_codes.regenerated',
            \sprintf("Backup codes MFA regenerados para user '%s'.", (string) $user->get('userName')),
            ['userId' => $userId],
        );

        // Dual-write (Story 2.4 — FR37).
        $this->auditLog->log(
            'mfa.backup_codes.regenerated',
            'User',
            $userId,
            ['userName' => $user->get('userName'), 'count' => $count],
        );

        return $newCodes;
    }

    /**
     * @return array{total: int, used: int, remaining: int}
     */
    public function status(User $user): array
    {
        $userId = (string) $user->getId();

        $total = $this->em->getRDBRepository('TogareMfaBackupCode')
            ->where(['userId' => $userId, 'deleted' => false])
            ->count();

        $used = $this->em->getRDBRepository('TogareMfaBackupCode')
            ->where(['userId' => $userId, 'deleted' => false, 'used' => true])
            ->count();

        return [
            'total' => $total,
            'used' => $used,
            'remaining' => $total - $used,
        ];
    }

    private function generateRaw(): string
    {
        $alphabet = self::ALPHABET;
        $len = \strlen($alphabet);
        $result = '';
        for ($i = 0; $i < self::CODE_CHARS; $i++) {
            $result .= $alphabet[\random_int(0, $len - 1)];
        }
        return $result;
    }

    private function generateId(): string
    {
        // EspoCRM ID padrão: 17 chars hex
        return \substr(\bin2hex(\random_bytes(9)), 0, 17);
    }

    private function countRemaining(string $userId): int
    {
        return $this->em->getRDBRepository('TogareMfaBackupCode')
            ->where(['userId' => $userId, 'deleted' => false, 'used' => false])
            ->count();
    }
}
