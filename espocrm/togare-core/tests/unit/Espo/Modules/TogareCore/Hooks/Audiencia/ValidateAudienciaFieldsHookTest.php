<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Audiencia;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\Audiencia;
use Espo\Modules\TogareCore\Hooks\Audiencia\ValidateAudienciaFieldsHook;
use Espo\ORM\Repository\Option\SaveOptions;
use PHPUnit\Framework\TestCase;

/**
 * Cobre validações de campos de Audiencia (Story 3.6-magro, FR16).
 *
 * Defesa em profundidade — framework EspoCRM já valida required + enum,
 * mas hook bloqueia se algum campo for setado fora-banda.
 */
final class ValidateAudienciaFieldsHookTest extends TestCase
{
    public function testEntidadeValidaPassa(): void
    {
        $hook = new ValidateAudienciaFieldsHook();

        $aud = $this->makeValidAudiencia();

        $hook->beforeSave($aud, SaveOptions::create());

        // Nenhuma exceção lançada — passou.
        self::assertSame(Audiencia::TIPO_CONCILIACAO, $aud->get('tipo'));
    }

    public function testTipoInvalidoLancaBadRequest(): void
    {
        $hook = new ValidateAudienciaFieldsHook();

        $aud = $this->makeValidAudiencia();
        $aud->set('tipo', 'invalido');

        try {
            $hook->beforeSave($aud, SaveOptions::create());
            self::fail('BadRequest não foi lançada para tipo inválido');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = \json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('tipo', $decoded['field']);
            self::assertSame('invalid', $decoded['reason']);
            self::assertStringContainsString('Tipo inválido', $decoded['message']);
        }
    }

    public function testModalidadeInvalidaLancaBadRequest(): void
    {
        $hook = new ValidateAudienciaFieldsHook();

        $aud = $this->makeValidAudiencia();
        $aud->set('modalidade', 'metaverso');

        try {
            $hook->beforeSave($aud, SaveOptions::create());
            self::fail('BadRequest não foi lançada para modalidade inválida');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = \json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('modalidade', $decoded['field']);
            self::assertStringContainsString('Modalidade inválida', $decoded['message']);
        }
    }

    public function testStatusInvalidoLancaBadRequest(): void
    {
        $hook = new ValidateAudienciaFieldsHook();

        $aud = $this->makeValidAudiencia();
        $aud->set('status', 'pendente');

        try {
            $hook->beforeSave($aud, SaveOptions::create());
            self::fail('BadRequest não foi lançada para status inválido');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = \json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('status', $decoded['field']);
        }
    }

    public function testDuracaoMenorQueMinimoLancaBadRequest(): void
    {
        $hook = new ValidateAudienciaFieldsHook();

        $aud = $this->makeValidAudiencia();
        $aud->set('duracaoMinutos', 5);

        try {
            $hook->beforeSave($aud, SaveOptions::create());
            self::fail('BadRequest não foi lançada para duração < 15');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = \json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('duracaoMinutos', $decoded['field']);
            self::assertStringContainsString('Duração deve estar entre 15 e 480', $decoded['message']);
        }
    }

    public function testDuracaoMaiorQueMaximoLancaBadRequest(): void
    {
        $hook = new ValidateAudienciaFieldsHook();

        $aud = $this->makeValidAudiencia();
        $aud->set('duracaoMinutos', 600);

        try {
            $hook->beforeSave($aud, SaveOptions::create());
            self::fail('BadRequest não foi lançada para duração > 480');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = \json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('duracaoMinutos', $decoded['field']);
        }
    }

    public function testProcessoVazioLancaBadRequest(): void
    {
        $hook = new ValidateAudienciaFieldsHook();

        $aud = $this->makeValidAudiencia();
        $aud->set('processoId', null);

        try {
            $hook->beforeSave($aud, SaveOptions::create());
            self::fail('BadRequest não foi lançada para processoId ausente');
        } catch (BadRequest $e) {
            $body = $e->getBody();
            self::assertNotNull($body);
            $decoded = \json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('processo', $decoded['field']);
            self::assertStringContainsString('processo', $decoded['message']);
        }
    }

    public function testEntidadeNaoAudienciaIgnora(): void
    {
        $hook = new ValidateAudienciaFieldsHook();

        $other = new \Espo\Core\ORM\Entity();
        $other->set('tipo', 'invalido');

        // Não deve lançar — não é Audiencia
        $hook->beforeSave($other, SaveOptions::create());

        self::assertSame('invalido', $other->get('tipo'));
    }

    private function makeValidAudiencia(): Audiencia
    {
        $aud = new Audiencia();
        $aud->set([
            'dataHora' => '2026-05-15 14:00:00',
            'duracaoMinutos' => 60,
            'tipo' => Audiencia::TIPO_CONCILIACAO,
            'modalidade' => Audiencia::MODALIDADE_PRESENCIAL,
            'status' => Audiencia::STATUS_AGENDADA,
            'processoId' => 'proc-001',
        ]);

        return $aud;
    }
}
