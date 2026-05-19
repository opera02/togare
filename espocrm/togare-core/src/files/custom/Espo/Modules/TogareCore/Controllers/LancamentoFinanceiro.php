<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

use Espo\Core\Controllers\Record;

/**
 * Stock Record controller para LancamentoFinanceiro (Story 6.3 — T6.2).
 *
 * Espo `Record` cobre 100% do CRUD vanilla:
 *  POST   /api/v1/LancamentoFinanceiro          — criar
 *  GET    /api/v1/LancamentoFinanceiro          — listar
 *  GET    /api/v1/LancamentoFinanceiro/<id>     — detail
 *  PUT    /api/v1/LancamentoFinanceiro/<id>     — atualizar
 *  DELETE /api/v1/LancamentoFinanceiro/<id>     — remover
 *
 * Sem actions custom no MVP. Cancelamento da Fatura é feito via
 * Fatura::postActionCancelar (story 6.3 T6.1). Estorno é simplesmente
 * um LancamentoFinanceiro novo com tipo=estorno (POST regular).
 */
class LancamentoFinanceiro extends Record
{
}
