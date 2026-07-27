<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\User;

/**
 * Which of the front page's right-rail widgets a member keeps minimised.
 *
 * Stored in `users.slidetab_order`, the column the retired slidetabs used for
 * the same job — which arrangement of the rail a member prefers. Reusing it
 * keeps this a code change rather than a migration, and the column is free on
 * an island installation because nothing renders slidetabs there any more.
 *
 * The stored shape stays what that column always held: a serialised list of
 * short identifiers, capped so it cannot outgrow the 512-character column.
 * Anything unreadable is treated as "nothing minimised" rather than as an
 * error — a preference is not worth failing a page render over.
 */
class WidgetPreferences
{
    /** @var int the column is VARCHAR(512); stay well inside it */
    private const MAX_LENGTH = 480;

    /** @var int no interface offers more than a handful of widgets */
    private const MAX_ITEMS = 12;

    /**
     * Read the minimised widgets from a stored value.
     *
     * @param string|null $stored raw column value
     * @param list<string> $known identifiers the current interface offers
     * @return list<string> minimised widgets, in the order given
     */
    public static function read(?string $stored, array $known): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        // `unserialize` on user-influenced input: restrict to plain values, so a
        // crafted payload cannot instantiate anything.
        //
        // Malformed input makes it emit a warning and return false. We want the
        // false and not the warning — the column still holds values written by
        // Saito 5, and a member whose stored widget list predates a rename would
        // otherwise fill the log on every page view. Silenced with a handler
        // scoped to this one call rather than `@`, which would also hide
        // anything else that went wrong inside it.
        set_error_handler(static fn (): bool => true);
        try {
            $value = unserialize($stored, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        if (!is_array($value)) {
            return [];
        }

        $clean = [];
        foreach ($value as $item) {
            if (is_string($item) && in_array($item, $known, true) && !in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    /**
     * Turn a submitted list into a value safe to store.
     *
     * @param array $submitted whatever arrived in the request
     * @param list<string> $known identifiers the current interface offers
     * @return string serialised value, ready for the column
     */
    public static function write(array $submitted, array $known): string
    {
        $clean = [];
        foreach ($submitted as $item) {
            if (!is_string($item) || !in_array($item, $known, true) || in_array($item, $clean, true)) {
                continue;
            }
            $clean[] = $item;
            if (count($clean) >= self::MAX_ITEMS) {
                break;
            }
        }

        $serialised = serialize($clean);

        // Belt and braces: the allow-list already bounds this, but a column
        // overflow would truncate mid-serialisation and produce a value that
        // never unserialises again.
        return strlen($serialised) <= self::MAX_LENGTH ? $serialised : serialize([]);
    }
}
