# Contributing

Thank you for helping. Bug reports, fixes and small focused features are
welcome.

## Setup

You need PHP 8.1 or later and Composer.

```sh
git clone https://github.com/IndianOpinion/query-id-cache.git
cd query-id-cache
composer install
```

No WordPress install is needed: the tests run against a small stub layer in
`tests/stubs/`.

## Tests and checks

```sh
composer test      # PHPUnit
composer analyse   # PHPStan, level 8
composer lint      # PHP_CodeSniffer, PSR-12 with snake_case methods
composer check     # all three
```

CI runs the same on PHP 8.1, 8.2 and 8.3. Write 8.1-compatible code: no
readonly classes, no DNF types, no constants in traits.

When you change behaviour, add or change a test that names the PRD case it
covers (see `docs/PRD.md`). If you add a stubbed WordPress function, keep
its behaviour faithful to core.

## Style

- PSR-12, `declare(strict_types=1);` in every file.
- Snake_case method and function names, matching WordPress and the public API.
- Typed exceptions, never `@` to silence errors, never silent failure.
- Docs: short sentences, plain words, British spelling.

## Pull requests

- One topic per pull request. Explain the problem first, then the change.
- Keep `main` green: tests, PHPStan and PHPCS must pass.
- Update `CHANGELOG.md` under Unreleased, and `README.md` if behaviour or
  configuration changes.
- Record design decisions in `docs/ARCHITECTURE.md`.
- A DCO-style sign-off is not required.
