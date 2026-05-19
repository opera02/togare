<?php

declare(strict_types=1);

namespace Espo\Modules\TogareNextcloudBridge\Services;

use DateInterval;
use Espo\Modules\TogareCore\Contracts\PurgeableStorageContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudFileNotFoundException;
use RuntimeException;

/**
 * Implementação WebDAV/Nextcloud do PurgeableStorageContract da togare-core.
 *
 * Estende NextcloudFileStorage (herda put/get/exists/delete) e adiciona
 * `softPurge` e `restoreFromTombstone` — primitivas que Epic 8 LGPD usa
 * para janela de reversão antes de hard-delete (Decisão #5 da Story 5.1).
 *
 * Soft-purge faz WebDAV MOVE de `<logicalPath>` para
 * `.purged/<tombstoneId>/<logicalPath>` (preserva nome original sob a pasta
 * do tombstone — facilita auditoria humana). `tombstoneId =
 * bin2hex(random_bytes(16))` (32 chars hex — mesmo pattern do
 * `togare_ambiguity_log.id` da Story 4b.1b).
 *
 * Esta classe NÃO persiste tombstone metadata em tabela — o consumer
 * (Story 5.2 `SoftPurgeDocumentoHook` para Documento, Epic 8 futuro para
 * outras entidades) persiste em `togare_documento_log` da togare-core
 * (V018) gravando event=`documento.soft_purged` com payload JSON
 * `{tombstoneId, logicalPath, hardDeleteAt, retentionDays}`. A Story 5.5
 * `TogareBridgeHardDeleteJob` (cron diário) consome essa mesma tabela
 * gravando row IRMÃ event=`documento.hard_deleted` após a janela expirar
 * (Decisão #2 da 5.5 — ZERO tabela `togare_bridge_tombstones` nova).
 */
final class NextcloudPurgeableStorage extends NextcloudFileStorage implements PurgeableStorageContract
{
    public const PURGED_ROOT = '.purged';

    public function softPurge(string $logicalPath, DateInterval $retention): string
    {
        $this->validateLogicalPath($logicalPath);

        $tombstoneId = \bin2hex(\random_bytes(16));
        $tombstonePath = self::PURGED_ROOT . '/' . $tombstoneId . '/' . $logicalPath;

        $this->client->moveWebDav($logicalPath, $tombstonePath);

        TogareLogger::event(
            'info',
            'nextcloud.storage.softpurge',
            "Nextcloud softPurge {$logicalPath} → tombstone {$tombstoneId}",
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

        $tombstoneDir = self::PURGED_ROOT . '/' . $tombstoneId;

        try {
            $relativeOriginal = $this->findSingleRestorableEntry($tombstoneDir);
        } catch (NextcloudFileNotFoundException $e) {
            throw new RuntimeException(
                "Tombstone {$tombstoneId} não existe ou já foi purgado definitivamente.",
                0,
                $e,
            );
        }

        $sourcePath = $tombstoneDir . '/' . $relativeOriginal;
        $this->client->moveWebDav($sourcePath, $relativeOriginal);

        // Limpa o diretório vazio do tombstone (deleteWebDav é idempotente).
        $this->client->deleteWebDav($tombstoneDir);

        TogareLogger::event(
            'info',
            'nextcloud.storage.restore',
            "Nextcloud restoreFromTombstone {$tombstoneId} → {$relativeOriginal}",
            [
                'tombstoneId' => $tombstoneId,
                'restoredLogicalPath' => $relativeOriginal,
            ],
        );
    }

    /**
     * Descobre recursivamente o único arquivo dentro de `.purged/<id>/`.
     * `propfindList()` retorna filhos diretos; diretórios têm sufixo `/`.
     * Se houver zero ou mais de um arquivo, restaurar seria ambíguo.
     */
    private function findSingleRestorableEntry(string $tombstoneDir): string
    {
        $files = $this->collectRestorableFiles($tombstoneDir);
        if ($files === []) {
            throw new RuntimeException("Tombstone {$tombstoneDir} está vazio — nada a restaurar.");
        }
        if (\count($files) > 1) {
            throw new RuntimeException("Tombstone {$tombstoneDir} contém múltiplos arquivos — restore ambíguo.");
        }

        return $files[0];
    }

    /**
     * @return list<string>
     */
    private function collectRestorableFiles(string $baseDir, string $prefix = '', int $depth = 0): array
    {
        if ($depth > 32) {
            throw new RuntimeException("Tombstone {$baseDir} excedeu profundidade máxima de restore.");
        }

        $items = $this->client->propfindList($baseDir);
        \sort($items);
        $files = [];

        foreach ($items as $entry) {
            $entry = \trim($entry);
            if ($entry === '') {
                continue;
            }
            if (\str_ends_with($entry, '/')) {
                $dir = \rtrim($entry, '/');
                $files = \array_merge(
                    $files,
                    $this->collectRestorableFiles(
                        $baseDir . '/' . $dir,
                        $prefix . $dir . '/',
                        $depth + 1,
                    ),
                );
                continue;
            }

            $files[] = $prefix . $entry;
        }

        return $files;
    }

    private function validateTombstoneId(string $tombstoneId): void
    {
        if (! \preg_match('/^[0-9a-f]{32}$/', $tombstoneId)) {
            throw new RuntimeException(
                "tombstoneId inválido (esperado 32 chars hex): {$tombstoneId}",
            );
        }
    }

    private function formatRetentionIso(DateInterval $retention): string
    {
        // ISO 8601 duration "PnYnMnDTnHnMnS" — só inclui componentes não-zero.
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
