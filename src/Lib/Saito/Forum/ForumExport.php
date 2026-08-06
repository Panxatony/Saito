<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Saito\Forum;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use DateTimeInterface;
use Generator;
use const DATE_RFC3339;

/**
 * The whole forum's content, streamed one record at a time.
 *
 * The counterpart to {@see \Saito\User\DataExport}: that hands one member their
 * own data under GDPR; this hands the operator the entire forum, for a move or
 * for a content-level backup beside the SQL dump. On the reference forum that is
 * 66.5 MB of text across 680,292 postings and 5,540 uploads — far too much for a
 * request or for one array in memory, so every method is a generator that pages
 * its table by id and yields one record at a time. The command that drives it
 * writes them as JSON Lines: one self-describing JSON object per line, which
 * streams out and reads back in a line at a time.
 *
 * ## What it is not
 *
 * **An authentication backup.** Password hashes and activation codes stay out —
 * a plaintext content file is the wrong place for them, and they belong in the
 * SQL dump this sits beside. A forum rebuilt from this export alone would send
 * every member through a password reset.
 *
 * **The upload files.** Uploads are exported as metadata plus their URL, the
 * same choice the per-member export makes; the files themselves live under
 * `webroot/useruploads` and travel with a file-level backup, not inside a JSON
 * document as base64.
 *
 * ## Import-friendliness
 *
 * There is no importer yet, but the format is built so one is possible: every
 * record keeps its original id and names its `type`, and postings carry their
 * `user_id`, `reply_to`, `thread` and `category_id`, so the references between
 * records can be rebuilt. {@see self::FORMAT}/{@see self::FORMAT_VERSION} let an
 * importer refuse a shape it does not understand.
 */
class ForumExport
{
    /**
     * Names the on-disk format. An importer checks it before reading a line.
     *
     * @var string
     */
    public const FORMAT = 'saito-forum-export';

    /**
     * Bumped when a record shape changes in a way an old importer could not
     * read.
     *
     * @var int
     */
    public const FORMAT_VERSION = 1;

    /**
     * Rows per page. The result set is what costs the memory, not the loop; 500
     * keeps the working set at a few megabytes even for the posting table, and
     * paging by id stays stable while the forum is being written to.
     *
     * @var int
     */
    private const BATCH = 500;

    /**
     * The header record: what this file is, when it was made, and how much it
     * holds. Written as the first line so a reader — or an importer — knows the
     * format before the content starts.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return [
            'type' => 'meta',
            'format' => self::FORMAT,
            'version' => self::FORMAT_VERSION,
            'forum' => (string)Configure::read('Saito.Settings.forum_name'),
            'generated' => (new DateTime())->format(DATE_RFC3339),
            'counts' => [
                'users' => $this->table('Users')->find()->count(),
                'categories' => $this->table('Categories')->find()->count(),
                'postings' => $this->table('Entries')->find()->count(),
                'uploads' => $this->table('ImageUploader.Uploads')->find()->count(),
            ],
            'omitted' => 'password hashes and activation codes — restore accounts from the SQL dump',
        ];
    }

    /**
     * Every account, without its credentials. Enough to attribute a posting and
     * to recreate the member; not the password hash or activation code.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function eachUser(): Generator
    {
        yield from $this->pageById('Users', ['id', 'username', 'user_real_name', 'user_email', 'user_hp', 'user_place', 'registered', 'last_login', 'user_type', 'user_lock'], function (array $row): array {
            return [
                'type' => 'user',
                'id' => $row['id'],
                'username' => $row['username'],
                'real_name' => $row['user_real_name'],
                'email' => $row['user_email'],
                'homepage' => $row['user_hp'],
                'place' => $row['user_place'],
                'registered' => $this->stamp($row['registered']),
                'last_login' => $this->stamp($row['last_login']),
                'role' => $row['user_type'],
                'locked' => (bool)$row['user_lock'],
            ];
        });
    }

    /**
     * The categories, with their read/write accession levels so a moved forum
     * keeps the same permissions. Few enough not to need paging.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function eachCategory(): Generator
    {
        $rows = $this->table('Categories')->find()
            ->orderByAsc('id')
            ->disableHydration();
        foreach ($rows as $row) {
            yield [
                'type' => 'category',
                'id' => $row['id'],
                'name' => $row['category'],
                'description' => $row['description'],
                'order' => $row['category_order'],
                'accession' => $row['accession'],
                'accession_new_thread' => $row['accession_new_thread'],
                'accession_new_posting' => $row['accession_new_posting'],
            ];
        }
    }

    /**
     * Every posting, a batch at a time, each carrying its author and its place
     * in the thread. Same shape as the per-member export's posting, plus the
     * `user_id` that export leaves implicit.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function eachPosting(): Generator
    {
        yield from $this->pageById('Entries', ['id', 'user_id', 'pid', 'tid', 'subject', 'text', 'time', 'edited', 'category_id', 'views', 'locked', 'fixed', 'nsfw', 'ip'], function (array $row): array {
            return [
                'type' => 'posting',
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'reply_to' => $row['pid'],
                'thread' => $row['tid'],
                'category_id' => $row['category_id'],
                'subject' => $row['subject'],
                'text' => $row['text'],
                'written' => $this->stamp($row['time']),
                'edited' => $this->stamp($row['edited']),
                'views' => $row['views'],
                'locked' => (bool)$row['locked'],
                'pinned' => (bool)$row['fixed'],
                'nsfw' => (bool)$row['nsfw'],
                'ip' => ($row['ip'] ?? '') !== '' ? $row['ip'] : null,
            ];
        });
    }

    /**
     * Every upload as metadata plus its URL — not the file. The bytes live under
     * `webroot/useruploads` and belong in a file-level backup.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function eachUpload(): Generator
    {
        yield from $this->pageById('ImageUploader.Uploads', ['id', 'user_id', 'name', 'title', 'type', 'size', 'created'], function (array $row): array {
            return [
                'type' => 'upload',
                'id' => $row['id'],
                'user_id' => $row['user_id'],
                'name' => $row['name'],
                'title' => $row['title'],
                'mime' => $row['type'],
                'bytes' => $row['size'],
                'uploaded' => $this->stamp($row['created']),
                'url' => '/useruploads/' . $row['name'],
            ];
        });
    }

    /**
     * Page a table by ascending id and yield each row through a shaper.
     *
     * Keyset paging (`id >` the last one seen), not `offset`: it stays cheap on
     * a table of hundreds of thousands of rows and stable while that table is
     * being written to.
     *
     * @param string $table table registry alias
     * @param list<string> $fields columns to select
     * @param callable(array<string, mixed>): array<string, mixed> $shape row → record
     * @return \Generator<array<string, mixed>>
     */
    private function pageById(string $table, array $fields, callable $shape): Generator
    {
        $lastId = 0;
        while (true) {
            $rows = $this->table($table)->find()
                ->select($fields)
                ->where(['id >' => $lastId])
                ->orderByAsc('id')
                ->limit(self::BATCH)
                ->disableHydration()
                ->all()
                ->toList();

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = $row['id'];
                yield $shape($row);
            }
        }
    }

    /**
     * @param string $name table registry alias
     * @return \Cake\ORM\Table
     */
    private function table(string $name): Table
    {
        return TableRegistry::getTableLocator()->get($name);
    }

    /**
     * @param mixed $value a datetime, a string, or empty
     * @return string|null RFC 3339, or null when there is nothing
     */
    private function stamp(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_RFC3339);
        }

        return (new DateTime((string)$value))->format(DATE_RFC3339);
    }
}
