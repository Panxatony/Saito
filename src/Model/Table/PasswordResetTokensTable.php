<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\Utility\Security;

/**
 * Single-use, time-limited tokens for the self-service password reset (#63).
 *
 * The raw token exists only for the moment it takes to email it — it is
 * returned by {@see self::issueFor()} to the caller and never stored. What this
 * table keeps is its SHA-256, so the row is worthless to anyone who reads it.
 * Lookups hash the presented token and match against that column.
 *
 * SHA-256 without a per-row salt is deliberate and sufficient here: the token
 * is 256 bits of `random_bytes`, so it cannot be brute-forced or guessed, and
 * an unsalted hash of an unguessable value gives an attacker nothing a rainbow
 * table could help with. (This is the standard reset-token design, and unlike a
 * password there is no low-entropy input to protect.)
 *
 * @property \App\Model\Table\UsersTable&\Cake\ORM\Association\BelongsTo $Users
 */
class PasswordResetTokensTable extends Table
{
    /**
     * How long a reset link stays valid, in minutes.
     *
     * Short by intent: a reset link is a bearer credential for the account, and
     * a member who asked for one uses it within the hour or asks again.
     *
     * @var int
     */
    public const LIFETIME_MINUTES = 60;

    /**
     * @inheritDoc
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('password_reset_tokens');
        $this->setPrimaryKey('id');
        // Writes `created`; there is no `modified` column — a token is never
        // updated, only issued and then deleted.
        $this->addBehavior('Timestamp');
        $this->belongsTo('Users', ['foreignKey' => 'user_id']);
    }

    /**
     * Issue a fresh reset token for a member and return the **raw** token to
     * put in the emailed link.
     *
     * Any earlier token for the same member is cleared first: one outstanding
     * link at a time, so an inbox full of old requests cannot be walked back to
     * a still-valid one.
     *
     * @param int $userId the member the token is for
     * @return string the raw token (goes in the email, is not stored)
     */
    public function issueFor(int $userId): string
    {
        $this->deleteAll(['user_id' => $userId]);

        $token = bin2hex(Security::randomBytes(32));

        $entity = $this->newEntity(
            [
                'user_id' => $userId,
                'token_hash' => hash('sha256', $token),
                'expires' => DateTime::now()->addMinutes(self::LIFETIME_MINUTES),
            ],
            // The entity guards these against mass-assignment; this is the one
            // authorised writer, so allow them explicitly.
            ['accessibleFields' => ['user_id' => true, 'token_hash' => true, 'expires' => true]],
        );
        $this->saveOrFail($entity);

        return $token;
    }

    /**
     * Return the member id a valid, unexpired token belongs to, or null.
     *
     * This only reads — the caller consumes the token with {@see self::clearFor()}
     * once the new password is actually set, so a failed reset attempt does not
     * burn the link.
     *
     * @param string $token the raw token from the reset link
     * @return int|null the member id, or null if the token is unknown or expired
     */
    public function userIdForToken(string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        $row = $this->find()
            ->where([
                'token_hash' => hash('sha256', $token),
                'expires >' => DateTime::now(),
            ])
            ->first();

        return $row?->get('user_id');
    }

    /**
     * Delete every token for a member — called once a reset has succeeded, so
     * the used link and any siblings cannot be replayed.
     *
     * @param int $userId the member whose tokens to clear
     * @return void
     */
    public function clearFor(int $userId): void
    {
        $this->deleteAll(['user_id' => $userId]);
    }

    /**
     * Garbage-collect tokens whose lifetime has passed. For a scheduled sweep;
     * `issueFor()` already clears a member's own stale tokens on the next
     * request.
     *
     * @return int rows removed
     */
    public function deleteExpired(): int
    {
        return $this->deleteAll(['expires <=' => DateTime::now()]);
    }
}
