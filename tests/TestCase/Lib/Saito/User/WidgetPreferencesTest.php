<?php

declare(strict_types=1);

namespace App\Test\TestCase\Lib\Saito\User;

use Cake\TestSuite\TestCase;
use Saito\User\WidgetPreferences;

/**
 * The stored value comes back from the database and originally from a request,
 * so this class is the boundary between "what a member dragged" and "what the
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
        $stored = WidgetPreferences::write(['mine', 'online', 'recent'], ['online', 'mine'], self::KNOWN);

        $this->assertSame(
            ['order' => ['mine', 'online', 'recent'], 'minimised' => ['online', 'mine']],
            WidgetPreferences::read($stored, self::KNOWN)
        );
    }

    /**
     * Nothing stored is the common case — a member who never touched the rail.
     * They get the catalogue in its own order and nothing folded away.
     *
     * @return void
     */
    public function testEmptyStateReadsAsTheDefaultArrangement(): void
    {
        $expected = ['order' => self::KNOWN, 'minimised' => []];

        $this->assertSame($expected, WidgetPreferences::read(null, self::KNOWN));
        $this->assertSame($expected, WidgetPreferences::read('', self::KNOWN));
    }

    /**
     * The shape written before the rail could be reordered: a plain list, which
     * meant "these are minimised". Members carry those values across the deploy
     * that brings ordering, and their folded widgets must stay folded.
     *
     * @return void
     */
    public function testLegacyFlatListStillReadsAsMinimised(): void
    {
        $stored = serialize(['online', 'mine']);

        $this->assertSame(
            ['order' => self::KNOWN, 'minimised' => ['online', 'mine']],
            WidgetPreferences::read($stored, self::KNOWN)
        );
    }

    /**
     * A value left over from the retired slidetabs shares the column — the same
     * column, in fact, and for the same purpose. Its identifiers are not current
     * widgets, so they drop out instead of producing a rail full of ghosts.
     *
     * @return void
     */
    public function testLeftoverSlidetabValueIsIgnored(): void
    {
        $legacy = serialize(['slidetab_userlist', 'slidetab_shoutbox']);

        $this->assertSame(
            ['order' => self::KNOWN, 'minimised' => []],
            WidgetPreferences::read($legacy, self::KNOWN)
        );
    }

    /**
     * A widget that did not exist when the member arranged their rail has to
     * appear, and appending it is the only placement that leaves their own
     * choices undisturbed.
     *
     * @return void
     */
    public function testWidgetMissingFromAStoredOrderIsAppended(): void
    {
        $stored = WidgetPreferences::write(['mine', 'online'], [], ['online', 'mine']);

        $this->assertSame(
            ['order' => ['mine', 'online', 'recent'], 'minimised' => []],
            WidgetPreferences::read($stored, self::KNOWN)
        );
    }

    /**
     * Garbage in the column must not break the page. A preference is not worth
     * a 500.
     *
     * @return void
     */
    public function testUnreadableValueIsTreatedAsTheDefault(): void
    {
        $expected = ['order' => self::KNOWN, 'minimised' => []];
        foreach (['not serialised at all', 'a:1:{', serialize('a string'), serialize(42)] as $junk) {
            $this->assertSame($expected, WidgetPreferences::read($junk, self::KNOWN), $junk);
        }
    }

    /**
     * `unserialize` on stored input must not be able to instantiate anything.
     *
     * @return void
     */
    public function testSerialisedObjectIsRefused(): void
    {
        $payload = 'a:2:{s:5:"order";a:1:{i:0;O:8:"stdClass":0:{}}s:3:"min";a:0:{}}';

        $this->assertSame(
            ['order' => self::KNOWN, 'minimised' => []],
            WidgetPreferences::read($payload, self::KNOWN)
        );
    }

    /**
     * Only widgets the interface actually offers survive, so a submitted name
     * cannot smuggle arbitrary text into the column.
     *
     * @return void
     */
    public function testUnknownWidgetsAreDropped(): void
    {
        $stored = WidgetPreferences::write(
            ['online', 'nonsense', '<script>'],
            ['nonsense'],
            self::KNOWN
        );
        $read = WidgetPreferences::read($stored, self::KNOWN);

        $this->assertSame(['online', 'recent', 'mine'], $read['order']);
        $this->assertSame([], $read['minimised']);
    }

    /**
     * Duplicates would let a short allow-list still fill the column.
     *
     * @return void
     */
    public function testDuplicatesCollapse(): void
    {
        $stored = WidgetPreferences::write(
            ['online', 'online', 'online'],
            ['online', 'online'],
            self::KNOWN
        );

        $this->assertSame(
            ['order' => ['online', 'recent', 'mine'], 'minimised' => ['online']],
            WidgetPreferences::read($stored, self::KNOWN)
        );
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

        $stored = WidgetPreferences::write($known, $known, $known);

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
        $stored = WidgetPreferences::write(
            [['online'], 5, null, 'recent'],
            [['mine'], 7, null, 'mine'],
            self::KNOWN
        );
        $read = WidgetPreferences::read($stored, self::KNOWN);

        $this->assertSame(['recent', 'online', 'mine'], $read['order']);
        $this->assertSame(['mine'], $read['minimised']);
    }

    /**
     * Order and minimised state share one column, so writing one must not blank
     * the other. This is what a member sees when they fold a widget after
     * dragging the rail into shape: the shape has to survive the fold.
     *
     * @return void
     */
    public function testFoldingAWidgetKeepsTheOrder(): void
    {
        $order = WidgetPreferences::read(
            WidgetPreferences::write(['mine', 'recent', 'online'], [], self::KNOWN),
            self::KNOWN
        )['order'];

        $stored = WidgetPreferences::write($order, ['recent'], self::KNOWN);

        $this->assertSame(
            ['order' => ['mine', 'recent', 'online'], 'minimised' => ['recent']],
            WidgetPreferences::read($stored, self::KNOWN)
        );
    }
}
