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

use ReflectionClass;
use ReflectionMethod;
use Saito\Test\SaitoTestCase;

/**
 * Authorization coverage tripwire.
 *
 * Saito's authorization is opt-in: AuthUserComponent::isAuthorized() allows any
 * non-admin action that has no registered authorizeAction() to every logged-in
 * user. That is fine for the many actions intentionally open to members, but it
 * means a NEW state-changing action that forgets its gate is silently exposed.
 *
 * This test enumerates every controller action and classifies how it is
 * authorized:
 *  - admin            — under the Admin plugin or an `admin` prefix (isAuthorized
 *                       requires saito.core.admin.backend);
 *  - api              — an Api-plugin (JWT) endpoint with its own checks;
 *  - public           — declared in allowUnauthenticated();
 *  - authorizeAction  — bound to a permission via AuthUser->authorizeAction();
 *  - inline           — the action body performs its own permission / ownership
 *                       check (permission(), SaitoForbidden, isEditingAllowed …).
 *
 * Anything left over is open to any member. Those are legitimate but must be
 * listed explicitly in MEMBER_OPEN with a reason. A new, unclassified
 * member-open action fails this test — forcing a conscious authorization
 * decision at review time rather than shipping an unguarded endpoint.
 *
 * Public actions are covered the same way via PUBLIC_OPEN, so opening an action
 * to the internet is a reviewed decision too, not just an allowUnauthenticated()
 * call somewhere in beforeFilter().
 *
 * KNOWN LIMIT — `inline` is pattern-based: it only proves the body *mentions* a
 * guard, not that the guard gates access. UsersController::htmxProfile is the
 * standing example — its permission() call merely decides whether the blocking
 * controls are rendered, so the action counts as `inline` although the profile
 * itself is a plain member-open read. Read an `inline` verdict as "a human
 * should have checked this", not as a guarantee.
 */
class AuthorizationCoverageTest extends SaitoTestCase
{
    /** @var string guard patterns that mark an action as checking permission itself */
    private const INLINE_GUARD =
        '/permission\(|SaitoForbidden|isEditingAllowed|isAnsweringAllowed|onOwner|->isUser\(/';

    /**
     * Actions intentionally open to any logged-in member (self-scoped writes or
     * plain reads/renders). Key: "<Controller>::<action>", value: the reason.
     *
     * @var array<string, string>
     */
    private const MEMBER_OPEN = [
        'App\\Controller\\StatusController::status' => 'read-only forum status for a logged-in member',
        'App\\Controller\\UsersController::name' => 'view a public user profile by name (read)',
        'App\\Controller\\UsersController::ignore' => 'adds to the current user\'s own ignore list (self-scoped)',
        'App\\Controller\\UsersController::unignore' => 'removes from the current user\'s own ignore list (self-scoped)',
        // htmx island counterparts of the above — same exposure as the classic
        // action each one replaces (verified self-scoped or plain read).
        'App\\Controller\\UsersController::htmxUsers' => 'member list in the island (read; same as index)',
        'App\\Controller\\UsersController::recentPosts' => 'a user\'s recent postings, category-filtered for the reader (read)',
        'App\\Controller\\UsersController::bookmarks' => 'renders the current user\'s own bookmarks (self-scoped)',
        'App\\Controller\\UsersController::htmxWidgetState' => 'stores the current user\'s own widget-rail arrangement (self-scoped)',
        'App\\Controller\\UsersController::htmxChangePassword' => 'changes the current user\'s own password, requires password_old (self-scoped)',
        'App\\Controller\\EntriesController::htmxBookmark' => 'toggles the current user\'s own bookmark (self-scoped)',
        'App\\Controller\\EntriesController::htmxUploads' => 'lists the current user\'s own uploads (self-scoped)',
        'App\\Controller\\EntriesController::htmxPreview' => 'renders a BBCode preview of the text the member just submitted (no data access)',
    ];

