<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Model\Entity;

use Cake\ORM\Entity;
use Saito\User\ForumsUserInterface;
use Saito\User\ForumsUserTrait;

class User extends Entity implements ForumsUserInterface
{
    use ForumsUserTrait;

    /**
     * Mass-assignment guard.
     *
     * The framework default is fully-open (`'*' => true`), so a
     * `patchEntity($user, $requestData)` that forgets a `fields` whitelist
     * would let a user set ANY column on themselves — including their own
     * role. Deny the privilege- and security-relevant columns by default so
     * such a slip cannot escalate:
     *  - `user_type`     — the role (user/mod/owner)
     *  - `activate_code` — account-activation gate
     *  - `user_lock`     — the locked/blocked flag
     *  - `id`            — the identity itself
     *
     * The legitimate writers of these columns are unaffected: they either use
     * `->set()` (which is unguarded) or pass an explicit `fields` /
     * `accessibleFields` option — both bypass this guard. Only the accidental
     * bulk-assignment path is closed. Everything else stays assignable via the
     * retained `'*' => true`.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => true,
        'id' => false,
        'user_type' => false,
        'activate_code' => false,
        'user_lock' => false,
    ];
}
