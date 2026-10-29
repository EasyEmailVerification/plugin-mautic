# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Version 1.x targets Mautic 4.4; version 2.x targets Mautic 7.

## [Unreleased]

## [1.0.2] - 2026-10-29

### Fixed

- The plugin listened to Mautic's generic condition event, so it called the API and changed the result of every
  condition in every campaign, not only its own. The condition now has its own event name.
- A failed verification (invalid API key, no credits, API error, timeout, connection error) counted as a valid
  address. It now fails the condition and leaves the contact's fields unchanged. Timeouts and connection errors
  were not caught at all and broke the campaign run.
- A contact verified less than two minutes earlier always failed the condition. A stored result younger than
  30 days is now reused, so the same contact no longer spends a credit each time a campaign reaches the condition.
- The 15 `eev_` contact fields were created again every time the plugin loaded, logging an error for each one.
  They are now created once.
- The plugin wrote its own log to `app/logs/mautic_test.log`, including contact email addresses, and could log the
  API key in request errors. It now logs through Mautic's logger without contact data.
- The API key is sent in the `X-API-Key` header instead of the URL.
- Added a 10 second connect timeout to the verification request.
- A contact without an email address was sent to the API; it now fails the condition without a request.
- Missing integration or API key, and feature settings never saved, caused PHP errors and warnings. Unsaved settings
  now use the defaults shown in the settings form.
- Removed routes and menu items that pointed to controllers that do not exist.
- Removed an unused view, unused translations and unused configuration entries; the plugin version shown in
  Mautic now matches the release.
- `EasyIntegration::getKeys()` no longer overrides Mautic's method with a different return type; use `getApiKey()`.

### Added

- `composer.json` in the Mautic Marketplace format (`easyemailverification/plugin-mautic`).
- Unit tests, PHPStan (level 8) and CI on PHP 7.4 and 8.0.

## [1.0.1] - 2024-07-08

### Added

- Initial release for Mautic 4: campaign condition "Easy Email Verification", decision by "safe to send" or by
  "result", and the `eev_` contact fields with the verification results.

[Unreleased]: https://github.com/EasyEmailVerification/plugin-mautic/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/EasyEmailVerification/plugin-mautic/releases/tag/v1.0.2
[1.0.1]: https://www.easyemailverification.com/download/MauticEasyBundle-v1.0.1.zip
