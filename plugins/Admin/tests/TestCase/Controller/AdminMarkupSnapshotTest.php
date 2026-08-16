<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Admin\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Saito\Test\IntegrationTestCase;

/**
 * Renders every admin page, and can write the markup to disk for comparison.
 *
 * Written to remove the BootstrapUI plugin (#73) without moving a byte of
 * output. What it found first was that there was nothing to move: the plugin
 * supplied its helpers through `Controller::$helpers`, a property CakePHP 5 no
 * longer reads, so the admin area had been rendering with
 * `Cake\View\Helper\FormHelper` since the framework upgrade. Its Bootstrap look
 * comes from the stylesheet and from class names written into the templates.
 *
 * The comparison is still what made that safe to act on, and it stays here as a
 * regression guard — the admin area has few tests and is easy to break
 * invisibly, because nobody opens `/admin/smiley_codes/add` for months.
 *
 * To compare two states, capture before and after and diff:
 *
 *     SNAPSHOT_DIR=/tmp/before vendor/bin/phpunit --filter AdminMarkupSnapshot
 *     …make the change…
 *     SNAPSHOT_DIR=/tmp/after  vendor/bin/phpunit --filter AdminMarkupSnapshot
 *     diff -r /tmp/before /tmp/after
 *
 * Normalise the CSRF token and the generated form name first, or every page
 * differs for reasons that mean nothing:
 *
 *     sed -E 's/(name="_csrfToken" value=")[^"]*​/\1CSRF/g; s/post_[0-9a-f]+/post_NAME/g'
 */
class AdminMarkupSnapshotTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.Smiley',
        'app.SmileyCode',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserOnline',
        'app.UserRead',
    ];

    /**
     * Every admin page reachable with a GET, with the form-carrying ones first.
     *
     * The edit pages matter most: they are where BootstrapUI does its work, and
     * `Form->control()` alone appears 31 times across these templates.
     *
     * @var array<string>
     */
    private const PAGES = [
        '/admin/settings/index',
        '/admin/settings/edit/{setting}',
        '/admin/users/index',
        '/admin/categories/index',
        '/admin/categories/edit/{category}',
        '/admin/categories/add',
        '/admin/smiley_codes/index',
        '/admin/smiley_codes/add',
        // These two are the only templates that reach for `$this->Admin->`,
        // which is declared in the same dead `$helpers` array as the BootstrapUI
        // entries — so they are exactly the pages a careless removal breaks.
        '/admin/admins/index',
        '/admin/smilies/index',
        '/admin/settings/edit/timezone',
        '/admin/plugins',
        '/admin/plugins/sitemap',
    ];

    /**
     * Resolve the `{…}` placeholders against whatever the fixtures actually
     * hold.
     *
     * Hard-coded ids looked simpler and were wrong: the settings fixture starts
     * nowhere near 1, so the first edit page raised
     * `RecordNotFoundException` and the run stopped after one capture.
     *
     * @return array<string> pages with real ids in them
     */
    private function pages(): array
    {
        $locator = TableRegistry::getTableLocator();
        // Ask each table for its own primary key rather than assuming `id`:
        // `SettingsTable` keys on `name`, which is why the real edit URL reads
        // `/admin/settings/edit/2fa_required_from_role`. Passing the numeric id
        // there produces a RecordNotFoundException that looks like a broken
        // page rather than a broken assumption.
        $first = function (string $table) use ($locator): string {
            $model = $locator->get($table);
            $key = (array)$model->getPrimaryKey();
            $row = $model->find()->orderByAsc($key[0])->first();

            return (string)($row?->get($key[0]) ?? '');
        };

        $ids = [
            '{setting}' => $first('Settings'),
            '{category}' => $first('Categories'),
        ];

        $pages = [];
        foreach (self::PAGES as $page) {
            $resolved = strtr($page, $ids);
            // A placeholder with nothing behind it would request `/edit/` and
            // capture an error page as if it were a comparison.
            if (!str_contains($resolved, '{')) {
                $pages[] = $resolved;
            }
        }

        return $pages;
    }

    /**
     * @return string directory the snapshots go into
     */
    private function directory(): string
    {
        $dir = (string)(getenv('SNAPSHOT_DIR') ?: sys_get_temp_dir() . '/admin-markup');
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        return $dir;
    }

    public function testWriteSnapshots(): void
    {
        $dir = $this->directory();
        $this->_loginUser(1);

        $pages = $this->pages();
        $statuses = [];
        $written = 0;
        foreach ($pages as $page) {
            $this->get($page);

            $body = (string)$this->_response?->getBody();
            $name = trim(str_replace('/', '_', $page), '_') . '.html';

            $status = $this->_response?->getStatusCode() ?? 0;

            // The status goes into the file as well as into an assertion: a
            // page that starts redirecting should show up in the comparison,
            // not only as a failure that stops the run before the rest are
            // captured.
            file_put_contents(
                $dir . '/' . $name,
                sprintf("<!-- %d %s -->\n%s", $status, $page, $body),
            );
            $statuses[$page] = $status;
            $written++;
        }

        $this->assertSame(count($pages), $written);
        $this->assertSame(
            array_fill_keys($pages, 200),
            $statuses,
            'every admin page must render — a redirect here is usually a lost helper or a broken route',
        );
        $this->assertGreaterThan(
            0,
            count(glob($dir . '/*.html') ?: []),
            'nothing was captured, so a comparison would prove nothing',
        );
    }
}
