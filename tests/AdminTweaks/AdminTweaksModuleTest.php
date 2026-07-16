<?php

declare(strict_types=1);

namespace LeanAdmin\Tests\AdminTweaks;

use LeanAdmin\AdminTweaks\AdminTweaksModule;
use PHPUnit\Framework\TestCase;

final class AdminTweaksModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__lean_admin_test_options'] = [];
        $GLOBALS['__lean_admin_test_actions'] = [];
    }

    public function testRegisterAttachesApplyEnabledAtEarliestInitPriority(): void
    {
        $GLOBALS['__lean_admin_test_is_admin'] = true;

        (new AdminTweaksModule())->register();

        $initApply = array_values(array_filter(
            $GLOBALS['__lean_admin_test_actions'],
            static fn (array $a): bool => $a['hook'] === 'init'
                && is_array($a['callback'])
                && ($a['callback'][1] ?? null) === 'applyEnabled'
        ));

        self::assertCount(1, $initApply);
        // quiet_litespeed_purge must define LITESPEED_PURGE_SILENT before
        // LiteSpeed's own init-time purge callbacks run (WP-42).
        self::assertSame(PHP_INT_MIN, $initApply[0]['priority']);
    }

    public function testRegisterAppliesEnabledTweaksAtEarliestInitPriority(): void
    {
        (new AdminTweaksModule())->register();

        self::assertSame(
            PHP_INT_MIN,
            array_values(array_filter(
                $GLOBALS['__lean_admin_test_actions'],
                static fn (array $action): bool => 'init' === $action['hook'] && 'applyEnabled' === $action['callback'][1]
            ))[0]['priority']
        );
    }

    public function testDefinitionsExposeTheMigratedAdminTweaks(): void
    {
        $keys = array_keys(AdminTweaksModule::definitions());

        self::assertSame(['clean_admin_bar', 'clean_dashboard', 'clean_admin_footer', 'hide_comments_ui', 'quiet_litespeed_purge'], $keys);
    }

    public function testDefinitionsDefaultOffAndAreCallable(): void
    {
        foreach (AdminTweaksModule::definitions() as $tweak) {
            self::assertFalse($tweak['default']);
            self::assertIsCallable($tweak['callback']);
            self::assertNotSame('', $tweak['label']);
        }
    }

    public function testEnabledMapFallsBackToDefaultsWhenUnset(): void
    {
        $map = (new AdminTweaksModule())->enabledMap();

        self::assertSame(
            [
                'clean_admin_bar' => false,
                'clean_dashboard' => false,
                'clean_admin_footer' => false,
                'hide_comments_ui' => false,
                'quiet_litespeed_purge' => false,
            ],
            $map
        );
    }

    public function testEnabledMapRespectsSavedOverrides(): void
    {
        $GLOBALS['__lean_admin_test_options'][AdminTweaksModule::OPTION] = [
            'clean_admin_bar' => true,
            'clean_dashboard' => false,
        ];

        $map = (new AdminTweaksModule())->enabledMap();

        self::assertTrue($map['clean_admin_bar']);
        self::assertFalse($map['clean_dashboard']);
        self::assertFalse($map['clean_admin_footer']); // unset -> default
        self::assertFalse($map['hide_comments_ui']); // unset -> default
        self::assertFalse($map['quiet_litespeed_purge']); // unset -> default
    }

    public function testEnabledMapMigratesAdminTweaksFromLeanSeo(): void
    {
        $GLOBALS['__lean_admin_test_options']['lean_seo_tweak'] = [
            'clean_admin_bar' => ['value' => '1'],
            'clean_dashboard' => ['value' => '0'],
            'clean_admin_footer' => ['value' => '1'],
            'disable_comments' => ['value' => '1'],
        ];

        $map = (new AdminTweaksModule())->enabledMap();

        self::assertTrue($map['clean_admin_bar']);
        self::assertFalse($map['clean_dashboard']);
        self::assertTrue($map['clean_admin_footer']);
        self::assertTrue($map['hide_comments_ui']);
        self::assertSame(
            [
                'clean_admin_bar' => true,
                'clean_dashboard' => false,
                'clean_admin_footer' => true,
                'hide_comments_ui' => true,
                'quiet_litespeed_purge' => false,
            ],
            $GLOBALS['__lean_admin_test_options'][AdminTweaksModule::OPTION]
        );
    }

    public function testSanitizeCoercesKnownKeysToBoolAndDropsUnknown(): void
    {
        $clean = (new AdminTweaksModule())->sanitize([
            'clean_admin_bar' => '1',
            'clean_admin_footer' => 1,
            'evil' => 'x',
        ]);

        self::assertSame(
            [
                'clean_admin_bar' => true,
                'clean_dashboard' => false,
                'clean_admin_footer' => true,
                'hide_comments_ui' => false,
                'quiet_litespeed_purge' => false,
            ],
            $clean
        );
        self::assertArrayNotHasKey('evil', $clean);
    }
}
