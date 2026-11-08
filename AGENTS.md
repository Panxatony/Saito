# AGENTS.md — Working on Saito

Operating rules for people and AI agents working in this repository: what must
never happen, what needs explicit approval, how releases and deployments are
done, and the mistakes that have already been made once.

- **Maintainer / approvals:** the repository owner (GitHub `Panxatony`). Every
  approval below means an explicit instruction from them in the current
  conversation — a passing test run, a clean scan or "looks good" is not one.
- **Host infrastructure** (edge nginx, OS and jail updates, backups) is run from
  a separate operations repository by its own agent session, not from here.
- **Installation-specific operations** (hosts, addresses, paths, credentials
  locations, incident history) are in `AGENTS.local.md`, which is deliberately
  *not* committed — this repository is public. If it is missing on your
  machine, you do not have operations access; ask the maintainer.
- **As of:** 2026-11-08 (Saito 8.4.22 released; deployed: see `AGENTS.local.md`).

If this file and the actual state of the repository or a system disagree, the
actual state wins — check, do not assume, and correct this file.

@AGENTS.local.md

---

## 1. Binding rules

### Never

1. **Never commit the local `config/app.php`.** The tracked file is a template
   with placeholders; every working copy carries real database credentials and
   salts as an uncommitted modification. Stage explicit paths only — never
   `git add -A` / `git add .` — and check `git status` before each commit.
   Structural changes to the template go in their own commit via `git add -p`.
2. **Never replace `__SALT__` in the tracked `config/app.php` with a concrete
   value**, and give `Security.jwtSalt` no default. The installer generates the
   salt by searching for the placeholder, and the install check treats any
   other value as "configured". A concrete default disarms both silently, and
   the repository is public (fixed in 8.2.3 after exactly that happened).
3. **Never `git reset --hard`, switch branches or merge without first copying
   `config/app.php` aside** — its real values exist only in the working tree and
   are unrecoverable once lost. After a branch switch, verify it happened
   (`[ "$(git branch --show-current)" = main ]`) before anything destructive.
   `salt`, `cookieSalt` and `jwtSalt` must carry the *same* value locally.
4. **Never push without an explicit "push".** Committing is fine; publishing is
   a separate decision. Push to both remotes (`origin`, `github`); never to
   `upstream` (the original project, read-only).
5. **Never deploy a locally built package to production.** Production gets the
   artifact the release pipeline built from the tag (with its sha256). A release
   whose pipeline failed is not finished and cannot be deployed.
6. **Never extract a release tarball over an installation, and never copy a
   developer checkout's `vendor/composer/*`** onto a `--no-dev` installation
   (it `require`s dev packages → HTTP 500 on every page). Use `dev/deploy.sh`.
7. **Never overwrite an installation's `config/saito_config.php`, `config/app.php`
   or `config/.env`.** They are installation-specific; merge changes in by hand.
8. **Never put another installation's code, data or analysis into this
   repository.** Saito also runs at sites the maintainer does not operate; keep
   every change generic and configurable (`Configure` / `saito_config.php`), never
   tuned to one site.
9. **Never print or commit secrets** — no passwords, tokens, keys or `.env`
   values in output, commits, issues or docs. Do not pass secrets through chat.

### Needs explicit approval, every time

- Any change on production or beta (files, database, services, configuration).
- Cutting a release (version bump, tag) — also after green tests on test.
- Pushing.
- Deleting anything you did not create in this session.
- Filing issues in other projects: write a draft; the maintainer files it.

### Language

English for everything in the repository: code, comments, commit messages,
issues, pull requests, release notes, `docs/`, README and these files. German
only for the forum's UI translations (`src/Locale/de/`, `docs/help/de/`) and in
conversation with the maintainer.

### Communication

- Maintainer: in the conversation; bugs and features as GitHub issues
  (English).
- Host-level changes (edge nginx, jail base system, backups): send the request
  with the desired content and the reason to the operations session; see
  `AGENTS.local.md`. Read-only checks there need no request.

---

## 2. Security

**Where credentials live (paths only — never read them into output):**

