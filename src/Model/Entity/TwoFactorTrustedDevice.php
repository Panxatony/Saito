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

/**
 * One device that has proved the second factor, stored as a hash.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property \Cake\I18n\DateTime $expires
 */
class TwoFactorTrustedDevice extends Entity
{
    /**
     * Nothing is mass-assignable — see {@see TwoFactorCredential}. A request
     * that could name `expires` could keep a device trusted forever, and one
     * that could name `user_id` could point a device it holds at another
     * account.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];

    /**
     * @var list<string>
     */
    protected array $_hidden = [
        'token_hash',
    ];
}
