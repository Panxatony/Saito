<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace Feeds\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Feeds\Auth\FeedToken;
use Saito\Test\IntegrationTestCase;

class PostingsControllerTest extends IntegrationTestCase
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

    public function testNew()
    {
        $this->get('/feeds/postings/new.rss');
        $result = $this->viewVariable('entries');
        $first = $result->first();
        $this->assertEquals($first->get('subject'), 'First_Subject');
        $this->assertNull($first->get('password'));

        $this->assertResponseOk();
        $this->assertResponseContains('<title><![CDATA[First_Subject]]></title>');
        $this->assertResponseContains('<dc:creator xmlns:dc="http://purl.org/dc/elements/1.1/">Alice</dc:creator>');
    }

    public function testUploadedImageRendersAsImgInFeed()
    {
        // An uploaded image is stored as [img src=upload]<file>[/img]. The feed
        // must render it as an <img> with a full-base /useruploads/ URL so
        // readers show the picture — not the bare filename. Regression: the RSS
        // body used getAsText() (text mode), which strips every tag to its
        // inner text and collapsed the image to just "<file>".
        $Entries = TableRegistry::getTableLocator()->get('Entries');
        $Entries->updateAll(
            ['text' => '[img src=upload]22_testimage.jpg[/img]'],
            ['id' => 1]
        );

        $this->get('/feeds/postings/new.rss');

        $this->assertResponseOk();
        $this->assertResponseContains('<img');
        $this->assertResponseContains('useruploads/22_testimage.jpg');
        // The bare filename must not appear as plain text (only inside the src).
        $this->assertResponseNotContains('>22_testimage.jpg<');
    }

    public function testSiteRelativeUrlsAreAbsolutizedInFeed()
    {
        // A feed reader has no site to resolve root-relative URLs against, so
        // smilies / internal links / relative images must be made absolute.
        $Entries = TableRegistry::getTableLocator()->get('Entries');
        $Entries->updateAll(
            ['text' => '[img]/pics/foo.png[/img]'],
            ['id' => 1]
        );

        $this->get('/feeds/postings/new.rss');

        $this->assertResponseOk();
        $this->assertResponseContains('http://localhost/pics/foo.png');
        $this->assertResponseNotContains('src="/pics/foo.png"');
        $this->assertResponseNotContains('href="/pics/foo.png"');
    }

    public function testUnknownFeedSubpathIsNotRouted()
    {
        // A feed reader probing autodiscovery variants (…/new.rss/feed,
        // …/new.rss/rss) must not fall through to the auth-gated feed action
        // that 302-redirects to /login (readers misparse the login HTML as the
        // feed). With no feed route matching, the path resolves to nothing and
        // raises a 404-class exception instead. (Before the fix this returned a
        // 302 login redirect and no exception was thrown.)
        $this->expectException(\Cake\Http\Exception\MissingControllerException::class);
        $this->get('/feeds/postings/new.rss/feed');
    }

    /**
     * Build the personal feed token for a fixture user.
     */
    private function feedToken(int $userId): string
    {
        $user = TableRegistry::getTableLocator()->get('Users')
            ->find()->select(['id', 'password'])->where(['Users.id' => $userId])->first();

        return FeedToken::build($userId, (string)$user->get('password'));
    }

    /**
     * Category-ids present in the rendered feed's `entries` view variable.
     */
    private function feedCategoryIds(): array
    {
        return $this->viewVariable('entries')->all()->extract('category_id')->toList();
    }

    public function testAnonymousFeedShowsOnlyPublicCategories()
    {
        // Baseline: without a token a guest only sees public categories
        // (accession 0). Category 4 (accession 1) must be absent.
        $this->get('/feeds/postings/new.rss');
        $this->assertResponseOk();
        $this->assertNotContains(4, $this->feedCategoryIds());
    }

    public function testValidTokenUnlocksNonPublicCategories()
    {
        // User 3 (Ulysses, a regular user) may read category 4 (accession 1),
        // which a guest cannot. Their signed feed token must unlock it.
        $this->get('/feeds/f/' . $this->feedToken(3) . '/postings/new.rss');
        $this->assertResponseOk();
        $this->assertContains(4, $this->feedCategoryIds());
    }

    public function testValidTokenAuthenticatesEvenForBotClient()
    {
        // Feed readers (curl, Reeder, CFNetwork, HTTP libraries) are on the bot
        // list. A bot must still be authenticated by its personal feed token —
        // otherwise the bot short-circuit in AuthUserComponent would serve it
        // only the public feed and personalized feeds would never work in a
        // real reader.
        $this->configRequest(['headers' => ['User-Agent' => 'curl/8.7.1']]);
        $this->get('/feeds/f/' . $this->feedToken(3) . '/postings/new.rss');
        $this->assertResponseOk();
        $this->assertContains(4, $this->feedCategoryIds());
    }

    public function testBotWithoutTokenStillGetsOnlyPublicFeed()
    {
        // The bot classification must still apply when there is no valid token:
        // a crawler sees only public categories.
        $this->configRequest(['headers' => ['User-Agent' => 'curl/8.7.1']]);
        $this->get('/feeds/postings/new.rss');
        $this->assertResponseOk();
        $this->assertNotContains(4, $this->feedCategoryIds());
    }

    public function testTamperedTokenFallsBackToPublicFeed()
    {
        // A forged signature must not unlock non-public categories: the request
        // falls through to the public (guest) feed instead of being rejected.
        $this->get('/feeds/f/3-' . str_repeat('0', 32) . '/postings/new.rss');
        $this->assertResponseOk();
        $this->assertNotContains(4, $this->feedCategoryIds());
    }

    public function testTokenForUnknownUserFallsBackToPublicFeed()
    {
        $this->get('/feeds/f/9999-' . str_repeat('0', 32) . '/postings/new.rss');
        $this->assertResponseOk();
        $this->assertNotContains(4, $this->feedCategoryIds());
    }

    public function testThreads()
    {
        $this->get('/feeds/postings/threads.rss');
        $result = $this->viewVariable('entries');
        $first = $result->first();
        $this->assertEquals($first->get('subject'), 'First_Subject');
        $this->assertNull($first->get('password'));

        $this->assertResponseOk();
        $this->assertResponseContains('<title><![CDATA[First_Subject]]></title>');
    }
}
