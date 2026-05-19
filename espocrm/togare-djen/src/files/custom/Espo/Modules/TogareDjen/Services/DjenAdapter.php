<?php

declare(strict_types=1);

namespace Espo\Modules\TogareDjen\Services;

use DateTimeImmutable;
use Espo\Modules\TogareCore\Services\RateLimiter;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Modules\TogareDjen\Contracts\PublicationSourceAdapterContract;
use Espo\Modules\TogareDjen\Exception\DjenAdapterUnavailableException;
use Generator;
use Throwable;

/**
 * Adapter HTTP que consome a Comunica API PJe (`comunicaapi.pje.jus.br/api/v1`)
 * para obter publicações DJEN destinadas a um advogado em uma janela de datas.
 *
 * Resiliência (Decisão #5 da Story 4a.1):
 *   - Timeout HTTP: 30s por request.
 *   - Retry: 3 tentativas com backoff exponencial (1s, 4s, 16s).
 *   - Circuit breaker: 5 falhas consecutivas em 5min → abre por 10min
 *     (literal AC2 da story; difere do PdpjAdapter que abre 5min).
 *
 * Paginação automática (default itensPorPagina=100). Itera enquanto
 * `count` da resposta exceder o cursor local. Yields cada publicação
 * normalizada como DTO (memória constante para advogados com 100+ pubs/dia).
 *
 * HTTP via cURL nativo (mesmo padrão do PdpjAdapter — abstração HTTP do
 * EspoCRM é instável entre versões; cURL puro é estável e mocável via DI
 * substituindo o adapter inteiro nos testes).
 *
 * Suporta `file://` no `TOGARE_DJEN_BASE_URL` para fixtures locais (smoke
 * sem rede + suite PHPUnit standalone).
 *
 * Schema do DTO normalizado validado contra resposta real da Comunica API
 * (curl 2026-05-03, OAB 462034/SP — fixture em
 * tests/fixtures/comunica-api-462034-SP-202604.json).
 */
final class DjenAdapter implements PublicationSourceAdapterContract
{
    private const TIMEOUT_SECONDS = 30;
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = [1, 4, 16];
    private const CIRCUIT_BREAKER_THRESHOLD = 5;
    private const CIRCUIT_BREAKER_WINDOW_SECONDS = 300;
    /** AC2 da Story 4a.1: "pausa por 10 min" (vs PdpjAdapter 5min). */
    private const CIRCUIT_BREAKER_OPEN_DURATION_SECONDS = 600;
    private const ITENS_POR_PAGINA = 100;
    private const PAGINA_INICIAL = 1;
    /** Cap defensivo para evitar loop infinito em respostas mal-formadas. */
    private const MAX_PAGINAS = 100;

    private readonly string $baseUrl;
    private readonly string $stateFilePath;

    /** @var callable(string, array<string,mixed>): array{status:int, body:string} */
    private $httpExecutor;

    private readonly ?RateLimiter $rateLimiter;

    /** @var callable(): float Devolve "agora" em segundos float (mockável em testes). */
    private $clock;

    /** @var callable(int): void Executa sleep em N segundos (mockável em testes). */
    private $sleeper;

