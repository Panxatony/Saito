<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Close the gap between what these migrations build and what a grown forum runs.
 *
 * Measured on 2026-08-01 by migrating an empty database and comparing it column
 * by column with a production dump. Most of what came out is cosmetic — display
 * widths (`int(11)` against `int(4)`), `unsigned` on id columns, `char` against
 * `varchar` for a fixed-length hash. MySQL stores those identically and nothing
 * behaves differently.
 *
 * Two are not cosmetic, and this migration is about those.
 *
 * ## The text columns
 *
 * `entries.text`, `drafts.text` and `users.profile` are `TEXT` here and
 * `MEDIUMTEXT` on a forum that has been running for years. TEXT holds 65,535
 * bytes; MEDIUMTEXT holds 16 MB.
 *
 * That difference is not theoretical. **The longest posting on the macnemo
 * installation is 294,739 characters** — four and a half times what a TEXT
 * column can hold. Restoring that forum's dump into an installation built from
 * these migrations would not have worked, and outside MySQL's strict mode it
 * would not have failed loudly either: it would have cut the posting and moved
 * on.
 *
 * Widening can never truncate, so this is safe to run whatever the column
 * currently is.
 *
 * ## The username index
 *
 * `UsersTable` validates usernames as unique, case-insensitively
 * (`validateIsUniqueCiString`). A fresh installation backs that with a UNIQUE
 * index; a grown one has a plain, non-unique index over the first 191
 * characters. So on the installation that has 821 members, two simultaneous
 * registrations could in principle both pass validation and both be written.
 *
 * They have not — 821 names, 821 distinct when lower-cased, checked before this
 * was written. But "has not happened" is not the same as "cannot", and the
 * database should hold the guarantee the application already promises.
 *
 * The index is created as UNIQUE over the first 191 characters rather than the
 * whole column: 191 × 4 bytes is 764, just under the 767-byte limit that older
 * InnoDB row formats impose, so this works on an installation that has not been
 * converted to DYNAMIC yet. For names, 191 characters is not a restriction —
 * the longest on that forum is 60.
 */
class AlignSchemaWithGrownInstalls extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        // Widening only. Stated as raw SQL because the column definitions carry
        // a character set that must not be lost in the restatement.
        foreach (
            [
            ['entries', 'text'],
            ['drafts', 'text'],
            ['users', 'profile'],
            ] as [$table, $column]
        ) {
            $this->execute(sprintf(
                'ALTER TABLE `%s` MODIFY `%s` MEDIUMTEXT '
                . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
                $table,
                $column
            ));
        }

        // The unique index, if it is not already unique. Guarded rather than
        // dropped-and-recreated: a fresh installation has it, and dropping an
        // index on a live table to put an equivalent one back is a risk taken
        // for nothing.
        $isUnique = $this->fetchRow(
            "SELECT NON_UNIQUE FROM information_schema.STATISTICS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' "
            . "AND INDEX_NAME = 'username' LIMIT 1"
        );

        if ($isUnique !== false && (int)$isUnique['NON_UNIQUE'] === 1) {
            $this->execute('ALTER TABLE `users` DROP INDEX `username`');
            $this->execute('ALTER TABLE `users` ADD UNIQUE INDEX `username` (`username`(191))');
        }
    }

    /**
     * Deliberately empty.
     *
     * Narrowing MEDIUMTEXT back to TEXT would cut every posting over 65,535
     * bytes — the very thing this exists to make possible. And dropping the
     * unique index would hand back a guarantee for nothing.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
