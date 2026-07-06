# SaitoHelp

Serves the forum's help pages. Help content is authored as Markdown in
`docs/help/<lang>/` (and `plugins/<Plugin>/docs/help/<lang>/` for plugin-specific
help) and rendered through the bundled `Commonmark` plugin (`league/commonmark`).

Routes:

- `/help` — overview listing all help topics
- `/help/{id}` — redirects to the reader's language
- `/help/{lang}/{id}` — a single help page

Mark a help file as admin-only with an `<!-- admin -->` comment; such topics
are shown in the overview to admins only.
