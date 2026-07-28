
# Customizing #

## Themes ##

The default theme is *Nova*, a [CakePHP theme plugin](https://book.cakephp.org/5/en/views/themes.html) in `plugins/Nova`. It builds on the SCSS partials of *Bota* (`plugins/Bota`), the older theme, which also hosts the shared assets — the web fonts and the smiley icon font are served from `/bota/` for every theme. The UI is [Bootstrap 4](https://getbootstrap.com/docs/4.3/getting-started/introduction/) based.

To start your own theme I recommend using SASS and referencing the default theme.

A theme is an ordinary [CakePHP plugin](https://book.cakephp.org/5/en/plugins.html#manually-autoloading-plugin-classes); `plugins/Macnemo` (the macnemo identity, built on Nova) is a worked example to read. The steps below use *MyTheme* as a placeholder — substitute your own name.

1. Create `plugins/MyTheme` and copy the theme resources (default template, webroot content) from `plugins/Nova` into it.

2. Activate *MyTheme* as the default theme in *config/saito_config.php*
   (`Saito.themes.default`). That is enough to load it: Saito loads the
   configured default theme's plugin itself, and CakePHP finds a plugin by its
   directory name even when no class of its own is autoloadable.

   Add `"MyTheme\\": "./plugins/MyTheme/src/"` to `composer.json`'s autoload
   map only if the theme ships a plugin class you want CakePHP to *use* —
   without the entry it silently falls back to a generic `BasePlugin`, which is
   all a pure theme needs. Do not edit *src/Application.php*; nothing there has
   to know about your theme.

3. Replace everything in *plugins/MyTheme/webroot/css/theme.scss* with:

```
@import "../../../../../plugins/Bota/webroot/css/src/theme";
```

This includes Bota's *theme.scss*. Compiling it with SASS should give you the same look as the default theme. Now customize the theme:

```
/// Configure Bootstrap and Saito theme-variables before importing the theme.
$body-color: #222;
...

/// Import the default theme.
@import "../../../../../plugins/Bota/webroot/css/src/theme";

/// Additional customizations
body {...}
...
```

### The forum logo

The header shows `webroot/img/forum_logo.*` from the active theme. Any of `svg`,
`png`, `webp` or `jpg` works and the vector is preferred when several exist, so a
theme whose wordmark only exists as a bitmap needs no conversion. The image is
shown at about 2.5rem high; supply it at twice that for high-resolution screens.

If the header bar has a colour of its own, remember that the logo sits on *that*
and not on the page background — `plugins/Macfix` is a worked example of a
coloured bar with a light wordmark.

Theming resources:

- [Bootstrap theming](https://getbootstrap.com/docs/4.3/getting-started/theming/)
- [Boostrap variables](https://github.com/twbs/bootstrap/blob/v4.3.0/scss/_variables.scss)
- [SASS documentation](https://sass-lang.com/documentation)
- [Simple GUI crossplatform SASS processor](https://scout-app.io/)

## The front page ##

Three keys in `config/saito_config.php` decide what the front page carries
besides the thread list. All three are optional, and leaving a key out keeps
Saito behaving as it always has.

```
'Saito' => [
    'bannerHtml' => '',
    'notice' => true,
    'widgetsForGuests' => true,
],
```

`bannerHtml` is markup placed between the header bar and the page, inside a
`div.ads_top` — the slot forums have traditionally used for a banner or a
donation notice. It is rendered unescaped, so it is operator-controlled
configuration and never user input. Empty means the container is not rendered at
all, so a forum without a banner gets no stray markup.

`notice` shows the bar explaining the modernised frontend (or, on a beta
installation, that this is a throwaway copy). It earns its place in the weeks
around a switch, when people arrive with a stale cache and an interface they
have never seen; set it to `false` once that has passed.

`widgetsForGuests` decides whether visitors who are not signed in get the widget
rail — who is online, and the most recent postings. `false` makes it a
members-only feature: the rail is not rendered for a guest, and the fragment
endpoint answers them with nothing, so the online list cannot be read by
requesting it directly either. "Your postings" has always required an account
and is unaffected.

## Bot detection ##

Saito recognizes non-human clients (search engines, crawlers, HTTP libraries, feed readers, link-preview fetchers, monitors, …) by their `User-Agent`, so they can be handled and counted apart from human visitors (e.g. in the online-users list). A generic list of user-agent snippets ships built-in.

To recognize additional agents on your installation, add snippets via the `Saito.bots` configuration (for example in `config/saito_config.php`). They are merged with the built-in list:

```
'Saito' => [
    'bots' => ['MyCorpScanner', 'some-other-agent'],
],
```

A client is treated as a bot when its `User-Agent` contains any of the snippets (case-insensitive substring match).

## The help overlay

The guided tour in the help overlay is Markdown, and a forum can supply its own.
The first file found wins:

1. `config/help/<lang>/overlay.md` — this installation's own. `config/` is
   excluded from every deploy, so it survives updates.
2. `plugins/<YourTheme>/docs/help/<lang>/overlay.md` — shipped with your theme,
   and therefore in version control. `plugins/Macnemo` does this.
3. `docs/help/<lang>/overlay.md` — what Saito ships.

Languages are tried in turn: the configured one, its base (`de_DE` → `de`), then
English.

The format is Markdown with two conventions: an `<!-- icon: name -->` line above
a `###` heading picks the Font Awesome glyph beside it, and anything after
`<!-- outro -->` closes the tour. Text before the first heading introduces it.
