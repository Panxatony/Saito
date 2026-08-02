<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\View\Helper;

use Cake\Core\Configure;
use Cake\I18n\DateTime as CakeDateTime;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Turns a stored instant into what a reader sees.
 *
 * Everything Saito stores is UTC — PHP runs on `APP_DEFAULT_TIMEZONE=UTC` and
 * the database connection is pinned with `timezone=UTC`, so the instant in the
 * column is unambiguous. What this helper does is render that instant in the
 * forum's own timezone (`Saito.Settings.timezone`).
 *
 * **It renders; it does not shift.** Until 2026-08-02 it added the offset to the
 * epoch and then formatted with `date()`, which runs under PHP's timezone. Two
 * mistakes that cancel: a shifted instant printed in UTC reads exactly like the
 * right instant printed in Berlin. Three things came out of that, and only the
 * middle one was ever written down:
 *
 * - The offset was computed once, in `beforeRender()`, from *now*. So in summer
 *   every winter posting was shown an hour late, and in winter every summer one
 *   an hour early. On production a posting stored at 16:48 UTC in January was
 *   displayed as 18:48 instead of 17:48.
 * - The `datetime` attribute carried the shifted value with the label `+00:00`,
 *   so anything reading it machine-side was out by the offset.
 * - "Today" began at midnight UTC, because `mktime()` follows PHP's timezone —
 *   and the forum's busiest hour is the one right after midnight, local.
 *
 * Rendering through `DateTimeZone` fixes all three at once, because PHP looks
 * up the offset that applied *at that instant* rather than the one applying
 * today.
 */
class TimeHHelper extends AppHelper
{

    public array $helpers = [
        'Time',
    ];

    protected static $_timezoneGroups = [
        'UTC' => DateTimeZone::UTC,
        'Africa' => DateTimeZone::AFRICA,
        'America' => DateTimeZone::AMERICA,
        'Antarctica' => DateTimeZone::ANTARCTICA,
        'Asia' => DateTimeZone::ASIA,
        'Atlantic' => DateTimeZone::ATLANTIC,
        'Europe' => DateTimeZone::EUROPE,
        'Indian' => DateTimeZone::INDIAN,
        'Pacific' => DateTimeZone::PACIFIC,
    ];

    /** @var int unix timestamp of current time */
    protected $_now = null;

    /** @var int unix timestamp of the start of today, in the forum's timezone */
    protected $_today;

    /** @var \DateTimeZone|null the timezone the forum reads its clock in */
    protected ?DateTimeZone $_displayTimezone = null;

    /**
     * {@inheritDoc}
     */
    public function beforeRender()
    {
        $this->readClock();
    }

    /**
     * Read the forum's timezone and fix "now" for this render.
     *
     * Called from `beforeRender()`, and again from `formatTime()` if that never
     * happened: `ThreadHtmlRenderer` reaches for this helper outside a view
     * render, so the hook is not guaranteed. That used to pass unnoticed because
     * the offset defaulted to zero — the thread list simply came out in UTC and
     * nobody could tell it apart from a helper that had not been set up.
     *
     * @return void
     */
    protected function readClock(): void
    {
        $timezone = Configure::read('Saito.Settings.timezone');
        $this->_displayTimezone = new DateTimeZone(!empty($timezone) ? $timezone : 'UTC');

        // Cake's clock rather than `time()`, so a test can freeze it — the
        // "today" boundary below is otherwise only testable on the day it
        // happens to be.
        $now = CakeDateTime::now();
        $this->_now = $now->getTimestamp();
        // Midnight *there*, not midnight UTC. The forum's busiest hour is the
        // one after local midnight; against a UTC boundary those postings were
        // filed under the previous day.
        $this->_today = $now->setTimezone($this->_displayTimezone)->startOfDay()->getTimestamp();
    }

    /**
     * The same instant, read in the forum's timezone.
     *
     * Via the epoch on purpose: whatever arrives — a `Cake\I18n\DateTime` off
     * the database, a plain `DateTime` — carries an instant, and that is the
     * only part worth keeping. `setTimezone()` then asks for the offset that
     * applied *at that instant*, which is what makes a January posting come out
     * at +01:00 while an August one comes out at +02:00.
     *
     * @param \DateTimeInterface $timestamp the stored instant
     * @return \DateTimeImmutable
     */
    protected function inDisplayTimezone(DateTimeInterface $timestamp): DateTimeImmutable
    {
        if ($this->_displayTimezone === null) {
            $this->readClock();
        }

        return (new DateTimeImmutable('@' . $timestamp->format('U')))
            ->setTimezone($this->_displayTimezone);
    }

