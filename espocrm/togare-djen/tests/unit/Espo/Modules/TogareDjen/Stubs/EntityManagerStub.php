<?php

declare(strict_types=1);

namespace Espo\ORM;

/**
 * Stub mínimo de Espo\ORM\EntityManager para testes unit do togare-djen.
 *
 * Espelha o stub equivalente do togare-core. Carregado pelo bootstrap.php
 * apenas se a classe real ainda não existir (em integration tests com o
 * site/ EspoCRM rodando, a classe oficial substitui).
 *
 * Story 4b.1b ampliação: adicionados `getTransactionManager()`,
 * `getEntityById()` e `getPDO()` (já existia signature, agora documentado).
 */
class EntityManager
{
    public function getPDO(): \PDO
    {
        throw new \RuntimeException(
            'EntityManagerStub::getPDO() chamado direto — use PHPUnit createMock e willReturn.',
        );
    }

    /**
     * Story 4a.3 — PrazoCreatorService usa este método para repository chain
     * (where, distinct, join, findOne). Em testes, mock retorna instância
     * de Repository\RDBRepository stub configurada.
     */
    public function getRDBRepository(string $entityType): \Espo\ORM\Repository\RDBRepository
    {
        throw new \RuntimeException(
            'EntityManagerStub::getRDBRepository() chamado direto — use PHPUnit createMock e willReturn.',
        );
    }

    /**
     * Story 4a.3 — PrazoCreatorService usa para criar entity Prazo nova.
     * Story 4b.1b — também para PublicacaoAmbigua.
     */
    public function getNewEntity(string $entityType): \Espo\ORM\Entity
    {
        throw new \RuntimeException(
            'EntityManagerStub::getNewEntity() chamado direto — use PHPUnit createMock e willReturn.',
        );
    }

    public function saveEntity(\Espo\ORM\Entity $entity, array $options = []): \Espo\ORM\Entity
    {
        throw new \RuntimeException(
            'EntityManagerStub::saveEntity() chamado direto — use PHPUnit createMock e willReturn.',
        );
    }

    /**
     * Story 4b.1b — PublicationMatcher e AmbiguityResolverService usam para
     * carregar entity por ID (Processo nos candidatos / PublicacaoAmbigua a
     * resolver).
     */
    public function getEntityById(string $entityType, string $id): ?\Espo\ORM\Entity
    {
        throw new \RuntimeException(
            'EntityManagerStub::getEntityById() chamado direto — use PHPUnit createMock e willReturn.',
        );
    }

    /**
     * Story 4b.1b — AmbiguityResolverService envolve operações em transação
     * via $em->getTransactionManager()->run(callback). Em testes, mock
     * configura $tm->method('run')->willReturnCallback(fn($cb) => $cb()).
     */
    public function getTransactionManager(): \Espo\ORM\TransactionManager
    {
        throw new \RuntimeException(
            'EntityManagerStub::getTransactionManager() chamado direto — use PHPUnit createMock e willReturn.',
        );
    }

    /**
     * Story 4b.1b — PublicationMatcher usa API oficial para relações N:N:
     * $em->getRelation($entity, $link)->find().
     */
    public function getRelation(\Espo\ORM\Entity $entity, string $relationName): \Espo\ORM\Repository\RDBRelation
    {
        throw new \RuntimeException(
            'EntityManagerStub::getRelation() chamado direto — use PHPUnit createMock e willReturnCallback.',
        );
    }
}
