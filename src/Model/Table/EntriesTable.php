<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Model\Table;

use App\Lib\Model\Table\AppTable;
use App\Model\Entity\Entry;
use App\Model\Table\CategoriesTable;
use App\Model\Table\DraftsTable;
use Bookmarks\Model\Table\BookmarksTable;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\Event;
use Cake\ORM\Entity;
use Cake\ORM\Query;
use Cake\ORM\RulesChecker;
use Cake\Validation\Validator;
use Saito\Posting\PostingInterface;
use Saito\User\CurrentUser\CurrentUserInterface;
use Saito\Validation\SaitoValidationProvider;

/**
 * Stores postings
 *
 * Field notes:
 * - `edited_by` - Came from mylittleforum. @td Should by migrated to User.id.
 * - `name` - Came from mylittleforum. Is still used in fulltext index.
 *
 * @property BookmarksTable $Bookmarks
 * @property CategoriesTable $Categories
 * @property DraftsTable $Drafts
 */
class EntriesTable extends AppTable
{
    /**
     * Max subject length.
     *
     * Constrained to 191 due to InnoDB index max-length on MySQL 5.6.
     */
    public const SUBJECT_MAXLENGTH = 191;

    /**
     * Fields for search plugin
     *
     * @var array
     */
    public $filterArgs = [
        'subject' => ['type' => 'like'],
        'text' => ['type' => 'like'],
        'name' => ['type' => 'like'],
        'category' => ['type' => 'value'],
    ];

    /**
     * The fallback for `subject_maxlength`, used where the forum's settings are
     * not loaded — the console, mostly. In a request the admin setting wins; see
     * initialize().
     */
    protected array $_defaultConfig = [
        'subject_maxlength' => 100,
    ];

    /**
     * {@inheritDoc}
     */
    public function initialize(array $config): void
    {
        // Take the subject limit from the admin setting. Nothing did, so this
        // validated against the 100 above whatever an administrator had chosen —
        // and the *form* has always used the setting for its `maxlength`. Set it
        // above 100 and the field accepted what the server then refused, with no
        // hint as to why; the live forum sits at 101, so exactly one character
        // was affected. Capped by the constant the setting itself is validated
        // against, so a wider value cannot reach a column that will not hold it.
        $configured = (int)(\Cake\Core\Configure::read('Saito.Settings.subject_maxlength') ?: 0);
        if ($configured > 0) {
            $this->setConfig('subject_maxlength', min($configured, self::SUBJECT_MAXLENGTH));
        }

        $this->setPrimaryKey('id');

        $this->addBehavior('Posting');
        $this->addBehavior('IpLogging');
        $this->addBehavior('Timestamp');

        $this->addBehavior(
            'CounterCache',
            [
                // cache how many postings a user has
                'Users' => ['entry_count'],
                // cache how many threads a category has
                'Categories' => [
                    'thread_count' => function ($event, Entry $entity, $table, $original) {
                        if (!$entity->isRoot()) {
                            return false;
                        }
                        // posting is moved to new category…
                        if ($original) {
                            // update old category (should decrement counter)
                            $categoryId = $entity->getOriginal('category_id');
                        } else {
                            // update new category (increment counter)
                            $categoryId = $entity->get('category_id');
                        }

                        $query = $table->find('all')
                            ->where(['pid' => 0, 'category_id' => $categoryId]);
                        $count = $query->count();

                        return $count;
                    },
                ],
            ]
        );

        $this->belongsTo('Categories', ['foreignKey' => 'category_id']);
        $this->belongsTo('Users', ['foreignKey' => 'user_id']);

        $this->hasMany(
            'Bookmarks.Bookmarks',
            ['foreignKey' => 'entry_id', 'dependent' => true]
        );

        // Releation never queried. Just for quick access to the table.
        $this->hasOne('Drafts');
    }

