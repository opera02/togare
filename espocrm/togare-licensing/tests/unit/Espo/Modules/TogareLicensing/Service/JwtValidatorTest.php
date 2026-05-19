<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareLicensing\Service;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareLicensing\Service\JwtValidationResult;
use Espo\Modules\TogareLicensing\Service\JwtValidator;
use Espo\Modules\TogareLicensing\Service\PublicKeyProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use tests\unit\Espo\Modules\TogareLicensing\JwtFixtures;
use tests\unit\Espo\Modules\TogareLicensing\TestClock;

/**
 * Cobre AC3 itens 1-6 da Story 1b.1.
 *
 * Cada teste roda em processo separado (RunInSeparateProcess) porque o
 * JwtValidator emite logs via TogareLogger singleton.
 */
final class JwtValidatorTest extends TestCase
{
    private TestClock $clock;
    private JwtValidator $validator;

    protected function setUp(): void
    {
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');
        TogareLogger::init('test', null, $stdout, $stderr);

        $this->clock = new TestClock(new DateTimeImmutable('2026-04-24T12:00:00+00:00'));

        $publicKey = new PublicKeyProvider(JwtFixtures::publicKeyPath());
        $this->validator = new JwtValidator($publicKey, $this->clock);
    }

    #[RunInSeparateProcess]
    public function testValidJwtReturnsValid(): void
    {
        $jwt = JwtFixtures::makeJwt([
            'iat' => $this->clock->now(),
            'exp' => $this->clock->now()->modify('+30 days'),
            'mod' => ['togare-djen', 'togare-portal-ui'],
        ]);

        $result = $this->validator->validate($jwt);

        $this->assertTrue($result->isValid);
        $this->assertNull($result->reason);
        $this->assertSame('togare-empresa', $result->claims['iss']);
        $this->assertSame(['togare-djen', 'togare-portal-ui'], $result->claims['mod']);
    }

    #[RunInSeparateProcess]
    public function testExpiredJwtReturnsExpired(): void
    {
        $jwt = JwtFixtures::makeJwt([
            'iat' => $this->clock->now()->modify('-60 days'),
            'exp' => $this->clock->now()->modify('-1 day'),
        ]);

        $result = $this->validator->validate($jwt);

        $this->assertFalse($result->isValid);
        $this->assertSame(JwtValidationResult::REASON_EXPIRED, $result->reason);
    }

    #[RunInSeparateProcess]
    public function testMalformedJwtReturnsMalformed(): void
    {
        $result = $this->validator->validate('not.a.jwt');

        $this->assertFalse($result->isValid);
        $this->assertSame(JwtValidationResult::REASON_MALFORMED, $result->reason);
    }

    #[RunInSeparateProcess]
    public function testWrongSignatureReturnsInvalidSignature(): void
    {
        $jwt = JwtFixtures::makeJwtWithOtherKey();

        $result = $this->validator->validate($jwt);

        $this->assertFalse($result->isValid);
        $this->assertSame(JwtValidationResult::REASON_INVALID_SIGNATURE, $result->reason);
    }

    #[RunInSeparateProcess]
    public function testWrongIssuerReturnsWrongIssuer(): void
    {
        $jwt = JwtFixtures::makeJwt([
            'iss' => 'fake-empresa',
            'iat' => $this->clock->now(),
            'exp' => $this->clock->now()->modify('+1 day'),
        ]);

        $result = $this->validator->validate($jwt);

        $this->assertFalse($result->isValid);
        $this->assertSame(JwtValidationResult::REASON_WRONG_ISSUER, $result->reason);
    }

    #[RunInSeparateProcess]
    public function testClockSkewWithin5MinLeewayPasses(): void
    {
        // exp 4min no passado — leeway 5min deve aceitar.
        $jwt = JwtFixtures::makeJwt([
            'iat' => $this->clock->now()->modify('-1 hour'),
            'exp' => $this->clock->now()->modify('-4 minutes'),
        ]);

        $result = $this->validator->validate($jwt);

        $this->assertTrue($result->isValid, 'Esperado aceitar com leeway 5min, recebeu reason: ' . ($result->reason ?? ''));
    }

    #[RunInSeparateProcess]
    public function testEmptyModuleArrayReturnsMalformed(): void
    {
        $jwt = JwtFixtures::makeJwt([
            'iat' => $this->clock->now(),
            'exp' => $this->clock->now()->modify('+1 day'),
            'mod' => [],
        ]);

        $result = $this->validator->validate($jwt);

        $this->assertFalse($result->isValid);
        $this->assertSame(JwtValidationResult::REASON_MALFORMED, $result->reason);
    }
}