    /**
     * @param callable|null  $httpExecutor função custom para testes; default usa cURL.
     * @param ?RateLimiter   $rateLimiter  Story 4a.6 — gate ≤30 req/min Comunica API.
     *                                     Default null = sem gate (retrocompat dos testes da 4a.1).
     *                                     Em produção, Binding.php injeta via bindCallback
     *                                     (RateLimiter recebe EntityManager).
     * @param callable|null  $clock        Override do "tempo agora" em testes (`fn(): float`).
     * @param callable|null  $sleeper      Override do `sleep()` em testes (`fn(int $s): void`).
     */
    public function __construct(
        ?string $baseUrl = null,
        ?string $stateFilePath = null,
        ?callable $httpExecutor = null,
        ?RateLimiter $rateLimiter = null,
        ?callable $clock = null,
        ?callable $sleeper = null,
    ) {
        $this->baseUrl = \rtrim(
            $baseUrl ?? (string) (\getenv('TOGARE_DJEN_BASE_URL') ?: 'https://comunicaapi.pje.jus.br/api/v1'),
            '/',
        );
        // Story 4b.4 / Decisão #10: env var TOGARE_DJEN_CB_STATE_PATH permite
        // que o state-file viva em volume Docker compartilhado entre o worker
        // e o container espocrm (snapshot endpoint precisa ler o mesmo file
        // que o worker escreve; /tmp não é compartilhado entre containers).
        // Backward-compat: default antigo (`sys_get_temp_dir()`) é fallback
        // quando construtor recebe null E env var não está setado.
        $envStatePath = \getenv('TOGARE_DJEN_CB_STATE_PATH');
        $this->stateFilePath = $stateFilePath
            ?? (\is_string($envStatePath) && $envStatePath !== ''
                ? $envStatePath
                : \sys_get_temp_dir() . '/togare-djen-circuit-breaker.json');
        $this->httpExecutor = $httpExecutor ?? $this->defaultHttpExecutor();
        $this->rateLimiter = $rateLimiter;
        $this->clock = $clock ?? static fn (): float => \microtime(true);
        $this->sleeper = $sleeper ?? static function (int $seconds): void {
            if ($seconds > 0 && \getenv('TOGARE_DJEN_DISABLE_BACKOFF') !== '1') {
                \sleep($seconds);
            }
        };
    }

    /**
     * @return Generator<int, array{
     *     id:int, numeroProcesso:string, numeroProcessoComMascara:string,
     *     siglaTribunal:string, nomeOrgao:string, idOrgao:int,
     *     tipoComunicacao:string, tipoDocumento:string,
     *     dataDisponibilizacao:string, texto:string, link:string,
     *     meio:string, meioCompleto:string, codigoClasse:string,
     *     nomeClasse:string, numeroComunicacao:int, hash:string,
     *     status:string, ativo:bool,
     *     motivoCancelamento:?string, dataCancelamento:?string,
     *     destinatarios:list<array{nome:string,polo:string}>,
     *     destinatarioAdvogados:list<array{advogadoId:int,nome:string,numeroOab:string,ufOab:string}>
     * }>
     */
    public function fetchPublicacoes(
        string $oab,
        string $uf,
        DateTimeImmutable $dataInicio,
        DateTimeImmutable $dataFim,
    ): iterable {
        $this->guardCircuitBreaker();

        $oabSanitized = \preg_replace('/\D/', '', $oab) ?? '';
        $ufSanitized = \strtoupper(\trim($uf));
        if ($oabSanitized === '' || $ufSanitized === '') {
            $maskedOab = $oabSanitized !== '' ? \str_repeat('*', \strlen($oabSanitized)) : '(vazio)';
            throw new DjenAdapterUnavailableException(
                "OAB e UF são obrigatórios para fetchPublicacoes (recebido oab={$maskedOab}, uf={$uf})",
            );
        }

        $dataInicioStr = $dataInicio->format('Y-m-d');
        $dataFimStr = $dataFim->format('Y-m-d');

        $pagina = self::PAGINA_INICIAL;
        $consumed = 0;
        $totalReportado = null;

        while ($pagina <= self::MAX_PAGINAS) {
            $url = $this->buildUrl($oabSanitized, $ufSanitized, $dataInicioStr, $dataFimStr, $pagina);
            $body = $this->fetchWithRetry($url);

            $decoded = $this->decodeResponse($body, $url);
            $items = $decoded['items'];
            $totalReportado ??= $decoded['count'];

            if ($items === []) {
                break;
            }

            foreach ($items as $row) {
                yield $this->normalizeRow($row);
                $consumed++;
            }

            // Critério de fim de paginação:
            //   - Se já consumimos tudo que a API reportou em `count`, paramos.
            //   - Se a página voltou com menos de ITENS_POR_PAGINA itens, paramos
            //     (margem de segurança caso `count` esteja inconsistente).
            if ($totalReportado !== null && $consumed >= $totalReportado) {
                break;
            }
            if (\count($items) < self::ITENS_POR_PAGINA) {
                break;
            }

            $pagina++;
        }

        TogareLogger::event(
            'info',
            'djen.adapter.fetch.completed',
            "Fetch DJEN concluído oab={$oabSanitized}/{$ufSanitized}",
            [
                'oab' => $oabSanitized,
                'uf' => $ufSanitized,
                'dataInicio' => $dataInicioStr,
                'dataFim' => $dataFimStr,
                'consumed' => $consumed,
                'totalReportado' => $totalReportado,
                'paginasLidas' => $pagina,
            ],
        );
    }

