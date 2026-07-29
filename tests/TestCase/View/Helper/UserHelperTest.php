<?php

namespace App\Test\TestCase\View\Helper;

use App\View\Helper\UserHelper;
use Cake\TestSuite\TestCase;
use Cake\View\View;

class UserHelperTest extends TestCase
{
    /**
     * Helper to test
     *
     * @var UserHelper
     */
    public $helper = null;

    public function setUp(): void
    {
        parent::setUp();
        $View = new View();
        $this->helper = new UserHelper($View);
    }

    public function testLinkExternalHomepageEscapeIfNoLink()
    {
        $actual = $this->helper->linkExternalHomepage('<');
        $this->assertEquals('&lt;', $actual);
    }

    public function testLinkExternalHomepageLinkHttp()
    {
        $actual = $this->helper->linkExternalHomepage('http://tempest.island');
        $this->assertHtml(
            [
                'a' => ['href' => 'http://tempest.island'],
                'i' => ['class' => 'fa fa-home fa-lg'],
                '/i',
                '/a',
            ],
            $actual
        );
    }

    public function testLinkExternalHomepageLinkWww()
    {
        $actual = $this->helper->linkExternalHomepage('www.tempest.island');
        $this->assertHtml(['a' => ['href' => 'http://www.tempest.island']], $actual);
    }

    /**
     * A homepage URL must not be able to leave its own href attribute.
     *
     * `user_hp` is free text a member types into their profile, and the profile
     * is read by other members and by moderators reviewing an account. A quote
     * in the URL used to close the attribute early and everything after it
     * became live markup in the reader's page.
     *
     * @return void
     */
    public function testLinkExternalHomepageCannotBreakOutOfHref()
    {
        $actual = $this->helper->linkExternalHomepage(
            'http://example.com/"><img src=x onerror=alert(1)>'
        );

        $this->assertStringNotContainsString('<img', $actual, 'markup escaped the href');
        $this->assertStringContainsString('&quot;', $actual, 'the quote must be encoded');
    }

    /**
     * The same for the `www.` shorthand, which takes a second code path.
     *
     * @return void
     */
    public function testLinkExternalHomepageWwwCannotBreakOutOfHref()
    {
        $actual = $this->helper->linkExternalHomepage(
            'www.example.com/"><svg onload=alert(1)>'
        );

        $this->assertStringNotContainsString('<svg', $actual, 'markup escaped the href');
    }

    /**
     * The icon is markup on purpose and must survive — escaping the title as
     * well would show the `<i>` tag as text instead of the home symbol. This is
     * what `escape => false` was there for, and what `escapeTitle` now does
     * without also unescaping the URL.
     *
     * @return void
     */
    public function testLinkExternalHomepageKeepsTheIconMarkup()
    {
        $actual = $this->helper->linkExternalHomepage('http://tempest.island');

        $this->assertStringContainsString('<i class="fa fa-home fa-lg">', $actual);
    }
}