    /**
     * Actions intentionally reachable without login (declared in
     * allowUnauthenticated). Key: "<Controller>::<action>", value: the reason.
     *
     * Public exposure is the riskier direction — an action added to
     * allowUnauthenticated by mistake is open to the whole internet — so it must
     * be a conscious, reviewed decision here too, not just a call somewhere in
     * beforeFilter().
     *
     * @var array<string, string>
     */
    private const PUBLIC_OPEN = [
        // Reading the forum: content itself is filtered by the reader's
        // category read-permission (guests see public categories only).
        'App\\Controller\\EntriesController::htmxIndex' => 'island front page (read; category-filtered)',
        'App\\Controller\\EntriesController::htmxThread' => 'island thread view (read; category-filtered)',
        'App\\Controller\\EntriesController::htmxPosting' => 'island single posting + its thread (read; category-filtered)',
        'App\\Controller\\EntriesController::htmxWidgets' => 'island sidebar widgets (read; category-filtered)',
        'App\\Controller\\EntriesController::htmxNewCount' => 'polls the number of new postings (read; category-filtered)',
        'App\\Controller\\EntriesController::update' => 'sets the visitor\'s own last-refresh marker (self-scoped, session only)',
        // Authentication / registration flows must be reachable before login.
        'App\\Controller\\UsersController::login' => 'login form + submit',
        'App\\Controller\\UsersController::logout' => 'logout',
        'App\\Controller\\UsersController::rs' => 'account activation via the emailed code',
        'App\\Controller\\UsersController::htmxLogin' => 'island login form',
        'App\\Controller\\UsersController::htmxRegister' => 'island registration form',
        // Public search + contact + static content.
        'SaitoSearch\\Controller\\SearchesController::htmxSimple' => 'island fulltext search (results category-filtered)',
        'App\\Controller\\ContactsController::htmxContactOwner' => 'island contact the operator (honeypot + timing guard)',
        'App\\Controller\\PagesController::display' => 'static pages (imprint, help …)',
        // Feeds / sitemap are public by design.
        'Feeds\\Controller\\PostingsController::new' => 'public RSS feed of new postings (category-filtered)',
        'Feeds\\Controller\\PostingsController::threads' => 'public RSS feed of new threads (category-filtered)',
        'Sitemap\\Controller\\SitemapsController::index' => 'sitemap index for search engines',
        'Sitemap\\Controller\\SitemapsController::file' => 'sitemap file for search engines',
        'SaitoHelp\\Controller\\SaitoHelpsController::index' => 'help pages',
        'SaitoHelp\\Controller\\SaitoHelpsController::view' => 'help page',
        'SaitoHelp\\Controller\\SaitoHelpsController::languageRedirect' => 'redirects to the help page in the visitor\'s language',
        'SaitoHelp\\Controller\\SaitoHelpsController::tour' => 'the help overlay\'s content: the tour and the topic list',
    ];

    /**
     * Controllers whose authorization is NOT the AuthUserComponent model and so
     * are out of scope here. The Installer/Updater extend the raw CakePHP
     * controller (no auth component at all) and are gated by the file-based
     * InstallerState machine plus the db_version check — verified separately.
     *
     * @var string[] namespace fragments
     */
    private const EXCLUDED_NAMESPACES = [
        'Installer\\Controller\\',
    ];

    /** @var string[] framework/lifecycle methods that are not actions */
    private const NON_ACTIONS = [
        'initialize', 'beforeFilter', 'beforeRender', 'afterFilter',
        'beforeRedirect', 'startup', 'render', 'blackhole', 'invokeAction',
    ];

    public function testEveryActionHasAnAuthorizationDecision(): void
    {
        $unclassified = [];
        foreach ($this->discoverActions() as $key => [$class, $action, $file]) {
            if (
                $this->classify($class, $action, $file) === 'member-open'
                && !isset(self::MEMBER_OPEN[$key])
            ) {
                $unclassified[] = $key;
            }
        }
        sort($unclassified);

        $this->assertSame(
            [],
            $unclassified,
            "These actions are open to any logged-in member but are not classified.\n"
            . "Add a permission/authorizeAction gate, or, if the exposure is intended,\n"
            . "add the action to MEMBER_OPEN with a reason:\n  " . implode("\n  ", $unclassified),
        );
    }

    /**
     * Keep the allowlist honest: every MEMBER_OPEN entry must still be a real,
     * still-member-open action. A stale entry (action gained a gate, was renamed
     * or removed) must be pruned so the allowlist cannot hide a regression.
     */
    /**
     * Every publicly reachable action must be declared in PUBLIC_OPEN.
     *
     * The member-open tripwire above only asks "is this open to any member?" —
     * it accepts `allowUnauthenticated` as a valid classification without
     * questioning it. So an action wrongly added to allowUnauthenticated (open
     * to the internet, the worse mistake) would pass unnoticed. This closes
     * that gap: adding a public action forces a reviewed reason here.
     *
     * @return void
     */
    public function testEveryPublicActionIsDeclared(): void
    {
        $undeclared = [];
        foreach ($this->discoverActions() as $key => [$class, $action, $file]) {
            if (
                $this->classify($class, $action, $file) === 'public'
                && !isset(self::PUBLIC_OPEN[$key])
            ) {
                $undeclared[] = $key;
            }
        }
        sort($undeclared);

        $this->assertSame(
            [],
            $undeclared,
            "These actions are reachable WITHOUT login but are not declared.\n"
            . "If that is intended, add them to PUBLIC_OPEN with a reason;\n"
            . "otherwise remove them from allowUnauthenticated():\n  "
            . implode("\n  ", $undeclared),
        );
    }

