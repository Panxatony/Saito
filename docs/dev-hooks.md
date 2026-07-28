# PHP #

## saito.core.posting.delete.after ##

Trigger: after a posting was deleted

Data:

- subject: Posting
- table: Table

## saito.core.posting.view.badges.request ##

Trigger: badge for posting

Data:

- posting - posting data as array

Returns: badge

Be careful, this callback is performance sensitive when rendering thread-trees or long threads in mix-view.

## saito.core.posting.view.footerActions.request ##

Add HTML content to the footer actions when viewing a posting.

Data:

- posting - posting data as array
- View

Returns: items to be inserted in

Be careful, this callback may be performance sensitive when rendering long threads in mix-view.

## saito.core.threadline.render.before ##

Trigger: before threadline is rendered

Data:

- posting
- view

Returns: array with optional keys

- css
- style

Be **very** careful, this callback is performance sensitive when rendering thread-trees.

## saito.core.user.activate.after ##

Trigger: after a new user activated an account

Data:

- subject: User
- table: Table

## saito.core.user.ignore.after ##

Trigger: after a user is ignored

Data:

- blockedUserId
- userId
- Model - UserIgnore


## saito.core.user.register.after ##

Trigger: after a new user registered

Data:

- subject: User
- table: Table

> **Withdrawn.** `saito.core.user.edit.render.request` and
> `saito.core.user.profile.render.request` used to let a plugin add content to
> the settings and profile pages. Nothing dispatches them since those pages were
> rebuilt as islands, so a listener would simply never be called.

# JS #

The `SaitoApp.callbacks.*` hooks and the `Vent.*` events documented here until
Saito 8.1 belonged to the Backbone/Marionette application and went with it.
There is no JavaScript plugin interface in their place.

The frontend is now server-rendered HTML enhanced by htmx and Alpine. A plugin
that needs behaviour in the browser writes it into its own template — Alpine
components are declared in the markup with `x-data`, and htmx attributes drive
requests. Both libraries are published as `window.Alpine` and `window.htmx` by
the island bundle, so an inline `<script>` in a plugin template can reach them.
