<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Entities\ContratoHonorarios;

/**
 * Constrói o logical path do ContratoHonorarios e parsing reverso da URI.
 *
 * Decisão #9 (Story 6.1): logical path =
 *   `clientes/<clienteId>/contratos/<contratoId>-<filename>`.
 *
 * Não usa o pattern de buckets {processos,clientes,prazos} do
 * `DocumentoLogicalPathBuilder` porque contrato é SEMPRE de Cliente (N:1
 * obrigatório); não há alternativa (single-context).
 *
 * Decisão #2 (Story 6.1) — primeira entity Togare a consumir
 * `FileStorageContract::buildUri()` agnóstico desde D0:
 *  - Bridge instalado → 'nextcloud://clientes/<id>/contratos/<contratoId>-<file>'
 *  - Bridge ausente   → 'local://clientes/<id>/contratos/<contratoId>-<file>'
 *
 * `extractLogicalPath(string $uri)` tolera AMBOS os esquemas (`nextcloud://` e
 * `local://`) — divergência intencional do `DocumentoLogicalPathBuilder` que
 * só tolera `nextcloud://`.
 *
 * Sanitização: copy literal de `DocumentoLogicalPathBuilder::sanitizeFilename`
 * (regex `[a-zA-Z0-9._-]`, transliteração ASCII, max 100 chars, fallback
 * `arquivo`).
 */
final class ContratoLogicalPathBuilder
{
    public const URI_SCHEME_NEXTCLOUD = 'nextcloud://';
    public const URI_SCHEME_LOCAL = 'local://';

    /** Subdir fixo do bucket de contratos (Decisão #9). */
    private const CONTRATOS_SUBDIR = 'contratos';

    /** Prefixo do bucket no logical path (Decisão #9). */
    private const BUCKET_CLIENTES = 'clientes';

    /**
     * Sanitiza filename preservando extensão original.
     *
     * Regra (copy literal de DocumentoLogicalPathBuilder::sanitizeFilename):
     *  - basename: `[^a-zA-Z0-9._-]` → `_`, depois colapsa `_+` em `_`,
     *    truncate.
     *  - extension: case original preservado, max 10 chars.
     *  - basename vazio após sanitização → "arquivo".
     *  - resultado total ≤ 100 chars.
     */
    public static function sanitizeFilename(string $original): string
    {
        $original = trim($original);
        if ($original === '') {
            return ContratoHonorarios::FILENAME_FALLBACK;
        }

        $lastDotPos = strrpos($original, '.');
        if ($lastDotPos === false || $lastDotPos === 0 || $lastDotPos === strlen($original) - 1) {
            $basename = $original;
            $extension = '';
        } else {
            $basename = substr($original, 0, $lastDotPos);
            $extension = substr($original, $lastDotPos + 1);
        }

        $basename = self::transliterate($basename);

        $basenameSan = preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename) ?? '';
        $basenameSan = preg_replace('/_+/', '_', $basenameSan) ?? '';
        $basenameSan = trim($basenameSan, '_');

        if ($basenameSan === '') {
            $basenameSan = ContratoHonorarios::FILENAME_FALLBACK;
        }

        $extensionSan = preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?? '';
        if (strlen($extensionSan) > 10) {
            $extensionSan = substr($extensionSan, 0, 10);
        }

        $maxBasenameLen = ContratoHonorarios::MAX_FILENAME_LENGTH - ($extensionSan === '' ? 0 : strlen($extensionSan) + 1);
        if ($maxBasenameLen < 1) {
            $maxBasenameLen = 1;
        }
        if (strlen($basenameSan) > $maxBasenameLen) {
            $basenameSan = substr($basenameSan, 0, $maxBasenameLen);
        }

        if ($extensionSan === '') {
            return $basenameSan;
        }

