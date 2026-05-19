<?php

declare(strict_types=1);

namespace Espo\Modules\TogareSpikeS1\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\Forbidden;

/**
 * Controller THROWAWAY da Spike 1b.S1 — NÃO USAR EM PRODUÇÃO.
 *
 * Validação da receita X-Accel-Redirect equivalente no Caddy v2 (ADR 0004).
 * A URL pública é `/api/v1/Spike/action/download` (mapeada via routes.json)
 * mesmo que o nome da classe seja TogareSpike (R1 do validator).
 *
 * Query params:
 *   path       : nome relativo do arquivo dentro do volume nextcloud_data_spike.
 *                Mock ACL aceita apenas 'test-200mb.pdf' ou prefixo 'spike-'.
 *   use_proxy  : se 'php', ativa o branch fallback (AC3) — streama bytes via
 *                readfile() em chunks de 1 MB, sem usar X-Accel-Redirect.
 *                Qualquer outro valor (ou ausência) usa o caminho primário
 *                X-Accel (AC2) — retorna header e body vazio, Caddy serve.
 */
class TogareSpike
{
    private const INTERNAL_MOUNT = '/internal-files';
    private const NEXTCLOUD_DATA_SUBPATH = '/data';
    private const PDF_CHUNK_SIZE = 1048576; // 1 MiB

    /**
     * Mock ACL: em produção (Story 5.3) a validação real passa pelo
     * togare-nextcloud-bridge. Aqui só confirma o contrato com um shortcut.
     */
    private function mockAclAllows(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Rejeita path traversal e separadores de diretório.
        if (str_contains($path, '..') || str_contains($path, "\0") || str_contains($path, '/') || str_contains($path, '\\')) {
            return false;
        }

        // Aceita apenas o arquivo de teste canônico ou arquivos com prefixo 'spike-'.
        return $path === 'test-200mb.pdf' || str_starts_with($path, 'spike-');
    }

    public function getActionDownload(Request $request, Response $response): Response
    {
        // is_string guard: getQueryParam pode retornar array para params repetidos.
        $rawPath = $request->getQueryParam('path');
        $path = is_string($rawPath) ? $rawPath : '';
        $rawProxy = $request->getQueryParam('use_proxy');
        $useProxy = is_string($rawProxy) ? $rawProxy : '';

        if (! $this->mockAclAllows($path)) {
            throw new Forbidden('Path negado pelo mock ACL do spike.');
        }

        if ($useProxy === 'php') {
            // AC3 — sanity do plano B (PHP-proxy via readfile chunked).
            $this->servePhpProxy($path);
            // servePhpProxy sai do processo via exit. Nunca chega aqui.
        }

        // AC2 — caminho primário: retorna header X-Accel-Redirect + body vazio.
        // O Caddy (handle_response) intercepta o header e serve o arquivo do
        // mount /internal-files/ direto do filesystem. EspoCRM não toca bytes.
        //
        // Importante: NÃO chamar $response->getBody()->write(...) — qualquer
        // write empurra body e contradiz o contrato do X-Accel-Redirect.
        //
        // EspoCRM 9.3 usa ResponseWrapper com setHeader (fluente) — NÃO o
        // withHeader imutável do PSR-7 puro.
        $response->setHeader('X-Accel-Redirect', self::INTERNAL_MOUNT . self::NEXTCLOUD_DATA_SUBPATH . '/' . $path);
        $response->setHeader('Content-Type', 'application/pdf');

        return $response;
    }

    /**
     * Branch fallback (AC3) — PHP-proxy chunked.
     *
     * Lê o arquivo do mesmo volume nextcloud_data_spike montado read-only no
     * container espocrm-spike em /internal-files (ver docker-compose.spike.yml).
     *
     * Chunks de 1 MiB + ob_end_clean + flush + set_time_limit(0) mantêm
     * memory_limit irrelevante. Worker FPM fica ocupado durante todo o
     * download — esperado no fallback.
     *
     * Sai do processo via exit() para não deixar o framework serializar nada.
     */
    private function servePhpProxy(string $path): void
    {
        $absPath = self::INTERNAL_MOUNT . self::NEXTCLOUD_DATA_SUBPATH . '/' . $path;

        if (! is_file($absPath) || ! is_readable($absPath)) {
            http_response_code(404);
            echo 'Arquivo não encontrado no mount do spike.';
            exit;
        }

        // Limpa buffers pendentes. Sem isso, PHP acumula bytes em memória.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $fileSize = filesize($absPath);
        if ($fileSize === false) {
            http_response_code(500);
            echo 'Falha ao obter tamanho do arquivo.';
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . $fileSize);
        // Precaução: desabilita buffering em proxies intermediários.
        header('X-Accel-Buffering: no');

        set_time_limit(0);

        $handle = fopen($absPath, 'rb');
        if ($handle === false) {
            http_response_code(500);
            echo 'Falha ao abrir arquivo.';
            exit;
        }

        while (! feof($handle)) {
            $chunk = fread($handle, self::PDF_CHUNK_SIZE);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            flush();
        }
        fclose($handle);
        exit;
    }
}
