<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Contracts;

/**
 * Log de auditoria append-only (FR37 + NFR10). Implementação concreta em
 * togare-core/Services/AuditLogService (Epic 2) grava em togare_audit_log
 * com GRANT revogado de DELETE/UPDATE na aplicação.
 *
 * Retenção 24 meses; arquivamento obrigatório além disso.
 *
 * Contrato: log() é idempotente para eventos iguais no mesmo correlationId?
 * NÃO — audit é append-only por princípio; callers duplicados geram linhas
 * duplicadas e isso é aceito (auditoria prefere redundância à omissão).
 */
interface AuditLogContract
{
    /**
     * Registra um evento auditável.
     *
     * @param string $event dot-separated em pt-BR (ex.: 'cliente.excluido',
     *                     'prazo.atribuido', 'licenca.renovada')
     * @param string $entityType tipo da entidade alvo ('TogareCliente',
     *                          'TogarePrazo', etc.) ou '*' para evento global
     * @param string|null $entityId id da entidade alvo; null para evento global
     * @param array<string, mixed> $context dados estruturados adicionais
     *                                      (serializados como JSON)
     */
    public function log(
        string $event,
        string $entityType,
        ?string $entityId,
        array $context = [],
    ): void;
}
