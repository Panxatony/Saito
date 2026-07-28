# Contributing

## Feedback & Questions

Please file bugs and open pull requests on [GitHub][saito-github].

## Development Setup

See [docs/dev-setup.md](docs/dev-setup.md) for how to get a local development
environment up and running.

## Working on a Change

- Saito uses a gitflow-style branching model: branch off `develop` for features
  and fixes (off `main` for hotfixes), and open the pull request against
  `develop`.
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
