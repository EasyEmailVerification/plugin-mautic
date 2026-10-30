# Contributing

Thanks for helping to improve Easy Email Verification for Mautic.

## Branches and versions

- `main`: plugin 2.x for Mautic 5, 6 and 7 (PHP 8.0 – 8.5).
- `1.x`: plugin 1.x for Mautic 4.4 (PHP 7.4 – 8.0). Bug and security fixes only.

Open pull requests against the branch of the version you are fixing. Versions follow
[semantic versioning](https://semver.org/).

## Development setup

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

`composer install` brings the latest Mautic 7 (`mautic/core-lib`) as a dev dependency. Mautic needs the PHP
extensions it requires (bcmath, imap, intl, zip, ...); add `--ignore-platform-req='ext-*'` if one is missing
locally, the tests do not use them. To test against another Mautic line, as CI does:

```sh
composer require --no-update mautic/core-lib:^6.0
composer update
```

`.github/scripts/integration.sh` installs Mautic from its official Docker image, adds the plugin, reloads the
plugins and runs `Tests/Integration/smoke.php` (needs Docker), for example
`bash .github/scripts/integration.sh 7-apache mysql:8.4`. Commit first: it installs the plugin with `git archive`.

To try a change by hand, copy the repository content to `docroot/plugins/MauticEasyBundle` of a Mautic 5, 6 or 7
installation, then run `php bin/console cache:clear` and `php bin/console mautic:plugins:reload`. The sandbox API key
`eev_sandbox_key` works without credits for addresses at `sandbox.easyemailverification.com`. Verifications through
an OAuth connection always use credits of the connected account.

## Pull requests

- Keep the code compatible with PHP 8.0 (the lowest PHP of Mautic 5.0) and with Mautic 5, 6 and 7.
- Add or update tests for the behavior you change; CI must pass (PHP lint, PHPUnit and PHPStan on each Mautic
  line, and the installation in Mautic 5, 6 and 7).
- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages, for example
  `fix: add timeout to verification request`.
- Add a line to the `Unreleased` section of [CHANGELOG.md](CHANGELOG.md).
- Never commit API keys or other credentials. Use the sandbox key in examples.

Security issues: see [SECURITY.md](SECURITY.md).
