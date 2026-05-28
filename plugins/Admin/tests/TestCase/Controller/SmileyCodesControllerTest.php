<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller\Admin;

use Saito\Test\IntegrationTestCase;

class SmileyCodesControllerTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Setting',
        'app.User',
        'app.UserBlock',
        'app.UserRead',
        'app.UserOnline',
        'app.Smiley',
        'app.SmileyCode',
    ];

    /**
     * Renders the smiley-code list.
     *
     * Guards the Cake 5 pagination change: the paginator no longer applies a
     * `contain` setting, so the associated smiley came back null and the
     * template's $smileyCode->get('smiley')->get('icon') fatally failed.
     */
    public function testIndexRendersSmileyCodeList()
    {
        $this->_loginUser(1);
        $this->get('/admin/smiley_codes/index');
        $this->assertResponseOk();
    }

    public function testIndexNotAllowedForNonAdmin()
    {
        $url = '/admin/smiley_codes/index';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }
}
