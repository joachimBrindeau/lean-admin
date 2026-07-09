<?php

declare(strict_types=1);

namespace LeanAdmin\Tests\MetaMenu;

use LeanAdmin\Config\PluginConstants;
use LeanAdmin\MetaMenu\MetaMenuConfig;
use PHPUnit\Framework\TestCase;

final class MetaMenuConfigTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__lean_admin_test_options'] = [];
    }

    public function testThreeLevelTreeRoundTripsUnchanged(): void
    {
        $tree = [
            [
                'type' => 'group',
                'id' => 'post-types',
                'label' => 'Post Types',
                'icon' => 'dashicons-admin-post',
                'children' => [
                    ['type' => 'ref', 'slug' => 'edit.php'],
                    [
                        'type' => 'group',
                        'id' => 'content',
                        'label' => 'Content',
                        'icon' => '',
                        'children' => [
                            ['type' => 'ref', 'slug' => 'edit.php?post_type=page'],
                        ],
                    ],
                ],
            ],
        ];

        self::assertSame($tree, MetaMenuConfig::normalize($tree));
    }

    public function testEmptyArrayNormalizesToEmpty(): void
    {
        self::assertSame([], MetaMenuConfig::normalize([]));
    }

    public function testDeeplyNestedTreePreservesAllLevels(): void
    {
        $node = ['type' => 'ref', 'slug' => 'users.php'];
        for ($i = 0; $i < 5; $i++) {
            $node = [
                'type' => 'group',
                'id' => "level-$i",
                'label' => "Level $i",
                'icon' => '',
                'children' => [$node],
            ];
        }

        $normalized = MetaMenuConfig::normalize([$node]);

        // Walk down 5 group levels and confirm the leaf ref survived.
        $cursor = $normalized[0];
        for ($i = 0; $i < 5; $i++) {
            self::assertSame('group', $cursor['type']);
            $cursor = $cursor['children'][0];
        }
        self::assertSame(['type' => 'ref', 'slug' => 'users.php'], $cursor);
    }

    public function testGroupMissingLabelIsDropped(): void
    {
        $tree = [
            ['type' => 'group', 'id' => 'x', 'label' => '', 'children' => []],
            ['type' => 'ref', 'slug' => 'edit.php'],
        ];

        $normalized = MetaMenuConfig::normalize($tree);

        self::assertCount(1, $normalized);
        self::assertSame('ref', $normalized[0]['type']);
    }

    public function testGroupMissingIdGetsGeneratedId(): void
    {
        $normalized = MetaMenuConfig::normalize([
            ['type' => 'group', 'label' => 'No Id', 'children' => []],
        ]);

        self::assertNotSame('', $normalized[0]['id']);
        self::assertMatchesRegularExpression('/^[a-z0-9_-]+$/', $normalized[0]['id']);
    }

    public function testMalformedNodesDroppedWithoutThrowing(): void
    {
        $tree = [
            ['type' => 'ref'],                                   // missing slug
            ['type' => 'ref', 'slug' => ''],                     // empty slug
            ['type' => 'group', 'label' => 5, 'children' => []], // non-string label
            ['type' => 'group', 'label' => 'OK', 'children' => 'nope'], // non-array children
            'not-an-array',
            ['type' => 'ref', 'slug' => 'edit.php'],             // the one valid node
        ];

        $normalized = MetaMenuConfig::normalize($tree);

        // Two nodes survive: the 'OK' group (non-array children coerced to [])
        // and the valid ref. The non-string-label group is dropped (label 5 is
        // not a string -> '' -> dropped); the malformed refs and the bare
        // string are dropped.
        self::assertCount(2, $normalized);
        self::assertSame('group', $normalized[0]['type']);
        self::assertSame('OK', $normalized[0]['label']);
        self::assertSame([], $normalized[0]['children']);
        self::assertSame('ref', $normalized[1]['type']);
        self::assertSame('edit.php', $normalized[1]['slug']);
    }

    public function testRecursionIsBoundedByMaxDepth(): void
    {
        // Build a tree deeper than MAX_DEPTH (20); the over-deep tail is pruned
        // rather than recursing without bound.
        $node = ['type' => 'ref', 'slug' => 'edit.php'];
        for ($i = 0; $i < 40; $i++) {
            $node = ['type' => 'group', 'id' => "g$i", 'label' => "G$i", 'children' => [$node]];
        }

        $normalized = MetaMenuConfig::normalize([$node]);

        // Walk down counting group levels until children empties out.
        $depth = 0;
        $cursor = $normalized[0];
        while (($cursor['type'] ?? null) === 'group' && ! empty($cursor['children'])) {
            $depth++;
            $cursor = $cursor['children'][0];
        }
        self::assertLessThanOrEqual(21, $depth, 'depth must be capped near MAX_DEPTH');
        self::assertGreaterThan(10, $depth, 'a generous depth is still allowed');
    }

    public function testLabelWithScriptIsSanitized(): void
    {
        $normalized = MetaMenuConfig::normalize([
            ['type' => 'group', 'label' => '<script>alert(1)</script>Posts', 'children' => []],
        ]);

        self::assertStringNotContainsString('<script>', $normalized[0]['label']);
        self::assertStringContainsString('Posts', $normalized[0]['label']);
    }

    public function testIconAcceptsOnlyDashiconsClass(): void
    {
        $kept = MetaMenuConfig::normalize([
            ['type' => 'group', 'label' => 'A', 'icon' => 'dashicons-admin-post', 'children' => []],
        ]);
        self::assertSame('dashicons-admin-post', $kept[0]['icon']);

        foreach (['data:image/svg+xml,<svg>', 'http://evil.test/x.png', '"><img onerror=1>', 'foo'] as $bad) {
            $rejected = MetaMenuConfig::normalize([
                ['type' => 'group', 'label' => 'A', 'icon' => $bad, 'children' => []],
            ]);
            self::assertSame('', $rejected[0]['icon'], "icon '$bad' should be rejected");
        }
    }

    public function testRefPreservesLabelIconAndChildren(): void
    {
        $normalized = MetaMenuConfig::normalize([
            [
                'type' => 'ref',
                'slug' => 'edit.php?post_type=companies',
                'label' => 'Companies',
                'icon' => 'dashicons-building',
                'children' => [
                    ['type' => 'ref', 'slug' => 'edit.php?post_type=companies', 'label' => 'List'],
                    ['type' => 'ref', 'slug' => 'post-new.php?post_type=companies', 'label' => 'Create'],
                ],
            ],
        ]);

        $ref = $normalized[0];
        self::assertSame('Companies', $ref['label']);
        self::assertSame('dashicons-building', $ref['icon']);
        self::assertCount(2, $ref['children']);
        self::assertSame('List', $ref['children'][0]['label']);
        self::assertSame('Create', $ref['children'][1]['label']);
    }

    public function testRefBadIconRejectedAndBareRefStaysMinimal(): void
    {
        $normalized = MetaMenuConfig::normalize([
            ['type' => 'ref', 'slug' => 'users.php', 'icon' => 'http://evil/x.png'],
            ['type' => 'ref', 'slug' => 'edit.php'],
        ]);

        self::assertArrayNotHasKey('icon', $normalized[0]); // bad icon dropped
        self::assertSame(['type' => 'ref', 'slug' => 'edit.php'], $normalized[1]); // bare ref minimal
    }

    public function testHideNodeKeptWithSlugDroppedWithout(): void
    {
        $normalized = MetaMenuConfig::normalize([
            ['type' => 'hide', 'slug' => 'edit-comments.php'],
            ['type' => 'hide'], // no slug -> dropped
        ]);

        self::assertCount(1, $normalized);
        self::assertSame(['type' => 'hide', 'slug' => 'edit-comments.php'], $normalized[0]);
    }

    public function testUnknownKeysStripped(): void
    {
        $normalized = MetaMenuConfig::normalize([
            ['type' => 'ref', 'slug' => 'edit.php', 'evil' => 'x', 'extra' => 1],
        ]);

        self::assertSame(['type' => 'ref', 'slug' => 'edit.php'], $normalized[0]);
    }

    public function testSaveAndLoadRoundTrip(): void
    {
        $tree = [
            ['type' => 'group', 'id' => 'pt', 'label' => 'Post Types', 'icon' => '', 'children' => [
                ['type' => 'ref', 'slug' => 'edit.php'],
            ]],
        ];

        self::assertTrue(MetaMenuConfig::save($tree));
        self::assertSame($tree, MetaMenuConfig::load());
        self::assertSame($tree, $GLOBALS['__lean_admin_test_options'][PluginConstants::OPTION_METAMENU]);
    }

    public function testLoadReturnsEmptyArrayWhenOptionMissing(): void
    {
        self::assertSame([], MetaMenuConfig::load());
    }
}
