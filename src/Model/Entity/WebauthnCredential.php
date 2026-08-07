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
 * One registered passkey.
 *
 * @property int $id
 * @property int $user_id
 * @property string $credential_id
 * @property string $credential
 * @property int $sign_count
 * @property string|null $label
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $last_used_at
 */
class WebauthnCredential extends Entity
{
    /**
     * The label alone is mass-assignable — it is the one field that is the
     * member's to write. Everything else comes out of a completed ceremony, and
     * a request that could name `credential` or `sign_count` could replace the
     * key the signature is checked against, or wind the clone detector back.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
        'label' => true,
    ];

    /**
     * The serialised record never leaves the server.
     *
     * @var list<string>
     */
    protected array $_hidden = [
        'credential',
    ];
}
