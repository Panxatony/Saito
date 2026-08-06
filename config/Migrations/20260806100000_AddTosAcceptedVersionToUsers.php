<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Which version of the terms of service a member has agreed to.
 *
 * The terms are versioned by the `tos_version` setting. When an operator raises
 * it after a material change, every account whose accepted version is lower is
 * asked to agree again before it can keep using the forum (issue #80) — § 7 of
 * the shipped terms is the clause this implements.
 *
 * Existing accounts start at 0 — and so does the `tos_version` setting, which
 * makes the upgrade a no-op: nothing is behind 0, so nobody is asked anything.
 * The feature stays dormant until an operator raises the setting after actually
 * changing the terms, and then every account still on a lower number is asked
 * once. An upgrade must not put a consent form in front of members whose terms
 * nobody touched.
 */
class AddTosAcceptedVersionToUsers extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        $this->table('users')
            // No `after`: column order carries no meaning here, and naming a
            // neighbour is a failure path on a grown schema for nothing.
            ->addColumn('tos_accepted_version', 'integer', [
                'default' => 0,
                'limit' => 11,
                'null' => false,
            ])
            ->update();
    }
}
