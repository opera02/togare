<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use Throwable;

/**
 * Implementação canônica do AuditLogContract — grava em `togare_audit_log`
 * (Story 2.4 — FR37, NFR10).
 *
 * Decisões vinculantes:
 *
 *  - **log() NÃO bloqueia o caller.** Erros de PDO (tabela ausente, GRANT
 *    revogado por engano, deadlock, etc.) são capturados e roteados pro
 *    TogareLogger como `audit.log.write.failed`. O fluxo de negócio segue.
 *    Razão: auditoria nunca pode virar SPOF da operação. Se a equipe quiser
 *    bloquear, é decisão consciente de release futuro com flag explícita.
 *
 *  - **NÃO é idempotente.** Mesmo evento/entity/context em sequência → 2 rows.
 *    Audit prefere redundância à omissão. Quem precisa de dedup sai daqui.
 *
 *  - **Validação rígida de tamanho** em `event` (≤120 chars), `entityType`
 *    (≤80 chars) e `entityId` (≤32 chars) — bate com o schema da V006.
 *    Inputs que extrapolam lançam InvalidArgumentException; é bug de caller,
 *    não falha externa.
 *
 *  - **`occurred_at` em UTC explícito.** Independe da timezone do servidor —
 *    correlação cross-host fica determinística e DST não corrompe a ordem.
 */
final class AuditLogService implements AuditLogContract
{
    private const EVENT_MAX_LEN = 120;
    private const ENTITY_TYPE_MAX_LEN = 80;
    private const ENTITY_ID_MAX_LEN = 32;
    private const USER_AGENT_MAX_LEN = 500;
    private const IP_ADDRESS_MAX_LEN = 45;
    private const CORRELATION_ID_MAX_LEN = 64;

    private readonly PDO $pdo;

    public function __construct(
        EntityManager $entityManager,
    ) {
        // InjectableFactory do EspoCRM resolve EntityManager por type-hint.
        // Testes unit usam createMock(EntityManager::class)->willReturn($pdo).
        $this->pdo = $entityManager->getPDO();
    }

    public function log(
        string $event,
        string $entityType,
        ?string $entityId,
        array $context = [],
    ): void {
        if ($event === '' || \mb_strlen($event) > self::EVENT_MAX_LEN) {
            throw new \InvalidArgumentException(\sprintf(
                "AuditLogService: 'event' deve ter 1..%d chars; recebido %d.",
                self::EVENT_MAX_LEN,
                \mb_strlen($event),
            ));
        }
        if ($entityType === '' || \mb_strlen($entityType) > self::ENTITY_TYPE_MAX_LEN) {
            throw new \InvalidArgumentException(\sprintf(
                "AuditLogService: 'entityType' deve ter 1..%d chars; recebido %d.",
                self::ENTITY_TYPE_MAX_LEN,
                \mb_strlen($entityType),
            ));
        }
        if ($entityId !== null && \mb_strlen($entityId) > self::ENTITY_ID_MAX_LEN) {
            throw new \InvalidArgumentException(\sprintf(
                "AuditLogService: 'entityId' deve ter ≤%d chars; recebido %d.",
                self::ENTITY_ID_MAX_LEN,
                \mb_strlen($entityId),
            ));
        }

        $occurredAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d H:i:s.v');
        // user_id e user_name ficam null — hooks passam o contexto relevante
        // no array $context. Injetar Container causaria dependência circular
        // no fluxo de autenticação (auth falho → container sem 'user' → 500).
        $ip = $this->resolveIpAddress();
        $userAgent = $this->resolveUserAgent();
        $correlationId = $this->resolveCorrelationId();

        $contextJson = $this->encodeContext($context);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO togare_audit_log
                    (id, occurred_at, event, entity_type, entity_id,
                     user_id, user_name, ip_address, user_agent,
                     correlation_id, context_json)
                 VALUES
                    (:id, :occurred_at, :event, :entity_type, :entity_id,
                     :user_id, :user_name, :ip_address, :user_agent,
                     :correlation_id, :context_json)'
            );
            $stmt->execute([
                ':id' => $this->newId(),
                ':occurred_at' => $occurredAt,
                ':event' => $event,
                ':entity_type' => $entityType,
                ':entity_id' => $entityId,
                ':user_id' => null,
                ':user_name' => null,
                ':ip_address' => $ip,
                ':user_agent' => $userAgent === null
                    ? null
                    : \mb_substr($userAgent, 0, self::USER_AGENT_MAX_LEN),
                ':correlation_id' => $correlationId,
                ':context_json' => $contextJson,
            ]);
        } catch (PDOException $e) {
            // NÃO propaga: auditoria nunca quebra o flow de negócio.
            // Reporta via TogareLogger para que SREs/admin investiguem.
            try {
                TogareLogger::event(
                    'error',
                    'audit.log.write.failed',
                    \sprintf(
                        "Falha ao gravar audit log para event='%s' (entityType='%s'): %s",
                        $event,
                        $entityType,
                        $e->getMessage(),
                    ),
                    [
                        'event' => $event,
                        'entityType' => $entityType,
                        'entityId' => $entityId,
                        'sqlstate' => $e->getCode(),
                        'cause' => $e->getMessage(),
                    ],
                );
            } catch (Throwable) {
                // Fallback silencioso: logger pode estar fora de init em testes.
            }
        }
    }

    /**
     * Serializa o context array para JSON. Quando a serialização falha
     * (recursão, closure, UTF-8 inválido) grava um marker indicando o motivo
     * em vez de descartar o evento — o INSERT é preservado e o admin descobre
     * pelo próprio audit que houve corrupção de payload.
     *
     * @param array<string, mixed> $context
     */
    private function encodeContext(array $context): ?string
    {
        if ($context === []) {
            return null;
        }
        $encoded = \json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return \json_encode(
                ['_audit_context_encode_failed' => \json_last_error_msg()],
                JSON_UNESCAPED_UNICODE,
            );
        }
        return $encoded;
    }

    private function newId(): string
    {
        // 32 chars hex (compat com togare_queue_items.id da V004).
        return \bin2hex(\random_bytes(16));
    }

    /**
     * Resolve o IP da requisição. Apenas REMOTE_ADDR é considerado confiável —
     * X-Forwarded-For seria spoofable na ausência de uma allowlist de proxies
     * confiáveis (será reabilitado quando houver proxy reverso validado, Epic 10).
     * Trunca para o limite do schema (VARCHAR(45)) — IPv6 com zone-id pode
     * superar 45 chars, e a coluna nunca deve causar drop silencioso por
     * data-too-long.
     */
    private function resolveIpAddress(): ?string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!\is_string($remote) || $remote === '') {
            return null;
        }
        return \mb_substr($remote, 0, self::IP_ADDRESS_MAX_LEN);
    }

    private function resolveUserAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return \is_string($ua) && $ua !== '' ? $ua : null;
    }

    private function resolveCorrelationId(): ?string
    {
        $header = $_SERVER['HTTP_X_TOGARE_CORRELATION_ID'] ?? null;
        if (!\is_string($header) || $header === '') {
            return null;
        }
        return \mb_substr($header, 0, self::CORRELATION_ID_MAX_LEN);
    }
}
