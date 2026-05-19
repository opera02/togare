<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Storage;

use Espo\Modules\TogareCore\Contracts\FileStorageContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use InvalidArgumentException;
use RuntimeException;

/**
 * Implementação filesystem nativo do FileStorageContract da togare-core.
 *
 * Story 6.0 — fallback default registrado em TogareCore Binding.php quando o
 * bridge não está instalado/autoloadable. Em deployment sem bridge, este é o
 * storage que atende FR19/FR20/FR21 (Document) + FR22 (Contrato Honorários
 * Story 6.1) + FR24 (Fatura Story 6.3). Quando o bridge existe, o core pula
 * estes binds e deixa NextcloudFileStorage vencer.
 *
 * Paths LÓGICOS relativos a `$rootPath` (ex.: 'clientes/abc/contratos/2026-001.pdf').
 * Path absoluto (`/...`) ou com `..` é rejeitado — defesa contra path traversal.
 *
 * Defesa em profundidade (Decisão #8 — 3 camadas):
 *   1. validateLogicalPath — regex anti-traversal idêntica ao bridge.
 *   2. realpath() guard — assertWithinRoot verifica que dirs e arquivos
 *      resolvidos estão DENTRO do rootRealpath (defesa contra symlink injection).
 *   3. rootRealpath cacheado no construtor — root próprio não pode ser symlink
 *      apontando para fora.
 *
 * Permissões (Decisão #9 — principle of least privilege):
 *   - file = 0640 (rw owner; r group; none other)
 *   - dir  = 0750 (rwx owner; rx group; none other)
 * Owner esperado: www-data (uid do PHP-FPM no container espocrm).
 *
 * NÃO cifra payload em repouso — NFR38/FR38 são responsabilidade do host
 * (LUKS/dm-crypt no volume Docker; ver docs/decisoes/0XXX-storage-encryption-at-rest.md
 * Balde C Epic 10).
 */
class LocalDiskStorage implements FileStorageContract
{
    /** File mode 0640 (rw owner / r group / none other). */
    public const FILE_MODE = 0640;

    /** Dir mode 0750 (rwx owner / rx group / none other). */
    public const DIR_MODE = 0750;

    /** Cache do realpath($rootPath) — resolvido no construtor. */
    protected readonly string $rootRealpath;

    public function __construct(
        protected readonly string $rootPath,
    ) {
        if ($this->rootPath === '') {
            throw new RuntimeException('LocalDiskStorage: rootPath não pode ser vazio.');
        }
        if (! \str_starts_with($this->rootPath, '/')) {
            throw new RuntimeException(
                "LocalDiskStorage: rootPath deve ser absoluto (recebido: {$this->rootPath})",
            );
        }

        // mkdir race-safe — `mkdir` retorna false se outro processo concorrente já criou.
        // Re-check via is_dir resolve race; segundo `is_dir` torna o helper idempotente.
        if (! \is_dir($this->rootPath)
            && ! @\mkdir($this->rootPath, self::DIR_MODE, true)
            && ! \is_dir($this->rootPath)
        ) {
            throw new RuntimeException(
                "LocalDiskStorage: não foi possível criar rootPath {$this->rootPath}.",
            );
        }

        if (! \is_writable($this->rootPath)) {
            throw new RuntimeException(
                "LocalDiskStorage: rootPath não é gravável: {$this->rootPath}.",
            );
        }

        $resolved = \realpath($this->rootPath);
        if ($resolved === false) {
            throw new RuntimeException(
                "LocalDiskStorage: realpath() falhou para rootPath {$this->rootPath}.",
            );
        }
        $this->rootRealpath = $resolved;
    }

