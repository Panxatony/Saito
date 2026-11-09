<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Remember the last TOTP time step that was accepted (#165).
 *
 * A code is valid for its 30-second step and, with the leeway for clock drift,
 * the steps on either side. Without a record of which step was used, an
 * observed or relayed code worked a second time for up to 90 seconds — a
 * phishing proxy's second session, for instance. A code is now refused unless
 * its step is later than the last one accepted.
 *
 * `BIGINT`: the step is the Unix time divided by 30, and stays far below the
 * signed 32-bit limit for centuries, but the column costs nothing and the
 * 2038 lesson (#70) was learnt once already. Null until the first code is
 * accepted after this migration.
 */
class AddLastUsedStepToTwoFactorCredentials extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('two_factor_credentials')
            ->addColumn('last_used_step', 'biginteger', ['default' => null, 'null' => true, 'after' => 'confirmed_at'])
            ->update();
    }
}
