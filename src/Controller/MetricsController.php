<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Controller;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\Http\Response;
use Cake\ORM\TableRegistry;
use Saito\App\Registry;
use Throwable;

/**
 * Prometheus metrics.
 *
 * Deliberately **not** under `/api/v2`. The exposition format is plain text, so
 * everything that scope brings — the JSON error renderer, the CSRF exemption —
 * would be inherited for nothing.
 *
 * ## Why a token and not the admin session
 *
 * Prometheus is a scraper, not a member. It can send basic auth, a static
 * bearer token or a client certificate; it cannot log in, and it cannot renew a
 * JWT when one expires. So the forum's own authentication is the wrong
 * instrument here, and this uses a fixed token from the environment.
 *
 * **Empty token means the endpoint does not exist** — a 404, not a 403, and not
 * an empty page. An installation that has not asked for this must look like an
 * installation that never had it, which is also what keeps it off macfix.
 *
 * The token is the only lock this code can provide. Put a second one in front of
 * it at the web server: a metrics endpoint tells a stranger how many members a
 * forum has, how active it is and which version it runs, which is a decent
 * reconnaissance report. Restricting it to the monitoring network costs one
 * `allow`/`deny` pair.
 *
 * ## What it costs
 *
 * Nothing that the front page does not already pay. `Saito\App\Stats` collects
 * these counters for the footer and caches them under `header_counter`, so a
 * scrape reads the same cache a visitor does. Measured on the reference install
 * before this was written, uncached: `COUNT(*)` over 680,292 postings takes
 * **128 ms** while every other counter is 10–13 ms — which is exactly why this
 * goes through the cache rather than querying directly.
 */
