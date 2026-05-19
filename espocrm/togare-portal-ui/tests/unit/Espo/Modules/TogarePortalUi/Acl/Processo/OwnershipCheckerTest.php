<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogarePortalUi\Acl\Processo;

use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogarePortalUi\Stubs\AuditLogContractStub;

/**
 * Story 7a.2 — Action Item A4 (AC#4 NÃO DEFERÍVEL).
 *
 * Prova determinística (unit, sem container) da lógica do
 * `Espo\Modules\TogarePortalUi\Classes\AclPortal\Processo\OwnershipChecker`:
 *
 *  - Processo vinculado ao Cliente do portal user → checkOwn() == true,
 *    SEM evento de audit (acesso legítimo).
 *  - Processo de OUTRO Cliente → checkOwn() == false E grava exatamente
 *    1 evento `portal.acesso_cruzado_tentado` (entityType=Processo,
 *    entityId=alvo, contexto estruturado). false ⇒ ForbiddenSilent (403)
 *    na camada Core\Record\Service (cobertura runtime: smoke-7a-2-cli).
 *  - Portal user sem `togareCliente` → fail-closed: false + audit.
 *
 * O 403 HTTP fim-a-fim + zero-vazamento em list/search/related/by-id é
 * provado em runtime no `docker/smoke-7a-2-cli.php` (cultura
 * smoke-dividido — Claude executa o CLI). Aqui isolamos a REGRA.
 */
final class OwnershipCheckerTest extends TestCase
{
    private string $moduleRoot;

    protected function setUp(): void
    {
        $this->moduleRoot = dirname(__DIR__, 7);

        require_once dirname(__DIR__, 2) . '/Stubs/CoreStubs.php';
    }

    private function makeChecker(AuditLogContractStub $audit, bool $linked): object
    {
        $em = new class($linked) extends \Espo\ORM\EntityManager {
            public function __construct(private bool $linked)
            {
            }

            public function getRDBRepository(string $entityType): mixed
            {
                $linked = $this->linked;

                return new class($linked) {
                    public function __construct(private bool $linked)
                    {
                    }

                    public function getRelation(mixed $entity, string $link): object
                    {
                        $linked = $this->linked;

                        return new class($linked) {
                            public array $whereCalls = [];

                            public function __construct(private bool $linked)
                            {
                            }

                            public function where(mixed $clause): self
                            {
                                $this->whereCalls[] = $clause;

                                return $this;
                            }

                            public function findOne(): mixed
                            {
                                return $this->linked ? (object) ['id' => 'C1'] : null;
                            }
                        };
                    }
                };
            }
        };

        $class = 'Espo\\Modules\\TogarePortalUi\\Classes\\AclPortal\\Processo\\OwnershipChecker';

        return new $class($em, $audit);
    }

    private function makeProcesso(string $id): object
    {
        return new class($id) implements \Espo\ORM\Entity {
            public function __construct(private string $id)
            {
            }

            public function getId(): ?string
            {
                return $this->id;
            }

            public function get(string $name): mixed
            {
                return null;
            }

            public function set(mixed $key, mixed $value = null): void
            {
            }
        };
    }

    public function testProcessoDoProprioClienteLiberaSemAudit(): void
    {
        $audit = new AuditLogContractStub();
        $checker = $this->makeChecker($audit, linked: true);

        $user = new \Espo\Entities\User(['id' => 'U1', 'togareClienteId' => 'C1']);
        $processo = $this->makeProcesso('P9');

        self::assertTrue($checker->checkOwn($user, $processo));
        self::assertSame([], $audit->calls, 'Acesso legítimo NÃO deve gerar audit.');
    }

    public function testProcessoDeOutroClienteNegaEGravaAuditCruzado(): void
    {
        $audit = new AuditLogContractStub();
        $checker = $this->makeChecker($audit, linked: false);

        $user = new \Espo\Entities\User(['id' => 'U1', 'togareClienteId' => 'C1']);
        $processo = $this->makeProcesso('P-de-outro');

        self::assertFalse($checker->checkOwn($user, $processo));

        self::assertCount(1, $audit->calls);
        $call = $audit->calls[0];

        self::assertSame('portal.acesso_cruzado_tentado', $call['event']);
        self::assertSame('Processo', $call['entityType']);
        self::assertSame('P-de-outro', $call['entityId']);
        self::assertSame('U1', $call['context']['portalUserId']);
        self::assertSame('C1', $call['context']['portalClienteId']);
        self::assertSame('Processo', $call['context']['targetEntityType']);
        self::assertSame('P-de-outro', $call['context']['targetRecordId']);
    }

    public function testPortalUserSemClienteFailClosedComAudit(): void
    {
        $audit = new AuditLogContractStub();
        $checker = $this->makeChecker($audit, linked: true);

        $user = new \Espo\Entities\User(['id' => 'U2']); // sem togareClienteId
        $processo = $this->makeProcesso('P1');

        self::assertFalse(
            $checker->checkOwn($user, $processo),
            'Portal user sem Cliente vinculado deve ser negado (fail-closed).',
        );
        self::assertCount(1, $audit->calls);
        self::assertSame('portal.acesso_cruzado_tentado', $audit->calls[0]['event']);
        self::assertNull($audit->calls[0]['context']['portalClienteId']);
    }
}
