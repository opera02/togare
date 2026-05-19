<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Notification;

/**
 * Constantes compartilhadas pelo subsistema togare-core/Notifications & Reminders
 * (ADR-04 — Story 4b.2). Consumidas por:
 *  - `EnqueuePrazoLembretesHook` (write em `togare_prazo_lembrete`)
 *  - `PrazoReminderJob` (read+update em `togare_prazo_lembrete`)
 *  - `StreamNotificationService` / `EmailNotificationService` (via $marco/$canal)
 *
 * Marcos (varchar 32) atualmente suportados: D-7/D-3/D-1/D-0 (deadline) +
 * status_atrasado_reagendado/aguardando_cliente/aguardando_correcao (status
 * dirigidos). D-0 entrou na Story 4b.3 (UX-DR10) com hora de disparo
 * especial 00:05 BRT (vs 09:00 dos demais).
 */
final class PrazoLembreteConstants
{
    /** Marcos de proximidade do `dataFatal` (calculados via subtractBusinessDays). */
    public const MARCO_D7 = 'D-7';
    public const MARCO_D3 = 'D-3';
    public const MARCO_D1 = 'D-1';
    /** Story 4b.3 — vence hoje (offset = 0; mesma data do `dataFatal`). */
    public const MARCO_D0 = 'D-0';

    /** Marcos de status dirigido (disparam `scheduled_for=now`). */
    public const MARCO_STATUS_REAGENDADO = 'status_atrasado_reagendado';
    public const MARCO_STATUS_AGUARDANDO_CLIENTE = 'status_aguardando_cliente';
    public const MARCO_STATUS_AGUARDANDO_CORRECAO = 'status_aguardando_correcao';

    /** @var array<string, int> Mapa marco → offset em dias úteis. */
    public const DEADLINE_OFFSETS = [
        self::MARCO_D7 => 7,
        self::MARCO_D3 => 3,
        self::MARCO_D1 => 1,
        self::MARCO_D0 => 0,
    ];

    /** Hora de disparo legacy (Decisão #6 da Story 4b.2) — fallback para
     *  marcos sem entrada explícita em HORA_DISPARO_BY_MARCO. */
    public const HORA_DISPARO = 9; // 09:00 America/Sao_Paulo
    public const TZ_BRT = 'America/Sao_Paulo';

    /**
     * Story 4b.3 (Decisão #2) — hora de disparo por marco. D-0 dispara
     * 00:05 BRT (alinha NFR5 + AC original do epic linha 1144 "email de
     * backup já foi enviado às 00:05 do dia"). Demais marcos mantêm 09:00 BRT.
     *
     * @var array<string, int>
     */
    public const HORA_DISPARO_BY_MARCO = [
        self::MARCO_D7 => 9,
        self::MARCO_D3 => 9,
        self::MARCO_D1 => 9,
        self::MARCO_D0 => 0, // 00:00 BRT (minuto vem de MINUTO_DISPARO_BY_MARCO).
    ];

    /**
     * Story 4b.3 (Decisão #2) — minuto de disparo por marco.
     *
     * @var array<string, int>
     */
    public const MINUTO_DISPARO_BY_MARCO = [
        self::MARCO_D7 => 0,
        self::MARCO_D3 => 0,
        self::MARCO_D1 => 0,
        self::MARCO_D0 => 5, // 00:05 BRT (NFR5: ≤5min após entrar na janela).
    ];

    /** Nome literal do role Sócio/Admin (case-sensitive — seed `socio-admin.json`). */
    public const ROLE_SOCIO_ADMIN_NAME = 'Sócio/Admin';

    /** Canais (DB enum textual). */
    public const CANAL_POPUP = 'popup';
    public const CANAL_EMAIL = 'email';
    public const CANAL_BOTH = 'both';

    /** Status (DB enum textual). */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /** Retry SMTP — 3 tentativas com backoff exponencial (Decisão #7 da Story 4b.2). */
    public const MAX_EMAIL_ATTEMPTS = 3;
    public const RETRY_BACKOFF_MINUTES = [1, 5, 30]; // attempt_count 1/2/3

    /**
     * Defaults aplicados quando user não tem `Preferences.togareLembreteConfig`
     * setado ainda (Decisão #5 da Story 4b.2).
     *
     * @return array{channels: array{popup: bool, email: bool}, marcos: array{"D-7": bool, "D-3": bool, "D-1": bool, status_dirigido: bool}}
     */
    public static function defaultConfig(): array
    {
        return [
            'channels' => ['popup' => true, 'email' => true],
            'marcos' => [
                self::MARCO_D7 => true,
                self::MARCO_D3 => true,
                self::MARCO_D1 => true,
                self::MARCO_D0 => true, // Story 4b.3: fail-safe para o marco mais crítico.
                'status_dirigido' => true,
            ],
        ];
    }

