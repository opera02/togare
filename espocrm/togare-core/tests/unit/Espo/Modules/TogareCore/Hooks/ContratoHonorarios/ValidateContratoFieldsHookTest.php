<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Hooks\ContratoHonorarios\ValidateContratoFieldsHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.1 — testa todas as validações do ValidateContratoFieldsHook
 * (BeforeSave order=10): cliente obrigatório + modalidade + valor/percentual +
 * vigência + processos cross-cliente + link immutable + PDF only.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ValidateContratoFieldsHookTest extends TestCase
{
    public function testClienteAusenteLancaBadRequest(): void
    {
        $hook = $this->makeHook();
        $c = $this->makeNew([
            'modalidade' => 'fixo',
            'valor' => 5000,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $this->expectException(BadRequest::class);
        $hook->beforeSave($c, SaveOptions::create());
    }

    public function testModalidadeFixoSemValorLancaBadRequest(): void
    {
        $hook = $this->makeHook();
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'fixo',
            'valor' => null,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest valorRequiredForModalidade não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('valorRequiredForModalidade', $body['reason']);
        }
    }

    public function testModalidadeExitoSemPercentualLancaBadRequest(): void
    {
        $hook = $this->makeHook();
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'exito',
            'percentual' => null,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest percentualRequiredForModalidade não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('percentualRequiredForModalidade', $body['reason']);
        }
    }

    public function testModalidadeMistoSemAmbosLancaBadRequest(): void
    {
        $hook = $this->makeHook();
        // Modalidade misto exige AMBOS valor + percentual.
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'misto',
            'valor' => 1000,
            // percentual ausente!
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $this->expectException(BadRequest::class);
        $hook->beforeSave($c, SaveOptions::create());
    }

    public function testPercentualForaDoIntervaloLancaBadRequest(): void
    {
        $hook = $this->makeHook();
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'exito',
            'percentual' => 150,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest percentualOutOfRange não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('percentualOutOfRange', $body['reason']);
        }
    }

    public function testVigenciaFimAntesInicioLancaBadRequest(): void
    {
        $hook = $this->makeHook();
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'fixo',
            'valor' => 5000,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-06-01',
            'vigenciaFim' => '2026-01-15', // antes do início!
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest vigenciaFimAntesInicio não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('vigenciaFimAntesInicio', $body['reason']);
        }
    }

    public function testVigenciaFimNullContratoAbertoOk(): void
    {
        // vigenciaFim NULL é permitido (Decisão #8 da spec — contratos abertos).
        $attachment = $this->makeAttachment('contrato.pdf', 'application/pdf', 50000);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($attachment);

        $hook = $this->makeHook($em);
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'exito',
            'percentual' => 20,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'vigenciaFim' => null,
            'uploadedAttachmentId' => 'att-001',
        ]);

        $hook->beforeSave($c, SaveOptions::create());

        self::assertSame('contrato.pdf', $c->get('filename'));
        self::assertSame('application/pdf', $c->get('mimeType'));
    }

    public function testMimeNaoPdfLancaBadRequest(): void
    {
        $attachment = $this->makeAttachment('virus.exe', 'application/x-msdownload', 10000);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($attachment);

        $hook = $this->makeHook($em);
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'fixo',
            'valor' => 5000,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest mimeRejected não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('mimeRejected', $body['reason']);
        }
    }

    public function testTrocarClienteEmContratoExistenteLancaBadRequest(): void
    {
        $hook = $this->makeHook();
        $c = new ContratoHonorarios();
        $c->setId('contrato-001');
        $c->set('clienteId', 'cli-novo');
        $c->setFetched('clienteId', 'cli-antigo');
        $c->set('modalidade', 'fixo');
        $c->set('valor', 5000);
        $c->set('dataAssinatura', '2026-01-01');
        $c->set('vigenciaInicio', '2026-01-01');

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest linkImmutable não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('linkImmutable', $body['reason']);
        }
    }

    public function testHappyPathPopulaMetaDoAttachment(): void
    {
        $attachment = $this->makeAttachment('contrato-2026.pdf', 'application/pdf', 123456);
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn($attachment);

        $hook = $this->makeHook($em);
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'fixo',
            'valor' => 5000,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'vigenciaFim' => '2026-12-31',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $hook->beforeSave($c, SaveOptions::create());

        self::assertSame('contrato-2026.pdf', $c->get('filename'));
        self::assertSame('application/pdf', $c->get('mimeType'));
        self::assertSame(123456, $c->get('sizeBytes'));
    }

    public function testModalidadeExitoComValorPreenchidoLancaBadRequest(): void
    {
        $hook = $this->makeHook();
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'exito',
            'valor' => 5000,
            'percentual' => 20,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest valorIncompativelComModalidade não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('valorIncompativelComModalidade', $body['reason']);
        }
    }

    public function testModalidadeFixoComPercentualPreenchidoLancaBadRequest(): void
    {
        $hook = $this->makeHook();
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'fixo',
            'valor' => 5000,
            'percentual' => 10,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest percentualIncompativelComModalidade não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('percentualIncompativelComModalidade', $body['reason']);
        }
    }

    public function testClienteSemAclReadLancaForbidden(): void
    {
        $attachment = $this->makeAttachment('contrato.pdf', 'application/pdf', 1000);
        $cliente = $this->makeEntity('cli-001');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnMap([
            ['Cliente', 'cli-001', $cliente],
            ['Attachment', 'att-001', $attachment],
        ]);

        $hook = $this->makeHook($em, $this->makeAcl(false));
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'fixo',
            'valor' => 5000,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $this->expectException(Forbidden::class);
        $hook->beforeSave($c, SaveOptions::create());
    }

    public function testAttachmentDeOutroUsuarioLancaForbidden(): void
    {
        $attachment = $this->makeAttachment('contrato.pdf', 'application/pdf', 1000, [
            'createdById' => 'user-outro',
        ]);
        $cliente = $this->makeEntity('cli-001');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnMap([
            ['Cliente', 'cli-001', $cliente],
            ['Attachment', 'att-001', $attachment],
        ]);

        $hook = $this->makeHook($em);
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'modalidade' => 'fixo',
            'valor' => 5000,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $this->expectException(Forbidden::class);
        $hook->beforeSave($c, SaveOptions::create());
    }

    public function testCrossClienteAceitaProcessoComMultiplosClientesIncluindoContrato(): void
    {
        $attachment = $this->makeAttachment('contrato.pdf', 'application/pdf', 1000);
        $cliente = $this->makeEntity('cli-001');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE cliente_processo (cliente_id TEXT, processo_id TEXT, deleted INTEGER DEFAULT 0)');
        $pdo->exec("INSERT INTO cliente_processo (cliente_id, processo_id, deleted) VALUES ('cli-001', 'proc-001', 0)");
        $pdo->exec("INSERT INTO cliente_processo (cliente_id, processo_id, deleted) VALUES ('cli-002', 'proc-001', 0)");

        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getEntityById')->willReturnMap([
            ['Cliente', 'cli-001', $cliente],
            ['Attachment', 'att-001', $attachment],
        ]);

        $hook = $this->makeHook($em);
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'processosIds' => ['proc-001'],
            'modalidade' => 'fixo',
            'valor' => 5000,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        $hook->beforeSave($c, SaveOptions::create());
        self::assertSame('contrato.pdf', $c->get('filename'));
    }

    public function testCrossClienteFalhaFechadaQuandoConsultaFalha(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willThrowException(new \RuntimeException('db offline'));

        $hook = $this->makeHook($em);
        $c = $this->makeNew([
            'clienteId' => 'cli-001',
            'processosIds' => ['proc-001'],
            'modalidade' => 'fixo',
            'valor' => 5000,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'uploadedAttachmentId' => 'att-001',
        ]);

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest processosCrossClienteCheckFailed não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('processosCrossClienteCheckFailed', $body['reason']);
        }
    }

    public function testMetadadosStorageSaoImutaveisEmUpdate(): void
    {
        $hook = $this->makeHook();
        $c = new ContratoHonorarios();
        $c->setId('contrato-001');
        $c->set([
            'clienteId' => 'cli-001',
            'modalidade' => 'fixo',
            'valor' => 5000,
            'dataAssinatura' => '2026-01-01',
            'vigenciaInicio' => '2026-01-01',
            'fileStorageUri' => 'nextcloud://clientes/cli-001/contratos/contrato-001-novo.pdf',
        ]);
        $c->setFetched('clienteId', 'cli-001');
        $c->setFetched('fileStorageUri', 'nextcloud://clientes/cli-001/contratos/contrato-001-antigo.pdf');

        try {
            $hook->beforeSave($c, SaveOptions::create());
            self::fail('BadRequest storageMetadataImmutable não foi lançada');
        } catch (BadRequest $e) {
            $body = json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('storageMetadataImmutable', $body['reason']);
        }
    }

    public function testEntidadeNaoContratoIgnora(): void
    {
        $hook = $this->makeHook();
        $other = new CoreEntity();
        // Não deve lançar nada — não é ContratoHonorarios.
        $hook->beforeSave($other, SaveOptions::create());
        self::assertTrue(true);
    }

    private function makeHook(?EntityManager $em = null, ?Acl $acl = null, ?User $user = null): ValidateContratoFieldsHook
    {
        return new ValidateContratoFieldsHook(
            $em ?? $this->createMock(EntityManager::class),
            $acl ?? $this->makeAcl(),
            $user ?? $this->makeUser(),
        );
    }

    /** @param array<string, mixed> $attrs */
    private function makeNew(array $attrs): ContratoHonorarios
    {
        $c = new ContratoHonorarios();
        $c->set($attrs);
        return $c;
    }

    /** @param array<string, mixed> $overrides */
    private function makeAttachment(string $name, string $type, int $size, array $overrides = []): CoreEntity
    {
        $a = new CoreEntity();
        $a->set(array_merge([
            'name' => $name,
            'type' => $type,
            'size' => $size,
            'role' => ContratoHonorarios::ATTACHMENT_ROLE,
            'parentType' => ContratoHonorarios::ENTITY_TYPE,
            'field' => 'uploadedAttachment',
            'createdById' => 'user-001',
        ], $overrides));
        $a->setId('att-001');
        return $a;
    }

    private function makeEntity(string $id): CoreEntity
    {
        $entity = new CoreEntity();
        $entity->setId($id);
        return $entity;
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setId('user-001');
        return $user;
    }

    private function makeAcl(bool $allowed = true): Acl
    {
        return new class ($allowed) extends Acl {
            public function __construct(private bool $allowed)
            {
            }

            public function checkEntity(mixed $entity, ?string $action = null): bool
            {
                return $this->allowed;
            }
        };
    }
}
