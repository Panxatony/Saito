<?php
use Migrations\BaseMigration;

/**
 * Drop six `users` columns that only exist on installations grown from Saito 5.
 *
 * Upstream removed these around 2012, but as a manual SQL step printed in
 * `docs/CHANGELOG_OLD.md` rather than as a migration — so nobody ran it, and
 * every grown installation still carries them. An installation built from
 * `20180620081553_Initial` never had them, which is why the drops are guarded:
 * the migration has to be a no-op there rather than an error.
 *
 * `user_font_size`, `show_about`, `show_donate`, `flattr_uid`,
 * `flattr_allow_user`, `flattr_allow_posting`.
 *
 * **Dropped, not converted.** `user_font_size` holds a Saito 5 *factor*, not the
 * percentage today's settings page works in — on the live forum 194 of 821
 * accounts have a value. Reviving them would resize the forum for people who
 * never asked for it, in units that no longer mean what they say. The other five
 * belong to features that do not exist: an "about" and a "donate" section, and
 * Flattr, a micro-payment service that shut down in 2018.
 *
 * Note for whoever reads this next: a grep over the source proves a column is
 * unused, never that it is residue. What proved it here was comparing a grown
 * database against one built from the migrations — the difference is exactly
 * these six.
 */
class DropLegacySaito5UserColumns extends BaseMigration
{
    /**
     * @var list<string>
     */
    private const COLUMNS = [
        'user_font_size',
        'show_about',
        'show_donate',
        'flattr_uid',
        'flattr_allow_user',
        'flattr_allow_posting',
    ];

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $names = "'" . implode("', '", self::COLUMNS) . "'";
        $rows = $this->fetchAll(
            'SELECT column_name AS col FROM information_schema.columns '
            . "WHERE table_schema = DATABASE() AND table_name = 'users' "
            . "AND column_name IN ($names)"
        );

        $present = [];
        foreach ($rows as $row) {
            // MySQL and MariaDB disagree on the case of the column name, hence
            // the alias; the numeric index is the belt to that braces.
            $present[] = $row['col'] ?? $row[0];
        }

        if (!$present) {
            return;
        }

        // One statement: `users` is rewritten per ALTER, and on a forum with a
        // few thousand accounts that is cheap but not free.
        $drops = array_map(fn(string $col): string => "DROP `$col`", $present);
        $this->execute('ALTER TABLE `users` ' . implode(', ', $drops));
    }

    /**
     * Deliberately empty.
     *
     * Rolling back could only recreate the columns empty. Their contents are the
     * one thing a rollback cannot restore, and nothing reads them anyway — an
     * empty `flattr_uid` is not closer to the old state than no column at all.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
