<?php
use Migrations\BaseMigration;

/**
 * Give `users.user_category_custom` its full 1024 characters back.
 *
 * 20180620093430_Saitox5x0x0 widened the column from 512 to 1024 in 2018.
 * 20260604090000_ConvertLegacyTablesToUtf8mb4 then restated the column to
 * convert its character set — and, by writing VARCHAR(512), narrowed it again
 * as a side effect its docblock never mentioned. That migration has since been
 * corrected, which protects installs that have not run it yet; installs that
 * already did keep the narrow column, because Migrations will not replay a
 * recorded version. This repairs those.
 *
 * The column holds a PHP-serialized map of the categories a member chose to
 * see. Roughly 14 characters per category, so 512 runs out at about 36 of them
 * — and MySQL outside strict mode truncates silently, which does not shorten
 * such a value but destroys it. Widening can never truncate, so this is safe
 * to run whatever width the column currently has.
 */
class RestoreUserCategoryCustomWidth extends BaseMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE `users` '
            . 'MODIFY `user_category_custom` VARCHAR(1024) '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL'
        );
    }

    /**
     * Deliberately empty.
     *
     * Rolling back would mean narrowing the column, which is the very thing
     * this migration exists to undo — and it could truncate data on the way.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
