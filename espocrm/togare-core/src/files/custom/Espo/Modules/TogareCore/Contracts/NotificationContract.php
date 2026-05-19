<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Contracts;

/**
 * Envio de notificação para usuário. Canal default é 'stream' (Stream nativo
 * do EspoCRM — NFR32). Implementações concretas em Epic 2 (stream) e Epic 3
 * (email SMTP para prazos/audiências críticas).
 *
 * Contrato: notify() é síncrono para canal 'stream' (write direto na tabela
 * Notification do EspoCRM); para canais externos ('email'), a implementação
 * deve enfileirar via QueueService (Story 1a.4c) em vez de enviar direto.
 */
interface NotificationContract
{
    public const CHANNEL_STREAM = 'stream';
    public const CHANNEL_EMAIL = 'email';

    /**
     * @param string $userId id do usuário no EspoCRM
     * @param string $subject linha única (usada como título/assunto). pt-BR.
     * @param string $body corpo completo, pode ser multi-linha. pt-BR.
     * @param 'stream'|'email' $channel default 'stream'
     */
    public function notify(
        string $userId,
        string $subject,
        string $body,
        string $channel = self::CHANNEL_STREAM,
    ): void;
}
