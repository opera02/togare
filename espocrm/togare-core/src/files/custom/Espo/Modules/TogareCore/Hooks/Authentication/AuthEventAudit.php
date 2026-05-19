<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Hooks\Authentication;

use Espo\Core\Authentication\AuthenticationData;
use Espo\Core\Authentication\Hook\OnResult;
use Espo\Core\Authentication\Result;
use Espo\Core\Authentication\Result\FailReason;
use Espo\Core\Api\Request;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;

/**
 * Captura auth.login.success e auth.login.failed em togare_audit_log (FR37).
 *
 * Registrado via metadata/app/authentication.json em onSuccessHookClassNameList
 * e onFailHookClassNameList — mecanismo nativo do EspoCRM para hooks de auth.
 * Não usa ORM hooks (AfterSave<AuthLogRecord>) porque esses não disparam durante
 * falhas de autenticação (Container não tem 'user' nesse momento).
 */
final class AuthEventAudit implements OnResult
{
    /**
     * Allowlist dos failReasons conhecidos do EspoCRM core (Result\FailReason).
     * Reasons fora dessa lista (3rd party plugins, SSO custom, LDAP misconfig)
     * são substituídos por 'unknown' antes de gravar em context_json — defesa
     * em profundidade contra log injection / vazamento de payload arbitrário.
     */
    private const KNOWN_FAIL_REASONS = [
        FailReason::DENIED,
        FailReason::CODE_NOT_VERIFIED,
        FailReason::NO_USERNAME,
        FailReason::NO_PASSWORD,
        FailReason::TOKEN_NOT_FOUND,
        FailReason::USER_NOT_FOUND,
        FailReason::WRONG_CREDENTIALS,
        FailReason::USER_TOKEN_MISMATCH,
        FailReason::HASH_NOT_MATCHED,
        FailReason::METHOD_NOT_ALLOWED,
        FailReason::DISCREPANT_DATA,
        FailReason::ANOTHER_USER_NOT_FOUND,
        FailReason::ANOTHER_USER_NOT_ALLOWED,
        FailReason::ERROR,
    ];

    public function __construct(
        private readonly AuditLogContract $auditLog,
    ) {
    }

    public function process(Result $result, AuthenticationData $data, Request $request): void
    {
        $isFail = $result->isFail();
        $event = $isFail ? 'auth.login.failed' : 'auth.login.success';

        $user = $result->getUser();
        $userName = $user !== null ? $user->getUserName() : $data->getUsername();
        $ip = $request->getServerParam('REMOTE_ADDR');

        // Cast defensivo para string — getServerParam retorna mixed; um proxy
        // mal configurado pode injetar array/objeto, causando type-error
        // na serialização context_json.
        $context = \array_filter([
            'userName' => \is_string($userName) ? $userName : null,
            'ipAddress' => \is_string($ip) ? $ip : null,
        ], static fn ($v): bool => $v !== null && $v !== '');

        if ($isFail) {
            $reason = $result->getFailReason();
            if (\is_string($reason) && $reason !== '') {
                $context['failReason'] = \in_array($reason, self::KNOWN_FAIL_REASONS, true)
                    ? $reason
                    : 'unknown';
            }
        }

        $this->auditLog->log($event, 'AuthLogRecord', null, $context);
    }
}
