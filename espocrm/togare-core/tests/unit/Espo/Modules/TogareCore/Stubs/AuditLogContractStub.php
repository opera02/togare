<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Stubs;

use Espo\Modules\TogareCore\Contracts\AuditLogContract;

/**
 * Spy de `AuditLogContract` para testes — captura chamadas em `$calls`.
 *
 * Uso típico:
 *
 *   $stub = new AuditLogContractStub();
 *   $sut = new MeuHook($em, $stub);
 *   $sut->afterSave($entity, $opts);
 *   self::assertCount(1, $stub->calls);
 *   self::assertSame('user.created', $stub->calls[0]['event']);
 *
 * Para testar resiliência try/catch (Story 4a.3 AuditPrazoHook), set
 * `$stub->shouldThrow = true;` antes da chamada — `log()` lançará
 * `\RuntimeException` e incrementa `$throwCount`. Hook bem-comportado
 * deve capturar e logar via TogareLogger sem propagar.
 */
final class AuditLogContractStub implements AuditLogContract
{
    /** @var list<array{event: string, entityType: string, entityId: ?string, context: array<string, mixed>}> */
    public array $calls = [];

    public bool $shouldThrow = false;
    public int $throwCount = 0;

    public function log(
        string $event,
        string $entityType,
        ?string $entityId,
        array $context = [],
    ): void {
        if ($this->shouldThrow) {
            $this->throwCount++;
            throw new \RuntimeException('AuditLogContractStub: simulated failure');
        }

        $this->calls[] = [
            'event' => $event,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'context' => $context,
        ];
    }
}
