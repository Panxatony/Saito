<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Thread;

use Saito\Posting\PostingInterface;

/**
 * Class Thread collection of Postings
 */
class Thread
{

    protected $_Postings = [];

    protected $_rootId;

    /**
     * Whether _rootId came from a posting that says it is the root, rather than
     * from the fallback below.
     *
     * @var bool
     */
    protected $_rootIsKnown = false;

    protected $_unread = 0;

    /**
     * Add posting to thread
     *
     * @param PostingInterface $posting posting
     * @return void
     */
    public function add(PostingInterface $posting)
    {
        $id = $posting->get('id');
        $this->_Postings[$id] = $posting;

        // The root is the posting that has no parent — that is what the data
        // says, via `pid == 0`. This used to take the smallest id instead, which
        // holds right up until a merge re-parents an older thread onto a newer
        // one: from then on the smallest id in the thread belongs to a *child*,
        // and everything derived from "the root" is derived from the wrong
        // posting — the last-answer stamp that decides whether cached thread
        // lines are still valid, and which posting counts as the ignored thread
        // starter.
        // has() first: a posting can be built from a partial row that carries no
        // `pid` at all, and this runs from Posting's constructor — asking such a
        // posting whether it is a root would have turned every one of them into
        // an exception.
        if ($posting->has('pid') && $posting->isRoot()) {
            $this->_rootId = $id;
            $this->_rootIsKnown = true;

            return;
        }

        // A collection does not have to contain its own root: a subtree can be
        // built on its own. Rather than leave get('root') without an answer,
        // keep the old smallest-id guess for that case — but never let it
        // override a posting that actually said it was the root.
        if (!$this->_rootIsKnown && ($this->_rootId === null || $id < $this->_rootId)) {
            $this->_rootId = $id;
        }
    }

    /**
     * Get posting from thread
     *
     * @param int|string $id posting-ID
     * - <int> - Posting with that id
     * - 'root' - Root-posting
     * @return PostingInterface
     */
    public function get($id): PostingInterface
    {
        if ($id === 'root') {
            $id = $this->_rootId;
        }

        return $this->_Postings[$id];
    }

    /**
     * Get time of last answer in thread.
     *
     * @return int
     */
    public function getLastAnswer(): int
    {
        /** @var \DateTime */
        $lastAnswer = $this->get('root')->get('last_answer');

        return $lastAnswer->getTimestamp();
    }

    /**
     * Count postings in thread.
     *
     * @return int
     */
    public function count(): int
    {
        return count($this->_Postings);
    }

    /**
     * Count unread posting in thread.
     *
     * @return int
     */
    public function countUnread(): int
    {
        $unread = 0;
        foreach ($this->_Postings as $posting) {
            if ($posting->isUnread()) {
                $unread++;
            }
        }

        return $unread;
    }
}
