<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use stdClass;

/**
 * Emite `config.security.changed` para chaves da allowlist que mudaram.
 *
 * Extraído de TogareSettingsService para ser testável unit: recebe oldValues
 * (snapshot pré-save) e newData (o payload da requisição) e emite 1 evento
 * por chave modificada. Não depende de Config ou SettingsService.
 */
final class SettingsSecurityAuditor
{
    public const SECURITY_KEYS = [
        'passwordStrengthLength',
        'passwordStrengthLetterCount',
        'passwordStrengthNumberCount',
        'passwordStrengthSpecialCharacterCount',
        'passwordStrengthBothCases',
        'passwordChangeRequestNewUserLifetime',
        'auth2FA',
        'auth2FAMethodList',
        'auth2FAInPortal',
        'auth2FAForced',
    ];

    /**
     * Subset de SECURITY_KEYS cujos valores devem ser mascarados em
     * `context_json`. Hoje vazio — as 10 chaves atuais são int/bool/array de
     * strings curtas, sem segredo. Antes de adicionar uma chave aqui,
     * confirmar que o valor crú nunca deve aparecer no audit log (ex.: token,
     * salt, hash). Quando preenchida, `maskValue()` substitui por
     * '[REDACTED]' antes da gravação.
     */
    public const REDACTED_KEYS = [];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    /**
     * @param array<string, mixed> $oldValues snapshot pré-save (chave → valor antigo)
     */
    public function auditChanges(array $oldValues, stdClass $newData): void
    {
        foreach ($oldValues as $key => $oldValue) {
            $newValue = property_exists($newData, $key) ? $newData->$key : null;
            if (self::valuesEqual($oldValue, $newValue)) {
                continue;
            }
            $this->auditLog->log(
                'config.security.changed',
                'Settings',
                null,
                [
                    'key' => $key,
                    'oldValue' => self::maskValue($key, $oldValue),
                    'newValue' => self::maskValue($key, $newValue),
                ],
            );
        }
    }

    /**
     * Comparação semântica:
     *  - arrays são comparados order-agnostic (sort + ==), evitando audit-spam
     *    quando ['Totp','Email'] vira ['Email','Totp'];
     *  - escalares usam loose equality (==), tolerando divergência legítima
     *    entre Config (int/bool armazenado) e payload de form (string
     *    equivalente como "8" vs 8 ou "1" vs true).
     */
    private static function valuesEqual(mixed $a, mixed $b): bool
    {
        if (\is_array($a) && \is_array($b)) {
            \sort($a);
            \sort($b);
            return $a == $b;
        }
        return $a == $b;
    }

    private static function maskValue(string $key, mixed $value): mixed
    {
        if (\in_array($key, self::REDACTED_KEYS, true)) {
            return $value === null ? null : '[REDACTED]';
        }
        return $value;
    }
}
