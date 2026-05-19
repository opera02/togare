<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareRbac\Service\Password;

use Espo\Core\Utils\Config;
use Espo\Modules\TogareRbac\Service\Password\TogarePasswordHash;
use PHPUnit\Framework\TestCase;

/**
 * Cobre AC2 — bcrypt cost 12 (NFR8 do PRD).
 */
final class TogarePasswordHashTest extends TestCase
{
    private TogarePasswordHash $hasher;

    protected function setUp(): void
    {
        $this->hasher = new TogarePasswordHash(new Config());
    }

    public function testHashUsaCost12(): void
    {
        $hash = $this->hasher->hash('SenhaForte!9');

        $info = \password_get_info($hash);

        $this->assertSame(PASSWORD_BCRYPT, $info['algo']);
        $this->assertSame(12, $info['options']['cost'] ?? null);
        $this->assertStringStartsWith('$2y$12$', $hash);
    }

    public function testHashesProduzemSaltsDiferentes(): void
    {
        $a = $this->hasher->hash('SenhaForte!9');
        $b = $this->hasher->hash('SenhaForte!9');

        $this->assertNotSame($a, $b, 'Cada hash deve ter salt único.');
    }

    public function testVerifyAceitaProprioHash(): void
    {
        $hash = $this->hasher->hash('SenhaForte!9');

        $this->assertTrue($this->hasher->verify('SenhaForte!9', $hash));
        $this->assertFalse($this->hasher->verify('OutraSenha!9', $hash));
    }

    public function testVerifyAceitaHashLegacyCost10(): void
    {
        // Hash gerado com cost 10 explícito (simula hash legado do EspoCRM core).
        // PHP 8.4 mudou o default de cost=10 para cost=12 — por isso forçamos cost=10 aqui.
        $legacy = \password_hash('SenhaForte!9', PASSWORD_BCRYPT, ['cost' => 10]);
        $info = \password_get_info($legacy);
        $this->assertSame(10, $info['options']['cost']);

        $this->assertTrue(
            $this->hasher->verify('SenhaForte!9', $legacy),
            'Verify deve aceitar hashes legacy cost 10 (sem regressão).',
        );
    }

    public function testCostEhConstantePublica(): void
    {
        $this->assertSame(12, TogarePasswordHash::COST);
    }
}
