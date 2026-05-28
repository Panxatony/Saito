<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Model\Filter;

use Search\Model\Filter\FilterCollection;

/**
 * Advanced search filters for the Entries table.
 *
 * Auto-discovered by friendsofcake/search via the App\Model\Filter\<Alias>Collection
 * convention. Replaces the deprecated EntriesTable::searchManager() method.
 *
 * @see https://github.com/FriendsOfCake/search
 */
class EntriesCollection extends FilterCollection
{
    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        $this->like('subject', [
            'before' => true,
            'after' => true,
            'fieldMode' => 'OR',
            'comparison' => 'LIKE',
            'wildcardAny' => '*',
            'wildcardOne' => '?',
            'fields' => ['subject'],
            'filterEmpty' => true,
        ]);
        $this->like('text', [
            'before' => true,
            'after' => true,
            'fieldMode' => 'OR',
            'comparison' => 'LIKE',
            'wildcardAny' => '*',
            'wildcardOne' => '?',
            'fields' => ['text'],
            'filterEmpty' => true,
        ]);
        $this->value('name', ['filterEmpty' => true]);
    }
}