| What | Where |
|---|---|
| Local development DB + salts | uncommitted working-tree changes in `config/app.php` |
| An installation's DB URL, salts, SMTP | `config/.env` of that installation (or the PHP-FPM pool's `env[]` on the test machine) |
| GitHub CLI token | `gh` configuration of the working account |

Production and beta locations are listed in `AGENTS.local.md`.

**Do not read or print:** any `.env`, PHP-FPM pool files with `env[]` lines,
private keys, certificate-renewal secret files. If a value is needed for a
command, source it in the same shell (`. config/.env`) and pass it via
environment (e.g. `MYSQL_PWD`), never on a command line or into a file outside
a 0600 scratch location that is deleted afterwards.

**Deliberate exceptions (not bugs — do not "fix"):**

- `/api/v2/*` is exempt from CSRF: it authenticates only by JWT bearer token
  from the `Authorization` header, so there is no ambient credential to forge.
  The five routes are live (bookmarks, uploads, upload thumbnails used by the
  editor). Ask `bin/cake routes`, never infer from `curl` on guessed paths.
- The recommended CSP keeps `'unsafe-eval'`: Alpine evaluates `x-data`
  expressions with `new Function`. `'unsafe-inline'` is not needed.
- A bot filter in front of the forum must let htmx requests (`HX-Request`
  header + `/htmx-` path) and static assets through, or readers see a
  challenge page inside the forum / an unstyled forum. See
  `docs/configuration.md`.

**Known security-relevant code paths (re-check on dependency updates):**

- `[code]` → tempest/highlight: the library maps its placeholder glyphs
  U+2776–U+277F back to `< > " &` *after* escaping. `JbbCodeCodeDefinition`
  strips that range first. After every tempest/highlight update run
  `php scripts/probe-highlight-xss.php` and diff `src/Escape.php`.
- `[embed]` fetches remote URLs through `SsrfGuardedClient` (IP pinning, manual
  redirects); embed/embed ≥ 4.5 adds its own URL policy on top. Exceptions are
  caught and fall back to a plain link.

---

## 3. Topology (code and installations)

**Installations this repository deploys to** (targets of `dev/deploy.sh`):

| Target | Purpose | Theme | Notes |
|---|---|---|---|
| `test` | staging on the maintainer's workstation | Nova | DB is a copy of production data |
| `beta` | public preview | Macfix | own DB clone |
| `prod` | the live forum | Macnemo | behind an edge proxy and a bot filter (Anubis) |

Which theme a system renders decides where a CSS change can be seen — check
before asking anyone to look (`curl <site>/<theme>/css/theme.css | grep …`).

Request path on prod: edge nginx (TLS) → jail nginx :80 → Anubis → jail nginx
(internal vhost) → PHP-FPM → Saito. Hosts, addresses and paths:
`AGENTS.local.md`.

**Code:** CakePHP 5, PHP 8.4, MariaDB/MySQL. Server-rendered pages with htmx +
Alpine "islands" built by Vite (`frontend/src/islands/`); themes as plugins in
the chain `Bota → Nova → Macnemo/Macfix`. Architecture: `docs/architecture.md`.

**Remotes:** `origin` (GitLab, runs the test suite on branches), `github`
(public, runs PHPStan and the tag-driven release pipeline), `upstream`
(original project, never push). `gh` resolves this repo to the upstream — always
pass `--repo Panxatony/Saito`.

---

## 4. Standard procedures

### Development checks

```shell
sh scripts/test-with-mariadb.sh     # full PHPUnit suite on a throwaway MariaDB (~6 min)
composer phpstan
yarn lint                           # tsc + eslint
yarn css && yarn css:postcss && yarn build
```

`vendor/bin/psalm` is not a gate (not in CI, ~700 findings from an unbootstrapped
run); do not read its exit code as a regression. The jbbcode deprecation stack
traces during the test run are vendor noise.

### Dependabot pull requests

Arrive weekly (Monday). For each batch:

1. Back up `config/app.php`, fetch `github`.
2. Build the assets once on current `develop` and keep a copy (baseline).
3. Merge each PR **separately** with `--no-ff`, message
   `Merge pull request #N from Panxatony/<branch>` plus the attribution trailer.
   Do not octopus-merge.
