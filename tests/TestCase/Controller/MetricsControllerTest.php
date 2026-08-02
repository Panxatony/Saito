<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Http\Exception\NotFoundException;
use Saito\Test\IntegrationTestCase;

/**
 * The endpoint is unauthenticated in Saito's own terms and guarded by a token
 * instead, which puts the whole of its access control in one comparison. So the
 * tests spend most of their effort there: what happens with no token, a wrong
 * token, a token of the wrong length, and none at all.
 *
 * The exposition itself is checked for the two things a scraper will not
 * tolerate — a content type it does not recognise, and a line it cannot parse.
 */
class MetricsControllerTest extends IntegrationTestCase
{
    public array $fixtures = [
        'plugin.ImageUploader.Uploads',
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.User',
        'app.UserOnline',
    ];

    private const TOKEN = 'a-long-and-random-token-0123456789';

    public function tearDown(): void
    {
        Configure::write('Saito.metricsToken', '');
        parent::tearDown();
    }

    /**
     * Without a token configured the address does not exist.
     *
     * A 404 rather than a 403: an installation that never asked for metrics
     * should not advertise that it could have them. This is also what keeps the
     * endpoint off installations that simply take the next release.
     *
     * @return void
     */
    public function testWithoutATokenTheAddressDoesNotExist(): void
    {
        Configure::write('Saito.metricsToken', '');

        // The suite disables the error-handler middleware, so a refusal
        // arrives as the exception rather than as a rendered 404.
        $this->expectException(NotFoundException::class);
        $this->get('/metrics');
    }

    /**
     * A wrong token is answered the same way as no token.
     *
     * Not 401: the distinction between "you guessed wrong" and "there is
     * nothing here" is exactly what a stranger would like to learn.
     *
     * @return void
     */
    public function testAWrongTokenIsIndistinguishableFromNoEndpoint(): void
    {
        Configure::write('Saito.metricsToken', self::TOKEN);
        $this->configRequest(['headers' => ['Authorization' => 'Bearer wrong']]);

        $this->expectException(NotFoundException::class);
        $this->get('/metrics');
    }

    /**
     * …and so is no Authorization header at all.
     *
     * @return void
     */
    public function testAMissingHeaderIsRefused(): void
    {
        Configure::write('Saito.metricsToken', self::TOKEN);

        $this->expectException(NotFoundException::class);
        $this->get('/metrics');
    }

    /**
     * A token that is a prefix of the real one must not pass.
     *
     * Guards the comparison itself: `str_starts_with`, `substr` or a regex
     * would let this through, and any of the three is a plausible thing for
     * someone to write here later.
     *
     * @return void
     */
    public function testAPrefixOfTheTokenIsRefused(): void
    {
        Configure::write('Saito.metricsToken', self::TOKEN);
        $this->configRequest([
            'headers' => ['Authorization' => 'Bearer ' . substr(self::TOKEN, 0, 20)],
        ]);

        $this->expectException(NotFoundException::class);
        $this->get('/metrics');
    }

    /**
     * The right token gets the exposition, in the format Prometheus parses.
     *
     * @return void
     */
    public function testTheRightTokenGetsTheExposition(): void
    {
        Configure::write('Saito.metricsToken', self::TOKEN);
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . self::TOKEN]]);

        $this->get('/metrics');

        $this->assertResponseOk();
        $this->assertHeaderContains('Content-Type', 'text/plain');
        $this->assertHeaderContains('Content-Type', 'version=0.0.4');
        $this->assertResponseContains('# TYPE saito_postings_total gauge');
        $this->assertResponseContains('saito_users_online{kind="registered"}');
    }

    /**
     * Every line is either a comment or `name{labels} value`.
     *
     * A single malformed line makes Prometheus drop the whole scrape, so this
     * parses the output rather than looking for substrings in it.
     *
     * @return void
     */
    public function testEveryLineIsParsable(): void
    {
        Configure::write('Saito.metricsToken', self::TOKEN);
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . self::TOKEN]]);

        $this->get('/metrics');

        $lines = array_filter(explode("\n", (string)$this->_response->getBody()));
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression(
                '/^(# (HELP|TYPE) \w+ .+|\w+(\{[^}]*\})? -?[0-9.]+(e[+-]?\d+)?)$/i',
                $line,
                "unparsable: $line",
            );
        }
    }

    /**
     * The version mismatch that takes a forum down is reported as a number.
     *
     * When `db_version` and the code version disagree Saito routes *every*
     * request to the updater. Nothing logs an error, because it is not one —
     * so this is the metric worth an alert, and it has to be right in both
     * directions.
     *
     * @return void
     */
    public function testTheUpdaterTrapIsExposed(): void
    {
        Configure::write('Saito.metricsToken', self::TOKEN);
        Configure::write('Saito.v', '9.9.9');
        Configure::write('Saito.Settings.db_version', '9.9.9');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . self::TOKEN]]);

        $this->get('/metrics');
        $this->assertResponseContains('saito_db_version_matches 1');

        Configure::write('Saito.Settings.db_version', '9.9.8');
        $this->configRequest(['headers' => ['Authorization' => 'Bearer ' . self::TOKEN]]);

        $this->get('/metrics');
        $this->assertResponseContains('saito_db_version_matches 0');
    }
}
