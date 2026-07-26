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

## Scanner-Sonden im Fehlerprotokoll — Rest: skipLog

Gefunden und zu zwei Dritteln behoben am 2026-07-26. **Kein Cutover-Schaden**,
das lief seit Jahren so.

**Der Befund war:** Jede Anfrage auf einen nicht existierenden Pfad landete bei
CakePHP und schrieb dort eine `MissingControllerException` samt vollem Stack
Trace. Ausgezählt über alle Protokolle: `Config` 2.975×, `Api` 1.835×, `Core`
848×, `WpIncludes` 252× — ganz überwiegend Schwachstellenscanner. Daher wuchs
`error.log` binnen zehn Tagen auf 10 MB, und jede Sonde belegte kurz einen
PHP-Prozess.

### Erledigt

**Schritt 1 — Plugin-Assets sind echte Dateien.** `bin/cake plugin assets
symlink` auf allen drei Installationen; neun Symlinks (`macnemo`, `nova`,
`bota`, `admin`, `saito_help`, `saito_search`, `spectrum_colorpicker`,
`stopwatch`, `bootstrap_u_i`). Vorher lieferte CakePHPs `AssetMiddleware` die
Theme-Dateien über PHP aus — gemessen 240 Anfragen auf `/macnemo/…`, 75 auf
`/bota/…`. **Genau deshalb musste dieser Schritt zuerst kommen:** Ohne die
Symlinks hätte Schritt 2 das Theme abgeschaltet.

**Schritt 2 — nginx beantwortet fehlende Dateien selbst.** Im Block für
statische Endungen `try_files $uri =404;` statt des Durchfalls auf `index.php`.
Gemessen: `/gibtesnicht.png`, `/wp-content/uploads/x.png` und `/assets/style.css`
liefern 404 mit **null** neuen Protokolleinträgen.

Vorher abgesichert, dass nichts anderes statische Endungen erzeugt: Avatare
liegen unter `/useruploads/` (eigener Block, längst `=404`), Identicons sind
data:-URIs ohne HTTP-Anfrage.

*Falle für später:* `add_header` in einem verschachtelten `location` **ersetzt**
geerbte Header, statt sie zu ergänzen. Beim Nachziehen der Beta hätte der neue
Block deren `X-Robots-Tag: noindex` entfernt; die Zeile steht dort deshalb
erneut drin.

### Offen: Schritt 3

Sonden **ohne** Dateiendung (`/config/database`, `/api/…`) laufen weiterhin über
`location /` bei der Anwendung auf und schreiben je einen Stack Trace — nach
Schritt 2 nachgemessen: ein neuer Eintrag pro Sonde.

Dagegen hilft `Error.skipLog` in `config/app.php`, ergänzt um
`MissingControllerException` und `MissingRouteException`.

**Die Abwägung, weshalb das offen ist:** Damit würde auch ein *echter* fehlender
Controller im eigenen Code stillschweigend nicht mehr protokolliert. Bei
getesteten Routen ist das Risiko gering, aber es ist eine Entscheidung darüber,
was auf dem Server protokolliert wird — keine reine Technikfrage. Wer sie
trifft, sollte wissen, dass sie beide Fälle betrifft.

*Erledigt nebenbei:* `/apple-touch-icon-precomposed.png` fehlte (327 Sonden) und
ist jetzt ein Symlink; ein Übergangs-Symlink `local` → `Macnemo/webroot` fängt
Browser mit zwischengespeichertem HTML von vor der Theme-Umbenennung ab
(165 Anfragen).

## Zeitzonen: die Datenbank hält Lokalzeit, das Framework glaubt an UTC

Gefunden am 2026-07-26 beim Nachgehen eines ganz anderen Verdachts. **Kein
Cutover-Schaden** — das liegt seit Jahren so und betrifft auch die SPA.

**Der Befund**, an einem Beitrag durchgemessen:

| Stelle | Wert |
|---|---|
| Server | `21:11 CEST` = `19:11 UTC` |
| `entries.time` in der Datenbank | `20:54:02` — also **Lokalzeit** (DB-Zeitzone `SYSTEM`) |
| `APP_DEFAULT_TIMEZONE` | `UTC` |
| ausgeliefertes `<time datetime>` | `2026-07-26T20:54:02+00:00` |
| RSS `<pubDate>` | `… +0000` |
| angezeigter Text | `20:54` — **richtig** |

**Warum die Anzeige trotzdem stimmt:** `TimeHHelper` rechnet
`serverOffset - offset(Saito.Settings.timezone)`. Die Einstellung steht auf
`Europe/Berlin`, der Server läuft auf CEST — die Differenz ist null, also wird
der rohe Wert unverändert ausgegeben und trifft zufällig zu.

**Was daraus folgt:**

- Alles Maschinenlesbare ist um den lokalen Versatz falsch: `datetime`-Attribut,
  RSS-`pubDate`, damit jeder Feedreader. Beiträge erscheinen dort **zwei Stunden
  in der Zukunft** (im Winter eine).
- Es ist zusätzlich **fragil**: Stellt man den Server auf UTC um — bei einem
  Umzug naheliegend — kippt auch die bislang korrekte Anzeige um zwei Stunden.
  Die Richtigkeit hängt daran, dass Serverzeitzone und Anzeigeeinstellung
  zufällig übereinstimmen.

**Warum nicht schnell behoben:** `APP_DEFAULT_TIMEZONE` auf `Europe/Berlin` zu
setzen verschiebt schlagartig **alle** Zeiten, auch die von 2006 — und diese
Altbestände wurden über die Jahre womöglich unter wechselnden Annahmen
geschrieben. Das braucht:

1. eine Bestandsaufnahme, ob `entries.time` durchgängig Lokalzeit enthält
   (Sommer-/Winterzeitgrenzen prüfen, Zeiten um 02:00 im Oktober),
2. eine Entscheidung: Bestand nach UTC migrieren (sauber, aber einmalig
   riskant) oder dem Framework die richtige Zeitzone beibringen (billiger,
   konserviert aber die Lokalzeit in der Datenbank),
3. eine Prüfung aller Ausgabewege: `TimeHHelper`, `<time>`-Elemente, Feeds,
   Sortierung, „seit wann ungelesen".

Zusammen mit dem SPA-Rückbau anzugehen, nicht davor: Solange beide Frontends
laufen, verdoppelt sich die Prüffläche.

## Not part of this, but adjacent

- **`templates/Pages/forum_disabled.php`** — fixed on 2026-07-26 (external
  Google font over http removed, viewport added). No template makes an external
  request any more; worth keeping it that way.
- **DeepSource JS-0067 / JS-0052** — 24 occurrences that are knowingly accepted.
  They need an ignore rule in the dashboard; the API refuses it for this account
  type and `module_system`/`dialect` in `.deepsource.toml` did not help
  (verified 2026-07-26). Reasoning is recorded in that file.