    /**
     * {@inheritDoc}
     */
    public function validationDefault(Validator $validator): \Cake\Validation\Validator
    {
        $validator->setProvider('saito', SaitoValidationProvider::class);

        /// category_id
        $categoryRequiredL10N = __('vld.entries.categories.notEmpty');
        $validator
            ->notBlank('category_id', $categoryRequiredL10N)
            ->requirePresence('category_id', 'create', $categoryRequiredL10N);

        /// last_answer
        $validator
            ->requirePresence('last_answer', 'create')
            ->notEmptyDateTime('last_answer', null, 'create');

        /// name
        $validator
            ->requirePresence('name', 'create')
            ->notEmptyString('name', null, 'create');

        /// pid
        $validator->requirePresence('pid', 'create');

        /// subject
        $subjectRequiredL10N = __('vld.entries.subject.notEmpty');
        $validator
            ->notEmptyString('subject', $subjectRequiredL10N)
            ->requirePresence('subject', 'create', $subjectRequiredL10N)
            ->add(
                'subject',
                [
                    'maxLength' => [
                        'rule' => ['maxLength', (int)$this->getConfig('subject_maxlength')],
                        'message' => __('vld.entries.subject.maxlength'),
                    ],
                ]
            );

        /// time
        $validator
            ->requirePresence('time', 'create')
            ->notEmptyDateTime('time', null, 'create');

        /// user_id
        $validator
            ->requirePresence('user_id', 'create')
            ->add('user_id', ['numeric' => ['rule' => 'numeric']]);

        /// views
        $validator->add(
            'views',
            ['comparison' => ['rule' => ['comparison', '>=', 0]]]
        );

        return $validator;
    }

    /**
     * {@inheritDoc}
     */
    public function buildRules(RulesChecker $rules): \Cake\ORM\RulesChecker
    {
        $rules = parent::buildRules($rules);

        $rules->add(
            function ($entity) {
                if (!$entity->isDirty('solves') || empty($entity->get('solves') > 0)) {
                    return true;
                }

                return !$entity->isRoot();
            },
            'checkSolvesOnlyOnAnswers',
            [
                'errorField' => 'solves',
                'message' => 'Root postings cannot mark themself solved.',
            ]
        );

        return $rules;
    }

    /**
     * {@inheritDoc}
     */
    public function afterSave(\Cake\Event\EventInterface $event, Entity $entity, \ArrayObject $options)
    {
        if ($entity->isNew()) {
            $this->Drafts->deleteDraftForPosting($entity);

            /** @var Entry */
            $posting = $this->get($entity->get('id'));
            if ($posting->isRoot()) {
                /// New thread: set thread-ID to posting's own ID.
                // `tid` is not assignable in general (see Entry::$_accessible);
                // this is the one place that sets it, and it sets it to the
                // posting's own id rather than to anything a client sent.
                $patched = $this->patchEntity(
                    $posting,
                    ['tid' => $entity->get('id')],
                    ['accessibleFields' => ['tid' => true]]
                );
                if (!$this->save($patched)) {
                    $event->stopPropagation();
                }
                // Set it in the entity returned by the the save
                $entity->set('tid', $entity->get('id'));
            } else {
                /// New answer: update last answer time of root entry
                // @td Is this really necessary?
                $this->updateAll(
                    ['last_answer' => $posting->get('last_answer')],
                    ['id' => $posting->get('tid')]
                );
            }
        }
    }

    /**
     * Get an array of postings for threads
     *
     * @param array $tids Thread-IDs
     * @param array|null $order Thread sort order
     * @param CurrentUserInterface|null $CU Current User
     * @return array<PostingInterface>
     */
    public function postingsForThreads(array $tids, ?array $order = null, ?CurrentUserInterface $CU = null): array
    {
        return $this->postingBehavior()->postingsForThreads($tids, $order, $CU);
    }

    /**
     * Get a posting for a thread
     *
     * @param int $tid Thread-ID
     * @param bool $complete complete fieldset
     * @param CurrentUserInterface|null $CU CurrentUser
     * @return PostingInterface
     */
    public function postingsForThread(int $tid, bool $complete = false, ?CurrentUserInterface $CU = null): PostingInterface
    {
        return $this->postingBehavior()->postingsForThread($tid, $complete, $CU);
    }

    /**
     * Delete a posting and all its subpostings
     *
     * @param int $id the node id
     * @return bool
     */
    public function deletePosting(int $id): bool
    {
        return $this->postingBehavior()->deletePosting($id);
    }

    /**
     * Get recent postings
     *
     * @param CurrentUserInterface $User User who has access to postings
     * @param array $options find options
     * @return array<PostingInterface>
     */
    public function getRecentPostings(CurrentUserInterface $User, array $options = []): array
    {
        return $this->postingBehavior()->getRecentPostings($User, $options);
    }

    /**
     * Merge thread onto entry $targetId
     *
     * @param int $sourceId root-id of the posting that is merged onto another thread
     * @param int $targetId id of the posting the source-thread should be appended to
     * @return bool
     */
    public function threadMerge(int $sourceId, int $targetId): bool
    {
        return $this->postingBehavior()->threadMerge($sourceId, $targetId);
    }

