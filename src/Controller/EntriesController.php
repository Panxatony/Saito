<?php

declare(strict_types=1);

/**
 * Saito - The Threaded Web Forum
 *
 * @copyright Copyright (c) the Saito Project Developers
 * @link https://github.com/Schlaefer/Saito
 * @license http://opensource.org/licenses/MIT
 */

namespace App\Controller;

use App\Controller\Component\AutoReloadComponent;
use App\Controller\Component\MarkAsReadComponent;
use App\Controller\Component\PostingComponent;
use App\Controller\Component\RefererComponent;
use App\Controller\Component\ThreadsComponent;
use App\Model\Table\EntriesTable;
use Cake\Core\Configure;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Event\Event;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\MethodNotAllowedException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Saito\Exception\SaitoForbiddenException;
use Saito\Posting\Basic\BasicPostingInterface;
use Saito\User\CurrentUser\CurrentUserInterface;
use Saito\User\Permission\ResourceAI;
use Stopwatch\Lib\Stopwatch;

/**
 * Class EntriesController
 *
 * @property CurrentUserInterface $CurrentUser
 * @property EntriesTable $Entries
 * @property MarkAsReadComponent $MarkAsRead
 * @property PostingComponent $Posting
 * @property RefererComponent $Referer
 * @property ThreadsComponent $Threads
 */
class EntriesController extends AppController
{
    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->addHelpers(['Posting', 'Text']);

