<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Passkeys registered as a second factor (#81).
 *
 * One row per device. Several per account on purpose: a passkey lives in the
 * Secure Enclave of the machine it was made on, and while Apple and Google do
 * sync them now, nothing may depend on that — a member who registers only their
 * laptop and then reaches for their phone has to have a way through, so
 * registering more than one device is possible from the first day and the
 * recovery codes stay mandatory.
 *
 * `credential` holds the library's own serialisation of the credential record
 * rather than a column per field. The record carries a COSE public key, a trust
 * path, an AAGUID and the backup-state flags, and hand-mapping those onto
 * columns is how a subtle verification bug gets written; the shape belongs to
 * the library that validates it.
 *
 * `credential_id` is the lookup key, stored base64url-encoded because that is
 * how it arrives from the browser and how it goes back out in the allow-list.
 * `sign_count` is denormalised out of the record for one reason: a counter that
 * fails to advance is how a cloned authenticator announces itself, and that is
 * worth being able to read without deserialising anything.
 */
class CreateWebauthnCredentials extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('webauthn_credentials', ['engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('user_id', 'integer', ['default' => null, 'limit' => 11, 'null' => false])
            // Base64url of a credential id, which the spec caps at 1023 bytes.
            ->addColumn('credential_id', 'string', ['default' => null, 'limit' => 512, 'null' => false])
            ->addColumn('credential', 'text', ['default' => null, 'limit' => 16777215, 'null' => false])
            ->addColumn('sign_count', 'integer', ['default' => 0, 'limit' => 11, 'null' => false])
            // What the member calls this device. Their words, shown back to them
            // when they come to remove one.
            ->addColumn('label', 'string', ['default' => null, 'limit' => 100, 'null' => true])
            ->addColumn('created', 'datetime', ['default' => null, 'null' => true])
            ->addColumn('last_used_at', 'datetime', ['default' => null, 'null' => true])
            ->addIndex(['credential_id'], ['unique' => true])
            ->addIndex(['user_id'])
            ->create();
    }
}
