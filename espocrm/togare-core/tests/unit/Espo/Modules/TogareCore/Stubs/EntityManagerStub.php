<?php

declare(strict_types=1);

namespace Espo\ORM;

/**
 * Stub mínimo de Espo\ORM\EntityManager para testes unit do togare-core.
 *
 * O EntityManager real do EspoCRM só está disponível em runtime dentro do
 * container (site/vendor/autoload.php). Os testes unit do togare-core rodam
 * standalone com `vendor/bin/phpunit` — então declaramos uma stub no mesmo
 * namespace para que `$this->createMock(\Espo\ORM\EntityManager::class)`
 * funcione no PHPUnit sem depender do core.
 *
 * O bootstrap.php carrega este arquivo APENAS se a classe real ainda não
 * estiver definida (graceful fallback — em integration tests, o EspoCRM real
 * tem precedência).
 *
 * NÃO usar fora de testes. Em runtime real, o EntityManager do EspoCRM substitui.
 */
// Não-final: PHPUnit `createMock(EntityManager::class)` precisa derivar.
// Em runtime real, a EntityManager nativa do EspoCRM substitui esta stub.
class EntityManager
{
    public function getPDO(): \PDO
    {
        throw new \RuntimeException(
            'EntityManagerStub::getPDO() chamado direto — use PHPUnit createMock e willReturn.',
        );
    }

    /**
     * Declarado para que `createMock(EntityManager::class)->method('getEntityById')`
     * funcione (Story 2.4 — hooks que resolvem User pelo userId).
     */
    public function getEntityById(string $entityType, string $id): mixed
    {
        return null;
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

    public function saveEntity(mixed $entity, array $options = []): void
    {
    }

    public function removeEntity(mixed $entity): void
    {
    }
}