class MetricsController extends AppController
{
    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->Authentication->allowUnauthenticated(['index']);
    }

    /**
     * Serve the exposition.
     *
     * @return \Cake\Http\Response
     */
    public function index(): Response
    {
        $this->autoRender = false;
        $started = microtime(true);

        $token = (string)Configure::read('Saito.metricsToken');
        if ($token === '') {
            // Never configured: behave like a forum that has no such address.
            return $this->refuse();
        }

        // hash_equals rather than ===: a plain comparison returns as soon as two
        // bytes differ, and the time it takes leaks how much of the token was
        // right. The scraper sends `Authorization: Bearer <token>`.
        $offered = $this->getRequest()->getHeaderLine('Authorization');
        $offered = preg_replace('/^Bearer\s+/i', '', trim($offered)) ?? '';
        if (!hash_equals($token, $offered)) {
            // 404 again, not 401: an installation with a token and one without
            // should be indistinguishable to anybody who does not hold it.
            return $this->refuse();
        }

        $body = $this->render_(
            $this->collect() + [
                'saito_scrape_duration_seconds' => [
                    'gauge',
                    'How long collecting these metrics took.',
                    [[[], round(microtime(true) - $started, 6)]],
                ],
            ],
        );

        return $this->response
            ->withType('text/plain')
            ->withHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            ->withStringBody($body);
    }

    /**
     * Answer 404 without raising.
     *
     * This used to throw `NotFoundException`, which is correct about the
     * request and wrong about the event: the guard doing its job is not an
     * application error, and it landed in `error.log` like one. That costs
     * nothing while the token is right and everything when it is not — a
     * rotated token or a typo in a scrape config writes **1,440 entries a day**
     * at a 60-second interval, and the log stops being readable exactly when
     * somebody needs to read it.
     *
     * Not solved with `skipLog` in `config/app.php`: that is keyed on the
     * exception class, so silencing `NotFoundException` would silence every
     * genuinely broken route in the forum as well.
     *
     * The body stays empty. A scraper reads the status code, and an
     * installation without a token should look like one that has no such
     * address.
     *
     * @return \Cake\Http\Response
     */
    private function refuse(): Response
    {
        return $this->response
            ->withStatus(404)
            ->withType('text/plain')
            ->withStringBody('');
    }

    /**
     * Gather the numbers.
     *
     * @return array<string, array{0: string, 1: string, 2: list<array{0: array<string, string>, 1: int|float}>}>
     */
    private function collect(): array
    {
        $stats = Registry::get('AppStats');
        $codeVersion = (string)Configure::read('Saito.v');
        $dbVersion = (string)Configure::read('Saito.Settings.db_version');

        $uploads = TableRegistry::getTableLocator()->get('ImageUploader.Uploads');

        return [
            'saito_info' => [
                'gauge',
                'Version of the running code and of the schema it expects.',
                [[['version' => $codeVersion, 'db_version' => $dbVersion], 1]],
            ],
            // The one metric worth alerting on. When these two disagree Saito
            // routes *every* request to the updater — the forum is effectively
            // down, and nothing in an error log says so, because it is not an
            // error. It has happened here, from a deploy that copied
            // version.php without setting db_version.
            'saito_db_version_matches' => [
                'gauge',
                'Whether db_version equals the code version. 0 means every request lands on the updater.',
                [[[], $codeVersion === $dbVersion ? 1 : 0]],
            ],
            'saito_postings_total' => [
                'gauge',
                'Postings in the forum.',
                [[[], $stats->getNumberOfPostings()]],
            ],
            'saito_threads_total' => [
                'gauge',
                'Threads in the forum.',
                [[[], $stats->getNumberOfThreads()]],
            ],
            'saito_users_registered_total' => [
                'gauge',
                'Registered members.',
                [[[], $stats->getNumberOfRegisteredUsers()]],
            ],
            'saito_users_online' => [
                'gauge',
                'Sessions currently counted as online.',
                [
                    [['kind' => 'registered'], $stats->getNumberOfRegisteredUsersOnline()],
                    [['kind' => 'anonymous'], $stats->getNumberOfAnonUsersOnline()],
                    [['kind' => 'bot'], $stats->getNumberOfBotsOnline()],
                ],
            ],
            'saito_uploads_total' => [
                'gauge',
                'Files members have uploaded.',
                [[[], $uploads->find()->count()]],
            ],
            'saito_database_up' => [
                'gauge',
                'Whether the database answered a trivial query.',
                [[[], $this->databaseAnswers() ? 1 : 0]],
            ],
        ];
    }

    /**
     * Whether the database is reachable at all.
     *
     * Everything above would have thrown already if it were not — but a cached
     * counter can outlive the database it came from, so this asks once
     * directly and cheaply.
     *
     * @return bool
     */
    private function databaseAnswers(): bool
    {
        try {
            /** @var \Cake\Database\Connection $connection */
            $connection = ConnectionManager::get('default');
            $connection->execute('SELECT 1')->fetch();

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Render the Prometheus text exposition format.
     *
     * Hand-written rather than pulled in as a dependency: the format is a name,
     * optional labels, and a number, and the whole of it fits in this method.
     * A library for that would be more to keep current than to write.
     *
     * @param array<string, array{0: string, 1: string, 2: list<array{0: array<string, string>, 1: int|float}>}> $metrics
     * @return string
     */
    private function render_(array $metrics): string
    {
        $out = [];
        foreach ($metrics as $name => [$type, $help, $samples]) {
            $out[] = sprintf('# HELP %s %s', $name, str_replace(["\n", '\\'], ' ', $help));
            $out[] = sprintf('# TYPE %s %s', $name, $type);
            foreach ($samples as [$labels, $value]) {
                $rendered = '';
                if ($labels !== []) {
                    $pairs = [];
                    foreach ($labels as $key => $label) {
                        // Backslash, quote and newline are the three characters
                        // the format reserves inside a label value.
                        $label = str_replace(
                            ['\\', '"', "\n"],
                            ['\\\\', '\\"', '\\n'],
                            (string)$label,
                        );
                        $pairs[] = sprintf('%s="%s"', $key, $label);
                    }
                    $rendered = '{' . implode(',', $pairs) . '}';
                }
                $out[] = sprintf('%s%s %s', $name, $rendered, $value);
            }
        }

        return implode("\n", $out) . "\n";
    }
}
