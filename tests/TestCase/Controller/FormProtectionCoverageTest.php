<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller;

use Saito\Exception\SaitoBlackholeException;
use Saito\Test\IntegrationTestCase;

/**
 * Form-protection tripwire for the island's write endpoints.
 *
 * FormProtectionComponent validates a `_Token` field on every POST. The island
 * writes with `fetch`, which sends a CSRF token in a header and no form fields at
 * all — so any action it posts to must be listed in that controller's
 * `unlockedActions`, or the request is rejected before it reaches the code.
 *
 * That rejection is invisible from the outside: no message, nothing on screen,
 * only a line in the server log. Pinning a thread was broken this way from the
 * frontend rewrite until 8.3.0 — through three releases, while two tests for the
 * very same action passed the whole time. They passed because
 * IntegrationTestCase calls enableSecurityToken() in setUp(), so every test
 * request carries a form token and therefore looks like a form submission, which
 * `fetch` is not. The harness was more permissive than the browser it stands in
 * for, and no test could see the difference.
 *
 * This one turns the token off and posts to every endpoint the island's own
 * source says it writes to. The endpoints are discovered from `frontend/src`
 * rather than listed here, so an endpoint added later is covered without anybody
 * remembering to add it.
 *
 * Any response is acceptable — not found, forbidden, a validation error. The one
 * unacceptable outcome is a blackhole, which is the signature of the missing
 * exemption.
 */
class FormProtectionCoverageTest extends IntegrationTestCase
{
    public array $fixtures = [
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
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
     * Write targets the island posts to, read out of its own source.
     *
     * Looks for a `fetch(<url>, { … method: 'POST' … })` and keeps the url.
     * Template placeholders become `1`, which is a plausible id in the fixtures;
     * an endpoint that needs something else answers with not-found, and that is
     * a perfectly good answer here.
     *
     * @return array<string> URLs to post to
     */
    private function islandWriteTargets(): array
    {
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(ROOT . DS . 'frontend' . DS . 'src')
        );

        $urls = [];
        foreach ($dir as $file) {
            if ($file->getExtension() !== 'ts') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());

            // The url and the options object of one fetch() call. Non-greedy up
            // to the first closing brace, which is enough to see the method.
            preg_match_all('/fetch\(\s*[`\'"]([^`\'"]+)[`\'"]\s*,\s*\{(.*?)\}/s', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                if (!preg_match("/method:\s*'(POST|PUT|PATCH|DELETE)'/", $match[2])) {
                    continue;
                }
                $url = preg_replace('/\$\{[^}]*\}/', '1', $match[1]);
                if ($url === null || !str_starts_with($url, '/')) {
                    // A url held in a variable — those are the editor preview and
                    // the insert preview, both of which post to htmxPreview and
                    // are covered by the explicit entry below.
                    continue;
                }
                $urls[$url] = true;
            }
        }

        // Posted from a variable rather than a literal, so the scan above cannot
        // see it. Kept here so the endpoint is still exercised.
        $urls['/entries/htmx-preview'] = true;

        return array_keys($urls);
    }

    /**
     * Every island write endpoint survives a post without a form token.
     *
     * @return void
     */
    public function testIslandWriteEndpointsAreNotBlackholed(): void
    {
        $targets = $this->islandWriteTargets();
        $this->assertNotEmpty($targets, 'no write endpoints found — has the scan gone stale?');

        $blackholed = [];
        foreach ($targets as $url) {
            // A fresh request each time; the login and the header have to be set
            // again after every one.
            $this->_securityToken = false;
            $this->_loginUser(1);
            $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);

            try {
                $this->post($url);
            } catch (SaitoBlackholeException $e) {
                $blackholed[] = $url . ' — ' . $e->getMessage();
            } catch (\Throwable $e) {
                // Anything else is the endpoint answering, which is all this
                // test asks of it.
            }
        }

        $this->assertSame(
            [],
            $blackholed,
            "These endpoints reject the island's own requests. Add the action to its\n"
            . "controller's FormProtection unlockedActions:\n  " . implode("\n  ", $blackholed)
        );
    }

    /**
     * The test above only means something while the token can be switched off.
     *
     * If a future change made IntegrationTestCase attach a form token
     * unconditionally, every endpoint would pass and the tripwire would be
     * decoration — which is exactly how the pin bug survived three releases.
     *
     * @return void
     */
    public function testTheTokenCanActuallyBeSwitchedOff(): void
    {
        $this->_securityToken = false;
        $this->_loginUser(1);
        $this->configRequest(['headers' => ['X-Requested-With' => 'XMLHttpRequest']]);

        // `update` is not in any unlockedActions list and is posted to by nothing,
        // so without a token it must be blackholed. If it is not, the token is
        // being supplied from somewhere and the test above proves nothing.
        $this->expectException(SaitoBlackholeException::class);
        $this->post('/entries/delete/1');
    }
}
