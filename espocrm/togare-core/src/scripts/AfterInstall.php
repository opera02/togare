<?php

declare(strict_types=1);

use Espo\Core\Container;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Currency\DatabasePopulator as CurrencyDatabasePopulator;
use Espo\Modules\TogareCore\Services\CurrencyConfigSeeder;
use Espo\Modules\TogareCore\Services\DashboardLayoutSeeder;
use Espo\Modules\TogareCore\Services\HealthPanelDashboardSeeder;
use Espo\Modules\TogareCore\Services\MigrationRunner;
use Espo\Modules\TogareCore\Services\Notification\PrazoD0BackfillService;
use Espo\Modules\TogareCore\Services\PreferencesLayoutSeeder;
use Espo\Modules\TogareCore\Services\TogareLogger;
use Espo\Tools\Currency\SyncManager as CurrencySyncManager;
use Espo\ORM\EntityManager;

/**
 * Hook de instalação do ext-template. Executa migrations pendentes do togare-core.
 *
 * Chamado pelo EspoCRM após copiar os arquivos da extension para
 * /var/www/html/custom/Espo/Modules/TogareCore/. Aqui é seguro obter o PDO via
 * EntityManager — o container já foi bootstrapped.
 */
class AfterInstall
{
    public function run(Container $container): void
    {
        // Bootstrap do logger — todos os logs do togare-core a partir daqui passam
        // por TogareLogger::event() (NFR32). Propagação PSR-3 para data/logs/espo.log.
        TogareLogger::init('togare-core', $container);

        $entityManager = $container->getByClass(EntityManager::class);
        $pdo = $entityManager->getPDO();

        // O hook AfterInstall roda de um path temporário durante a instalação;
        // __DIR__ não bate com o destino final do módulo. Usamos o caminho fixo
        // onde o EspoCRM instala extensions (convenção estável do framework).
        $migrationDir = 'custom/Espo/Modules/TogareCore/Migration';
        if (! is_dir($migrationDir)) {
            // cwd pode não ser o root do EspoCRM em alguns cenários; tentamos absoluto.
            $migrationDir = '/var/www/html/custom/Espo/Modules/TogareCore/Migration';
        }

        $runner = new MigrationRunner($pdo, TogareLogger::getInstance());
        $applied = $runner->runPending($migrationDir);

        if ($applied === []) {
            echo "[togare-core] Nenhuma migration pendente.\n";
        } else {
            echo "[togare-core] Migrations aplicadas: " . implode(', ', $applied) . "\n";
        }

        $this->ensureTabsInNavbar($container);
        $this->ensureDefaultLanguagePtBr($container);
        $this->ensureBrlCurrencyConfig($container);
        $this->ensureBriefingDoDiaInDashboardLayout($container);
        $this->ensureHealthPanelInDashboardLayout($container);
        $this->ensureLembreteTabInPreferencesLayout();
        $this->backfillD0Lembretes($pdo);
    }

    /**
     * Sobrescreve o idioma default da aplicação para pt_BR APENAS se ainda
     * estiver no default de fábrica do EspoCRM (`en_US`). Se o admin já
     * mudou para outro idioma (`pt_BR`, `pt_PT`, `es_ES` etc.), NÃO toca.
     *
     * Bug corrigido em 0.39.2 (smoke browser do Felipe 2026-05-20):
     * instalação fresh ficava em inglês — sidebar mostrava "Accounts/
     * Contacts/Leads" stock e a experiência era confusa para o ICP do
     * Togare (escritórios de advocacia brasileiros).
     *
     * Idempotente: subsequentes execuções não tocam (config já é `pt_BR`,
     * ou foi explicitamente mudada — não há como distinguir "admin
     * configurou pt_BR" de "AfterInstall configurou", e ambos são OK).
     */
    private function ensureDefaultLanguagePtBr(Container $container): void
    {
        try {
            $config = $container->getByClass(Config::class);
            $injectableFactory = $container->getByClass(InjectableFactory::class);
            $configWriter = $injectableFactory->create(ConfigWriter::class);
        } catch (\Throwable $e) {
            echo "[togare-core] AVISO: ConfigWriter indisponível — defina language=pt_BR manualmente em Admin → Settings.\n";
            return;
        }

        $current = $config->get('language');
        if ($current !== 'en_US') {
            // já configurado pelo admin (pt_BR, outro idioma, ou null custom).
            return;
        }

        $configWriter->set('language', 'pt_BR');
        $configWriter->save();
        echo "[togare-core] Idioma default da aplicação: en_US → pt_BR.\n";
    }

