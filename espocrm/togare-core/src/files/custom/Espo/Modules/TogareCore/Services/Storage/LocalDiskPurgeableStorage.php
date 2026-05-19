<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Storage;

use DateInterval;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use RuntimeException;

/**
 * Implementação filesystem nativo do PurgeableStorageContract da togare-core.
 *
 * Estende LocalDiskStorage (herda put/get/exists/delete/buildUri) e adiciona
 * `softPurge` e `restoreFromTombstone` — primitivas que Epic 8 LGPD (futuro)
 * usa para janela de reversão antes de hard-delete. Story 6.0 entrega a
 * mecânica completa por simetria com NextcloudPurgeableStorage; sem bridge
 * instalado, este storage é o registrado em AMBOS os contratos
 * (FileStorageContract + PurgeableStorageContract) via LSP — Decisão #2.
 *
 * Soft-purge faz `rename()` atômico de `<root>/<logicalPath>` para
 * `<root>/.purged/<tombstoneId>/<logicalPath>` (preserva nome original sob a
 * pasta do tombstone — facilita auditoria humana). `tombstoneId =
 * bin2hex(random_bytes(16))` (32 chars hex — mesma convenção do
 * NextcloudPurgeableStorage:43 + togare_ambiguity_log.id da Story 4b.1b).
 *
 * Esta classe NÃO persiste tombstone metadata em tabela — o consumer (Story
 * 5.2 SoftPurgeDocumentoHook + Epic 8 futuro) persiste em
 * `togare_documento_log` da togare-core (V018) gravando event=`*.soft_purged`
 * com payload JSON `{tombstoneId, logicalPath, hardDeleteAt, retentionDays}`.
 *
 * Hard-delete job paralelo para LocalDisk (equivalente da Story 5.5
 * TogareBridgeHardDeleteJob) é DIFERIDO (Decisão #5 — Balde B). Tombstones
 * em `.purged/` no LocalDisk acumulam até cleanup manual via cron ops:
 *   `find <root>/.purged -mtime +30 -type d -exec rm -rf {} \;`
 */
final class LocalDiskPurgeableStorage extends LocalDiskStorage implements PurgeableStorageContract
{
    public const PURGED_ROOT = '.purged';

    public function softPurge(string $logicalPath, DateInterval $retention): string
    {
        $this->validateLogicalPath($logicalPath);

        $srcAbs = $this->rootPath . '/' . $logicalPath;
        if (! \is_file($srcAbs)) {
            throw new RuntimeException(
                "LocalDiskStorage softPurge: arquivo origem não encontrado: {$logicalPath}",
            );
        }
        $this->assertWithinRoot($srcAbs);

        $tombstoneId = \bin2hex(\random_bytes(16));
        $tombstoneRelative = self::PURGED_ROOT . '/' . $tombstoneId . '/' . $logicalPath;
        $dstAbs = $this->rootPath . '/' . $tombstoneRelative;
        $dstDir = \dirname($dstAbs);

        if (! \is_dir($dstDir)
            && ! @\mkdir($dstDir, self::DIR_MODE, true)
            && ! \is_dir($dstDir)
        ) {
            throw new RuntimeException(
                "LocalDiskStorage softPurge: não foi possível criar destino {$tombstoneRelative}.",
            );
        }
        $this->assertWithinRoot($dstDir);
        @\chmod($dstDir, self::DIR_MODE);

        if (! @\rename($srcAbs, $dstAbs)) {
            throw new RuntimeException(
                "LocalDiskStorage softPurge: rename falhou para tombstone {$tombstoneId}.",
            );
        }

        TogareLogger::event(
            'info',
            'localdisk.storage.softpurge',
            "LocalDisk softPurge {$logicalPath} → tombstone {$tombstoneId}",
            [
                'logicalPath' => $logicalPath,
                'tombstoneId' => $tombstoneId,
                'retentionIso8601' => $this->formatRetentionIso($retention),
            ],
        );

        return $tombstoneId;
    }

