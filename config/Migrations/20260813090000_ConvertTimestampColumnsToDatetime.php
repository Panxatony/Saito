<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Lift the 2038 ceiling off the timestamp columns (#70).
 *
 * `timestamp` cannot hold an instant after 2038-01-19. That is the type of the
 * columns new postings are written to, so a forum still running then would stop
 * accepting them. Eleven years away, but it is a date and not a worry, and
 * `datetime` has no such limit.
 *
 * `useronline.time` reaches the same ceiling by another route: it holds a Unix
 * timestamp in a signed `INT`, which tops out at the same instant. It stays an
 * integer here rather than becoming a `datetime`, because UserOnlineTable does
 * arithmetic on it (`$now - $timeUntilOffline * 0.75`); `BIGINT` lifts the
 * ceiling without touching that code.
 *
 * ## Two things that would go wrong quietly
 *
 * **The session timezone decides the values.** MySQL keeps a `timestamp`
 * internally as UTC and renders it into the *session's* timezone when
 * converting to `datetime` — which stores whatever literal it is given, with no
 * conversion of its own. Run through `bin/cake migrations migrate` the session
 * is already UTC, because the driver issues `SET time_zone = '+0:00'` whenever
 * `Datasources.default.timezone` is UTC. Run by hand from a `mysql` client on a
 * host set to, say, CEST, every instant in the table shifts by the offset — and
 * with new postings interleaved afterwards, that is not something you can undo.
 * The explicit `SET` below makes the migration correct however it is invoked.
 *
 * **A `MODIFY` that omits part of the definition drops that part.** Nullability
 * and defaults are restated in full for every column, because the utf8mb4 pass
 * narrowed `users.user_category_custom` from 1024 to 512 by leaving the width
 * out of an otherwise correct `MODIFY`.
 *
 * ## Why it may take a while
 *
 * Changing a column's type cannot be done in place — MariaDB answers
 * `ALGORITHM=INPLACE` with `ERROR 1846` and rebuilds the table instead. On a
 * large forum that is minutes, not seconds. MariaDB 11.2 and later can do that
 * rebuild while the table stays writable, so each statement is attempted with
 * `LOCK=NONE` first and repeated without it where the server does not offer it.
 * On those servers the table is readable but not writable for the duration:
 * people can read the forum, but new postings wait.
 */
class ConvertTimestampColumnsToDatetime extends BaseMigration
{
    /**
     * Columns to convert, as `table => [column => definition]`.
     *
     * The definitions are the current ones with `timestamp` swapped for
     * `datetime` — nothing else about them changes.
     *
     * @var array<string, array<string, string>>
     */
    private const CONVERSIONS = [
        'entries' => [
            'time' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'last_answer' => 'DATETIME NULL DEFAULT NULL',
            'edited' => 'DATETIME NULL DEFAULT NULL',
        ],
        'users' => [
            'last_login' => 'DATETIME NULL DEFAULT NULL',
            'registered' => 'DATETIME NULL DEFAULT NULL',
            'last_refresh' => 'DATETIME NULL DEFAULT NULL',
        ],
        'drafts' => [
            'created' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'modified' => 'DATETIME NULL DEFAULT NULL',
        ],
    ];

    /**
     * The same tables and columns as they were before, for `down()`.
     *
     * @var array<string, array<string, string>>
     */
    private const REVERSALS = [
        'entries' => [
            'time' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'last_answer' => 'TIMESTAMP NULL DEFAULT NULL',
            'edited' => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'users' => [
            'last_login' => 'TIMESTAMP NULL DEFAULT NULL',
            'registered' => 'TIMESTAMP NULL DEFAULT NULL',
            'last_refresh' => 'TIMESTAMP NULL DEFAULT NULL',
        ],
        'drafts' => [
            'created' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'modified' => 'TIMESTAMP NULL DEFAULT NULL',
        ],
    ];

    /**
     * @return void
     */
    public function up(): void
    {
        $this->execute("SET time_zone = '+00:00'");

        foreach (self::CONVERSIONS as $table => $columns) {
            $this->convert($table, $columns);
        }

        $this->convert('useronline', ['time' => 'BIGINT NOT NULL DEFAULT 0']);
    }

    /**
     * Back to `timestamp`.
     *
     * Only possible while every value in the table falls inside the range
     * `timestamp` can represent. That holds right after `up()`, which is when a
     * reversal is plausible; a forum that has since been running past 2038 has
     * no way back, and should not have one.
     *
     * @return void
     */
    public function down(): void
    {
        $this->execute("SET time_zone = '+00:00'");

        foreach (self::REVERSALS as $table => $columns) {
            $this->convert($table, $columns);
        }

        $this->convert('useronline', ['time' => 'INT(14) NOT NULL DEFAULT 0']);
    }

    /**
     * Rebuild one table, preferring the online path where the server has it.
     *
     * All of a table's columns go into a single statement: each `ALTER` copies
     * the whole table, so three separate ones would copy `entries` three times.
     *
     * @param string $table table name
     * @param array<string, string> $columns column => full definition
     * @return void
     */
    private function convert(string $table, array $columns): void
    {
        $changes = [];
        foreach ($columns as $column => $definition) {
            $changes[] = sprintf('MODIFY `%s` %s', $column, $definition);
        }

        $statement = sprintf('ALTER TABLE `%s` %s', $table, implode(', ', $changes));

        try {
            // MariaDB >= 11.2 rebuilds the table without blocking writes.
            $this->execute($statement . ', LOCK=NONE');
        } catch (\Throwable $e) {
            // Every other server rejects LOCK=NONE outright, before doing any
            // work, so retrying without it is safe rather than a half-applied
            // change. The table is write-locked for the rebuild there.
            $this->execute($statement);
        }
    }
}