    /**
     * Story 4a.3 — garante que as tabs operacionais Togare estão no navbar
     * (`data/config.php::tabList`). EspoCRM não auto-popula o navbar a partir
     * do entityDefs `tab: true` ou do `metadata/app/client.json::tabList` —
     * o navbar é controlado por config.php (Settings.tabList) e precisa ser
     * atualizado via ConfigWriter.
     *
     * Idempotente: só adiciona tabs ausentes, preserva customização do admin.
     */
    private function ensureTabsInNavbar(Container $container): void
    {
        // Story 4b.1a — adiciona PublicacaoAmbigua ao tabList (fila "Precisa sua leitura").
        // Story 6.3 — adiciona Fatura + LancamentoFinanceiro (financeiro do escritório).
        // Story 6.5 — adiciona Funcionario (RH-lite, cadastro básico de equipe — FR32).
        $togareTabs = ['Cliente', 'ParteContraria', 'Processo', 'Audiencia', 'Prazo', 'PublicacaoAmbigua', 'Fatura', 'LancamentoFinanceiro', 'Funcionario'];

        try {
            $config = $container->getByClass(Config::class);
            $injectableFactory = $container->getByClass(InjectableFactory::class);
            $configWriter = $injectableFactory->create(ConfigWriter::class);
        } catch (\Throwable $e) {
            echo "[togare-core] AVISO: ConfigWriter indisponível — adicione as tabs Togare ao tabList manualmente em Admin → User Interface: " . implode(', ', $togareTabs) . ".\n";
            return;
        }

        $tabList = $config->get('tabList') ?? [];
        if (! is_array($tabList)) {
            return;
        }

        $missing = [];
        foreach ($togareTabs as $tab) {
            if (! in_array($tab, $tabList, true)) {
                $missing[] = $tab;
            }
        }

        if ($missing === []) {
            return;
        }

        // Inserção em 3 níveis de fallback — lógica pura em TabListPlacer
        // (extraída em 0.39.2 para teste isolado; ver TabListPlacerTest).
        // Universo conhecido das tabs Togare é o mesmo array $togareTabs
        // do início do método.
        $tabList = \Espo\Modules\TogareCore\Services\TabListPlacer::place(
            $tabList,
            $missing,
            $togareTabs,
        );

        $configWriter->set('tabList', $tabList);
        $configWriter->save();

        echo "[togare-core] Tabs adicionadas ao navbar: " . implode(', ', $missing) . "\n";
    }

