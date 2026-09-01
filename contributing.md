# Contributing

## Feedback & Questions

Please file bugs and open pull requests on [GitHub][saito-github].

## Development Setup

See [docs/dev-setup.md](docs/dev-setup.md) for how to get a local development
environment up and running.

## Working on a Change

- `develop` is the trunk. Branch off it for anything larger than a single
  commit, and open the pull request against it. Small changes go on `develop`
  directly.
- `main` is not a second line of development: it records what production is
  running, and is fast-forwarded from `develop` when a release is deployed.
  There is nothing to branch off it — a fix that has to go out now is a commit
  on `develop` and a tag, the same as any other release.
- This used to say "gitflow-style", with release and hotfix branches. Those were
  real until 8.2.1 (July 2026) and have not been used since; the ceremony bought
  nothing at this size. Releases are a commit and a tag, described under
  **Create A Release** in the [README](README.md).
- Before opening a pull request, run the tests and the static analysis:

  ```shell
  composer phpunit
  composer phpstan
  composer cs-check
  ```

  What CI actually runs, so there are no surprises: GitHub Actions runs
  **PHPStan** on pull requests and on pushes to `main` / `develop`, and the full
  test suite only when a release tag is pushed. GitLab CI runs the test suite on
  branches. Code style is checked by neither — `composer cs-check` is yours to
  run, and it currently reports a sizeable backlog.

[saito-github]: https://github.com/Panxatony/Saito/issues
