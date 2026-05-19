<?php

declare(strict_types=1);

namespace Espo\ORM;

/**
 * Stub mínimo de Espo\ORM\EntityManager para testes unit do togare-licensing.
 *
 * O EntityManager real só está disponível em runtime dentro do container do
 * EspoCRM. Os testes unit do togare-licensing rodam standalone — esta stub
 * permite que `$this->createMock(\Espo\ORM\EntityManager::class)` funcione,
 * ou que se passe diretamente ao construtor dos services com getPDO()
 * retornando o PDO SQLite in-memory dos testes.
 *
 * Padrão idêntico ao Stubs/EntityManagerStub.php do togare-core (Story
 * 1b.1.1.1-followup). Carregada condicionalmente via bootstrap.php se a
 * classe real ainda não estiver definida.
 */
class EntityManager
{
    public function getPDO(): \PDO
    {
        throw new \RuntimeException(
            'EntityManagerStub::getPDO() chamado direto — use mock encadeado ou inject manual.',
        );
    }
}
