<?php
declare(strict_types=1);

namespace MailObfuscator\Test\TestCase\View\Helper;

use Cake\TestSuite\TestCase;
use Cake\View\View;
use MailObfuscator\View\Helper\MailObfuscatorHelper;

/**
 * @covers \MailObfuscator\View\Helper\MailObfuscatorHelper
 */
class MailObfuscatorHelperTest extends TestCase
{
    private MailObfuscatorHelper $helper;

    public function setUp(): void
    {
        parent::setUp();
        $this->helper = new MailObfuscatorHelper(new View());
    }

    /**
     * The address is split into the two attributes, and nothing else leaks.
     *
     * @return void
     */
    public function testSplitsAddressIntoAttributes(): void
    {
        $out = $this->helper->link('info@example.org');

        $this->assertStringContainsString('data-ttl="info"', $out);
        $this->assertStringContainsString('data-dom="example.org"', $out);
        $this->assertStringContainsString('class="js-mailObfuscated"', $out);
    }

    /**
     * **No inline script.** The old helper shipped a jQuery `<script>` block;
     * a strict `script-src` blocks it and the frontend has no jQuery. The
     * reassembly is in the bundle now, so nothing script-shaped belongs here.
     *
     * @return void
     */
    public function testEmitsNoInlineScript(): void
    {
        $out = $this->helper->link('info@example.org', 'Mail an uns');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('.html(', $out);
    }

    /**
     * The one that matters: hostile input in the address must not break out of
     * the attribute. Escaped here as defence in depth, independent of whatever
     * the caller did.
     *
     * @return void
     */
    public function testEscapesHostileAddress(): void
    {
        $out = $this->helper->link('a" onmouseover="alert(1)@x.de');

        $this->assertStringNotContainsString('onmouseover="alert(1)"', $out);
        // The double quote must be entity-encoded, not close the attribute.
        $this->assertStringContainsString('&quot;', $out);
        $this->assertStringNotContainsString('"a" onmouseover', $out);
    }

    /**
     * A hostile title is escaped too.
     *
     * @return void
     */
    public function testEscapesHostileTitle(): void
    {
        $out = $this->helper->link('info@example.org', '<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $out);
        $this->assertStringContainsString('&lt;img', $out);
    }

    /**
     * An address without an `@` must not produce a warning or a half-built tag.
     *
     * @return void
     */
    public function testHandlesAddressWithoutAtSign(): void
    {
        $out = $this->helper->link('notanemail');

        $this->assertStringContainsString('data-ttl="notanemail"', $out);
        $this->assertStringContainsString('data-dom=""', $out);
    }
}
