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
 * Password reset token.
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property \Cake\I18n\DateTime $expires
 * @property \Cake\I18n\DateTime $created
 */
class PasswordResetToken extends Entity
{
    /**
     * Nothing here is ever set from request data — the fields are written only
     * by `PasswordResetTokensTable::issueFor()`, which opens them explicitly via
     * `accessibleFields`. Closed by default so a stray patch cannot forge a
     * token row.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'user_id' => false,
        'token_hash' => false,
        'expires' => false,
        'created' => false,
    ];

    /**
     * Keep the hash out of array/JSON casts — it should never be rendered.
     *
     * @var list<string>
     */
    protected array $_hidden = [
        'token_hash',
    ];
}
