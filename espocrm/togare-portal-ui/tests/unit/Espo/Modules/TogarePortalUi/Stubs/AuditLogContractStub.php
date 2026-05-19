<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogarePortalUi\Stubs;

use Espo\Modules\TogareCore\Contracts\AuditLogContract;

/**
 * Spy de `AuditLogContract` (interface real do togare-core, autoloadável
 * via PSR-4) — captura chamadas em `$calls`. Usado para provar o evento
 * `portal.acesso_cruzado_tentado` da A4 (Story 7a.2) de forma determinística.
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
