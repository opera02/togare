<?php

declare(strict_types=1);

namespace Espo\Modules\TogareRbac\Service;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Modules\TogareCore\Services\TogareLogger;

/**
 * Aplica os ajustes de policy de segurança do Togare via ConfigWriter nativo
 * do EspoCRM. Idempotente: nunca baixa um valor que o admin tornou mais
 * restritivo.
 *
 * Story 2.2: 6 chaves de senha (FR1 + NFR8).
 * Story 2.3: 3 chaves MFA (auth2FA, auth2FAMethodList, auth2FAInPortal).
 * Story 2.5: 4 chaves sessão + throttle nativo (authTokenMaxIdleTime,
 *            authTokenLifetime, authMaxFailedAttemptNumber, authFailedAttemptsPeriod).
 * NÃO seta auth2FAForced — granularidade global incompatível com NFR9
 * (Sócio/Admin obrigatório, demais roles opcionais via MfaPolicyResolver).
 *
 * Comparação "mais restritivo" por chave:
 *   - inteiros mínimos (Length, LetterCount, NumberCount,
 *     SpecialCharacterCount): admin >= default → mantém.
 *   - boolean BothCases: admin true → mantém.
 *   - lifetime do invite: TTL menor é mais restritivo.
 *   - auth2FA (bool): somente seta se ainda false/null (ativar é irreversível).
 *   - auth2FAMethodList: preserva config do admin (não restringe opções).
 *   - auth2FAInPortal (bool): somente seta se ainda true/null (false é safer).
 *   - authTokenMaxIdleTime (float): 0 = desabilitado (menos restritivo); valor
 *     menor é mais restritivo; aplica se null, 0 (desabilitado) ou > 0.5.
 *   - authTokenLifetime (int/float): aplica somente se null (preserva qualquer
 *     valor que admin pôs intencionalmente).
 *   - authMaxFailedAttemptNumber (int): menor é mais restritivo; aplica se null
 *     ou > default.
 *   - authFailedAttemptsPeriod (string): aplica somente se null (string com
 *     unidade — sem comparação semântica).
 */
final class SecurityConfigInstaller
{
    /**
     * Política de segurança do MVP Togare. Aplicada no AfterInstall.
     */
    public const DEFAULTS = [
        // Story 2.2 — política de senha forte (FR1, NFR8)
        'passwordStrengthLength' => 10,
        'passwordStrengthLetterCount' => 1,
        'passwordStrengthNumberCount' => 1,
        'passwordStrengthSpecialCharacterCount' => 1,
        'passwordStrengthBothCases' => true,
        'passwordChangeRequestNewUserLifetime' => 168,
        // Story 2.3 — MFA TOTP (FR2, NFR9)
        // NÃO inclui auth2FAForced (global — quebraria opt-in dos outros roles).
        'auth2FA' => true,
        'auth2FAMethodList' => ['Totp'],
        'auth2FAInPortal' => false,
        // Story 2.5 — sessão CRM 30min idle + throttle nativo EspoCRM (NFR11, NFR13)
        // authTokenMaxIdleTime é global (CRM + Portal). Portal 45min resolve em Story 7a.6.
        'authTokenMaxIdleTime' => 0.5,         // 0.5h = 30 min (NFR13 CRM)
        'authTokenLifetime' => 0,              // 0 = ilimitado; idle controla expiração
        'authMaxFailedAttemptNumber' => 10,    // throttle nativo IP-based (defense in depth)
        'authFailedAttemptsPeriod' => '60 seconds',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly ConfigWriter $configWriter,
    ) {
    }

    /**
     * @return array{applied: list<string>, skipped: list<string>}
     */
    public function applyDefaults(): array
    {
        $summary = ['applied' => [], 'skipped' => []];
        $changes = [];

        foreach (self::DEFAULTS as $key => $defaultValue) {
            $current = $this->config->get($key);
            $shouldApply = $this->shouldApply($key, $current, $defaultValue);

            if ($shouldApply) {
                $changes[$key] = $defaultValue;
                $summary['applied'][] = $key;

                TogareLogger::event(
                    'info',
                    'rbac.config.applied',
                    \sprintf("Config '%s' atualizada de %s para %s.", $key, $this->stringify($current), $this->stringify($defaultValue)),
                    ['key' => $key, 'oldValue' => $current, 'newValue' => $defaultValue],
                );
            } else {
                $summary['skipped'][] = $key;

                TogareLogger::event(
                    'info',
                    'rbac.config.skipped',
                    \sprintf("Config '%s' já está em valor mais restritivo (%s) — preservada.", $key, $this->stringify($current)),
                    ['key' => $key, 'currentValue' => $current],
                );
            }
        }

        if ($changes !== []) {
            $this->configWriter->setMultiple($changes);
            $this->configWriter->save();
        }

        return $summary;
    }

    private function shouldApply(string $key, mixed $current, mixed $defaultValue): bool
    {
        if ($current === null) {
            return true;
        }

        if ($key === 'passwordStrengthBothCases') {
            return ! (bool) $current;
        }

        if ($key === 'passwordChangeRequestNewUserLifetime') {
            // TTL menor é mais restritivo. Aplica se admin pôs maior que o default.
            return (int) $current > (int) $defaultValue;
        }

        // auth2FA: ativar é irreversível do ponto de vista de segurança.
        // Só aplica se ainda está false/null.
        if ($key === 'auth2FA') {
            return ! (bool) $current;
        }

        // auth2FAInPortal: false é mais seguro (portal sem MFA, UX-DR1 — idosos/leigos).
        // Só aplica se ainda está true/null.
        if ($key === 'auth2FAInPortal') {
            return (bool) $current;
        }

        // auth2FAMethodList: preservar config do admin (ele pode ter adicionado métodos
        // adicionais intencionalmente). Só aplica se vazio/null.
        if ($key === 'auth2FAMethodList') {
            return empty($current);
        }

        // authTokenMaxIdleTime: 0 = desabilitado (menos restritivo); valor menor = mais restritivo.
        // Aplica se: null, ou 0 (desabilitado → habilitar 30min), ou > 0.5 (timeout muito longo).
        if ($key === 'authTokenMaxIdleTime') {
            if ($current === null) {
                return true;
            }
            $currentFloat = (float) $current;
            if ($currentFloat <= 0.0) {
                return true; // 0 (desabilitado) ou negativo → habilitar com 30min.
            }
            return $currentFloat > (float) $defaultValue;
        }

        // authTokenLifetime: aplica somente se null (preserva qualquer valor que admin pôs).
        // 0 = ilimitado; qualquer positivo = expiração absoluta (admin pode querer isso).
        if ($key === 'authTokenLifetime') {
            return $current === null;
        }

        // authMaxFailedAttemptNumber: menor é mais restritivo. Aplica se null ou > default.
        if ($key === 'authMaxFailedAttemptNumber') {
            if ($current === null) {
                return true;
            }
            return (int) $current > (int) $defaultValue;
        }

        // authFailedAttemptsPeriod: string com unidade — sem comparação semântica.
        // Aplica somente se null/vazio (preserva customização do admin).
        if ($key === 'authFailedAttemptsPeriod') {
            return $current === null || $current === '';
        }

        // Inteiros mínimos: aplica se admin pôs menor que o default (ou se é 0/null).
        return (int) $current < (int) $defaultValue;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return \json_encode($value, JSON_UNESCAPED_UNICODE) ?: '?';
    }
}
