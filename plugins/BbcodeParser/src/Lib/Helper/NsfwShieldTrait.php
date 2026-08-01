<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Plugin\BbcodeParser\src\Lib\Helper;

/**
 * Puts a cover over media an author has marked as not-safe-for-work.
 *
 * The marker travels in the BBCode itself — `[img src=upload nsfw=1]…[/img]` —
 * not in the database. That is deliberate and worth stating, because the
 * obvious alternative looks tidier and is not:
 *
 * - The tag stored in a posting carries only the upload's *file name*, so a
 *   renderer holds no upload row and never queries the `uploads` table. A flag
 *   on that table would mean a lookup per image, keyed on a column that carries
 *   no index.
 * - It also puts the decision in the right place. The same picture can be
 *   unremarkable in one thread and not in another; the author marks the
 *   *insertion*, not the file. The flip side, which nobody should discover by
 *   surprise: marking an upload afterwards cannot reach postings already
 *   written.
 *
 * What this is not: the files themselves are served straight off the disk by
 * the web server, at a URL anybody may request. The cover keeps a picture from
 * appearing unbidden on a screen someone else can see. It keeps nothing from
 * anybody who wants to look.
 *
 * No JavaScript. The cover is a checkbox and its label, and the reveal is a
 * `:checked` rule — so it works with scripting off, needs no event handler, and
 * has nothing for a content-security policy to object to. The alternative, an
 * island listening for clicks, would have been three moving parts where the
 * stylesheet alone will do.
 */
trait NsfwShieldTrait
{
    /**
     * Whether the author marked this piece of media not-safe-for-work.
     *
     * Any value counts except the ones that read as a denial, so `nsfw`,
     * `nsfw=1` and `nsfw=yes` all mark it while `nsfw=0` and `nsfw=false` do
     * not. Authors type these attributes by hand as often as the editor writes
     * them, and a marker that silently fails open is worse than no marker.
     *
     * Nullable on purpose: a tag written without attributes at all — plain
     * `[img]…[/img]`, which is most of them — is handed `null`, not an empty
     * array. Typed as `array` this threw on every such tag.
     *
     * @param array|null $attributes the tag's parsed attributes
     * @return bool
     */
    protected function _isNsfw(?array $attributes): bool
    {
        // The whole posting can be marked instead of each tag: an author who
        // ticks the box on a posting covers everything in it, and the eleven
        // hundred postings that carry the flag from Saito 4 get the cover
        // without their text being rewritten. Set per parse, never on the
        // shared settings object — see Parser::_initParser().
        if ($this->_sOptions->get('nsfw')) {
            return true;
        }

        if ($attributes === null || !array_key_exists('nsfw', $attributes)) {
            return false;
        }

        $value = strtolower(trim((string)$attributes['nsfw']));

        return !in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Wraps rendered media in its cover.
     *
     * The media keeps `pointer-events: none` while covered (see the
     * stylesheet). Without it the cover would only be a blur: a top-level
     * `[img]` is wrapped in a link to the full-size file, and a click meant to
     * reveal the picture would open it instead.
     *
     * The badge and its hints sit in their own `nsfwShield-tab` rather than
     * directly in the label. For a picture the label keeps covering the whole
     * area after the reveal — invisibly — so that clicking anywhere puts the
     * cover back; the tab is what is actually drawn in the corner. Two elements
     * because one cannot both fill the box and be a small tab in it.
     *
     * @param string $html the rendered media, already escaped by its caller
     * @param string $kind `image`, `video`, `audio` or `file` — only used to
     *     tell the reader what is underneath
     * @return string
     */
    protected function _wrapNsfw(string $html, string $kind = 'image'): string
    {
        // The id has to be unique across the whole page, and a posting's
        // rendered markup is cached, so it also has to survive being stored and
        // handed out again. Random rather than a counter for that reason: a
        // counter restarts at one for every posting the cache renders, and two
        // postings on one page would then share ids.
        $id = 'nsfw-' . bin2hex(random_bytes(8));

        $label = __('saito.nsfw.reveal');
        $hide = __('saito.nsfw.hide');

        return '<span class="nsfwShield" data-nsfw-kind="' . h($kind) . '">'
            . '<input type="checkbox" class="nsfwShield-toggle" id="' . $id . '">'
            . '<span class="nsfwShield-media">' . $html . '</span>'
            . '<label class="nsfwShield-veil" for="' . $id . '">'
            . '<span class="nsfwShield-tab">'
            . '<span class="nsfwShield-badge" aria-hidden="true">NSFW</span>'
            . '<span class="nsfwShield-hint nsfwShield-hint--show">' . h($label) . '</span>'
            . '<span class="nsfwShield-hint nsfwShield-hint--hide">' . h($hide) . '</span>'
            . '</span>'
            . '</label>'
            . '</span>';
    }
}
