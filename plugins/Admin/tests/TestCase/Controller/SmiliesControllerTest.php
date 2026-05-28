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

class SmiliesControllerTest extends IntegrationTestCase
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
     * Renders the smiley list.
     *
     * Guards the pagination sort column: it ordered by the non-existent
     * `Smiley.order` (wrong alias + column) which threw a 500 under Cake 5;
     * the real column is `Smilies.sort`. Also exercises the template, which
     * builds a Collection from each smiley's contained smiley_codes.
     */
    public function testIndexRendersSmileyList()
    {
        $this->_loginUser(1);
        $this->get('/admin/smilies/index');
        $this->assertResponseOk();
    }

    public function testIndexNotAllowedForNonAdmin()
    {
        $url = '/admin/smilies/index';
        $this->get($url);
        $this->assertRedirectLogin($url);
    }
}