    /**
     * Keep the public allowlist honest: no entries for actions that are no
     * longer public (removed from allowUnauthenticated, renamed or deleted).
     *
     * @return void
     */
    public function testPublicAllowlistHasNoStaleEntries(): void
    {
        $actual = [];
        foreach ($this->discoverActions() as $key => [$class, $action, $file]) {
            if ($this->classify($class, $action, $file) === 'public') {
                $actual[$key] = true;
            }
        }

        $stale = array_values(array_diff(array_keys(self::PUBLIC_OPEN), array_keys($actual)));
        sort($stale);

        $this->assertSame(
            [],
            $stale,
            "PUBLIC_OPEN lists actions that are no longer public (gated, renamed\n"
            . "or removed). Remove them:\n  " . implode("\n  ", $stale),
        );
    }

    public function testMemberOpenAllowlistHasNoStaleEntries(): void
    {
        $actual = [];
        foreach ($this->discoverActions() as $key => [$class, $action, $file]) {
            if ($this->classify($class, $action, $file) === 'member-open') {
                $actual[$key] = true;
            }
        }

        $stale = array_values(array_diff(array_keys(self::MEMBER_OPEN), array_keys($actual)));
        sort($stale);

        $this->assertSame(
            [],
            $stale,
            "MEMBER_OPEN lists actions that are no longer member-open (gated, renamed\n"
            . "or removed). Remove them:\n  " . implode("\n  ", $stale),
        );
    }

    /**
     * Discover every controller action in the app and plugins.
     *
     * @return array<string, array{0: string, 1: string, 2: string}> key => [class, action, file]
     */
    private function discoverActions(): array
    {
        $files = array_merge(
            (array)glob(APP . 'Controller' . DS . '*Controller.php'),
            (array)glob(ROOT . DS . 'plugins' . DS . '*' . DS . 'src' . DS . 'Controller' . DS . '*Controller.php'),
            (array)glob(ROOT . DS . 'plugins' . DS . '*' . DS . 'src' . DS . 'Controller' . DS . '*' . DS . '*Controller.php'),
        );

        $actions = [];
        foreach ($files as $file) {
            $src = file_get_contents($file);
            if (!preg_match('/namespace\s+([^;]+);/', $src, $nm)) {
                continue;
            }
            if (!preg_match('/\bclass\s+(\w+Controller)\b/', $src, $cm)) {
                continue;
            }
            $short = $cm[1];
            $class = $nm[1] . '\\' . $short;

            if (str_ends_with($short, 'AppController') || !class_exists($class)) {
                continue;
            }
            foreach (self::EXCLUDED_NAMESPACES as $fragment) {
                if (str_contains($class . '\\', $fragment)) {
                    continue 2;
                }
            }
            $ref = new ReflectionClass($class);
            if ($ref->isAbstract()) {
                continue;
            }

            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Only actions declared on the controller itself.
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                $name = $method->getName();
                if (
                    $name[0] === '_'
                    || $method->isStatic()
                    || in_array($name, self::NON_ACTIONS, true)
                ) {
                    continue;
                }
                // Key by fully-qualified class: several controllers share a
                // short name (App\UsersController vs Admin\UsersController,
                // Sitemap vs Sitemap\Admin, Feeds vs Api Postings). A
                // short-name key let the later file silently shadow the earlier
                // one, so those actions were never audited at all.
                $actions[$class . '::' . $name] = [$class, $name, $file];
            }
        }

