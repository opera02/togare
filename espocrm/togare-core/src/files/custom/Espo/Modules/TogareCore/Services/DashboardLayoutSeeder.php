<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

/**
 * Lógica pura de mutação do `Settings.dashboardLayout` para garantir que o
 * dashlet `togare-prazos-do-dia` (Story 4a.5 BriefingDoDia) esteja presente
 * no layout default que vai para usuários novos.
 *
 * **Por que classe separada?** Permite testar a lógica de idempotência +
 * mutação do array isoladamente, sem mockar `Container`/`Config`/
 * `ConfigWriter`/`InjectableFactory` do EspoCRM.
 *
 * **Idempotência:** scaneia todas as tabs e seus items; se encontrar um item
 * com `name = 'togare-prazos-do-dia'`, retorna o layout original sem mutação.
 * Caso contrário, anexa nova tab "Briefing" com 1 item single-occurrence.
 *
 * **IDs:** gerados via `bin2hex(random_bytes(8))` no caller (AfterInstall.php)
 * para preservar testabilidade — esta service aceita IDs explícitos para
 * permitir snapshots determinísticos nos testes.
 */
final class DashboardLayoutSeeder
{
    public const DASHLET_NAME = 'togare-prazos-do-dia';
    public const DASHLET_NAME_BRIEFING = 'briefing-do-dia';
    public const TAB_NAME = 'Briefing';
    public const DEFAULT_WIDTH = 4;
    public const DEFAULT_HEIGHT = 4;

    /**
     * Verifica se o layout já contém o dashlet `togare-prazos-do-dia` em
     * qualquer tab. Tolerante a estruturas malformadas (tabs ou items que
     * não são arrays são puladas).
     *
     * @param mixed $layout Pode ser array, null ou outra coisa.
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
                if (! \is_array($item)) {
                    continue;
                }
                if (($item['name'] ?? '') === self::DASHLET_NAME) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Verifica se o layout já contém o dashlet `briefing-do-dia`.
     *
     * @param mixed $layout Pode ser array, null ou outra coisa.
     */
    public static function hasBriefingDoDia(mixed $layout): bool
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
                if (! \is_array($item)) {
                    continue;
                }
                if (($item['name'] ?? '') === self::DASHLET_NAME_BRIEFING) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Retorna NOVO layout array com a tab "Briefing" anexada (sem mutar
     * o input). Caller deve usar `hasDashlet()` antes para evitar duplicação.
     *
     * @param mixed       $layout    Layout atual (array ou outra coisa — substitui).
     * @param string      $tabId     ID determinístico para a nova tab.
     * @param string      $itemId    ID determinístico para o item dashlet.
     * @return list<array<string, mixed>>
     */
    public static function appendBriefingTab(mixed $layout, string $tabId, string $itemId): array
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

    /**
     * Cria tab "Briefing" com AMBOS dashlets (install limpo — AC5).
     *
     * @param mixed  $layout         Layout atual.
     * @param string $tabId          ID da nova tab.
     * @param string $prazosItemId   ID do item togare-prazos-do-dia.
     * @param string $briefingItemId ID do item briefing-do-dia.
     * @return list<array<string, mixed>>
     */
    public static function appendBriefingTabWithBoth(
        mixed $layout,
        string $tabId,
        string $prazosItemId,
        string $briefingItemId
    ): array {
        $base = \is_array($layout) ? \array_values($layout) : [];

        $base[] = [
            'id' => $tabId,
            'name' => self::TAB_NAME,
            'layout' => [
                [
                    'id' => $prazosItemId,
                    'name' => self::DASHLET_NAME,
                    'x' => 0,
                    'y' => 0,
                    'width' => self::DEFAULT_WIDTH,
                    'height' => self::DEFAULT_HEIGHT,
                ],
                [
                    'id' => $briefingItemId,
                    'name' => self::DASHLET_NAME_BRIEFING,
                    'x' => 4,
                    'y' => 0,
                    'width' => self::DEFAULT_WIDTH,
                    'height' => self::DEFAULT_HEIGHT,
                ],
            ],
        ];

        return $base;
    }

    /**
     * Adiciona `briefing-do-dia` ao tab que já contém `togare-prazos-do-dia`
     * (ou ao primeiro tab de nome "Briefing"). Sem mutar o input. AC5.
     *
     * @param mixed  $layout  Layout atual (deve conter togare-prazos-do-dia).
     * @param string $itemId  ID determinístico para o novo item.
     * @return list<array<string, mixed>>
     */
    public static function appendBriefingDoDiaToExistingTab(mixed $layout, string $itemId): array
    {
        $tabs = \is_array($layout) ? \array_values($layout) : [];

        // Busca o tab que contém togare-prazos-do-dia.
        $targetIndex = -1;
        foreach ($tabs as $i => $tab) {
            if (! \is_array($tab)) {
                continue;
            }
            $items = $tab['layout'] ?? [];
            if (! \is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (\is_array($item) && ($item['name'] ?? '') === self::DASHLET_NAME) {
                    $targetIndex = $i;
                    break 2;
                }
            }
        }

        // Fallback: busca por nome "Briefing".
        if ($targetIndex < 0) {
            foreach ($tabs as $i => $tab) {
                if (\is_array($tab) && ($tab['name'] ?? '') === self::TAB_NAME) {
                    $targetIndex = $i;
                    break;
                }
            }
        }

        if ($targetIndex < 0) {
            // Nenhum tab Briefing encontrado — cria novo com só o briefing-do-dia.
            $tabs[] = [
                'id' => $itemId . '-tab',
                'name' => self::TAB_NAME,
                'layout' => [[
                    'id' => $itemId,
                    'name' => self::DASHLET_NAME_BRIEFING,
                    'x' => 0,
                    'y' => 0,
                    'width' => self::DEFAULT_WIDTH,
                    'height' => self::DEFAULT_HEIGHT,
                ]],
            ];
            return $tabs;
        }

        // Adiciona briefing-do-dia ao tab existente (lado a lado: x=4).
        $tab = $tabs[$targetIndex];
        $existingItems = \is_array($tab['layout']) ? $tab['layout'] : [];
        $existingItems[] = [
            'id' => $itemId,
            'name' => self::DASHLET_NAME_BRIEFING,
            'x' => 4,
            'y' => 0,
            'width' => self::DEFAULT_WIDTH,
            'height' => self::DEFAULT_HEIGHT,
        ];
        $tabs[$targetIndex] = \array_merge($tab, ['layout' => $existingItems]);

        return $tabs;
    }
}
