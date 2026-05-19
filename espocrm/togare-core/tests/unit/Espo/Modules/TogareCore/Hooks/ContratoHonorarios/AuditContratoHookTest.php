<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Hooks\ContratoHonorarios\AuditContratoHook;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.1 — cobre AfterSave + AfterRemove order=50: emite eventos
 * contrato.created/modified/removed via AuditLogContract.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class AuditContratoHookTest extends TestCase
{
    public function testCreatedEventEmitidoEmIsNew(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::once())
            ->method('log')
            ->with(
                'contrato.created',
                'ContratoHonorarios',
                'contrato-001',
                self::callback(function (array $ctx): bool {
                    return $ctx['modalidade'] === 'fixo'
                        && $ctx['clienteId'] === 'cli-001'
                        && $ctx['filename'] === 'contrato.pdf';
                }),
            );

        $hook = new AuditContratoHook($audit);
        $c = new ContratoHonorarios();
        $c->set([
            'modalidade' => 'fixo',
            'valor' => 5000,
            'clienteId' => 'cli-001',
            'filename' => 'contrato.pdf',
            'mimeType' => 'application/pdf',
            'sizeBytes' => 12345,
            'fileStorageUri' => 'nextcloud://clientes/cli-001/contratos/contrato-001-contrato.pdf',
        ]);
        // makeNew: id setado via reflection para preservar new=true
        self::injectId($c, 'contrato-001');

        $hook->afterSave($c, SaveOptions::create());
    }

    public function testModifiedEventEmitidoQuandoSensitiveFieldMuda(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::once())
            ->method('log')
            ->with(
                'contrato.modified',
                'ContratoHonorarios',
                'contrato-002',
                self::callback(function (array $ctx): bool {
                    return \in_array('modalidade', $ctx['changedFields'], true);
                }),
            );

        $hook = new AuditContratoHook($audit);
        $c = new ContratoHonorarios();
        $c->setId('contrato-002'); // setId marca new=false
        $c->set('modalidade', 'exito');
        $c->setFetched('modalidade', 'fixo');
        $c->set('clienteId', 'cli-002');

        $hook->afterSave($c, SaveOptions::create());
    }

    public function testRemovedEventEmitido(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::once())
            ->method('log')
            ->with(
                'contrato.removed',
                'ContratoHonorarios',
                'contrato-003',
                self::callback(function (array $ctx): bool {
                    return $ctx['clienteId'] === 'cli-003' && $ctx['filename'] === 'foo.pdf';
                }),
            );

        $hook = new AuditContratoHook($audit);
        $c = new ContratoHonorarios();
        $c->setId('contrato-003');
        $c->set([
            'modalidade' => 'mensal',
            'clienteId' => 'cli-003',
            'filename' => 'foo.pdf',
        ]);

        $hook->afterRemove($c, RemoveOptions::create());
    }

    public function testModifiedSemSensitiveFieldMudancaNaoEmite(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::never())->method('log');

        $hook = new AuditContratoHook($audit);
        $c = new ContratoHonorarios();
        $c->setId('contrato-004');
        $c->set('clienteId', 'cli-004');
        $c->setFetched('clienteId', 'cli-004'); // sem mudança
        // Nenhum campo sensível foi tocado.

        $hook->afterSave($c, SaveOptions::create());
    }

    public function testEntidadeNaoContratoIgnora(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->expects(self::never())->method('log');

        $hook = new AuditContratoHook($audit);
        $other = new CoreEntity();
        $hook->afterSave($other, SaveOptions::create());
        $hook->afterRemove($other, RemoveOptions::create());
        self::assertTrue(true);
    }

    public function testAuditFalhaNaoBloqueiaFlow(): void
    {
        $audit = $this->createMock(AuditLogContract::class);
        $audit->method('log')->willThrowException(new \RuntimeException('Audit DB indisponível'));

        $hook = new AuditContratoHook($audit);
        $c = new ContratoHonorarios();
        $c->set('modalidade', 'fixo');
        $c->set('clienteId', 'cli-005');
        self::injectId($c, 'contrato-005');

        // Não deve re-lançar — audit falha silenciosa.
        $hook->afterSave($c, SaveOptions::create());

        self::assertTrue(true);
    }

    private static function injectId(\Espo\ORM\Entity $entity, string $id): void
    {
        $ref = new \ReflectionClass($entity);
        while ($ref !== false && ! $ref->hasProperty('id')) {
            $ref = $ref->getParentClass();
        }
        if ($ref === false) {
            return;
        }
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($entity, $id);
    }
}
