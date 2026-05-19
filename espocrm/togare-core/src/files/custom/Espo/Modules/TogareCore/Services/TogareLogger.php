<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use Espo\Core\Container;
use Espo\Entities\User;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logger estruturado estático — único canal de log permitido em código Togare.
 *
 * Emite JSON por evento com 9 campos: timestamp, service, level, event,
 * correlationId, userId, message, context (+ trace em error/critical).
 *
 * Saída: stdout para debug/info, stderr para warning/error/critical. Se o
 * container EspoCRM estiver disponível, também propaga via PSR-3 para o
 * logger Monolog do EspoCRM (data/logs/espo.log).
 *
 * Uso típico:
 *
 *   TogareLogger::init('togare-core', $container);
 *   TogareLogger::event('info', 'djen.sync.completed',
 *       'DJEN sync completou 47 publicações em 4m21s',
 *       ['advCount' => 12, 'pubCount' => 47]);
 *
 * Singleton estático por design (ver spec Story 1a.4b): evita ritual de DI
 * em 40+ classes que só precisam logar. Testes isolam state via
 * #[RunInSeparateProcess].
 */
final class TogareLogger
{
    public const LEVELS = ['debug', 'info', 'warning', 'error', 'critical'];

    private static ?self $instance = null;

    /** @var resource|null */
    private $stdoutStream;

    /** @var resource|null */
    private $stderrStream;

    private ?string $cachedCorrelationId = null;

    /**
     * @param resource|null $stdoutStream stream aberto em modo escrita; default php://stdout
     * @param resource|null $stderrStream stream aberto em modo escrita; default php://stderr
     */
    public function __construct(
        private readonly string $service = 'unknown',
        private readonly ?Container $container = null,
        $stdoutStream = null,
        $stderrStream = null,
    ) {
        $this->stdoutStream = $stdoutStream;
        $this->stderrStream = $stderrStream;
    }

    /**
     * Inicializa o singleton. Pode ser chamado múltiplas vezes (substitui a
     * instância anterior — útil em contextos de teste).
     *
     * @param resource|null $stdoutStream opcional; default php://stdout em runtime real
     * @param resource|null $stderrStream opcional; default php://stderr em runtime real
     */
    public static function init(
        string $service,
        ?Container $container = null,
        $stdoutStream = null,
        $stderrStream = null,
    ): void {
        self::$instance = new self($service, $container, $stdoutStream, $stderrStream);
    }

    /**
     * Reset completo do singleton — usado por testes entre casos.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self('unknown');
        }
        return self::$instance;
    }

    /**
     * Ponto de entrada único para emissão de log.
     *
     * @param array<string, mixed> $context
     */
    public static function event(
        string $level,
        string $event,
        string $message,
        array $context = [],
    ): void {
        self::getInstance()->emit($level, $event, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emit(string $level, string $event, string $message, array $context): void
    {
        if (! \in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException(\sprintf(
                "Level de log inválido: '%s'. Aceitos: %s.",
                $level,
                \implode(', ', self::LEVELS),
            ));
        }

        $record = [
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            'service' => $this->service,
            'level' => $level,
            'event' => $event,
            'correlationId' => $this->resolveCorrelationId(),
            'userId' => $this->resolveUserId(),
            'message' => $message,
            'context' => $context,
        ];

        if ($level === 'error' || $level === 'critical') {
            $record['trace'] = $this->captureTrace();
        }

        $line = \json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        $stream = \in_array($level, ['warning', 'error', 'critical'], true)
            ? ($this->stderrStream ?? $this->openDefault('php://stderr', 'stderrStream'))
            : ($this->stdoutStream ?? $this->openDefault('php://stdout', 'stdoutStream'));

        \fwrite($stream, $line);

        $this->propagateToPsr($level, $message, $record);
    }

    private function resolveCorrelationId(): ?string
    {
        if ($this->cachedCorrelationId !== null) {
            return $this->cachedCorrelationId;
        }

        $headerValue = $_SERVER['HTTP_X_TOGARE_CORRELATION_ID'] ?? null;
        if (\is_string($headerValue) && $headerValue !== '') {
            $this->cachedCorrelationId = $headerValue;
            return $this->cachedCorrelationId;
        }

        $this->cachedCorrelationId = $this->generateUuidV4();
        return $this->cachedCorrelationId;
    }

    private function resolveUserId(): ?string
    {
        if ($this->container === null) {
            return null;
        }
        try {
            $user = $this->container->getByClass(User::class);
            return $user?->getId();
        } catch (Throwable) {
            return null;
        }
    }

    private function generateUuidV4(): string
    {
        $data = \random_bytes(16);
        $data[6] = \chr(\ord($data[6]) & 0x0f | 0x40);
        $data[8] = \chr(\ord($data[8]) & 0x3f | 0x80);
        return \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex($data), 4));
    }

    /**
     * @return list<array{file: string, line: int, class: string, function: string}>
     */
    private function captureTrace(): array
    {
        $frames = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        // Descartar frames internos do logger (emit + event).
        $frames = \array_slice($frames, 2);

        $out = [];
        $max = 10;
        foreach ($frames as $frame) {
            if (\count($out) >= $max) {
                break;
            }
            $out[] = [
                'file' => (string) ($frame['file'] ?? ''),
                'line' => (int) ($frame['line'] ?? 0),
                'class' => (string) ($frame['class'] ?? ''),
                'function' => (string) ($frame['function'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Propaga para o logger PSR-3 do container (EspoCRM expõe Monolog via
     * `Espo\Core\Utils\Log`, que implementa PSR-3). Tentamos resolver por
     * interface PSR-3 primeiro; se o container não tiver esse binding,
     * tentamos a classe concreta do EspoCRM.
     *
     * @param array<string, mixed> $record
     */
    private function propagateToPsr(string $level, string $message, array $record): void
    {
        if ($this->container === null) {
            return;
        }

        // Tenta 3 formas de resolver o logger PSR-3 no container EspoCRM:
        //   1) por interface PSR-3 padrão
        //   2) pela classe concreta Espo\Core\Utils\Log (estende Monolog)
        //   3) pelo nome simbólico 'log' (convenção histórica do EspoCRM)
        $resolvers = [
            fn () => $this->container->getByClass(LoggerInterface::class),
            fn () => $this->container->getByClass('\\Espo\\Core\\Utils\\Log'),
            fn () => method_exists($this->container, 'get')
                ? $this->container->get('log')
                : null,
        ];

        foreach ($resolvers as $resolver) {
            try {
                $psr = $resolver();
                if ($psr instanceof LoggerInterface) {
                    $psr->{$level}($message, $record);
                    return;
                }
            } catch (Throwable) {
                // próxima candidata
            }
        }
        // Nenhum logger PSR-3 disponível — stdout/stderr já cobriu.
    }

    /**
     * Abre um stream default (php://stdout ou php://stderr) sob demanda e
     * cacheia.
     *
     * @return resource
     */
    private function openDefault(string $uri, string $property)
    {
        $handle = \fopen($uri, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Falha ao abrir stream {$uri}.");
        }
        $this->{$property} = $handle;
        return $handle;
    }
}