    public function restoreFromTombstone(string $tombstoneId): void
    {
        $this->validateTombstoneId($tombstoneId);

        $tombstoneAbsDir = $this->rootPath . '/' . self::PURGED_ROOT . '/' . $tombstoneId;
        if (! \is_dir($tombstoneAbsDir)) {
            throw new RuntimeException(
                "Tombstone {$tombstoneId} não existe ou já foi purgado definitivamente.",
            );
        }

        $logicalPath = $this->findSingleRestorableFile($tombstoneAbsDir);

        $srcAbs = $tombstoneAbsDir . '/' . $logicalPath;
        $dstAbs = $this->rootPath . '/' . $logicalPath;
        $dstDir = \dirname($dstAbs);

        if (! \is_dir($dstDir)
            && ! @\mkdir($dstDir, self::DIR_MODE, true)
            && ! \is_dir($dstDir)
        ) {
            throw new RuntimeException(
                "LocalDiskStorage restore: não foi possível criar destino {$logicalPath}.",
            );
        }
        $this->assertWithinRoot($dstDir);
        $this->assertWithinRoot($srcAbs);
        if (! \is_file($srcAbs)) {
            throw new RuntimeException(
                "LocalDiskStorage restore: origem não é um arquivo restaurável para tombstone {$tombstoneId}.",
            );
        }

        if (\file_exists($dstAbs) || \is_link($dstAbs)) {
            throw new RuntimeException(
                "LocalDiskStorage restore: destino já existe para {$logicalPath}.",
            );
        }

        if (! @\rename($srcAbs, $dstAbs)) {
            throw new RuntimeException(
                "LocalDiskStorage restore: rename inverso falhou para tombstone {$tombstoneId}.",
            );
        }

        // Cleanup recursivo dos dirs vazios DENTRO do tombstone (idempotente).
        $this->removeEmptyDirsUpward(\dirname($srcAbs), $tombstoneAbsDir);

        // Remove o dir do tombstone (deve estar vazio agora).
        @\rmdir($tombstoneAbsDir);

        TogareLogger::event(
            'info',
            'localdisk.storage.restore',
            "LocalDisk restoreFromTombstone {$tombstoneId} → {$logicalPath}",
            [
                'tombstoneId' => $tombstoneId,
                'restoredLogicalPath' => $logicalPath,
            ],
        );
    }

    /**
     * Recursive scan dentro do tombstone — retorna o path do único arquivo
     * relativo a `$tombstoneAbsDir`. Throw se houver zero ou mais de um.
     *
     * Profundidade máxima 32 (defesa contra recursão patológica — mesmo
     * pattern do NextcloudPurgeableStorage:116-148).
     */
    private function findSingleRestorableFile(string $tombstoneAbsDir): string
    {
        $files = $this->collectFiles($tombstoneAbsDir, '', 0);
        if ($files === []) {
            throw new RuntimeException(
                "Tombstone {$tombstoneAbsDir} está vazio — nada a restaurar.",
            );
        }
        if (\count($files) > 1) {
            throw new RuntimeException(
                "Tombstone {$tombstoneAbsDir} contém múltiplos arquivos — restore ambíguo.",
            );
        }

        return $files[0];
    }

    /**
     * @return list<string> paths relativos a $baseDir; ordenados deterministicamente.
     */
    private function collectFiles(string $baseDir, string $prefix, int $depth): array
    {
        if ($depth > 32) {
            throw new RuntimeException(
                "Tombstone {$baseDir} excedeu profundidade máxima de restore.",
            );
        }

        $entries = @\scandir($baseDir);
        if ($entries === false) {
            return [];
        }
        \sort($entries);

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $baseDir . '/' . $entry;
            if (\is_link($full)) {
                $files[] = $prefix . $entry;
                continue;
            }
            if (\is_dir($full)) {
                $files = \array_merge(
                    $files,
                    $this->collectFiles($full, $prefix . $entry . '/', $depth + 1),
                );
                continue;
            }

            $files[] = $prefix . $entry;
        }

        return $files;
    }

    /**
     * Remove diretórios vazios subindo de $fromDir até (mas SEM incluir)
     * $stopDir. Idempotente — rmdir falha silenciosamente se dir não-vazio.
     */
    private function removeEmptyDirsUpward(string $fromDir, string $stopDir): void
    {
        $current = $fromDir;
        $maxIter = 32;
        while ($current !== $stopDir && $maxIter-- > 0) {
            if (! @\rmdir($current)) {
                break; // dir não-vazio ou já removido — para
            }
            $current = \dirname($current);
        }
    }

    private function validateTombstoneId(string $tombstoneId): void
    {
        if (\preg_match('/^[0-9a-f]{32}$/', $tombstoneId) !== 1) {
            throw new RuntimeException(
                "tombstoneId inválido (esperado 32 chars hex): {$tombstoneId}",
            );
        }
    }

    /**
     * Formata DateInterval em ISO 8601 duration ("PnYnMnDTnHnMnS"). Inline
     * por simplicidade — pattern idêntico ao NextcloudPurgeableStorage:159-187.
     */
    private function formatRetentionIso(DateInterval $retention): string
    {
        $parts = '';
        if ($retention->y > 0) {
            $parts .= $retention->y . 'Y';
        }
        if ($retention->m > 0) {
            $parts .= $retention->m . 'M';
        }
        if ($retention->d > 0) {
            $parts .= $retention->d . 'D';
        }
        $time = '';
        if ($retention->h > 0) {
            $time .= $retention->h . 'H';
        }
        if ($retention->i > 0) {
            $time .= $retention->i . 'M';
        }
        if ($retention->s > 0) {
            $time .= $retention->s . 'S';
        }
        if ($parts === '' && $time === '') {
            return 'P0D';
        }

        return 'P' . $parts . ($time !== '' ? 'T' . $time : '');
    }
}