    public function put(string $logicalPath, string $binaryContent): void
    {
        $this->validateLogicalPath($logicalPath);
        $absolute = $this->rootPath . '/' . $logicalPath;
        $absoluteDir = \dirname($absolute);

        $start = \microtime(true);

        // Criar dirs pai. mkdir já é recursive=true; idempotente via is_dir post-check.
        if (! \is_dir($absoluteDir)
            && ! @\mkdir($absoluteDir, self::DIR_MODE, true)
            && ! \is_dir($absoluteDir)
        ) {
            throw new RuntimeException(
                "LocalDiskStorage: não foi possível criar diretório pai para {$logicalPath}.",
            );
        }

        // Camada 3 — assertWithinRoot pós-mkdir. Resolve o parent recém-criado e
        // confirma que está DENTRO do rootRealpath (bloqueia symlink injection
        // mesmo se validateLogicalPath fosse contornada).
        $this->assertWithinRoot($absoluteDir);

        // Permissão explícita no dir pode ter sido afetada por umask.
        @\chmod($absoluteDir, self::DIR_MODE);

        // Escrita atômica real para leitores/backup: grava temp no mesmo dir e
        // promove via rename(). LOCK_EX sozinho só protege writers cooperativos.
        $tmpAbs = $this->buildTempPath($absoluteDir, \basename($absolute));
        $writtenBytes = @\file_put_contents($tmpAbs, $binaryContent, \LOCK_EX);
        if ($writtenBytes === false) {
            @\unlink($tmpAbs);
            throw new RuntimeException(
                "LocalDiskStorage: file_put_contents falhou para {$logicalPath}.",
            );
        }
        @\chmod($tmpAbs, self::FILE_MODE);

        if (! @\rename($tmpAbs, $absolute)) {
            @\unlink($tmpAbs);
            throw new RuntimeException(
                "LocalDiskStorage: rename atômico falhou para {$logicalPath}.",
            );
        }

        // Mode 0640 explícito (rename preserva o chmod do temp, mas reforça).
        @\chmod($absolute, self::FILE_MODE);

        TogareLogger::event(
            'info',
            'localdisk.storage.put',
            "LocalDisk put {$logicalPath}",
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
        $absolute = $this->rootPath . '/' . $logicalPath;

        if (! \is_file($absolute)) {
            TogareLogger::event(
                'warning',
                'localdisk.storage.miss',
                "LocalDisk get {$logicalPath} — arquivo não encontrado",
                ['logicalPath' => $logicalPath],
            );
            throw new RuntimeException(
                "Arquivo não encontrado no disco local: {$logicalPath}",
            );
        }
        $this->assertWithinRoot($absolute);

        $start = \microtime(true);
        $bytes = @\file_get_contents($absolute);
        if ($bytes === false) {
            throw new RuntimeException(
                "LocalDiskStorage: file_get_contents falhou para {$logicalPath}.",
            );
        }

        TogareLogger::event(
            'debug',
            'localdisk.storage.get',
            "LocalDisk get {$logicalPath}",
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
        $absolute = $this->rootPath . '/' . $logicalPath;

        if (! \is_file($absolute)) {
            return false;
        }

        $this->assertWithinRoot($absolute);

        return true;
    }

    public function delete(string $logicalPath): void
    {
        $this->validateLogicalPath($logicalPath);
        $absolute = $this->rootPath . '/' . $logicalPath;

        $was404 = ! \is_file($absolute);
        if (! $was404) {
            $this->assertWithinRoot($absolute);
            if (! @\unlink($absolute)) {
                throw new RuntimeException(
                    "LocalDiskStorage: unlink falhou para {$logicalPath}.",
                );
            }
        }

        TogareLogger::event(
            'info',
            'localdisk.storage.delete',
            "LocalDisk delete {$logicalPath}",
            ['logicalPath' => $logicalPath, 'was404' => $was404],
        );
    }

    /**
     * Story 6.0 Decisão #6 — esquema canônico `local://`. Função pura sobre
     * string; não consulta filesystem, mas valida a mesma gramática de path
     * lógico que put/get/exists/delete para evitar URI impossível.
     */
    public function buildUri(string $logicalPath): string
    {
        $this->validateLogicalPath($logicalPath);

        return 'local://' . $logicalPath;
    }

    protected function buildTempPath(string $absoluteDir, string $basename): string
    {
        for ($i = 0; $i < 5; $i++) {
            $candidate = $absoluteDir
                . '/.'
                . $basename
                . '.'
                . \bin2hex(\random_bytes(8))
                . '.tmp';

            if (! \file_exists($candidate) && ! \is_link($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('LocalDiskStorage: não foi possível gerar caminho temporário único.');
    }

    /**
     * Validação anti-traversal — pattern idêntico ao NextcloudFileStorage:116-138.
     *
     * Rejeita: vazio, prefixo '/', backslash, control chars, segmentos '.' ou '..'.
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

    /**
     * Camada 3 da defesa anti-traversal — assert que o path existente resolvido
     * via realpath() está DENTRO do rootRealpath. Bloqueia symlink injection
     * mesmo se validateLogicalPath fosse contornada.
     *
     * Caller é responsável por garantir que $dirPath existe ANTES (mkdir já
     * rodou para diretórios, ou is_file confirmou arquivo). Se realpath()
     * retornar false, o caller tentou usar antes de criar — erro de programação.
     *
     * @throws InvalidArgumentException se $dirPath resolver fora do root
     * @throws RuntimeException se realpath() falhar inesperadamente
     */
    protected function assertWithinRoot(string $dirPath): void
    {
        $resolved = \realpath($dirPath);
        if ($resolved === false) {
            throw new RuntimeException(
                "LocalDiskStorage: realpath() falhou inesperadamente para {$dirPath}.",
            );
        }

        // Tem que ser idêntico ao root OU prefixo + DIRECTORY_SEPARATOR.
        // Sem o separator final, '/var/togare/local-storage-evil' passaria
        // o str_starts_with de '/var/togare/local-storage'.
        if ($resolved !== $this->rootRealpath
            && ! \str_starts_with($resolved, $this->rootRealpath . \DIRECTORY_SEPARATOR)
        ) {
            throw new InvalidArgumentException(
                "logicalPath resolveu fora do root (possível symlink injection): {$resolved}",
            );
        }
    }
}
