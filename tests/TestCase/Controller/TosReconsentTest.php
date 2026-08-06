<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Saito\Test\IntegrationTestCase;

/**
 * Re-consent to changed terms of service (#80).
 *
 * The gate blocks the whole forum, so the tests are weighted towards the ways
 * it must *not* block: a member who will not agree has to be able to read the
 * terms, take their data and log out, or they are trapped in a forum they
 * cannot leave.
 */
class TosReconsentTest extends IntegrationTestCase
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
        'plugin.Bookmarks.Bookmark',
        'plugin.ImageUploader.Uploads',
    ];

    /**
     * Terms in force at version 2, with the member still on an older one.
     *
     * @param int $accepted the version the member has agreed to
     * @return void
     */
    private function termsChangedSince(int $accepted): void
    {
        Configure::write('Saito.Settings.tos_enabled', true);
        Configure::write('Saito.Settings.tos_version', 2);
        TableRegistry::getTableLocator()->get('Users')
            ->updateAll(['tos_accepted_version' => $accepted], ['id' => 1]);
    }

    public function testStaleAcceptanceIsAskedToAgreeAgain(): void
    {
        $this->termsChangedSince(1);
        $this->_loginUser(1);

        $this->get('/');

        $this->assertResponseOk();
        $this->assertResponseContains('tos-reconsent-form');
    }

    public function testCurrentAcceptancePassesThrough(): void
    {
        $this->termsChangedSince(2);
        $this->_loginUser(1);

        $this->get('/');

        $this->assertResponseOk();
        $this->assertResponseNotContains('tos-reconsent-form');
    }

    /**
     * A forum that does not require terms never gates, whatever the versions
     * say — the setting is the switch.
     */
    public function testNothingIsGatedWhileTermsAreNotRequired(): void
    {
        $this->termsChangedSince(1);
        Configure::write('Saito.Settings.tos_enabled', false);
        $this->_loginUser(1);

        $this->get('/');

        $this->assertResponseOk();
        $this->assertResponseNotContains('tos-reconsent-form');
    }

    /**
     * An installation that never touches the version: absent reads as 0, and
     * nothing is behind 0.
     */
    public function testNoVersionSetGatesNobody(): void
    {
        Configure::write('Saito.Settings.tos_enabled', true);
        Configure::write('Saito.Settings.tos_version', null);
        $this->_loginUser(1);

        $this->get('/');

        $this->assertResponseOk();
        $this->assertResponseNotContains('tos-reconsent-form');
    }

    public function testGuestsAreNotGated(): void
    {
        $this->termsChangedSince(1);

        $this->get('/');

        $this->assertResponseOk();
        $this->assertResponseNotContains('tos-reconsent-form');
    }

    /**
     * The ways out. Each one blocked would trap a member who does not agree.
     *
     * @return void
     */
    public function testTheWaysOutStayOpen(): void
    {
        $this->termsChangedSince(1);
        $this->_loginUser(1);

        // The terms one is being asked to agree to…
        $this->get('/pages/tos');
        $this->assertResponseOk();
        $this->assertResponseNotContains('tos-reconsent-form');

        // …the imprint and privacy policy beside them…
        $this->get('/pages/impressum');
        $this->assertResponseOk();
        $this->assertResponseNotContains('tos-reconsent-form');

        // …and taking one's own data out (GDPR Art. 15/20 is not withheld
        // pending a signature).
        $this->get('/users/export');
        $this->assertResponseOk();
        $this->assertResponseNotContains('tos-reconsent-form');
    }

    public function testLoggingOutIsNotGated(): void
    {
        $this->termsChangedSince(1);
        $this->_loginUser(1);

        $this->get('/logout');

        // Whatever it answers, it must not be the interstitial.
        $this->assertResponseNotContains('tos-reconsent-form');
    }

    public function testAcceptingRecordsTheVersionAndLetsTheMemberBackIn(): void
    {
        $this->termsChangedSince(1);
        $this->_loginUser(1);
        // A real CSRF token (disableCsrf() is misnamed: it enables the token
        // under Saito's own cookie name) and deliberately *no* form-tampering
        // token — exactly what the browser posts. The interstitial's form is
        // rendered before FormProtection sets its token up, so every click was
        // blackholed until the action was unlocked; calling enableSecurityToken()
        // here would fake a token and hide that.
        $this->disableCsrf();

        // With a `_Token` that does not validate — which is what the browser
        // sends, because the form is built before FormProtection sets its token
        // up. A locked action blackholes this; the unlocked one takes it.
        $this->post('/users/tos-accept', [
            '_Token' => ['fields' => 'not-a-valid-token', 'unlocked' => ''],
        ]);

        $this->assertRedirect();
        $users = TableRegistry::getTableLocator()->get('Users');
        $this->assertSame(
            2,
            (int)$users->get(1)->get('tos_accepted_version'),
            'the accepted version was recorded',
        );

        // …and the next request goes through.
        $this->get('/');
        $this->assertResponseOk();
        $this->assertResponseNotContains('tos-reconsent-form');
    }

    /**
     * The version is never mass-assignable: a crafted profile edit must not be
     * able to pre-accept terms the member has not seen.
     *
     * @return void
     */
    public function testTheVersionCannotBeMassAssigned(): void
    {
        $users = TableRegistry::getTableLocator()->get('Users');
        $user = $users->get(1);
        $users->patchEntity($user, ['tos_accepted_version' => 99]);

        $this->assertNotSame(99, (int)$user->get('tos_accepted_version'));
    }
}
