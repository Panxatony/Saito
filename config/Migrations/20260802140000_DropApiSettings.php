<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Remove two settings that control nothing.
 *
 * `api_enabled` and `api_crossdomain` are read by no code. Found by the probe
 * sweep on 2026-08-02, which compares every row in `settings` against the code
 * that might consult it.
 *
 * **`api_enabled` is the uncomfortable one**, because it is not merely inert: it
 * is a switch in the admin area that appears to control access to the API. An
 * administrator who sets it to 0 has every reason to believe the API is off. It
 * is not, and it never was — the value has no reader. On the reference
 * installation it stood at 1, so nobody had yet been misled, but a control that
 * lies about what it does is worse than no control at all.
 *
 * **The API itself stays**, and this migration does not touch it. That deserves
 * saying plainly because the first version of the probe report claimed the
 * opposite: probing `/api`, `/api/v2` and `/api/v2/entries` returns 404 and
 * looks like a dead feature. The live routes are registered by the
 * **ImageUploader** and **Bookmarks** plugins, not by the `Api` plugin, and they
 * answer properly:
 *
 * ```
 * /api/v2/uploads/thumb/{id}   403      /api/v2/uploads.json   401
 * /api/v2/bookmarks            401
 * ```
 *
 * 401 and 403 are authenticated endpoints working. The `Api` plugin supplies the
 * base controller and the JSON error renderer they share.
 *
 * Guarded, like every drop here: an installation created from these migrations
 * never had these rows, and `DELETE` on nothing is fine, but the count is
 * checked so the migration says what it did rather than pretending.
 *
 * @see 20260801080000_DropFlattrResidue the same shape, for the same reason
 */
class DropApiSettings extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->execute(
            "DELETE FROM `settings` WHERE `name` IN ('api_enabled', 'api_crossdomain')"
        );
    }

    /**
     * Deliberately empty.
     *
     * Putting the rows back would recreate a switch that controls nothing, which
     * is the state this exists to leave. If the API ever grows a real on/off
     * switch it should be added as one, wired to something, not restored from
     * here.
     *
     * @return void
     */
    public function down(): void
    {
    }
}
