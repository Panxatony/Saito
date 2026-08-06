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
 * One single-use recovery code, stored as a hash.
 *
 * @property int $id
 * @property int $user_id
 * @property string $code_hash
 * @property \Cake\I18n\DateTime|null $used_at
 */
class TwoFactorRecoveryCode extends Entity
{
    /**
     * Nothing is mass-assignable — see {@see TwoFactorCredential}. A request
     * that could name `used_at` could un-burn a code it already spent.
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
        'code_hash',
    ];
}
