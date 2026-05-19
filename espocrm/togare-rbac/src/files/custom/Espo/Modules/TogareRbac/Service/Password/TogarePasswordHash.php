<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Service\Password;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\PasswordHash;
use SensitiveParameter;

/**
 * Override do `passwordHash` core do EspoCRM para emitir bcrypt com
 * **cost 12** (NFR8 do PRD Togare). Default PHP `password_hash($p,
 * PASSWORD_BCRYPT)` usa cost 10.
 *
 * `verify()` é herdado do core — `password_verify` é cost-agnostic, então
 * hashes legacy cost 10 continuam validando sem regressão. Quando o usuário
 * trocar a senha, o re-hash automaticamente eleva pra cost 12.
 *
 * Registrado via DI override em
 * `Resources/metadata/app/containerServices.json` chave `passwordHash`.
 */
final class TogarePasswordHash extends PasswordHash
{
    public const COST = 12;

    public function __construct(Config $config)
    {
        parent::__construct($config);
    }

    public function hash(#[SensitiveParameter] string $password): string
    {
        return \password_hash($password, PASSWORD_BCRYPT, ['cost' => self::COST]);
    }
}
