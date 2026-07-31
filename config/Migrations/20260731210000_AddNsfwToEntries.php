<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Give `entries.nsfw` to installations that do not have it.
 *
 * The column is older than these migrations. Saito 4 carried a per-posting
 * "not safe for work" flag, moved out into an `NsfwBadge` plugin in 2014 and
 * lost with the Saito 5 rewrite — the plugin went, the column stayed, and the
 * migrations never mention it. So a forum that has been running since then has
 * the column and its data (1928 postings still marked on the macnemo install,
 * counted 2026-07-31), while an install created from these migrations does not
 * have it at all.
 *
 * That divergence was harmless while nothing read the column. It stops being
 * harmless now that the badge is back, which is what this repairs.
 *
 * Guarded rather than unconditional: adding a column that is already there is
 * an error, and roughly every grown installation already has it.
 */
class AddNsfwToEntries extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        if ($this->table('entries')->hasColumn('nsfw')) {
            return;
        }

        $this->table('entries')
            ->addColumn('nsfw', 'boolean', [
                'default' => false,
                'null' => true,
                'after' => 'fixed',
            ])
            ->update();
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would drop a column that on most installations predates this
     * migration by more than a decade and holds data nothing can reconstruct.
     * An install that gained the column here loses nothing by keeping it.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
