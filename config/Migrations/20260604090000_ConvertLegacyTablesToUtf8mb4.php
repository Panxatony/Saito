<?php
use Migrations\BaseMigration;

/**
 * Bring the last legacy utf8mb3 (3-byte) tables/columns in line with the rest
 * of the schema, which is already utf8mb4.
 *
 * Installs that predate the utf8mb4 switch kept a couple of stragglers on
 * utf8mb3, so they cannot store 4-byte characters (e.g. emoji):
 *
 *   - useronline               (whole table; only `uuid` is a char column)
 *   - users.user_category_custom (single column; the rest of `users` is utf8mb4)
 *
 * Both hold short ASCII-only values today, so the conversion is data-safe.
 * `phinxlog` is intentionally left untouched (it is managed by Migrations).
 */
class ConvertLegacyTablesToUtf8mb4 extends BaseMigration
{
    public function up(): void
    {
        // `users` is already utf8mb4 except this one column — convert it
        // surgically so the rest of the (large) table is not rewritten.
        $this->execute(
            'ALTER TABLE `users` '
            . 'MODIFY `user_category_custom` VARCHAR(512) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL'
        );

        // `useronline` is utf8mb3 at table level; convert the whole table.
        $this->execute(
            'ALTER TABLE `useronline` '
            . 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    public function down(): void
    {
        $this->execute(
            'ALTER TABLE `users` '
            . 'MODIFY `user_category_custom` VARCHAR(512) '
            . 'CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NULL DEFAULT NULL'
        );

        $this->execute(
            'ALTER TABLE `useronline` '
            . 'CONVERT TO CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci'
        );
    }
}
