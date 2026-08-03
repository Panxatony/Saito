<?php
declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Finish the utf8mb4 conversion, which stopped four tables short of the forum.
 *
 * `ConvertLegacyTablesToUtf8mb4` (8.0.x) converted `users` and `useronline`.
 * `AlignSchemaWithGrownInstalls` reaches `entries` and `drafts` on its way to
 * widening their text columns. Everything else a grown forum carries —
 * bookmarks, categories, settings, smilies, uploads, the two block tables —
 * stayed in utf8mb3, the three-byte encoding that cannot hold an emoji.
 *
 * **This is not cosmetic, and it is not silent either.** Measured on a schema
 * from a forum that grew from an old version, with the server in the default
 * strict mode:
 *
 *     INSERT INTO bookmarks (…, comment, …) VALUES (…, '👍 gut', …)
 *     ERROR 1366 (22007): Incorrect string value: '\xF0\x9F\x91\x8D g...'
 *
 * The row is refused outright. A member saving a bookmark note with an emoji,
 * an administrator naming a category or a smiley, a moderator writing a block
 * reason — each hits an error a freshly installed forum never sees. Outside
 * strict mode it is worse rather than better: the value is truncated at the
 * first four-byte character and stored anyway.
 *
 * Installations built from these migrations are already utf8mb4 throughout and
 * this does nothing for them; the guard below skips each table that is already
 * converted, so they pay no rebuild.
 *
 * `phinxlog` is deliberately left alone. It belongs to the migration tool
 * rather than to Saito, holds nothing but ASCII class names, and rewriting
 * another project's bookkeeping table is not this migration's business.
 */
class ConvertRemainingTablesToUtf8mb4 extends BaseMigration
{
    /**
     * Saito's own tables. Named rather than discovered, so an installation that
     * keeps unrelated tables in the same database does not have them rewritten
     * by a Saito upgrade.
     *
     * @var array<string>
     */
    private const TABLES = [
        'bookmarks',
        'categories',
        'drafts',
        'entries',
        'settings',
        'smilies',
        'smiley_codes',
        'uploads',
        'user_blocks',
        'user_ignores',
        'user_reads',
        'useronline',
        'users',
    ];

    /**
     * @return void
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $row = $this->fetchRow(sprintf(
                'SELECT TABLE_COLLATION FROM information_schema.TABLES '
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' LIMIT 1",
                $table,
            ));

            // Absent is not an error: an installation may predate a table, and
            // a missing one is the installer's business, not this migration's.
            if ($row === false) {
                continue;
            }

            if (str_starts_with((string)$row['TABLE_COLLATION'], 'utf8mb4')) {
                continue;
            }

            // The whole table at once. Converting single columns is what breaks
            // on `entries`, whose FULLTEXT index spans three of them and may not
            // straddle two character sets — see AlignSchemaWithGrownInstalls.
            $this->execute(sprintf(
                'ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                $table,
            ));
        }
    }

    /**
     * Deliberately empty.
     *
     * Going back to utf8mb3 would cut every four-byte character written in the
     * meantime, and there is no reading of "undo" in which that is what somebody
     * wanted.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
