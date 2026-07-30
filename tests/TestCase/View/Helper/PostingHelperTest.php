<?php

namespace App\Test\TestCase\View\Helper;

use Cake\Core\Configure;
use App\View\Helper\PostingHelper;
use Cake\View\View;
use Saito\Cache\ItemCache;
use Saito\Posting\Posting;
use Saito\Test\SaitoTestCase;
use Saito\User\CurrentUser\CurrentUserFactory;
use Saito\User\SaitoUser;

class PostingHelperTest extends SaitoTestCase
{
    public $Helper;

    public function setUp(): void
    {
        parent::setUp();

        $View = new View();
        $View->set('LineCache', new ItemCache('test'));
        $this->Helper = new PostingHelper($View);
    }

    public function tearDown(): void
    {
        unset($this->Helper);
        parent::tearDown();
    }

    public function testUrlToMix()
    {
        $data = [
            'id' => 4,
            'tid' => 2,
        ];
        $posting = new Posting($data);

        $result = $this->Helper->urlToMix($posting);
        $this->assertEquals('/entries/htmx-thread/2#4', $result);

        $result = $this->Helper->urlToMix($posting, false);
        $this->assertEquals('/entries/htmx-thread/2', $result);
    }

}
