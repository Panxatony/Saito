<?php
declare(strict_types=1);

namespace App\Test\TestCase\View\Helper;

use App\View\Helper\TimeHHelper;
use Cake\Core\Configure;
use Cake\I18n\DateTime as CakeDateTime;
use Cake\TestSuite\TestCase;
use Cake\View\View;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The helper that turns a stored instant into what a reader sees.
 *
 * Two things come out of it and they are not the same thing:
 *
 * - the **text**, which is a wall clock in the forum's timezone,
 * - the **`datetime` attribute**, which is machine-readable and is what feed
 *   readers, search engines and a browser's own tooltip go by.
 *
 * The text has been right for years. The attribute has not, and the reason it
 * went unnoticed for so long is that the helper produced the correct text by
 * making two mistakes that cancel: it added the timezone offset to the epoch
 * and then formatted with `date()`, which runs under PHP's timezone — UTC on
 * every Saito installation. A shifted instant printed in UTC reads exactly like
 * the right instant printed in Berlin. Only the offset label gives it away.
 *
 * These tests pin both halves separately, so the next change to this file
 * cannot fix one by breaking the other.
 *
 * The forum's timezone is a setting (`Saito.Settings.timezone`) and PHP's is
 * UTC, which is what `APP_DEFAULT_TIMEZONE` is set to on every install — the
 * tests state both explicitly rather than inheriting them.
 */
class TimeHHelperTest extends TestCase
{
    protected TimeHHelper $helper;

    protected string $phpTimezone;

    public function setUp(): void
    {
        parent::setUp();
        $this->phpTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        Configure::write('Saito.Settings.timezone', 'Europe/Berlin');
        $this->helper = new TimeHHelper(new View());
        $this->helper->beforeRender();
    }

    public function tearDown(): void
    {
        date_default_timezone_set($this->phpTimezone);
        CakeDateTime::setTestNow(null);
        parent::tearDown();
    }

    /**
     * Rebuild the helper after changing the setting or the frozen clock —
     * `beforeRender()` is where it reads both.
     *
     * @param string|null $timezone forum timezone, null to leave as is
     * @param string|null $now frozen "now" in UTC, null to leave as is
     * @return void
     */
    protected function rebuild(?string $timezone = null, ?string $now = null): void
    {
        if ($timezone !== null) {
            Configure::write('Saito.Settings.timezone', $timezone);
        }
        if ($now !== null) {
            CakeDateTime::setTestNow(new CakeDateTime($now, new DateTimeZone('UTC')));
        }
        $this->helper = new TimeHHelper(new View());
        $this->helper->beforeRender();
    }

