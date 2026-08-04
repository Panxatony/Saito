<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace MailObfuscator\View\Helper;

use Cake\View\Helper;

/**
 * Mail Obfuscator Helper
 *
 * Emits a mail address as two `data-` attributes that the island bundle
 * reassembles into a `mailto:` link at runtime — so the address is not in the
 * markup for a scraper to read, but is there for a reader whose browser runs
 * scripts.
 *
 * **This used to carry an inline jQuery `<script>` that did the reassembly with
 * `.html()`.** Three things were wrong with it. It needed jQuery, which the
 * frontend dropped in 8.1.0, so the link stayed empty. It was an inline script,
 * which a strict `script-src` blocks. And `.html()` turned the decoded
 * attribute back into live markup — a stored-XSS path on any install that still
 * had jQuery and no CSP. The reassembly now lives in
 * `frontend/src/islands/features/mailObfuscator.ts`, which uses `textContent`.
 *
 * The attributes are escaped here as well. Every current caller passes text that
 * was already `h()`-escaped upstream (the BBCode preprocessor), so this is
 * defence in depth rather than the only guard — but a helper that builds HTML
 * should not depend on its callers to make its output safe.
 */
class MailObfuscatorHelper extends Helper
{
    /**
     * Generate an obfuscated mail link.
     *
     * `$title` is nullable because a bare `[email]addr[/email]` has no title and
     * the caller passes null for it.
     *
     * @param string $addr mail address
     * @param string|null $title link title
     * @return string
     */
    public function link(string $addr, ?string $title = null): string
    {
        [$ttl, $dom] = array_pad(explode('@', $addr, 2), 2, '');

        // The link text is the title when given, otherwise empty — the bundle
        // fills an empty link with the assembled address. Both go through h().
        return sprintf(
            '<a class="js-mailObfuscated" href="#" data-ttl="%s" data-dom="%s">%s</a>'
            . '<noscript><p>[You need to have Javascript enabled to see this mail address.]</p></noscript>',
            h($ttl),
            h($dom),
            h((string)$title),
        );
    }
}
