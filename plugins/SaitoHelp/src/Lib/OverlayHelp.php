<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace SaitoHelp\Lib;

use Cake\Core\Plugin;

/**
 * The guided tour shown in the help overlay, as Markdown.
 *
 * The text used to be a hand-written PHP template: one language, one wording,
 * editable only by changing code that an update would overwrite. It is content,
 * so it lives in content files now, and an installation can supply its own
 * without forking anything.
 *
 * ## Where the text comes from
 *
 * Three places are consulted, and the first file found wins:
 *
 * 1. `config/help/<lang>/overlay.md` — this installation's own. `config/` is
 *    excluded from every deploy, so a forum's own wording survives updates.
 * 2. `plugins/<Theme>/docs/help/<lang>/overlay.md` — supplied by the active
 *    theme. This is how a forum that ships its own theme keeps its own voice in
 *    version control; macnemo does exactly that.
 * 3. `docs/help/<lang>/overlay.md` — what Saito ships.
 *
 * Languages are tried in turn — the requested one, its base (`de_DE` → `de`),
 * then English — and the whole chain is walked for each. That order matters: a
 * forum that only wrote a German override should still get Saito's English text
 * for an English reader, rather than German prose in an English interface.
 *
 * ## The format
 *
 * Plain Markdown with two conventions, both invisible in any other Markdown
 * viewer:
 *
 * - Text before the first `###` heading introduces the tour.
 * - Each `###` heading starts a section. An `<!-- icon: name -->` line directly
 *   above it picks the Font Awesome glyph beside it (`name` → `fa-name`).
 * - Anything after an `<!-- outro -->` line closes the tour.
 *
 * Nothing here is required: a file with no headings at all renders as one block
 * of prose.
 */
class OverlayHelp
{
    /** @var string the file every location is searched for */
    private const FILE = 'overlay.md';

    /**
     * Find the tour text for a language.
     *
     * @param string $lang locale, e.g. `de_DE` or `en`
     * @param string|null $theme active theme plugin, if any
     * @return string|null Markdown, or null when no file exists anywhere
     */
    public static function markdown(string $lang, ?string $theme = null): ?string
    {
        foreach (self::languages($lang) as $candidate) {
            foreach (self::locations($candidate, $theme) as $path) {
                if (is_file($path) && is_readable($path)) {
                    $text = file_get_contents($path);
                    if ($text !== false && trim($text) !== '') {
                        return $text;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Split the Markdown into the parts the overlay renders.
     *
     * @param string $markdown the tour text
     * @return array{lead: string, sections: list<array{icon: ?string, title: string, body: string}>, outro: string}
     */
    public static function split(string $markdown): array
    {
        $outro = '';
        $parts = preg_split('/^<!--\s*outro\s*-->\s*$/m', $markdown, 2);
        if (is_array($parts) && count($parts) === 2) {
            [$markdown, $outro] = $parts;
        }

        // Keep the icon marker with the heading it belongs to by splitting on
        // the heading itself and looking backwards for the comment.
        $chunks = preg_split(
            '/^(?:<!--\s*icon:\s*(?<icon>[a-z0-9-]+)\s*-->\s*\n)?###\s+(?<title>.+?)\s*$/m',
            $markdown,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        if (!is_array($chunks) || $chunks === []) {
            return ['lead' => trim($markdown), 'sections' => [], 'outro' => trim($outro)];
        }

        $lead = trim((string)array_shift($chunks));
        $sections = [];
        // Each heading contributes three entries: icon, title, body.
        foreach (array_chunk($chunks, 3) as $chunk) {
            if (count($chunk) < 3) {
                continue;
            }
            [$icon, $title, $body] = $chunk;
            $sections[] = [
                'icon' => $icon === '' ? null : $icon,
                'title' => trim($title),
                'body' => trim($body),
            ];
        }

        return ['lead' => $lead, 'sections' => $sections, 'outro' => trim($outro)];
    }

    /**
     * Languages to try, most specific first.
     *
     * @param string $lang the requested locale
     * @return list<string>
     */
    private static function languages(string $lang): array
    {
        $langs = [$lang];

        $base = explode('_', $lang)[0];
        if ($base !== $lang) {
            $langs[] = $base;
        }
        if (!in_array('en', $langs, true)) {
            $langs[] = 'en';
        }

        return $langs;
    }

    /**
     * Paths to try for one language, highest precedence first.
     *
     * @param string $lang language directory
     * @param string|null $theme active theme plugin, if any
     * @return list<string>
     */
    private static function locations(string $lang, ?string $theme): array
    {
        $paths = [CONFIG . 'help' . DS . $lang . DS . self::FILE];

        if ($theme !== null && $theme !== '' && Plugin::isLoaded($theme)) {
            $paths[] = Plugin::path($theme) . 'docs' . DS . 'help' . DS . $lang . DS . self::FILE;
        }

        $paths[] = ROOT . DS . 'docs' . DS . 'help' . DS . $lang . DS . self::FILE;

        return $paths;
    }
}
