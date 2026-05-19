<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Processo;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareCore\Validators\BrValidator;
use Espo\Modules\TogareCore\Validators\CnjNumberValidator;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Normaliza e valida o número CNJ no `beforeSave` da entidade Processo
 * (Story 3.4, FR7).
 *
 * Comportamento:
 *  1. Strip de máscara via `BrValidator::digitsOnly` — input mascarado
 *     `'0001234-56.2023.8.26.0100'` (25 chars) ou só dígitos
 *     `'00012345620238260100'` (20 chars) ambos aceitos.
 *  2. Valida exatamente 20 dígitos (após strip).
 *  3. Valida dígito verificador via `CnjNumberValidator::isValid` (mod 97
 *     progressivo conforme Res. CNJ 65/2008).
 *  4. Salva apenas os 20 dígitos puros — display formatado é responsabilidade
 *     do helper Handlebars `formatCnj` (architecture L457 + Decisão #2 da story).
 *
 * Mensagens de erro em pt-BR (UX-DR9 + architecture L562) via
 * `BadRequest::createWithBody` com payload JSON `{field, reason, message}`.
 *
 * Order $order = 10 — RODA PRIMEIRO. Outros hooks (validate fields, TPU lookup,
 * audit) dependem do CNJ já normalizado.
 *
 * Defesa em profundidade: client-side `brValidators.js::isValidCnj` também
 * valida — server NUNCA confia no cliente (architecture L573).
 *
 * @implements BeforeSave<Entity>
 */
final class NormalizeCnjNumberHook implements BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private readonly EntityManager $entityManager,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        // Só processa se numeroCnj é new ou foi alterado nesta save.
        if (! $entity->isNew() && ! $entity->isAttributeChanged('numeroCnj')) {
            return;
        }

        $raw = $entity->get('numeroCnj');

        if ($raw === null || $raw === '' || ! \is_string($raw)) {
            $this->fail('numeroCnj', 'Número CNJ é obrigatório.');
        }

        $digits = BrValidator::digitsOnly($raw);

        if (\strlen($digits) !== 20) {
            $this->fail(
                'numeroCnj',
                'Número CNJ inválido — confira o número e tente de novo.',
            );
        }

        if (! CnjNumberValidator::isValid($digits)) {
            $this->fail(
                'numeroCnj',
                'Número CNJ inválido — confira o número e tente de novo.',
            );
        }

        $this->assertUniqueNumeroCnj($entity, $digits);

        if ($digits !== $raw) {
            $entity->set('numeroCnj', $digits);
        }
    }

    private function assertUniqueNumeroCnj(Entity $entity, string $digits): void
    {
        try {
            $pdo = $this->entityManager->getPDO();
            $sql = 'SELECT id FROM processo WHERE numero_cnj = :numero_cnj AND deleted = 0';
            $params = ['numero_cnj' => $digits];

            $id = $entity->getId();
            if ($id !== null && $id !== '') {
                $sql .= ' AND id <> :id';
                $params['id'] = (string) $id;
            }
            $sql .= ' LIMIT 1';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            if ($stmt->fetchColumn() !== false) {
                $this->fail(
                    'numeroCnj',
                    "Número CNJ '{$digits}' já está cadastrado.",
                    'duplicate',
                );
            }
        } catch (BadRequest $e) {
            throw $e;
        } catch (\Throwable $e) {
            TogareLogger::event(
                'warning',
                'processo.cnj.duplicate_check.failed',
                'Falha ao verificar duplicidade de número CNJ — índice UNIQUE continua como proteção.',
                ['reason' => $e->getMessage()],
            );
        }
    }

    /**
     * @param non-empty-string $field
     * @param non-empty-string $message
     */
    private function fail(string $field, string $message, string $reason = 'invalid'): never
    {
        TogareLogger::event(
            'warning',
            'processo.cnj.normalize.failed',
            $message,
            [
                'field' => $field,
                'reason' => $reason,
            ],
        );

        throw BadRequest::createWithBody(
            $message,
            (string) \json_encode(
                ['field' => $field, 'reason' => $reason, 'message' => $message],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        );
    }
}