    /**
     * Get timezone list for select popup
     *
     * @return array timezones
     */
    public function getTimezoneSelectOptions()
    {
        $options = [];
        foreach (self::$_timezoneGroups as $groupTitle => $groupId) {
            $timeZones = DateTimeZone::listIdentifiers($groupId);
            foreach ($timeZones as $timeZoneTitle) {
                $timezone = new DateTimeZone($timeZoneTitle);

                $timeInTimezone = new DateTime('now', $timezone);
                $timeDiffToUtc = $timeInTimezone->getOffset() / 3600;

                if ($timeDiffToUtc > 0) {
                    $timeDiffToUtc = '+' . $timeDiffToUtc;
                }

                $tz = $timeZoneTitle . ' – ' . $timeInTimezone->format('H:i');
                if ($timeDiffToUtc !== 0) {
                    $tz .= ' (' . $timeDiffToUtc . ')';
                }

                $options[$groupTitle][$timeZoneTitle] = $tz;
            }
        }

        return $options;
    }

    /**
     * Format timestamp to readable string
     *
     * @param DateTime $timestamp timestamp
     * @param string $format format
     * @param array $options options
     * @return string
     */
    public function formatTime(\DateTimeInterface $timestamp, $format = 'normal', array $options = []): string
    {
        // Stopwatch::start('formatTime');
        $options += ['wrap' => []];

        $local = $this->inDisplayTimezone($timestamp);

        switch ($format) {
            case 'normal':
                $string = $this->_formatRelative($local);
                break;
            case 'short':
                $string = $local->format('d.m.');
                break;
            case 'eng':
                $string = $local->format('Y-m-d H:i:s');
                break;
            default:
                // $format is a date()-style format string (strftime() is
                // deprecated since PHP 8.1).
                $string = $local->format($format);
        }

        if ($options['wrap'] !== false) {
            $string = $this->timeTag($string, $local, $options['wrap']);
        }

        // Stopwatch::stop('formatTime');

        return $string;
    }

    /**
     * Format timestamp relative to age
     *
     * @param \DateTimeInterface $local the instant, already in the forum's timezone
     * @return string formated time
     */
    protected function _formatRelative(DateTimeInterface $local)
    {
        $timestamp = $local->getTimestamp();

        if ($timestamp > $this->_today || $timestamp > ($this->_now - 21600)) {
            // today or in the last 6 hours
            $time = $local->format('H:i');
        } elseif ($timestamp > ($this->_today - 64800)) {
            // yesterday but in the last 18 hours
            $time = __('yesterday') . ' ' . $local->format('H:i');
        } else {
            // yesterday and 18 hours and older
            $time = $local->format('d.m.Y');
        }

        return $time;
    }

    /**
     * Create HTML time tag
     *
     * @performance Is used hundreds of times on homepage.
     *
     * @param string $content Content for tag
     * @param \DateTimeInterface $timestamp the instant, in the forum's timezone
     * @param array $options options will become attributes in time-tag
     * @return string HTML
     */
    public function timeTag($content, DateTimeInterface $timestamp, array $options = [])
    {
        $options += [
            // RFC 3339 off the localised object, so the offset written into the
            // attribute is the one the value is actually in.
            'datetime' => $timestamp->format(DATE_RFC3339),
            'title' => $timestamp->format('Y-m-d H:i:s'),
        ];
        $attributes = [];
        foreach ($options as $attribute => $value) {
            $attributes[$attribute] = "$attribute=\"$value\"";
        }
        $attributes = implode(' ', $attributes);
        $timestamp = "<time $attributes>$content</time>";

        return $timestamp;
    }

    /**
     * Converts time value to ISO time string
     *
     * @param mixed $date date
     * @return bool|null|string
     */
    public function dateToIso($date)
    {
        if ($date === null) {
            return null;
        }

        return dateToIso($date);
    }
}
