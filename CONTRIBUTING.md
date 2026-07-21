# Contributing to FamilyWell

Thanks for helping out! Bug fixes, new modules, and documentation improvements are all welcome.

## Getting set up

Follow the [Quick start](README.md#quick-start-local-development) in the README to get a local instance running. Local development uses SQLite and needs no external services.

## Development workflow

1. Fork the repo and create a branch off `main` (`git checkout -b fix/short-description`).
2. Make your change, with tests where it makes sense.
3. Run the checks below and make sure they pass.
4. Open a pull request describing **what** changed and **why**. Keep PRs focused, one topic per PR is easier to review.

## Code style and checks

The same checks run in CI, so run them locally first:

```bash
# PHP formatting (Laravel Pint)
./vendor/bin/pint

# Frontend formatting and linting
npm run format        # Prettier (writes)
npm run lint          # ESLint (fixes)

# Type-check the frontend
npx tsc --noEmit

# Tests (Pest)
php artisan test
```

Conventions:

- **PHP** follows Laravel Pint's ruleset. **Frontend** is TypeScript + React with Prettier and ESLint.
- Match the style of the surrounding code, naming, structure, and comment density.
- Write a comment only to explain *why* something non-obvious is done, not *what* the code does.
- Prefer fixing the root cause over adding defensive patches.

## Tests

Tests use [Pest](https://pestphp.com) and live in `tests/`. Feature tests boot a family with modules enabled (see `tests/Feature/Spending/SpendingTest.php` for the pattern). Run the full suite with `php artisan test`, or a single file by passing its path.

## Building a module

New features usually belong in a module rather than the core. See **[docs/modules.md](docs/modules.md)** for the module system and a step-by-step guide, `modules/hello` is the minimal example and `modules/spending` is a full one.

## Security

- Never commit secrets. `.env` and credential files are git-ignored; configuration comes from environment variables (see [docs/configuration.md](docs/configuration.md)).
- If you find a security issue, please report it privately to the maintainer rather than opening a public issue.

## License

By contributing, you agree that your contributions are licensed under the [MIT License](LICENSE).
