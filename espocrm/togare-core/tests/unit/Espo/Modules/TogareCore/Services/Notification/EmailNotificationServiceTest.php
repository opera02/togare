<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services\Notification;

use Espo\Core\Mail\EmailFactory;
use Espo\Core\Mail\EmailSender;
use Espo\Core\Mail\Sender;
use Espo\Core\Utils\Config;
use Espo\Modules\TogareCore\Contracts\NotificationContract;
use Espo\Modules\TogareCore\Services\Notification\EmailNotificationService;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * Cobre EmailNotificationService (Story 4b.2, AC4 + AC6).
 */
final class EmailNotificationServiceTest extends TestCase
{
    public function testRenderHtmlEscapaXssNaDescricao(): void
    {
        $vars = $this->makeRenderVars([
            'descricao' => '<script>alert("xss")</script>',
        ]);

        $html = EmailNotificationService::renderHtml($vars);

        self::assertStringNotContainsString('<script>alert("xss")</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        // Hedge jurídico literal (FR39) presente.
        self::assertStringContainsString('responsabilidade final pelo cumprimento', $html);
    }

    public function testRenderHtmlSubstituiTodasAsVariaveis(): void
    {
        $vars = $this->makeRenderVars();
        $html = EmailNotificationService::renderHtml($vars);

        self::assertStringContainsString('Vence em 3 dias úteis', $html);
        self::assertStringContainsString('0001234-56.2024.8.26.0001', $html);
        self::assertStringContainsString('2026-06-01', $html);
        self::assertStringContainsString('https://togare.example.com/#Prazo/view/prazo-001', $html);
        // Sem placeholders sobrando.
        self::assertStringNotContainsString('{marcoLabel}', $html);
        self::assertStringNotContainsString('{cnj}', $html);
    }

    public function testRenderTextNaoCarregaTagsHtml(): void
    {
        $vars = $this->makeRenderVars();
        $text = EmailNotificationService::renderText($vars);

        self::assertStringNotContainsString('<', $text);
        self::assertStringNotContainsString('</', $text);
        self::assertStringContainsString('Vence em 3 dias úteis', $text);
        self::assertStringContainsString('0001234-56.2024.8.26.0001', $text);
        // Hedge jurídico literal preservado.
        self::assertStringContainsString('responsabilidade final', $text);
    }

    public function testBuildPrazoUrlComSiteUrlSetadoMontaUrlAbsoluta(): void
    {
        $em = $this->createMock(EntityManager::class);
        $sender = new EmailSender();
        $config = new Config();
        $config->set('siteUrl', 'https://togare.example.com');

        $service = new EmailNotificationService($em, $sender, new EmailFactory(), $config);
        self::assertSame('https://togare.example.com/#Prazo/view/prazo-001', $service->buildPrazoUrl('prazo-001'));
    }

    public function testBuildPrazoUrlSemSiteUrlCaiEmPathRelativo(): void
    {
        $em = $this->createMock(EntityManager::class);
        $sender = new EmailSender();
        $config = new Config();
        // siteUrl não setado.

        $service = new EmailNotificationService($em, $sender, new EmailFactory(), $config);
        self::assertSame('/#Prazo/view/prazo-001', $service->buildPrazoUrl('prazo-001'));
    }

    public function testNotifyComUserSemEmailLancaRuntimeException(): void
    {
        $user = new \Espo\Core\ORM\Entity();
        $user->setId('user-001');
        // sem emailAddress.

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('User', 'user-001')->willReturn($user);

        $sender = new EmailSender();
        $config = new Config();

        $service = new EmailNotificationService($em, $sender, new EmailFactory(), $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/nao tem emailAddress/');
        $service->notify('user-001', 's', 'b');
    }

    public function testNotifyUsaEmailFactoryEEmailSenderOficiais(): void
    {
        $user = new \Espo\Core\ORM\Entity();
        $user->setId('user-001');
        $user->set('emailAddress', 'user@example.com');

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('User', 'user-001')->willReturn($user);

        $sender = new EmailSender();
        $service = new EmailNotificationService($em, $sender, new EmailFactory(), new Config());

        $service->notify('user-001', 'Assunto', '<p>Corpo</p>');

        self::assertCount(1, $sender->sent);
        self::assertSame('Assunto', $sender->sent[0]->get('subject'));
        self::assertSame('<p>Corpo</p>', $sender->sent[0]->get('body'));
        self::assertSame('user@example.com', $sender->sent[0]->get('to'));
    }

    public function testNotifyComUserInexistenteLancaRuntimeException(): void
    {
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturn(null);

        $service = new EmailNotificationService($em, new EmailSender(), new EmailFactory(), new Config());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/User 'fantasma' nao existe/");
        $service->notify('fantasma', 's', 'b');
    }

    public function testNotifyComCanalErradoLancaInvalidArgument(): void
    {
        $em = $this->createMock(EntityManager::class);
        $service = new EmailNotificationService($em, new EmailSender(), new EmailFactory(), new Config());

        $this->expectException(\InvalidArgumentException::class);
        $service->notify('user-001', 's', 'b', NotificationContract::CHANNEL_STREAM);
    }

    // ====== Story 4b.3 — D-0 redundância semântica UX-DR10 ======

    /**
     * Story 4b.3 AC5 — template D-0 tem header destacado vermelho `#c62828`,
     * overline `🔔 Togare — VENCE HOJE` e <h1> "VENCE HOJE — {cnj}".
     */
    public function testRenderHtmlD0UsaHeaderVermelhoComOverlineSino(): void
    {
        $vars = $this->makeRenderVars([
            'marcoLabel' => 'D-0',
            'marcoTitle' => 'VENCE HOJE',
        ]);
        $html = EmailNotificationService::renderHtml($vars);

        // Header vermelho
        self::assertStringContainsString('#c62828', $html);
        // Overline Togare — VENCE HOJE
        self::assertStringContainsString('🔔 Togare — VENCE HOJE', $html);
        // Título h1 "VENCE HOJE — {cnj}"
        self::assertStringContainsString('VENCE HOJE — 0001234-56.2024.8.26.0001', $html);
        // Hedge jurídico FR39 LITERAL preservado.
        self::assertStringContainsString('responsabilidade final pelo cumprimento', $html);
        // Sem placeholders sobrando.
        self::assertStringNotContainsString('{marcoLabel}', $html);
        self::assertStringNotContainsString('{cnj}', $html);
        // **NÃO** deve usar header azul default.
        self::assertStringNotContainsString('Togare — Lembrete de prazo', $html);
        self::assertStringNotContainsString('#1c5fbf', $html);
    }

    /**
     * Story 4b.3 não-regressão — D-7 mantém header azul `#1c5fbf` + título "Prazo vence em 7 dias úteis".
     */
    public function testRenderHtmlD7MantemHeaderAzulNaoRegrediu(): void
    {
        $vars = $this->makeRenderVars([
            'marcoLabel' => 'D-7',
            'marcoTitle' => 'Prazo vence em 7 dias úteis',
        ]);
        $html = EmailNotificationService::renderHtml($vars);

        self::assertStringContainsString('Togare — Lembrete de prazo', $html);
        self::assertStringContainsString('#1c5fbf', $html);
        self::assertStringContainsString('Prazo vence em 7 dias úteis', $html);
        // **NÃO** deve usar header vermelho do D-0.
        self::assertStringNotContainsString('#c62828', $html);
        self::assertStringNotContainsString('🔔 Togare — VENCE HOJE', $html);
    }

    /**
     * Story 4b.3 AC5 — template texto fallback tem `🔔 TOGARE — VENCE HOJE` no topo.
     */
    public function testRenderTextD0HeaderDestacadoVenceHoje(): void
    {
        $vars = $this->makeRenderVars([
            'marcoLabel' => 'D-0',
            'marcoTitle' => 'VENCE HOJE',
        ]);
        $text = EmailNotificationService::renderText($vars);

        self::assertStringContainsString('🔔 TOGARE — VENCE HOJE', $text);
        self::assertStringContainsString('VENCE HOJE — 0001234-56.2024.8.26.0001', $text);
        self::assertStringNotContainsString('<', $text);
        self::assertStringNotContainsString('Togare — Lembrete de prazo', $text);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{marcoLabel: string, marcoTitle: string, cnj: string, descricao: string, dataFatal: string, dataCumprimento: ?string, prazoUrl: string, hedgeJuridico: string}
     */
    private function makeRenderVars(array $overrides = []): array
    {
        return \array_merge([
            'marcoLabel' => 'D-3',
            'marcoTitle' => 'Vence em 3 dias úteis',
            'cnj' => '0001234-56.2024.8.26.0001',
            'descricao' => 'Apresentar contestação',
            'dataFatal' => '2026-06-01',
            'dataCumprimento' => '2026-05-28',
            'prazoUrl' => 'https://togare.example.com/#Prazo/view/prazo-001',
            'hedgeJuridico' => 'A responsabilidade final pelo cumprimento do prazo é do(a) advogado(a).',
        ], $overrides);
    }
}
