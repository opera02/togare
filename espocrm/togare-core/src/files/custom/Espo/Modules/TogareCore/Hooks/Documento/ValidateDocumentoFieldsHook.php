<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Documento;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\Documento;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Valida XOR triplo Processo/Cliente/Prazo + MIME + size + popula filename/mimeType/sizeBytes
 * a partir do Attachment temporário (Story 5.2 — Decisões #2, #5, #6; Story 5.6 — Decisão #1).
 *
 * Order = 10 (executa antes de DefaultUploadedBy=15 e Move=30).
 *
 * @implements BeforeSave<Documento>
 */
final class ValidateDocumentoFieldsHook implements BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly Acl $acl,
        private readonly User $user,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (! $entity instanceof Documento) {
            return;
        }

        $this->validateXor($entity);
        $this->validateLinkMutation($entity);

        if (! $entity->isNew()) {
            return;
        }

        $this->validateParentAccess($entity);
        $attachment = $this->loadAttachment($entity);
        $this->validateAttachmentContext($attachment);
        $this->validateMimeType($attachment);
        $this->validateSize($attachment);

        $entity->set([
            'filename' => self::sanitizeForStorage((string) $attachment->get('name')),
            'mimeType' => (string) $attachment->get('type'),
            'sizeBytes' => (int) $attachment->get('size'),
        ]);
    }

    /**
     * Sanitização que vai pro storage (não afeta logical path no Nextcloud — esse
     * passa por DocumentoLogicalPathBuilder::sanitizeFilename separadamente).
     * Aqui só evita truncamento/coerção surpresa: trim + maxLen 100.
     */
    private static function sanitizeForStorage(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return Documento::FILENAME_FALLBACK;
        }
        if (mb_strlen($name) > Documento::MAX_FILENAME_LENGTH) {
            $name = mb_substr($name, 0, Documento::MAX_FILENAME_LENGTH);
        }
        return $name;
    }

    private function validateLinkMutation(Documento $entity): void
    {
        if ($entity->isNew()) {
            return;
        }

        if ($entity->isAttributeChanged('processoId')
            || $entity->isAttributeChanged('clienteId')
            || $entity->isAttributeChanged('prazoId')
        ) {
            throw BadRequest::createWithBody(
                'Não é possível reatribuir um Documento já enviado. Remova e anexe novamente no destino correto.',
                json_encode([
                    'reason' => 'documento_link_immutable',
                    'message' => 'Não é possível reatribuir um Documento já enviado. Remova e anexe novamente no destino correto.',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }
    }

    /**
     * XOR triplo (Story 5.6 Decisão #1): EXATAMENTE UM de processoId/clienteId/prazoId.
     * Rejeita 0 setados (xorAllNull) ou ≥2 setados (xorMultipleSet).
     */
    private function validateXor(Documento $entity): void
    {
        $processoId = $entity->get('processoId');
        $clienteId = $entity->get('clienteId');
        $prazoId = $entity->get('prazoId');

        $hasProcesso = $processoId !== null && $processoId !== '';
        $hasCliente = $clienteId !== null && $clienteId !== '';
        $hasPrazo = $prazoId !== null && $prazoId !== '';

        $count = ($hasProcesso ? 1 : 0) + ($hasCliente ? 1 : 0) + ($hasPrazo ? 1 : 0);

        if ($count === 0) {
            TogareLogger::event(
                'warning',
                'documento.create_rejected',
                'ValidateDocumentoFieldsHook: XOR violation — todos null',
                ['reason' => 'xor_violation', 'detail' => 'all_null', 'countSetados' => 0],
            );
            throw BadRequest::createWithBody(
                'Documento deve estar vinculado a um Processo, Cliente OU Prazo.',
                json_encode([
                    'reason' => 'xor_all_null',
                    'message' => 'Documento deve estar vinculado a um Processo, Cliente OU Prazo.',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }

        if ($count >= 2) {
            $keysSetados = array_values(array_filter([
                $hasProcesso ? 'processoId' : null,
                $hasCliente ? 'clienteId' : null,
                $hasPrazo ? 'prazoId' : null,
            ]));
            TogareLogger::event(
                'warning',
                'documento.create_rejected',
                'ValidateDocumentoFieldsHook: XOR violation — múltiplos preenchidos',
                [
                    'reason' => 'xor_violation',
                    'detail' => 'multiple_set',
                    'countSetados' => $count,
                    'keysSetados' => $keysSetados,
                ],
            );
            throw BadRequest::createWithBody(
                'Documento não pode estar vinculado a mais de um registro ao mesmo tempo. Escolha apenas um (Processo, Cliente ou Prazo).',
                json_encode([
                    'reason' => 'xor_multiple_set',
                    'countSetados' => $count,
                    'message' => 'Documento não pode estar vinculado a mais de um registro ao mesmo tempo. Escolha apenas um (Processo, Cliente ou Prazo).',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }
    }

    private function validateParentAccess(Documento $entity): void
    {
        $processoId = (string) ($entity->get('processoId') ?? '');
        $clienteId = (string) ($entity->get('clienteId') ?? '');
        $prazoId = (string) ($entity->get('prazoId') ?? '');

        if ($processoId !== '') {
            $this->assertParentReadable('Processo', $processoId);
            return;
        }

        if ($clienteId !== '') {
            $this->assertParentReadable('Cliente', $clienteId);
            return;
        }

        if ($prazoId !== '') {
            $this->assertParentReadable('Prazo', $prazoId);
            return;
        }
    }

    private function assertParentReadable(string $entityType, string $id): void
    {
        $parent = $this->entityManager->getEntityById($entityType, $id);
        if ($parent === null) {
            throw BadRequest::createWithBody(
                $entityType . ' vinculado não encontrado.',
                json_encode([
                    'reason' => 'parent_not_found',
                    'entityType' => $entityType,
                    'id' => $id,
                    'message' => $entityType . ' vinculado não encontrado.',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }

        if (method_exists($this->acl, 'checkEntity')) {
            if (! $this->acl->checkEntity($parent, 'read')) {
                throw new Forbidden('Sem permissão para anexar documento a este registro.');
            }
            return;
        }

        if (method_exists($this->acl, 'check') && ! $this->acl->check($parent, 'read')) {
            throw new Forbidden('Sem permissão para anexar documento a este registro.');
        }
    }

    private function loadAttachment(Documento $entity): Entity
    {
        $attachmentId = (string) ($entity->get('uploadedAttachmentId') ?? '');
        if ($attachmentId === '') {
            throw BadRequest::createWithBody(
                'Documento exige uploadedAttachmentId na criação.',
                json_encode([
                    'reason' => 'missing_attachment',
                    'message' => 'Anexo temporário não encontrado. Faça o upload novamente.',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }

        $attachment = $this->entityManager->getEntityById('Attachment', $attachmentId);
        if ($attachment === null) {
            TogareLogger::event(
                'warning',
                'documento.attachment_not_found',
                'ValidateDocumentoFieldsHook: Attachment não encontrado',
                ['attachmentId' => $attachmentId],
            );
            throw BadRequest::createWithBody(
                'Anexo não encontrado.',
                json_encode([
                    'reason' => 'attachment_not_found',
                    'message' => 'Anexo temporário não encontrado. Faça o upload novamente.',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }

        return $attachment;
    }

    private function validateAttachmentContext(Entity $attachment): void
    {
        $attachmentId = (string) $attachment->getId();
        $role = (string) ($attachment->get('role') ?? '');
        if ($role !== Documento::ATTACHMENT_ROLE) {
            throw BadRequest::createWithBody(
                'Anexo temporário inválido. Faça o upload novamente.',
                json_encode([
                    'reason' => 'invalid_attachment_role',
                    'attachmentId' => $attachmentId,
                    'message' => 'Anexo temporário inválido. Faça o upload novamente.',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }

        $parentType = (string) ($attachment->get('parentType') ?? '');
        if ($parentType !== '' && $parentType !== Documento::ENTITY_TYPE) {
            throw BadRequest::createWithBody(
                'Anexo temporário inválido. Faça o upload novamente.',
                json_encode([
                    'reason' => 'invalid_attachment_parent',
                    'attachmentId' => $attachmentId,
                    'message' => 'Anexo temporário inválido. Faça o upload novamente.',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }

        $field = (string) ($attachment->get('field') ?? '');
        if ($field !== '' && $field !== 'uploadedAttachment' && $field !== 'uploadedAttachmentId') {
            throw BadRequest::createWithBody(
                'Anexo temporário inválido. Faça o upload novamente.',
                json_encode([
                    'reason' => 'invalid_attachment_field',
                    'attachmentId' => $attachmentId,
                    'message' => 'Anexo temporário inválido. Faça o upload novamente.',
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }

        $createdById = (string) ($attachment->get('createdById') ?? '');
        $userId = (string) ($this->user->getId() ?? '');
        if ($createdById === '' || $userId === '' || $createdById !== $userId) {
            throw new Forbidden('Sem permissão para usar este anexo temporário.');
        }
    }

    private function validateMimeType(Entity $attachment): void
    {
        $mimeType = (string) $attachment->get('type');
        if (! \in_array($mimeType, Documento::ALLOWED_MIME_TYPES, true)) {
            TogareLogger::event(
                'warning',
                'documento.mime_rejected',
                'ValidateDocumentoFieldsHook: MIME type rejeitado',
                [
                    'mimeType' => $mimeType,
                    'filename' => (string) $attachment->get('name'),
                    'attachmentId' => (string) $attachment->getId(),
                ],
            );
            throw BadRequest::createWithBody(
                sprintf('Tipo de arquivo não permitido: %s. Aceitos: PDF, DOCX, XLSX, PNG, JPG, TIFF, TXT.', $mimeType),
                json_encode([
                    'reason' => 'mime_rejected',
                    'mimeType' => $mimeType,
                    'message' => sprintf('Tipo de arquivo não permitido: %s. Aceitos: PDF, DOCX, XLSX, PNG, JPG, TIFF, TXT.', $mimeType),
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }
    }

    private function validateSize(Entity $attachment): void
    {
        $rawSize = $attachment->get('size');
        if (! is_numeric($rawSize)) {
            $this->throwInvalidSize($attachment, 0);
        }

        $size = (int) $rawSize;
        if ($size <= 0) {
            $this->throwInvalidSize($attachment, $size);
        }

        $maxMb = $this->resolveMaxSizeMb();
        $maxBytes = $maxMb * 1024 * 1024;

        if ($size > $maxBytes) {
            $sizeMb = (int) round($size / (1024 * 1024));
            TogareLogger::event(
                'warning',
                'documento.size_exceeded',
                'ValidateDocumentoFieldsHook: tamanho excedido',
                [
                    'sizeBytes' => $size,
                    'sizeMb' => $sizeMb,
                    'limitMb' => $maxMb,
                    'attachmentId' => (string) $attachment->getId(),
                ],
            );
            throw BadRequest::createWithBody(
                sprintf('Arquivo muito grande: %dMB. Limite: %dMB.', $sizeMb, $maxMb),
                json_encode([
                    'reason' => 'size_exceeded',
                    'sizeMb' => $sizeMb,
                    'limitMb' => $maxMb,
                    'message' => sprintf('Arquivo muito grande: %dMB. Limite: %dMB.', $sizeMb, $maxMb),
                ], JSON_UNESCAPED_UNICODE) ?: '{}',
            );
        }
    }

    private function throwInvalidSize(Entity $attachment, int $size): never
    {
        TogareLogger::event(
            'warning',
            'documento.size_invalid',
            'ValidateDocumentoFieldsHook: tamanho inválido',
            [
                'sizeBytes' => $size,
                'attachmentId' => (string) $attachment->getId(),
            ],
        );
        throw BadRequest::createWithBody(
            'Arquivo vazio ou com tamanho inválido. Faça o upload novamente.',
            json_encode([
                'reason' => 'size_invalid',
                'message' => 'Arquivo vazio ou com tamanho inválido. Faça o upload novamente.',
            ], JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }

    private function resolveMaxSizeMb(): int
    {
        $envVar = getenv('TOGARE_MAX_DOCUMENT_SIZE_MB');
        if ($envVar === false || $envVar === '') {
            return 200;
        }
        $parsed = (int) $envVar;
        return $parsed > 0 ? $parsed : 200;
    }
}
