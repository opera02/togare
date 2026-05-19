<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Entities\Documento;

/**
 * Constrói o logical path do Documento e parsing reverso da URI lógica.
 *
 * Decisão #4 (Story 5.2) + Decisão #2 (Story 5.6): logical path =
 *   `<bucket>/<entityId>/<documentoId>-<filename>`.
 *  - bucket = 'processos', 'clientes' OU 'prazos' (Story 5.6 adicionou prazos).
 *  - entityId = processoId, clienteId OU prazoId.
 *  - documentoId = Espo Entity ID (gerado em beforeSave).
 *  - filename = sanitizeFilename(original).
 *
 * Decisão #7: sanitizeFilename = `[a-zA-Z0-9._-]`, replace outros por `_`,
 * trim consecutive underscores, preserve extension, max 100 chars total,
 * fallback "arquivo" se basename zerado.
 *
 * URI persistida em `Documento.nextcloudUri`:
 *   `nextcloud://<logicalPath>` (sem host, sem user; bridge resolve internamente).
 *
 * Métodos:
 *  - sanitizeFilename(string): string  — puro, testável.
 *  - build(Documento, originalFilename): string  — logical path completo.
 *  - parseFromUri(string): array{bucket,entityId,documentoId,filename}
 *    — útil para SoftPurgeDocumentoHook extrair logicalPath.
 */
final class DocumentoLogicalPathBuilder
{
    /**
     * Sanitiza filename preservando extensão original.
     *
     * Regra (Decisão #7):
     *  - basename: `[^a-zA-Z0-9._-]` → `_`, depois colapsa `_+` em `_`,
     *    truncate a 90 chars (deixa folga p/ extensão).
     *  - extension: case original preservado, max 10 chars.
     *  - basename vazio após sanitização → "arquivo".
     *  - resultado total ≤ 100 chars.
     */
    public static function sanitizeFilename(string $original): string
    {
        $original = trim($original);
        if ($original === '') {
            return Documento::FILENAME_FALLBACK;
        }

        // Separa basename + extension. Extension = última ocorrência de "." se houver.
        $lastDotPos = strrpos($original, '.');
        if ($lastDotPos === false || $lastDotPos === 0 || $lastDotPos === strlen($original) - 1) {
            // Sem ponto, ponto inicial (.htaccess), ou ponto final → tratar tudo como basename.
            $basename = $original;
            $extension = '';
        } else {
            $basename = substr($original, 0, $lastDotPos);
            $extension = substr($original, $lastDotPos + 1);
        }

        $basename = self::transliterate($basename);

        // Sanitiza basename.
        $basenameSan = preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename) ?? '';
        $basenameSan = preg_replace('/_+/', '_', $basenameSan) ?? '';
        // Strip leading/trailing _ (estética).
        $basenameSan = trim($basenameSan, '_');

        if ($basenameSan === '') {
            $basenameSan = Documento::FILENAME_FALLBACK;
        }

        // Sanitiza extension defensivamente (extensões ESC/EXE-like com caracteres exóticos
        // ainda não passariam aqui se o MIME allowlist passou — mas defesa em profundidade).
        $extensionSan = preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?? '';
        if (strlen($extensionSan) > 10) {
            $extensionSan = substr($extensionSan, 0, 10);
        }

        // Trunca basename para deixar folga.
        $maxBasenameLen = Documento::MAX_FILENAME_LENGTH - ($extensionSan === '' ? 0 : strlen($extensionSan) + 1);
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
     * Monta o logical path final a partir de Documento + nome original do anexo.
     *
     * @throws \LogicException se o Documento não tiver nem processoId nem clienteId
     *                        (ValidateDocumentoFieldsHook bloqueia em runtime —
     *                        defesa em profundidade aqui).
     * @throws \LogicException se documentoId for null (Espo deve gerar antes).
     */
    public static function build(Documento $entity, string $originalFilename): string
    {
        $documentoId = (string) ($entity->getId() ?? '');
        if ($documentoId === '') {
            throw new \LogicException('Documento sem ID — Espo precisa gerar ID antes do build do logical path.');
        }

        $processoId = (string) ($entity->get('processoId') ?? '');
        $clienteId = (string) ($entity->get('clienteId') ?? '');
        $prazoId = (string) ($entity->get('prazoId') ?? '');

        $setCount = ($processoId !== '' ? 1 : 0)
            + ($clienteId !== '' ? 1 : 0)
            + ($prazoId !== '' ? 1 : 0);

        if ($setCount !== 1) {
            throw new \LogicException(\sprintf(
                'Documento sem XOR único — esperado exatamente 1 de processoId/clienteId/prazoId, encontrado %d.',
                $setCount,
            ));
        }

        if ($processoId !== '') {
            $bucket = Documento::BUCKET_PROCESSOS;
            $entityId = $processoId;
        } elseif ($clienteId !== '') {
            $bucket = Documento::BUCKET_CLIENTES;
            $entityId = $clienteId;
        } else {
            $bucket = Documento::BUCKET_PRAZOS;
            $entityId = $prazoId;
        }

        $sanitized = self::sanitizeFilename($originalFilename);

        return $bucket . '/' . $entityId . '/' . $documentoId . '-' . $sanitized;
    }

    /**
     * Extrai logicalPath de uma URI `nextcloud://<logicalPath>`.
     *
     * @throws \RuntimeException se a URI não tiver scheme nextcloud://.
     */
    public static function extractLogicalPath(string $uri): string
    {
        $scheme = Documento::URI_SCHEME;
        if (!str_starts_with($uri, $scheme)) {
            throw new \RuntimeException('URI inválida — esperado prefixo "' . $scheme . '", recebido: ' . $uri);
        }

        return substr($uri, strlen($scheme));
    }

    /**
     * Faz o parse reverso da URI lógica em componentes: bucket, entityId, documentoId, filename.
     *
     * @return array{bucket: string, entityId: string, documentoId: string, filename: string}
     * @throws \RuntimeException se URI ou logical path malformados.
     */
    public static function parseFromUri(string $uri): array
    {
        $logicalPath = self::extractLogicalPath($uri);
        $segments = explode('/', $logicalPath);
        if (count($segments) !== 3) {
            throw new \RuntimeException('Logical path malformado — esperado <bucket>/<entityId>/<documentoId-filename>, recebido: ' . $logicalPath);
        }

        [$bucket, $entityId, $tail] = $segments;
        if ($bucket !== Documento::BUCKET_PROCESSOS
            && $bucket !== Documento::BUCKET_CLIENTES
            && $bucket !== Documento::BUCKET_PRAZOS
        ) {
            throw new \RuntimeException('Bucket inválido: ' . $bucket);
        }

        $dashPos = strpos($tail, '-');
        if ($dashPos === false || $dashPos === 0) {
            throw new \RuntimeException('Tail do logical path malformado — esperado <documentoId>-<filename>, recebido: ' . $tail);
        }

        $documentoId = substr($tail, 0, $dashPos);
        $filename = substr($tail, $dashPos + 1);

        return [
            'bucket' => $bucket,
            'entityId' => $entityId,
            'documentoId' => $documentoId,
            'filename' => $filename,
        ];
    }
}
