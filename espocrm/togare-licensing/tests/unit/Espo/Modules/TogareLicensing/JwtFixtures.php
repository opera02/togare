<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareLicensing;

use DateTimeImmutable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Builder;

/**
 * Helpers para gerar JWTs de teste assinados com a chave privada de fixture.
 * Centralizado pra todos os testes reutilizarem.
 */
final class JwtFixtures
{
    public static function privateKeyPath(): string
    {
        return \realpath(__DIR__ . '/../../../../fixtures/togare-private-test.pem')
            ?: throw new \RuntimeException('Fixture togare-private-test.pem não encontrada');
    }

    public static function publicKeyPath(): string
    {
        return \realpath(__DIR__ . '/../../../../fixtures/togare-public-test.pem')
            ?: throw new \RuntimeException('Fixture togare-public-test.pem não encontrada');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function makeJwt(array $overrides = []): string
    {
        $now = new DateTimeImmutable();
        $defaults = [
            'iss' => 'togare-empresa',
            'sub' => 'cartorio-test-001',
            'iat' => $now,
            'exp' => $now->modify('+30 days'),
            'jti' => 'lic-test-' . \bin2hex(\random_bytes(8)),
            'mod' => ['togare-djen'],
        ];

        $claims = \array_merge($defaults, $overrides);

        $builder = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedBy($claims['iss'])
            ->relatedTo($claims['sub'])
            ->issuedAt($claims['iat'] instanceof DateTimeImmutable ? $claims['iat'] : new DateTimeImmutable('@' . $claims['iat']))
            ->expiresAt($claims['exp'] instanceof DateTimeImmutable ? $claims['exp'] : new DateTimeImmutable('@' . $claims['exp']))
            ->identifiedBy((string) $claims['jti'])
            ->withClaim('mod', $claims['mod']);

        $token = $builder->getToken(
            new Sha256(),
            InMemory::file(self::privateKeyPath()),
        );

        return $token->toString();
    }

    /**
     * Gera JWT assinado com par de chaves diferente (pra testar invalid_signature).
     */
    public static function makeJwtWithOtherKey(): string
    {
        $tmpKey = self::generateOtherPrivateKey();

        $now = new DateTimeImmutable();
        $builder = (new Builder(new JoseEncoder(), ChainedFormatter::default()))
            ->issuedBy('togare-empresa')
            ->relatedTo('test')
            ->issuedAt($now)
            ->expiresAt($now->modify('+30 days'))
            ->identifiedBy('other-key-jti')
            ->withClaim('mod', ['togare-djen']);

        $token = $builder->getToken(
            new Sha256(),
            InMemory::plainText($tmpKey),
        );

        return $token->toString();
    }

    private static function generateOtherPrivateKey(): string
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $res = \openssl_pkey_new($config);
        if ($res === false) {
            throw new \RuntimeException('openssl_pkey_new falhou');
        }
        \openssl_pkey_export($res, $privateKey);

        return (string) $privateKey;
    }
}
