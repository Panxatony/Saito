<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Detectors\Test\TestCase\Controller\Component;

use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use Detectors\Controller\Component\DetectorsComponent;

class DetectorsComponentTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        Configure::delete('Saito.bots');
        parent::tearDown();
    }

    protected function isBot(string $userAgent): bool
    {
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;
        $request = new ServerRequest(['environment' => ['HTTP_USER_AGENT' => $userAgent]]);
        $component = new DetectorsComponent(new ComponentRegistry(new Controller($request)));

        return $component->isBot();
    }

    public function testDetectsClassicCrawler(): void
    {
        $this->assertTrue($this->isBot(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
        ));
    }

    public function testDetectsModernAgentsWithoutGenericBotToken(): void
    {
        // These carry no bot/crawl/spider token but are clearly not humans.
        $this->assertTrue($this->isBot('meta-externalagent/1.1'));
        $this->assertTrue($this->isBot('Go-http-client/2.0'));
        $this->assertTrue($this->isBot('python-requests/2.31.0'));
        $this->assertTrue($this->isBot('Feedly/1.0 (+http://www.feedly.com/fetcher.html)'));
    }

    public function testDoesNotFlagRegularBrowser(): void
    {
        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $this->assertFalse($this->isBot($chrome));
    }

    public function testEmptyUserAgentIsNotBot(): void
    {
        $this->assertFalse($this->isBot(''));
    }

    public function testInstallationCanAddOwnBotsViaConfig(): void
    {
        $this->assertFalse($this->isBot('MyCorpScanner/3.0'));
        Configure::write('Saito.bots', ['MyCorpScanner']);
        $this->assertTrue($this->isBot('MyCorpScanner/3.0'));
    }

    public function testConfigBotWithRegexMetacharsIsMatchedLiterally(): void
    {
        // A config value containing regex metacharacters must be matched
        // literally and must not corrupt the overall detection pattern.
        Configure::write('Saito.bots', ['Foo(Bar)+']);
        $this->assertTrue($this->isBot('acme Foo(Bar)+ fetcher'));
        $chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $this->assertFalse($this->isBot($chrome));
    }
}
