<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use Espo\ORM\Entity;

/**
 * DTO de saída do `PrazoCreatorService::create()` (Story 4b.1b — Decisão #3 mãe).
 *
 * Imutável (`readonly`). Representa 1 dos 4 outcomes do pipeline
 * `idempotência → matcher → ramificação`:
 *
 *  - `prazo_bound`        — Prazo persistido vinculado a Processo único
 *                           (CNJ exact 1 hit OU name-match 1 hit distinct).
 *                           `entity` é `Espo\Modules\TogareCore\Entities\Prazo`.
 *  - `prazo_rascunho`     — Prazo persistido sem match (kind=none ou kind=too_many).
 *                           `entity` é `Prazo`.
 *  - `publicacao_ambigua` — PublicacaoAmbigua persistida com snapshot candidatos
 *                           denormalizado (kind=multiple). `entity` é
 *                           `Espo\Modules\TogareCore\Entities\PublicacaoAmbigua`.
 *  - `deduped`            — sourcePubId já existe em `prazo` OU em
 *                           `publicacao_ambigua`. Re-fetch DJEN nunca duplica.
 *                           `entity` é o registro pré-existente (pode ser
 *                           Prazo ou PublicacaoAmbigua — callsite faz
 *                           `instanceof` se precisar discriminar).
 *
 * `DjenWorkerService::handlePublication` descarta o retorno do `create()`
 * — backward-compat preservada com o pipeline da Story 4a.3.
 */
final readonly class CreationResult
{
    public function __construct(
        public string $kind,
        public Entity $entity,
    ) {
    }

    public static function prazoBound(Entity $prazo): self
    {
        return new self(kind: 'prazo_bound', entity: $prazo);
    }

    public static function prazoRascunho(Entity $prazo): self
    {
        return new self(kind: 'prazo_rascunho', entity: $prazo);
    }

    public static function publicacaoAmbigua(Entity $pub): self
    {
        return new self(kind: 'publicacao_ambigua', entity: $pub);
    }

    public static function deduped(Entity $existing): self
    {
        return new self(kind: 'deduped', entity: $existing);
    }
}
