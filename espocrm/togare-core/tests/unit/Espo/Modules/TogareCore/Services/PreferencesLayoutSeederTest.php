<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\TogareCore\Services;

use Espo\Modules\TogareCore\Services\PreferencesLayoutSeeder;
use PHPUnit\Framework\TestCase;

/**
 * Cobre Story 4b.2 fix-pass v0.26.2 — bug B25 layout Preferences não aparece.
 */
final class PreferencesLayoutSeederTest extends TestCase
{
    public function testStockLayoutTem4TabsBreak(): void
    {
        $layout = PreferencesLayoutSeeder::stockLayout();
        $tabs = \array_filter($layout, static fn ($p) => ($p['tabBreak'] ?? false) === true);
        self::assertCount(4, $tabs, 'Stock EspoCRM 9.x Preferences tem 4 tabs (Locale, General, User Interface, Notifications)');
    }

    public function testHasTogareTabReturnsFalseEmStock(): void
    {
        self::assertFalse(PreferencesLayoutSeeder::hasTogareTab(PreferencesLayoutSeeder::stockLayout()));
    }

    public function testHasTogareTabReturnsFalseEmInputInvalido(): void
    {
        self::assertFalse(PreferencesLayoutSeeder::hasTogareTab(null));
        self::assertFalse(PreferencesLayoutSeeder::hasTogareTab('not-array'));
        self::assertFalse(PreferencesLayoutSeeder::hasTogareTab([]));
    }

    public function testAppendLembreteTabComLayoutNullUsaStockMaisTab(): void
    {
        $result = PreferencesLayoutSeeder::appendLembreteTab(null);
        $tabs = \array_filter($result, static fn ($p) => ($p['tabBreak'] ?? false) === true);
        self::assertCount(5, $tabs, '4 stock + 1 togareLembretes');
        self::assertTrue(PreferencesLayoutSeeder::hasTogareTab($result));
    }

    public function testAppendLembreteTabComLayoutCustomPreservaCustomizacao(): void
    {
        $custom = [
            ['tabBreak' => true, 'tabLabel' => '$label:Locale', 'rows' => [[['name' => 'language'], false]]],
            ['tabBreak' => true, 'tabLabel' => 'Custom Admin Tab', 'rows' => [[['name' => 'theme']]]],
        ];
        $result = PreferencesLayoutSeeder::appendLembreteTab($custom);

        // Customização preservada + tab Togare anexada.
        self::assertCount(3, $result);
        self::assertSame('Custom Admin Tab', $result[1]['tabLabel']);
        self::assertSame(PreferencesLayoutSeeder::TAB_NAME, $result[2]['name']);
    }

    public function testAppendLembreteTabIdempotente(): void
    {
        $first = PreferencesLayoutSeeder::appendLembreteTab(null);
        $second = PreferencesLayoutSeeder::appendLembreteTab($first);
        self::assertSame($first, $second, 'Re-execução não duplica tab Togare');
    }

    public function testLembreteTabTemFieldEsperado(): void
    {
        $tab = PreferencesLayoutSeeder::lembreteTab();
        self::assertTrue($tab['tabBreak']);
        self::assertSame(PreferencesLayoutSeeder::TAB_NAME, $tab['name']);
        self::assertSame(PreferencesLayoutSeeder::TAB_LABEL_REF, $tab['tabLabel']);
        self::assertSame(PreferencesLayoutSeeder::FIELD_NAME, $tab['rows'][0][0]['name']);
        self::assertTrue($tab['rows'][0][0]['fullWidth']);
    }
}
