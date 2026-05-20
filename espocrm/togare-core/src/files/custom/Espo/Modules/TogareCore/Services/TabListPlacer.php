<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Services;

/**
 * Lógica pura de inserção das tabs operacionais Togare no `tabList` do EspoCRM.
 *
 * Extraída de `AfterInstall::ensureTabsInNavbar` em 0.39.2 (após bug ach
 * achado no smoke browser fresh-install do Felipe 2026-05-20: tabs caíam
 * depois do `_delimiter_` quando NÃO existia tab Togare prévia, ficando
 * escondidas no dropdown "More" da sidebar).
 *
 * Estratégia em 3 níveis de fallback:
 *  1. Existem tabs Togare prévias  → insere após a última (preserva
 *     ordem caso o admin tenha reordenado).
 *  2. Não há Togare mas há `_delimiter_`  → insere imediatamente antes
 *     do delimiter, para as tabs aparecerem DIRETO na sidebar.
 *  3. Sem delimiter e sem Togare  → append no fim (cenário raríssimo).
 *
 * Função pura: sem dependência de Container/Config/ConfigWriter. Caller
 * lê o `tabList` atual, chama esta função, e persiste o resultado via
 * `ConfigWriter`.
 */
final class TabListPlacer
{
    /**
     * @param list<string|array<string,mixed>|object> $tabList     `tabList` atual do Settings.
     * @param list<string>                            $missing     Tabs Togare a inserir (ordem preservada).
     * @param list<string>                            $togareKnown Universo das tabs Togare (p/ detectar nível 1).
     *
     * @return list<string|array<string,mixed>|object> Novo `tabList`.
     */
    public static function place(array $tabList, array $missing, array $togareKnown): array
    {
        if ($missing === []) {
            return $tabList;
        }

        $lastTogareIndex = -1;
        $delimiterIndex = -1;
        foreach ($tabList as $i => $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if (in_array($entry, $togareKnown, true)) {
                $lastTogareIndex = $i;
            } elseif ($entry === '_delimiter_' && $delimiterIndex < 0) {
                $delimiterIndex = $i;
            }
        }

        if ($lastTogareIndex >= 0) {
            // Nível 1.
            array_splice($tabList, $lastTogareIndex + 1, 0, $missing);
            return $tabList;
        }

        if ($delimiterIndex >= 0) {
            // Nível 2 — corrige bug 0.39.2.
            array_splice($tabList, $delimiterIndex, 0, $missing);
            return $tabList;
        }

        // Nível 3.
        foreach ($missing as $tab) {
            $tabList[] = $tab;
        }
        return $tabList;
    }
}
