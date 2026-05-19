<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Processo;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\Processo;
use Espo\Modules\TogareCore\Hooks\Processo\ValidateProcessoFieldsHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Story 3.4 AC7 — defesa em profundidade do hook validate.
 *
 * Framework EspoCRM já valida `required` e enum. Hook bloqueia payloads
 * fora-banda (API client direto) com regras: enum allowlist, ints positivos,
 * valor causa ≥ 0, dataAutuacao ≥ dataDistribuicao.
 *
 * NÃO faz lookup TPU — esse é responsabilidade do hook em togare-tpu.
 */
final class ValidateProcessoFieldsHookTest extends TestCase
{
    private function processoValido(): Processo
    {
        $proc = new Processo();
        $proc->set([
            'area' => Processo::AREA_CIVEL,
            'instancia' => Processo::INSTANCIA_PRIMEIRA,
            'fase' => Processo::FASE_CONHECIMENTO,
            'status' => Processo::STATUS_ATIVO,
            'polo' => Processo::POLO_ATIVO,
            'classeCodigo' => 436,
            'assuntoCodigo' => 10001,
            'valorCausa' => 1000.0,
        ]);
        return $proc;
    }

    public function testEntidadeValidaPassa(): void
    {
        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();

        $hook->beforeSave($proc, SaveOptions::create());

        // Sem exception = passou
        self::assertSame(436, $proc->get('classeCodigo'));
    }

    public function testValorCausaNegativoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Valor da causa não pode ser negativo.');

        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();
        $proc->set('valorCausa', -100.0);

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testDataAutuacaoAnteriorADistribuicaoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Data de autuação deve ser posterior ou igual à data de distribuição.');

        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();
        $proc->set([
            'dataDistribuicao' => '2026-05-01',
            'dataAutuacao' => '2026-04-15',
        ]);

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testDataAutuacaoIgualDistribuicaoPassa(): void
    {
        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();
        $proc->set([
            'dataDistribuicao' => '2026-04-15',
            'dataAutuacao' => '2026-04-15',
        ]);

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame('2026-04-15', $proc->get('dataAutuacao'));
    }

    public function testAreaForaDoEnumFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Área inválida — escolha uma das opções.');

        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();
        $proc->set('area', 'inexistente');

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testClasseCodigoZeroFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Código de classe deve ser um número positivo.');

        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();
        $proc->set('classeCodigo', 0);

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testAssuntoCodigoNegativoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Código de assunto deve ser um número positivo.');

        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();
        $proc->set('assuntoCodigo', -5);

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testMovimentoCodigoZeroQuandoPreenchidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Código de movimento deve ser um número positivo.');

        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();
        $proc->set('movimentoCodigo', 0);

        // Forçar movimentoCodigo a ser detectado como mudou (não new precisa de fetched)
        $proc->setFetched('movimentoCodigo', 100);

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testMovimentoCodigoNullPassaSeOpcional(): void
    {
        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();
        // movimentoCodigo é opcional — null é aceito

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertNull($proc->get('movimentoCodigo'));
    }

    public function testInstanciaForaDoEnumFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Instância inválida — escolha uma das opções.');

        $hook = new ValidateProcessoFieldsHook();
        $proc = $this->processoValido();
        $proc->set('instancia', 'terceira');

        $hook->beforeSave($proc, SaveOptions::create());
    }
}
