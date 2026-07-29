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

    public function testColorsReturnsTheChosenColours()
    {
        $actual = $this->helper->colors([
            'user_color_new_postings' => '#ff0000',
            'user_color_old_postings' => '#00FF00',
            'user_color_actual_posting' => '#00f',
        ]);

        $this->assertSame(
            ['new' => '#ff0000', 'old' => '#00FF00', 'current' => '#00f'],
            $actual
        );
    }

    /**
     * "Use the theme's colour" is stored as an empty value or a bare `#`, and a
     * guest has no settings at all. None of those may produce a declaration —
     * an empty one would override the theme with nothing.
     *
     * @return void
     */
    public function testColorsSkipsUnsetValues()
    {
        $actual = $this->helper->colors([
            'user_color_new_postings' => '',
            'user_color_old_postings' => '#',
        ]);

        $this->assertSame([], $actual);
    }

    /**
     * The values end up inside a CSS declaration, so anything that is not a
     * plain hex colour is dropped rather than passed on. Validation has allowed
     * a hex string without its `#` for years, and rows predating it are whatever
     * they were.
     *
     * @return void
     */
    public function testColorsRejectsAnythingButAHexColour()
    {
        $actual = $this->helper->colors([
            'user_color_new_postings' => 'red; } body { display: none',
            'user_color_old_postings' => 'ff0000',
            'user_color_actual_posting' => 'javascript:alert(1)',
        ]);

        $this->assertSame(['old' => '#ff0000'], $actual);
    }
}
