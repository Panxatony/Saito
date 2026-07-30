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
    /**
     * A language that does not exist, written and removed by the two translation
     * tests. `zz` is unassigned in ISO 639-1, so it cannot collide with a real
     * translation somebody adds later.
     */
    private const THROWAWAY_LANG = 'zz';

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

    /**
     * SECURITY regression: a translation that forgets the `<!-- admin -->` line
     * must not make the topic public in that language.
     *
     * The marker used to be read off whichever file had been found, so the
     * visibility of a topic was a property of its translation. Both German admin
     * topics happen to carry the line, which is why nothing was wrong in
     * practice — and why nothing would have caught the day a new translation
     * omitted it. A throwaway language is written here without the marker; the
     * English baseline still has it, and that is what has to decide.
     *
     * @return void
     */
    public function testATranslationCannotMakeAnAdminTopicPublic(): void
    {
        $dir = ROOT . DS . 'docs' . DS . 'help' . DS . self::THROWAWAY_LANG;
        $file = $dir . DS . '6-admin-email.md';
        mkdir($dir, 0775, true);
        file_put_contents($file, "## Ohne Markierung\n\nBewusst ohne den admin-Kommentar.\n");

        try {
            $this->_loginUser(3); // normal user
            $this->get('/help/' . self::THROWAWAY_LANG . '/6');

            $this->assertResponseCode(302);
            $this->assertRedirect('/');
        } finally {
            unlink($file);
            rmdir($dir);
        }
    }

    /**
     * The same file, read by an admin, is served — so the test above proves the
     * marker was honoured rather than the language merely being unreachable.
     *
     * @return void
     */
    public function testTheThrowawayLanguageIsServedToAnAdmin(): void
    {
        $dir = ROOT . DS . 'docs' . DS . 'help' . DS . self::THROWAWAY_LANG;
        $file = $dir . DS . '6-admin-email.md';
        mkdir($dir, 0775, true);
        file_put_contents($file, "## Ohne Markierung\n\nBewusst ohne den admin-Kommentar.\n");

        try {
            $this->_loginUser(1); // admin
            $this->get('/help/' . self::THROWAWAY_LANG . '/6');

            $this->assertResponseOk();
            $this->assertResponseContains('Ohne Markierung');
        } finally {
            unlink($file);
            rmdir($dir);
        }
    }
}
