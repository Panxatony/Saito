<?php

declare(strict_types=1);

namespace App\Test\TestCase\Lib\Saito\User;

use Cake\TestSuite\TestCase;
use Saito\User\WidgetPreferences;

/**
 * The stored value comes back from the database and originally from a request,
 * so this class is the boundary between "what a member clicked" and "what the
 * page renders". Most of these tests are about what it refuses.
 */
class WidgetPreferencesTest extends TestCase
{
    /** @var list<string> */
    private const KNOWN = ['online', 'recent', 'mine'];

    /**
     * @return void
     */
    public function testRoundTrip(): void
    {
        $stored = WidgetPreferences::write(['online', 'mine'], self::KNOWN);

        $this->assertSame(['online', 'mine'], WidgetPreferences::read($stored, self::KNOWN));
    }

    /**
     * Nothing stored is the common case — a member who never touched the rail.
     *
     * @return void
     */
    public function testEmptyStateReadsAsNothingMinimised(): void
    {
        $this->assertSame([], WidgetPreferences::read(null, self::KNOWN));
        $this->assertSame([], WidgetPreferences::read('', self::KNOWN));
    }

    /**
     * A value left over from the retired slidetabs shares the column. Its
     * identifiers are not widgets, so they simply drop out instead of producing
     * an error or a rail full of ghosts.
     *
     * @return void
     */
    public function testLeftoverSlidetabValueIsIgnored(): void
    {
        $legacy = serialize(['SlidetabUserlist', 'SlidetabRecentposts']);

        $this->assertSame([], WidgetPreferences::read($legacy, self::KNOWN));
    }

    /**
     * Garbage in the column must not break the page. A preference is not worth
     * a 500.
     *
     * @return void
     */
    public function testUnreadableValueIsTreatedAsEmpty(): void
    {
        foreach (['not serialised at all', 'a:1:{', serialize('a string'), serialize(42)] as $junk) {
            $this->assertSame([], WidgetPreferences::read($junk, self::KNOWN), $junk);
        }
    }

    /**
     * `unserialize` on stored input must not be able to instantiate anything.
     *
     * @return void
     */
    public function testSerialisedObjectIsRefused(): void
    {
        $payload = 'a:1:{i:0;O:8:"stdClass":0:{}}';

        $this->assertSame([], WidgetPreferences::read($payload, self::KNOWN));
    }

    /**
     * Only widgets the interface actually offers survive, so a submitted name
     * cannot smuggle arbitrary text into the column.
     *
     * @return void
     */
    public function testUnknownWidgetsAreDropped(): void
    {
        $stored = WidgetPreferences::write(['online', 'nonsense', '<script>'], self::KNOWN);

        $this->assertSame(['online'], WidgetPreferences::read($stored, self::KNOWN));
    }

    /**
     * Duplicates would let a short allow-list still fill the column.
     *
     * @return void
     */
    public function testDuplicatesCollapse(): void
    {
        $stored = WidgetPreferences::write(['online', 'online', 'online'], self::KNOWN);

        $this->assertSame(['online'], WidgetPreferences::read($stored, self::KNOWN));
    }

    /**
     * The column is VARCHAR(512). A value that would overflow it must not be
     * stored half-written, because a truncated serialisation never reads back.
     *
     * @return void
     */
    public function testStoredValueStaysInsideTheColumn(): void
    {
        $known = [];
        for ($i = 0; $i < 200; $i++) {
            $known[] = str_repeat('w', 40) . $i;
        }

        $stored = WidgetPreferences::write($known, $known);

        $this->assertLessThanOrEqual(512, strlen($stored));
        $this->assertIsArray(unserialize($stored, ['allowed_classes' => false]));
    }

    /**
     * Non-string entries in the request must not reach the column.
     *
     * @return void
     */
    public function testNonStringSubmissionsAreDropped(): void
    {
        $stored = WidgetPreferences::write([['online'], 5, null, 'recent'], self::KNOWN);

        $this->assertSame(['recent'], WidgetPreferences::read($stored, self::KNOWN));
    }
}
