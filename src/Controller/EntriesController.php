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
use Cake\Cache\Cache;
use Saito\Exception\SaitoForbiddenException;
use Saito\Posting\Basic\BasicPostingInterface;
use Saito\User\CurrentUser\CurrentUserInterface;
use Saito\User\Permission\ResourceAI;
use Saito\User\WidgetPreferences;
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
    /** @var int postings a member may write per window */
    private const POST_MAX_ATTEMPTS = 10;

    /** @var int the window in seconds */
    private const POST_THROTTLE_WINDOW = 300;

    /**
     * The front page's right-rail widgets, in the order they are rendered.
     *
     * The single list of what exists: the template renders from it, and the
     * stored per-member preference is filtered against it, so a widget that is
     * removed here simply stops being minimisable instead of lingering in
     * everybody's saved state.
     *
     * @var list<string>
     */
    public const WIDGETS = ['online', 'recent', 'mine'];

    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        parent::initialize();
        // `User` draws the author's profile link — used by the posting views and
        // by the editor preview, which reproduces the same info line.
        $this->viewBuilder()->addHelpers(['Posting', 'Text', 'User']);

        $this->loadComponent('Posting');
        $this->loadComponent('MarkAsRead');
        $this->loadComponent('Referer');
        $this->loadComponent('Threads', ['table' => $this->Entries]);
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

        // Island category filter (?category=3,7): restrict the list to the
        // chosen readable categories; 'all'/absent shows everything. Several at
        // once, as the retired chooser allowed — paginate() has always taken a
        // list and intersects it with what the member may read, so an unknown or
        // unreadable id simply drops out.
        $onlyCategories = null;
        $catParam = (string)($this->getRequest()->getQuery('category') ?? '');
        if ($catParam !== '' && $catParam !== 'all') {
            $ids = array_values(array_unique(array_filter(
                array_map('trim', explode(',', $catParam)),
                'ctype_digit'
            )));
            $onlyCategories = $ids === [] ? null : array_map('intval', $ids);
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
            // Only the categories the member actually wants to see: paginate()
            // filters on getCurrent(), so listing every readable one here would
            // offer choices that then show nothing.
            $catList = $this->CurrentUser->getCategories()->getCurrent('read');
            $titles = $this->CurrentUser->getCategories()->getAll('read', 'select');
            $catList = array_map(
                fn($id) => ['id' => $id, 'title' => $titles[$id] ?? (string)$id],
                array_keys($catList)
            );
            if (count($catList) > 1) {
                $this->set('categoryChooser', $catList);
                // A list, not a single id: the chooser ticks every active box.
                $this->set('activeCategories', $onlyCategories ?? []);
            }
        }

        // htmx swaps only the thread-list page fragment; a direct visit gets
        // the shell page in the standalone htmx_island layout.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $this->viewBuilder()
                ->disableAutoLayout()
                ->setTemplate('htmx_index_threads');
        } else {
            // The rail loads asynchronously, but its width decides the layout —
            // so the page has to know on first paint whether it is a full rail
            // or a strip of icons, or the thread list visibly jumps.
            $this->set('minimisedWidgets', $this->railArrangement()['minimised']);
            $this->set('widgetCatalogue', self::WIDGETS);
            $this->set('railVisible', $this->railVisible());
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
     * fragment the sidebar htmx-refreshes on a poll and after new posts.
     *
     * Public by default; an installation can keep it for members only, in which
     * case a guest gets an empty response instead of a rendered view — hence
     * the two possible returns.
     *
     * @return \Cake\Http\Response|void
     */
    public function htmxWidgets()
    {
        // A forum can keep the rail for members only. Enforced here and not just
        // by leaving the markup out: this endpoint answers on its own URL, so
        // hiding the rail while still serving the fragment would let anyone read
        // who is online by asking for it directly.
        if (!$this->railVisible()) {
            $this->viewBuilder()->disableAutoLayout();

            return $this->getResponse()->withStringBody('');
        }

        // Registry::get() always returns an object (it throws on a missing key),
        // so no null check is needed here.
        $stats = \Saito\App\Registry::get('AppStats');
        $this->set('online', $stats->getRegistredUsersOnline());
        $this->set('onlineCount', $stats->getNumberOfRegisteredUsersOnline());
        $this->set('guestCount', $stats->getNumberOfAnonUsersOnline());
        $this->set('botCount', $stats->getNumberOfBotsOnline());
        $this->set('recentEntries', $this->Entries->getRecentPostings($this->CurrentUser));
        if ($this->CurrentUser->isLoggedIn()) {
            $this->set('myPosts', $this->Entries->getRecentPostings(
                $this->CurrentUser,
                ['user_id' => $this->CurrentUser->getId(), 'limit' => 5]
            ));
        }
        // Rendered server-side rather than applied by script afterwards: the
        // rail would otherwise flash open on every load, and in the member's
        // default order, before a script folded and reshuffled it.
        $arrangement = $this->railArrangement();
        $this->set('minimisedWidgets', $arrangement['minimised']);
        $this->set('widgetOrder', $arrangement['order']);
        $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_widgets');
    }

    /**
     * Whether the widget rail is shown to the current viewer.
     *
     * Members always see it. Guests see it unless the installation has turned
     * `Saito.widgetsForGuests` off — absent configuration means "show", so an
     * installation that predates the setting is unaffected.
     *
     * @return bool
     */
    protected function railVisible(): bool
    {
        return $this->CurrentUser->isLoggedIn()
            || Configure::read('Saito.widgetsForGuests') !== false;
    }

    /**
     * How the current member arranged the rail: order, and what is minimised.
     *
     * Signed-in members have this on their account (see WidgetPreferences);
     * for everyone else the island falls back to the browser's own storage,
     * so the arrangement still survives a reload without an account.
     *
     * @return array{order: list<string>, minimised: list<string>}
     */
    protected function railArrangement(): array
    {
        if (!$this->CurrentUser->isLoggedIn()) {
            return ['order' => self::WIDGETS, 'minimised' => []];
        }

        return WidgetPreferences::read(
            $this->CurrentUser->get('slidetab_order'),
            self::WIDGETS
        );
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
        // view_posting needs the thread root and the answering flag.
        $this->_setRootEntry($postings);
        $this->_showAnsweringPanel();
        $this->set('titleForLayout', $postings->get('subject'));

        // Same side effects as the SPA mix() view: bump the view counter and
        // mark the whole thread read for the current user.
        $this->Threads->incrementViewsForThread($postings, $this->CurrentUser);
        $this->MarkAsRead->thread($postings);

        // The mix button expands a thread in place (see the island bundle), which
        // needs the postings without the surrounding page. `?view=tree` asks for
        // the same thread as its subject lines instead — what the front page
        // shows, and what a thread has to look like again after a reply.
        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            $template = $this->getRequest()->getQuery('view') === 'tree'
                ? 'htmx_thread_tree'
                : 'htmx_thread_fragment';
            $this->viewBuilder()->disableAutoLayout()->setTemplate($template);
        } else {
            $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_thread');
        }
    }

    /**
     * A single posting with the thread it belongs to, as an htmx island page.
     *
     * The counterpart to the SPA's {@see view()}: the posting in full at the
     * top, the thread's tree of subject lines below it, so one can read a
     * posting and still see where it sits in the conversation. That combination
     * had no island equivalent — the front page opens postings inline, and
     * htmxThread shows the whole thread flattened, but neither serves a
     * deep-link to one posting.
     *
     * An `HX-Request` returns just the posting fragment, which is what the
     * island uses to open a posting inline.
     *
     * @param string $id posting-ID
     * @return \Cake\Http\Response|void
     */
    public function htmxPosting(string $id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            throw new BadRequestException();
        }

        $entry = $this->Entries->get($id);
        $posting = $entry->toPosting()->withCurrentUser($this->CurrentUser);

        if (!$this->CurrentUser->getCategories()->permission('read', $posting->get('category'))) {
            return $this->_requireAuth();
        }

        $this->set('entry', $posting);
        $this->Threads->incrementViewsForPosting($posting, $this->CurrentUser);
        // view_posting needs the thread root and the answering flag.
        $this->_setRootEntry($posting);
        $this->_showAnsweringPanel();
        $this->MarkAsRead->posting($posting);

        if ($this->getRequest()->getHeaderLine('HX-Request') === 'true') {
            // Ohne dies käme das Element im vollen Layout zurück — die SPA kam
            // damit durch, weil sie den ajax-Layout-Umschalter benutzte.
            $this->viewBuilder()->disableAutoLayout();

            return $this->render('/element/entry/view_posting');
        }

        $this->set(
            'tree',
            $this->Entries->postingsForThread($posting->get('tid'), false, $this->CurrentUser)
        );
        // Subject *and* category, the way the retired single-posting page did
        // it: the title is what a browser tab and a search result show, and
        // "Re: that thing" without its category says very little.
        $this->Title->setFromPosting($posting);
        $this->viewBuilder()->setLayout('htmx_island')->setTemplate('htmx_posting');
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
    /**
     * Whether the member has written too many postings too fast.
     *
     * The forum throttles login, registration and the contact form; posting was
     * the one write path left open. It is cheaper to abuse than any of those — a
     * script needs one confirmed account, then a `create()` per request — and
     * each write invalidates the thread cache, so the cost is not only rows.
     *
     * Keyed on the member id, not the client IP: posting already requires an
     * account, and several members behind one connection (a university, a mobile
     * network) must not throttle each other. Moderators and above are exempt —
     * they answer and clean up in bursts, and the limit is aimed at a script.
     *
     * @return bool
     */
    private function isPostThrottled(): bool
    {
        if ($this->CurrentUser->permission('saito.core.posting.unthrottled')) {
            return false;
        }

        $key = 'post-throttle-' . $this->CurrentUser->getId();
        $record = Cache::read($key);
        if (!is_array($record) || (time() - $record['first']) >= self::POST_THROTTLE_WINDOW) {
            $record = ['count' => 0, 'first' => time()];
        }
        $record['count']++;
        Cache::write($key, $record);

        return $record['count'] > self::POST_MAX_ATTEMPTS;
    }

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
                // Offered when starting a thread only. A reply is its author's
                // own posting and gets marked on its own merits — the same call
                // Saito 4 made, and for the same reason.
                'nsfw' => (bool)$this->getRequest()->getData('nsfw'),
            ];
            if ($this->isPostThrottled()) {
                $posting = null;
                $this->Flash->set(__('entry.post.throttled'), ['element' => 'error']);
            } else {
                try {
                    $posting = $this->Posting->create($data, $this->CurrentUser);
                } catch (SaitoForbiddenException $e) {
                    $posting = null;
                }
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
                // The toggle is in the toolbar, so it is submitted with every
                // edit — including an edit that clears it, which is why it is
                // read unconditionally rather than only when set.
                'nsfw' => (bool)$this->getRequest()->getData('nsfw'),
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

        /** @var \App\Model\Entity\Entry|null $entry */
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
        $request = $this->getRequest();
        $this->set('previewText', (string)$request->getData('text'));
        $this->set('previewSubject', (string)$request->getData('subject'));

        // The preview shows the posting the way the forum will: heading, the
        // author/category line, then the text. Author and time are the ones it
        // would actually get, so what is shown is not a mock-up of the layout
        // but the posting itself, one step early.
        //
        // The whole user entity, not just the name: the info line links the
        // author to their profile exactly as a real posting does, and the
        // helper that draws that link needs the record, not a string.
        $this->set('previewAuthor', $this->fetchTable('Users')->get((int)$this->CurrentUser->getId()));

        // The category is whatever the form knows — the parent's for a reply,
        // the chooser's for a new thread. Absent is fine: the line simply drops
        // that part rather than inventing one.
        $categoryId = (int)$request->getData('categoryId');
        $category = null;
        if ($categoryId > 0) {
            $found = $this->Entries->Categories->find()
                ->where(['id' => $categoryId])
                ->first();
            // Only categories the member may read — the preview must not become
            // a way to learn the name of a category they cannot see.
            $readable = $this->CurrentUser->getCategories()->getAll('read');
            if ($found !== null && in_array($categoryId, $readable, true)) {
                $category = $found;
            }
        }
        // Views: nought for a posting that does not exist yet, the real count
        // when an existing one is being edited. The form says which.
        $this->set('previewViews', (int)$request->getData('views'));

        $this->set('previewCategory', $category);

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
     * An upload archive for the editor upload overlay — a page of thumbnail
     * tiles (20 per page, newest first) plus a "load more" control.
     * Session-based (the REST uploads API is token-auth). Login required.
     *
     * Defaults to the current user's own uploads. `?id=` asks for somebody
     * else's and is checked against `saito.plugin.uploader.view`, which grants
     * admins exactly that — the permission has been declared all along, but this
     * action hard-coded the current user, so the admin half of it had no way to
     * be exercised outside the token-authed REST controller.
     *
     * @return void
     */
    public function htmxUploads()
    {
        $userId = $this->CurrentUser->getId();
        if (!$userId) {
            throw new BadRequestException();
        }

        $requested = (int)$this->getRequest()->getQuery('id');
        if ($requested > 0 && $requested !== (int)$userId) {
            /** @var \App\Model\Entity\User $owner */
            $owner = $this->fetchTable('Users')->get($requested);
            $allowed = $this->CurrentUser->permission(
                'saito.plugin.uploader.view',
                (new ResourceAI())->onRole($owner->getRole())->onOwner($owner->getId()),
            );
            if (!$allowed) {
                throw new SaitoForbiddenException(
                    sprintf('Attempt to index uploads of "%s".', $requested),
                    ['CurrentUser' => $this->CurrentUser]
                );
            }
            $userId = $requested;
        }

        // 60, not 20. The tiles are a grid, and twenty of them do not fill one
        // screen — a member with 493 uploads had to press "load more" 24 times
        // to see their own archive, which is what prompted this. The images load
        // lazily, so the extra rows cost markup and nothing else.
        $perPage = 60;
        $page = max(1, (int)$this->getRequest()->getQuery('page'));

        $Uploads = $this->fetchTable('ImageUploader.Uploads');
        $query = $Uploads->find()->where(['user_id' => $userId])->orderBy(['id' => 'DESC']);
        $total = $query->count();
        $uploads = $query->limit($perPage)->offset(($page - 1) * $perPage)->all();

        $this->set('uploads', $uploads);
        $this->set('page', $page);
        $this->set('total', $total);
        $this->set('hasMore', ($page * $perPage) < $total);
        // Only set when looking at somebody else's archive, so "load more" keeps
        // asking about the same member instead of falling back to the admin's own.
        $this->set('ownerId', $requested > 0 && $requested !== (int)$this->CurrentUser->getId() ? $userId : null);
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

        // Deleting an upload that is still embedded leaves a dangling [img] in
        // old postings. When something references it, answer the first request
        // with a warning instead of deleting; the caller re-posts with
        // `confirm=1` to go ahead. Nothing embedding it → straight through.
        if ($this->getRequest()->getData('confirm') !== '1') {
            $usageCount = $this->findPostingsEmbeddingUpload($upload)->count();
            if ($usageCount > 0) {
                $this->set('uploadId', $id);
                $this->set('usageCount', $usageCount);
                $this->set('ownerId', (int)$upload->user->getId());
                $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_upload_delete_confirm');

                return null;
            }
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
     * List the postings that embed a given upload (issue #64).
     *
     * So a member can see where an image is used before deleting it: a deleted
     * upload that is still embedded leaves a dangling `[img]` in old postings,
     * with nothing warning them first.
     *
     * An upload is referenced in a posting as `[img src=upload]<name>[/img]`
     * (see `uploads.ts`), so its filename appears verbatim in `entries.text`.
     * The name is matched literally — every upload name carries underscores,
     * which are LIKE wildcards unless escaped. Scoped to the upload's owner: a
     * member embeds their own uploads in their own postings, and this keeps the
     * scan to one member's rows. `LIKE '%…%'` cannot use an index, so this runs
     * per-upload and on demand, never in bulk.
     *
     * @param string|null $id upload id
     * @return void
     */
    public function htmxUploadUsage($id = null): void
    {
        $id = (int)$id;
        if ($id <= 0) {
            throw new BadRequestException();
        }

        $Uploads = $this->fetchTable('ImageUploader.Uploads');
        /** @var \ImageUploader\Model\Entity\Upload $upload */
        $upload = $Uploads->get($id, contain: ['Users']);

        // Same right as viewing that member's uploads at all.
        $allowed = $this->CurrentUser->permission(
            'saito.plugin.uploader.view',
            (new ResourceAI())->onRole($upload->user->getRole())->onOwner($upload->user->getId()),
        );
        if (!$allowed) {
            throw new SaitoForbiddenException(
                sprintf('Attempt to list usage of upload "%s".', $id),
                ['CurrentUser' => $this->CurrentUser],
            );
        }

        $postings = $this->findPostingsEmbeddingUpload($upload)
            ->select(['id', 'subject', 'time'])
            ->orderBy(['Entries.time' => 'DESC'])
            ->limit(50)
            ->all();

        $this->set('postings', $postings);
        $this->viewBuilder()->disableAutoLayout()->setTemplate('htmx_upload_usage');
    }

    /**
     * Postings by an upload's owner whose text embeds it.
     *
     * The upload appears in a posting as `[img src=upload]<name>[/img]`, so the
     * filename is matched in `entries.text` with LIKE — its underscores escaped
     * so they stay literal rather than single-character wildcards — scoped to
     * the owner's rows. Shared by the usage listing and the delete guard.
     *
     * @param \ImageUploader\Model\Entity\Upload $upload the upload
     * @return \Cake\ORM\Query\SelectQuery
     */
    private function findPostingsEmbeddingUpload(
        \ImageUploader\Model\Entity\Upload $upload,
    ): \Cake\ORM\Query\SelectQuery {
        $pattern = '%' . addcslashes((string)$upload->get('name'), '\\%_') . '%';

        return $this->Entries->find()
            ->where([
                'Entries.user_id' => (int)$upload->user->getId(),
                'Entries.text LIKE' => $pattern,
            ]);
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
        // For the preview: a reply inherits its parent's category, and there is
        // no chooser in this form to read it from.
        $this->set('parentCategoryId', (int)$parent->get('category_id'));
        $this->set('replySubject', $this->replySubject((string)$parent->get('subject')));

        if ($parentPosting->isAnsweringForbidden()) {
            $this->set('forbidden', true);
            $this->viewBuilder()->setTemplate('htmx_reply_form');

            return;
        }

        if ($this->getRequest()->is('post')) {
            // An empty subject means "the one the placeholder offered". The form
            // shows it in pale text rather than filling it in, so nothing is
            // submitted when the writer leaves it alone — and without this the
            // posting would get the parent's subject *without* the "Re:" the
            // field had just promised.
            $typedSubject = trim((string)$this->getRequest()->getData('subject'));
            $subject = $typedSubject !== ''
                ? $typedSubject
                : $this->replySubject((string)$parent->get('subject'));

            $data = [
                'pid' => $parent->get('id'),
                'subject' => $subject,
                'text' => (string)$this->getRequest()->getData('text'),
                // Required by validation and set the same way as the REST add().
                'name' => $this->CurrentUser->get('username'),
                'user_id' => $this->CurrentUser->getId(),
                // An answer is its own posting and can be marked on its own.
                // Nothing is inherited from the thread it hangs under — that
                // part of Saito 4's rule still holds.
                'nsfw' => (bool)$this->getRequest()->getData('nsfw'),
            ];
            if ($this->isPostThrottled()) {
                $posting = null;
                $this->Flash->set(__('entry.post.throttled'), ['element' => 'error']);
            } else {
                try {
                    $posting = $this->Posting->create($data, $this->CurrentUser);
                } catch (SaitoForbiddenException $e) {
                    $posting = null;
                }
            }

            if ($posting !== null && !$posting->getErrors()) {
                $this->set('posting', $posting);
                $this->viewBuilder()->setTemplate('htmx_reply_done');

                return;
            }

            $this->set('errors', $posting !== null ? $posting->getErrors() : []);
            // What the writer typed, not what was made of it. `$data` carries
            // the filled-in subject because that is what gets saved; putting the
            // same thing back into the form would turn the pale placeholder into
            // a real value the writer now has to delete before typing their own
            // — and it would stay there while they type, which is exactly what a
            // placeholder must not do.
            $this->set('submitted', ['subject' => $typedSubject] + $data);
        }

        if (!$this->getRequest()->is('post')) {
            $this->set('draft', $this->draftFor((int)$parent->get('id')));
        }

        $this->viewBuilder()->setTemplate('htmx_reply_form');
    }

    /**
     * load front page force all entries mark-as-read
     *
     * @return \Cake\Http\Response|void an empty 204 for the island, else redirect
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

        return $this->redirect('/entries/index');
    }

    /**
     * Delete posting
     *
     * @param string $id posting-ID
     * @return \Cake\Http\Response|null
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
                $redirect = '/entries/htmx-thread/' . $posting->get('pid');
            }
        } else {
            $flashType = 'error';
            $message = __('delete_tree_error');
            $redirect = $this->referer();
        }
        $this->Flash->set($message, ['element' => $flashType]);

        return $this->redirect($redirect);
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
            // get() raises RecordNotFoundException for an unknown id; it never
            // returns an empty value, so the check that used to stand here could
            // not fire.
            $posting = $this->Entries->get($id);

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
        } catch (SaitoForbiddenException $e) {
            // Let it through: it is a 403 and it logs who tried what. Turning it
            // into an anonymous 400 threw that away, so a refused attempt read
            // like a malformed request.
            throw $e;
        } catch (\Exception $e) {
            // Keep the cause rather than discarding it — a failure here used to
            // be indistinguishable from a typo in the id.
            throw new BadRequestException(null, null, $e);
        }
    }

    /**
     * The current member's saved draft for one parent posting, if any.
     *
     * Handed to the form as `draft`, which fills the fields when nothing was
     * submitted. A rejected submission must win over it — what the writer just
     * typed is newer than what was stored seconds ago — so the caller only asks
     * for this on a GET.
     *
     * @param int $pid parent posting id, 0 for a new thread
     * @return array{subject: string, text: string}|null
     */
    private function draftFor(int $pid): ?array
    {
        $userId = $this->CurrentUser->getId();
        if (!$userId) {
            return null;
        }
        /** @var \Cake\Datasource\EntityInterface|null $draft */
        $draft = $this->fetchTable('Drafts')->find()
            ->where(['pid' => $pid, 'user_id' => $userId])
            ->first();
        if ($draft === null) {
            return null;
        }

        return [
            'subject' => (string)$draft->get('subject'),
            'text' => (string)$draft->get('text'),
        ];
    }

    /**
     * Keep what is being written, so closing the tab does not lose it.
     *
     * Posted by the editor a few seconds after typing stops. There is one draft
     * per (parent posting, member) — `pid = 0` for a new thread — which the
     * storage layer enforces with a uniqueness rule, so this is an upsert rather
     * than a log of versions.
     *
     * Submitting nothing at all removes the draft. That is the discard button and
     * also what happens when the writer empties the box themselves: the table
     * refuses a draft with neither subject nor text, and keeping an empty row to
     * restore from would offer the writer their own blank page back.
     *
     * The whole storage layer for this — table, validation, uniqueness rule, the
     * daily garbage collection, the clean-up after a posting is saved — has been
     * in the code since Saito 5 with nothing to write to it. This is the writer
     * that was missing.
     *
     * @return \Cake\Http\Response
     */
    public function htmxDraft(): Response
    {
        $this->request->allowMethod(['post']);
        $this->autoRender = false;

        $userId = $this->CurrentUser->getId();
        if (!$userId) {
            throw new BadRequestException();
        }

        $pid = (int)$this->getRequest()->getData('pid');
        $subject = trim((string)$this->getRequest()->getData('subject'));
        $text = trim((string)$this->getRequest()->getData('text'));

        $Drafts = $this->fetchTable('Drafts');
        /** @var \Cake\Datasource\EntityInterface|null $draft */
        $draft = $Drafts->find()
            ->where(['pid' => $pid, 'user_id' => $userId])
            ->first();

        if ($subject === '' && $text === '') {
            if ($draft !== null) {
                $Drafts->delete($draft);
            }

            return $this->response->withStatus(204);
        }

        $data = ['subject' => $subject, 'text' => $text];
        if ($draft === null) {
            $draft = $Drafts->newEntity($data + ['pid' => $pid, 'user_id' => $userId]);
        } else {
            $Drafts->patchEntity($draft, $data);
        }

        // A failure is not worth telling the writer about: they did not ask for
        // this to happen, and the text is still in front of them. The subject is
        // the one thing that can be refused — it has a maximum length — and the
        // editor caps it at the same number anyway.
        $Drafts->save($draft);

        return $this->response->withStatus(204);
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
        // POST only: this toggles state, so never accept it on a GET (`'ajax'`
        // used to let a GET with X-Requested-With through, which CSRF
        // middleware does not validate).
        $this->request->allowMethod(['post']);
        $this->autoRender = false;
        $entryId = (int)$id;
        $userId = $this->CurrentUser->getId();
        if (!$entryId || !$userId) {
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
     * Toggle posting property via ajax request.
     *
     * @param string $id posting-ID
     * @param string $toggle property
     *
     * @return \Cake\Http\Response
     */
    public function ajaxToggle($id = null, $toggle = null)
    {
        // POST only. The ajax check below is a header test, not a token — it
        // happens to keep a cross-origin request out because no <img> or <form>
        // can set the header, but that is a side effect. CSRF protection only
        // looks at POST/PUT/PATCH/DELETE, so this action was outside it.
        $this->request->allowMethod(['post']);

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
        // Pinning/locking is authorized via authorizeAction('ajaxToggle',
        // 'saito.core.posting.pinAndLock') above, so set the field directly.
        // Going through PostingComponent::update() would also require edit
        // permission (isEditingAllowed), which would wrongly block a moderator
        // from pinning/locking threads they may not edit.
        //
        // setPostingState() rather than updateEntry(): `locked` and `fixed` are
        // not assignable from an array (Entry::$_accessible), so that no other
        // write path can set them while it is doing something else.
        $this->Entries->setPostingState($posting, $toggle, !$posting->get($toggle));

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
            // ajaxToggle is pin/lock. It is posted by the island with a CSRF
            // token in the header and no form behind it, so FormProtection had
            // nothing to validate and blackholed every attempt — pinning and
            // unpinning a thread simply did nothing, silently, with the failure
            // visible only in the server log.
            ['solve', 'ajaxToggle', 'htmxPosting', 'htmxReply', 'htmxAdd', 'htmxPreview', 'htmxUpload',
                'htmxBookmark', 'htmxEdit', 'htmxMerge', 'htmxUploadDelete', 'htmxDraft']
        );
        $this->Authentication->allowUnauthenticated(
            ['update', 'htmxIndex', 'htmxNewCount', 'htmxThread', 'htmxPosting', 'htmxWidgets']
        );

        $this->AuthUser->authorizeAction('ajaxToggle', 'saito.core.posting.pinAndLock');
        $this->AuthUser->authorizeAction('htmxMerge', 'saito.core.posting.merge');
        $this->AuthUser->authorizeAction('delete', 'saito.core.posting.delete');

        Stopwatch::stop('Entries->beforeFilter()');
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
            // Only logged-in users see the answering buttons, and only where a
            // posting is shown in full — not in a list of subject lines.
            //
            // This list is the only place carrying the rule. It used to exist
            // twice — htmxThread set the flag itself — and when htmxPosting was
            // added only one copy was updated, so the reply button vanished
            // from the inline view.
            $showAnsweringPanel = in_array(
                $this->request->getParam('action'),
                ['htmxPosting', 'htmxThread'],
                true
            );
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

    /**
     * What a reply's subject field starts out holding.
     *
     * The parent's subject with "Re:" in front, which is what the writer would
     * have typed anyway. Leaving it empty is still allowed and still works —
     * `PostingComponent::prepareChildPosting()` then takes the parent's subject
     * verbatim — but an empty box gave no hint that this was so.
     *
     * The prefix does not stack. Answering the third posting in a thread should
     * read "Re: Question" and not "Re: Re: Re: Question", so a subject that
     * already carries one is left as it is. The prefix itself is translatable
     * because not every language writes it the same way.
     *
     * @param string $parentSubject the subject being replied to
     * @return string the subject to offer, empty when the parent has none
     */
    private function replySubject(string $parentSubject): string
    {
        $subject = trim($parentSubject);
        if ($subject === '') {
            return '';
        }

        $prefix = trim((string)__('reply.subject.prefix'));
        // Case-insensitive, and tolerant of the space being there or not — the
        // subject may have come from a mail gateway or another forum.
        if ($prefix !== '' && preg_match('/^' . preg_quote($prefix, '/') . '\s*/iu', $subject)) {
            return $subject;
        }

        return $prefix === '' ? $subject : $prefix . ' ' . $subject;
    }
}
