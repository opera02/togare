<?php

declare(strict_types=1);

namespace Espo\Modules\TogareNextcloudBridge\Services;

use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareNextcloudBridge\Contracts\NextcloudClientContract;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudFileNotFoundException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Implementação WebDAV/Nextcloud do FileStorageContract da togare-core.
 *
 * Story 5.1: primeira implementação concreta. Caller injeta
 * FileStorageContract via DI e nem precisa saber que está conversando com
 * Nextcloud — Binding registra a resolução. Story 6.0 entregará uma 2ª
 * implementação (`LocalDiskStorage`) para ambientes sem Epic 5 instalado.
 *
 * Paths LÓGICOS relativos a `togare/` no Nextcloud (ex.:
 * `clientes/abc/contratos/2026-001.pdf`). Path absoluto (`/...`) ou com
 * `..` é rejeitado — defesa contra path traversal.
 */
class NextcloudFileStorage implements FileStorageContract
{
    public function __construct(
        protected readonly NextcloudClientContract $client,
    ) {
    }

    public function put(string $logicalPath, string $binaryContent): void
    {
        $this->validateLogicalPath($logicalPath);

        $start = \microtime(true);
        $this->client->putWebDav($logicalPath, $binaryContent);
        TogareLogger::event(
            'info',
            'nextcloud.storage.put',
            "Nextcloud put {$logicalPath}",
            [
                'logicalPath' => $logicalPath,
                'sizeBytes' => \strlen($binaryContent),
                'durationMs' => (int) ((\microtime(true) - $start) * 1000),
            ],
        );
    }

    public function get(string $logicalPath): string
    {
        $this->validateLogicalPath($logicalPath);

        $start = \microtime(true);
        try {
            $bytes = $this->client->getWebDav($logicalPath);
        } catch (NextcloudFileNotFoundException $e) {
            TogareLogger::event(
                'warning',
                'nextcloud.storage.miss',
                "Nextcloud get {$logicalPath} retornou 404",
                ['logicalPath' => $logicalPath],
            );
            // FileStorageContract::get() prevê RuntimeException se file não existe.
            // Mantém tipo do contrato + permite caller fazer instanceof
            // NextcloudFileNotFoundException pra distinguir.
            throw new RuntimeException(
                "Arquivo não encontrado no Nextcloud: {$logicalPath}",
                0,
                $e,
            );
        }

        TogareLogger::event(
            'debug',
            'nextcloud.storage.get',
            "Nextcloud get {$logicalPath}",
            [
                'logicalPath' => $logicalPath,
                'sizeBytes' => \strlen($bytes),
                'durationMs' => (int) ((\microtime(true) - $start) * 1000),
            ],
        );

        return $bytes;
    }

    public function exists(string $logicalPath): bool
    {
        $this->validateLogicalPath($logicalPath);

        return $this->client->existsWebDav($logicalPath);
    }

    public function delete(string $logicalPath): void
    {
        $this->validateLogicalPath($logicalPath);

        // OcsApiClient::deleteWebDav é idempotente (404 silencioso) e devolve
        // se algo existia para manter o log canônico no nível FileStorage.
        $deleted = $this->client->deleteWebDav($logicalPath);
        TogareLogger::event(
            'info',
            'nextcloud.storage.delete',
            "Nextcloud delete {$logicalPath}",
            ['logicalPath' => $logicalPath, 'was404' => ! $deleted],
        );
    }

    /**
     * Story 6.0 Decisão #6 — esquema canônico `nextcloud://`. Função pura
     * sobre string; não consulta o WebDAV, mas valida a mesma gramática de
     * path lógico que put/get/exists/delete. Caller persiste o URI opaco.
     */
    public function buildUri(string $logicalPath): string
    {
        $this->validateLogicalPath($logicalPath);

        return 'nextcloud://' . $logicalPath;
    }

    /**
     * Defesa contra path absoluto, traversal e empty.
     *
     * @throws InvalidArgumentException
     */
    protected function validateLogicalPath(string $logicalPath): void
    {
        if ($logicalPath === '') {
            throw new InvalidArgumentException('logicalPath não pode ser vazio.');
        }
        if (\str_starts_with($logicalPath, '/')) {
            throw new InvalidArgumentException(
                "logicalPath deve ser relativo (sem '/' inicial); recebido: {$logicalPath}",
            );
        }
        if (\str_contains($logicalPath, '\\') || \preg_match('/[\x00-\x1F\x7F]/', $logicalPath) === 1) {
            throw new InvalidArgumentException(
                "logicalPath contém caracteres inválidos; recebido: {$logicalPath}",
            );
        }
        foreach (\explode('/', $logicalPath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException(
                    "logicalPath contém segmento inválido; recebido: {$logicalPath}",
                );
            }
        }
    }
}
