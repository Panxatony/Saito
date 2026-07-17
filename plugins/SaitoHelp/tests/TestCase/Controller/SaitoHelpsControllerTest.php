<?php
declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace SaitoHelp\Test\TestCase\Controller;

use Saito\Test\IntegrationTestCase;

class SaitoHelpsControllerTest extends IntegrationTestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Entry',
        'app.Setting',
        'app.Smiley',
        'app.SmileyCode',
        'app.User',
        'app.UserBlock',
        'app.UserIgnore',
        'app.UserOnline',
        'app.UserRead',
    ];

    public function testAnonymousCanViewNormalHelpTopic(): void
    {
        // id 1 = docs/help/en/1-search.md, not admin-marked
        $this->get('/help/en/1');
        $this->assertResponseOk();
    }

    /**
     * SECURITY regression: an `<!-- admin -->`-marked topic (docs/help/en/
     * 6-admin-email.md) must not be readable by a non-admin via a direct id,
     * even though the overview page already hides it.
     */
    public function testAnonymousCannotViewAdminHelpTopic(): void
    {
        $this->get('/help/en/6');
        $this->assertResponseCode(302);
        $this->assertRedirect('/');
    }

    public function testNonAdminUserCannotViewAdminHelpTopic(): void
    {
        $this->_loginUser(3); // normal user
        $this->get('/help/en/6');
        $this->assertResponseCode(302);
        $this->assertRedirect('/');
    }

    public function testAdminCanViewAdminHelpTopic(): void
    {
        $this->_loginUser(1); // user_type = admin
        $this->get('/help/en/6');
        $this->assertResponseOk();
    }
}
