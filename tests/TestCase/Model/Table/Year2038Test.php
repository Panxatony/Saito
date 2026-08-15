<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Model\Table;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * The 2038 ceiling (#70).
 *
 * These assert the thing that matters rather than the column type: an instant
 * past 2038-01-19 goes in and comes back unchanged. A test on the type would
 * pass against a fixture that says `datetime` while the real table still says
 * `timestamp`; a stored value cannot lie about it.
 *
 * The dates are deliberately far past the ceiling. A `timestamp` column does
 * not round-trip them — depending on the server's strict mode it either raises
 * or silently stores a zero date, and both failures show up here.
 */
class Year2038Test extends TestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.User',
        'app.UserOnline',
        'app.Draft',
    ];

    /**
     * Past **2106**, not merely past 2038, and deliberately so.
     *
     * MariaDB 11.5 widened `timestamp` to run until 2106-02-07, which makes any
     * date between 2038 and 2106 useless as a guard: on a modern MariaDB it
     * round-trips through a `timestamp` column just as happily as through a
     * `datetime` one, and the test passes while proving nothing. Verified by
     * converting the columns back and watching these assertions stay green.
     *
     * A date beyond 2106 is outside what `timestamp` can represent on every
     * MySQL and MariaDB version, so this bites everywhere — including on a
     * developer machine newer than the servers.
     *
     * Not a round number either, so a truncated or defaulted value cannot match
     * by coincidence.
     */
    private const AFTER_THE_CEILING = '2151-06-14 09:26:53';

    public function testAPostingCanBeDatedAfter2038(): void
    {
        $entries = TableRegistry::getTableLocator()->get('Entries');
        $entry = $entries->get(1);

        $entry->set('time', new DateTime(self::AFTER_THE_CEILING));
        $entry->set('last_answer', new DateTime(self::AFTER_THE_CEILING));
        $entry->set('edited', new DateTime(self::AFTER_THE_CEILING));
        $entries->saveOrFail($entry);

        $entries->getConnection()->cacheMetadata(false);
        $reloaded = $entries->get(1);

        foreach (['time', 'last_answer', 'edited'] as $column) {
            $this->assertSame(
                self::AFTER_THE_CEILING,
                $reloaded->get($column)->format('Y-m-d H:i:s'),
                "entries.$column did not survive a date past 2038",
            );
        }
    }

    public function testAnAccountCanCarryDatesAfter2038(): void
    {
        $users = TableRegistry::getTableLocator()->get('Users');
        $user = $users->get(1);

        $user->set('registered', new DateTime(self::AFTER_THE_CEILING));
        $user->set('last_login', new DateTime(self::AFTER_THE_CEILING));
        $user->set('last_refresh', new DateTime(self::AFTER_THE_CEILING));
        $users->saveOrFail($user);

        $reloaded = $users->get(1);

        foreach (['registered', 'last_login', 'last_refresh'] as $column) {
            $this->assertSame(
                self::AFTER_THE_CEILING,
                $reloaded->get($column)->format('Y-m-d H:i:s'),
                "users.$column did not survive a date past 2038",
            );
        }
    }

    /**
     * `useronline.time` holds a Unix timestamp as an integer, so its ceiling is
     * the signed 32-bit maximum rather than a date type. The column keeps doing
     * arithmetic in UserOnlineTable, so it stays an integer — just a wider one.
     *
     * @return void
     */
    public function testTheOnlineTableCanHoldAUnixTimestampAfter2038(): void
    {
        $beyondSignedInt = 3699523613; // 2087-03-14, above 2147483647

        // The fixture carries no rows, so the row is written here — which has
        // the side benefit of exercising the real insert rather than an update.
        $online = TableRegistry::getTableLocator()->get('UserOnline');
        $row = $online->newEntity([
            'uuid' => 'year-2038-probe',
            'user_id' => 1,
            'logged_in' => true,
            'time' => $beyondSignedInt,
        ]);
        $online->saveOrFail($row);

        $reloaded = $online->get($row->get('id'));

        $this->assertSame(
            $beyondSignedInt,
            (int)$reloaded->get('time'),
            'useronline.time was clamped — the column is still a 32-bit int',
        );
    }

    public function testADraftCanBeDatedAfter2038(): void
    {
        $drafts = TableRegistry::getTableLocator()->get('Drafts');
        $draft = $drafts->find()->firstOrFail();

        $draft->set('created', new DateTime(self::AFTER_THE_CEILING));
        $draft->set('modified', new DateTime(self::AFTER_THE_CEILING));
        $drafts->saveOrFail($draft);

        $reloaded = $drafts->get($draft->get('id'));

        $this->assertSame(
            self::AFTER_THE_CEILING,
            $reloaded->get('created')->format('Y-m-d H:i:s'),
            'drafts.created did not survive a date past 2038',
        );
    }
}
