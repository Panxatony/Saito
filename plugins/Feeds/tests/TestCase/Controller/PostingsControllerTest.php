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
