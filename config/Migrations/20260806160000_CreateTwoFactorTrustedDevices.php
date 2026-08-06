<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Devices that have already proved the second factor.
 *
 * Enabling two-factor authentication made "stay signed in" stop working
 * entirely, and on a phone — where the browser evicts sessions freely — that
 * meant logging in again and again. The cause was a blunt guard: a remember-me
 * cookie cannot be revoked (it validates against a username and a password
 * hash, nothing the server keeps), so one minted *before* an account enrolled
 * would have walked past 2FA until it expired, and the only way to stop that
 * was to refuse them all.
 *
 * This table is the finer instrument. A row is written only after a second
 * factor has actually been proved, and a remember-me cookie is honoured for an
 * enrolled account only when it comes with a device token that matches one.
 * Old cookies from before enrolment have no row and are still refused, so the
 * hole stays shut while the convenience comes back.
 *
 * What is stored is the SHA-256 of the token, never the token: a read of this
 * table hands an attacker nothing they could put in a cookie. Rows are deleted
 * when the second factor is switched off, when an administrator resets it, and
 * when the password changes — the three moments that mean "the trust I placed
 * in that device no longer holds".
 */
class CreateTwoFactorTrustedDevices extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('two_factor_trusted_devices', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('user_id', 'integer', ['default' => null, 'limit' => 11, 'null' => false])
            // SHA-256, hex — always 64 characters.
            ->addColumn('token_hash', 'string', ['default' => null, 'limit' => 64, 'null' => false])
            ->addColumn('expires', 'datetime', ['default' => null, 'null' => false])
            ->addColumn('created', 'datetime', ['default' => null, 'null' => true])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['user_id'])
            ->create();
    }
}
