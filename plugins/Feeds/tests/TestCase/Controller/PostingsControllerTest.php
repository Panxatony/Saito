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
        // Entries 1 and 9 carry the identical `last_answer`, so the tie-break
        // decides which one leads. It is `id DESC` — the same direction as
        // `last_answer DESC`, which is what lets the index serve the sort
        // instead of a filesort (see FeedsPostingBehavior) — so id 9 wins.
        $this->assertEquals('Sixth_Subject', $first->get('subject'));
        $this->assertNull($first->get('password'));

        $this->assertResponseOk();
        // Titles are escaped rather than CDATA-wrapped since the move to
        // laminas-feed in 8.4.10; both are well-formed XML and readers do not
        // tell them apart. The namespace now sits on the document root
        // instead of being repeated on every element.
        $this->assertResponseContains('<title>First_Subject</title>');
        $this->assertResponseContains('<dc:creator>Alice</dc:creator>');
        // The item identity subscribers are keyed on. It must not move: a
        // changed guid makes every reader re-announce every posting it has
        // already shown. Held down here rather than trusted.
        $this->assertResponseContains('<guid>http://localhost/entries/htmx-posting/1</guid>');
        // The writer emits `slash:comments` for every item whether or not a
        // count was set, falling back to 0 — which would have the forum telling
        // every reader that every posting has no replies. It is taken back out
        // in FeedsHelper::render(); this is what says so.
        $this->assertResponseNotContains('slash:comments');
        $this->assertResponseNotContains('purl.org/rss/1.0/modules/slash');
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
        $this->assertResponseContains('<title>First_Subject</title>');
    }

    /**
     * A reply commonly has no subject, and the feed writer refuses an empty
     * title outright — so an untitled posting has to be written *without* the
     * element, not with an empty one. Get this wrong and the whole feed answers
     * with an error instead of a document, for every reader at once.
     *
     * @return void
     */
    public function testAPostingWithoutASubjectStillRenders(): void
    {
        $Entries = TableRegistry::getTableLocator()->get('Entries');
        $Entries->updateAll(['subject' => ''], ['id' => 1]);

        $this->get('/feeds/postings/new.rss');

        $this->assertResponseOk();
        // Still there, identified as always by its guid. The writer emits an
        // empty `<title/>` beside it, which is well-formed and what a reader
        // expects of an untitled item — the failure mode being guarded against
        // is the whole document turning into an error, not the empty element.
        $this->assertResponseContains('<guid>http://localhost/entries/htmx-posting/1</guid>');
    }
}
