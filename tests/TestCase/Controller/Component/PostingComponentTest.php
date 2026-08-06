<?php

namespace App\Test\TestCase\Controller\Component;

use App\Controller\Component\PostingComponent;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\ORM\TableRegistry;
use Saito\Exception\SaitoForbiddenException;
use Saito\Posting\Posting;
use Saito\Test\SaitoTestCase;
use Saito\User\CurrentUser\CurrentUserFactory;

class PostingComponentTest extends SaitoTestCase
{
    public array $fixtures = [
        'app.Category',
        'app.Draft',
        'app.Entry',
        'app.User',
        'plugin.Bookmarks.Bookmark',
    ];

    /**
     * @var PostingComponent
     */
    public $component;

    /**
     * @var Controller
     */
    public $controller;

    public function setUp(): void
    {
        parent::setUp();
        // Setup our component and fake test controller
        $request = new ServerRequest(['url' => '/users/view/5']);
        $response = new Response();
        // A real controller: the double replaced no method at all
        // (`onlyMethods([])`), so it only ever stood in for `new`.
        $this->controller = new Controller($request);
        $registry = new ComponentRegistry($this->controller);
        $this->component = new PostingComponent($registry);

        $this->insertCategoryPermissions();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        // Clean up after we're done
        unset($this->component, $this->controller);
    }

    public function testCreateUserThreadDisallowed()
    {
        $thread = ['subject' => 'foo', 'category_id' => 4];

        $user = ['id' => 100, 'username' => 'foo', 'user_type' => 'user'];
        $user = CurrentUserFactory::createLoggedIn($user);

        $this->expectException(SaitoForbiddenException::class);

        $this->component->create($thread, $user);
    }

    public function testCreateUserAnswerDisallowed()
    {
        $answer = ['pid' => 6, 'subject' => 'foo'];
        $user = ['id' => 100, 'username' => 'foo', 'user_type' => 'user'];
        $user = CurrentUserFactory::createLoggedIn($user);

        $this->expectException(SaitoForbiddenException::class);

        $result = $this->component->create($answer, $user);
    }

    public function testCreateUserAnswerAllowed()
    {
        $answer = ['pid' => 11, 'subject' => 'foo', 'name' => 'foo', 'user_id' => 100];

        $user = ['id' => 100, 'username' => 'foo', 'user_type' => 'user'];
        $user = CurrentUserFactory::createLoggedIn($user);

        $posting = $this->component->create($answer, $user);

        $errors = $posting->getErrors();
        $this->assertEmpty($errors);
    }

    public function testCreateAdminAllowed()
    {
        $admin = ['id' => 101, 'username' => 'foo', 'user_type' => 'admin'];
        $admin = CurrentUserFactory::createLoggedIn($admin);

        $thread = ['subject' => 'foo', 'category_id' => 4, 'name' => 'foo', 'user_id' => 101];
        $answer = ['pid' => 11] + $thread;

        foreach ([$thread, $answer] as $data) {
            $posting = $this->component->create($answer, $admin);

            $this->assertEmpty($posting->getErrors());
        }
    }

    public function testCreateParentDoesNotExist()
    {
        $answer = ['pid' => 9999, 'subject' => 'foo'];
        $user = ['id' => 100, 'username' => 'foo', 'user_type' => 'user'];
        $user = CurrentUserFactory::createLoggedIn($user);

        $this->expectException(RecordNotFoundException::class);

        $this->component->create($answer, $user);
    }

    public function testCreateNewThreadButNoCategoryProvided()
    {
        $answer = ['subject' => 'foo'];
        $user = ['id' => 100, 'username' => 'foo', 'user_type' => 'user'];
        $user = CurrentUserFactory::createLoggedIn($user);

        $this->expectException(\InvalidArgumentException::class);

        $result = $this->component->create($answer, $user);
    }

    public function testUpdateSuccesModOnPinnedPosting()
    {
        $now = (string)time();
        $edit = ['subject' => $now];

        $table = TableRegistry::getTableLocator()->get('Entries');
        $entity = $table->findById(11)->first();

        $user = ['id' => 7, 'user_type' => 'mod', 'username' => 'bar'];
        $user = CurrentUserFactory::createLoggedIn($user);

        $result = $this->component->update($entity, $edit, $user);

        $this->assertEmpty($result->getErrors());
        $this->assertEquals($now, $result->get('subject'));
    }

    public function testUpdateFailureModOnOwnPosting()
    {
        $now = (string)time();
        $edit = ['subject' => $now];

        $table = TableRegistry::getTableLocator()->get('Entries');
        $entity = $table->findById(11)->first();
        $entity->set('fixed', false);

        $user = ['id' => 7, 'user_type' => 'mod', 'username' => 'bar'];
        $user = CurrentUserFactory::createLoggedIn($user);

        $this->expectException(SaitoForbiddenException::class);

        $this->component->update($entity, $edit, $user);
    }

    /**
     * A thread may not be moved into a category the mover has no right to.
     *
     * Changing a root posting's category moves the whole thread, replies by
     * other people included. Editing rights answer where the posting *is*;
     * they say nothing about where it is going, so the target needs its own
     * check — the same one create() makes for a new thread.
     *
     * @return void
     */
    public function testUpdateCannotMoveThreadIntoForbiddenCategory()
    {
        $table = TableRegistry::getTableLocator()->get('Entries');
        // Posting 1 is a thread root in category 2.
        $entity = $table->findById(1)->first();

        // Category 5 requires accession 3 (admins) to start a thread.
        $edit = ['subject' => 'moved', 'category_id' => 5];

        $user = ['id' => 7, 'user_type' => 'mod', 'username' => 'bar'];
        $user = CurrentUserFactory::createLoggedIn($user);

        $this->expectException(SaitoForbiddenException::class);

        $this->component->update($entity, $edit, $user);
    }

    /**
     * …but a category the mover may use is still allowed, so the check
     * discriminates rather than simply forbidding every move.
     *
     * @return void
     */
    public function testUpdateCanMoveThreadIntoPermittedCategory()
    {
        $table = TableRegistry::getTableLocator()->get('Entries');
        $entity = $table->findById(1)->first();

        // Category 3 only requires accession 1.
        $edit = ['subject' => 'moved', 'category_id' => 3];

        $user = ['id' => 7, 'user_type' => 'mod', 'username' => 'bar'];
        $user = CurrentUserFactory::createLoggedIn($user);

        $result = $this->component->update($entity, $edit, $user);

        $this->assertEmpty($result->getErrors());
        $this->assertEquals(3, $result->get('category_id'));
    }

    public function testPrepareChildPosting()
    {
        $parent = [
            'id' => 123,
            'category_id' => 456,
            'subject' => 'parent subject',
            'tid' => 789,
        ];
        $parent = new Posting($parent);

        $data = $this->component->prepareChildPosting($parent, []);

        $this->assertEquals(456, $data['category_id']);
        $this->assertEquals('parent subject', $data['subject']);
        $this->assertEquals(789, $data['tid']);
    }
}
