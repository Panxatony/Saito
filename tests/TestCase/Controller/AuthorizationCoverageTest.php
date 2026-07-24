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
        $method = new ReflectionMethod($class, $action);
        $lines = file($file);
        $body = implode(
            '',
            array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1),
        );
        if (preg_match('/permission\(|SaitoForbidden|isEditingAllowed|isAnsweringAllowed|onOwner|->isUser\(/', $body)) {
            return 'inline';
        }

        return 'member-open';
    }
}
