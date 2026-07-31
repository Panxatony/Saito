<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Model\Entity;

use Cake\ORM\Entity;
use Saito\App\Registry;
use Saito\Posting\Basic\BasicPostingInterface;
use Saito\Posting\Basic\BasicPostingTrait;
use Saito\Posting\Posting;
use Saito\Posting\PostingInterface;

class Entry extends Entity implements BasicPostingInterface
{
    use BasicPostingTrait;

    /**
     * What may be filled from an array, and — more to the point — what may not.
     *
     * Without this, Cake's default is `['*' => true]`: every column assignable
     * from whatever array reaches `newEntity()`/`patchEntity()`. Nothing was
     * exploitable, because all three call sites build their array field by
     * field and set `user_id`, `name` and `edited_by` from the current user
     * rather than from the request. But that is a convention held up by three
     * call sites, and the next one that hands `getData()` straight through
     * would give away authorship (`user_id`) and moderation state (`locked`,
     * `fixed`) without anything raising an objection.
     *
     * So the list below is the guarantee that the convention was standing in
     * for. The denied fields are reachable where they are genuinely needed, by
     * naming them explicitly at that one call site:
     *
     * - `user_id` — {@see EntriesTable::createEntry()}
     * - `tid` — set to the posting's own id when a thread is started
     * - `locked` / `fixed` — {@see EntriesTable::setPostingState()}
     *
     * `views`, `ip`, `created` and `modified` are set by the application and by
     * nobody else. `flattr` and `nsfw` are residue from Saito 5 that only grown
     * installations carry — no code reads them, and an assignable column no
     * code reads is exactly the kind of thing that goes unnoticed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        '*' => false,
        // The posting itself, as its author writes it.
        'pid' => true,
        'category_id' => true,
        'subject' => true,
        'text' => true,
        'name' => true,
        'time' => true,
        'last_answer' => true,
        'edited' => true,
        'edited_by' => true,
        'solves' => true,
    ];

    /**
     * Mutator for "text" property
     *
     * @param string $text content for "text"
     * @return null|string
     */
    //@codingStandardsIgnoreStart
    public function _setText(?string $text)
    {
    //@codingStandardsIgnoreEnd
        if (empty($text)) {
            return $text;
        }

        $markup = Registry::get('Markup');
        $text = $markup->preprocess($text);

        return $text;
    }

    /**
     * Convert entity to posting
     *
     * @return PostingInterface
     */
    public function toPosting(): PostingInterface
    {
        return new Posting($this->toArray());
    }
}
