<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Contracts;

use DateInterval;

/**
 * Storage com soft-delete + retenção — pra purge LGPD (FR42-FR45).
 *
 * Nextcloud nunca deve receber DELETE físico direto; soft-purge move para
 * `/.purged/<tombstoneId>` com TTL, deixando janela de reversão. Após TTL,
 * um job em Epic 8 promove soft-purge em hard-delete definitivo + grava
 * prova ed25519.
 *
 * Este contrato vive em togare-core (não em togare-lgpd) por decisão
 * estrutural — compliance não deve conhecer o storage concreto. Winston
 * Party Mode Step 6.
 */
interface PurgeableStorageContract extends FileStorageContract
{
    /**
     * Move $logicalPath para tombstone com TTL. Retorna o id do tombstone
     * (string opaca), necessário para restoreFromTombstone().
     *
     * @return string tombstoneId
     */
    public function softPurge(string $logicalPath, DateInterval $retention): string;

    /**
     * Move um tombstone de volta para seu path original. Idempotente: se o
     * tombstone já foi hard-deletado (TTL expirou), lança RuntimeException.
     *
     * @throws \RuntimeException se o tombstone não existe ou já foi purgado
     */
    public function restoreFromTombstone(string $tombstoneId): void;
}
