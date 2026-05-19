<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Contracts;

/**
 * Armazenamento de arquivos abstrato. Implementação concreta
 * NextcloudFileStorage em togare-nextcloud-bridge (Story 5.1) usa OCS API +
 * WebDAV. Para dev local, ambientes sem bridge instalado ou fallback de
 * continuidade, `LocalDiskStorage` em togare-core (Story 6.0) persiste em
 * filesystem nativo.
 *
 * Paths são LÓGICOS (ex.: 'clientes/abc/contratos/2026-contrato-001.pdf'),
 * não filesystem paths. A implementação resolve para URI/WebDAV apropriada.
 *
 * Override semântico: TogareCore Binding registra LocalDiskPurgeableStorage
 * somente quando a bridge não está instalada/autoloadable. Quando
 * TogareNextcloudBridge existe, o core pula os binds de storage e deixa o
 * Binding da bridge resolver FileStorageContract para NextcloudFileStorage.
 */
interface FileStorageContract
{
    /**
     * Grava $binaryContent em $logicalPath. Sobrescreve se já existir.
     * Cria diretórios pai conforme necessário.
     */
    public function put(string $logicalPath, string $binaryContent): void;

    /**
     * Recupera o conteúdo binário de $logicalPath.
     *
     * @throws \RuntimeException se o arquivo não existe
     */
    public function get(string $logicalPath): string;

    public function exists(string $logicalPath): bool;

    /**
     * Remove (hard-delete) o arquivo. Para delete com retenção/tombstone,
     * usar PurgeableStorageContract::softPurge().
     */
    public function delete(string $logicalPath): void;

    /**
     * Retorna o URI canônico opaco para o $logicalPath. Deve validar a mesma
     * gramática de path lógico de put/get/exists/delete. Implementações usam
     * esquema próprio:
     *   - NextcloudFileStorage   → "nextcloud://<logicalPath>"
     *   - LocalDiskStorage       → "local://<logicalPath>"
     *
     * Caller deve persistir o URI retornado SEM inspecionar o esquema
     * (princípio storage-agnóstico — Story 6.0 Decisão #6). Story 6.1
     * ContratoHonorarios é o primeiro consumer; entity Documento (Epic 5)
     * ainda usa scheme literal hardcoded por compatibilidade (Decisão #7).
     */
    public function buildUri(string $logicalPath): string;
}
