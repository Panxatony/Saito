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

use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use DateTimeInterface;
use Generator;
use const DATE_RFC3339;

/**
 * Everything the forum holds about one member, for that member.
 *
 * GDPR Art. 15 gives a person the right to a copy of their personal data and
 * Art. 20 the right to have it in a structured, machine-readable form. Saito had
 * no way to answer either except by hand.
 *
 * ## What is deliberately left out
 *
 * **Credentials.** The password hash and the activation code are not
 * "information about the person" in any useful sense, and a file that a member
 * downloads, mails to themselves and forgets in a Downloads folder is the last
 * place a password hash should be.
 *
 * **Other people.** Art. 15(4) says the right to a copy must not adversely
 * affect the rights of others, and three places in Saito's schema hold someone
 * else's data next to this member's:
 *
 * - `user_ignores.blocked_user_id` pointing *at* them — who has chosen to ignore
 *   this member is those people's decision, not this member's data.
 * - `user_blocks.blocked_by_user_id` — the block itself is about them and is
 *   included; which moderator imposed it is not, so the export says a moderator
 *   did, not who.
 * - Anything from a posting that is not theirs, including the postings they
 *   edited as a moderator: those are listed by id and date so the record of
 *   *their* action survives, without carrying the other author's text along.
 *
 * **`ip`** goes the other way and is included: on their own postings it is their
 * address. This installation stores none (`store_ip` is off), but an
 * installation that does must hand them over.
 *
 * ## Size, and why this streams
 *
 * The first version built the whole document as an array and then encoded it.
 * Measured before shipping, on the reference forum:
 *
 * | account | postings | JSON | peak memory |
 * |---|---|---|---|
 * | a typical heavy writer | 15,624 | 9.1 MB | **59 MB** |
 * | the busiest member | 50,871 | 24.9 MB | **174 MB** |
 *
 * Production runs on `memory_limit = 128M`. So the busiest member's export would
 * not have been slow — it would have died with "allowed memory size exhausted",
 * and only for the people with the most to export. The estimate that led there
 * came from the *text* size, 3 MB, which is what the data weighs and not what
 * holding it costs.
 *
 * So postings are streamed: read in batches, encoded one at a time, written out
 * and dropped. Everything else is small — `user_reads` turns out to be a marker
 * table rather than a log, at most 25 rows for anybody — and is assembled
 * normally. The whole-forum export is a different problem; see todo.md.
 */
class DataExport
{
    /**
     * @param int $userId the member asking for their own data
     */
    public function __construct(private int $userId)
    {
    }

    /**
     * Everything except the postings, which stream separately.
     *
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        return [
            'export' => [
                'generated' => (new DateTime())->format(DATE_RFC3339),
                'about' => 'Personal data held by this forum about one member, '
                    . 'assembled under GDPR Art. 15/20.',
                'omitted' => [
                    'credentials' => 'password hash and activation code',
                    'other people' => 'who ignores this member, which moderator '
                        . 'imposed a block, and the content of postings by others',
                ],
            ],
            'account' => $this->account(),
            'preferences' => $this->preferences(),
            'drafts' => $this->drafts(),
            'uploads' => $this->uploads(),
            'bookmarks' => $this->bookmarks(),
            'blocks_against_this_account' => $this->blocks(),
            'people_this_account_ignores' => $this->ignores(),
            'moderation_by_this_account' => $this->moderationActions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function account(): array
    {
        $u = $this->users()->get($this->userId);

        return [
            'id' => $u->get('id'),
            'username' => $u->get('username'),
            'real_name' => $u->get('user_real_name'),
            'email' => $u->get('user_email'),
            'homepage' => $u->get('user_hp'),
            'place' => $u->get('user_place'),
            'signature' => $u->get('signature'),
            'profile_text' => $u->get('profile'),
            'avatar' => $u->get('avatar'),
            'role' => $u->get('user_type'),
            'registered' => $this->stamp($u->get('registered')),
            'last_login' => $this->stamp($u->get('last_login')),
            'logins' => $u->get('logins'),
            'postings_written' => $u->get('entry_count'),
            'locked' => (bool)$u->get('user_lock'),
        ];
    }

    /**
     * The display settings a member has chosen. Not interesting to read, but it
     * is data held about them and it is what they would need to recreate their
     * account elsewhere.
     *
     * @return array<string, mixed>
     */
    private function preferences(): array
    {
        $u = $this->users()->get($this->userId);
        $keys = [
            'user_theme', 'user_forum_refresh_time', 'user_automaticaly_mark_as_read',
            'user_sort_last_answer', 'user_show_thread_collapsed', 'inline_view_on_click',
            'user_signatures_hide', 'user_signatures_images_hide',
            'user_color_new_postings', 'user_color_actual_posting', 'user_color_old_postings',
            'user_category_override', 'user_category_active', 'user_category_custom',
            'slidetab_order', 'personal_messages',
        ];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $u->get($key);
        }

