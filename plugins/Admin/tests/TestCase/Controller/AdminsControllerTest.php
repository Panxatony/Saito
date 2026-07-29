<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Http\Exception\MethodNotAllowedException;
use Saito\Exception\SaitoForbiddenException;
use Saito\Test\IntegrationTestCase;

/**
 * App\Controller\ToolsController Test Case
 */
class AdminsControllerTest extends IntegrationTestCase
{
    /**
     * Fixtures
     *
     * @var array
     */
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserOnline',
        'app.UserRead',
    ];

    /**
     * testAdminEmptyCaches method
     *
     * @return void
     */
    public function testAdminEmptyCachesNonAdmin()
    {
        $url = '/admin/admins/emptyCaches';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }

    public function testAdminEmptyCachesUser()
    {
        $this->_loginUser(2);
        $url = '/admin/admins/emptyCaches';
        $this->expectException(SaitoForbiddenException::class);
        $this->get($url);
    }

    public function testAdminEmptyCaches()
    {
        $this->_loginUser(1);
        Cache::write('foo', 'bar');
        $this->assertEquals('bar', Cache::read('foo'));
        $this->post('admin/admins/emptyCaches');
        $this->assertEmpty(Cache::read('foo'));
    }

    /**
     * Flushing the caches is a state change and must not answer to a GET —
     * otherwise an image tag on any page an admin opens can fire it, as often
     * as it likes, and CSRF protection never sees the request.
     *
     * @return void
     */
    public function testAdminEmptyCachesRejectsGet()
    {
        $this->_loginUser(1);
        Cache::write('foo', 'bar');

        $this->expectException(MethodNotAllowedException::class);
        $this->get('admin/admins/emptyCaches');
    }

    /**
     * Admin dashboard renders the system-info panel.
     *
     * Guards the Cake 5 cache-config rename: the panel calls
     * badgeForCache('_cake_translations_') (was '_cake_core_' in Cake 4);
     * the old name no longer exists and threw a 500 on /admin/.
     */
    public function testAdminIndexRendersSystemInfo()
    {
        $this->_loginUser(1);
        $this->get('/admin/');
        $this->assertResponseOk();
        $this->assertResponseContains('PHP Info');
    }

    public function testPhpInfoUserAllowence()
    {
        $this->assertRouteForRole('/admin/admins/phpinfo', 'admin');
    }
}
