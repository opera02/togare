<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Entities;

use Espo\Modules\TogareCore\Entities\Funcionario;
use Espo\Modules\TogareCore\Traits\TenantAwareEntity;
use PHPUnit\Framework\TestCase;

/**
 * Story 6.5 — testa constantes + trait obrigatório de Funcionario.
 *
 * Funcionario é entidade plana (cadastro básico de equipe, FR32) — não tem
 * lógica de domínio própria; o contrato a garantir é ENTITY_TYPE + uso do
 * trait TenantAwareEntity (architecture L650 / Story 1a.9).
 */
final class FuncionarioTest extends TestCase
{
    public function testEntityTypeConstant(): void
    {
        self::assertSame('Funcionario', Funcionario::ENTITY_TYPE);
    }

    public function testUsaTenantAwareEntityTrait(): void
    {
        self::assertContains(
            TenantAwareEntity::class,
            class_uses(Funcionario::class),
            'Toda entidade de negócio Togare deve usar TenantAwareEntity (tenant_id nullable MVP).',
        );
    }

    public function testGetSetCamposDeDominio(): void
    {
        $f = new Funcionario();
        $f->set([
            'nome' => 'Maria Souza',
            'cpf' => '52998224725',
            'cargo' => 'Secretária',
            'salario' => 3500.0,
            'dataAdmissao' => '2026-05-16',
        ]);

        self::assertSame('Maria Souza', $f->get('nome'));
        self::assertSame('52998224725', $f->get('cpf'));
        self::assertSame('Secretária', $f->get('cargo'));
        self::assertSame(3500.0, $f->get('salario'));
        self::assertSame('2026-05-16', $f->get('dataAdmissao'));
    }
}
