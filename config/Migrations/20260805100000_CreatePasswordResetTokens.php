<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * A place to hold self-service password-reset tokens.
 *
 * Saito has had no forgotten-password path — the only way back in for a member
 * who lost their password was an administrator setting a new one by hand (see
 * `Admin\Controller\UsersController::password()`). This table is the storage
 * for the self-service flow that closes that gap (issue #63).
 *
 * One row per outstanding request. The token itself never lands here: what is
 * stored is its SHA-256 (`token_hash`), so a read of this table hands an
 * attacker nothing usable — the raw token lives only in the emailed link and in
 * the member's inbox. The hash is unique, which is both the lookup key and a
 * guarantee that two requests cannot collide.
 *
 * `expires` is written by the controller (60 minutes out); rows are single-use
 * and are deleted the moment a reset succeeds, and a fresh request for the same
 * member clears the member's earlier rows first, so a mailbox full of old links
 * cannot be walked back. Expired rows that were never used are swept on the next
 * request for that member and by the garbage collector.
 *
 * `user_id` carries no foreign-key constraint here, matching the rest of the
 * schema (Saito enforces associations in the ORM, not the database); the index
 * is what the per-member lookup and cleanup ride on.
 */
class CreatePasswordResetTokens extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        // BaseMigration adds the auto-increment `id` primary key itself; adding
        // it by hand as well produced a duplicate-column error.
        $this->table('password_reset_tokens', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('user_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => false,
            ])
            // SHA-256 of the token, hex-encoded — always 64 characters.
            ->addColumn('token_hash', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('expires', 'datetime', [
                'default' => null,
                'limit' => null,
                'null' => false,
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'limit' => null,
                'null' => true,
            ])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['user_id'])
            ->create();
    }
}
