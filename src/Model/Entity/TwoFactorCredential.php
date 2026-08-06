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
 * One account's second factor.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $secret encrypted at rest
 * @property \Cake\I18n\DateTime|null $confirmed_at
 */
class TwoFactorCredential extends Entity
{
    /**
     * Nothing is mass-assignable. Every field here is written by
     * {@see \App\Model\Table\TwoFactorCredentialsTable}, which owns the rules —
     * a request that could name `user_id` or `confirmed_at` could enrol a second
     * factor for somebody else, or confirm one nobody proved.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
    ];

    /**
     * Keep the secret out of anything that stringifies or serialises the entity
     * — debug output, an accidental `json_encode`, a stack trace in a log.
     *
     * @var list<string>
     */
    protected array $_hidden = [
        'secret',
    ];
}
