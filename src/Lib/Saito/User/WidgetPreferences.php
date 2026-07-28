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
 * How a member arranges the front page's right-rail widgets: in which order
 * they sit, and which of them are kept minimised.
 *
 * Stored in `users.slidetab_order` — not a borrowed column but the original
 * one: the retired slidetabs kept their drag-and-drop order there, and
 * installations upgraded from Saito 5 still hold those lists. Keeping the rail
 * arrangement here makes this a code change rather than a migration.
 *
 * Two shapes can arrive out of that column, and they are told apart by their
 * keys rather than by a version marker:
 *
 * - `['order' => [...], 'min' => [...]]` — what this class writes.
 * - a plain list — everything written before the rail could be reordered, when
 *   the list *was* the minimised set. Read as exactly that, so nobody's folded
 *   widgets spring open on the deploy that brings ordering.
 *
 * A Saito 5 list survives both branches: it is a plain list, and its
 * `slidetab_*` identifiers are not in the current catalogue, so it cleans away
 * to nothing. Anything unreadable is treated as "no preference" rather than as
 * an error — an arrangement is not worth failing a page render over.
 */
class WidgetPreferences
{
    /** @var int the column is VARCHAR(512); stay well inside it */
    private const MAX_LENGTH = 480;

    /** @var int no interface offers more than a handful of widgets */
    private const MAX_ITEMS = 12;

    /**
     * Read a member's rail arrangement from a stored value.
     *
     * `order` always names every known widget: a member who arranged the rail
     * before a new widget existed should still see the new one, and appending
     * it is the only placement that does not shuffle what they chose.
     *
     * @param string|null $stored raw column value
     * @param list<string> $known identifiers the current interface offers
     * @return array{order: list<string>, minimised: list<string>}
     */
    public static function read(?string $stored, array $known): array
    {
        $value = self::decode($stored);

        // No 'order' key means the legacy shape, where the whole list was the
        // minimised set. `array_is_list` keeps a map from being read that way.
        $legacy = array_is_list($value) ? $value : [];
        $order = self::clean($value['order'] ?? [], $known);
        $minimised = self::clean($value['min'] ?? $legacy, $known);

        foreach ($known as $id) {
            if (!in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return ['order' => $order, 'minimised' => $minimised];
    }

    /**
     * Turn a submitted arrangement into a value safe to store.
     *
     * @param array $order the order the widgets were dragged into
     * @param array $minimised which of them are kept as icons
     * @param list<string> $known identifiers the current interface offers
     * @return string serialised value, ready for the column
     */
    public static function write(array $order, array $minimised, array $known): string
    {
        $data = [
            'order' => self::clean($order, $known),
            'min' => self::clean($minimised, $known),
        ];

        // The allow-list already bounds this, but a column overflow would
        // truncate mid-serialisation and produce a value that never unserialises
        // again. So shed the arrangement in the order it can best be spared:
        // the full preference, then the order alone (a widget the member has to
        // fold again is a smaller loss than a rail that reshuffles itself), then
        // nothing at all.
        foreach ([$data, ['order' => $data['order']], []] as $candidate) {
            $serialised = serialize($candidate);
            if (strlen($serialised) <= self::MAX_LENGTH) {
                return $serialised;
            }
        }

        return serialize([]);
    }

    /**
     * Unserialise a stored value without letting a crafted one build objects.
     *
     * @param string|null $stored raw column value
     * @return array plain array, empty when the value is unusable
     */
    private static function decode(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        // Malformed input makes `unserialize` emit a warning and return false.
        // We want the false and not the warning — the column still holds values
        // written by Saito 5, and a member whose stored list predates a rename
        // would otherwise fill the log on every page view. Silenced with a
        // handler scoped to this one call rather than `@`, which would also hide
        // anything else that went wrong inside it.
        set_error_handler(static fn (): bool => true);
        try {
            $value = unserialize($stored, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        return is_array($value) ? $value : [];
    }

    /**
     * Reduce whatever arrived to known identifiers, each at most once.
     *
     * @param mixed $items candidate identifiers
     * @param list<string> $known identifiers the current interface offers
     * @return list<string>
     */
    private static function clean(mixed $items, array $known): array
    {
        if (!is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (!is_string($item) || !in_array($item, $known, true) || in_array($item, $clean, true)) {
                continue;
            }
            $clean[] = $item;
            if (count($clean) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $clean;
    }
}
