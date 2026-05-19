<?php

declare(strict_types=1);

namespace Espo\Modules\TogareTpu\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\TogareTpu\Services\TpuCacheService;

/**
 * Controller REST para search no catálogo TPU (Story 3.4 Task 8 — autocomplete).
 *
 * Endpoints:
 *  - GET /api/v1/TogareTpuCatalog/action/searchClasses?q={q}&limit={n}
 *  - GET /api/v1/TogareTpuCatalog/action/searchAssuntos?q={q}&limit={n}
 *  - GET /api/v1/TogareTpuCatalog/action/searchMovimentos?q={q}&limit={n}
 *
 * Resposta: list<{codigo: int, nome: string, paiCodigo: ?int, ativo: bool}>
 *
 * Auth: qualquer usuário autenticado (não-portal). Portal cliente acessa
 * via PortalProcess separado (Epic 7a) — endpoint TPU search é interno
 * do CRM.
 *
 * Search via `TpuCacheService::searchByName` — Redis cache 1h, min q=3 chars,
 * limit cap 100 (default 20). Query case-insensitive (collation
 * utf8mb4_unicode_ci da tabela já é). Wildcards LIKE sanitizados para evitar
 * pattern attack.
 */
class TogareTpuCatalog
{
    public function __construct(
        private readonly TpuCacheService $service,
        private readonly User $user,
    ) {
    }

    /**
     * @return list<array{codigo:int, nome:string, paiCodigo:?int, ativo:bool}>
     */
    public function getActionSearchClasses(Request $request): array
    {
        return $this->search('classe', $request);
    }

    /**
     * @return list<array{codigo:int, nome:string, paiCodigo:?int, ativo:bool}>
     */
    public function getActionSearchAssuntos(Request $request): array
    {
        return $this->search('assunto', $request);
    }

    /**
     * @return list<array{codigo:int, nome:string, paiCodigo:?int, ativo:bool}>
     */
    public function getActionSearchMovimentos(Request $request): array
    {
        return $this->search('movimento', $request);
    }

    /**
     * @param non-empty-string $tipo
     * @return list<array{codigo:int, nome:string, paiCodigo:?int, ativo:bool}>
     */
    private function search(string $tipo, Request $request): array
    {
        $this->ensureAuthenticated();

        $q = (string) ($request->getQueryParam('q') ?? '');
        $limitRaw = $request->getQueryParam('limit');
        $limit = \is_numeric($limitRaw) ? (int) $limitRaw : 20;

        return $this->service->searchByName($tipo, $q, $limit);
    }

    private function ensureAuthenticated(): void
    {
        if ($this->user->isPortal()) {
            throw new Forbidden('Endpoint TPU search é interno do CRM.');
        }

        if ((string) $this->user->getId() === '') {
            throw new Forbidden('Autenticação obrigatória.');
        }
    }
}
