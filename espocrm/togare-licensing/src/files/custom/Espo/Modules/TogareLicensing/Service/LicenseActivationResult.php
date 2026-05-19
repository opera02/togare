<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Service;

use DateTimeImmutable;

/**
 * Resultado de LicenseKeyService::activate(). Imutável.
 */
final readonly class LicenseActivationResult
{
    /**
     * @param list<string> $modulesActivated nomes dos módulos cujo status foi
     *                                       persistido como 'active' nesta operação
     */
    private function __construct(
        public bool $success,
        public array $modulesActivated,
        public ?DateTimeImmutable $expiresAt,
        public ?string $reason,
        public ?string $errorMessage,
    ) {
    }

    /**
     * @param list<string> $modulesActivated
     */
    public static function success(array $modulesActivated, DateTimeImmutable $expiresAt): self
    {
        return new self(
            success: true,
            modulesActivated: $modulesActivated,
            expiresAt: $expiresAt,
            reason: null,
            errorMessage: null,
        );
    }

    public static function invalid(string $reason, string $errorMessage): self
    {
        return new self(
            success: false,
            modulesActivated: [],
            expiresAt: null,
            reason: $reason,
            errorMessage: $errorMessage,
        );
    }
}
