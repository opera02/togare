<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\TogareCore\Services\QueueService;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Contracts\PublicationSourceAdapterContract;
use Espo\Modules\TogareDjen\Exception\DjenAdapterUnavailableException;
use Throwable;

/**
 * Consumer da fila `djen`.
 *
 * Dispatcher por `payload.type`:
 *   - `sync_window`: chama o adapter, itera generator de pubs, enfileira N
 *     items `type=publication` via QueueService::enqueue (idempotency_key
 *     garante no-dup mesmo em re-runs).
 *   - `publication`: **handler real (Stories 4a.2 + 4a.3)** — chama
 *     `DjenParserService` que aplica art. 5º Res. CNJ 455 e calcula
 *     `dataFatal`; depois `PrazoCreatorService` cria entidade `Prazo`
 *     vinculada ao Processo (status=pendente) ou em rascunho
 *     (status=rascunho_nao_vinculado, payload preservado em
 *     `publicacaoOrigemRaw`). Loga `djen.publication.parsed` (info) com
 *     PrazoCalculado completo, `djen.parser.classifier_lowconfidence`
 *     (warning) quando confidence=low, `djen.publication.unparsed` (info)
 *     quando ato é certificatório (parser retorna null), e os eventos do
 *     creator (`djen.prazo.created_bound`, `djen.prazo.created_unbound`,
 *     `djen.prazo.deduped`, `djen.prazo.deduped_via_constraint`).
 *     markDone() em todos os caminhos (sem perda silenciosa).
 *
 * Try/catch hierárquico (Decisão #5 da Story 4a.1 — fecha invariante Spike 1b.S3):
 *   1. DjenAdapterUnavailableException → markFailed customDelay=3600 (AC3 4a.1).
 *   2. \Espo\Core\Exceptions\Forbidden license_expired → markFailed
 *      customDelay=3600 (AC5.1 4a.1; NÃO dead_letter — quando licença renovar,
 *      próximo claim recupera automaticamente).
 *   3. \Throwable outros → markFailed delay padrão (60s→...→960s).
 */
final class DjenWorkerService
{
    /** Customizável em testes via construtor (default 1h literal AC3). */
    private const CUSTOM_DELAY_SECONDS = 3600;

    /**
     * Story 4b.4 — categorias semânticas de falha gravadas em
     * `togare_queue_items.failure_category` (V017). Usadas pelo
     * `QueueService::rescheduleAfterCircuitBreakerClose` para filtrar quais
     * items reagendar quando o CB do adapter fecha.
     */
    public const FAILURE_CATEGORY_ADAPTER_UNAVAILABLE = 'adapter_unavailable';
    public const FAILURE_CATEGORY_LICENSE_EXPIRED = 'license_expired';
    public const FAILURE_CATEGORY_FORBIDDEN = 'forbidden';

    /** @var callable(): int Devolve "agora" em segundos (mockável em testes). */
    private $clock;

    public function __construct(
        private readonly QueueService $queueService,
        private readonly PublicationSourceAdapterContract $adapter,
        private readonly DjenParserService $parser,
        private readonly PrazoCreatorService $prazoCreator,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => \time();
    }

