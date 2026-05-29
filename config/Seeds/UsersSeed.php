<?php
use Migrations\BaseSeed;

/**
 * Users seed.
 */
class UsersSeed extends BaseSeed
{
    /**
     * Run Method.
     *
     * Write your database seeder using this method.
     *
     * More information on writing seeds is available here:
     * http://docs.phinx.org/en/latest/seeding.html
     *
     * @return void
     */
    public function run(): void
    {
        $data = [
                'id' => 1,
                'user_type' => 'owner',
                'username' => '__set_by_installer__',
                'password' => '__set_by_installer__',
                'activate_code' => '0',
                'registered' => date('Y-m-d H:i:s'),
        ];

        $table = $this->table('users');
        $table->insert($data)->save();
    }
}
