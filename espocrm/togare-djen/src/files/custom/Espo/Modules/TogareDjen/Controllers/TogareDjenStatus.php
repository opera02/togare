<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\TogareDjen\Services\DjenAdapter;
use stdClass;

/**
 * Story 4b.4 / ADR 0009 — endpoint REST para o `SystemStatusBannerView`
 * polar o estado do circuit breaker do DjenAdapter.
 *
 * Endpoint:
 *   GET /api/v1/TogareDjenStatus/action/snapshot
 *
 * Response shape (HTTP 200):
 *   {
 *     "cbOpen": bool,             // compat: true quando DJEN está operacionalmente indisponível
 *     "technicalCbOpen": bool,    // true apenas durante o cooldown técnico do CB
 *     "openedAt": string|null,    // ISO 8601 BRT do início da indisponibilidade
 *     "openUntil": string|null,   // ISO 8601 BRT do cooldown técnico, quando conhecido
 *     "unavailableSince": string|null,
 *     "minutesOpen": int,         // floor((now - unavailable_since) / 60)
 *     "nextRetryHint": string|null  // "HH:MM" 24h BRT, null quando saudável
 *   }
 *
 * ACL: roles operacionais são inferidas por acesso de leitura a Prazo ou
 *      PublicacaoAmbigua. O scope `TogareDjenStatus` continua aceito para
 *      customizações futuras, mas não é obrigatório nos seeds RBAC.
 */
class TogareDjenStatus
{
    private const TIMEZONE_BRT = 'America/Sao_Paulo';

    public function __construct(
        private readonly Acl $acl,
        private readonly DjenAdapter $adapter,
    ) {
    }

    /**
     * @throws Forbidden
     */
    public function getActionSnapshot(Request $request): stdClass
    {
        if (! $this->hasOperationalAccess()) {
            throw new Forbidden('No access to TogareDjenStatus.');
        }

        $state = $this->adapter->getCircuitBreakerState();
        $now = \time();

        $openUntil = (int) ($state['open_until'] ?? 0);
        $unavailableSince = (int) ($state['unavailable_since'] ?? 0);
        $technicalCbOpen = $openUntil > 0 && $openUntil > $now;
        $djenUnavailable = $unavailableSince > 0;
        $nextRetryAt = $technicalCbOpen ? $openUntil : $now;

        $response = new stdClass();
        $response->cbOpen = $djenUnavailable;
        $response->technicalCbOpen = $technicalCbOpen;
        $response->openedAt = $djenUnavailable ? $this->toIsoBrt($unavailableSince) : null;
        $response->openUntil = $openUntil > 0 ? $this->toIsoBrt($openUntil) : null;
        $response->unavailableSince = $djenUnavailable ? $this->toIsoBrt($unavailableSince) : null;
        $response->minutesOpen = $djenUnavailable
            ? (int) \floor(($now - $unavailableSince) / 60)
            : 0;
        $response->nextRetryHint = $djenUnavailable ? $this->toHourMinuteBrt($nextRetryAt) : null;

        return $response;
    }

    private function hasOperationalAccess(): bool
    {
        return $this->acl->checkScope('TogareDjenStatus')
            || $this->acl->checkScope('Prazo', 'read')
            || $this->acl->checkScope('PublicacaoAmbigua', 'read');
    }

    private function toIsoBrt(int $unixTimestamp): string
    {
        return (new DateTimeImmutable('@' . $unixTimestamp))
            ->setTimezone(new DateTimeZone(self::TIMEZONE_BRT))
            ->format('c');
    }

    private function toHourMinuteBrt(int $unixTimestamp): string
    {
        return (new DateTimeImmutable('@' . $unixTimestamp))
            ->setTimezone(new DateTimeZone(self::TIMEZONE_BRT))
            ->format('H:i');
    }
}