    /**
     * Story 6.5 review fix-pass: campos monetários Togare usam BRL.
     *
     * O EspoCRM stock instala com USD em currencyList/defaultCurrency/baseCurrency.
     * Isso fazia `salarioCurrency=BRL` falhar com validCurrency mesmo quando o
     * entityDefs declarava defaultCurrency=BRL.
     */
    private function ensureBrlCurrencyConfig(Container $container): void
    {
        try {
            $config = $container->getByClass(Config::class);
            $injectableFactory = $container->getByClass(InjectableFactory::class);
            $configWriter = $injectableFactory->create(ConfigWriter::class);
        } catch (\Throwable $e) {
            echo "[togare-core] AVISO: ConfigWriter indisponível — configure BRL em Admin → Settings → Currency manualmente.\n";
            return;
        }

        $next = CurrencyConfigSeeder::buildBrlConfig(
            $config->get('currencyList') ?? [],
            $config->get('defaultCurrency'),
            $config->get('baseCurrency'),
        );

        if (! $next['changed']) {
            echo "[togare-core] Configuração de moeda já está em BRL — skip.\n";
            return;
        }

        try {
            $configWriter->set('currencyList', $next['currencyList']);
            $configWriter->set('defaultCurrency', $next['defaultCurrency']);
            $configWriter->set('baseCurrency', $next['baseCurrency']);
            $configWriter->save();

            try {
                $injectableFactory->create(CurrencySyncManager::class)->sync();
                $injectableFactory->create(CurrencyDatabasePopulator::class)->process();
            } catch (\Throwable $e) {
                echo "[togare-core] AVISO: moeda BRL salva, mas sync/populate de Currency falhou: " . $e->getMessage() . "\n";
            }

            echo "[togare-core] Configuração de moeda ajustada para BRL (currencyList/defaultCurrency/baseCurrency).\n";
        } catch (\Throwable $e) {
            echo "[togare-core] AVISO: falha ao salvar configuração de moeda BRL: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Story 4a.5 / 10.1 — popula `Settings.dashboardLayout` com a tab "Briefing"
     * contendo `togare-prazos-do-dia` e `briefing-do-dia` lado a lado.
     *
     * Lógica idempotente (AC5 Story 10.1):
     *  - Ambos presentes → skip.
     *  - Só `togare-prazos-do-dia` presente (upgrade de install anterior) →
     *    adiciona `briefing-do-dia` ao tab Briefing existente.
     *  - Nenhum presente (install limpo) → cria tab "Briefing" com ambos.
     *
     * Não-fatal: try/catch envolve toda a operação.
     */
    private function ensureBriefingDoDiaInDashboardLayout(Container $container): void
    {
        try {
            $config = $container->getByClass(Config::class);
            $injectableFactory = $container->getByClass(InjectableFactory::class);
            $configWriter = $injectableFactory->create(ConfigWriter::class);
        } catch (\Throwable $e) {
            echo "[togare-core] AVISO: ConfigWriter indisponível para dashboardLayout — adicione os dashlets manualmente em Admin → Settings → Dashboard Layout.\n";
            return;
        }

        $rawLayout = $config->get('dashboardLayout');
        $hasPrazos   = DashboardLayoutSeeder::hasDashlet($rawLayout);
        $hasBriefing = DashboardLayoutSeeder::hasBriefingDoDia($rawLayout);

        if ($hasBriefing) {
            echo "[togare-core] dashboardLayout já contém briefing-do-dia — skip.\n";
            return;
        }

        if ($hasPrazos) {
            // Upgrade: adiciona briefing-do-dia ao tab Briefing existente.
            $layout = DashboardLayoutSeeder::appendBriefingDoDiaToExistingTab(
                $rawLayout,
                bin2hex(random_bytes(8)),
            );
            $configWriter->set('dashboardLayout', $layout);
            $configWriter->save();
            echo "[togare-core] dashboardLayout: briefing-do-dia adicionado ao tab Briefing existente.\n";
            return;
        }

        // Install limpo: cria tab Briefing com ambos.
        $layout = DashboardLayoutSeeder::appendBriefingTabWithBoth(
            $rawLayout,
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(8)),
        );
        $configWriter->set('dashboardLayout', $layout);
        $configWriter->save();

        echo "[togare-core] dashboardLayout default populado com tab 'Briefing' (togare-prazos-do-dia + briefing-do-dia).\n";
    }

    /**
     * Story 10.2 / FR41 — popula `Settings.dashboardLayout` com a tab "Saúde"
     * contendo o dashlet `TogareHealth` se ainda não estiver presente.
     *
     * Idempotente (re-rodadas verificam `hasDashlet`) e não-fatal — falha aqui
     * NÃO bloqueia o install. RBAC: o endpoint responde 403 para role ≠
     * Sócio/Admin e a view renderiza vazio nesse caso (blindagem cruzada AC4).
     */
    private function ensureHealthPanelInDashboardLayout(Container $container): void
    {
        try {
            $config = $container->getByClass(Config::class);
            $injectableFactory = $container->getByClass(InjectableFactory::class);
            $configWriter = $injectableFactory->create(ConfigWriter::class);
        } catch (\Throwable $e) {
            echo "[togare-core] AVISO: ConfigWriter indisponível para HealthPanel — adicione o dashlet 'Saúde do Togare' manualmente em Admin → Settings → Dashboard Layout.\n";
            return;
        }

        $rawLayout = $config->get('dashboardLayout');

        if (HealthPanelDashboardSeeder::hasDashlet($rawLayout)) {
            echo "[togare-core] dashboardLayout já contém TogareHealth — skip.\n";
            return;
        }

        $layout = HealthPanelDashboardSeeder::appendSaudeTab(
            $rawLayout,
            bin2hex(random_bytes(8)),
            bin2hex(random_bytes(8)),
        );

        $configWriter->set('dashboardLayout', $layout);
        $configWriter->save();

        echo "[togare-core] dashboardLayout default populado com tab 'Saúde' (dashlet TogareHealth).\n";
    }

    /**
     * Story 4b.2 fix-pass v0.26.3 — popula
     * `custom/Espo/Custom/Resources/layouts/Preferences/detail.json` com a tab
     * "Meus lembretes (Togare)" no fim do layout stock.
     *
     * **Por quê via custom/Espo/Custom/Resources/layouts/ (não data/layouts/)**:
     * o EspoCRM 9.x stock `LayoutManager::set()` (linha 83) e
     * `CustomLayoutService` (linha 157) gravam em
     * `custom/Espo/Custom/Resources/layouts/<scope>/<name>.json`. Esse é o path
     * canônico que `LayoutProvider::get() → FileReader` consulta com
     * precedência sobre `application/Espo/Resources/layouts/` (stock). Tentativa
     * inicial via `data/layouts/` (v0.26.2) NÃO funcionou — EspoCRM 9.x não usa
     * esse path. Bug B25 da Story 4b.2 — diagnosticado por Felipe via API
     * `/api/v1/Preferences/layout/detail` retornar layout sem a tab Togare
     * mesmo com arquivo escrito em `data/`.
     *
     * Idempotente: lê layout atual (se existir), só faz append da tab Togare
     * se ainda não estiver presente. Preserva quaisquer customizações que
     * o admin tenha feito via Admin → Layouts.
     */
    private function ensureLembreteTabInPreferencesLayout(): void
    {
        $layoutDir = '/var/www/html/custom/Espo/Custom/Resources/layouts/Preferences';
        $layoutPath = $layoutDir . '/detail.json';

        try {
            if (! is_dir($layoutDir)) {
                if (! mkdir($layoutDir, 0755, true) && ! is_dir($layoutDir)) {
                    echo "[togare-core] AVISO: não pude criar {$layoutDir} — adicione tab manualmente em Admin → Layouts → Preferences.\n";
                    return;
                }
            }

            $current = null;
            if (is_file($layoutPath)) {
                $raw = file_get_contents($layoutPath);
                if ($raw !== false) {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $current = $decoded;
                    }
                }
            }

            if (PreferencesLayoutSeeder::hasTogareTab($current)) {
                echo "[togare-core] custom/Espo/Custom/Resources/layouts/Preferences/detail.json já contém tab togareLembretes — skip.\n";
                return;
            }

            $newLayout = PreferencesLayoutSeeder::appendLembreteTab($current);
            $encoded = json_encode($newLayout, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                echo "[togare-core] AVISO: falha ao encodar layout Preferences — pulei.\n";
                return;
            }

            $written = file_put_contents($layoutPath, $encoded);
            if ($written === false) {
                echo "[togare-core] AVISO: não pude escrever {$layoutPath} — adicione tab manualmente.\n";
                return;
            }

            // Permissões: EspoCRM lê via apache user; garantir 0644 + grupo writable.
            @chmod($layoutPath, 0664);

            echo "[togare-core] custom/Espo/Custom/Resources/layouts/Preferences/detail.json populado com tab 'Meus lembretes (Togare)'.\n";
        } catch (\Throwable $e) {
            echo "[togare-core] AVISO: ensureLembreteTabInPreferencesLayout falhou: " . $e->getMessage() . "\n";
        }
    }

    private function backfillD0Lembretes(\PDO $pdo): void
    {
        try {
            $inserted = (new PrazoD0BackfillService())->backfill($pdo);
            echo "[togare-core] Backfill D-0: {$inserted} lembrete(s) inserido(s).\n";
        } catch (\Throwable $e) {
            echo "[togare-core] AVISO: backfill D-0 falhou: " . $e->getMessage() . "\n";
        }
    }
}
