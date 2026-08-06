<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\User\Userranks;

/**
 * A ladder of titles a member climbs by writing.
 *
 * Saito had this until 2014, when it was moved out into a plugin and then out
 * of the project. The **settings survived**: on the reference installation
 * `userranks_ranks` still held a fully configured ladder, and `userranks_show`
 * was on — for eleven years, with nothing left to read either. Found by the
 * probe sweep on 2026-08-02.
 *
 * ```
 * 10=Fischbrötchen|100=Schiffsjunge|1000=Maat|5000=Bootsmann|…
 * ```
 *
 * **A threshold is the number a rank is earned at.** Ten postings make a
 * *Fischbrötchen*, a hundred a *Schiffsjunge*; below the lowest threshold a
 * member has **no rank yet** rather than the bottom one. Past the highest, the
 * top title stands and does not run out.
 *
 * The 2014 implementation read the thresholds the other way round — as upper
 * bounds, so fifty postings already made a Schiffsjunge and nobody was ever
 * rankless. Changed deliberately on 2026-08-02: a rank you get for writing
 * nothing is not a rank, and "earned at" is what a reader assumes the number
 * next to a title means.
 *
 * Absent or unparsable settings mean **no rank at all**, never a guess: an
 * installation that never configured this — macfix — must show nothing rather
 * than invent a ladder of its own.
 */
class Ranks
{
    /**
     * @var array<int, string> threshold => title, ascending by threshold
     */
    private array $ladder;

    /**
     * @param array<int, string> $ladder threshold => title
     */
    private function __construct(array $ladder)
    {
        ksort($ladder, SORT_NUMERIC);
        $this->ladder = $ladder;
    }

    /**
     * Reads the ladder out of the `userranks_ranks` setting.
     *
     * Tolerant on purpose: the value is typed into an admin form by hand, so a
     * trailing separator, stray whitespace and empty segments are all normal
     * and none of them should cost a member their rank. A segment without `=`,
     * or with a non-numeric threshold, is dropped rather than throwing — one
     * typo must not take the whole ladder down.
     *
     * @param string|null $setting raw setting value
     * @return self
     */
    public static function fromSetting(?string $setting): self
    {
        $ladder = [];
        foreach (explode('|', (string)$setting) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || !str_contains($segment, '=')) {
                continue;
            }
            [$threshold, $title] = explode('=', $segment, 2);
            $threshold = trim($threshold);
            $title = trim($title);
            if ($title === '' || !ctype_digit($threshold)) {
                continue;
            }
            $ladder[(int)$threshold] = $title;
        }

        return new self($ladder);
    }

    /**
     * The highest rank a posting count has reached.
     *
     * Null in two cases, and they are not the same thing: no ladder configured,
     * and a member who has not yet written enough for the first rung. Both come
     * out as "no row in the profile", which is the intent — a rank is something
     * to have earned.
     *
     * @param int $postings the member's posting count
     * @return string|null
     */
    public function titleFor(int $postings): ?string
    {
        $title = null;
        foreach ($this->ladder as $threshold => $candidate) {
            if ($postings < $threshold) {
                break;
            }
            $title = $candidate;
        }

        return $title;
    }

    /**
     * Whether anything was configured at all.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->ladder === [];
    }
}
