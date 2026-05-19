<?php

declare(strict_types=1);

namespace Espo\ORM;

/**
 * Stub mínimo de Espo\ORM\EntityManager para testes unit do togare-tpu.
 *
 * Espelha o stub equivalente do togare-core. Carregado pelo bootstrap.php
 * apenas se a classe real ainda não existir (em integration tests com o
 * site/ EspoCRM rodando, a classe oficial substitui).
 */
class EntityManager
{
    public function getPDO(): \PDO
    {
        throw new \RuntimeException(
            'EntityManagerStub::getPDO() chamado direto — use PHPUnit createMock e willReturn.',
        );
    }
}
