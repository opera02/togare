<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Stubs;

use Espo\Modules\TogareCore\Contracts\AuditLogContract;

/**
 * Spy de `AuditLogContract` para testes da togare-rbac (Story 2.4 dual-write).
 * Duplicado do stub da togare-core porque cada módulo tem PSR-4 isolado em
 * tests/unit (autoload-dev).
 */
final class AuditLogContractStub implements AuditLogContract
{
    /** @var list<array{event: string, entityType: string, entityId: ?string, context: array<string, mixed>}> */
    public array $calls = [];

    public function log(
        string $event,
        string $entityType,
        ?string $entityId,
        array $context = [],
    ): void {
        $this->calls[] = [
            'event' => $event,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'context' => $context,
        ];
    }
}
