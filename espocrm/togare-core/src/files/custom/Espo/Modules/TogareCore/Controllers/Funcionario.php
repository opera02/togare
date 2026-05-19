<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Controllers;

/**
 * Controller REST para a entidade Funcionario (Story 6.5, FR32 — RH-lite).
 *
 * Estende `Espo\Core\Controllers\Record` — expõe endpoints CRUD nativos:
 *  - GET    /api/v1/Funcionario?orderBy=...&maxSize=... — listagem.
 *  - GET    /api/v1/Funcionario/{id}                     — detalhe.
 *  - POST   /api/v1/Funcionario                          — criar.
 *  - PUT    /api/v1/Funcionario/{id}                     — editar.
 *  - DELETE /api/v1/Funcionario/{id}                     — excluir.
 *
 * Dev Decision D6.5.1 (correção de curso vs. spec): a spec da Story 6.5
 * afirmava "sem Controller custom (RESTful stock cobre CRUD)". Na prática,
 * o EspoCRM 9.x deste projeto **exige** uma classe Controller por entity
 * para registrar a rota `/api/v1/<Entity>` — TODAS as 10 entities Togare
 * (Cliente, ContratoHonorarios, Fatura, …) têm essa classe fina. Sem ela
 * o endpoint retorna 404 e a aba do navbar não funciona. Esta classe é o
 * mínimo (stock Record, zero lógica custom — Funcionario não tem actions
 * próprias), espelhando exatamente `Controllers/Cliente.php`.
 *
 * ACL aplicada via 8 roles do togare-rbac (Story 6.5 / V010 — FR32):
 *  - Sócio/Admin: all
 *  - RH-lite:     all
 *  - Advogado / Assistente / Secretária / Financeiro / Marketing /
 *    Cliente-portal: no (blindagem cruzada — só RH gerencia funcionários).
 *
 * Hooks beforeSave registrados em `Hooks/Funcionario/`:
 *  - NormalizeFuncionarioCpfHook (10): CPF só dígitos no storage.
 *  - ValidateFuncionarioCpfHook  (20): valida CPF; lança BadRequest pt-BR.
 */
class Funcionario extends \Espo\Core\Controllers\Record
{
}