4. `composer install` (watch for a lock-file warning after several
   `composer.lock` auto-merges), `yarn install --frozen-lockfile`.
5. Rebuild; for CSS changes run
   `python3 -I scripts/css-equivalence.py BEFORE.css AFTER.css [SOURCE.css]`
   on every theme file. Byte diffs of minified CSS say nothing.
6. Read the upstream changelog / source diff of runtime packages (they ship to
   every installation). tempest/highlight → probe script; embed/embed → SSRF
   path.
7. Full test suite, PHPStan, lint. Then report and wait for "push".

Dependabot closes a PR it has superseded; its commit may still have been merged
locally — check `git merge-base --is-ancestor`.

### From change to release

1. Commit on `develop` (small) or a feature branch + PR (larger). There are no
   release or hotfix branches any more.
2. Push (after approval), wait for the DeepSource scan and read it. A scan of a
   commit that touches no PHP reports nothing for PHP and proves nothing.
3. Deploy to `test`, let the maintainer check.
4. **Wait for an explicit release instruction.** Ask for the release name if
   none was given (every release has one: CHANGELOG heading, tag message,
   GitHub release title).
5. Check the documentation the release makes untrue — `todo.md`, `README.md`,
   `docs/*.md`, help pages and guided tours (`docs/help/<lang>/`,
   `plugins/*/docs/help/`), not only the CHANGELOG. Search case-insensitively.
6. Follow **Create A Release** in `README.md`: `dev/check-changelog.sh`,
   `dev/check-ignores.sh`, annotated tag (`git tag -a`), tag without `v`.
   Do **not** create the GitHub release by hand; the pipeline does it.
7. No pipeline run after the tag push? Check githubstatus.com first, then
   `gh workflow run release.yml --ref <tag> --repo Panxatony/Saito` (against
   the tag, never a branch).
8. After the release: update the version sentence on the project page (both
   languages) — see `AGENTS.local.md`.

### Deploying a release (after approval)

```shell
dev/deploy.sh <test|beta|prod> <version>         # dry run: what would change
dev/deploy.sh <test|beta|prod> <version> --go    # do it
```

The script downloads the release artifact, verifies it, protects the
installation's own files, backs up, copies, fixes ownership, clears caches,
sets `db_version` and verifies. It does **not** run migrations: if it lists
pending ones, run `bin/cake migrations migrate` on the target, then make sure
`tmp/` and `logs/` are owned by the web user again. Deploy **prod before beta**
when a release carries a migration.

After a prod deploy, fast-forward `main` to the deployed release — `main`
records what production runs, it moves on deploy, not on tag. Pre-releases
(`-alpha` etc.) never reach `main`.

### Checks after a deploy or an OS update

- Public URLs: status **and** content type (a bot-filter challenge is
  `200 text/html`, also for a stylesheet):
  `sh scripts/status-check.sh <urls…> -- <ssh hosts…>`.
- On prod, Saito itself is only reached inside the jail at the internal vhost
  (see `dev/deploy.sh`, target `prod`, `VERIFY`). An empty response greps as
  "0 matches" — never conclude from a zero count alone.
- Error log and backend 5xx since the change. Note that database timestamps and
  Saito's `logs/error.log` are UTC while web-server logs are local time.

---

## 5. Known pitfalls (each happened at least once)

