# TODO — retiring the SPA

**Goal: after macnemo.de is cut over to the island frontend, the whole
Backbone/Marionette SPA comes out of the repository.**

Everything below is blocked until then: production still runs the SPA, so it has
to keep working. The order matters — each step removes a dependency the next one
would otherwise trip over.

The island frontend is feature-complete apart from the admin area, which was
never part of the SPA and stays as it is.

---

## 1. Decouple `/entries/view/<id>` (variant B)

The one remaining structural tie to the SPA. This URL does double duty: it is
both the link target of a thread line **and** the AJAX endpoint the island posts
to for opening a posting inline (`toggleInlinePosting` reads the `href` and
posts to it). As long as that holds, the SPA's `EntriesController::view()`
cannot be deleted.

**Emitters to switch to island URLs** (all gated on `Saito.frontend`, the same
pattern already used for edit/merge/mix):

| Where | What |
|---|---|
| `PostingHelper::getFastLink()` | thread-line subject link — the main one |
| `ThreadHtmlRenderer:91` | same link in the fast renderer (raw HTML string) |
| `templates/element/entry/view_content.php:13` | link inside a rendered posting |
| `templates/Entries/htmx_reply_done.php:13` | "reply posted" confirmation |
| `EntriesController:873, 1003` | redirects after edit and merge |
| `MarkupSettings::hashBaseUrl` | `#123` tags **inside members' posting text** |

**Decoupling the endpoint.** Cheaper than it looks: the thread leaf already
carries `data-id` and a `data-leaf` JSON blob containing the `tid`. So the
island can read the id from the element instead of the href (~5 lines), and
navigation can go straight to `/entries/htmx-thread/<tid>` instead of relying on
the 301 hop that today's code leans on.

Variant B proper adds a dedicated island action, e.g.
`EntriesController::htmxPosting($id)` (~15 lines) rendering the same
`element/entry/view_posting`, so nothing points at an SPA action any more.

**Effort:** ~1–2 h for the emitters plus the JS decoupling, ~1 h more for the
dedicated action.

**Checked, so it does not surprise anyone later:**
- No caching involved. Despite its name `thread_cached_init.php` caches
  nothing, and parsed posting text is not cached either — no stale URLs.
- 20 test references across 5 files, three of them hard expectations in
  `PostingHelperTest`. Because the change is config-gated they stay green; add
  island cases rather than rewriting them.
- The real risk is `hashBaseUrl`: it decides how `#123` tags in **existing**
  postings render. Render-time only and revertible, but verify it against a
  posting that actually contains such a tag.

## 2. Remove the SPA entry points

Delete the SPA actions and their templates — **not** the shared logic behind
them. Several actions are only thin wrappers: `runSimpleSearch()`,
`prepareAdvancedSearch()` and `_contact()` are shared with the island and must
survive.

- ~14 candidate actions in `EntriesController`, `UsersController`,
  `SearchesController`, `ContactsController` (`index`, `view`, `mix`, `edit`,
  `merge`, `simple`, `advanced`, `bookmarks`, `changepassword`, …).
- The non-`htmx_*` templates under `templates/Entries/`, `templates/Users/`
  and `plugins/SaitoSearch/templates/Searches/`.
- Layouts `templates/layout/_default.php` and `ajax.php`.

Known and deliberately not fixed beforehand: the SPA member list
(`UsersController::index`) has the same `limit => 400` that Cake's `maxLimit`
silently caps at 100, and it offers no page navigation — so it shows 100 of the
members and pretends that is all. It dies with this step.

## 3. Remove the SPA frontend and its build

- `frontend/src` minus `frontend/src/islands` — 111 files, ~856 KB
  (`app`, `collections`, `models`, `modules`, `views`, `lib`, `templates`).
- `webpack.config.js`, `karma.conf.js`, `frontend/test`.
- The SPA parts of `Gruntfile.js`; `vite.config.ts` stays, it builds the island.
- ~30 packages in `package.json`: backbone, backbone.marionette,
  backbone.babysitter, backbone.localstorage, jquery (+ plugins), underscore,
  webpack, karma, jasmine and their `@types`. Of 81 packages today.
- Keep `frontend/src/locale` — the island uses the translations too.

## 4. Remove the switch itself

With only one frontend left, `Saito.frontend` has nothing left to decide.
Thirteen files read it today:

`src/Application.php`, `src/Controller/AppController.php` (`isIslandFrontend()`),
`src/Controller/PagesController.php`, `src/View/Helper/PostingHelper.php`,
`src/View/Helper/UserHelper.php`, `templates/layout/htmx_island.php`,
`templates/cell/AppStatus/display.php`, `templates/element/entry/view_posting.php`,
`templates/element/layout/disclaimer.php`, `templates/element/users/recent_posts_list.php`,
`templates/Pages/impressum.php`, `templates/Pages/privacy.php`,
`templates/Users/htmx_profile.php`.

Also drop the `SAITO_FRONTEND` env variable and the config key — and check
`debug.email`, which currently defaults to true *because* the frontend is the
island (a beta safety net that must not survive into a single-frontend world).

## 5. Tidy up afterwards

- `.deepsource.toml`: several exclusion patterns exist only because of SPA
  script files (`**/webroot/**`, `Gruntfile.js`, `karma.conf.js`,
  `webpack.config.js`, `frontend/test/**`, the legacy `.js` list).
- `.gitignore`: the `webroot/js/*` rules cover the webpack bundles.
- README and `docs/`: the Marionette mentions in the development section.

---

## Not part of this, but adjacent

- **`templates/Pages/forum_disabled.php`** — fixed on 2026-07-26 (external
  Google font over http removed, viewport added). No template makes an external
  request any more; worth keeping it that way.
- **DeepSource JS-0067 / JS-0052** — 24 occurrences that are knowingly accepted.
  They need an ignore rule in the dashboard; the API refuses it for this account
  type and `module_system`/`dialect` in `.deepsource.toml` did not help
  (verified 2026-07-26). Reasoning is recorded in that file.
