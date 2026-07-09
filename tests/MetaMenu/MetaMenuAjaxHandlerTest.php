<?php

declare(strict_types=1);

namespace LeanAdmin\Tests\MetaMenu;

use LeanAdmin\Config\PluginConstants;
use LeanAdmin\MetaMenu\MetaMenuAjaxHandler;
use LeanAdmin\MetaMenu\MetaMenuConfig;
use LeanAdmin_Test_JsonResponse;
use PHPUnit\Framework\TestCase;

final class MetaMenuAjaxHandlerTest extends TestCase
{
    private MetaMenuAjaxHandler $handler;

    protected function setUp(): void
    {
        $GLOBALS['__lean_admin_test_options'] = [];
        $GLOBALS['__lean_admin_test_nonce_valid'] = true;
        $GLOBALS['__lean_admin_test_logged_in'] = true;
        $GLOBALS['__lean_admin_test_can'] = true;
        $_POST = [];
        $_REQUEST = ['nonce' => 'test-nonce'];
        $this->handler = new MetaMenuAjaxHandler();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_REQUEST = [];
        unset($GLOBALS['__lean_admin_test_nonce_valid'], $GLOBALS['__lean_admin_test_logged_in'], $GLOBALS['__lean_admin_test_can']);
    }

    /** @return array{payload:array,status:int} */
    private function invoke(): array
    {
        try {
            $this->handler->handle_save_config();
        } catch (LeanAdmin_Test_JsonResponse $r) {
            return ['payload' => $r->payload, 'status' => $r->status];
        }
        self::fail('handler did not emit a JSON response');
    }

    public function testJsConfigUsesNamespacedAjaxActionPrefix(): void
    {
        $config = $this->handler->getJsConfig();

        self::assertSame('lean_admin_metamenu', $config['module']);
        self::assertSame(['save_config'], $config['actions']);
        self::assertSame('test-nonce', $config['nonce']);
    }

    public function testValidTreePersistsAndReturnsSuccess(): void
    {
        $_POST['tree'] = json_encode([
            ['type' => 'group', 'id' => 'pt', 'label' => 'Post Types', 'children' => [
                ['type' => 'ref', 'slug' => 'edit.php'],
            ]],
        ]);

        $result = $this->invoke();

        self::assertTrue($result['payload']['success']);
        self::assertNotEmpty($GLOBALS['__lean_admin_test_options'][PluginConstants::OPTION_METAMENU]);
        self::assertSame('edit.php', MetaMenuConfig::load()[0]['children'][0]['slug']);
    }

    public function testEmptyTreeReturnsMetamenuEmptyError(): void
    {
        $_POST['tree'] = '';

        $result = $this->invoke();

        self::assertFalse($result['payload']['success']);
        self::assertSame(400, $result['status']);
        self::assertSame('metamenu_empty', $result['payload']['data']['code']);
    }

    public function testInvalidJsonReturnsInvalidJsonError(): void
    {
        $_POST['tree'] = '{not valid json';

        $result = $this->invoke();

        self::assertFalse($result['payload']['success']);
        self::assertSame(400, $result['status']);
        self::assertSame('metamenu_invalid_json', $result['payload']['data']['code']);
    }

    public function testNonArrayJsonReturnsInvalidJsonError(): void
    {
        $_POST['tree'] = '"a string"';

        $result = $this->invoke();

        self::assertSame('metamenu_invalid_json', $result['payload']['data']['code']);
    }

    public function testBadNonceReturns403(): void
    {
        $GLOBALS['__lean_admin_test_nonce_valid'] = false;
        $_POST['tree'] = json_encode([['type' => 'ref', 'slug' => 'edit.php']]);

        $result = $this->invoke();

        self::assertFalse($result['payload']['success']);
        self::assertSame(403, $result['status']);
    }

    public function testNonAdminReturns403(): void
    {
        $GLOBALS['__lean_admin_test_can'] = false;
        $_POST['tree'] = json_encode([['type' => 'ref', 'slug' => 'edit.php']]);

        $result = $this->invoke();

        self::assertSame(403, $result['status']);
    }
}
