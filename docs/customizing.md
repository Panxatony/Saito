
# Customizing #

## Themes ##

The default theme *Bota* is implemented as a [CakePHP theme plugin](https://book.cakephp.org/5/en/views/themes.html) and lives in `plugins/Bota`. The UI is implemented as [Bootstrap 4](https://getbootstrap.com/docs/4.3/getting-started/introduction/) theme.

To start your own theme I recommend using SASS and referencing the default theme.

A theme is an ordinary [CakePHP plugin](https://book.cakephp.org/5/en/plugins.html#manually-autoloading-plugin-classes); `plugins/Macnemo` (the macnemo identity, built on Nova) is a worked example to read. The steps below use *MyTheme* as a placeholder — substitute your own name.

1. Create `plugins/MyTheme` and copy the theme resources (default template, webroot content) from `plugins/Bota` into it.

2. Register the plugin (`composer.json` autoload + `addPlugin()` in *src/Application.php*) and activate *MyTheme* as default theme in *config/saito_config.php*.

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

Theming resources:

- [Bootstrap theming](https://getbootstrap.com/docs/4.3/getting-started/theming/)
- [Boostrap variables](https://github.com/twbs/bootstrap/blob/v4.3.0/scss/_variables.scss)
- [SASS documentation](https://sass-lang.com/documentation)
- [Simple GUI crossplatform SASS processor](https://scout-app.io/)

## Bot detection ##

Saito recognizes non-human clients (search engines, crawlers, HTTP libraries, feed readers, link-preview fetchers, monitors, …) by their `User-Agent`, so they can be handled and counted apart from human visitors (e.g. in the online-users list). A generic list of user-agent snippets ships built-in.

To recognize additional agents on your installation, add snippets via the `Saito.bots` configuration (for example in `config/saito_config.php`). They are merged with the built-in list:

```
'Saito' => [
    'bots' => ['MyCorpScanner', 'some-other-agent'],
],
```

A client is treated as a bot when its `User-Agent` contains any of the snippets (case-insensitive substring match).
