<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

/**
 * Story 4b.2 fix-pass v0.26.2 — popula `data/layouts/Preferences/detail.json`
 * com tab "Meus lembretes (Togare)" no fim do layout stock do EspoCRM.
 *
 * **Contexto do bug B25:** o `LayoutProvider::get()` busca primeiro layouts em
 * `application/Espo/Resources/layouts/<scope>/<name>.json` (stock) e só
 * depois itera os módulos. Por isso o layout em
 * `custom/Espo/Modules/TogareCore/Resources/layouts/Preferences/detail.json`
 * nunca era retornado para a entity stock `Preferences` (que tem layout em
 * `application/`). O caminho `data/layouts/<scope>/<name>.json` tem
 * precedência ABSOLUTA — sobrepõe stock + módulos.
 *
 * **Idempotência**: append da tab `togareLembretes` só se ainda não existir;
 * preserva quaisquer customizações que o admin tenha feito via Admin → Layouts.
 */
final class PreferencesLayoutSeeder
{
    public const TAB_NAME = 'togareLembretes';
    public const TAB_LABEL_REF = '$label:lembreteConfig.tabLabel';
    public const FIELD_NAME = 'togareLembreteConfig';

    /**
     * Snapshot do layout stock do EspoCRM 9.x para Preferences/detail
     * (4 tabs: Locale, General, User Interface, Notifications) capturado
     * em 2026-05-09 do install do container nextcloud-crm-espocrm-1.
     *
     * Em update do core EspoCRM, o admin pode resetar via Admin → Layouts e
     * re-instalar o togare-core para repopular este seed (cobertura
     * defensiva: o seeder só escreve se o file não existir).
     *
     * @return list<array<string, mixed>>
     */
    public static function stockLayout(): array
    {
        return [
            [
                'tabBreak' => true,
                'tabLabel' => '$label:Locale',
                'rows' => [
                    [['name' => 'language'], false],
                ],
            ],
            [
                'rows' => [
                    [['name' => 'timeZone'], ['name' => 'weekStart']],
                    [['name' => 'dateFormat'], false],
                    [['name' => 'timeFormat'], false],
                ],
            ],
            [
                'rows' => [
                    [['name' => 'defaultCurrency'], false],
                ],
            ],
            [
                'rows' => [
                    [['name' => 'thousandSeparator'], ['name' => 'decimalMark']],
                ],
            ],
            [
                'tabBreak' => true,
                'tabLabel' => '$label:General',
                'rows' => [
                    [['name' => 'emailReplyToAllByDefault'], ['name' => 'emailReplyForceHtml']],
                    [['name' => 'emailUseExternalClient'], false],
                    [['name' => 'signature']],
                ],
            ],
            [
                'rows' => [
                    [['name' => 'followEntityOnStreamPost'], ['name' => 'autoFollowEntityTypeList']],
                    [['name' => 'followCreatedEntities'], ['name' => 'followCreatedEntityTypeList']],
                    [['name' => 'followAsCollaborator'], false],
                ],
            ],
            [
                'rows' => [
                    [['name' => 'exportDelimiter'], false],
                    [['name' => 'textSearchStoringDisabled'], ['name' => 'doNotFillAssignedUserIfNotRequired']],
                ],
            ],
            [
                'rows' => [
                    [['name' => 'calendarSlotDuration'], ['name' => 'calendarScrollHour']],
                    [['name' => 'defaultReminders'], ['name' => 'defaultRemindersTask']],
                ],
            ],
            [
                'tabBreak' => true,
                'tabLabel' => '$label:User Interface',
                'rows' => [
                    [['name' => 'theme'], ['name' => 'pageContentWidth']],
                ],
            ],
            [
                'rows' => [
                    [['name' => 'useCustomTabList'], ['name' => 'addCustomTabs']],
                    [['name' => 'tabList'], false],
                ],
            ],
            [
                'name' => 'dashboard',
                'rows' => [
                    [['name' => 'dashboardLayout']],
                ],
            ],
            [
                'tabBreak' => true,
                'tabLabel' => '$label:Notifications',
                'name' => 'notifications',
                'rows' => [
                    [
                        ['name' => 'receiveAssignmentEmailNotifications'],
                        ['name' => 'receiveMentionEmailNotifications'],
                    ],
                    [['name' => 'receiveStreamEmailNotifications'], false],
                    [
                        ['name' => 'assignmentNotificationsIgnoreEntityTypeList'],
                        ['name' => 'assignmentEmailNotificationsIgnoreEntityTypeList'],
                    ],
                    [['name' => 'reactionNotifications'], ['name' => 'reactionNotificationsNotFollowed']],
                ],
            ],
        ];
    }

    /**
     * Tab "Meus lembretes (Togare)" — única row com o field
     * `togareLembreteConfig` (renderizado pela view custom
     * `togare-core:views/preferences/lembrete-config`).
     *
     * @return array<string, mixed>
     */
    public static function lembreteTab(): array
    {
        return [
            'tabBreak' => true,
            'tabLabel' => self::TAB_LABEL_REF,
            'name' => self::TAB_NAME,
            'rows' => [
                [['name' => self::FIELD_NAME, 'fullWidth' => true]],
            ],
        ];
    }

    /**
     * Detecta se o layout já tem a tab `togareLembretes`. Idempotente.
     *
     * @param mixed $layout
     */
    public static function hasTogareTab(mixed $layout): bool
    {
        if (! \is_array($layout)) {
            return false;
        }
        foreach ($layout as $panel) {
            if (! \is_array($panel)) {
                continue;
            }
            if (($panel['name'] ?? null) === self::TAB_NAME) {
                return true;
            }
        }
        return false;
    }

    /**
     * Append da tab Togare ao final do layout. Se `$current` for null/inválido,
     * usa `stockLayout()` como base. Idempotente: se a tab já existe, devolve
     * o layout intacto.
     *
     * @param mixed $current  layout atual (lido de data/layouts/Preferences/detail.json)
     * @return list<array<string, mixed>>
     */
    public static function appendLembreteTab(mixed $current): array
    {
        $layout = \is_array($current) && $current !== [] ? $current : self::stockLayout();

        if (self::hasTogareTab($layout)) {
            return $layout;
        }

        $layout[] = self::lembreteTab();
        return $layout;
    }
}
