<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Prazo;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\Prazo;
use Espo\Modules\TogareCore\Hooks\Prazo\ValidatePrazoFieldsHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre validações de campos de Prazo (Story 4a.3 + 4a.3.1, FR12+FR13+FR14).
 *
 * Story 4a.3.1 atualizou:
 *  - VALID_STATUSES 6→9 valores (8 visíveis + descartado oculto)
 *  - Constantes STATUS_RASCUNHO_NAO_VINCULADO/CONFIRMADO/CUMPRIDO/REVERTIDO
 *    REMOVIDAS — substituídas por STATUS_RASCUNHO/PENDENTE/PROTOCOLADO/etc.
 *  - Nova validação `motivoReagendamento` ≥10 chars trim quando
 *    status=atrasado_reagendado (Decisão #2 da 4a.3.1)
 *  - Validação `prioridade` enum 4 valores
 */
final class ValidatePrazoFieldsHookTest extends TestCase
{
    public function testEntidadePendenteValidaPassa(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame(Prazo::STATUS_PENDENTE, $prazo->get('status'));
    }

    public function testEntidadeRascunhoValidaPassa(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoRascunho();

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame(Prazo::STATUS_RASCUNHO, $prazo->get('status'));
    }

    public function testStatusInvalidoLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('status', 'inexistente');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para status inválido');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('status', $decoded['field']);
            self::assertStringContainsString('Status inválido', $decoded['message']);
        }
    }

    /**
     * Story 4a.3.1: status legados (`confirmado`, `cumprido`, `revertido`,
     * `rascunho_nao_vinculado`) foram REMOVIDOS do enum e mapeados via V009.
     * Hook deve rejeitar esses valores em saves novos.
     */
    public function testStatusConfirmadoLegacyEhRejeitado(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('status', 'confirmado');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para status legado "confirmado"');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('status', $decoded['field']);
        }
    }

    public function testContagemInvalidaLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('contagem', 'lunares');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para contagem inválida');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('contagem', $decoded['field']);
        }
    }

    public function testConfidenceInvalidaLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('confidence', 'incerto');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para confidence inválida');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('confidence', $decoded['field']);
        }
    }

    public function testSourceInvalidaLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('source', 'fax');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para source inválida');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('source', $decoded['field']);
        }
    }

    /** Story 4a.3.1 — novo enum prioridade. */
    public function testPrioridadeInvalidaLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('prioridade', 'mega-urgente');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para prioridade inválida');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('prioridade', $decoded['field']);
        }
    }

    public function testStatusPendenteSemProcessoLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('processoId', null);

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para status=pendente sem processoId');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('processo', $decoded['field']);
            self::assertStringContainsString('processo', $decoded['message']);
        }
    }

    public function testStatusPendenteSemAssignedUserLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('assignedUserId', null);

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para status=pendente sem assignedUserId');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('assignedUser', $decoded['field']);
            self::assertStringContainsString('advogado responsável', $decoded['message']);
        }
    }

    public function testStatusRascunhoSemNumeroProcessoOriginalLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoRascunho();
        $prazo->set('numeroProcessoOriginal', null);

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para rascunho sem numeroProcessoOriginal');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('numeroProcessoOriginal', $decoded['field']);
        }
    }

    public function testStatusRascunhoComProcessoNullEAssignedUserNullEhValido(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoRascunho();
        $prazo->set('processoId', null);
        $prazo->set('assignedUserId', null);

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame(Prazo::STATUS_RASCUNHO, $prazo->get('status'));
    }

    public function testDataFatalAnteriorADisponibilizacaoLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('dataDisponibilizacao', '2026-05-15');
        $prazo->set('dataFatal', '2026-05-10');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para dataFatal anterior à dataDisponibilizacao');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('dataFatal', $decoded['field']);
            self::assertStringContainsString('anterior', $decoded['message']);
        }
    }

    public function testPrazoDiasZeroLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('prazoDias', 0);

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para prazoDias=0');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('prazoDias', $decoded['field']);
        }
    }

    public function testPrazoDiasMaiorQue365LancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('prazoDias', 366);

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para prazoDias>365');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('prazoDias', $decoded['field']);
        }
    }

    public function testEntidadeNaoPrazoIgnora(): void
    {
        $hook = new ValidatePrazoFieldsHook();

        $other = new \Espo\Core\ORM\Entity();
        $other->set('status', 'inexistente');

        // Não deve lançar — não é Prazo
        $hook->beforeSave($other, SaveOptions::create());

        self::assertSame('inexistente', $other->get('status'));
    }

    public function testDataInicioPrazoPosteriorADataFatalLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('dataInicioPrazo', '2026-06-10');
        $prazo->set('dataFatal', '2026-06-08');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para dataInicioPrazo posterior à dataFatal');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('dataInicioPrazo', $decoded['field']);
            self::assertStringContainsString('posterior', $decoded['message']);
        }
    }

    public function testStatusPendenteSemProcessoEmUpdateLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = new Prazo();
        $prazo->setFetched('status', Prazo::STATUS_PENDENTE);
        $prazo->setFetched('processoId', 'proc-001');
        $prazo->setFetched('assignedUserId', 'user-001');
        $prazo->set([
            'dataDisponibilizacao' => '2026-05-15',
            'dataInicioPrazo' => '2026-05-18',
            'dataFatal' => '2026-06-08',
            'prazoDias' => 15,
            'contagem' => Prazo::CONTAGEM_UTEIS,
            'atoCodigo' => 'contestacao',
            'referenciaLegal' => 'CPC art. 335',
            'confidence' => Prazo::CONFIDENCE_HIGH,
            'parserRegraVersao' => '1.0.0',
            'source' => Prazo::SOURCE_DJEN,
            'prioridade' => Prazo::PRIORIDADE_NORMAL,
            'status' => Prazo::STATUS_PENDENTE,
            'processoId' => null,
            'assignedUserId' => 'user-001',
        ]);
        $prazo->setId('00000000000000prz');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada ao zerar processoId em Prazo pendente existente');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('processo', $decoded['field']);
        }
    }

    // ---------------------------------------------------------------------
    // Story 4a.3.1 — testes novos para motivoReagendamento (Decisão #2)
    // ---------------------------------------------------------------------

    /** Story 4a.3.1 — AC4: status=atrasado_reagendado SEM motivoReagendamento. */
    public function testStatusReagendadoSemMotivoReagendamentoLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('status', Prazo::STATUS_REAGENDADO);
        $prazo->set('motivoReagendamento', null);

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para reagendado sem motivoReagendamento');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('motivoReagendamento', $decoded['field']);
            self::assertStringContainsString('mínimo 10 caracteres', $decoded['message']);
        }
    }

    /** Story 4a.3.1 — AC4: motivoReagendamento curto (3 chars) é rejeitado. */
    public function testStatusReagendadoComMotivoCurtoLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('status', Prazo::STATUS_REAGENDADO);
        $prazo->set('motivoReagendamento', 'OK!');

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para motivoReagendamento curto');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('motivoReagendamento', $decoded['field']);
        }
    }

    /** Story 4a.3.1 — AC4: motivoReagendamento só com espaços (≥10) é rejeitado por trim. */
    public function testStatusReagendadoComMotivoSoEspacosLancaBadRequest(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('status', Prazo::STATUS_REAGENDADO);
        $prazo->set('motivoReagendamento', '              '); // 14 espaços

        try {
            $hook->beforeSave($prazo, SaveOptions::create());
            self::fail('BadRequest não foi lançada para motivoReagendamento só com espaços');
        } catch (BadRequest $e) {
            $decoded = \json_decode((string) $e->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('motivoReagendamento', $decoded['field']);
        }
    }

    /** Story 4a.3.1 — AC4: motivoReagendamento ≥10 chars válidos passa. */
    public function testStatusReagendadoComMotivoValidoNaoLancaNada(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('status', Prazo::STATUS_REAGENDADO);
        $prazo->set('motivoReagendamento', 'Cliente solicitou prazo extra para revisar provas.');

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame(Prazo::STATUS_REAGENDADO, $prazo->get('status'));
    }

    /** Story 4a.3.1 — status diferente de reagendado, motivoReagendamento opcional. */
    public function testStatusPendenteComMotivoReagendamentoNullEhValido(): void
    {
        $hook = new ValidatePrazoFieldsHook();
        $prazo = $this->makeValidPrazoPendente();
        $prazo->set('motivoReagendamento', null);

        $hook->beforeSave($prazo, SaveOptions::create());

        self::assertSame(Prazo::STATUS_PENDENTE, $prazo->get('status'));
    }

    private function makeValidPrazoPendente(): Prazo
    {
        $prazo = new Prazo();
        $prazo->set([
            'dataDisponibilizacao' => '2026-05-15',
            'dataInicioPrazo' => '2026-05-18',
            'dataFatal' => '2026-06-08',
            'prazoDias' => 15,
            'contagem' => Prazo::CONTAGEM_UTEIS,
            'atoCodigo' => 'contestacao',
            'referenciaLegal' => 'CPC art. 335',
            'confidence' => Prazo::CONFIDENCE_HIGH,
            'parserRegraVersao' => '1.0.0',
            'source' => Prazo::SOURCE_DJEN,
            'sourcePubId' => 12345,
            'numeroProcessoOriginal' => '0001234-56.2024.8.26.0001',
            'status' => Prazo::STATUS_PENDENTE,
            'prioridade' => Prazo::PRIORIDADE_NORMAL,
            'processoId' => 'proc-uuid-001',
            'assignedUserId' => 'user-uuid-alice',
        ]);

        return $prazo;
    }

    private function makeValidPrazoRascunho(): Prazo
    {
        $prazo = new Prazo();
        $prazo->set([
            'dataDisponibilizacao' => '2026-05-15',
            'dataInicioPrazo' => '2026-05-18',
            'dataFatal' => '2026-06-08',
            'prazoDias' => 15,
            'contagem' => Prazo::CONTAGEM_UTEIS,
            'atoCodigo' => 'manifestacao_generica',
            'referenciaLegal' => 'CPC art. 218',
            'confidence' => Prazo::CONFIDENCE_LOW,
            'parserRegraVersao' => '1.0.0',
            'source' => Prazo::SOURCE_DJEN,
            'sourcePubId' => 99999,
            'numeroProcessoOriginal' => '9999999-99.2024.8.26.9999',
            'status' => Prazo::STATUS_RASCUNHO,
            'prioridade' => Prazo::PRIORIDADE_NORMAL,
            'processoId' => null,
            'assignedUserId' => 'user-uuid-felipe',
        ]);

        return $prazo;
    }
}
