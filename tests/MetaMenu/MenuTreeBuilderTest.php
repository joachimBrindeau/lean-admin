<?php

declare(strict_types=1);

namespace LeanAdmin\Tests\MetaMenu;

use LeanAdmin\MetaMenu\MenuTreeBuilder;
use PHPUnit\Framework\TestCase;

final class MenuTreeBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['menu'] = [
            ['Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard', 'dashicons-dashboard'],
            ['Posts', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts', 'dashicons-admin-post'],
            ['Pages', 'edit_pages', 'edit.php?post_type=page', '', 'menu-top', 'menu-pages', 'dashicons-admin-page'],
            ['Users', 'list_users', 'users.php', '', 'menu-top', 'menu-users', 'dashicons-admin-users'],
        ];
        $GLOBALS['submenu'] = [
            'edit.php' => [
                ['All Posts', 'edit_posts', 'edit.php'],
                ['Categories', 'manage_categories', 'edit-tags.php?taxonomy=category'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['menu'], $GLOBALS['submenu'], $GLOBALS['pagenow'], $_GET['page'], $_GET['post_type']);
    }

    /** @return array<int, array<string, mixed>> */
    private function postTypesConfig(): array
    {
        return [
            [
                'type' => 'group',
                'id' => 'post-types',
                'label' => 'Post Types',
                'icon' => 'dashicons-admin-post',
                'children' => [
                    ['type' => 'ref', 'slug' => 'edit.php'],
                    ['type' => 'ref', 'slug' => 'edit.php?post_type=page'],
                    ['type' => 'ref', 'slug' => 'users.php'],
                ],
            ],
        ];
    }

    public function testBuildTreeResolvesRefsWithOriginalHrefsAndNativeChildren(): void
    {
        $tree = MenuTreeBuilder::buildTree($this->postTypesConfig());

        self::assertCount(1, $tree);
        $group = $tree[0];
        self::assertSame('group', $group['type']);
        self::assertCount(3, $group['children']);

        $posts = $group['children'][0];
        self::assertSame('edit.php', $posts['slug']);
        self::assertStringContainsString('edit.php', $posts['href']); // routing unchanged (R5)
        self::assertCount(2, $posts['children']);                     // native 3rd level
        self::assertSame('Categories', $posts['children'][1]['label']);
    }

    public function testBuildTreeDropsRefsThatResolveToNull(): void
    {
        $config = [[
            'type' => 'group', 'id' => 'g', 'label' => 'G', 'icon' => '',
            'children' => [
                ['type' => 'ref', 'slug' => 'edit.php'],
                ['type' => 'ref', 'slug' => 'admin.php?page=ghost'], // absent → dropped
            ],
        ]];

        $tree = MenuTreeBuilder::buildTree($config);

        self::assertCount(1, $tree[0]['children']);
        self::assertSame('edit.php', $tree[0]['children'][0]['slug']);
    }

    public function testReconcileDropsDeadRefsAndEmptyGroups(): void
    {
        $config = [[
            'type' => 'group', 'id' => 'g', 'label' => 'G', 'children' => [
                ['type' => 'ref', 'slug' => 'admin.php?page=ghost'],
            ],
        ]];

        self::assertSame([], MenuTreeBuilder::reconcileConfig($config));
    }

    public function testReconcilePromotesValidChildrenOfMissingParent(): void
    {
        $config = [[
            'type' => 'ref', 'slug' => 'admin.php?page=missing-parent', 'children' => [
                ['type' => 'ref', 'slug' => 'edit.php'],
                ['type' => 'ref', 'slug' => 'admin.php?page=ghost'],
            ],
        ]];

        self::assertSame(
            [['type' => 'ref', 'slug' => 'edit.php']],
            MenuTreeBuilder::reconcileConfig($config)
        );
    }

    public function testTopLevelGroupsGetPositions(): void
    {
        $tree = MenuTreeBuilder::buildTree($this->postTypesConfig());

        self::assertArrayHasKey('position', $tree[0]);
        self::assertGreaterThan(2, $tree[0]['position']);
    }

    public function testHideHrefsIncludesGroupedAndExplicitHideEntries(): void
    {
        $config = $this->postTypesConfig();
        $config[] = ['type' => 'hide', 'slug' => 'edit-comments.php'];

        self::assertSame(
            [
                'http://example.test/wp-admin/edit.php',
                'http://example.test/wp-admin/edit.php?post_type=page',
                'http://example.test/wp-admin/users.php',
                'http://example.test/wp-admin/edit-comments.php',
            ],
            MenuTreeBuilder::hideHrefs($config)
        );
    }

    public function testMarkActiveFlagsMatchingLink(): void
    {
        $tree = MenuTreeBuilder::buildTree($this->postTypesConfig());
        $marked = MenuTreeBuilder::markActive($tree, 'users.php');

        $byslug = [];
        foreach ($marked[0]['children'] as $child) {
            $byslug[$child['slug']] = $child['active'];
        }

        self::assertTrue($byslug['users.php']);
        self::assertFalse($byslug['edit.php']);
    }

    public function testCurrentMenuSlugForPluginPage(): void
    {
        $_GET['page'] = 'lean-admin';
        self::assertSame('lean-admin', MenuTreeBuilder::currentMenuSlug());
    }

    public function testCurrentMenuSlugForPostTypeListing(): void
    {
        $GLOBALS['pagenow'] = 'edit.php';
        $_GET['post_type'] = 'page';
        self::assertSame('edit.php?post_type=page', MenuTreeBuilder::currentMenuSlug());
    }

    public function testCurrentMenuSlugForPlainPostsScreen(): void
    {
        $GLOBALS['pagenow'] = 'edit.php';
        self::assertSame('edit.php', MenuTreeBuilder::currentMenuSlug());
    }

    public function testCurrentMenuSlugForNewPostTypePage(): void
    {
        $GLOBALS['pagenow'] = 'post-new.php';
        $_GET['post_type'] = 'product';
        self::assertSame('post-new.php?post_type=product', MenuTreeBuilder::currentMenuSlug());
    }

    public function testBuildRefAppliesLabelIconAndExplicitLeaves(): void
    {
        // post-new.php?post_type=page lives as a submenu item; ensure it resolves.
        $GLOBALS['submenu']['edit.php?post_type=page'] = [
            ['Add New', 'edit_pages', 'post-new.php?post_type=page'],
        ];

        $config = [[
            'type' => 'group', 'id' => 'pt', 'label' => 'Post Types', 'children' => [
                [
                    'type' => 'ref',
                    'slug' => 'edit.php?post_type=page',
                    'label' => 'Pages',
                    'icon' => 'dashicons-admin-page',
                    'children' => [
                        ['type' => 'ref', 'slug' => 'edit.php?post_type=page', 'label' => 'List'],
                        ['type' => 'ref', 'slug' => 'post-new.php?post_type=page', 'label' => 'Create'],
                        ['type' => 'ref', 'slug' => 'admin.php?page=ghost', 'label' => 'Edit'], // unresolvable -> dropped
                    ],
                ],
            ],
        ]];

        $ref = MenuTreeBuilder::buildTree($config)[0]['children'][0];

        self::assertSame('Pages', $ref['label']);
        self::assertSame('dashicons-admin-page', $ref['icon']);          // icon override
        $labels = array_column($ref['children'], 'label');
        self::assertSame(['List', 'Create'], $labels);                   // explicit leaves; ghost dropped (cap-safe)
    }

    public function testConfigRefSlugsWalksNestedGroups(): void
    {
        $config = [[
            'type' => 'group', 'id' => 'a', 'label' => 'A', 'children' => [
                ['type' => 'ref', 'slug' => 'edit.php'],
                ['type' => 'group', 'id' => 'b', 'label' => 'B', 'children' => [
                    ['type' => 'ref', 'slug' => 'users.php'],
                ]],
            ],
        ]];

        self::assertSame(['edit.php', 'users.php'], MenuTreeBuilder::configRefSlugs($config));
    }

    public function testConfigRefSlugsWalksRefChildren(): void
    {
        $config = [[
            'type' => 'group', 'id' => 'settings', 'label' => 'Settings', 'children' => [
                ['type' => 'ref', 'slug' => 'tools.php', 'children' => [
                    ['type' => 'ref', 'slug' => 'emcp-tools'],
                ]],
            ],
        ]];

        self::assertSame(['tools.php', 'emcp-tools'], MenuTreeBuilder::configRefSlugs($config));
    }

    public function testConfigHideSlugsCollectsHideNodes(): void
    {
        $config = [
            ['type' => 'hide', 'slug' => 'edit-comments.php'],
            ['type' => 'group', 'id' => 'g', 'label' => 'G', 'children' => [
                ['type' => 'ref', 'slug' => 'edit.php'],
                ['type' => 'hide', 'slug' => 'link-manager.php'],
            ]],
        ];

        self::assertSame(['edit-comments.php', 'link-manager.php'], MenuTreeBuilder::configHideSlugs($config));
    }

    public function testDedupeDropsMirrorLeafButKeepsSelfLink(): void
    {
        // A group with the profile CPT as a ref AND Users as a ref whose native
        // submenu mirrors the profile listing ("Profiles (Voxel)"). After
        // dedupe, the mirror leaf under Users is gone, but Posts' own "All
        // Posts" self-link (same href as the Posts ref) survives.
        $tree = [[
            'type' => 'group', 'id' => 'g', 'label' => 'G', 'icon' => '', 'position' => 3,
            'children' => [
                ['type' => 'link', 'slug' => 'edit.php', 'href' => 'edit.php', 'active' => false, 'children' => [
                    ['type' => 'link', 'href' => 'edit.php', 'active' => false], // All Posts self-link -> keep
                ]],
                ['type' => 'link', 'slug' => 'edit.php?post_type=profile', 'href' => 'edit.php?post_type=profile', 'active' => false, 'children' => []],
                ['type' => 'link', 'slug' => 'users.php', 'href' => 'users.php', 'active' => false, 'children' => [
                    ['type' => 'link', 'href' => 'users.php', 'active' => false],                 // All Users self-link -> keep
                    ['type' => 'link', 'href' => 'edit.php?post_type=profile', 'active' => false], // Profiles (Voxel) mirror -> drop
                ]],
            ],
        ]];

        $deduped = MenuTreeBuilder::dedupeGroupedHrefs($tree);
        $children = $deduped[0]['children'];

        // Posts keeps its self-link.
        self::assertCount(1, $children[0]['children']);
        // Users keeps All Users, drops the profile mirror.
        $usersHrefs = array_column($children[2]['children'], 'href');
        self::assertSame(['users.php'], $usersHrefs);
    }

    public function testBuildTreeTitleCasesGroupRefAndNativeChildLabels(): void
    {
        // Native labels that come in ALL-CAPS and all-lowercase must be
        // normalized to Title Case at every level of the managed flyout tree.
        $GLOBALS['menu'] = [
            ['ELEMENTOR', 'edit_posts', 'admin.php?page=elementor', '', 'menu-top', 'menu-elementor', 'dashicons-admin-generic'],
        ];
        $GLOBALS['submenu'] = [
            'admin.php?page=elementor' => [
                ['get started', 'edit_posts', 'admin.php?page=elementor-getting-started'],
                ['ROLE MANAGER', 'edit_posts', 'admin.php?page=elementor-role-manager'],
            ],
        ];

        $config = [[
            'type' => 'group', 'id' => 'g', 'label' => 'SITE TOOLS', 'icon' => '',
            'children' => [
                ['type' => 'ref', 'slug' => 'admin.php?page=elementor'],
            ],
        ]];

        $tree = MenuTreeBuilder::buildTree($config);

        // Group label Title-Cased.
        self::assertSame('Site Tools', $tree[0]['label']);

        // Ref label Title-Cased (native ALL-CAPS "ELEMENTOR" -> "Elementor").
        $ref = $tree[0]['children'][0];
        self::assertSame('Elementor', $ref['label']);

        // Native submenu child labels Title-Cased at the leaf level.
        $childLabels = array_column($ref['children'], 'label');
        self::assertSame(['Get Started', 'Role Manager'], $childLabels);
    }

    public function testBuildTreeTitleCasesExplicitLeafLabels(): void
    {
        $GLOBALS['submenu']['edit.php?post_type=page'] = [
            ['Add New', 'edit_pages', 'post-new.php?post_type=page'],
        ];

        $config = [[
            'type' => 'group', 'id' => 'pt', 'label' => 'Post Types', 'children' => [
                [
                    'type' => 'ref',
                    'slug' => 'edit.php?post_type=page',
                    'label' => 'PAGES',
                    'children' => [
                        ['type' => 'ref', 'slug' => 'edit.php?post_type=page', 'label' => 'all pages'],
                        ['type' => 'ref', 'slug' => 'post-new.php?post_type=page', 'label' => 'CREATE'],
                    ],
                ],
            ],
        ]];

        $ref = MenuTreeBuilder::buildTree($config)[0]['children'][0];

        self::assertSame('Pages', $ref['label']);                        // ref label override Title-Cased
        self::assertSame(['All Pages', 'Create'], array_column($ref['children'], 'label')); // explicit leaves Title-Cased
    }

    public function testTitleCaseNormalizesMixedCasingAndPreservesSeparators(): void
    {
        self::assertSame('Elementor', MenuTreeBuilder::titleCase('ELEMENTOR'));
        self::assertSame('Woocommerce', MenuTreeBuilder::titleCase('woocommerce'));
        self::assertSame('Yoast Seo', MenuTreeBuilder::titleCase('Yoast SEO'));
        self::assertSame('Wp-Rocket', MenuTreeBuilder::titleCase('WP-ROCKET'));
        self::assertSame('— User Profiles', MenuTreeBuilder::titleCase('— user profiles'));
        self::assertSame('', MenuTreeBuilder::titleCase(''));
    }

    public function testMarkActiveFlagsRefInsideNestedSubgroup(): void
    {
        $config = [[
            'type' => 'group', 'id' => 'outer', 'label' => 'Outer', 'icon' => '',
            'children' => [
                ['type' => 'group', 'id' => 'inner', 'label' => 'Inner', 'icon' => '', 'children' => [
                    ['type' => 'ref', 'slug' => 'edit.php'],
                    ['type' => 'ref', 'slug' => 'users.php'],
                ]],
            ],
        ]];

        $tree = MenuTreeBuilder::buildTree($config);
        $marked = MenuTreeBuilder::markActive($tree, 'users.php');

        // outer group -> inner group -> [edit.php, users.php]
        $innerChildren = $marked[0]['children'][0]['children'];
        $byslug = [];
        foreach ($innerChildren as $child) {
            $byslug[$child['slug']] = $child['active'];
        }
        self::assertTrue($byslug['users.php']);
        self::assertFalse($byslug['edit.php']);
    }
}