        return $out;
    }

    /**
     * The member's postings, a batch at a time.
     *
     * Batched rather than one big query: the result set is what costs the
     * memory, not the loop. 500 keeps the working set at a few megabytes for
     * even the busiest account, and the ordering by id makes the paging stable
     * while somebody is writing.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function eachPosting(): Generator
    {
        $batch = 500;
        $lastId = 0;

        while (true) {
            $rows = $this->table('Entries')->find()
                ->select([
                    'id', 'pid', 'tid', 'subject', 'text', 'time', 'edited',
                    'category_id', 'views', 'locked', 'fixed', 'nsfw', 'ip',
                ])
                ->where(['user_id' => $this->userId, 'id >' => $lastId])
                ->orderByAsc('id')
                ->limit($batch)
                ->disableHydration()
                ->all()
                ->toList();

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $lastId = $row['id'];
                yield $this->posting($row);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function posting(array $row): array
    {
        return [
                'id' => $row['id'],
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
                // Their own address, on their own posting. Null on an
                // installation that does not store it.
                'ip' => $row['ip'] !== '' ? $row['ip'] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drafts(): array
    {
        $out = [];
        foreach ($this->table('Drafts')->find()->where(['user_id' => $this->userId])->disableHydration() as $row) {
            $out[] = [
                'reply_to' => $row['pid'],
                'subject' => $row['subject'],
                'text' => $row['text'],
                'saved' => $this->stamp($row['modified'] ?? $row['created']),
            ];
        }

        return $out;
    }

    /**
     * Metadata, not the files. A member can fetch their own uploads at the URLs
     * listed here; putting five hundred images inside a JSON document as base64
     * would quadruple it for no gain.
     *
     * @return list<array<string, mixed>>
     */
    private function uploads(): array
    {
        $out = [];
        $rows = $this->table('ImageUploader.Uploads')->find()
            ->where(['user_id' => $this->userId])->disableHydration();
        foreach ($rows as $row) {
            $out[] = [
                'name' => $row['name'],
                'title' => $row['title'],
                'type' => $row['type'],
                'bytes' => $row['size'],
                'uploaded' => $this->stamp($row['created']),
                'url' => '/useruploads/' . $row['name'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bookmarks(): array
    {
        $out = [];
        $rows = $this->table('Bookmarks.Bookmarks')->find()
            ->where(['user_id' => $this->userId])->disableHydration();
        foreach ($rows as $row) {
            $out[] = [
                'posting_id' => $row['entry_id'],
                'comment' => $row['comment'],
                'created' => $this->stamp($row['created']),
            ];
        }

        return $out;
    }

    /**
     * Blocks imposed on this member.
     *
     * The reason is included — it is a statement about them and they are
     * entitled to it. `blocked_by_user_id` is not: that identifies another
     * person, and Art. 15(4) is explicit that the copy must not come at their
     * expense.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(): array
    {
        $out = [];
        $rows = $this->table('UserBlocks')->find()
            ->where(['user_id' => $this->userId])->disableHydration();
        foreach ($rows as $row) {
            $out[] = [
                'reason' => $row['reason'],
                'from' => $this->stamp($row['created']),
                'until' => $this->stamp($row['ends']),
                'lifted' => $this->stamp($row['ended']),
                'imposed_by' => 'a moderator',
            ];
        }

        return $out;
    }

    /**
     * Who this member has chosen to ignore — their own list, their own data.
     *
     * The mirror image, who ignores *them*, is not here and must not be: that
     * is other people's decision about their own reading.
     *
     * @return list<array<string, mixed>>
     */
    private function ignores(): array
    {
        $out = [];
        $rows = $this->table('UserIgnores')->find()
            ->where(['user_id' => $this->userId])->disableHydration();
        foreach ($rows as $row) {
            $out[] = [
                'user_id' => $row['blocked_user_id'],
                'since' => $this->stamp($row['created']),
            ];
        }

        return $out;
    }

    /**
     * Postings this member edited that are not their own.
     *
     * The record that they did it is about them; the posting is not. So it is
     * an id and a date, with no subject and no text.
     *
     * @return list<array<string, mixed>>
     */
    private function moderationActions(): array
    {
        $out = [];
        $rows = $this->table('Entries')->find()
            ->select(['id', 'edited'])
            ->where(['edited_by' => $this->userId, 'user_id !=' => $this->userId])
            ->disableHydration();
        foreach ($rows as $row) {
            $out[] = [
                'edited_posting_id' => $row['id'],
                'edited' => $this->stamp($row['edited']),
            ];
        }

        return $out;
    }

    /**
     * @param string $name table alias
     * @return \Cake\ORM\Table
     */
    private function table(string $name): Table
    {
        return TableRegistry::getTableLocator()->get($name);
    }

    /**
     * @return \Cake\ORM\Table
     */
    private function users(): Table
    {
        return $this->table('Users');
    }

    /**
     * A timestamp a machine can read, or null.
     *
     * RFC 3339 with the real offset — the same correction the display layer
     * received in 8.3.11, and for the same reason: a time without its offset is
     * a number somebody has to guess at.
     *
     * @param mixed $value
     * @return string|null
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
