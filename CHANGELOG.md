# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Version 1.x targets Mautic 4.4; version 2.x targets Mautic 5, 6 and 7.

## [Unreleased]

## [2.0.1] - 2026-10-30

### Documentation

- The README lists the plugin's features at the top and describes on-demand verification of one contact
  (**Verify email now**) and of several contacts (**Verify selected**).

## [2.0.0] - 2026-10-30

### Breaking

- Requires Mautic 5, 6 or 7 and PHP 8.0 or later. Mautic 4.4 users stay on 1.x (`composer require
  easyemailverification/plugin-mautic:^1.0`), which keeps receiving security and bug fixes.

### Added

- Support for Mautic 5.0 – 5.2, 6.0 and 7.0 – 7.2 in one release line, tested in CI against each of them.
- "Sign in with Easy Email Verification" (OAuth 2.0) as an alternative to the API key: click **Authorize** in the
  plugin settings, sign in and allow access. It uses the same authorization server as the Easy Email Verification
  connector for ChatGPT and Claude (dynamic client registration per Mautic, PKCE, scope `verify`). Tokens are stored
  encrypted by Mautic, refreshed automatically and revoked by **Disconnect**. Verifications through OAuth use
  credits of the connected account.
- Verification when a contact is saved in Mautic's contact form (new, edit, quick add): when it has no result yet or
  its email changed; never twice for the same email. On by default ("Verify on contact save"). Imports, the API,
  forms, integrations and campaign actions are verified only with "Also verify on import/API" (off by default). A
  failed verification never blocks the save: the contact is kept and the error is logged.
- **Verify email now** on the contact page and in the contact list's row actions, and **Verify selected** for the
  contacts selected in the list: verifies right away, ignoring the 30-day reuse, and shows the result. Requires permission to edit the
  contact.
- Integration tests that install the plugin in the official Mautic 5, 6 and 7 Docker images and run the condition,
  the verification on save and the settings form with the sandbox key.

### Changed

- Rebuilt on Mautic's IntegrationsBundle: settings on the **Details** tab (authentication) and the **Features** tab
  (decision), services in `Config/services.php` with autowiring, no PHP templates.
- The two "decide by" yes/no switches are now one choice: **Safe To Send** (default) or **Result**. Settings saved by
  1.x keep working and show the mode they selected; existing API keys are kept on upgrade.
- The API key field no longer shows the saved key; leave it blank to keep it.
- The campaign builder shows the condition as "Easy Email Verification" instead of
  `mautic.campaign.email_verification`.

### Unchanged

- The campaign condition and its results: the `eev_` contact fields and how they are filled, reuse of a result
  younger than 30 days, verification again after a domain change, the "no" path with fields left unchanged when the
  API cannot answer, timeouts, and logging without contact data or credentials.

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

[Unreleased]: https://github.com/EasyEmailVerification/plugin-mautic/compare/v2.0.1...HEAD
[2.0.1]: https://github.com/EasyEmailVerification/plugin-mautic/releases/tag/v2.0.1
[2.0.0]: https://github.com/EasyEmailVerification/plugin-mautic/releases/tag/v2.0.0
[1.0.2]: https://github.com/EasyEmailVerification/plugin-mautic/releases/tag/v1.0.2
[1.0.1]: https://www.easyemailverification.com/download/MauticEasyBundle-v1.0.1.zip
