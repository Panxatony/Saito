<?php
declare(strict_types=1);

namespace Saito\Test\User\Userranks;

use Cake\TestSuite\TestCase;
use Saito\User\Userranks\Ranks;

/**
 * The ladder is typed into an admin form by hand and then applied to every
 * member, so both halves are worth pinning: what it does with a well-formed
 * ladder, and what it does with the ways a human mistypes one.
 *
 * The production value is used as the fixture rather than a tidy invention —
 * including its trailing separator, which is exactly the kind of thing a
 * stricter parser would have choked on.
 */
class RanksTest extends TestCase
{
    private const LIVE = '10=Fischbrötchen|100=Schiffsjunge|1000=Maat|5000=Bootsmann'
        . '|10000=Harpunier|50000=Smutje|100000=Kpt. Ahab|';

    /**
     * A threshold is the number the rank is earned at.
     *
     * Every assertion here is a boundary, because the boundary is the whole
     * question: ten postings make a Fischbrötchen, nine do not.
     *
     * @return void
     */
    public function testARankIsEarnedAtItsThreshold(): void
    {
        $ranks = Ranks::fromSetting(self::LIVE);

        $this->assertSame('Fischbrötchen', $ranks->titleFor(10), 'exactly at the threshold');
        $this->assertSame('Fischbrötchen', $ranks->titleFor(99), 'and up to the next one');
        $this->assertSame('Schiffsjunge', $ranks->titleFor(100));
        $this->assertSame('Schiffsjunge', $ranks->titleFor(999));
        $this->assertSame('Maat', $ranks->titleFor(1000));
    }

    /**
     * Below the first threshold there is no rank at all.
     *
     * This is the half the 2014 implementation did not have: it read the
     * thresholds as upper bounds, so a member with no postings was already a
     * Fischbrötchen. On this forum that is not a detail — **511 of 821 members
     * have ten postings or fewer**, so the rule decides whether a rank means
     * anything or everybody has one.
     *
     * @return void
     */
    public function testBelowTheFirstThresholdThereIsNoRank(): void
    {
        $ranks = Ranks::fromSetting(self::LIVE);

        $this->assertNull($ranks->titleFor(0), 'a member who has written nothing');
        $this->assertNull($ranks->titleFor(9), 'one short of the first rank');
    }

    /**
     * Past the last threshold the last title stands.
     *
     * The busiest member on the reference forum has 50,878 postings, so under
     * this rule they are a Smutje and the ladder's top rung is **nobody's** —
     * 100,000 has never been reached by anyone. That is a property of the
     * configured ladder, not of this code, and it is fixed by editing the
     * setting rather than by changing anything here.
     *
     * @return void
     */
    public function testTheTopOfTheLadderDoesNotRunOut(): void
    {
        $ranks = Ranks::fromSetting(self::LIVE);

        $this->assertSame('Smutje', $ranks->titleFor(50000), 'at the threshold');
        $this->assertSame('Smutje', $ranks->titleFor(50878), 'the busiest member');
        $this->assertSame('Kpt. Ahab', $ranks->titleFor(100000), 'nobody has reached this');
        $this->assertSame('Kpt. Ahab', $ranks->titleFor(10000000), 'and it never runs out');
    }

    /**
     * No setting means no rank — never a guess.
     *
     * An installation that never configured this must show nothing. macfix is
     * that installation.
     *
     * @return void
     */
    public function testWithoutASettingThereIsNoRank(): void
    {
        foreach ([null, '', '   ', '|||'] as $value) {
            $ranks = Ranks::fromSetting($value);
            $this->assertTrue($ranks->isEmpty(), var_export($value, true));
            $this->assertNull($ranks->titleFor(500), var_export($value, true));
        }
    }

    /**
     * One mistyped segment must not take the ladder with it.
     *
     * @return void
     */
    public function testAMistypedSegmentIsDroppedNotFatal(): void
    {
        $ranks = Ranks::fromSetting('10=Erste|zwanzig=Zweite|30|40=|50=Letzte');

        $this->assertSame('Erste', $ranks->titleFor(15));
        $this->assertSame('Erste', $ranks->titleFor(45), 'the broken middle is skipped');
        $this->assertSame('Letzte', $ranks->titleFor(50));
    }

    /**
     * Whitespace around the parts is what hand-typing produces.
     *
     * @return void
     */
    public function testWhitespaceIsTolerated(): void
    {
        $ranks = Ranks::fromSetting('  10 = Erster Rang | 100 =  Zweiter  ');

        $this->assertSame('Erster Rang', $ranks->titleFor(10));
        $this->assertSame('Zweiter', $ranks->titleFor(100));
    }

    /**
     * A ladder entered out of order still reads bottom to top.
     *
     * The admin form is a free-text field; nothing makes anyone type the
     * thresholds in sequence.
     *
     * @return void
     */
    public function testOrderOfEntryDoesNotMatter(): void
    {
        $ranks = Ranks::fromSetting('1000=Maat|10=Fischbrötchen|100=Schiffsjunge');

        $this->assertSame('Fischbrötchen', $ranks->titleFor(10));
        $this->assertSame('Schiffsjunge', $ranks->titleFor(100));
        $this->assertSame('Maat', $ranks->titleFor(1000));
    }

    /**
     * A title is passed through as written, including anything that would be
     * dangerous unescaped — escaping is the view's job and the view does it.
     *
     * @return void
     */
    public function testTitleIsNotAlteredByTheParser(): void
    {
        $ranks = Ranks::fromSetting('10=<b>Fett</b> & frei');

        $this->assertSame('<b>Fett</b> & frei', $ranks->titleFor(10));
    }
}
