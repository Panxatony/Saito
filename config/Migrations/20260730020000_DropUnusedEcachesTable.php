<?php
use Migrations\BaseMigration;

/**
 * Drop `ecaches`, a cache table Saito stopped writing to in 2014.
 *
 * Commit `1f45bb1` ("removes Ecach facilities", 6 November 2014, released in
 * 4.6.0) took out the code. The table was left behind, and because a cache table
 * is invisible when nothing uses it, it has sat in every grown database since —
 * on the live forum it still holds one row, written the same day the code was
 * removed, 811 KB of serialized posting data from 2014.
 *
 * It is not in `20180620081553_Initial`, so an installation built from the
 * migrations never had it. That is why the drop is guarded: on a fresh database
 * this migration has to do nothing rather than fail. Same shape as
 * `DropLegacySaito5UserColumns`, and found the same way — by comparing a grown
 * database against the schema the migrations describe, not by grepping the
 * source, which can only ever show that nothing *reads* a thing.
 *
 * Nothing else in the database refers to it: no foreign key, no view, and the
 * only code that ever did went eleven years ago.
 */
class DropUnusedEcachesTable extends BaseMigration
{
    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $rows = $this->fetchAll(
            'SELECT table_name AS tbl FROM information_schema.tables '
            . "WHERE table_schema = DATABASE() AND table_name = 'ecaches'"
        );

        if (!$rows) {
            return;
        }

        $this->execute('DROP TABLE `ecaches`');
    }

    /**
     * Deliberately empty.
     *
     * Recreating the table would produce an empty cache for an engine that no
     * longer exists — closer to nothing than to the old state, and misleading to
     * whoever finds it next.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
