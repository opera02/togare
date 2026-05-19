<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Service;

/**
 * Resultado da validação de uma chave JWT pelo JwtValidator.
 *
 * Imutável. NUNCA carrega o JWT em si — só claims parseados (se válido) ou
 * motivo de falha (se inválido). NFR35 reforça: token é secret.
 *
 * Construa via factories: ::valid(), ::invalid().
 */
final readonly class JwtValidationResult
{
    public const REASON_INVALID_SIGNATURE = 'invalid_signature';
    public const REASON_EXPIRED = 'expired';
    public const REASON_MALFORMED = 'malformed';
    public const REASON_WRONG_ISSUER = 'wrong_issuer';

    /**
     * @param array<string, mixed> $claims claims parseados (apenas se isValid)
     */
    private function __construct(
        public bool $isValid,
        public ?string $reason,
        public array $claims,
        public ?string $errorMessage,
    ) {
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function valid(array $claims): self
    {
        return new self(
            isValid: true,
            reason: null,
            claims: $claims,
            errorMessage: null,
        );
    }

    public static function invalid(string $reason, string $errorMessage): self
    {
        return new self(
            isValid: false,
            reason: $reason,
            claims: [],
            errorMessage: $errorMessage,
        );
    }
}
