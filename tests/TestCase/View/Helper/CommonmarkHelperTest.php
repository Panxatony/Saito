<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\View\Helper;

use Cake\TestSuite\TestCase;
use Cake\View\View;
use Commonmark\View\Helper\CommonmarkHelper;

class CommonmarkHelperTest extends TestCase
{
    private CommonmarkHelper $Helper;

    public function setUp(): void
    {
        parent::setUp();
        $this->Helper = new CommonmarkHelper(new View());
    }

    /**
     * Raw HTML in the input must be escaped, not passed through.
     *
     * @return void
     */
    public function testRawHtmlIsEscaped()
    {
        $out = $this->Helper->parse('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $out);
    }

    /**
     * Unsafe link schemes (javascript:, …) must be dropped.
     *
     * @return void
     */
    public function testUnsafeLinkSchemeIsDropped()
    {
        $out = $this->Helper->parse('[x](javascript:alert(1))');
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $out);
    }

    /**
     * Ordinary Markdown still renders.
     *
     * @return void
     */
    public function testPlainMarkdownStillRenders()
    {
        $out = $this->Helper->parse('**bold**');
        $this->assertStringContainsString('<strong>bold</strong>', $out);
    }
}