        return $basenameSan . '.' . $extensionSan;
    }

    private static function transliterate(string $value): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return strtr($value, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c', 'Ñ' => 'N', 'ñ' => 'n',
        ]);
    }

    /**
     * Monta o logical path final a partir de ContratoHonorarios + nome original do anexo.
     *
     * @throws \LogicException se o ContratoHonorarios não tiver clienteId ou ID.
     */
    public static function build(ContratoHonorarios $entity, string $originalFilename): string
    {
        $contratoId = (string) ($entity->getId() ?? '');
        if ($contratoId === '') {
            throw new \LogicException('ContratoHonorarios sem ID — Espo precisa gerar ID antes do build do logical path.');
        }

        $clienteId = (string) ($entity->get('clienteId') ?? '');
        if ($clienteId === '') {
            throw new \LogicException('ContratoHonorarios sem clienteId — Cliente é obrigatório.');
        }

        $sanitized = self::sanitizeFilename($originalFilename);

        return self::BUCKET_CLIENTES . '/' . $clienteId . '/' . self::CONTRATOS_SUBDIR . '/' . $contratoId . '-' . $sanitized;
    }

    /**
     * Extrai o logical path de uma URI `nextcloud://<path>` OU `local://<path>`.
     *
     * Decisão #2 — tolera AMBOS os esquemas (vs DocumentoLogicalPathBuilder que
     * só tolera `nextcloud://`).
     *
     * @throws \RuntimeException se a URI não tiver scheme conhecido.
     */
    public static function extractLogicalPath(string $uri): string
    {
        if (str_starts_with($uri, self::URI_SCHEME_NEXTCLOUD)) {
            return substr($uri, strlen(self::URI_SCHEME_NEXTCLOUD));
        }
        if (str_starts_with($uri, self::URI_SCHEME_LOCAL)) {
            return substr($uri, strlen(self::URI_SCHEME_LOCAL));
        }
        throw new \RuntimeException(
            'URI inválida — esperado prefixo "' . self::URI_SCHEME_NEXTCLOUD . '" ou "'
            . self::URI_SCHEME_LOCAL . '", recebido: ' . $uri,
        );
    }

    /**
     * Faz parse reverso da URI lógica em componentes.
     *
     * @return array{scheme: string, bucket: string, clienteId: string, subdir: string, contratoId: string, filename: string}
     * @throws \RuntimeException se URI ou logical path malformados.
     */
    public static function parseFromUri(string $uri): array
    {
        $scheme = self::detectScheme($uri);
        $logicalPath = self::extractLogicalPath($uri);
        $segments = explode('/', $logicalPath);
        if (count($segments) !== 4) {
            throw new \RuntimeException(
                'Logical path malformado — esperado <bucket>/<clienteId>/<subdir>/<contratoId-filename>, recebido: ' . $logicalPath,
            );
        }

        [$bucket, $clienteId, $subdir, $tail] = $segments;
        if ($bucket !== self::BUCKET_CLIENTES) {
            throw new \RuntimeException('Bucket inválido: ' . $bucket);
        }
        if ($subdir !== self::CONTRATOS_SUBDIR) {
            throw new \RuntimeException('Subdir inválido: ' . $subdir);
        }

        $dashPos = strpos($tail, '-');
        if ($dashPos === false || $dashPos === 0) {
            throw new \RuntimeException('Tail do logical path malformado — esperado <contratoId>-<filename>, recebido: ' . $tail);
        }

        $contratoId = substr($tail, 0, $dashPos);
        $filename = substr($tail, $dashPos + 1);

        return [
            'scheme' => $scheme,
            'bucket' => $bucket,
            'clienteId' => $clienteId,
            'subdir' => $subdir,
            'contratoId' => $contratoId,
            'filename' => $filename,
        ];
    }

    private static function detectScheme(string $uri): string
    {
        if (str_starts_with($uri, self::URI_SCHEME_NEXTCLOUD)) {
            return 'nextcloud';
        }
        if (str_starts_with($uri, self::URI_SCHEME_LOCAL)) {
            return 'local';
        }
        return '';
    }
}