    private function buildUrl(
        string $oab,
        string $uf,
        string $dataInicio,
        string $dataFim,
        int $pagina,
    ): string {
        // Permite fixture file:// para suite PHPUnit (single page, ignora query).
        if (\str_starts_with($this->baseUrl, 'file://')) {
            return $this->baseUrl;
        }

        $params = \http_build_query([
            'numeroOab' => $oab,
            'siglaUf' => $uf,
            'dataDisponibilizacaoInicio' => $dataInicio,
            'dataDisponibilizacaoFim' => $dataFim,
            'itensPorPagina' => self::ITENS_POR_PAGINA,
            'pagina' => $pagina,
        ]);

        return $this->baseUrl . '/comunicacao?' . $params;
    }

    private function fetchWithRetry(string $url): string
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $failed = false;

            // Story 4a.6 — cada tentativa HTTP consome um slot do rate-limit.
            // Se estourar o cap de espera, a exceção sobe sem contaminar o
            // circuit breaker da Comunica API.
            $this->guardRateLimit();

            try {
                $result = ($this->httpExecutor)($url, [
                    'timeout' => self::TIMEOUT_SECONDS,
                ]);
                $status = (int) ($result['status'] ?? 0);
                $body = (string) ($result['body'] ?? '');

                if ($status >= 200 && $status < 300) {
                    TogareLogger::event(
                        'info',
                        'djen.adapter.attempt.success',
                        "DJEN adapter sucesso em {$url}",
                        ['url' => $this->maskOabInUrl($url), 'attempt' => $attempt, 'status' => $status],
                    );
                    $this->resetCircuitBreakerOnSuccess();
                    return $body;
                }

                $failed = true;
                $lastError = "HTTP status {$status}";
                TogareLogger::event(
                    'warning',
                    'djen.adapter.attempt.failed',
                    "DJEN adapter falhou em {$url}: {$lastError}",
                    ['url' => $this->maskOabInUrl($url), 'attempt' => $attempt, 'status' => $status],
                );
            } catch (Throwable $e) {
                $failed = true;
                $lastError = $e->getMessage();
                TogareLogger::event(
                    'warning',
                    'djen.adapter.attempt.failed',
                    "DJEN adapter exception em {$url}: {$lastError}",
                    ['url' => $this->maskOabInUrl($url), 'attempt' => $attempt, 'exception' => \get_class($e)],
                );
            }

