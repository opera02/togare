<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\ContratoHonorarios;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\Modules\TogareCore\Entities\ContratoHonorarios;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Valida campos do ContratoHonorarios + popula meta do Attachment temporário
 * (Story 6.1 — Decisões #3, #5, #8 + Discovery #1 retro Epic 5).
 *
 * Validações (na ordem):
 *  1. Cliente obrigatório (N:1 — Discovery #1 retro Epic 5).
 *  2. Modalidade obrigatória + entre as 6 canônicas.
 *  3. valor/percentual coerentes com modalidade (Decisão #5):
 *     - fixo/mensal/hora_trabalhada → valor obrigatório
 *     - exito/sucumbencia           → percentual obrigatório (0 < x ≤ 100)
 *     - misto                       → AMBOS obrigatórios
 *  4. percentual entre 0 e 100 (defesa em profundidade — entityDefs já cobre).
 *  5. vigenciaInicio + dataAssinatura obrigatórias.
 *  6. vigenciaFim opcional, mas se setada deve ser >= vigenciaInicio.
 *  7. Processos cross-cliente bloqueado (Decisão #3 — todos processos vinculados
 *     devem pertencer ao mesmo cliente do contrato).
 *  8. Cliente / Attachment imutáveis pós-save (pattern Documento — não permite
 *     trocar PDF; criar contrato novo + expirar antigo).
 *  9. PDF obrigatório no create + MIME=application/pdf (Decisão #11 da spec).
 * 10. Popular filename/mimeType/sizeBytes a partir do Attachment.
 *
 * Order = 10 (executa antes de DefaultUploadedBy=15 e Move=30).
 *
 * @implements BeforeSave<ContratoHonorarios>
 */
final class ValidateContratoFieldsHook implements BeforeSave
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
        if (! $entity instanceof ContratoHonorarios) {
            return;
        }

        $this->validateClienteRequired($entity);
        $this->validateModalidade($entity);
        $this->validateValorPercentual($entity);
        $this->validateVigencia($entity);
        $this->validateProcessosCrossCliente($entity);
        $this->validateLinkMutation($entity);

        if (! $entity->isNew()) {
            return;
        }

        // Create only: validar attachment + popular meta.
        $this->validateClienteAccess($entity);
        $attachment = $this->loadAttachment($entity);
        $this->validateAttachmentContext($attachment);
        $this->validateMimeType($attachment);
        $this->populateMetaFromAttachment($entity, $attachment);
    }

    private function validateClienteRequired(ContratoHonorarios $entity): void
    {
        $clienteId = (string) ($entity->get('clienteId') ?? '');
        if ($clienteId !== '') {
            return;
        }
        throw self::badRequest(
            'clienteRequired',
            'O contrato precisa estar vinculado a um Cliente.',
        );
    }

    private function validateModalidade(ContratoHonorarios $entity): void
    {
        $modalidade = (string) ($entity->get('modalidade') ?? '');
        if ($modalidade === '') {
            throw self::badRequest(
                'modalidadeRequired',
                'Selecione a modalidade do contrato.',
            );
        }
        if (! \in_array($modalidade, ContratoHonorarios::MODALIDADES, true)) {
            throw self::badRequest(
                'modalidadeInvalid',
                'Modalidade inválida: ' . $modalidade,
            );
        }
    }

    private function validateValorPercentual(ContratoHonorarios $entity): void
    {
        $modalidade = (string) ($entity->get('modalidade') ?? '');
        $valor = $entity->get('valor');
        $percentual = $entity->get('percentual');

        $needsValor = \in_array($modalidade, ContratoHonorarios::MODALIDADES_COM_VALOR, true);
        $needsPercentual = \in_array($modalidade, ContratoHonorarios::MODALIDADES_COM_PERCENTUAL, true);

        if ($needsValor && ($valor === null || $valor === '' || (float) $valor <= 0)) {
            throw self::badRequest(
                'valorRequiredForModalidade',
                'Modalidade "' . $modalidade . '" exige o campo Valor preenchido (> 0).',
            );
        }
        if ($needsPercentual) {
            if ($percentual === null || $percentual === '') {
                throw self::badRequest(
                    'percentualRequiredForModalidade',
                    'Modalidade "' . $modalidade . '" exige o campo Percentual preenchido.',
                );
            }
            $p = (float) $percentual;
            if ($p <= 0 || $p > 100) {
                throw self::badRequest(
                    'percentualOutOfRange',
                    'O Percentual deve estar entre 0 e 100 (recebido: ' . $p . ').',
                );
            }
        }

        if (! $needsValor && $valor !== null && $valor !== '') {
            throw self::badRequest(
                'valorIncompativelComModalidade',
                'Modalidade "' . $modalidade . '" não permite o campo Valor preenchido.',
            );
        }

        if (! $needsPercentual && $percentual !== null && $percentual !== '') {
            throw self::badRequest(
                'percentualIncompativelComModalidade',
                'Modalidade "' . $modalidade . '" não permite o campo Percentual preenchido.',
            );
        }
    }

    private function validateVigencia(ContratoHonorarios $entity): void
    {
        $inicio = (string) ($entity->get('vigenciaInicio') ?? '');
        $fim = (string) ($entity->get('vigenciaFim') ?? '');
        $assinatura = (string) ($entity->get('dataAssinatura') ?? '');

        if ($assinatura === '') {
            throw self::badRequest(
                'dataAssinaturaRequired',
                'A Data de assinatura é obrigatória.',
            );
        }
        if ($inicio === '') {
            throw self::badRequest(
                'vigenciaInicioRequired',
                'A Vigência (início) é obrigatória.',
            );
        }
        // vigenciaFim é NULLABLE (Decisão #8). Se setada, deve ser >= inicio.
        if ($fim !== '' && $fim < $inicio) {
            throw self::badRequest(
                'vigenciaFimAntesInicio',
                'A Vigência (fim) deve ser igual ou posterior à Vigência (início). Para contratos sem prazo, deixe em branco.',
            );
        }
    }

    /**
     * Discovery #1 retro Epic 5 enforce: processos vinculados devem
     * pertencer ao mesmo cliente do contrato. Defesa contra contrato
     * cross-cliente.
     */
    private function validateProcessosCrossCliente(ContratoHonorarios $entity): void
    {
        $clienteId = (string) ($entity->get('clienteId') ?? '');
        if ($clienteId === '') {
            return;
        }

        $processosIds = $this->extractProcessosIds($entity);
        if ($processosIds === []) {
            return;
        }

        try {
            $pdo = $this->entityManager->getPDO();
            $placeholders = implode(',', array_fill(0, count($processosIds), '?'));
            $sql = "SELECT DISTINCT cp.cliente_id, cp.processo_id "
                . "FROM cliente_processo cp "
                . "WHERE cp.processo_id IN ($placeholders) AND cp.deleted = 0";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($processosIds);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            TogareLogger::event(
                'error',
                'contrato.cross_cliente_check_failed',
                'ValidateContratoFieldsHook: falha ao validar cross-cliente',
                ['error' => $e->getMessage(), 'clienteId' => $clienteId, 'processosIds' => $processosIds],
            );
            throw self::badRequest(
                'processosCrossClienteCheckFailed',
                'Não foi possível validar os processos vinculados ao contrato. Tente novamente.',
            );
        }

        $clientesPorProcesso = [];
        foreach ($rows as $row) {
            $procId = (string) ($row['processo_id'] ?? '');
            $cliId = (string) ($row['cliente_id'] ?? '');
            if ($procId === '' || $cliId === '') {
                continue;
            }
            $clientesPorProcesso[$procId][$cliId] = true;
        }

        foreach ($processosIds as $procId) {
            if (! isset($clientesPorProcesso[$procId])) {
                throw self::badRequest(
                    'processoSemCliente',
                    'Processo selecionado não pertence a nenhum cliente (ou cliente inválido).',
                );
            }
            if (! isset($clientesPorProcesso[$procId][$clienteId])) {
                throw self::badRequest(
                    'processosCrossCliente',
                    'Os processos vinculados devem pertencer ao mesmo Cliente do contrato.',
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function extractProcessosIds(ContratoHonorarios $entity): array
    {
        // linkMultiple field: Espo armazena `processosIds` (array)
        $raw = $entity->get('processosIds');
        if (! \is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $id) {
            $s = (string) $id;
            if ($s !== '') {
                $ids[] = $s;
            }
        }
        return $ids;
    }

    private function validateLinkMutation(ContratoHonorarios $entity): void
    {
        if ($entity->isNew()) {
            return;
        }
        if ($entity->isAttributeChanged('clienteId')) {
            throw self::badRequest(
                'linkImmutable',
                'Não é possível trocar o Cliente de um contrato já salvo. Cadastre um contrato novo e marque o antigo como expirado em Vigência (fim).',
            );
        }
        // uploadedAttachment é notStorable, mas defensivamente bloqueia se aparecer.
        if ($entity->isAttributeChanged('uploadedAttachmentId')) {
            throw self::badRequest(
                'attachmentImmutable',
                'Não é possível substituir o PDF de um contrato já salvo. Cadastre um contrato novo.',
            );
        }

        foreach (['fileStorageUri', 'filename', 'mimeType', 'sizeBytes'] as $field) {
            if ($entity->isAttributeChanged($field)) {
                throw self::badRequest(
                    'storageMetadataImmutable',
                    'Não é possível alterar os metadados do arquivo de um contrato já salvo.',
                );
            }
        }
    }

    private function validateClienteAccess(ContratoHonorarios $entity): void
    {
        $clienteId = (string) ($entity->get('clienteId') ?? '');
        $cliente = $this->entityManager->getEntityById('Cliente', $clienteId);
        if ($cliente === null) {
            throw self::badRequest(
                'clienteNotFound',
                'Cliente vinculado não encontrado.',
            );
        }

        if (\method_exists($this->acl, 'checkEntity')) {
            if (! $this->acl->checkEntity($cliente, 'read')) {
                throw new Forbidden('Sem permissão para anexar contrato a este Cliente.');
            }
            return;
        }

        if (\method_exists($this->acl, 'check') && ! $this->acl->check($cliente, 'read')) {
            throw new Forbidden('Sem permissão para anexar contrato a este Cliente.');
        }
    }

    private function loadAttachment(ContratoHonorarios $entity): Entity
    {
        $attachmentId = (string) ($entity->get('uploadedAttachmentId') ?? '');
        if ($attachmentId === '') {
            throw self::badRequest(
                'pdfRequired',
                'Anexe o PDF do contrato assinado.',
            );
        }
        $attachment = $this->entityManager->getEntityById('Attachment', $attachmentId);
        if ($attachment === null) {
            throw self::badRequest(
                'attachmentNotFound',
                'Anexo temporário não encontrado. Faça o upload novamente.',
            );
        }
        return $attachment;
    }

    private function validateAttachmentContext(Entity $attachment): void
    {
        $attachmentId = (string) $attachment->getId();
        $role = (string) ($attachment->get('role') ?? '');
        if ($role !== ContratoHonorarios::ATTACHMENT_ROLE) {
            throw self::badRequest(
                'invalidAttachmentRole',
                'Anexo temporário inválido. Faça o upload novamente.',
            );
        }

        $parentType = (string) ($attachment->get('parentType') ?? '');
        if ($parentType !== '' && $parentType !== ContratoHonorarios::ENTITY_TYPE) {
            throw self::badRequest(
                'invalidAttachmentParent',
                'Anexo temporário inválido. Faça o upload novamente.',
            );
        }

        $field = (string) ($attachment->get('field') ?? '');
        if ($field !== '' && $field !== 'uploadedAttachment' && $field !== 'uploadedAttachmentId') {
            throw self::badRequest(
                'invalidAttachmentField',
                'Anexo temporário inválido. Faça o upload novamente.',
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
        $mime = (string) ($attachment->get('type') ?? '');
        if (! \in_array($mime, ContratoHonorarios::ALLOWED_MIME_TYPES, true)) {
            throw self::badRequest(
                'mimeRejected',
                'O contrato precisa ser um arquivo PDF (recebido: ' . $mime . ').',
            );
        }
    }

    private function populateMetaFromAttachment(ContratoHonorarios $entity, Entity $attachment): void
    {
        $entity->set([
            'filename' => self::sanitizeForStorage((string) $attachment->get('name')),
            'mimeType' => (string) $attachment->get('type'),
            'sizeBytes' => (int) $attachment->get('size'),
        ]);
    }

    /**
     * Pattern Documento — defesa contra truncamento/coerção surpresa.
     */
    private static function sanitizeForStorage(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return ContratoHonorarios::FILENAME_FALLBACK;
        }
        if (mb_strlen($name) > ContratoHonorarios::MAX_FILENAME_LENGTH) {
            $name = mb_substr($name, 0, ContratoHonorarios::MAX_FILENAME_LENGTH);
        }
        return $name;
    }

    private static function badRequest(string $reason, string $message): BadRequest
    {
        return BadRequest::createWithBody(
            $message,
            json_encode([
                'reason' => $reason,
                'message' => $message,
            ], JSON_UNESCAPED_UNICODE) ?: '{}',
        );
    }
}
