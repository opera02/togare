<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Entities;

use Espo\Core\ORM\Entity;

/**
 * Entity para backup codes MFA one-time-use.
 *
 * Nome TogareMfaBackupCode → EspoCRM gera tabela togare_mfa_backup_code
 * automaticamente (convenção R3 — prefix togare_ via nome da entity).
 *
 * Story 2.3.
 */
class TogareMfaBackupCode extends Entity
{
    public const ENTITY_TYPE = 'TogareMfaBackupCode';
}
