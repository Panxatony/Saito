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

  CI (GitHub Actions) runs the test suite and PHPStan on every pull request and
  on pushes to `main` / `develop`.

[saito-github]: https://github.com/Panxatony/Saito/issues