        return $actions;
    }

    /**
     * Classify how a single action is authorized.
     *
     * @param string $class fully-qualified controller class
     * @param string $action action name
     * @param string $file controller file path
     * @return string one of admin|api|public|authorizeAction|inline|member-open
     */
    /**
     * How an `allowUnauthenticated([...])` call is recognised.
     *
     * A constant rather than a literal inside classify(), so that
     * {@see testThePatternSurvivesReformatting} exercises the very same
     * expression. A meta-test with its own copy would happily keep passing while
     * the real one rotted.
     *
     * @var string
     */
    private const ALLOW_UNAUTH = '/allowUnauthenticated\(\s*\[([^\]]*)\]/s';

    private function classify(string $class, string $action, string $file): string
    {
        // admin plugin, or an `admin` route prefix (…\Controller\Admin\…)
        if (
            preg_match('/(^|\\\\)Admin\\\\Controller\\\\/', $class)
            || preg_match('/\\\\Controller\\\\Admin\\\\/', $class)
        ) {
            return 'admin';
        }
        if (is_subclass_of($class, 'Api\\Controller\\ApiAppController')) {
            return 'api';
        }

        $src = file_get_contents($file);

        // allowUnauthenticated([...])
        //
        // `\s*` between the paren and the bracket on purpose: without it a call
        // whose array is wrapped onto its own line stops matching, and every
        // action in it silently drops out of the `public` class — the test then
        // reports them as unclassified, or worse, as stale allowlist entries.
        // A tripwire that switches itself off when someone reformats the code
        // it watches is worse than no tripwire.
        preg_match_all(self::ALLOW_UNAUTH, $src, $ua);
        foreach ($ua[1] as $block) {
            if (preg_match("/'" . preg_quote($action, '/') . "'/", $block)) {
                return 'public';
            }
        }
        // authorizeAction('action', ...)
        if (preg_match("/authorizeAction\('" . preg_quote($action, '/') . "'/", $src)) {
            return 'authorizeAction';
        }

        // inline permission / ownership check inside the action body
        $lines = file($file);
        $body = $this->methodBody($class, $action, $lines);
        if (preg_match(self::INLINE_GUARD, $body)) {
            return 'inline';
        }

        // An action may delegate its guard to a non-public helper on the same
        // controller (e.g. advanced() and htmxAdvanced() both call the protected
        // prepareAdvancedSearch(), which raises SaitoForbiddenException). Follow
        // one hop into such helpers so a refactor that merely moves the check
        // does not read as an unguarded action. Deliberately narrow: only
        // non-public methods declared on this very controller — never other
        // actions or shared AppController helpers, which would blunt the
        // tripwire for every action.
        preg_match_all('/\$this->([a-zA-Z_]\w*)\s*\(/', $body, $calls);
        foreach (array_unique($calls[1]) as $callee) {
            if ($callee === $action || !method_exists($class, $callee)) {
                continue;
            }
            $ref = new ReflectionMethod($class, $callee);
            if ($ref->isPublic() || $ref->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if (preg_match(self::INLINE_GUARD, $this->methodBody($class, $callee, $lines))) {
                return 'inline';
            }
        }

        return 'member-open';
    }

    /**
     * Source of a method's body (for guard-pattern matching).
     *
     * @param string $class class name
     * @param string $method method name
     * @param string[] $lines the class file's lines
     * @return string
     */
    private function methodBody(string $class, string $method, array $lines): string
    {
        $ref = new ReflectionMethod($class, $method);

        return implode(
            '',
            array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1),
        );
    }

    /**
     * The tripwire watching itself.
     *
     * This test exists because the guard silently stopped guarding once: the
     * pattern matched `allowUnauthenticated([` literally, and when a call was
     * reformatted so that its array moved onto the next line, six public actions
     * quietly dropped out of the `public` class. Nothing failed. The `\s*` that
     * fixes it looks like a detail, and a later "tidy-up" could remove it again
     * with the whole suite still green.
     *
     * So: both formattings must be recognised. The pattern under test is the
     * constant the classifier itself uses, not a copy — a copy would drift.
     *
     * @return void
     */
    public function testThePatternSurvivesReformatting(): void
    {
        $einzeilig = "\$this->Authentication->allowUnauthenticated(['index', 'view']);";
        $mehrzeilig = "\$this->Authentication->allowUnauthenticated(\n"
            . "    ['index', 'view']\n"
            . ");";

        foreach (['on one line' => $einzeilig, 'wrapped' => $mehrzeilig] as $wie => $quelle) {
            $treffer = preg_match_all(self::ALLOW_UNAUTH, $quelle, $m);

            $this->assertSame(1, $treffer, "the call written $wie is not recognised");
            $this->assertStringContainsString(
                "'index'",
                $m[1][0],
                "the actions of the call written $wie were not captured"
            );
        }
    }

    /**
     * And the classifier must actually reject something it should. An action
     * that is neither declared nor guarded has to come out as `member-open`, so
     * that the two coverage tests above have something to fail on.
     *
     * Without this, a classify() that returned `inline` for everything would
     * make the whole tripwire pass forever.
     *
     * @return void
     */
    public function testAnUnguardedActionIsNotMistakenForAGuardedOne(): void
    {
        $klassifizieren = (new ReflectionClass(self::class))->getMethod('classify');

        // A real controller and a real action of it: htmxIndex is declared
        // public, so it must classify as `public` …
        $this->assertSame(
            'public',
            $klassifizieren->invoke(
                $this,
                'App\\Controller\\EntriesController',
                'htmxIndex',
                (new ReflectionClass('App\\Controller\\EntriesController'))->getFileName()
            )
        );

        // … while an action name that appears in no allowUnauthenticated() call
        // and has no guard of its own must not be waved through as public.
        $this->assertNotSame(
            'public',
            $klassifizieren->invoke(
                $this,
                'App\\Controller\\EntriesController',
                'delete',
                (new ReflectionClass('App\\Controller\\EntriesController'))->getFileName()
            )
        );
    }
}
