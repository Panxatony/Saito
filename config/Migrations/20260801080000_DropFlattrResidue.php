<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Remove what is left of Flattr.
 *
 * Flattr was a micropayment service; Saito could mark a posting for it, and the
 * mark lived in `entries.flattr`. The feature went with the Saito 5 rewrite and
 * the service itself is gone. What stayed behind, on grown installations only,
 * is the column and three settings rows.
 *
 * On the macnemo install, counted 2026-08-01: **16,104 postings carry the flag**,
 * set between 2011-02-16 and 2018-07-20, and read by nothing since.
 *
 * That is the difference between this and `entries.nsfw`, which sat in exactly
 * the same state until yesterday. The NSFW marking meant something a reader
 * would still want — 1928 postings said "not for the office", and they say it
 * again now. Flattr's marking meant "this posting can be tipped through a
 * service that no longer exists". There is nothing to bring back.
 *
 * Guarded both ways: an installation created from these migrations never had
 * either, and dropping what is not there is an error rather than a no-op.
 *
 * @see 20260731210000_AddNsfwToEntries the twin that went the other way
 */
class DropFlattrResidue extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        if ($this->table('entries')->hasColumn('flattr')) {
            $this->table('entries')->removeColumn('flattr')->update();
        }

        // The settings the feature was configured with. `settings` is a plain
        // key/value table, so these are rows rather than schema — but they are
        // residue of the same feature and would otherwise sit in the admin's
        // settings list forever, describing a service that shut down.
        $this->execute(
            "DELETE FROM `settings` WHERE `name` IN "
            . "('flattr_enabled', 'flattr_language', 'flattr_category')"
        );
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would recreate an empty column and three settings rows, and
     * neither would carry back the sixteen thousand marks the column held — the
     * data is in the backup taken before this ran, or it is gone. Pretending
     * otherwise with a `down()` that "restores" an empty column would be worse
     * than saying so.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