    /**
     * An instant stored as UTC, as every instant in the database is.
     *
     * @param string $utc e.g. "2026-08-01 21:14:24"
     * @return DateTimeImmutable
     */
    protected function utc(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    /**
     * The text: a wall clock in the forum's timezone. Summer, so +2.
     *
     * This is what has always been correct and must stay byte for byte the
     * same — it is the whole safety net for the change underneath.
     *
     * @return void
     */
    public function testTextIsTheForumsWallClock(): void
    {
        $actual = $this->helper->formatTime($this->utc('2026-08-01 21:14:24'), 'eng', ['wrap' => false]);

        $this->assertSame('2026-08-01 23:14:24', $actual);
    }

    /**
     * …and winter is +1, from the same stored instant shape.
     *
     * Worth its own test because the offset is not a constant: a helper that
     * hard-codes one is right for half the year.
     *
     * @return void
     */
    public function testTextFollowsDaylightSaving(): void
    {
        $actual = $this->helper->formatTime($this->utc('2026-01-15 21:14:24'), 'eng', ['wrap' => false]);

        $this->assertSame('2026-01-15 22:14:24', $actual);
    }

    /**
     * The attribute must carry the offset it is actually in.
     *
     * This is the defect. Before the fix the helper emitted
     * `2026-08-01T23:14:24+00:00` — the value moved to Berlin, the label still
     * claiming UTC, so anything reading it machine-side is two hours out.
     *
     * @return void
     */
    public function testAttributeCarriesTheRightOffset(): void
    {
        $actual = $this->helper->formatTime($this->utc('2026-08-01 21:14:24'), 'eng');

        $this->assertStringContainsString('datetime="2026-08-01T23:14:24+02:00"', $actual);
    }

    /**
     * The same in winter — offset and value move together.
     *
     * @return void
     */
    public function testAttributeCarriesTheRightOffsetInWinter(): void
    {
        $actual = $this->helper->formatTime($this->utc('2026-01-15 21:14:24'), 'eng');

        $this->assertStringContainsString('datetime="2026-01-15T22:14:24+01:00"', $actual);
    }

    /**
     * The attribute and the text must describe the same instant.
     *
     * Reading them back independently is the check that does not care how the
     * helper arrives at either: parse the attribute, and it has to be the
     * instant that went in.
     *
     * @return void
     */
    public function testAttributeAndStoredInstantAreTheSameMoment(): void
    {
        $stored = $this->utc('2026-08-01 21:14:24');
        $html = $this->helper->formatTime($stored, 'eng');

        preg_match('/datetime="([^"]+)"/', $html, $m);
        $parsed = new DateTimeImmutable($m[1]);

        $this->assertSame($stored->getTimestamp(), $parsed->getTimestamp());
    }

    /**
     * An installation left on UTC gets UTC, not a silent Berlin.
     *
     * @return void
     */
    public function testUtcInstallationIsUnshifted(): void
    {
        $this->rebuild('UTC');

        $actual = $this->helper->formatTime($this->utc('2026-08-01 21:14:24'), 'eng');

        $this->assertStringContainsString('2026-08-01 21:14:24', $actual);
        $this->assertStringContainsString('datetime="2026-08-01T21:14:24+00:00"', $actual);
    }

    /**
     * A setting nobody has filled in must not throw, and must not guess.
     *
     * @return void
     */
    public function testMissingSettingFallsBackToUtc(): void
    {
        $this->rebuild('');

        $actual = $this->helper->formatTime($this->utc('2026-08-01 21:14:24'), 'eng', ['wrap' => false]);

        $this->assertSame('2026-08-01 21:14:24', $actual);
    }

    /**
     * "Today" begins at midnight in the forum's timezone, not in UTC.
     *
     * The posting below was written at 00:30 Berlin, which is 22:30 UTC on the
     * *previous* day. Against a UTC midnight it counts as yesterday and gets a
     * date; against Berlin's it is today and gets a time. A German forum is at
     * its busiest in exactly that hour — the daily histogram peaks at midnight.
     *
     * @return void
     */
    public function testTodayBeginsInTheForumsTimezone(): void
    {
        // 03:00 Berlin on 2 August; the posting is 2½ hours old.
        $this->rebuild('Europe/Berlin', '2026-08-02 01:00:00');

        $actual = $this->helper->formatTime($this->utc('2026-08-01 22:30:00'), 'normal', ['wrap' => false]);

        $this->assertSame('00:30', $actual);
    }

    /**
     * And something genuinely older still gets a date.
     *
     * Without this the test above would pass on a helper that simply prints a
     * time for everything.
     *
     * @return void
     */
    public function testOlderPostingsGetADate(): void
    {
        $this->rebuild('Europe/Berlin', '2026-08-02 01:00:00');

        $actual = $this->helper->formatTime($this->utc('2026-07-20 10:00:00'), 'normal', ['wrap' => false]);

        $this->assertSame('20.07.2026', $actual);
    }

    /**
     * The short format is a date in the forum's timezone too.
     *
     * 22:30 UTC is already the next day in Berlin, so this catches a helper
     * that formats the date from the unshifted instant.
     *
     * @return void
     */
    public function testShortFormatUsesTheForumsDate(): void
    {
        $actual = $this->helper->formatTime($this->utc('2026-08-01 22:30:00'), 'short', ['wrap' => false]);

        $this->assertSame('02.08.', $actual);
    }
}
