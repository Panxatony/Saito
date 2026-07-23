<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Feeds\Model\Behavior;

use Cake\ORM\Behavior;
use Cake\ORM\Query;

class FeedsPostingBehavior extends Behavior
{
    /**
     * Implements the custom find type 'feed'
     *
     * Add parameters for generating a rss/json-feed with find('feed', …)
     *
     * @param Query $query query
     * @return Query
     */
    public function findFeed(Query $query)
    {
        // PERFORMANCE: both sort keys must run in the same direction. InnoDB
        // appends the primary key to every secondary index, so the
        // `last_answer` index is physically (last_answer, id) and can serve
        // `last_answer DESC, id DESC` straight from the index — the LIMIT 10
        // then stops after a few dozen rows. A mixed `… DESC, id ASC` cannot
        // be read off that index, so MariaDB fell back to the widest matching
        // index plus a filesort over every candidate row (measured on a
        // 680k-row production table: 118k rows sorted, ~4.5s per request,
        // versus ~0.01s now). The tie-break only decides the order of
        // postings sharing the very same `last_answer` second; in a
        // newest-first feed, newest-id-first is the natural choice anyway.
        return $query->contain('Users')
            ->orderBy(['last_answer' => 'DESC', 'Entries.id' => 'DESC'])
            ->limit(10);
    }
}
