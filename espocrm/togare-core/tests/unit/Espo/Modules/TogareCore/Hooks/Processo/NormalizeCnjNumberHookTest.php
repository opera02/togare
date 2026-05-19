<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Hooks\Processo;

use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\TogareCore\Entities\Processo;
use Espo\Modules\TogareCore\Hooks\Processo\NormalizeCnjNumberHook;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Story 3.4 AC2 + AC5 — normalize/validate CNJ no beforeSave.
 *
 * Storage CNJ = 20 dígitos puros (architecture L457 + Decisão #2 da story).
 * Hook strip mask + valida formato + valida DV via mod 97 progressivo.
 */
final class NormalizeCnjNumberHookTest extends TestCase
{
    private const CNJ_SP_2023 = '00012340820238260100';
    private const CNJ_JF_2024 = '00055552220244037700';

    /**
     * Formata os 20 dígitos canônicos em NNNNNNN-DD.AAAA.J.TR.OOOO.
     */
    private function formatCnj(string $digits20): string
    {
        return \sprintf(
            '%s-%s.%s.%s.%s.%s',
            \substr($digits20, 0, 7),
            \substr($digits20, 7, 2),
            \substr($digits20, 9, 4),
            \substr($digits20, 13, 1),
            \substr($digits20, 14, 2),
            \substr($digits20, 16, 4),
        );
    }

    private function createProcessoPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE processo (id VARCHAR(17), numero_cnj VARCHAR(25), deleted TINYINT DEFAULT 0)');

        return $pdo;
    }

    private function createHook(?PDO $pdo = null): NormalizeCnjNumberHook
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo ?? $this->createProcessoPdo());

        return new NormalizeCnjNumberHook($em);
    }

    public function testCnj20DigitosPurosNormalizaSemMudanca(): void
    {
        $hook = $this->createHook();
        $proc = new Processo();
        $cnj = self::CNJ_SP_2023;
        $proc->set('numeroCnj', $cnj);

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame($cnj, $proc->get('numeroCnj'));
        self::assertSame(20, \strlen((string) $proc->get('numeroCnj')));
    }

    public function testCnjMascaradoNormalizaPara20Digitos(): void
    {
        $hook = $this->createHook();
        $proc = new Processo();
        $cnj = self::CNJ_JF_2024;
        $masked = $this->formatCnj($cnj);
        $proc->set('numeroCnj', $masked);

        $hook->beforeSave($proc, SaveOptions::create());

        self::assertSame($cnj, $proc->get('numeroCnj'));
        self::assertSame(20, \strlen((string) $proc->get('numeroCnj')));
    }

    public function testCnjComMenosDe20DigitosFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Número CNJ inválido — confira o número e tente de novo.');

        $hook = $this->createHook();
        $proc = new Processo();
        $proc->set('numeroCnj', '12345');

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testCnjDvInvalidoFalha(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Número CNJ inválido — confira o número e tente de novo.');

        $hook = $this->createHook();
        $proc = new Processo();
        // 20 dígitos com DV deliberadamente errado.
        $proc->set('numeroCnj', '00012349820238260100');

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testCnjVazioNewLancaBadRequestObrigatorio(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Número CNJ é obrigatório.');

        $hook = $this->createHook();
        $proc = new Processo();
        $proc->set('numeroCnj', '');

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testCnjDuplicadoLancaMensagemLocalizada(): void
    {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Número CNJ '" . self::CNJ_SP_2023 . "' já está cadastrado.");

        $pdo = $this->createProcessoPdo();
        $stmt = $pdo->prepare('INSERT INTO processo (id, numero_cnj, deleted) VALUES (:id, :numero_cnj, 0)');
        $stmt->execute(['id' => 'existing000000001', 'numero_cnj' => self::CNJ_SP_2023]);

        $hook = $this->createHook($pdo);
        $proc = new Processo();
        $proc->set('numeroCnj', self::CNJ_SP_2023);

        $hook->beforeSave($proc, SaveOptions::create());
    }

    public function testCnjNaoMudadoEmEditNaoFazNada(): void
    {
        $hook = $this->createHook();
        $proc = new Processo();
        $cnj = self::CNJ_SP_2023;
        // Edit save: fetched igual ao set atual → isAttributeChanged = false → hook early return.
        $proc->setFetched('numeroCnj', $cnj);
        $proc->set('numeroCnj', $cnj);
        $proc->setId('00000000000000001'); // isNew=false

        $hook->beforeSave($proc, SaveOptions::create());

        // Sem exception — early return no isAttributeChanged. Valor inalterado.
        self::assertSame($cnj, $proc->get('numeroCnj'));
    }
}
