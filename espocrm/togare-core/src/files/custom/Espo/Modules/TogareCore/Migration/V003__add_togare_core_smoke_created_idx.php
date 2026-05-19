<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Migration;

use Espo\Modules\TogareCore\Contracts\MigrationInterface;
use PDO;

// phpcs:ignore Squiz.Classes.ValidClassName.NotCamelCaps
final class V003__add_togare_core_smoke_created_idx implements MigrationInterface
{
    public function version(): string
    {
        return 'V003__add_togare_core_smoke_created_idx';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec('CREATE INDEX idx_togare_core_smoke_created ON togare_core_smoke (created_at)');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP INDEX idx_togare_core_smoke_created ON togare_core_smoke');
    }
}