| What went wrong | Cause | Right way |
|---|---|---|
| Updater page shown to every visitor (8.3.17, also 2026-07-24) | `version.php` landed before `db_version` was updated; opcache revalidates in ~2 s | `dev/deploy.sh`; when doing it by hand: prove the DB command works first (`SELECT` the old value), then copy and update in one sequence |
| Prod HTTP 500 for five minutes (2026-08-17) | `config/` skipped wholesale, so `config/bootstrap.php` stayed old against a new `vendor/` | only `app.php`, `saito_config.php`, `.env` are installation-specific — everything else in `config/` ships |
| Published posting URLs redirected to login for weeks | `config/routes.php` stale on prod for the same reason | as above; verify a deploy by grepping for something new in plugin config, not only the front page |
| Avatars vanished forum-wide (8.2.0) | `rsync --exclude '/webroot/useruploads/'` matches directories only; the path is a symlink and `--delete` removed it with 16 others | use `--filter='protect …'` for path *and* contents; read `--dry-run --itemize-changes \| grep '^\*deleting'` first |
| 21 plugin `routes.php`/`bootstrap.php` not deployed | unanchored `--exclude 'config/'` matched every `config/` dir | anchor exclude patterns with a leading `/` |
| Unstyled forum after a package deploy | plugin asset symlinks are not in the tarball; `rsync --delete` removed them | `bin/cake plugin assets symlink`; compare the link count with what *should* exist on that install |
| Test site lost its theme and language | tracked `saito_config.php` installed over the deployed one | merge the specific new lines only |
| Release pipeline left without tarball (8.2.8) | GitHub release created by hand before the job | never create it by hand; repair: `gh release delete <tag> --yes`, rerun failed jobs |
| Release created a tag the dispatch could not run | a dispatch reads the workflow from the ref; old tags lack the trigger | dispatch against the tag; check githubstatus.com before searching the repo |
| Migration narrowed `users.user_category_custom` 1024 → 512 | `MODIFY … VARCHAR(512)` restates the whole column | write the full intended definition; fixed in 8.0.12 with a widening migration |
| Theme variable could not be overridden | Sass variable declared without `!default` | every theme variable carries `!default` |
| Twenty `@extend` would have emitted nothing | under `@use` an `@extend` only reaches modules the file loads | load the module you extend from; `@import` migration is blocked on Bootstrap 6 (#68) |
| `x-show` did not reveal a modal | Alpine clears its own inline `display`; the stylesheet's `display:none` wins | bind a class with higher specificity |
| Endless re-login (8.4.9) | a pre-7.0.4 two-part remember-me cookie was rejected but never deleted | `clearUnusableAuthCookie()`; for "logged out again" reports first check whether "Remember me" was ticked — only then are `Saito-AU`/`Saito-AU-2FA` set, and logout expires both |
| Issue filed as "API retired" — wrong on every point (#85) | inferred from `curl` 404s on guessed paths | `bin/cake routes` |
| Headless-browser comparison showed everything "read" | Saito treats the default headless user agent as a bot (`ReadPostingsDummy`) | pass a normal browser user agent |
| Migration ran against the wrong database on test | several PHP-FPM pools with different `DATABASE_URL`s | derive the pool from `fastcgi_pass` first; abort if unexpectedly many migrations run |
| A cssnano update looked like a 150-line CSS change | minified output regrouped | `scripts/css-equivalence.py` |
| Local `app.php` wiped, suite went 720 green → 207 errors | `git reset --hard` | back it up first (rule 3) |
| Release cut on a test report alone (2026-07-29) | a test result was read as approval | releases need an explicit instruction |

---

## 6. Open items (not commissioned)

- GitHub issues #66, #68, #69, #74, #79, #92, #94 — all tech debt, none
  urgent; #68 waits for Bootstrap 6, #69 for typescript-eslint support of TS 7.
- No test drives a *successful* passkey (WebAuthn) login; `WebauthnTest` covers
  the refusal paths only, so the cookies a passkey login sets are untested.
- Psalm runs unbootstrapped and reports ~700 configuration findings; either
  configure it properly or remove it from `require-dev`.
- `cakephp/authentication` stays on 3.x (see #66). Still free while security
  fixes are backported; the moment a 4.x security fix is *not* backported, the
  upgrade becomes necessary. Check with
  `gh api repos/cakephp/authentication/compare/<our-tag>...3.x`.

---

## Helper scripts

| Script | Purpose |
|---|---|
| `scripts/test-with-mariadb.sh` | PHPUnit on a throwaway MariaDB container |
| `scripts/css-equivalence.py` | are two CSS builds equivalent (declarations + per-property order, optionally against source order) |
| `scripts/probe-highlight-xss.php` | XSS probe for `[code]` / tempest/highlight |
| `scripts/status-check.sh` | read-only status of URLs and FreeBSD hosts after updates |
| `dev/deploy.sh` | deploy a released version to test / beta / prod |
| `dev/check-changelog.sh`, `dev/check-ignores.sh` | release preconditions |
| `dev/check-security-headers.sh`, `dev/audit-probes.sh` | security checks against an installation |