    /**
     * Shorthand for reading an entry with full data.
     *
     * Signature widened in Cake 5: Table::get() now takes (mixed $primaryKey,
     * array|string $finder, ?CacheInterface|string $cache, Closure|string|null
     * $cacheKey, mixed ...$args). The previous (array $options) form is
     * replaced — Saito only ever called get($id) anyway.
     */
    public function get( // skipcq: PHP-W1079 - variadic ...$args must be last, so the defaults before it are required (Cake 5 Table::get() override)
        mixed $primaryKey,
        array|string $finder = 'all',
        \Psr\SimpleCache\CacheInterface|string|null $cache = null,
        \Closure|string|null $cacheKey = null,
        mixed ...$args
    ): \Cake\Datasource\EntityInterface {
        /** @var Entry $result */
        $result = $this->find('entry', complete: true)
            ->where([$this->getAlias() . '.id' => $primaryKey])
            ->first();

        if (empty($result)) {
            $msg = sprintf('Posting with ID "%s" not found.', $primaryKey);
            throw new RecordNotFoundException($msg);
        }

        return $result;
    }

    /**
     * Implements the custom find type 'entry'
     *
     * @param Query $query query
     * @param array $options options
     * - 'complete' bool controls fieldset selected as in getFieldset($complete)
     * @return Query
     */
    public function findEntry(Query $query, array $options = [])
    {
        $options += ['complete' => false];
        $query
            ->select($this->getFieldset($options['complete']))
            ->contain(['Users', 'Categories']);

        return $query;
    }

    /**
     * Get list of fields required to display posting.:w
     *
     * You don't want to fetch every field for performance reasons.
     *
     * @param bool $complete Threadline if false; Full posting if true
     * @return array The fieldset
     */
    public function getFieldset(bool $complete = false): array
    {
        // field list necessary for displaying a thread_line
        $threadLineFieldList = [
            'Categories.accession',
            'Categories.category',
            'Categories.description',
            'Categories.id',
            'Entries.fixed',
            'Entries.id',
            'Entries.last_answer',
            'Entries.locked',
            'Entries.name',
            'Entries.pid',
            'Entries.solves',
            'Entries.subject',
            // Entry.text determines if Entry is n/t
            'Entries.text',
            'Entries.tid',
            'Entries.time',
            'Entries.user_id',
            'Entries.views',
            'Users.username',
        ];

        // fields additional to $threadLineFieldList to show complete entry
        $showEntryFieldListAdditional = [
            'Entries.category_id',
            'Entries.edited',
            'Entries.edited_by',
            'Entries.ip',
            'Users.avatar',
            'Users.id',
            'Users.signature',
            'Users.user_type',
            'Users.user_place',
        ];

        $fields = $threadLineFieldList;
        if ($complete) {
            $fields = array_merge($fields, $showEntryFieldListAdditional);
        }

        return $fields;
    }

    /**
     * Finds the thread-IT for a posting.
     *
     * @param int $id Posting-Id
     * @return int Thread-Id
     * @throws RecordNotFoundException If posting isn't found
     */
    public function getThreadId($id)
    {
        $entry = $this->find('all')
            ->where(['id' => $id])
            ->select(['tid'])
            ->first();
        if (empty($entry)) {
            throw new RecordNotFoundException(
                'Posting not found. Posting-Id: ' . $id
            );
        }

        return $entry->get('tid');
    }

    /**
     * creates a new root or child entry for a node
     *
     * What may be filled is decided by `Entry::$_accessible`, not by this
     * method — the docblock used to claim "fields in $data are filtered" here,
     * which was never true of this method and read as a guarantee that lived
     * somewhere it did not. `user_id` is named explicitly because creating a
     * posting is the one moment it is set at all, and the caller takes it from
     * the current user rather than from the request.
     *
     * @param array $data data
     * @return Entry|null on success, null otherwise
     */
    public function createEntry(array $data): ?Entry
    {
        $data['time'] = bDate();
        $data['last_answer'] = bDate();

        /** @var Entry */
        $posting = $this->newEntity($data, ['accessibleFields' => ['user_id' => true]]);
        $errors = $posting->getErrors();
        if (!empty($errors)) {
            return $posting;
        }

        /** @var Entry */
        $posting = $this->save($posting);
        if (empty($posting)) {
            return null;
        }

        $eventData = ['subject' => $posting->get('pid'), 'data' => $posting];
        $this->dispatchDbEvent('Model.Entry.replyToEntry', $eventData);

        return $posting;
    }

