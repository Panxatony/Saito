<?php
use Migrations\BaseMigration;

/**
 * Move the core tables off MyISAM.
 *
 * MyISAM has no transactions and does not complain about being asked for one:
 * BEGIN and COMMIT are accepted and ignored. Code that groups several writes
 * into a transaction is therefore correct on an InnoDB installation and silently
 * unprotected on a MyISAM one — no error, no warning, no way to notice from the
 * application side. Merging two threads is five dependent writes and is exactly
 * that case; a failure part-way through used to leave a thread half-merged and
 * unrepairable through the interface.
 *
 * 20180620081553_Initial created eight of these tables as MyISAM. That file now
 * says InnoDB, which covers installations that have not run it yet; a recorded
 * version is never replayed, so this migration is what reaches the ones that
 * did.
 *
 * Only what is still MyISAM is touched, so this is a no-op wherever the tables
 * were converted at some earlier point — which is the case on all three live
 * installations except one small table. Tables absent from the schema (the
 * `esevents`/`esnotifications` pair does not exist everywhere) fall out of the
 * query by themselves.
 *
 * `shouts` and `useronline` are deliberately left out: both are MEMORY, volatile
 * by design, and meant to be lost on a restart.
 *
 * Cost: converting a table rewrites it. On the live forum every table in this
 * list is already InnoDB apart from `uploads` (5,539 rows, under a megabyte), so
 * the run is immediate. An installation whose `entries` is still MyISAM should
 * expect this to take a while and hold a lock — that is unavoidable, and the
 * alternative is a forum whose transactions do not work.
 */
class ConvertCoreTablesToInnodb extends BaseMigration
{
    /**
     * Tables that must be transactional. `uploads`, `user_blocks`,
     * `user_ignores`, `user_reads` and `users` were already declared InnoDB in
     * Initial, but grown installations predate that and can still be MyISAM, so
     * they are listed too.
     *
     * @var list<string>
     */
    private const TABLES = [
        'bookmarks',
        'categories',
        'drafts',
        'entries',
        'esevents',
        'esnotifications',
        'settings',
        'smiley_codes',
        'smilies',
        'uploads',
        'user_blocks',
        'user_ignores',
        'user_reads',
        'users',
    ];

    /**
     * {@inheritDoc}
     */
    public function up(): void
    {
        $names = "'" . implode("', '", self::TABLES) . "'";
        $rows = $this->fetchAll(
            'SELECT table_name AS tbl FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() '
            . "AND engine = 'MyISAM' AND table_name IN ($names)"
        );

        foreach ($rows as $row) {
            // MySQL and MariaDB disagree on the case of the column name, hence
            // the alias; the numeric index is the belt to that braces.
            $table = $row['tbl'] ?? $row[0];
            $this->execute(sprintf('ALTER TABLE `%s` ENGINE = InnoDB', $table));
        }
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would mean putting the tables back on an engine that cannot
     * do transactions, which is the whole reason this migration exists.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
