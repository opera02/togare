<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Hooks\Processo;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareTpu\Contracts\AssuntoResolverContract;
use Espo\Modules\TogareTpu\Contracts\ClasseResolverContract;
use Espo\Modules\TogareTpu\Contracts\MovimentoResolverContract;
use Espo\Modules\TogareTpu\Exception\TpuCodeNotFoundException;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Hook beforeSave em `Processo` (escuta cross-module — entity vive em
 * togare-core, hook vive em togare-tpu). Story 3.4, FR8.
 *
 * Responsabilidade única:
 *  1. Quando classeCodigo / assuntoCodigo / movimentoCodigo é new ou
 *     foi alterado, faz lookup no catálogo TPU via ResolverContracts
 *     (implementados por TpuCacheService — cache-aside Redis + fallback DB,
 *     ver Story 3.3).
 *  2. Se lookup retorna null (código fora do catálogo) → lança
 *     TpuCodeNotFoundException (extends BadRequest → HTTP 400 + body em pt-BR).
 *  3. Se lookup retorna row → denormaliza nome no campo *Nome correspondente.
 *
 * Movimento é OPCIONAL — se movimentoCodigo === null/0, limpa movimentoNome.
 *
 * EspoCRM 9.3 Hook scanner descobre este hook por convenção do path
 * `Modules/TogareTpu/Hooks/Processo/*.php` mesmo sem entityDef de Processo
 * estar em togare-tpu. Se togare-tpu não estiver instalado, hook não existe →
 * Processo aceita qualquer código sem validação TPU (degradação graciosa
 * documentada em Dev Notes §6 da story).
 *
 * Order $order = 30 — roda DEPOIS de NormalizeCnjNumberHook (10) e
 * ValidateProcessoFieldsHook (20) em togare-core, ANTES de AuditProcessoHook
 * (50). Sequência correta porque (a) faz sentido só lookup se CNJ + campos
 * básicos OK; (b) audit precisa do registro persistido com nomes denormalizados.
 *
 * Bindings DI dos 3 contracts → TpuCacheService já registrados em
 * `togare-tpu/Binding.php` desde Story 3.3.
 *
 * @implements BeforeSave<Entity>
 */
final class ResolveTpuFieldsHook implements BeforeSave
{
    public static int $order = 30;

    public function __construct(
        private readonly ClasseResolverContract $classeResolver,
        private readonly AssuntoResolverContract $assuntoResolver,
        private readonly MovimentoResolverContract $movimentoResolver,
    ) {
    }

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->getEntityType() !== 'Processo') {
            return;
        }

        $this->resolveClasse($entity);
        $this->resolveAssunto($entity);
        $this->resolveMovimento($entity);
    }

    private function resolveClasse(Entity $entity): void
    {
        if (! $entity->isNew() && ! $entity->isAttributeChanged('classeCodigo')) {
            return;
        }
        $codigo = $this->normalizeCodigo($entity->get('classeCodigo'));
        if ($codigo === null) {
            // required já é tratado pelo framework + ValidateProcessoFieldsHook
            return;
        }
        $row = $this->classeResolver->resolveClasse($codigo);
        if ($row === null) {
            $this->failNotFound('classe', $codigo);
        }
        $entity->set('classeNome', $row['nome']);
        $this->logSuccess('classe', $codigo, $row['nome']);
    }

    private function resolveAssunto(Entity $entity): void
    {
        if (! $entity->isNew() && ! $entity->isAttributeChanged('assuntoCodigo')) {
            return;
        }
        $codigo = $this->normalizeCodigo($entity->get('assuntoCodigo'));
        if ($codigo === null) {
            return;
        }
        $row = $this->assuntoResolver->resolveAssunto($codigo);
        if ($row === null) {
            $this->failNotFound('assunto', $codigo);
        }
        $entity->set('assuntoNome', $row['nome']);
        $this->logSuccess('assunto', $codigo, $row['nome']);
    }

    private function resolveMovimento(Entity $entity): void
    {
        if (! $entity->isNew() && ! $entity->isAttributeChanged('movimentoCodigo')) {
            return;
        }
        $rawCodigo = $entity->get('movimentoCodigo');
        if ($rawCodigo === null || $rawCodigo === '' || (int) $rawCodigo === 0) {
            // Movimento opcional — limpa movimentoNome se codigo foi removido
            $entity->set('movimentoNome', null);
            return;
        }
        $codigo = $this->normalizeCodigo($rawCodigo);
        if ($codigo === null) {
            return;
        }
        $row = $this->movimentoResolver->resolveMovimento($codigo);
        if ($row === null) {
            $this->failNotFound('movimento', $codigo);
        }
        $entity->set('movimentoNome', $row['nome']);
        $this->logSuccess('movimento', $codigo, $row['nome']);
    }

    private function normalizeCodigo(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (\is_int($raw)) {
            return $raw > 0 ? $raw : null;
        }
        if (\is_string($raw) && \ctype_digit($raw)) {
            $codigo = (int) $raw;
            return $codigo > 0 ? $codigo : null;
        }
        return null;
    }

    /**
     * @param non-empty-string $tipo
     */
    private function failNotFound(string $tipo, int $codigo): never
    {
        TogareLogger::event(
            'warning',
            'tpu.lookup.failed.code_not_found',
            "Código TPU {$codigo} não encontrado no catálogo de {$tipo}",
            ['tipo' => $tipo, 'codigo' => $codigo],
        );

        throw TpuCodeNotFoundException::create($tipo, $codigo);
    }

    /**
     * @param non-empty-string $tipo
     */
    private function logSuccess(string $tipo, int $codigo, string $nome): void
    {
        TogareLogger::event(
            'debug',
            'tpu.lookup.success',
            "Lookup TPU {$tipo} {$codigo} resolvido",
            ['tipo' => $tipo, 'codigo' => $codigo, 'nome' => $nome],
        );
    }
}
