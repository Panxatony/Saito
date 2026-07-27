<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller\Admin;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Saito\Test\IntegrationTestCase;
use Saito\User\Permission\ResourceAI;
use Saito\User\SaitoUser;

/**
 * Class UsersControllerTest
 *
 * @package App\Test\TestCase\Controller\Admin
 */
class UsersControllerTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserRead',
        'app.UserOnline',
    ];

    protected $Users;

    public function setUp(): void
    {
        parent::setUp();
        foreach (['Users'] as $table) {
            $this->$table = TableRegistry::getTableLocator()->get($table);
        }
    }

    public function testUsersIndexAccess()
    {
        $this->assertRouteForRole('/admin/users/block', 'admin');
    }

    /**
     * The block list offers "unblock" links, and they have to point at an
     * action that exists. They did not: the template reset the route with
     * `'admin' => false`, a CakePHP 2/3 prefix idiom that no longer resets
     * anything, so from inside the Admin plugin the link built
     * `/admin/users/unlock/<id>` — which this controller has never had.
     * Unblocking from the backend was quietly broken.
     *
     * @return void
     */
    public function testUnblockLinksPointAtALiveAction()
    {
        $this->_loginUser(1);
        $this->get('/admin/users/block');
        $this->assertResponseOk();

        preg_match_all('#action="([^"]*unlock[^"]*)"#', (string)$this->_response->getBody(), $matches);
        $this->assertNotEmpty($matches[1], 'the block list offered no unblock link at all');

        foreach ($matches[1] as $url) {
            $this->assertStringStartsWith(
                '/users/unlock/',
                $url,
                'unblock points into the Admin plugin, where the action does not exist'
            );
        }
    }

    /**
     * Role and account deletion moved here when the SPA was retired. They used
     * to hang off the forum's own profile page — the only way to reach them —
     * so the island frontend had left the forum with no way to appoint a
     * moderator or remove an account at all.
     *
     * @return void
     */
    public function testRoleRequiresTheBackend()
    {
        $this->assertRouteForRole('/admin/users/role/3', 'admin');
    }

    /**
     * @return void
     */
    public function testDeleteRequiresTheBackend()
    {
        $this->assertRouteForRole('/admin/users/delete/3', 'admin');
    }

    /**
     * The form offers exactly the roles the current user may assign — an admin
     * must not be able to hand out `owner`.
     *
     * @return void
     */
    public function testRoleFormOffersOnlyAssignableRoles()
    {
        $this->_loginUser(1);
        $this->get('/admin/users/role/3');

        $this->assertResponseOk();
        $this->assertResponseContains('user_type');
        $this->assertResponseNotContains('value="owner"');
    }

    /**
     * The happy path: promote a plain member to moderator.
     *
     * @return void
     */
    public function testRoleIsChanged()
    {
        $this->_loginUser(1);
        $this->post('/admin/users/role/3', ['user_type' => 'mod']);

        $this->assertRedirect('/admin/users');
        $this->assertSame('mod', $this->Users->get(3)->get('user_type'));
    }

    /**
     * `user_type` is guarded against mass assignment on the entity; this action
     * is the one authorized path around that guard. A role the current user may
     * not assign has to be refused rather than quietly written.
     *
     * @return void
     */
    public function testRoleRejectsARoleTheUserMayNotAssign()
    {
        $this->_loginUser(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->post('/admin/users/role/3', ['user_type' => 'owner']);
    }

    /**
     * A GET must not change anything — otherwise a crawler or a prefetching
     * browser could promote users by following a link.
     *
     * @return void
     */
    public function testRoleGetDoesNotChangeAnything()
    {
        $this->_loginUser(1);
        $this->get('/admin/users/role/3');

        $this->assertSame('user', $this->Users->get(3)->get('user_type'));
    }

    /**
     * Deleting needs the explicit confirmation the form asks for. Without it
     * the request is a no-op, not a deletion.
     *
     * @return void
     */
    public function testDeleteWithoutConfirmationKeepsTheAccount()
    {
        $this->_loginUser(1);
        $this->post('/admin/users/delete/3', []);

        $this->assertNotEmpty($this->Users->find()->where(['id' => 3])->first());
    }

    /**
     * Likewise a GET only renders the confirmation page.
     *
     * @return void
     */
    public function testDeleteGetOnlyShowsTheForm()
    {
        $this->_loginUser(1);
        $this->get('/admin/users/delete/3');

        $this->assertResponseOk();
        $this->assertResponseContains('userdeleteconfirm');
        $this->assertNotEmpty($this->Users->find()->where(['id' => 3])->first());
    }

    /**
     * An admin may delete moderators and members, not their peers — so deleting
     * another admin, or themselves, is already stopped by the permission check,
     * before the self-deletion guard is ever reached.
     *
     * @return void
     */
    public function testAdminCannotDeleteAnAdmin()
    {
        $this->_loginUser(1);

        $this->expectException(\Saito\Exception\SaitoForbiddenException::class);
        $this->post('/admin/users/delete/6', ['userdeleteconfirm' => 1]);
    }

    /**
     * Moderators must not delete accounts. They are kept out twice over — the
     * backend is admin-only, and the permission itself no longer grants it — so
     * this stays true even if the action is ever reachable from elsewhere.
     *
     * @return void
     */
    public function testModeratorCannotDelete()
    {
        $this->assertFalse(
            $this->mayDeleteAsRole('mod'),
            'a moderator is allowed to delete an account'
        );
    }

    /**
     * The counterpart, so the test above cannot pass by simply being wrong
     * about how the permission is asked.
     *
     * @return void
     */
    public function testAdminMayDelete()
    {
        $this->assertTrue(
            $this->mayDeleteAsRole('admin'),
            'an admin is not allowed to delete an account'
        );
    }

    /**
     * May somebody in $role delete a plain member's account?
     *
     * @param string $role the acting role
     * @return bool
     */
    private function mayDeleteAsRole(string $role): bool
    {
        $identifier = (new ResourceAI())
            ->asUser(new SaitoUser(['user_type' => $role]))
            ->onRole('user');

        return Configure::read('Saito.Permission.Resources')
            ->get('saito.core.user.delete')
            ->check($identifier);
    }

    /**
     * The owner may delete any role, which makes them the one user who reaches
     * the self-deletion guard. It has to hold: deleting yourself would lock the
     * forum's owner out of their own forum.
     *
     * @return void
     */
    public function testOwnerCannotDeleteThemselves()
    {
        $this->_loginUser(11);
        $this->post('/admin/users/delete/11', ['userdeleteconfirm' => 1]);

        $this->assertNotEmpty(
            $this->Users->find()->where(['id' => 11])->first(),
            'the owner deleted themselves'
        );
    }

    /**
     * The happy path. Postings survive the account on purpose — discussions
     * others took part in stay readable.
     *
     * @return void
     */
    public function testDeleteRemovesTheAccount()
    {
        $this->_loginUser(1);
        $this->post('/admin/users/delete/3', ['userdeleteconfirm' => 1]);

        $this->assertRedirect('/admin/users');
        $this->assertEmpty($this->Users->find()->where(['id' => 3])->first());
    }
}
