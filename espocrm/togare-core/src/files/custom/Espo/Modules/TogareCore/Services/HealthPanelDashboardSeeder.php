<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

/**
 * Lógica pura de mutação do `Settings.dashboardLayout` para garantir o dashlet
 * `TogareHealth` (Story 10.2, FR41) no layout default.
 *
 * Espelha o pattern de {@see DashboardLayoutSeeder} (Story 4a.5): classe
 * separada para testar idempotência + mutação sem mockar Container/Config.
 *
 * **Idempotência:** se QUALQUER tab já contém um item `name = 'TogareHealth'`,
 * retorna o layout original sem mutação. Caso contrário, anexa uma tab "Saúde"
 * com 1 item dashlet.
 *
 * **RBAC (AC4):** o dashlet é seedado para todos os usuários novos (design
 * stock do EspoCRM copia o default para Preferences.dashboardLayout), mas o
 * endpoint `TogareHealth/action/data` responde 403 para role ≠ Sócio/Admin e
 * a view renderiza vazio nesse caso — blindagem cruzada preservada.
 */
final class HealthPanelDashboardSeeder
{
    public const DASHLET_NAME = 'TogareHealth';
    public const TAB_NAME = 'Saúde';
    public const DEFAULT_WIDTH = 4;
    public const DEFAULT_HEIGHT = 4;

    /**
     * @param mixed $layout array, null ou outra coisa.
     */
    public static function hasDashlet(mixed $layout): bool
    {
        if (! \is_array($layout)) {
            return false;
        }
        foreach ($layout as $tab) {
            if (! \is_array($tab)) {
                continue;
            }
            $items = $tab['layout'] ?? [];
            if (! \is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (\is_array($item) && ($item['name'] ?? '') === self::DASHLET_NAME) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Retorna NOVO layout com a tab "Saúde" anexada (sem mutar input). Caller
     * deve usar `hasDashlet()` antes para evitar duplicação.
     *
     * @param  mixed  $layout
     * @return list<array<string, mixed>>
     */
    public static function appendSaudeTab(mixed $layout, string $tabId, string $itemId): array
    {
        $base = \is_array($layout) ? \array_values($layout) : [];

        $base[] = [
            'id' => $tabId,
            'name' => self::TAB_NAME,
            'layout' => [[
                'id' => $itemId,
                'name' => self::DASHLET_NAME,
                'x' => 0,
                'y' => 0,
                'width' => self::DEFAULT_WIDTH,
                'height' => self::DEFAULT_HEIGHT,
            ]],
        ];

        return $base;
    }
}
