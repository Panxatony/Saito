<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Credentials left behind by accounts that no longer exist (#86).
 *
 * The five tables the 8.4 line added were never wired into the account's
 * `dependent` associations, so deleting a member kept every way of signing in
 * as them: the encrypted second-factor secret, ten hashed recovery codes, the
 * trusted-device tokens, the passkey with its public key and user handle, and
 * any outstanding password-reset token. Thirteen rows for one account, measured
 * before the fix.
 *
 * The association is repaired in `UsersTable::initialize()`, which stops it
 * happening again. This clears what has already accumulated — a member who
 * asked to be erased is not erased while these rows stand, and a fix that only
 * applies from today would leave exactly the people who already exercised that
 * right still on file.
 *
 * Written as `WHERE user_id NOT IN (SELECT id FROM users)` rather than a join
 * so it reads as what it is, and guarded per table because an installation that
 * predates one of them will not have it. Silent, like every other migration
 * here. There is no `down()`: these rows reference accounts that are gone, and
 * restoring them would restore nothing usable.
 */
class DeleteOrphanedCredentials extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $tables = [
            'two_factor_credentials',
            'two_factor_recovery_codes',
            'two_factor_trusted_devices',
            'webauthn_credentials',
            'password_reset_tokens',
        ];

        foreach ($tables as $table) {
            if (!$this->hasTable($table)) {
                continue;
            }

            $this->execute(sprintf(
                'DELETE FROM %s WHERE user_id NOT IN (SELECT id FROM users)',
                $table,
            ));
        }
    }

    /**
     * @return void
     */
    public function down(): void
    {
    }
}
