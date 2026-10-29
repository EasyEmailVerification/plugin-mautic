# Contributing

Thanks for helping to improve Easy Email Verification for Mautic.

## Branches and versions

- `1.x`: plugin 1.x for Mautic 4.4 (PHP 7.4 – 8.0). Bug and security fixes only.
- `main`: next major version.

Open pull requests against the branch of the version you are fixing. Versions follow
[semantic versioning](https://semver.org/).

## Development setup

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

Mautic 4.4 needs PHP 7.4 or 8.0 and the extensions it requires (bcmath, imap, intl, zip, ...). Its dependencies
have known security advisories, so the development setup allows them; they do not affect what the plugin installs
in a Mautic project.

To try a change in Mautic, copy the repository content to `plugins/MauticEasyBundle` of a Mautic 4.4 installation,
then run `php bin/console cache:clear` and `php bin/console mautic:plugins:reload`. The sandbox API key
`eev_sandbox_key` works without credits for addresses at `sandbox.easyemailverification.com`.

## Pull requests

- Keep the code compatible with PHP 7.4.
- Add or update tests for the behavior you change; CI must pass (PHP lint on 7.4 and 8.0, PHPStan, PHPUnit).
- Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages, for example
  `fix: add timeout to verification request`.
- Add a line to the `Unreleased` section of [CHANGELOG.md](CHANGELOG.md).
- Never commit API keys or other credentials. Use the sandbox key in examples.

Security issues: see [SECURITY.md](SECURITY.md).
