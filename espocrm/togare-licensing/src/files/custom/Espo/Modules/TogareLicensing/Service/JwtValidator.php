<?php

declare(strict_types=1);

namespace Espo\Modules\TogareLicensing\Service;

use DateInterval;
use DateTimeImmutable;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\RegisteredClaims;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Valida chaves JWT contra a chave pública RSA embutida — STATELESS.
 *
 * Não toca banco. Não depende do servidor Togare empresa em runtime
 * (NFR12 — operação totalmente offline).
 *
 * Schema esperado de claims:
 *   - iss = 'togare-empresa' (validado por IssuedBy)
 *   - sub = installation_id (string arbitrária — escritório identifica)
 *   - iat = unix timestamp (validado por StrictValidAt)
 *   - exp = unix timestamp (validado por StrictValidAt, leeway 5min)
 *   - jti = id da chave (obrigatório — pra rastreio futuro de revogação)
 *   - mod = array<string> de módulos premium ativados (≥1 elemento)
 *
 * NUNCA loga o JWT em si — apenas reason de falha e prefixo (8 chars) do jti.
 */
final class JwtValidator
{
    private const ISSUER = 'togare-empresa';
    private const LEEWAY_SECONDS = 'PT5M';

    public function __construct(
        private readonly PublicKeyProvider $publicKeyProvider,
        private readonly ClockInterface $clock,
    ) {
    }

    public function validate(string $key): JwtValidationResult
    {
        Bootstrap::init();

        // Parse — invalid format → malformed.
        try {
            $token = (new Parser(new JoseEncoder()))->parse(\trim($key));
        } catch (Throwable) {
            $this->logInvalid(JwtValidationResult::REASON_MALFORMED, null);

            return JwtValidationResult::invalid(
                JwtValidationResult::REASON_MALFORMED,
                'Chave JWT mal-formada — verifique se copiou a chave inteira sem quebras de linha extras.',
            );
        }

        if (! $token instanceof UnencryptedToken) {
            $this->logInvalid(JwtValidationResult::REASON_MALFORMED, null);

            return JwtValidationResult::invalid(
                JwtValidationResult::REASON_MALFORMED,
                'Chave JWT em formato não suportado (esperado: assinatura RSA não-cifrada).',
            );
        }

        // Constraints — assinatura, issuer, validade temporal.
        $constraints = [
            new SignedWith(new Sha256(), InMemory::plainText($this->publicKeyProvider->getPublicKeyPem())),
            new IssuedBy(self::ISSUER),
            new LooseValidAt($this->clock, new DateInterval(self::LEEWAY_SECONDS)),
        ];

        try {
            (new Validator())->assert($token, ...$constraints);
        } catch (RequiredConstraintsViolated $e) {
            $reason = $this->mapViolationsToReason($e);
            $jtiPrefix = $this->extractJtiPrefix($token);
            $this->logInvalid($reason, $jtiPrefix);

            return JwtValidationResult::invalid($reason, $this->errorMessageFor($reason));
        } catch (Throwable) {
            $this->logInvalid(JwtValidationResult::REASON_INVALID_SIGNATURE, null);

            return JwtValidationResult::invalid(
                JwtValidationResult::REASON_INVALID_SIGNATURE,
                'Falha ao verificar assinatura da chave JWT.',
            );
        }

        // Claims customizados: precisa de mod array ≥1.
        $claims = $token->claims()->all();
        $mod = $claims['mod'] ?? null;
        if (! \is_array($mod) || $mod === []) {
            $this->logInvalid(JwtValidationResult::REASON_MALFORMED, $this->extractJtiPrefix($token));

            return JwtValidationResult::invalid(
                JwtValidationResult::REASON_MALFORMED,
                'Chave JWT não declara nenhum módulo (claim "mod" ausente ou vazia).',
            );
        }

        return JwtValidationResult::valid($this->normalizeClaims($claims));
    }

    /**
     * Retira datetimes/uuids dos claims, devolvendo array primitivo serializável.
     *
     * @param  array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private function normalizeClaims(array $claims): array
    {
        $out = [];
        foreach ($claims as $name => $value) {
            $out[$name] = $value instanceof DateTimeImmutable ? $value->getTimestamp() : $value;
        }

        return $out;
    }

    private function mapViolationsToReason(RequiredConstraintsViolated $e): string
    {
        foreach ($e->violations() as $violation) {
            $msg = \strtolower($violation->getMessage());

            // lcobucci/jwt v5 emite mensagens como:
            //   - "the token is expired"
            //   - "the token is not yet valid"
            //   - "the token was issued by a different issuer"
            //   - "token signature mismatch"
            // Match por substrings DISTINTAS (sem colisão com palavras comuns).
            if (\str_contains($msg, 'expired')) {
                return JwtValidationResult::REASON_EXPIRED;
            }
            if (\str_contains($msg, 'issuer')) {
                return JwtValidationResult::REASON_WRONG_ISSUER;
            }
            if (\str_contains($msg, 'signature') || \str_contains($msg, 'signed')) {
                return JwtValidationResult::REASON_INVALID_SIGNATURE;
            }
        }

        return JwtValidationResult::REASON_INVALID_SIGNATURE;
    }

    private function errorMessageFor(string $reason): string
    {
        return match ($reason) {
            JwtValidationResult::REASON_EXPIRED => 'A chave JWT expirou. Solicite renovação ao Togare empresa.',
            JwtValidationResult::REASON_WRONG_ISSUER => 'A chave JWT foi emitida por um issuer desconhecido.',
            JwtValidationResult::REASON_INVALID_SIGNATURE => 'Assinatura da chave JWT inválida — chave pode ter sido adulterada ou foi emitida com chave privada diferente.',
            default => 'Chave JWT inválida.',
        };
    }

    private function extractJtiPrefix(UnencryptedToken $token): ?string
    {
        $jti = $token->claims()->get(RegisteredClaims::ID);
        if (! \is_string($jti) || $jti === '') {
            return null;
        }

        return \substr($jti, 0, 8);
    }

    private function logInvalid(string $reason, ?string $jtiPrefix): void
    {
        TogareLogger::event(
            'warning',
            'licensing.key.invalid',
            'Tentativa de ativar chave JWT inválida',
            \array_filter([
                'reason' => $reason,
                'jti_prefix' => $jtiPrefix,
            ], static fn (mixed $v): bool => $v !== null),
        );
    }
}
