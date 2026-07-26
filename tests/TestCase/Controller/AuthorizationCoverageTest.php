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
        'EntriesController::add' => 'renders the new-posting form; the create itself is category-permission checked in PostingComponent',
        'EntriesController::e' => 'renders the inline edit-marker fragment',
        'EntriesController::source' => 'shows a posting the member may already read',
        'StatusController::status' => 'read-only forum status for a logged-in member',
        'UsersController::name' => 'view a public user profile by name (read)',
        'UsersController::view' => 'view a public user profile (read)',
        'UsersController::ignore' => 'adds to the current user\'s own ignore list (self-scoped)',
        'UsersController::unignore' => 'removes from the current user\'s own ignore list (self-scoped)',
        'UsersController::setcategory' => 'stores the current user\'s own category preference (self-scoped)',
        'UsersController::slidetabOrder' => 'stores the current user\'s own slidetab order (self-scoped)',
        'UsersController::uploads' => 'renders the current user\'s own uploads (self-scoped)',
        // htmx island counterparts of the above — same exposure as the classic
        // action each one replaces (verified self-scoped or plain read).
        'UsersController::htmxProfile' => 'view a public user profile in the island (read; same as view)',
        'UsersController::htmxUsers' => 'member list in the island (read; same as index)',
        'UsersController::recentPosts' => 'a user\'s recent postings, category-filtered for the reader (read)',
        'UsersController::bookmarks' => 'renders the current user\'s own bookmarks (self-scoped)',
        'UsersController::htmxChangePassword' => 'changes the current user\'s own password, requires password_old (self-scoped)',
        'EntriesController::htmxBookmark' => 'toggles the current user\'s own bookmark (self-scoped)',
        'EntriesController::htmxUploads' => 'lists the current user\'s own uploads (self-scoped)',
        'EntriesController::htmxPreview' => 'renders a BBCode preview of the text the member just submitted (no data access)',
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
                $actions[$short . '::' . $name] = [$class, $name, $file];
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
        preg_match_all('/allowUnauthenticated\(\[([^\]]*)\]/s', $src, $ua);
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
}
