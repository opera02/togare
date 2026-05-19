<?php

declare(strict_types=1);

namespace Espo\Modules\TogareNextcloudBridge\Contracts;

use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudFileNotFoundException;
use Espo\Modules\TogareNextcloudBridge\Exception\NextcloudUnavailableException;

/**
 * Contrato de cliente Nextcloud — operações WebDAV/OCS.
 *
 * Implementação default: `Services\OcsApiClient` (consome
 * `nextcloud:80/remote.php/dav/files/<user>/togare/...` via cURL + Basic auth).
 * Adapter pluggable permite trocar fonte sem refatorar (NFR24 — futuros
 * backends S3/Azure declaram contracts paralelos).
 *
 * Caminhos passados a este contrato são `webdavPath` LÓGICOS relativos a
 * `togare/` (a implementação prefixa o root e resolve a URL completa).
 * Exemplo: `webdavPath = 'clientes/abc/2026-001.pdf'` →
 * `http://nextcloud:80/remote.php/dav/files/admin/togare/clientes/abc/2026-001.pdf`.
 */
interface NextcloudClientContract
{
    /**
     * PUT WebDAV em $webdavPath. Cria diretórios pai automaticamente
     * (chamadas MKCOL recursivas).
     *
     * @throws NextcloudUnavailableException após esgotar retries OU CB aberto.
     */
    public function putWebDav(string $webdavPath, string $binaryContent): void;

    /**
     * GET WebDAV. Retorna conteúdo binário.
     *
     * @throws NextcloudFileNotFoundException se 404.
     * @throws NextcloudUnavailableException após esgotar retries OU CB aberto.
     */
    public function getWebDav(string $webdavPath): string;

    /**
     * PROPFIND com depth=0 — checa existência. Retorna true se 207, false se 404.
     *
     * @throws NextcloudUnavailableException após esgotar retries OU CB aberto.
     */
    public function existsWebDav(string $webdavPath): bool;

    /**
     * DELETE WebDAV (hard-delete). Idempotente: 404 não relança.
     *
     * @return bool true se removeu/existia, false se já estava ausente (404).
     *
     * @throws NextcloudUnavailableException após esgotar retries OU CB aberto.
     */
    public function deleteWebDav(string $webdavPath): bool;

    /**
     * MOVE WebDAV (Destination: header). Cria diretórios pai automaticamente.
     *
     * @throws NextcloudFileNotFoundException se source 404.
     * @throws NextcloudUnavailableException após esgotar retries OU CB aberto.
     */
    public function moveWebDav(string $sourceWebDavPath, string $destinationWebDavPath): void;

    /**
     * PROPFIND depth=1 — lista filhos diretos de um diretório. Retorna paths
     * RELATIVOS ao diretório consultado (sem o próprio dir). Diretórios vêm
     * com sufixo `/`, arquivos sem sufixo. Útil para
     * `restoreFromTombstone()` descobrir o original-path sob `.purged/<id>/`.
     *
     * @return list<string>
     *
     * @throws NextcloudFileNotFoundException se diretório 404.
     * @throws NextcloudUnavailableException após esgotar retries OU CB aberto.
     */
    public function propfindList(string $webdavPath): array;

    /**
     * Resolve $logicalPath (ex.: 'clientes/abc/contratos/2026-001.pdf') para
     * URL WebDAV completa. Útil para Story 5.3 (Controller monta header
     * X-Accel-Redirect: /internal-nextcloud/<path-resolvido>).
     *
     * Convenção: prefixa com 'togare/' + sufixa em $logicalPath.
     */
    public function resolveWebDavUrl(string $logicalPath): string;

    /**
     * GET WebDAV em streaming — escreve bytes diretamente em $outputStream
     * (resource PHP, ex.: `fopen('php://output', 'wb')`). PHP nunca carrega
     * o body em string; adequado para arquivos grandes (PDFs ~200 MB).
     *
     * `$beforeFirstByte`, quando informado, é chamado depois de status HTTP 2xx
     * confirmado e antes do primeiro byte ser escrito. Isso permite que o
     * controller emita headers de download apenas quando não há erro WebDAV
     * pré-byte.
     *
     * Sem retry e sem contagem no circuit breaker (Decisão #8 da Story 5.3 —
     * download é single-shot; retry de stream interrompido é responsabilidade
     * do cliente). Pré-byte: status 5xx/timeout/connect-fail conta como CB
     * via dispatchUnavailableEvent('stream_failed') e lança
     * NextcloudUnavailableException ANTES de qualquer byte escrito.
     *
     * @param string        $webdavPath      path lógico (sem prefixo togare/ — o adapter prefixa).
     * @param resource      $outputStream    resource aberto para escrita (php://output, file handle).
     * @param callable|null $beforeFirstByte callback pré-primeiro-byte após status 2xx confirmado.
     *
     * @throws NextcloudFileNotFoundException se 404 (antes de qualquer byte escrito).
     * @throws NextcloudUnavailableException  em falha pré-byte (timeout, 5xx, connect-fail).
     * @throws \InvalidArgumentException      se $outputStream não for resource aberto.
     */
    public function streamWebDav(string $webdavPath, $outputStream, ?callable $beforeFirstByte = null): void;
}