    /**
     * Updates a posting with new data
     *
     * What may be filled is decided by `Entry::$_accessible`. Moderation state
     * (`locked`, `fixed`) is deliberately not among it and cannot be set here —
     * {@see setPostingState()} exists for that, so a caller cannot reach it by
     * accident while updating a posting's text.
     *
     * @param Entry $posting Entity
     * @param array $data data
     * @return Entry|null
     */
    public function updateEntry(Entry $posting, array $data): ?Entry
    {
        // `id` used to be written into $data here. It never did anything —
        // patchEntity() works on the entity it is given, which already knows
        // its id — and now that `id` is not assignable it would be dropped
        // anyway. Removed rather than left to look meaningful.

        /** @var Entry */
        $patched = $this->patchEntity($posting, $data);
        $errors = $patched->getErrors();
        if (!empty($errors)) {
            return $patched;
        }

        /** @var Entry */
        $new = $this->save($posting);
        if (empty($new)) {
            return null;
        }

        $this->dispatchDbEvent(
            'Model.Entry.update',
            ['subject' => $posting->get('id'), 'data' => $posting]
        );

        return $new;
    }

    /**
     * Sets a posting's moderation state: pinned (`fixed`) or closed (`locked`).
     *
     * Its own method rather than a call to {@see updateEntry()}, because these
     * two fields are the ones a request must never be able to set in passing.
     * They are denied in `Entry::$_accessible` and named only here, where the
     * caller has already been through
     * `authorizeAction('ajaxToggle', 'saito.core.posting.pinAndLock')`.
     *
     * @param Entry $posting the posting
     * @param string $field `locked` or `fixed`
     * @param bool $value the new state
     * @return Entry|null the saved posting, null on failure
     */
    public function setPostingState(Entry $posting, string $field, bool $value): ?Entry
    {
        if (!in_array($field, ['locked', 'fixed'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Not a moderation state: %s', $field),
                1785520000
            );
        }

        $patched = $this->patchEntity(
            $posting,
            [$field => $value],
            ['accessibleFields' => [$field => true]]
        );
        if (!empty($patched->getErrors())) {
            return null;
        }

        /** @var Entry|null */
        return $this->save($patched) ?: null;
    }

    /**
     * {@inheritDoc}
     */
    public function beforeMarshal(Event $event, \ArrayObject $data, \ArrayObject $options)
    {
        /// Trim whitespace on subject and text
        $toTrim = ['subject', 'text'];
        foreach ($toTrim as $field) {
            if (!empty($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
    }

    /**
     * Deletes posting incl. all its subposting and associated data
     *
     * @param array $idsToDelete Entry ids which should be deleted
     * @return bool
     */
    public function deleteWithIds(array $idsToDelete): bool
    {
        $success = $this->deleteAll(['id IN' => $idsToDelete]);

        if (!$success) {
            return false;
        }

        // @td Should be covered by dependent assoc. Add tests.
        $this->Bookmarks->deleteAll(['entry_id IN' => $idsToDelete]);

        $this->dispatchSaitoEvent(
            'saito.core.posting.delete.after',
            ['subject' => $idsToDelete, 'table' => $this]
        );

        return true;
    }

    /**
     * Anonymizes the entries for a user
     *
     * @param int $userId user-ID
     * @return void
     */
    public function anonymizeEntriesFromUser(int $userId): void
    {
        // remove username from all entries and reassign to anonyme user
        $success = (bool)$this->updateAll(
            [
                'edited_by' => null,
                'ip' => null,
                'name' => null,
                'user_id' => 0,
            ],
            ['user_id' => $userId]
        );

        if ($success) {
            $this->dispatchDbEvent('Cmd.Cache.clear', ['cache' => 'Thread']);
        }
    }

    /**
     * Implements the custom find type 'index paginator'
     *
     * @param Query $query query
     * @param array $options finder options
     * @return Query
     */
    public function findIndexPaginator(Query $query, array $options)
    {
        $query
            ->select(['id', 'pid', 'tid', 'time', 'last_answer', 'fixed'])
            ->where(['Entries.pid' => 0]);

        if (!empty($options['counter'])) {
            $query->counter($options['counter']);
        }

        return $query;
    }

    /**
     * The Posting behavior, typed.
     *
     * getBehavior() is declared as returning the base Behavior, so calling the
     * behavior's own methods on it reads as calling undefined methods. Funnel
     * the access through here instead of annotating five call sites.
     *
     * @return \App\Model\Behavior\PostingBehavior
     */
    private function postingBehavior(): \App\Model\Behavior\PostingBehavior
    {
        /** @var \App\Model\Behavior\PostingBehavior $behavior */
        $behavior = $this->getBehavior('Posting');

        return $behavior;
    }

}
