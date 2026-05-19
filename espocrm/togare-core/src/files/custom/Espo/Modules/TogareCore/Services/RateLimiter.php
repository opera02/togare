<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use DateTimeImmutable;
use Espo\ORM\EntityManager;
use PDO;

/**
 * RateLimiter — sliding window por chave, persistido em togare_rate_limits.
 *
 * Uso:
 *   if (!$rateLimiter->check('djen:api', limit: 30, windowSeconds: 60)) {
 *       // requisição negada — retornar 429 ou enfileirar pra depois
 *   }
 *
 * Impl. simples: 1 linha por chave, contador + janela. Quando a janela
 * expira, reseta. Granularidade do sliding é "step" — não é sliding-window
 * perfeito (o qual exigiria múltiplas entradas por chave). Suficiente para
 * rate limits baixos do MVP (DJEN 30/min, auth 5/15min).
 *
 * DI: recebe EntityManager pelo InjectableFactory do EspoCRM e cacheia o PDO
 * no boot (mesmo padrão de QueueService — InjectableFactory não resolve `PDO`
 * direto porque não consegue injetar a string `$dsn`).
 */
final class RateLimiter
{
    private readonly PDO $pdo;

    public function __construct(EntityManager $entityManager)
    {
        $this->pdo = $entityManager->getPDO();
    }

    /**
     * Retorna true se a chamada é permitida (e incrementa o contador).
     * Retorna false se a janela atual já atingiu $limit chamadas.
     */
    public function check(string $key, int $limit, int $windowSeconds): bool
    {
        $now = new DateTimeImmutable();
        $nowStr = $now->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $supportsForUpdate = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            $suffix = $supportsForUpdate ? 'FOR UPDATE' : '';

            $stmt = $this->pdo->prepare("
                SELECT counter, window_started_at
                FROM togare_rate_limits
                WHERE rate_key = :k
                {$suffix}
            ");
            $stmt->execute([':k' => $key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                // Primeira chamada com essa chave — cria linha, retorna true.
                $ins = $this->pdo->prepare('
                    INSERT INTO togare_rate_limits (rate_key, counter, window_started_at, updated_at)
                    VALUES (:k, 1, :now, :now2)
                ');
                try {
                    $ins->execute([
                        ':k' => $key,
                        ':now' => $nowStr,
                        ':now2' => $nowStr,
                    ]);
                } catch (\PDOException $e) {
                    // Race: outro worker inseriu entre nosso SELECT e INSERT.
                    // Reexecuta leitura e segue fluxo normal.
                    if ($this->isDuplicateKey($e)) {
                        $this->pdo->rollBack();
                        return $this->check($key, $limit, $windowSeconds);
                    }
                    throw $e;
                }
                $this->pdo->commit();
                return true;
            }

            $windowStart = new DateTimeImmutable((string) $row['window_started_at']);
            $elapsedSeconds = $now->getTimestamp() - $windowStart->getTimestamp();

            if ($elapsedSeconds >= $windowSeconds) {
                // Janela expirou — reseta contador.
                $upd = $this->pdo->prepare('
                    UPDATE togare_rate_limits
                    SET counter = 1, window_started_at = :now, updated_at = :now2
                    WHERE rate_key = :k
                ');
                $upd->execute([
                    ':k' => $key,
                    ':now' => $nowStr,
                    ':now2' => $nowStr,
                ]);
                $this->pdo->commit();
                return true;
            }

            $currentCount = (int) $row['counter'];
            if ($currentCount >= $limit) {
                // Dentro da janela, acima do limite — negado.
                $this->pdo->commit();
                return false;
            }

            // Dentro da janela, abaixo do limite — incrementa.
            $upd = $this->pdo->prepare('
                UPDATE togare_rate_limits
                SET counter = counter + 1, updated_at = :now
                WHERE rate_key = :k
            ');
            $upd->execute([
                ':k' => $key,
                ':now' => $nowStr,
            ]);
            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Consulta sem incrementar — retorna true se ainda há orçamento na janela.
     *
     * Não cria linha se a chave não existe. Não muda estado. Par com check():
     * BeforeLogin usa peek() para decidir; OnFail usa check() para commitar a falha.
     */
    public function peek(string $key, int $limit, int $windowSeconds): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT counter, window_started_at FROM togare_rate_limits WHERE rate_key = :k'
        );
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return true;
        }

        $windowStart = new DateTimeImmutable((string) $row['window_started_at']);
        $elapsed = (new DateTimeImmutable())->getTimestamp() - $windowStart->getTimestamp();

        if ($elapsed >= $windowSeconds) {
            return true;
        }

        return (int) $row['counter'] < $limit;
    }

    /**
     * Remove o contador para uma chave — útil para admin/testes.
     */
    public function reset(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM togare_rate_limits WHERE rate_key = :k');
        $stmt->execute([':k' => $key]);
    }

    private function isDuplicateKey(\PDOException $e): bool
    {
        $sqlstate = $e->getCode();
        $msg = \strtolower($e->getMessage());
        if ($sqlstate === '23000') {
            return true;
        }
        return \str_contains($msg, 'duplicate') || \str_contains($msg, 'unique constraint');
    }
}
