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
    }

    public function testDefinitionsExposeTheMigratedAdminTweaks(): void
    {
        $keys = array_keys(AdminTweaksModule::definitions());

        self::assertSame(['clean_admin_bar', 'clean_dashboard', 'clean_admin_footer', 'hide_comments_ui'], $keys);
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
            ],
            $clean
        );
        self::assertArrayNotHasKey('evil', $clean);
    }
}
