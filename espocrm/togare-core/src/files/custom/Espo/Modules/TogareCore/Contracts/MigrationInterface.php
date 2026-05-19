<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Contracts;

use PDO;

/**
 * Contrato de migration versionada do Togare.
 *
 * Cada migration é uma classe imutável após aplicação. O MigrationRunner
 * detecta arquivos V<N>__<descricao>.php em Migration/, compara com a tabela
 * togare_migrations_applied e aplica os pendentes em ordem lexicográfica.
 *
 * Contrato: up() é idempotente no nível do registro (roda 1x, registra,
 * nunca mais). down() permite rollback manual via script dedicado
 * (Migration/rollback.php). Ambos recebem PDO direto porque o container DI
 * pode não estar pronto durante beforeInstall.
 */
interface MigrationInterface
{
    /**
     * Aplica a migration. Deve ser atômico do ponto de vista da migration
     * em si (ex.: 1 DDL + 1 INSERT no registry). MariaDB ≥10.6 faz implicit
     * commit em DDL; múltiplas DDLs numa mesma migration podem não ter
     * rollback atômico — evitar ou documentar.
     */
    public function up(PDO $pdo): void;

    /**
     * Reverte a migration. Idempotente: se o estado já foi revertido,
     * executa noop sem lançar.
     */
    public function down(PDO $pdo): void;

    /**
     * Identificador único da migration. Convenção: 'V<NNN>__<descricao_snake>'.
     * Ex.: 'V001__create_togare_migrations_applied'.
     */
    public function version(): string;
}