    /**
     * Resolve `canal` final dadas as preferences do user para um marco específico.
     * Retorna NULL quando user não quer receber este marco (ambos canais off
     * ou marco específico desligado).
     */
    public static function resolveCanal(array $preferences, string $marco): ?string
    {
        $config = self::mergeWithDefaults($preferences);
        $marcoKey = self::isStatusDirigidoMarco($marco) ? 'status_dirigido' : $marco;
        if (! ($config['marcos'][$marcoKey] ?? false)) {
            return null;
        }
        $popup = (bool) ($config['channels']['popup'] ?? false);
        $email = (bool) ($config['channels']['email'] ?? false);
        if ($popup && $email) {
            return self::CANAL_BOTH;
        }
        if ($popup) {
            return self::CANAL_POPUP;
        }
        if ($email) {
            return self::CANAL_EMAIL;
        }
        return null;
    }

    public static function isStatusDirigidoMarco(string $marco): bool
    {
        return $marco === self::MARCO_STATUS_REAGENDADO
            || $marco === self::MARCO_STATUS_AGUARDANDO_CLIENTE
            || $marco === self::MARCO_STATUS_AGUARDANDO_CORRECAO;
    }

    /**
     * Subject + título para o email/notification por marco (pt-BR hardcoded —
     * fallback caso i18n não esteja carregado em runtime do Job; Story 4b.2 AC4).
     *
     * @return array{subject: string, title: string}
     */
    public static function labelsForMarco(string $marco, string $cnj): array
    {
        $titles = [
            self::MARCO_D7 => 'Prazo vence em 7 dias úteis',
            self::MARCO_D3 => 'Prazo vence em 3 dias úteis',
            self::MARCO_D1 => 'Prazo vence amanhã (D-1)',
            // Story 4b.3 (Decisão #7) — D-0 sem prefixo "Prazo " (urgência > formalidade).
            self::MARCO_D0 => 'VENCE HOJE',
            self::MARCO_STATUS_REAGENDADO => 'Prazo marcado como atrasado/reagendado — sua atenção',
            self::MARCO_STATUS_AGUARDANDO_CLIENTE => 'Prazo aguarda resposta do cliente',
            self::MARCO_STATUS_AGUARDANDO_CORRECAO => 'Prazo aguarda correção',
        ];
        $title = $titles[$marco] ?? 'Prazo — ' . $marco;
        return [
            'subject' => "[Togare] {$title} — {$cnj}",
            'title' => $title,
        ];
    }

    /**
     * Hedge jurídico FR39 literal (PRD v1.3.1) — auditável para tribunal.
     * Constante para facilitar smoke testing + alinhamento com ADR-04 §5.
     */
    public const HEDGE_JURIDICO = 'A responsabilidade final pelo cumprimento do prazo é do(a) advogado(a). O Togare é uma ferramenta auxiliar e pode falhar; sempre confirme prazos críticos no DJEN/diário oficial.';

    /**
     * Merge defaults com preferences custom do user. Defaults aplicam apenas
     * quando key específica está ausente (não preenche keys explicitamente
     * `false`).
     *
     * @param array<string, mixed> $userConfig
     * @return array{channels: array<string, bool>, marcos: array<string, bool>}
     */
    public static function mergeWithDefaults(array $userConfig): array
    {
        $defaults = self::defaultConfig();
        $channels = \is_array($userConfig['channels'] ?? null) ? $userConfig['channels'] : [];
        $marcos = \is_array($userConfig['marcos'] ?? null) ? $userConfig['marcos'] : [];

        return [
            'channels' => [
                'popup' => \array_key_exists('popup', $channels) ? (bool) $channels['popup'] : $defaults['channels']['popup'],
                'email' => \array_key_exists('email', $channels) ? (bool) $channels['email'] : $defaults['channels']['email'],
            ],
            'marcos' => [
                self::MARCO_D7 => \array_key_exists(self::MARCO_D7, $marcos) ? (bool) $marcos[self::MARCO_D7] : $defaults['marcos'][self::MARCO_D7],
                self::MARCO_D3 => \array_key_exists(self::MARCO_D3, $marcos) ? (bool) $marcos[self::MARCO_D3] : $defaults['marcos'][self::MARCO_D3],
                self::MARCO_D1 => \array_key_exists(self::MARCO_D1, $marcos) ? (bool) $marcos[self::MARCO_D1] : $defaults['marcos'][self::MARCO_D1],
                self::MARCO_D0 => \array_key_exists(self::MARCO_D0, $marcos) ? (bool) $marcos[self::MARCO_D0] : $defaults['marcos'][self::MARCO_D0],
                'status_dirigido' => \array_key_exists('status_dirigido', $marcos) ? (bool) $marcos['status_dirigido'] : $defaults['marcos']['status_dirigido'],
            ],
        ];
    }
}
