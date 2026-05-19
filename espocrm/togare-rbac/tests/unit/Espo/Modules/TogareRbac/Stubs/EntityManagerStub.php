<?php

declare(strict_types=1);

namespace Espo\ORM;

/**
 * Stub mínimo de Espo\ORM\EntityManager para testes unit do togare-rbac.
 *
 * Padrão idêntico ao Stubs/EntityManagerStub.php do togare-licensing
 * (Story 1b.1.1.1-followup). Carregada condicionalmente via bootstrap.php
 * se a classe real ainda não estiver definida.
 */
class EntityManager
{
    public function getPDO(): \PDO
    {
        throw new \RuntimeException(
            'EntityManagerStub::getPDO() chamado direto — use mock encadeado ou inject manual.',
        );
    }

    public function getRepository(string $entityType): mixed
    {
        return null;
    }

    public function getRDBRepository(string $entityType): mixed
    {
        return null;
    }

    public function getNewEntity(string $entityType): mixed
    {
        return null;
    }

    public function getEntityById(string $entityType, string $id): mixed
    {
        return null;
    }

    public function saveEntity(mixed $entity, array $options = []): void
    {
    }
}