    /**
     * Tenta processar 1 item da fila djen.
     *
     * @return bool true se processou um item; false se a fila estava vazia.
     */
    public function processOne(): bool
    {
        // Story 4b.4 / ADR 0009 — tick check ANTES do claim.
        // Detecta transição open→closed do CB e reagenda items presos
        // (eliminando o gap CB-recupera 10min vs customDelay 1h).
        $this->detectAndHandleCbCloseTransition();

        $items = $this->queueService->claim('djen', 1);
        $item = $items[0] ?? null;
        if ($item === null) {
            return false;
        }

        $itemId = $item['id'];
        $payload = $item['payload'] ?? [];
        $type = \is_array($payload) ? ($payload['type'] ?? null) : null;

        try {
            match ($type) {
                'sync_window' => $this->handleSyncWindow($itemId, $payload),
                'publication' => $this->handlePublication($itemId, $payload),
                default => $this->handleUnknownType($itemId, $type),
            };

            $this->queueService->markDone($itemId);
        } catch (DjenAdapterUnavailableException $e) {
            $reason = 'djen sync window failed: ' . $e->getMessage();
            TogareLogger::event(
                'warning',
                'djen.worker.adapter_unavailable_retry',
                "Item DJEN re-enfileirado por adapter indisponível: {$reason}",
                ['itemId' => $itemId, 'type' => $type, 'retryInSeconds' => self::CUSTOM_DELAY_SECONDS],
            );
            // Story 4b.4: failure_category habilita reschedule quando CB fechar.
            $this->queueService->markFailed(
                $itemId,
                $reason,
                false,
                self::CUSTOM_DELAY_SECONDS,
                self::FAILURE_CATEGORY_ADAPTER_UNAVAILABLE,
            );
        } catch (Forbidden $e) {
            $reason = $this->classifyForbidden($e);
            $eventName = $reason === 'license_expired'
                ? 'djen.worker.license_expired_retry'
                : 'djen.worker.forbidden';
            TogareLogger::event(
                'warning',
                $eventName,
                "Item DJEN re-enfileirado por Forbidden ({$reason}): " . $e->getMessage(),
                ['itemId' => $itemId, 'type' => $type, 'retryInSeconds' => self::CUSTOM_DELAY_SECONDS],
            );
            // Story 4b.4: license_expired e forbidden NÃO são reagendáveis via
            // CB close (renovação de licença é manual; ACL não muda automaticamente).
            // Mas a categoria fica gravada para diagnóstico + filtros futuros.
            $failureCategory = $reason === 'license_expired'
                ? self::FAILURE_CATEGORY_LICENSE_EXPIRED
                : self::FAILURE_CATEGORY_FORBIDDEN;
            $this->queueService->markFailed(
                $itemId,
                $reason,
                false,
                self::CUSTOM_DELAY_SECONDS,
                $failureCategory,
            );
        } catch (Throwable $e) {
            TogareLogger::event(
                'error',
                'djen.worker.unexpected_error',
                "Item DJEN falhou (erro inesperado): " . $e->getMessage(),
                [
                    'itemId' => $itemId,
                    'type' => $type,
                    'exception' => \get_class($e),
                ],
            );
            // Story 4b.4: categoria NULL — categoria desconhecida não habilita
            // reschedule (Decisão #3).
            $this->queueService->markFailed($itemId, $e->getMessage(), false, null, null);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleSyncWindow(string $parentItemId, array $payload): void
    {
        $userId = (string) ($payload['userId'] ?? '');
        $oab = (string) ($payload['oab'] ?? '');
        $uf = (string) ($payload['uf'] ?? '');
        $dataInicio = (string) ($payload['dataInicio'] ?? '');
        $dataFim = (string) ($payload['dataFim'] ?? '');

        if ($userId === '' || $oab === '' || $uf === '' || $dataInicio === '' || $dataFim === '') {
            throw new \RuntimeException(
                "Payload sync_window incompleto (parentItemId={$parentItemId}): " . \json_encode($payload),
            );
        }

        $publicacoesEnfileiradas = 0;
        $generator = $this->adapter->fetchPublicacoes(
            $oab,
            $uf,
            new DateTimeImmutable($dataInicio),
            new DateTimeImmutable($dataFim),
        );

        foreach ($generator as $pub) {
            $idempotencyKey = 'djen.pub.' . $pub['id'];
            $publicacaoPayload = ['type' => 'publication'] + $pub + [
                'parentSyncWindowItemId' => $parentItemId,
                'userId' => $userId,
            ];
            $this->queueService->enqueue('djen', $publicacaoPayload, $idempotencyKey);
            $publicacoesEnfileiradas++;
        }

        TogareLogger::event(
            'info',
            'djen.worker.sync_window_processed',
            "Janela sync_window processada para userId={$userId}",
            [
                'parentItemId' => $parentItemId,
                'userId' => $userId,
                'oab' => $oab,
                'uf' => $uf,
                'dataInicio' => $dataInicio,
                'dataFim' => $dataFim,
                'publicacoesEnfileiradas' => $publicacoesEnfileiradas,
            ],
        );
    }

    /**
     * Handler real para items type=publication (Stories 4a.2 + 4a.3).
     *
     * Pipeline:
     *  1. Chama `DjenParserService::parse($payload)` — service puro que
     *     classifica o ato (regex + keyword), aplica regra do art. 5º
     *     Res. CNJ 455 e calcula `dataFatal`.
     *  2. Se parser retorna null (ato puramente certificatório — "Junte-se",
     *     "Cumpra-se", "Publique-se", "Conclusos") → loga
     *     `djen.publication.unparsed` (info) e markDone segue normal no
     *     chamador (NÃO cria Prazo).
     *  3. Se confidence === 'low' (fallback `manifestacao_generica`) →
     *     loga warning `djen.parser.classifier_lowconfidence` adicional
     *     para sinalizar revisão humana futura.
     *  4. Loga `djen.publication.parsed` (info) com PrazoCalculado completo.
     *  5. **Story 4a.3:** chama `PrazoCreatorService::create($payload, $prazoCalculado)`
     *     dentro do mesmo dispatch (Decisão #5 da 4a.2 — sequência síncrona
     *     transacional). Creator faz dedup (sourcePubId UNIQUE), match CNJ
     *     → Processo, heurística assignedUser cascade, persiste entity Prazo
     *     em togare-core, loga `djen.prazo.created_bound`/`created_unbound`/
     *     `deduped`/`deduped_via_constraint`.
     *
     * Exception do creator NÃO é capturada aqui — sobe para o catch
     * hierárquico do `processOne` (preservação Spike 1b.S3 — AC7.1 da
     * Story 4a.3): Throwable → markFailed delay padrão (vai para retry).
     *
     * @param array<string, mixed> $payload
     */
    private function handlePublication(string $itemId, array $payload): void
    {
        $prazoCalculado = $this->parser->parse($payload);

        $pubId = $payload['id'] ?? null;
        $numeroProcesso = $payload['numeroProcesso'] ?? null;

        if ($prazoCalculado === null) {
            TogareLogger::event(
                'info',
                'djen.publication.unparsed',
                'Publicação DJEN sem prazo aplicável (ato puramente certificatório)',
                [
                    'itemId' => $itemId,
                    'pubId' => $pubId,
                    'numeroProcesso' => $numeroProcesso,
                    'tipoComunicacao' => $payload['tipoComunicacao'] ?? null,
                    'tipoDocumento' => $payload['tipoDocumento'] ?? null,
                    'userId' => $payload['userId'] ?? null,
                ],
            );
            return;
        }

        if ($prazoCalculado->confidence === 'low') {
            TogareLogger::event(
                'warning',
                'djen.parser.classifier_lowconfidence',
                'Classificador caiu em fallback manifestacao_generica — recomendado revisão humana',
                [
                    'itemId' => $itemId,
                    'pubId' => $pubId,
                    'atoCodigo' => $prazoCalculado->atoCodigo,
                    'fonteExcerpt' => $prazoCalculado->fonteExcerpt,
                ],
            );
        }

        TogareLogger::event(
            'info',
            'djen.publication.parsed',
            'Publicação DJEN parseada — dataFatal calculada conforme art. 5º Res. CNJ 455',
            \array_merge(
                [
                    'itemId' => $itemId,
                    'pubId' => $pubId,
                    'numeroProcesso' => $numeroProcesso,
                    'dataDisponibilizacao' => $payload['dataDisponibilizacao'] ?? null,
                    'tipoComunicacao' => $payload['tipoComunicacao'] ?? null,
                    'tipoDocumento' => $payload['tipoDocumento'] ?? null,
                    'userId' => $payload['userId'] ?? null,
                ],
                $prazoCalculado->toArray(),
            ),
        );

        // Story 4a.3 — cria entidade Prazo (vinculado ou rascunho).
        // Throwable do creator sobe para o catch hierárquico em processOne
        // (AC7.1 — Spike 1b.S3 invariante: markFailed default delay → retry).
        $this->prazoCreator->create($payload, $prazoCalculado);
    }

    private function handleUnknownType(string $itemId, mixed $type): never
    {
        TogareLogger::event(
            'error',
            'djen.worker.unknown_payload_type',
            "Item DJEN com payload.type desconhecido: " . \var_export($type, true),
            ['itemId' => $itemId, 'type' => $type],
        );
        throw new \RuntimeException(
            "Payload type desconhecido na fila djen: " . \var_export($type, true),
        );
    }

    /**
     * Story 4b.4 / ADR 0009 — tick check pré-claim do circuit breaker.
     *
     * Lê estado atual do CB. Se detectar transição open→closed desde o último
     * tick (`open_until > 0 AND open_until <= now`), dispara reschedule de
     * todos os items DJEN `failed_retry` com `failure_category='adapter_unavailable'`
     * E zera as flags `open_until`/`opened_at` do CB (preserva `failures[]`
     * para a próxima janela de contagem).
     *
     * Lag máximo CB-recupera→retry-elegível = intervalo do sleep do worker
     * em fila vazia (5s).
     *
     * Idempotência: o UPDATE do reschedule tem `WHERE next_retry_at > :now`,
     * então 2 workers detectando simultaneamente fazem no-op.
     *
     * Captura Throwable defensivo — tick check NUNCA pode derrubar o worker
     * (princípio CLAUDE.md "infra de telemetria não trava bot").
     */
    private function detectAndHandleCbCloseTransition(): void
    {
        try {
            // Adapter pode não expor a interface (ex.: stub de teste). Defensivo.
            if (! \method_exists($this->adapter, 'getCircuitBreakerState')) {
                return;
            }
            /** @var array{failures:list<int>, open_until:int, opened_at:int, unavailable_since?:int} $state */
            $state = $this->adapter->getCircuitBreakerState();
            $now = ($this->clock)();
            $openUntil = (int) ($state['open_until'] ?? 0);

            if ($openUntil <= 0 || $openUntil > $now) {
                // CB nunca abriu OU ainda está aberto — nada a fazer.
                return;
            }

            // Transição open→closed detectada.
            $count = $this->queueService->rescheduleAfterCircuitBreakerClose(
                'djen',
                self::FAILURE_CATEGORY_ADAPTER_UNAVAILABLE,
            );

            TogareLogger::event(
                'info',
                'djen.queue.rescheduled_after_cb_close',
                "Reagendados {$count} items DJEN após fechamento do circuit breaker",
                [
                    'queueName' => 'djen',
                    'failureCategory' => self::FAILURE_CATEGORY_ADAPTER_UNAVAILABLE,
                    'count' => $count,
                    'openedAt' => (int) ($state['opened_at'] ?? 0),
                    'openUntil' => $openUntil,
                ],
            );

            // Limpa flags de CB aberto. Adapter pode não expor o método (stub).
            if (\method_exists($this->adapter, 'clearCircuitBreakerOpenFlag')) {
                $this->adapter->clearCircuitBreakerOpenFlag();
            }
        } catch (Throwable $e) {
            TogareLogger::event(
                'error',
                'djen.worker.tick_check_error',
                'Tick check do CB falhou — worker continua: ' . $e->getMessage(),
                ['exception' => \get_class($e)],
            );
        }
    }

    /**
     * Diferencia Forbidden por license_expired vs outros (ACL by-assignment etc.).
     */
    private function classifyForbidden(Forbidden $e): string
    {
        $msg = \strtolower($e->getMessage());
        if (
            \str_contains($msg, 'license')
            || \str_contains($msg, 'read-only')
            || \str_contains($msg, 'read_only')
            || \str_contains($msg, 'expirad')
        ) {
            return 'license_expired';
        }
        return 'forbidden';
    }
}
