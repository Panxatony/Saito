<?php
declare(strict_types=1);

namespace App\Test\TestCase\Entity;

use Cake\ORM\TableRegistry;
use Saito\Test\SaitoTestCase;

class UserTest extends SaitoTestCase
{
    public array $fixtures = ['app.Category', 'app.User'];

    public function testNumberOfPostings()
    {
        $Users = TableRegistry::getTableLocator()->get('Users');

        //= zero entries
        $user = $Users->get(4);
        $expected = 0;
        $result = $user->numberOfPostings();
        $this->assertEquals($expected, $result);

        //= multiple entries
        $Users->updateAll(['entry_count' => 101], ['id' => 3]);
        $user = $Users->get(3);
        $expected = 101;
        $result = $user->numberOfPostings();
        $this->assertEquals($expected, $result);
    }

    /**
     * A bare `patchEntity($user, $data)` (no `fields` whitelist) must NOT be
     * able to change the role or the other guarded columns — otherwise a
     * request body carrying `user_type` would be an instant privilege
     * escalation. See App\Model\Entity\User::$_accessible.
     */
    public function testGuardedFieldsAreNotMassAssignable()
    {
        $Users = TableRegistry::getTableLocator()->get('Users');
        $user = $Users->get(3);
        $originalType = $user->get('user_type');

        $Users->patchEntity($user, [
            'user_type' => 'admin',
            'activate_code' => 42,
            'user_lock' => true,
            'id' => 9999,
        ]);

        $this->assertSame($originalType, $user->get('user_type'), 'role must not be mass-assignable');
        $this->assertNotSame(42, $user->get('activate_code'), 'activate_code must not be mass-assignable');
        $this->assertNotSame(true, $user->get('user_lock'), 'user_lock must not be mass-assignable');
        $this->assertSame(3, $user->get('id'), 'id must not be mass-assignable');
    }

    /**
     * The authorized role-change path opts the field back in explicitly via
     * `accessibleFields` (see UsersController::role()), which must still work.
     */
    public function testRoleIsAssignableWithExplicitAccessibleFields()
    {
        $Users = TableRegistry::getTableLocator()->get('Users');
        $user = $Users->get(3);

        $Users->patchEntity(
            $user,
            ['user_type' => 'mod'],
            ['accessibleFields' => ['user_type' => true]],
        );

        $this->assertSame('mod', $user->get('user_type'));
    }

    /**
     * Non-sensitive columns stay freely assignable (the guard only denies the
     * privilege/security columns, keeping the framework's open default for the
     * rest so existing hand-built `$data` patches keep working).
     */
    public function testOrdinaryFieldsRemainMassAssignable()
    {
        $Users = TableRegistry::getTableLocator()->get('Users');
        $user = $Users->get(3);

        $Users->patchEntity($user, ['username' => 'Renamed_User']);

        $this->assertSame('Renamed_User', $user->get('username'));
    }
}
