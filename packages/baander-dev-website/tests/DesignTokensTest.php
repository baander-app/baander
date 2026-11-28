<?php

declare(strict_types=1);

namespace Baander\DevWebsite\Tests;

use Baander\DevWebsite\Content\Contrast;
use Baander\DevWebsite\Content\DesignDocument;
use Baander\DevWebsite\Content\DesignTokens;
use PHPUnit\Framework\TestCase;

final class DesignTokensTest extends TestCase
{
    private const array FILES = [DesignTokens::THEME, DesignTokens::TYPE, DesignTokens::PALETTE, DesignTokens::SEMANTICS, 'ui/DESIGN.md'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/baander-design-tokens-' . bin2hex(random_bytes(6));
        foreach (self::FILES as $path) {
            if (!is_dir(dirname($this->root . '/' . $path))) {
                mkdir(dirname($this->root . '/' . $path), 0700, true);
            }
            copy(dirname(__DIR__, 3) . '/' . $path, $this->root . '/' . $path);
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testTheGeneratedThemeHoldsEveryMoodWithEveryAccentAndVioletAsTheDefault(): void
    {
        $tokens = DesignTokens::load($this->root);

        self::assertSame(['dark' => 'Dark', 'warm' => 'Warm', 'cool' => 'Cool', 'balanced' => 'Balanced'], $tokens->moods());
        $accents = array_keys($tokens->accents());
        self::assertSame(['white', 'blue', 'violet', 'rose', 'amber', 'emerald', 'cyan', 'pink'], $accents);
        self::assertSame('violet', $tokens->defaultAccent());
        self::assertSame('#6e5ca5', $tokens->colors('warm')['--color-accent-text']);
        self::assertSame('#000000', $tokens->colors('dark', 'rose')['--color-background']);
        self::assertSame(['text' => 4.5, 'action' => 4.7, 'indicator' => 3.0], $tokens->targets());
    }

    public function testTheDefaultAccentIsTheOneAMoodWithoutAnAccentSelects(): void
    {
        $css = (string) file_get_contents($this->root . '/' . DesignTokens::THEME)
            |> (static fn(string $css): string => (string) preg_replace('/\n:root\[data-theme="\w+"\]:not\(\[data-accent\]\),?(?=\n|\s*\{)/', '', $css))
            |> (static fn(string $css): string => (string) preg_replace('/(:root\[data-theme="(\w+)"\]\[data-accent="blue"\]),?/', "\$1,\n:root[data-theme=\"\$2\"]:not([data-accent]),", $css))
            |> (static fn(string $css): string => (string) preg_replace('/,\s*\{/', ' {', $css));
        file_put_contents($this->root . '/' . DesignTokens::THEME, $css);

        self::assertSame('blue', DesignTokens::load($this->root)->defaultAccent());
    }

    public function testAMissingThemeBlockFailsNamingTheMoodAndAccent(): void
    {
        $this->replace(DesignTokens::THEME, ':root[data-theme="cool"][data-accent="amber"] {', ':root[data-theme="cool"][data-accent="ochre"] {');

        $this->expectExceptionMessage('ui/design-tokens/build/web/theme.css has no colors for the cool mood with the amber accent; rebuild design tokens.');
        DesignTokens::load($this->root);
    }

    public function testEveryDocumentedColorRoleExistsInEveryTheme(): void
    {
        $roles = DesignTokens::load($this->root)->colorRoles($this->design());

        self::assertSame(['--color-background'], $roles[0]['variables']);
        self::assertSame('Background', $roles[0]['use']);
        $border = array_find($roles, static fn(array $role): bool => $role['variables'] === ['--color-border']);
        self::assertSame(['variables' => ['--color-border'], 'use' => 'Borders', 'detail' => 'used sparingly, not between every element'], $border);
        self::assertContains(['variables' => ['--color-primary', '--color-primary-foreground'], 'use' => 'Filled actions and their content', 'detail' => null], $roles);
    }

    public function testADocumentedRoleMissingFromOneThemeFailsNamingItAndTheTheme(): void
    {
        $css = (string) file_get_contents($this->root . '/' . DesignTokens::THEME);
        $block = strpos($css, ':root[data-theme="balanced"][data-accent="pink"]');
        self::assertIsInt($block);
        $ring = strpos($css, '  --color-ring:', $block);
        self::assertIsInt($ring);
        $end = strpos($css, "\n", $ring);
        self::assertIsInt($end);
        file_put_contents($this->root . '/' . DesignTokens::THEME, substr($css, 0, $ring) . substr($css, $end + 1));

        $this->expectExceptionMessage('ui/DESIGN.md documents --color-ring, but ui/design-tokens/build/web/theme.css does not set it for the balanced mood with the pink accent.');
        DesignTokens::load($this->root)->colorRoles($this->design());
    }

    public function testTypeRolesJoinTheTokensWithTheRolesTable(): void
    {
        $roles = DesignTokens::load($this->root)->typeRoles($this->design());

        self::assertSame(['hero', 'title-lg', 'title', 'subtitle', 'body', 'body-sm', 'caption', 'label', 'timecode', 'mono-sm'], array_keys($roles));
        self::assertSame(
            ['desktop' => '12px', 'phone' => '12px', 'weight' => '600', 'tracking' => '0.12em', 'lineHeight' => '1.3', 'transform' => 'uppercase', 'mono' => false, 'use' => 'Overline labels, category chips'],
            $roles['label'],
        );
        self::assertSame(['40px', '34px'], [$roles['hero']['desktop'], $roles['hero']['phone']]);
        self::assertTrue($roles['timecode']['mono']);
        self::assertSame('Timestamps, badges, helper text', $roles['caption']['use']);
    }

    public function testATypeRoleTheTableAndTheTokensSizeDifferentlyFails(): void
    {
        $this->replace('ui/DESIGN.md', '| `caption` | 12px | 12px |', '| `caption` | 13px | 12px |');

        $this->expectExceptionMessage('ui/DESIGN.md › Typography › Roles gives caption the Desktop 13px, but ui/design-tokens/tokens/base/type.json sets 12px.');
        DesignTokens::load($this->root)->typeRoles($this->design());
    }

    public function testScaleStepsCarryTheUsesTheDesignListStatesForTheirSize(): void
    {
        $tokens = DesignTokens::load($this->root);
        $radii = $tokens->scale('radius', $this->design()->items(['Layout', 'Border radius']));

        self::assertSame(['name' => '--radius-lg', 'value' => '0.75rem', 'px' => 12.0, 'uses' => ['Cards']], $radii[2]);
        self::assertSame([], $radii[4]['uses'], 'A size named only in a "Do not use" sentence is not a use.');
        self::assertSame(['Page padding'], array_column($tokens->scale('space', $this->design()->items(['Layout', 'Spacing'])), 'uses', 'name')['--space-lg']);
        self::assertSame('60ms', $tokens->durations()['--duration-hover']);
    }

    public function testAScaleWithSubjectsCreditsOnlyTheItemsThatNameOne(): void
    {
        $items = ['Header height: `48px`', 'Section gap: `48px`', 'Compact rows: `4px` vertical padding', 'Panel width: `16px` wide. Padding is not set here.'];
        $uses = array_column(DesignTokens::load($this->root)->scale('space', $items, ['padding', 'gap']), 'uses', 'name');

        self::assertSame(['Section gap'], $uses['--space-2xl'], 'An item about a component dimension is not a use.');
        self::assertSame(['Compact rows'], $uses['--space-xs'], 'A subject named after the label counts.');
        self::assertSame([], $uses['--space-md'], 'A subject named after the first sentence does not count.');
        self::assertSame(['Header height', 'Section gap'], array_column(DesignTokens::load($this->root)->scale('space', $items), 'uses', 'name')['--space-2xl']);
    }

    public function testEveryAccentMeetsTheGeneratorTargetsInEveryMood(): void
    {
        $tokens = DesignTokens::load($this->root);
        $derivations = $tokens->accentDerivations();

        self::assertSame(array_keys($tokens->moods()), array_keys($derivations));
        $warm = $derivations['warm']['violet'];
        self::assertSame(['#a78bfa', '#6b59a1', '#ffffff', '#6e5ca5', '#8c75d2'], [$warm['identity'], $warm['primary'], $warm['primaryForeground'], $warm['accentText'], $warm['ring']]);
        self::assertSame('secondary', $warm['text']['surface']);
        self::assertSame('4.50', Contrast::format($warm['text']['ratio']));
        foreach ($derivations as $accents) {
            foreach ($accents as $derivation) {
                self::assertGreaterThanOrEqual(4.5, $derivation['fill']);
                self::assertGreaterThanOrEqual(4.5, $derivation['text']['ratio']);
                self::assertGreaterThanOrEqual(3.0, $derivation['ringContrast']['ratio']);
            }
        }
    }

    public function testAnAccentTextBelowItsTargetFailsNamingTheSurface(): void
    {
        $this->replace(DesignTokens::SEMANTICS, '"normalTextContrast": 4.5', '"normalTextContrast": 4.51');

        $this->expectExceptionMessageMatches('/in the warm mood with the \w+ accent, --color-accent-text reaches 4\.50:1 against secondary, below 4\.51:1\./');
        DesignTokens::load($this->root)->accentDerivations();
    }

    public function testTheMotionTableHoverRowMustEqualTheHoverDuration(): void
    {
        self::assertContains(
            ['transition' => 'Hover state', 'duration' => '60ms', 'easing' => 'ease-out'],
            DesignTokens::load($this->root)->motion($this->design()),
        );
        $this->replace(DesignTokens::THEME, '--duration-hover: 60ms;', '--duration-hover: 80ms;');

        $this->expectExceptionMessage('ui/DESIGN.md › Motion gives Hover state 60ms, but ui/design-tokens/build/web/theme.css sets --duration-hover to 80ms.');
        DesignTokens::load($this->root)->motion($this->design());
    }

    public function testADeclarationIsReadFromTheRuleWhoseSelectorListHoldsTheSelector(): void
    {
        $css = "/* .eyebrow { font-size: 99px; } */\n.eyebrow, .label { font-size: 11px; letter-spacing: .12em; }\n@media (max-width: 640px) {\n  .page { padding: 24px; }\n}\n";

        self::assertSame('11px', DesignTokens::declaration($css, 'ui/site/assets/site.css', '.label', 'font-size'));
        self::assertSame('24px', DesignTokens::declaration($css, 'ui/site/assets/site.css', '.page', 'padding'));
        $this->expectExceptionMessage('ui/site/assets/site.css sets no line-height for .eyebrow.');
        DesignTokens::declaration($css, 'ui/site/assets/site.css', '.eyebrow', 'line-height');
    }

    private function design(): DesignDocument
    {
        return DesignDocument::load($this->root, 'ui/DESIGN.md');
    }

    private function replace(string $path, string $search, string $replacement): void
    {
        $contents = (string) file_get_contents($this->root . '/' . $path);
        self::assertStringContainsString($search, $contents, $path . ' no longer holds the text this test changes.');
        file_put_contents($this->root . '/' . $path, str_replace($search, $replacement, $contents));
    }
}
