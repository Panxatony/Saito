<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Storage for two-factor authentication (issue #62).
 *
 * Two tables rather than columns on `users`, for one reason worth stating: the
 * rows only exist for accounts that enrolled, so a dump of `users` — the table
 * every admin screen, export and backup touches — carries no authentication
 * secrets at all.
 *
 * `two_factor_credentials` holds one row per enrolled account. `secret` is the
 * TOTP shared secret **encrypted at rest** with the app salt; it is never
 * written in plaintext, because a database read would otherwise let an attacker
 * mint valid codes forever without touching the password. `confirmed_at` stays
 * null between generating a secret and the member proving they can produce a
 * code from it: an unconfirmed row must never gate a login, or a half-finished
 * enrolment would lock somebody out of their own account.
 *
 * `two_factor_recovery_codes` is the way back in when the phone is gone. Each
 * code is stored as a bcrypt hash — the same treatment as a password, because
 * that is exactly what a recovery code is — and is single-use: `used_at` is
 * stamped rather than the row deleted, so "you have three left" stays
 * answerable and a reused code is distinguishable from an invented one.
 *
 * No foreign keys, matching the rest of the schema: Saito enforces associations
 * in the ORM, and the indexes are what the per-account lookups ride on.
 */
class CreateTwoFactorTables extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('two_factor_credentials', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('user_id', 'integer', ['default' => null, 'limit' => 11, 'null' => false])
            // 'totp' today. Named rather than assumed so a later WebAuthn
            // credential can sit beside it instead of replacing the column.
            ->addColumn('type', 'string', ['default' => 'totp', 'limit' => 20, 'null' => false])
            // Security::encrypt() output, base64 — comfortably inside 255 for a
            // 160-bit secret, with room for the envelope.
            ->addColumn('secret', 'string', ['default' => null, 'limit' => 255, 'null' => false])
            ->addColumn('confirmed_at', 'datetime', ['default' => null, 'null' => true])
            ->addColumn('created', 'datetime', ['default' => null, 'null' => true])
            ->addColumn('modified', 'datetime', ['default' => null, 'null' => true])
            // One second factor per account: unique, so a race during enrolment
            // cannot leave two secrets where only one was ever shown.
            ->addIndex(['user_id'], ['unique' => true])
            ->create();

        $this->table('two_factor_recovery_codes', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('user_id', 'integer', ['default' => null, 'limit' => 11, 'null' => false])
            // bcrypt output is 60 characters; 255 leaves room for a future hasher.
            ->addColumn('code_hash', 'string', ['default' => null, 'limit' => 255, 'null' => false])
            ->addColumn('used_at', 'datetime', ['default' => null, 'null' => true])
            ->addColumn('created', 'datetime', ['default' => null, 'null' => true])
            ->addIndex(['user_id'])
            ->create();
    }
}
