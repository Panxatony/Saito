<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Posting\Basic;

/**
 * Basic posting properties derived from a single posting alone
 */
interface BasicPostingInterface
{
    /**
     * Get posting property
     *
     * @param string $var key
     * @return mixed
     */
    public function get(string $var);

    /**
     * Whether the posting carries a property at all.
     *
     * get() throws for anything it does not hold, so a caller that can work
     * without a field needs a way to ask first. A posting can legitimately be
     * built from a partial row — a helper that only needs an id and a thread id,
     * for instance — and asking such a posting whether it is a thread root has
     * no answer rather than a wrong one.
     *
     * @param string $var key
     * @return bool
     */
    public function has(string $var): bool;

    /**
     * Check if posting is locked.
     *
     * @return bool
     */
    public function isLocked(): bool;

    /**
     * Check if posting is subject only.
     *
     * @return bool
     */
    public function isNt(): bool;

    /**
     * Check if posting is pinned
     *
     * @return bool
     */
    public function isPinned(): bool;

    /**
     * Check if posting is thread-root.
     *
     * @return bool
     */
    public function isRoot(): bool;
}
