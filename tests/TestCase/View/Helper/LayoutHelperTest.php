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

use App\View\Helper\LayoutHelper;
use Cake\TestSuite\TestCase;
use Cake\View\View;

/**
 * Icon classes: Font Awesome's names carry `fa-`, Saito's own carry
 * `saito-icon-` (#92).
 */
class LayoutHelperTest extends TestCase
{
    private LayoutHelper $Helper; // assigned in setUp() skipcq: PHP-E1008

    public function setUp(): void
    {
        parent::setUp();
        $this->Helper = new LayoutHelper(new View());
    }

    public function testAFontAwesomeIconKeepsItsPrefix(): void
    {
        $html = $this->Helper->textWithIcon('Search', 'search');

        $this->assertStringContainsString('class="saito-icon fa fa-search"', $html);
        $this->assertStringContainsString('<span class="saito-icon-text">Search</span>', $html);
    }

    public function testAnIconOfOursLeavesTheFontAwesomeNamespace(): void
    {
        $html = $this->Helper->textWithIcon('', 'mix');

        $this->assertSame('<i class="saito-icon fa saito-icon-mix"></i>', $html);
    }

    /**
     * The list in the helper and the rules in the stylesheet are two halves of
     * one decision, kept in two languages. A name on the list without a rule
     * draws nothing; a rule still called `.fa-<name>` is the squatting the list
     * exists to end.
     *
     * @return void
     */
    public function testEveryIconOfOursHasItsRuleAndNoneSquats(): void
    {
        $scss = (string)file_get_contents(WWW_ROOT . 'css/src/partials/_font-awesome-extend.scss');
        $this->assertNotSame('', $scss);

        foreach (LayoutHelper::OWN_ICONS as $name) {
            $this->assertMatchesRegularExpression(
                '/^\.saito-icon-' . preg_quote($name, '/') . '\s*\{/m',
                $scss,
                "no rule for saito-icon-{$name}",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\.fa-' . preg_quote($name, '/') . '(?![\w-])/',
                $scss,
                "{$name} is ours and must not be declared as fa-{$name}",
            );
        }
    }
}
