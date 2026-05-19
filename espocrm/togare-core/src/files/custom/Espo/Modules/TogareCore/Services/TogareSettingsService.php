<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

use Espo\Core\Acl;
use Espo\Core\Acl\Cache\Clearer as AclCacheClearer;
use Espo\Core\ApplicationState;
use Espo\Core\Authentication\Util\MethodProvider as AuthenticationMethodProvider;
use Espo\Core\DataManager;
use Espo\Core\FieldValidation\FieldValidationManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Mail\ConfigDataProvider as EmailConfigDataProvider;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\Access;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Config\SystemConfig;
use Espo\Core\Utils\Currency\DatabasePopulator as CurrencyDatabasePopulator;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\ThemeManager;
use Espo\Modules\TogareCore\Contracts\AuditLogContract;
use Espo\ORM\EntityManager;
use Espo\Tools\App\SettingsService;
use Espo\Tools\Currency\SyncManager as CurrencySyncManager;
use stdClass;

/**
 * Decorator de SettingsService que intercept setConfigData e emite
 * `config.security.changed` para cada chave da allowlist que mudou (AC9, FR37).
 *
 * Settings não usa ORM save — chama configWriter->save() diretamente —
 * então ORM hooks (AfterSave<Settings>) nunca disparam. Este subclass
 * é a alternativa correta: sobrescreve setConfigData e chama parent depois.
 * Registrado em Binding.php via bindImplementation.
 */
class TogareSettingsService extends SettingsService
{
    private Config $ownConfig;

    public function __construct(
        ApplicationState $applicationState,
        Config $config,
        ConfigWriter $configWriter,
        Metadata $metadata,
        Acl $acl,
        EntityManager $entityManager,
        DataManager $dataManager,
        FieldValidationManager $fieldValidationManager,
        InjectableFactory $injectableFactory,
        Access $access,
        AuthenticationMethodProvider $authenticationMethodProvider,
        ThemeManager $themeManager,
        SystemConfig $systemConfig,
        EmailConfigDataProvider $emailConfigDataProvider,
        AclCacheClearer $aclCacheClearer,
        CurrencySyncManager $currencySyncManager,
        CurrencyDatabasePopulator $currencyDatabasePopulator,
        private readonly AuditLogContract $auditLog,
    ) {
        parent::__construct(
            $applicationState,
            $config,
            $configWriter,
            $metadata,
            $acl,
            $entityManager,
            $dataManager,
            $fieldValidationManager,
            $injectableFactory,
            $access,
            $authenticationMethodProvider,
            $themeManager,
            $systemConfig,
            $emailConfigDataProvider,
            $aclCacheClearer,
            $currencySyncManager,
            $currencyDatabasePopulator,
        );
        $this->ownConfig = $config;
    }

    public function setConfigData(stdClass $data): void
    {
        $oldValues = [];
        foreach (SettingsSecurityAuditor::SECURITY_KEYS as $key) {
            if (property_exists($data, $key)) {
                $oldValues[$key] = $this->ownConfig->get($key);
            }
        }

        parent::setConfigData($data);

        (new SettingsSecurityAuditor($this->auditLog))->auditChanges($oldValues, $data);
    }
}