        $this->loadComponent('Posting');
        $this->loadComponent('MarkAsRead');
        $this->loadComponent('Referer');
        $this->loadComponent('Threads', ['table' => $this->Entries]);
    }

    /**
     * posting index
     *
     * @return void|\Cake\Http\Response
     */
    public function index()
    {
        Stopwatch::start('Entries->index()');

        //= determine user sort order
        $sortKey = 'last_answer';
        if (!$this->CurrentUser->get('user_sort_last_answer')) {
            $sortKey = 'time';
        }
        $order = ['fixed' => 'DESC', $sortKey => 'DESC'];

        //= get threads
        $threads = $this->Threads->paginate($order, $this->CurrentUser);
        $this->set('entries', $threads);

        $currentPage = (int)$this->request->getQuery('page') ?: 1;
        if ($currentPage > 1) {
            $this->set('titleForLayout', __('page') . ' ' . $currentPage);
        }
        if ($currentPage === 1) {
            if ($this->MarkAsRead->refresh()) {
                return $this->redirect(['action' => 'index']);
            }
            $this->MarkAsRead->next();
        }

        // @bogus
        $this->request->getSession()->write('paginator.lastPage', $currentPage);
        $this->set('showDisclaimer', true);
        $this->set('showBottomNavigation', true);
        $this->Slidetabs->show();

        $this->_setupCategoryChooser($this->CurrentUser);

        /** @var AutoReloadComponent */
        $autoReload = $this->loadComponent('AutoReload');
        $autoReload->after($this->CurrentUser);

        Stopwatch::stop('Entries->index()');
    }

    /**
     * Front-page thread list as an htmx island (strangler-fig migration).
     *
     * Same paginated thread data as {@see index()} (Threads->paginate), rendered
     * standalone (no SPA). An `HX-Request` returns just the thread-list page
     * fragment (for htmx "load more" pagination); a direct visit gets the shell.
     * Read-only: the mark-as-read side effects, category chooser and slidetabs
     * of index() are intentionally out of scope for this slice.
     *
     * @return void
     */
    public function htmxIndex()
    {
        $sortKey = $this->CurrentUser->get('user_sort_last_answer') ? 'last_answer' : 'time';
        $order = ['fixed' => 'DESC', $sortKey => 'DESC'];

        // Island category filter (?category=<id>): restrict the list to one
        // readable category; 'all'/absent shows everything.
        $onlyCategories = null;
        $catParam = $this->getRequest()->getQuery('category');
        if ($catParam !== null && $catParam !== 'all' && ctype_digit((string)$catParam)) {
            $onlyCategories = [(int)$catParam];
        }
        $this->set('entries', $this->Threads->paginate($order, $this->CurrentUser, $onlyCategories));

        // Marker for the live "new postings" poller: the newest entry id at
        // render time. Entry ids are globally monotonic, so any posting created
        // afterwards has a higher id — no timezone/clock handling needed.
        $newest = $this->Entries->find()->select(['id'])->orderByDesc('Entries.id')->first();
        $this->set('newestEntryId', $newest?->get('id') ?? 0);

        // Category chooser: the readable categories + the active one, so a
        // logged-in user with a choice can filter the list (paginate() already
        // honours the user's active categories).
        if ($this->CurrentUser->isLoggedIn()) {
            $catList = $this->CurrentUser->getCategories()->getAll('read', 'list');
            if (count($catList) > 1) {
                $this->set('categoryChooser', $catList);
                $this->set('activeCategory', $onlyCategories !== null ? (int)$catParam : 'all');
            }
        }

        // htmx swaps only the thread-list page fragment; a direct visit gets
        // the shell page in the standalone htmx_island layout.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $this->viewBuilder()
                ->disableAutoLayout()
                ->setTemplate('htmx_index_threads');
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_index');
        }
    }

    /**
     * Live "new postings" count for the htmx front-page island.
     *
     * Polled by the island: counts postings in the user's readable categories
     * created since the `since` entry id the page was rendered with, and renders
     * a small banner fragment (empty when there is nothing new). Read-only.
     *
     * @return void
     */
    public function htmxNewCount()
    {
        $since = (int)$this->request->getQuery('since');
        $count = 0;
        if ($since > 0) {
            $categories = $this->CurrentUser->getCategories()->getAll('read');
            if (!empty($categories)) {
                $count = $this->Entries->find()
                    ->where([
                        'Entries.id >' => $since,
                        'Entries.category_id IN' => $categories,
                    ])
                    ->count();
            }
        }
        $this->set('newCount', $count);
        $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_new_count');
    }

    /**
     * The right-rail widgets for the island front page: who's online, recent
     * posts, and — for members — the user's own recent posts. Rendered as a
     * fragment the sidebar htmx-refreshes on a poll and after new posts. Public
     * (guests see online + recent).
     *
     * @return void
     */
    public function htmxWidgets()
    {
        $stats = \Saito\App\Registry::get('AppStats');
        if ($stats !== null) {
            $this->set('online', $stats->getRegistredUsersOnline());
            $this->set('onlineCount', $stats->getNumberOfRegisteredUsersOnline());
            $this->set('guestCount', $stats->getNumberOfAnonUsersOnline());
            $this->set('botCount', $stats->getNumberOfBotsOnline());
        }
        $this->set('recentEntries', $this->Entries->getRecentPostings($this->CurrentUser));
        if ($this->CurrentUser->isLoggedIn()) {
            $this->set('myPosts', $this->Entries->getRecentPostings(
                $this->CurrentUser,
                ['user_id' => $this->CurrentUser->getId(), 'limit' => 5]
            ));
        }
        $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_widgets');
    }

    /**
     * Full thread reading view for the htmx island (strangler-fig migration).
     *
     * Same flattened-thread data + "mix" rendering as {@see mix()}, standalone
     * (no SPA). The island's reply handler enhances the per-posting answer
     * buttons. Read-only otherwise (no live view-count bump, no answering panel
     * chrome). Public like mix().
     *
     * @param string|null $tid thread-ID
     * @return \Cake\Http\Response|void
     */
    public function htmxThread($tid = null)
    {
        $tid = (int)$tid;
        if ($tid <= 0) {
            throw new BadRequestException();
        }

        try {
            $postings = $this->Entries->postingsForThread($tid, true, $this->CurrentUser);
        } catch (RecordNotFoundException $e) {
            $actualTid = $this->Entries->getThreadId($tid);

            return $this->redirect(['action' => 'htmxThread', $actualTid], 301);
        }

        if (!$this->CurrentUser->getCategories()->permission('read', $postings->get('category'))) {
            return $this->_requireAuth();
        }

        $this->set('entries', $postings);
        // view_posting needs the thread root + the answering flag (set by
        // _showAnsweringPanel only for view/mix); provide them directly.
        $this->_setRootEntry($postings);
        $this->set('showAnsweringPanel', $this->CurrentUser->isLoggedIn());
        $this->set('titleForLayout', $postings->get('subject'));

        // Same side effects as the SPA mix() view: bump the view counter and
        // mark the whole thread read for the current user.
        $this->Threads->incrementViewsForThread($postings, $this->CurrentUser);
        $this->MarkAsRead->thread($postings);

        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_thread');
    }

    /**
     * Create a new thread (root posting) via the htmx island — the write path
     * for new topics (the SPA answering module's other half).
     *
     * GET renders a standalone form (category / subject / text); POST creates
     * the root posting via the same PostingComponent the REST API uses and
     * redirects to the new thread, or re-renders the form with errors. A native
     * form (FormHelper supplies the CSRF token) so it also works without JS. The
     * rich BBCode editor / upload / preview stays a later island. Login required.
     *
     * @return \Cake\Http\Response|void
     */
    public function htmxAdd()
    {
        $this->set('categories', $this->CurrentUser->getCategories()->getAll('thread', 'select'));
        $this->set('titleForLayout', __('Write a New Posting'));

        $isHx = $this->getRequest()->getHeaderLine('HX-Request') === 'true';
        // Inline editor (on the front page) keeps the result on the page; the
        // standalone page navigates to the new thread.
        $inline = (bool)$this->getRequest()->getData('inline') || (bool)$this->getRequest()->getQuery('inline');
        $this->set('inline', $inline);

        if ($this->getRequest()->is('post')) {
            $data = [
                'pid' => 0,
                'category_id' => $this->getRequest()->getData('category_id'),
                'subject' => (string)$this->getRequest()->getData('subject'),
                'text' => (string)$this->getRequest()->getData('text'),
                'name' => $this->CurrentUser->get('username'),
                'user_id' => $this->CurrentUser->getId(),
            ];
            try {
                $posting = $this->Posting->create($data, $this->CurrentUser);
            } catch (SaitoForbiddenException $e) {
                $posting = null;
            }

            if ($posting !== null && !$posting->getErrors()) {
                if ($isHx && $inline) {
                    // Stay on the page: confirm + trigger the thread list to reload.
                    $this->set('posting', $posting);
                    $this->response = $this->response->withHeader('HX-Trigger', 'refresh-recent');
                    $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_add_done');

                    return;
                }
                $threadUrl = \Cake\Routing\Router::url(['action' => 'htmxThread', $posting->get('id')]);
                if ($isHx) {
                    return $this->response->withHeader('HX-Redirect', $threadUrl);
                }

                return $this->redirect($threadUrl);
            }

            $this->set('errors', $posting !== null ? $posting->getErrors() : []);
        }

        if ($isHx) {
            $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_add_form_fragment');
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_add');
        }
    }

    /**
     * Edit an existing posting via the htmx island — the counterpart to the
     * classic edit()/REST update path (which are token-auth). Standalone island
     * page: GET renders the edit form pre-filled, POST updates via the same
     * PostingComponent the REST API uses and redirects back to the thread.
     * Permission is enforced by the posting itself (isEditingAllowed); a
     * mod-editing notice is shown when editing another user's posting. Login
     * required.
     *
     * @param string|null $id posting id
     * @return \Cake\Http\Response|void
     */
    public function htmxEdit($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) {
            throw new BadRequestException();
        }
        $entry = $this->Entries->get($id);
        $posting = $entry->toPosting()->withCurrentUser($this->CurrentUser);

        if (!$posting->isEditingAllowed()) {
            throw new SaitoForbiddenException(
                'Access to posting in EntriesController:htmxEdit() forbidden.',
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        $isRoot = $entry->isRoot();
        $isHx = $this->getRequest()->getHeaderLine('HX-Request') === 'true';

        if ($this->getRequest()->is(['post', 'put'])) {
            $data = [
                'subject' => (string)$this->getRequest()->getData('subject'),
                'text' => (string)$this->getRequest()->getData('text'),
            ];
            // Only a thread's root carries the category.
            if ($isRoot) {
                $data['category_id'] = $this->getRequest()->getData('category_id');
            }
            // `edited` / `edited_by` are server-set (never client-supplied) so the
            // thread shows the "edited by …" marker — same as the REST edit path.
            $data += [
                'edited' => bDate(),
                'edited_by' => $this->CurrentUser->get('username'),
            ];
            try {
                $updated = $this->Posting->update($entry, $data, $this->CurrentUser);
            } catch (SaitoForbiddenException $e) {
                $updated = null;
            }

            if ($updated !== null && !$updated->getErrors()) {
                $threadUrl = \Cake\Routing\Router::url(
                    ['action' => 'htmxThread', $entry->get('tid')]
                ) . '#p' . $id;
                if ($isHx) {
                    return $this->response->withHeader('HX-Redirect', $threadUrl);
                }

                return $this->redirect($threadUrl);
            }

            $this->set('errors', $updated !== null ? $updated->getErrors() : []);
        }

        // Editing another user's posting (moderator) — warn like the classic form.
        if (!$posting->isEditingAsUserAllowed()) {
            $this->set('editingAsMod', true);
        }

        if ($isRoot) {
            $this->set('categories', $this->CurrentUser->getCategories()->getAll('thread', 'select'));
        }
        $this->set('posting', $posting);
        $this->set('isRoot', $isRoot);
        $this->set('titleForLayout', __('edit_linkname'));
        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_edit_posting');
    }

    /**
     * Merge a root thread onto another via the htmx island — the counterpart to
     * the classic merge() (Admin-layout) action. Authorized in beforeFilter with
     * `saito.core.posting.merge` (moderators). GET renders a standalone island
     * form asking for the target posting id; POST performs the merge and
     * redirects to the (now merged) thread.
     *
     * @param string|null $sourceId root posting id to merge away
     * @return \Cake\Http\Response|void
     */
    public function htmxMerge($sourceId = null)
    {
        $sourceId = (int)$sourceId;
        if ($sourceId <= 0) {
            throw new NotFoundException();
        }

        /** @var \App\Model\Entity\Entry $entry */
        $entry = $this->Entries->findById($sourceId)->first();
        if (!$entry || !$entry->isRoot()) {
            throw new NotFoundException();
        }

        $isHx = $this->getRequest()->getHeaderLine('HX-Request') === 'true';

        if ($this->getRequest()->is('post')) {
            $targetId = (int)$this->getRequest()->getData('targetId');
            if ($targetId > 0 && $this->Entries->threadMerge($sourceId, $targetId)) {
                $threadUrl = \Cake\Routing\Router::url(['action' => 'htmxThread', $sourceId]);
                if ($isHx) {
                    return $this->response->withHeader('HX-Redirect', $threadUrl);
                }

                return $this->redirect($threadUrl);
            }
            $this->set('mergeError', true);
        }

        $this->set('posting', $entry);
        $this->set('titleForLayout', __('merge_tree_link'));
        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_merge');
    }

    /**
     * Render a BBCode preview for the htmx editor toolbar (session-based; the
     * REST PreviewController is token-auth only). Login required.
     *
     * @return void
     */
    public function htmxPreview()
    {
        $this->set('previewText', (string)$this->getRequest()->getData('text'));
        $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_preview');
    }

    /**
     * Store an image/file upload for the htmx editor and return its name so the
     * editor can insert the `[img src=upload]<name>[/img]` tag. Session-based
     * (the REST UploadsController is token-auth); reuses the exact same secure
     * storage via UploadsTable::createFromUpload(). Login required.
     *
     * @return \Cake\Http\Response
     */
    public function htmxUpload()
    {
        $userId = $this->CurrentUser->getId();
        $user = $this->fetchTable('Users')->get($userId);
        $resourceAi = (new \Saito\User\Permission\ResourceAI())
            ->onRole($user->getRole())
            ->onOwner($user->getId());
        if (!$this->CurrentUser->permission('saito.plugin.uploader.add', $resourceAi)) {
            throw new SaitoForbiddenException(
                'Upload not allowed.',
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        $file = \Saito\RequestUpload::toArray($this->getRequest()->getUploadedFile('file'));
        if ($file === null || empty($file['tmp_name'])) {
            return $this->response
                ->withType('json')
                ->withStatus(422)
                ->withStringBody((string)json_encode(['error' => __d('image_uploader', 'add.failure')]));
        }

        $Uploads = $this->fetchTable('ImageUploader.Uploads');
        try {
            $upload = $Uploads->createFromUpload($file, $userId);
        } catch (\RuntimeException $e) {
            return $this->response
                ->withType('json')
                ->withStatus(422)
                ->withStringBody((string)json_encode(['error' => $e->getMessage()]));
        }

        return $this->response
            ->withType('json')
            ->withStringBody((string)json_encode([
                'name' => $upload->get('name'),
                'mime' => $upload->get('type'),
            ]));
    }

    /**
     * The current user's upload archive for the editor upload overlay — a page
     * of thumbnail tiles (20 per page, newest first) plus a "load more" control.
     * Session-based (the REST uploads API is token-auth). Login required.
     *
     * @return void
     */
    public function htmxUploads()
    {
        $userId = $this->CurrentUser->getId();
        if (!$userId) {
            throw new BadRequestException();
        }
        $perPage = 20;
        $page = max(1, (int)$this->getRequest()->getQuery('page'));

        $Uploads = $this->fetchTable('ImageUploader.Uploads');
        $query = $Uploads->find()->where(['user_id' => $userId])->orderBy(['id' => 'DESC']);
        $total = $query->count();
        $uploads = $query->limit($perPage)->offset(($page - 1) * $perPage)->all();

        $this->set('uploads', $uploads);
        $this->set('page', $page);
        $this->set('hasMore', ($page * $perPage) < $total);
        // `?manage=1` (the profile view) renders a delete control per tile.
        $this->set('manage', (bool)$this->getRequest()->getQuery('manage'));
        $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_uploads');
    }

    /**
     * Delete one of the current user's uploads via the htmx island (session
     * based; the ImageUploader REST controller is token-auth). Same permission
     * (`saito.plugin.uploader.delete`, owner-scoped) as the REST delete. POST;
     * returns an empty 200 so htmx removes the tile from the profile grid.
     *
     * @param string|null $id upload id
     * @return \Cake\Http\Response
     */
    public function htmxUploadDelete($id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $id = (int)$id;
        if ($id <= 0) {
            throw new BadRequestException();
        }
        $Uploads = $this->fetchTable('ImageUploader.Uploads');
        /** @var \ImageUploader\Model\Entity\Upload $upload */
        $upload = $Uploads->get($id, contain: ['Users']);

        $allowed = $this->CurrentUser->permission(
            'saito.plugin.uploader.delete',
            (new ResourceAI())->onRole($upload->user->getRole())->onOwner($upload->user->getId()),
        );
        if (!$allowed) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to delete upload "%s".', $id),
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        if (!$Uploads->delete($upload)) {
            throw new BadRequestException();
        }
        \Cake\Cache\Cache::delete((string)$id, 'uploadsThumbnails');

        // Empty 200 (not 204) so htmx swaps the tile away via outerHTML.
        $this->autoRender = false;

        return $this->response->withStringBody('');
    }

    /**
     * Inline reply to a posting, for the htmx island (strangler-fig migration).
     *
     * GET renders a minimal reply form; POST creates the answer via the same
     * PostingComponent the REST API uses and returns a confirmation (or the form
     * with validation errors). This is the write path the SPA answering module
     * covers; the rich editor (BBCode toolbar, upload, preview) stays a later
     * island — this is a plain subject/text form. Login required.
     *
     * @param string|null $id parent posting-ID
     * @return void
     */
    public function htmxReply($id = null)
    {
        $parent = $this->Entries->get((int)$id);
        $parentPosting = $parent->toPosting()->withCurrentUser($this->CurrentUser);

        $this->viewBuilder()->disableAutoLayout();
        $this->set('parentId', $parent->get('id'));

        if ($parentPosting->isAnsweringForbidden()) {
            $this->set('forbidden', true);
            $this->viewBuilder()->setTemplate('htmx_reply_form');

            return;
        }

        if ($this->getRequest()->is('post')) {
            $data = [
                'pid' => $parent->get('id'),
                'subject' => (string)$this->getRequest()->getData('subject'),
                'text' => (string)$this->getRequest()->getData('text'),
                // Required by validation and set the same way as the REST add().
                'name' => $this->CurrentUser->get('username'),
                'user_id' => $this->CurrentUser->getId(),
            ];
            try {
                $posting = $this->Posting->create($data, $this->CurrentUser);
            } catch (SaitoForbiddenException $e) {
                $posting = null;
            }

            if ($posting !== null && !$posting->getErrors()) {
                $this->set('posting', $posting);
                $this->viewBuilder()->setTemplate('htmx_reply_done');

                return;
            }

            $this->set('errors', $posting !== null ? $posting->getErrors() : []);
            $this->set('submitted', $data);
        }

        $this->viewBuilder()->setTemplate('htmx_reply_form');
    }

    /**
     * Mix view
     *
     * @param string $tid thread-ID
     * @return void|Response
     * @throws NotFoundException
     */
    public function mix($tid)
    {
        $tid = (int)$tid;
        if ($tid <= 0) {
            throw new BadRequestException();
        }

        try {
            $postings = $this->Entries->postingsForThread($tid, true, $this->CurrentUser);
        } catch (RecordNotFoundException $e) {
            /// redirect sub-posting to mix view of thread
            $actualTid = $this->Entries->getThreadId($tid);

            return $this->redirect([$actualTid, '#' => $tid], 301);
        }

        // check if anonymous tries to access internal categories
        $root = $postings;
        if (!$this->CurrentUser->getCategories()->permission('read', $root->get('category'))) {
            return $this->_requireAuth();
        }

        $this->_setRootEntry($root);
        $this->Title->setFromPosting($root, __('view.type.mix'));

        $this->set('showBottomNavigation', true);
        $this->set('entries', $postings);

        $this->_showAnsweringPanel();

        $this->Threads->incrementViewsForThread($root, $this->CurrentUser);
        $this->MarkAsRead->thread($postings);
    }

    /**
     * load front page force all entries mark-as-read
     *
     * @return void
     */
    public function update()
    {
        $this->autoRender = false;
        $this->CurrentUser->getLastRefresh()->set();

        // Island "mark all read": no SPA redirect — return empty and let the
        // thread list reload itself via the refresh-recent trigger.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            return $this->response->withStatus(204)->withHeader('HX-Trigger', 'refresh-recent');
        }

        $this->redirect('/entries/index');
    }

    /**
     * Outputs raw markup of an posting $id
     *
     * @param string $id posting-ID
     * @return void
     */
    public function source($id = null)
    {
        $this->viewBuilder()->enableAutoLayout(false);
        $this->view($id);
    }

    /**
     * View posting.
     *
     * @param string $id posting-ID
     * @return \Cake\Http\Response|void
     */
    public function view(string $id)
    {
        $id = (int)$id;
        Stopwatch::start('Entries->view()');

        $entry = $this->Entries->get($id);
        $posting = $entry->toPosting()->withCurrentUser($this->CurrentUser);

        if (!$this->CurrentUser->getCategories()->permission('read', $posting->get('category'))) {
            return $this->_requireAuth();
        }

        $this->set('entry', $posting);
        $this->Threads->incrementViewsForPosting($posting, $this->CurrentUser);
        $this->_setRootEntry($posting);
        $this->_showAnsweringPanel();

        $this->MarkAsRead->posting($posting);

        // inline open
        if ($this->request->is('ajax')) {
            return $this->render('/element/entry/view_posting');
        }

        // full page request
        $this->set(
            'tree',
            $this->Entries->postingsForThread($posting->get('tid'), false, $this->CurrentUser)
        );
        $this->Title->setFromPosting($posting);

        Stopwatch::stop('Entries->view()');
    }

    /**
     * Add new posting.
     *
     * @return void|\Cake\Http\Response
     */
    public function add()
    {
        $titleForPage = __('Write a New Posting');
        $this->set(compact('titleForPage'));
    }

    /**
     * Edit posting
     *
     * @param string $id posting-ID
     * @return void|\Cake\Http\Response
     * @throws NotFoundException
     * @throws BadRequestException
     */
    public function edit(string $id)
    {
        $id = (int)$id;
        $entry = $this->Entries->get($id);
        $posting = $entry->toPosting()->withCurrentUser($this->CurrentUser);

        if (!$posting->isEditingAllowed()) {
            throw new SaitoForbiddenException(
                'Access to posting in EntriesController:edit() forbidden.',
                ['CurrentUser' => $this->CurrentUser]
            );
        }

        // show editing form
        if (!$posting->isEditingAsUserAllowed()) {
            $this->Flash->set(
                __('notice_you_are_editing_as_mod'),
                ['element' => 'warning']
            );
        }

        $this->set(compact('posting'));

        // set headers
        $this->set(
            'headerSubnavLeftTitle',
            __('back_to_posting_from_linkname', $posting->get('name'))
        );
        $this->set('headerSubnavLeftUrl', ['action' => 'view', $id]);
        $this->set('form_title', __('edit_linkname'));
        $this->render('/Entries/add');
    }

    /**
     * Get thread-line to insert after an inline-answer
     *
     * @param string $id posting-ID
     * @return void|\Cake\Http\Response
     */
    public function threadLine($id = null)
    {
        $posting = $this->Entries->get($id)->toPosting()->withCurrentUser($this->CurrentUser);
        if (!$this->CurrentUser->getCategories()->permission('read', $posting->get('category'))) {
            return $this->_requireAuth();
        }

        $this->set('entrySub', $posting);
        // ajax requests so far are always answers
        $this->response = $this->response->withType('json');
        $this->set('level', '1');
    }

    /**
     * Delete posting
     *
     * @param string $id posting-ID
     * @return void
     * @throws NotFoundException
     * @throws MethodNotAllowedException
     */
    public function delete(string $id)
    {
        $id = (int)$id;
        if (!$id) {
            throw new NotFoundException();
        }
        /* @var Entry $posting */
        $posting = $this->Entries->get($id);

        $action = $posting->isRoot() ? 'thread' : 'answer';
        $allowed = $this->CurrentUser->getCategories()
            ->permission($action, $posting->get('category_id'));
        if (!$allowed) {
            throw new SaitoForbiddenException();
        }

        // A bare GET only renders a CSRF-protected confirmation form; the actual
        // deletion requires POST/DELETE. This stops a lured cross-site GET
        // (which carries no CSRF/FormProtection token) from destroying content.
        if (!$this->request->is(['post', 'delete'])) {
            $this->set('posting', $posting);

            return null;
        }

        $success = $this->Entries->deletePosting($id);

        if ($success) {
            $flashType = 'success';
            if ($posting->isRoot()) {
                $message = __('delete_tree_success');
                $redirect = '/';
            } else {
                $message = __('delete_subtree_success');
                $redirect = '/entries/view/' . $posting->get('pid');
            }
        } else {
            $flashType = 'error';
            $message = __('delete_tree_error');
            $redirect = $this->referer();
        }
        $this->Flash->set($message, ['element' => $flashType]);
        $this->redirect($redirect);
    }

    /**
     * Empty function for benchmarking
     *
     * @return void
     */
    public function e()
    {
        Stopwatch::start('Entries->e()');
        Stopwatch::stop('Entries->e()');
    }

    /**
     * Marks sub-entry $id as solution to its current root-entry
     *
     * @param string $id posting-ID
     * @return void
     * @throws BadRequestException
     */
    public function solve($id)
    {
        $this->autoRender = false;
        try {
            $posting = $this->Entries->get($id);

            if (empty($posting)) {
                throw new \InvalidArgumentException('Posting to mark solved not found.');
            }

            $rootId = $posting->get('tid');
            $rootPosting = $this->Entries->get($rootId);

            $allowed = $this->CurrentUser->permission(
                'saito.core.posting.solves.set',
                (new ResourceAI())->onRole($rootPosting->get('user')->getRole())->onOwner($rootPosting->get('user_id'))
            );
            if (!$allowed) {
                throw new SaitoForbiddenException(
                    sprintf('Attempt to mark posting %s as solution.', $posting->get('id')),
                    ['CurrentUser' => $this->CurrentUser]
                );
            }

            $value = $posting->get('solves') ? 0 : $rootPosting->get('tid');
            $success = $this->Entries->updateEntry($posting, ['solves' => $value]);

            if (!$success) {
                throw new BadRequestException();
            }
        } catch (\Exception $e) {
            throw new BadRequestException();
        }
    }

    /**
     * Toggle the current user's bookmark for a posting (session/CSRF variant of
     * the token-authed REST bookmarks API, for the htmx island posting view).
     *
     * @param string $id posting-ID
     * @return \Cake\Http\Response
     */
    public function htmxBookmark($id)
    {
        $this->autoRender = false;
        $entryId = (int)$id;
        $userId = $this->CurrentUser->getId();
        if (!$entryId || !$userId || !$this->request->is(['post', 'ajax'])) {
            throw new BadRequestException();
        }

        $Bookmarks = $this->fetchTable('Bookmarks.Bookmarks');
        $existing = $Bookmarks->find()
            ->where(['user_id' => $userId, 'entry_id' => $entryId])
            ->first();

        if ($existing) {
            $Bookmarks->delete($existing);
            $bookmarked = false;
        } else {
            $bookmark = $Bookmarks->createBookmark(['user_id' => $userId, 'entry_id' => $entryId]);
            $bookmarked = $bookmark && empty($bookmark->getErrors());
        }

        $this->response = $this->response
            ->withType('json')
            ->withStringBody((string)json_encode(['bookmarked' => $bookmarked]));

        return $this->response;
    }

    /**
     * Merge threads.
     *
     * @param string $sourceId posting-ID of thread to be merged
     * @return void
     * @throws NotFoundException
     * @td put into admin entries controller
     */
    public function merge(?string $sourceId = null)
    {
        $sourceId = (int)$sourceId;
        if (empty($sourceId)) {
            throw new NotFoundException();
        }

        /* @var Entry */
        $entry = $this->Entries->findById($sourceId)->first();

        if (!$entry || !$entry->isRoot()) {
            throw new NotFoundException();
        }

        // perform move operation
        $targetId = (int)$this->request->getData('targetId');
        if (!empty($targetId)) {
            if ($this->Entries->threadMerge($sourceId, $targetId)) {
                $this->redirect('/entries/view/' . $sourceId);

                return;
            } else {
                $this->Flash->set(__('Error'), ['element' => 'error']);
            }
        }

        $this->viewBuilder()->setLayout('Admin.admin');
        $this->set('posting', $entry);
    }

    /**
     * Toggle posting property via ajax request.
     *
     * @param string $id posting-ID
     * @param string $toggle property
     *
     * @return \Cake\Http\Response
     */
    public function ajaxToggle($id = null, $toggle = null)
    {
        $allowed = ['fixed', 'locked'];
        if (
            !$id
            || !$toggle
            || !$this->request->is('ajax')
            || !in_array($toggle, $allowed)
        ) {
            throw new BadRequestException();
        }

        $posting = $this->Entries->get($id);
        $data = ['id' => (int)$id, $toggle => !$posting->get($toggle)];
        // Pinning/locking is authorized via authorizeAction('ajaxToggle',
        // 'saito.core.posting.pinAndLock') above, so update the toggle field
        // directly. Going through PostingComponent::update() would also require
        // edit permission (isEditingAllowed), which would wrongly block a
        // moderator from pinning/locking threads they may not edit.
        $this->Entries->updateEntry($posting, $data);

        $this->response = $this->response->withType('json');
        $this->response = $this->response->withStringBody(json_encode('OK'));

        return $this->response;
    }

    /**
     * {@inheritDoc}
     */
    public function beforeFilter(\Cake\Event\EventInterface $event)
    {
        parent::beforeFilter($event);
        Stopwatch::start('Entries->beforeFilter()');

        $this->FormProtection->setConfig(
            'unlockedActions',
            // htmxReply/htmxAdd/htmxPreview/htmxUpload rely on CSRF (island header
            // / FormHelper token) instead of a FormProtection token, like the REST
            // posting endpoints.
            ['solve', 'view', 'htmxReply', 'htmxAdd', 'htmxPreview', 'htmxUpload', 'htmxBookmark',
                'htmxEdit', 'htmxMerge', 'htmxUploadDelete']
        );
        $this->Authentication->allowUnauthenticated(['index', 'view', 'mix', 'update', 'htmxIndex', 'htmxNewCount', 'htmxThread', 'htmxWidgets']);

        $this->AuthUser->authorizeAction('ajaxToggle', 'saito.core.posting.pinAndLock');
        $this->AuthUser->authorizeAction('merge', 'saito.core.posting.merge');
        $this->AuthUser->authorizeAction('htmxMerge', 'saito.core.posting.merge');
        $this->AuthUser->authorizeAction('delete', 'saito.core.posting.delete');

        Stopwatch::stop('Entries->beforeFilter()');
    }

    /**
     * set view vars for category chooser
     *
     * @param CurrentUserInterface $User CurrentUser
     * @return void
     */
    protected function _setupCategoryChooser(CurrentUserInterface $User)
    {
        if (!$User->isLoggedIn()) {
            return;
        }
        $globalActivation = Configure::read(
            'Saito.Settings.category_chooser_global'
        );
        if (!$globalActivation) {
            if (
                !Configure::read(
                    'Saito.Settings.category_chooser_user_override'
                )
            ) {
                return;
            }
            if (!$User->get('user_category_override')) {
                return;
            }
        }

        $this->set(
            'categoryChooserChecked',
            $User->getCategories()->getCustom('read')
        );
        switch ($User->getCategories()->getType()) {
            case 'single':
                $title = $User->get('user_category_active');
                break;
            case 'custom':
                $title = __('Custom');
                break;
            default:
                $title = __('All Categories');
        }
        $this->set('categoryChooserTitleId', $title);
        $this->set(
            'categoryChooser',
            $User->getCategories()->getAll('read', 'select')
        );
    }

    /**
     * Decide if an answering panel is show when rendering a posting
     *
     * @return void
     */
    protected function _showAnsweringPanel()
    {
        $showAnsweringPanel = false;

        if ($this->CurrentUser->isLoggedIn()) {
            // Only logged in users see the answering buttons if they …
            if (
                // … directly on entries/view (full page or inline)
                $this->request->getParam('action') === 'view'
                // … directly in entries/mix
                || $this->request->getParam('action') === 'mix'
            ) {
                $showAnsweringPanel = true;
            }
        }
        $this->set('showAnsweringPanel', $showAnsweringPanel);
    }

    /**
     * makes root posting of $posting avaiable in view
     *
     * @param BasicPostingInterface $posting posting for root entry
     * @return void
     */
    protected function _setRootEntry(BasicPostingInterface $posting): void
    {
        if (!$posting->isRoot()) {
            /** @var \App\Model\Entity\Entry root */
            $root = $this->Entries->find()
                ->select(['id', 'user_id', 'Users.user_type'])
                ->where(['Entries.id' => $posting->get('tid')])
                ->contain(['Users'])
                ->first();
            $root = $root->toPosting();
        } else {
            $root = $posting;
        }
        $this->set('rootEntry', $root);
    }
}
