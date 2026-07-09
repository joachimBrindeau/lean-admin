<?php

declare(strict_types=1);

namespace LeanAdmin\Tests\MetaMenu;

use LeanAdmin\MetaMenu\MenuRegistry;
use PHPUnit\Framework\TestCase;

final class MenuRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        // WordPress menu-row shape: [0]=title, [1]=cap, [2]=slug, [3]=page_title,
        // [4]=classes, [5]=hookname/id, [6]=icon.
        $GLOBALS['menu'] = [
            ['Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard', 'dashicons-dashboard'],
            ['', 'read', 'separator1', '', 'wp-menu-separator', '', ''],
            ['Posts <span class="awaiting-mod">3</span>', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts', 'dashicons-admin-post'],
            ['Pages', 'edit_pages', 'edit.php?post_type=page', '', 'menu-top', 'menu-pages', 'dashicons-admin-page'],
            ['Users', 'list_users', 'users.php', '', 'menu-top', 'menu-users', 'dashicons-admin-users'],
        ];

        $GLOBALS['submenu'] = [
            'edit.php' => [
                ['All Posts', 'edit_posts', 'edit.php'],
                ['Categories', 'manage_categories', 'edit-tags.php?taxonomy=category'],
                ['Tags', 'manage_categories', 'edit-tags.php?taxonomy=post_tag'],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['menu'], $GLOBALS['submenu']);
    }

    public function testGetAvailableItemsReturnsRealEntries(): void
    {
        $items = MenuRegistry::getAvailableItems();
        $slugs = array_column($items, 'slug');

        self::assertSame(['index.php', 'edit.php', 'edit.php?post_type=page', 'users.php'], $slugs);
    }

    public function testSeparatorRowsSkipped(): void
    {
        $slugs = array_column(MenuRegistry::getAvailableItems(), 'slug');

        self::assertNotContains('separator1', $slugs);
    }

    public function testCountBubblesStrippedFromTitle(): void
    {
        $items = MenuRegistry::getAvailableItems();
        $posts = $items[array_search('edit.php', array_column($items, 'slug'), true)];

        self::assertStringNotContainsString('<span', $posts['title']);
        self::assertStringContainsString('Posts', $posts['title']);
    }

    public function testTitlesDecodeEntitiesAndDropCountBubbles(): void
    {
        $GLOBALS['menu'] = [
            ['Plugins <span class="update-plugins count-0"><span class="plugin-count">0</span></span>', 'activate_plugins', 'plugins.php', '', 'menu-top', 'menu-plugins', 'dashicons-admin-plugins'],
            ['&mdash; User profiles', 'edit_posts', 'edit.php?post_type=profile', '', 'menu-top', 'menu-posts-profile', ''],
        ];

        $bySlug = [];
        foreach (MenuRegistry::getAvailableItems() as $i) {
            $bySlug[$i['slug']] = $i['title'];
        }

        self::assertSame('Plugins', $bySlug['plugins.php']); // count bubble dropped
        self::assertSame('— User profiles', $bySlug['edit.php?post_type=profile']); // &mdash; decoded
    }

    public function testResolveRefReturnsHrefAndNativeChildren(): void
    {
        $node = MenuRegistry::resolveRef('edit.php');

        self::assertNotNull($node);
        self::assertStringContainsString('edit.php', $node['href']);
        self::assertSame('edit_posts', $node['capability']);
        self::assertCount(3, $node['children']);
        self::assertSame('Categories', $node['children'][1]['label']);
        self::assertStringContainsString('taxonomy=category', $node['children'][1]['href']);
    }

    public function testResolveRefUsesParentFileForBareSubmenuPageSlugs(): void
    {
        $GLOBALS['submenu']['edit.php'][] = ['Edit post type', 'manage_options', 'edit-post-type-post'];
        $GLOBALS['menu'][] = ['Services', 'edit_posts', 'edit.php?post_type=services', '', 'menu-top', 'menu-services', ''];
        $GLOBALS['submenu']['edit.php?post_type=services'] = [
            ['Edit post type', 'manage_options', 'edit-post-type-services'],
        ];

        $posts = MenuRegistry::resolveRef('edit.php');
        $services = MenuRegistry::resolveRef('edit.php?post_type=services');

        self::assertNotNull($posts);
        self::assertNotNull($services);
        $postChildren = $posts['children'];
        self::assertSame('http://example.test/wp-admin/edit.php?page=edit-post-type-post', end($postChildren)['href']);
        self::assertSame('http://example.test/wp-admin/edit.php?post_type=services&page=edit-post-type-services', $services['children'][0]['href']);
    }

    public function testResolveRefReturnsNullForAbsentSlug(): void
    {
        self::assertNull(MenuRegistry::resolveRef('admin.php?page=does-not-exist'));
    }

    public function testResolveRefFallsBackToSubmenuForTaxonomyScreens(): void
    {
        // A taxonomy screen lives as a submenu item, not a top-level menu.
        $GLOBALS['submenu']['edit.php?post_type=companies'] = [
            ['Company types', 'manage_categories', 'edit-tags.php?taxonomy=companies-types&post_type=companies'],
        ];

        $node = MenuRegistry::resolveRef('edit-tags.php?taxonomy=companies-types&post_type=companies');

        self::assertNotNull($node);
        self::assertSame('Company types', $node['label']);
        self::assertSame('manage_categories', $node['capability']);
        self::assertSame([], $node['children']);
    }

    public function testResolveRefWithoutSubmenuHasEmptyChildren(): void
    {
        $node = MenuRegistry::resolveRef('users.php');

        self::assertNotNull($node);
        self::assertSame([], $node['children']);
    }

    public function testResolveRefSupportsVoxelEditPostTypeVirtualSlug(): void
    {
        $node = MenuRegistry::resolveRef('edit-post-type-services');

        self::assertNotNull($node);
        self::assertSame('manage_options', $node['capability']);
        self::assertSame('http://example.test/wp-admin/edit.php?post_type=services&page=edit-post-type-services', $node['href']);
    }

    public function testResolveRefSupportsEmcpToolsVirtualSlug(): void
    {
        $node = MenuRegistry::resolveRef('emcp-tools');

        self::assertNotNull($node);
        self::assertSame('EMCP Tools', $node['label']);
        self::assertSame('manage_options', $node['capability']);
        self::assertSame('http://example.test/wp-admin/admin.php?page=emcp-tools', $node['href']);
    }

    public function testResolveRefUsesNativeVoxelPostEditSlug(): void
    {
        $node = MenuRegistry::resolveRef('edit-post-type-post');

        self::assertNotNull($node);
        self::assertSame('http://example.test/wp-admin/edit.php?page=edit-post-type-post', $node['href']);
    }

}
