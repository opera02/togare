<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services\Notification;

use Espo\Core\Mail\EmailFactory;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Utils\Config;
use Espo\Modules\TogareCore\Contracts\NotificationContract;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Implementacao de canal `email` (SMTP via EspoCRM EmailSender). Story 4b.2,
 * AC4 + AC6 + ADR-04.
 *
 * API EspoCRM 9.x: cria Email via `EmailFactory->create()` e envia via
 * `EmailSender->send($email)`.
 */
final class EmailNotificationService implements NotificationContract
{
    private const TEMPLATE_HTML_PATH = __DIR__ . '/../../Resources/templates/prazo-reminder.tpl.html';
    private const TEMPLATE_TEXT_PATH = __DIR__ . '/../../Resources/templates/prazo-reminder.tpl.txt';
    /** Story 4b.3 — template D-0 com header destacado vermelho + título "VENCE HOJE". */
    private const TEMPLATE_HTML_D0_PATH = __DIR__ . '/../../Resources/templates/prazo-reminder-d0.tpl.html';
    private const TEMPLATE_TEXT_D0_PATH = __DIR__ . '/../../Resources/templates/prazo-reminder-d0.tpl.txt';

    public function __construct(
        private readonly EntityManager $entityManager,
        private readonly EmailSender $emailSender,
        private readonly EmailFactory $emailFactory,
        private readonly Config $config,
    ) {
    }

    public function notify(
        string $userId,
        string $subject,
        string $body,
        string $channel = NotificationContract::CHANNEL_EMAIL,
    ): void {
        if ($channel !== NotificationContract::CHANNEL_EMAIL) {
            throw new \InvalidArgumentException(
                "EmailNotificationService so aceita canal 'email', recebido: '{$channel}'"
            );
        }

        if ($userId === '') {
            throw new \InvalidArgumentException('EmailNotificationService.notify: userId vazio.');
        }

        try {
            $user = $this->entityManager->getEntityById('User', $userId);
            if ($user === null) {
                throw new \RuntimeException("User '{$userId}' nao existe.");
            }

            $emailAddress = $user->get('emailAddress');
            if (! \is_string($emailAddress) || $emailAddress === '') {
                throw new \RuntimeException("User '{$userId}' nao tem emailAddress configurado.");
            }

            // Body HTML ja vem renderizado pelo PrazoReminderJob. HTML e o
            // default no EspoCRM; nao chamamos setIsPlain().
            $email = $this->emailFactory->create();
            $email->setSubject($subject);
            $email->setBody($body);
            $email->addToAddress($emailAddress);

            $this->emailSender->send($email);
        } catch (Throwable $e) {
            TogareLogger::event(
                'warning',
                'notification.email.failed',
                'EmailNotificationService.notify falhou.',
                ['userId' => $userId, 'error' => $e->getMessage()],
            );
            throw new \RuntimeException(
                'EmailNotificationService falhou: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Renderiza template HTML do email.
     *
     * @param array{
     *     marcoLabel: string,
     *     marcoTitle: string,
     *     cnj: string,
     *     descricao: string,
     *     dataFatal: string,
     *     dataCumprimento: ?string,
     *     prazoUrl: string,
     *     hedgeJuridico: string,
     * } $vars
     */
    public static function renderHtml(array $vars): string
    {
        // Story 4b.3 (Decisão #7) — D-0 usa template com header destacado vermelho.
        $path = ($vars['marcoLabel'] ?? '') === PrazoLembreteConstants::MARCO_D0
            ? self::TEMPLATE_HTML_D0_PATH
            : self::TEMPLATE_HTML_PATH;
        $template = self::loadTemplate($path);

        $safe = [
            '{marcoLabel}' => \htmlspecialchars($vars['marcoLabel'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '{marcoTitle}' => \htmlspecialchars($vars['marcoTitle'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '{cnj}' => \htmlspecialchars($vars['cnj'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '{descricao}' => \htmlspecialchars($vars['descricao'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '{dataFatal}' => \htmlspecialchars($vars['dataFatal'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '{dataCumprimento}' => \htmlspecialchars($vars['dataCumprimento'] ?? '-', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '{prazoUrl}' => \htmlspecialchars($vars['prazoUrl'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            '{hedgeJuridico}' => \htmlspecialchars($vars['hedgeJuridico'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ];

        return \strtr($template, $safe);
    }

    /**
     * Mesmo `renderHtml` mas para template texto puro (sem escape HTML).
     *
     * @param array<string, mixed> $vars
     */
    public static function renderText(array $vars): string
    {
        // Story 4b.3 (Decisão #7) — D-0 usa template texto destacado.
        $path = ($vars['marcoLabel'] ?? '') === PrazoLembreteConstants::MARCO_D0
            ? self::TEMPLATE_TEXT_D0_PATH
            : self::TEMPLATE_TEXT_PATH;
        $template = self::loadTemplate($path);

        $plain = [
            '{marcoLabel}' => $vars['marcoLabel'],
            '{marcoTitle}' => $vars['marcoTitle'],
            '{cnj}' => $vars['cnj'],
            '{descricao}' => $vars['descricao'],
            '{dataFatal}' => $vars['dataFatal'],
            '{dataCumprimento}' => $vars['dataCumprimento'] ?? '-',
            '{prazoUrl}' => $vars['prazoUrl'],
            '{hedgeJuridico}' => $vars['hedgeJuridico'],
        ];

        return \strtr($template, $plain);
    }

    /**
     * Resolve URL completa do Prazo. Le `siteUrl` do Config; se nao setado,
     * cai em path relativo.
     */
    public function buildPrazoUrl(string $prazoId): string
    {
        $siteUrl = $this->config->get('siteUrl');
        if (! \is_string($siteUrl) || $siteUrl === '') {
            return "/#Prazo/view/{$prazoId}";
        }
        return \rtrim($siteUrl, '/') . "/#Prazo/view/{$prazoId}";
    }

    private static function loadTemplate(string $path): string
    {
        if (! \is_file($path)) {
            throw new \RuntimeException("Template nao encontrado: {$path}");
        }
        $content = \file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Nao foi possivel ler template: {$path}");
        }
        return $content;
    }
}