            if ($failed) {
                // Cada tentativa falhada conta para o circuit breaker.
                // (5 falhas em 5min — Decisão #5 — abre CB mesmo dentro de 1
                // só fetch se a fonte estiver totalmente fora do ar.)
                $this->recordFailure();
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                $sleep = self::BACKOFF_SECONDS[$attempt - 1] ?? 16;
                if ($sleep > 0 && \getenv('TOGARE_DJEN_DISABLE_BACKOFF') !== '1') {
                    \sleep($sleep);
                }
            }
        }

        throw new DjenAdapterUnavailableException(
            "Falha ao buscar DJEN em {$url} após " . self::MAX_ATTEMPTS . " tentativas: {$lastError}",
        );
    }

    /**
     * Story 4a.6 — gate de rate-limit (≤30 requests / 60s contra Comunica API).
     *
     * Comportamento:
     *  - peek() permitido → check() incrementa o contador e retorna.
     *  - peek() negado → sleep(1) em loop até janela liberar OU cap de 90s.
     *  - cap excedido → log error + lança DjenAdapterUnavailableException
     *    (DjenWorkerService captura e faz markFailed customDelay=1h).
     *  - RateLimiter null → skip (retrocompat dos testes da 4a.1).
     *  - TOGARE_DJEN_DISABLE_RATELIMIT=1 → skip (testes apenas).
     *  - RateLimiter lança Throwable (DB down) → fail-open: log warning + skip
     *    (princípio CLAUDE.md "infra de telemetria não trava bot"). NÃO conta
     *    como circuit breaker failure (rate-limit DB down ≠ Comunica API down).
     *
     * Decisão #1 da story: gate vive aqui (NÃO no httpExecutor), imediatamente
     * antes de cada tentativa HTTP. Retries internos também consomem cota,
     * porque cada retry é uma nova chamada real contra a Comunica API.
     */
    private function guardRateLimit(): void
    {
        if ($this->rateLimiter === null || \getenv('TOGARE_DJEN_DISABLE_RATELIMIT') === '1') {
            return;
        }

        $clock = $this->clock;
        $deadline = $clock() + DjenRateLimitConfig::CAP_SECONDS;
        $sleptCycles = 0;

        while (true) {
            try {
                $allowed = $this->rateLimiter->peek(
                    DjenRateLimitConfig::RATE_KEY,
                    DjenRateLimitConfig::LIMIT,
                    DjenRateLimitConfig::WINDOW_SECONDS,
                );
            } catch (Throwable $e) {
                TogareLogger::event(
                    'warning',
                    'djen.adapter.ratelimit.unavailable',
                    'RateLimiter indisponível — sync DJEN prosseguirá sem gate (fail-open)',
                    [
                        'exception' => \get_class($e),
                        'message' => $e->getMessage(),
                    ],
                );
                return;
            }

            if ($allowed) {
                try {
                    $committed = $this->rateLimiter->check(
                        DjenRateLimitConfig::RATE_KEY,
                        DjenRateLimitConfig::LIMIT,
                        DjenRateLimitConfig::WINDOW_SECONDS,
                    );
                } catch (Throwable $e) {
                    TogareLogger::event(
                        'warning',
                        'djen.adapter.ratelimit.unavailable',
                        'RateLimiter::check() falhou — sync DJEN prosseguirá sem incrementar contador (fail-open)',
                        [
                            'exception' => \get_class($e),
                            'message' => $e->getMessage(),
                        ],
                    );
                    return;
                }

                if ($committed) {
                    if ($sleptCycles > 0) {
                        TogareLogger::event(
                            'info',
                            'djen.adapter.ratelimit.released',
                            "Rate limit DJEN liberou após {$sleptCycles}s de espera",
                            ['sleptCycles' => $sleptCycles],
                        );
                    }
                    return;
                }
                // Corrida entre peek() e check(): outro worker consumiu o
                // último slot. Tratar como throttle, não como fail-open.
            }

            if ($clock() >= $deadline) {
                TogareLogger::event(
                    'error',
                    'djen.adapter.ratelimit.exceeded',
                    'Rate limit DJEN excedeu cap de espera (' . DjenRateLimitConfig::CAP_SECONDS . 's) — adapter indisponível',
                    [
                        'rateKey' => DjenRateLimitConfig::RATE_KEY,
                        'cap' => DjenRateLimitConfig::CAP_SECONDS,
                        'sleptCycles' => $sleptCycles,
                    ],
                );
                throw new DjenAdapterUnavailableException(
                    'Rate limit Comunica API excedeu cap de espera (' . DjenRateLimitConfig::CAP_SECONDS . 's)',
                );
            }

            TogareLogger::event(
                'warning',
                'djen.adapter.ratelimit.throttled',
                'Rate limit DJEN saturado — aguardando janela liberar',
                [
                    'rateKey' => DjenRateLimitConfig::RATE_KEY,
                    'sleptCycles' => $sleptCycles,
                ],
            );

            ($this->sleeper)(1);
            $sleptCycles++;
        }
    }

    /**
     * @return array{items:list<array<string,mixed>>, count:?int}
     */
    private function decodeResponse(string $body, string $url): array
    {
        if ($body === '') {
            // 204 No Content é interpretado como "sem publicações" — não conta como falha.
            return ['items' => [], 'count' => 0];
        }

        try {
            $decoded = \json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->recordFailure();
            throw new DjenAdapterUnavailableException(
                "Payload Comunica API malformado em {$this->maskOabInUrl($url)}: " . $e->getMessage(),
            );
        }

        if (! \is_array($decoded)) {
            $this->recordFailure();
            throw new DjenAdapterUnavailableException(
                "Payload Comunica API não é objeto JSON em {$this->maskOabInUrl($url)}",
            );
        }

        $items = $decoded['items'] ?? [];
        if (! \is_array($items)) {
            $this->recordFailure();
            throw new DjenAdapterUnavailableException(
                "Campo `items` da Comunica API não é lista em {$this->maskOabInUrl($url)}",
            );
        }

        $count = $decoded['count'] ?? null;
        $count = (\is_int($count) || (\is_string($count) && \ctype_digit($count))) ? (int) $count : null;

        // Items pode ser lista ou mapa associativo — normaliza para lista.
        $itemsList = \array_values($items);

        return ['items' => $itemsList, 'count' => $count];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{
     *     id:int, numeroProcesso:string, numeroProcessoComMascara:string,
     *     siglaTribunal:string, nomeOrgao:string, idOrgao:int,
     *     tipoComunicacao:string, tipoDocumento:string,
     *     dataDisponibilizacao:string, texto:string, link:string,
     *     meio:string, meioCompleto:string, codigoClasse:string,
     *     nomeClasse:string, numeroComunicacao:int, hash:string,
     *     status:string, ativo:bool,
     *     motivoCancelamento:?string, dataCancelamento:?string,
     *     destinatarios:list<array{nome:string,polo:string}>,
     *     destinatarioAdvogados:list<array{advogadoId:int,nome:string,numeroOab:string,ufOab:string}>
     * }
     *
     * @throws DjenAdapterUnavailableException se shape inesperada (id ausente etc.)
     */
    private function normalizeRow(array $row): array
    {
        $id = $row['id'] ?? null;
        if (! \is_int($id) && ! (\is_string($id) && \ctype_digit($id))) {
            throw new DjenAdapterUnavailableException(
                'Row Comunica API com campo `id` ausente ou inválido (' . \var_export($id, true) . ')',
            );
        }
        $idInt = (int) $id;

        return [
            'id' => $idInt,
            'numeroProcesso' => (string) ($row['numero_processo'] ?? ''),
            'numeroProcessoComMascara' => (string) ($row['numeroprocessocommascara'] ?? ''),
            'siglaTribunal' => (string) ($row['siglaTribunal'] ?? ''),
            'nomeOrgao' => (string) ($row['nomeOrgao'] ?? ''),
            'idOrgao' => (int) ($row['idOrgao'] ?? 0),
            'tipoComunicacao' => (string) ($row['tipoComunicacao'] ?? ''),
            'tipoDocumento' => (string) ($row['tipoDocumento'] ?? ''),
            'dataDisponibilizacao' => (string) ($row['data_disponibilizacao'] ?? ''),
            'texto' => (string) ($row['texto'] ?? ''),
            'link' => (string) ($row['link'] ?? ''),
            'meio' => (string) ($row['meio'] ?? ''),
            'meioCompleto' => (string) ($row['meiocompleto'] ?? ''),
            'codigoClasse' => (string) ($row['codigoClasse'] ?? ''),
            'nomeClasse' => (string) ($row['nomeClasse'] ?? ''),
            'numeroComunicacao' => (int) ($row['numeroComunicacao'] ?? 0),
            'hash' => (string) ($row['hash'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'ativo' => (bool) ($row['ativo'] ?? true),
            'motivoCancelamento' => $this->nullableString($row['motivo_cancelamento'] ?? null),
            'dataCancelamento' => $this->nullableString($row['data_cancelamento'] ?? null),
            'destinatarios' => $this->normalizeDestinatarios($row['destinatarios'] ?? []),
            'destinatarioAdvogados' => $this->normalizeAdvogados($row['destinatarioadvogados'] ?? []),
        ];
    }

    /**
     * @param mixed $list
     * @return list<array{nome:string,polo:string}>
     */
    private function normalizeDestinatarios(mixed $list): array
    {
        if (! \is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $row) {
            if (! \is_array($row)) {
                continue;
            }
            $out[] = [
                'nome' => (string) ($row['nome'] ?? ''),
                'polo' => (string) ($row['polo'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param mixed $list
     * @return list<array{advogadoId:int,nome:string,numeroOab:string,ufOab:string}>
     */
    private function normalizeAdvogados(mixed $list): array
    {
        if (! \is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $row) {
            if (! \is_array($row)) {
                continue;
            }
            // Comunica API nesta lista wrappa advogado num sub-objeto:
            // { advogado_id, advogado: { id, nome, numero_oab, uf_oab } }
            $inner = $row['advogado'] ?? null;
            if (! \is_array($inner)) {
                $inner = $row;
            }
            $out[] = [
                'advogadoId' => (int) ($inner['id'] ?? $row['advogado_id'] ?? 0),
                'nome' => (string) ($inner['nome'] ?? ''),
                'numeroOab' => (string) ($inner['numero_oab'] ?? ''),
                'ufOab' => (string) ($inner['uf_oab'] ?? ''),
            ];
        }
        return $out;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    private function maskOabInUrl(string $url): string
    {
        // Evita logar OAB completo (LGPD-friendly logging — OAB é dado pessoal).
        return (string) \preg_replace('/numeroOab=\d+/', 'numeroOab=***', $url);
    }

    private function guardCircuitBreaker(): void
    {
        $state = $this->loadState();
        if (($state['open_until'] ?? 0) > \time()) {
            throw new DjenAdapterUnavailableException(
                'Circuit breaker do DjenAdapter está aberto até ' . \date('c', (int) $state['open_until']),
            );
        }
    }

    private function recordFailure(): void
    {
        $state = $this->loadState();
        $now = \time();
        $windowStart = $now - self::CIRCUIT_BREAKER_WINDOW_SECONDS;

        $failures = \array_values(\array_filter(
            (array) ($state['failures'] ?? []),
            static fn ($t) => \is_int($t) && $t >= $windowStart,
        ));
        $failures[] = $now;
        $state['failures'] = $failures;

        if (\count($failures) >= self::CIRCUIT_BREAKER_THRESHOLD) {
            $state['open_until'] = $now + self::CIRCUIT_BREAKER_OPEN_DURATION_SECONDS;
            // Story 4b.4: registra timestamp em que o CB foi aberto (input
            // técnico para diagnóstico do cooldown do circuit breaker).
            $state['opened_at'] = $now;
            TogareLogger::event(
                'error',
                'djen.adapter.circuit_breaker.opened',
                'Circuit breaker do DjenAdapter aberto após '
                    . self::CIRCUIT_BREAKER_THRESHOLD . ' falhas em '
                    . self::CIRCUIT_BREAKER_WINDOW_SECONDS . 's',
                [
                    'open_until' => \date('c', (int) $state['open_until']),
                    'opened_at' => \date('c', (int) $state['opened_at']),
                ],
            );
        }

        if ((int) ($state['unavailable_since'] ?? 0) <= 0) {
            // Relógio operacional: primeira falha contínua da Comunica API.
            // Diferente de open_until/opened_at, este valor NÃO é limpo quando
            // o cooldown técnico do CB fecha; só sucesso real reseta.
            $state['unavailable_since'] = $now;
        }

        $this->saveState($state);
    }

    private function resetCircuitBreakerOnSuccess(): void
    {
        $state = $this->loadState();
        if (($state['open_until'] ?? 0) > 0 || ($state['failures'] ?? []) !== []) {
            TogareLogger::event(
                'info',
                'djen.adapter.circuit_breaker.half_open',
                'Circuit breaker do DjenAdapter retornou ao normal após sucesso',
                [],
            );
        }
        $state['failures'] = [];
        $state['open_until'] = 0;
        $state['opened_at'] = 0;
        $state['unavailable_since'] = 0;
        $this->saveState($state);
    }

    // ============================================================
    // Story 4b.4 / ADR 0009 — API pública do circuit breaker
    // ============================================================

    /**
     * Story 4b.4: lê estado atual do CB para consumidores externos
     * (DjenWorkerService tick check + TogareDjenStatusController snapshot).
     *
     * @return array{failures: list<int>, open_until: int, opened_at: int, unavailable_since: int}
     */
    public function getCircuitBreakerState(): array
    {
        return $this->loadState();
    }

    /**
     * Story 4b.4: zera apenas a flag `open_until` (e `opened_at`) preservando
     * o histórico de `failures[]` e o relógio operacional `unavailable_since`.
     *
     * Chamado pelo `DjenWorkerService::processOne` quando o tick check detecta
     * transição open→closed do CB e dispara
     * `QueueService::rescheduleAfterCircuitBreakerClose`. Após essa limpeza,
     * o cooldown técnico some do snapshot, mas o banner operacional pode
     * continuar visível enquanto `unavailable_since` não for resetado por
     * sucesso real.
     */
    public function clearCircuitBreakerOpenFlag(): void
    {
        $state = $this->loadState();
        $state['open_until'] = 0;
        $state['opened_at'] = 0;
        $this->saveState($state);
    }

    /**
     * @return array{failures:list<int>, open_until:int, opened_at:int, unavailable_since:int}
     */
    private function loadState(): array
    {
        $empty = ['failures' => [], 'open_until' => 0, 'opened_at' => 0, 'unavailable_since' => 0];
        if (! \is_file($this->stateFilePath)) {
            return $empty;
        }
        $fh = @\fopen($this->stateFilePath, 'r');
        if ($fh === false) {
            return $empty;
        }
        try {
            \flock($fh, LOCK_SH);
            $raw = \stream_get_contents($fh);
        } finally {
            \flock($fh, LOCK_UN);
            \fclose($fh);
        }
        if ($raw === false || $raw === '') {
            return $empty;
        }
        try {
            $decoded = \json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            TogareLogger::event(
                'warning',
                'djen.circuit_breaker.state_corrupt',
                'Estado do circuit breaker corrompido — resetando para empty',
                ['path' => $this->stateFilePath],
            );
            return $empty;
        }
        return [
            'failures' => \array_values(\array_map('\intval', (array) ($decoded['failures'] ?? []))),
            'open_until' => (int) ($decoded['open_until'] ?? 0),
            // Story 4b.4: campos novos. Backward-compat: state-files antigos
            // (sem opened_at/unavailable_since) leem 0 por default.
            'opened_at' => (int) ($decoded['opened_at'] ?? 0),
            'unavailable_since' => (int) ($decoded['unavailable_since'] ?? 0),
        ];
    }

    /**
     * @param array{failures:list<int>, open_until:int, opened_at:int, unavailable_since?:int} $state
     */
    private function saveState(array $state): void
    {
        $dir = \dirname($this->stateFilePath);
        if (! \is_dir($dir)) {
            @\mkdir($dir, 0755, true);
        }
        $fh = @\fopen($this->stateFilePath, 'c+');
        if ($fh === false) {
            TogareLogger::event(
                'warning',
                'djen.circuit_breaker.state_save_failed',
                'Falha ao abrir arquivo de estado do circuit breaker para escrita',
                ['path' => $this->stateFilePath],
            );
            return;
        }
        try {
            \flock($fh, LOCK_EX);
            \ftruncate($fh, 0);
            \fwrite($fh, \json_encode($state, JSON_THROW_ON_ERROR));
        } finally {
            \flock($fh, LOCK_UN);
            \fclose($fh);
        }
    }

    /**
     * @return callable(string, array<string,mixed>): array{status:int, body:string}
     */
    private function defaultHttpExecutor(): callable
    {
        return static function (string $url, array $opts): array {
            // Suporta file:// e http(s)://.
            if (\str_starts_with($url, 'file://') || ! \str_contains($url, '://')) {
                $body = @\file_get_contents($url);
                if ($body === false) {
                    throw new \RuntimeException("Falha ao ler arquivo DJEN: {$url}");
                }
                return ['status' => 200, 'body' => $body];
            }

            $ch = \curl_init($url);
            if ($ch === false) {
                throw new \RuntimeException('curl_init retornou false');
            }
            \curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => (int) ($opts['timeout'] ?? 30),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: Togare-DJEN/0.1.0',
                ],
            ]);
            $body = \curl_exec($ch);
            $status = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = \curl_error($ch);
            \curl_close($ch);
            if ($body === false) {
                throw new \RuntimeException("cURL erro em {$url}: {$err}");
            }
            return ['status' => (int) $status, 'body' => (string) $body];
        };
    }
}
