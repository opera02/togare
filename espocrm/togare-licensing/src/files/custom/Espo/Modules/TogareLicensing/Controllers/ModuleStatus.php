<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Controllers;

/**
 * Controller REST para a entidade ModuleStatus.
 *
 * Extensão do Record controller padrão do EspoCRM — expõe os endpoints
 * CRUD convencionais (GET/POST/PUT/DELETE em /api/v1/ModuleStatus) com
 * ACL padrão. A entidade é administrativa (só admin lê/escreve por
 * `scopes.ModuleStatus.acl=boolean`).
 */
class ModuleStatus extends \Espo\Core\Controllers\Record
{
}
