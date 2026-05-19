<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Contracts;

/**
 * Resolver de contexto tenant. Usado por repositórios/services pra filtrar
 * queries por tenant_id quando a arquitetura multi-tenant de Fase 2 entrar.
 *
 * No MVP (single-tenant), currentTenantId() sempre retorna null e
 * scope() apenas invoca o callable — mas os callers já estão instrumentados,
 * evitando ALTER em ~15 tabelas na Fase 2 (decisão estrutural da arquitetura
 * Step 6, Party Mode crítica de Mary).
 *
 * Todas as entidades de negócio têm coluna `tenant_id NULL-able` desde a
 * Story 1a.9 — esse contrato expõe como ler/escrever esse campo de forma
 * consistente.
 */
interface TenantContextResolverContract
{
    /**
     * Tenant ativo na thread atual. null = sem escopo (MVP default; também
     * usado por scripts CLI administrativos).
     */
    public function currentTenantId(): ?string;

    /**
     * Executa $fn no escopo do tenant informado. Retorna o valor de $fn.
     * Garante que currentTenantId() retorna $tenantId durante a execução de
     * $fn e restaura o escopo anterior após (inclusive em caso de exceção).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function scope(string $tenantId, callable $fn): mixed;
}
