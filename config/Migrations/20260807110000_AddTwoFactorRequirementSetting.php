<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * The setting that lets an operator require a second factor (#87).
 *
 * `off` on purpose, so upgrading changes nothing. Turning this on sends every
 * moderator or administrator without a second factor to the enrolment page
 * instead of the forum, which is exactly what it is for and exactly why it must
 * not happen to anybody who did not ask for it.
 *
 * Added by migration rather than left to the seed: the seed only runs on a new
 * installation, so a grown forum would carry no row and the setting would be
 * invisible in the admin screen — present in the code, absent from the
 * interface, which is the shape of a feature nobody can find.
 *
 * Guarded, because a forum that installed after this shipped already has the
 * row from the seed and a second insert would collide with the unique name.
 */
class AddTwoFactorRequirementSetting extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $rows = $this->fetchAll(
            "SELECT id FROM settings WHERE name = '2fa_required_from_role'"
        );
        if ($rows) {
            return;
        }

        $this->table('settings')->insert([
            ['name' => '2fa_required_from_role', 'value' => 'off'],
        ])->save();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->execute("DELETE FROM settings WHERE name = '2fa_required_from_role'");
    }
}
