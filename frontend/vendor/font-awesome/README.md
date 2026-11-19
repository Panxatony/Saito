# Font Awesome 4.7.0, vendored

The `scss/` and `fonts/` directories of the npm package `font-awesome@4.7.0`,
copied unchanged on 2026-11-19 (issue #92).

- Source: `https://registry.yarnpkg.com/font-awesome/-/font-awesome-4.7.0.tgz`
- Integrity of that tarball, as `yarn.lock` recorded it:
  `sha512-U6kGnykA/6bFmg1M/oT9EkFeIYv7JlX3bozwQJWiiLz6L0w3F5vBVPxHlwyX/vtNq1ckcpRKOB9f2Qal/VtFpg==`
- Licence: the font is SIL OFL 1.1 (Copyright Dave Gandy), the CSS is MIT.
  The header in `scss/font-awesome.scss` says so and travels into the compiled
  stylesheet.

## Why it is here

4.7.0 was released in 2016 and is the last version the `font-awesome` package
will ever have; Font Awesome moved to another package name with version 5 and
renamed its icons on the way. Saito uses 47 of these icons, some by names it
composes at run time. Rather than depend on a package that cannot change, the
files it consists of are kept here, where they cannot disappear either.

## How it is used

- `yarn css` passes `--load-path=frontend/vendor`, so
  `@use "font-awesome/scss/font-awesome"` in `webroot/css/src/` resolves here.
- `yarn assets:copy` copies `fonts/` to `webroot/css/stylesheets/fonts/`.

Nothing in this directory is edited. A change to an icon belongs in
`webroot/css/src/partials/_font-awesome-extend.scss`. `frontend/` is not part
of the release package; only the compiled stylesheet and the copied fonts are.
