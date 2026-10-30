# Easy Email Verification for Mautic

Verify the email addresses of your Mautic contacts with [Easy Email Verification](https://www.easyemailverification.com)
and store the result in contact fields.

- **Campaign condition**: checks the contact's address, so a campaign can continue for deliverable addresses and
  handle the others (tag, remove, skip, ...).
- **Verification on contact save**: verifies new contacts, and contacts whose email changed, when they are saved in
  Mautic.
- **On-demand verification**: **Verify email now** verifies one contact from its page or the contact list, and
  **Verify selected** verifies the contacts selected in the list.
- **Two ways to sign in**: an API key, or **Sign in with Easy Email Verification** (OAuth), which connects Mautic to
  your account without copying a key.

Each verification uses one credit.

Step-by-step setup guide: https://www.easyemailverification.com/mautic.md

## Compatibility

Plugin versions follow [semantic versioning](https://semver.org/) and are independent of Mautic's version
numbers.

| Plugin | Mautic | PHP | Branch |
|--------|--------|-----|--------|
| 2.x    | 5.0 – 5.2, 6.0, 7.0 – 7.2 | the PHP versions of your Mautic (8.0 – 8.5) | `main` |
| 1.x    | 4.4    | 7.4 – 8.0 | `1.x` |

Version 2.x does not run on Mautic 4.4, and 1.x does not run on Mautic 5 or later. Upgrading Mautic from 4.4?
Upgrade the plugin to 2.x at the same time: its settings and API key are kept.

## Installation

### With Composer

In a Composer-based Mautic installation (`mautic/recommended-project`):

```sh
composer require easyemailverification/plugin-mautic:^2.0
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

Composer installs the plugin as `docroot/plugins/MauticEasyBundle`. On Mautic 4.4, use `:^1.0` instead.

### From the zip file

1. Download `MauticEasyBundle-vX.Y.Z.zip` from the
   [latest release](https://github.com/EasyEmailVerification/plugin-mautic/releases).
2. Unzip it into Mautic's plugin directory (`docroot/plugins/`), so that
   `docroot/plugins/MauticEasyBundle/MauticEasyBundle.php` exists, and give the folder to the web server user:

   ```sh
   cd /path/to/mautic/docroot/plugins
   unzip MauticEasyBundle-vX.Y.Z.zip
   chown -R www-data:www-data MauticEasyBundle
   ```

   With the official Docker image (container named `mautic`):

   ```sh
   docker cp MauticEasyBundle-vX.Y.Z.zip mautic:/var/www/html/docroot/plugins/
   docker exec -w /var/www/html/docroot/plugins mautic sh -c 'unzip MauticEasyBundle-vX.Y.Z.zip && chown -R www-data:www-data MauticEasyBundle'
   ```

   When upgrading from 1.x, replace the old `MauticEasyBundle` folder (move it out of `plugins/` first).
3. Clear the cache and register the plugin:

   ```sh
   php bin/console cache:clear
   php bin/console mautic:plugins:reload
   ```

   or, in Mautic, open **Settings (gear icon) → Plugins** and click **Install/Upgrade Plugins**.

## Configuration

In Mautic, open **Settings (gear icon) → Plugins → Easy Email Verification**, set **Published** to *Yes*, choose how
the plugin signs in on the **Details** tab, and save.

### With an API key

1. Create an account at the [Easy Email Verification dashboard](https://dashboard.easyemailverification.com/register).
2. In the dashboard, open **Integration → API Settings** and click **Create API Key**.
   Use an **API key**: a widget key does not work with the plugin.
3. In the plugin settings, choose **Authentication: API key**, paste the key and save.

The key is stored encrypted and is not shown again; leave the field blank to keep it.

### With "Sign in with Easy Email Verification" (OAuth)

No key to copy: Mautic connects to your account, as the Easy Email Verification connector for ChatGPT and Claude
does. Mautic must be served over **https**.

1. In the plugin settings, choose **Authentication: Sign in with Easy Email Verification (OAuth)** and click
   **Authorize**.
2. In the window that opens, sign in to Easy Email Verification and click **Allow**. The window shows "Mautic is
   connected"; close it.
3. The settings now say "This Mautic is connected with OAuth". Verifications use this connection.

The connection appears in the dashboard under **Connected apps**. To disconnect, set **Disconnect from Easy Email
Verification** to *Yes* in the plugin settings and save: Mautic revokes its tokens and goes back to the API key. You
can also remove it from **Connected apps** in the dashboard; the next verification then takes the "no" path and
logs that the connection was revoked.

The plugin asks only for the `verify` permission. It stores the tokens encrypted with Mautic's other keys and
renews them on its own; a connection unused for 30 days expires and needs **Authorize** again.

### Decision mode

On the **Features** tab, **Decide by** sets what makes a contact pass the condition:

- **Use the field 'Safe To Send' for decision** (default): the address passes only when the API reports it as safe
  to send. This is the stricter option: it also fails disposable, accept-all, risky and unknown addresses.
- **Use the field Result for decision**: the address passes unless the result is `invalid` or `unknown`.
  Accept-all (catch-all) addresses are reported as `valid` and pass.

### Verification when contacts are saved

Also on the **Features** tab:

- **Verify on contact save** (default *Yes*): when a contact with an email is created or edited in Mautic's contact
  form (new, edit, quick add), it is verified if it has no result yet or its email changed. A contact already
  verified with the same email is not verified again, so saving it again uses no credit. The contact is saved first:
  if the API cannot answer, it stays saved, its `eev_` fields are left unchanged and the error is logged.
- **Also verify on import/API** (default *No*): also verify contacts saved by imports, the API, forms,
  integrations, campaign actions and contact list batch actions, with the same rules. **Each contact verified uses one
  credit**: an import of 10,000 new contacts uses up to 10,000 credits.

The verification waits for the API for up to 15 seconds; saving a contact takes that much longer when it is verified.

### On-demand verification

Verify contacts right away, outside campaigns. On-demand verification always calls the API, even for a recent
result, and stores the answer in the contact fields. It needs permission to edit the contacts and a plugin that is
set up and published.

**One contact.** On a contact's page, next to **Edit**, or in the menu of each row of the contact list, click
**Verify email now** and confirm. Mautic shows the result (`valid`, `invalid` or `unknown`, and safe to send) or the
error. It uses one credit. The button is disabled, with the reason as its tooltip, for a contact without email.

**Several contacts.** In the contact list, select contacts and click **Verify selected**: in the bar that appears
above the list on Mautic 6 and 7, in the mass actions menu on Mautic 5. The confirmation shows how many credits it
uses: one per selected contact with an email, at most 100 contacts at a time. When it finishes, a summary shows how
many contacts were verified, failed or had no email.

### Testing without credits

The sandbox API key `eev_sandbox_key` answers without using credits, for addresses at
`sandbox.easyemailverification.com` only. It works with the API key method; through OAuth every verification uses
credits of the connected account.

| Address | result | safe_to_send | accept_all | Safe To Send mode | Result mode |
|---------|--------|--------------|------------|-------------------|-------------|
| `valid@sandbox.easyemailverification.com` | valid | true | false | yes | yes |
| `disposable@sandbox.easyemailverification.com` | valid | false | false | no | yes |
| `catchall@sandbox.easyemailverification.com` | valid | false | true | no | yes |
| `invalid@sandbox.easyemailverification.com` | invalid | false | false | no | no |
| `unknown@sandbox.easyemailverification.com` | unknown | false | false | no | no |

## Campaign condition

The condition is not affected by the verification on save or by **Verify email now**, except that it reuses their
results like any other.

In the campaign builder, add the **Easy Email Verification** condition and connect its two paths: the "yes" path
for addresses that pass and the "no" path for the others.

- A contact verified less than **30 days** ago is not verified again: the stored result is reused, so no
  credit is spent. The result is verified again when the contact's address moves to another domain.
- A contact without an email address takes the "no" path.
- When the API cannot answer (timeout, connection error, invalid API key, revoked or expired OAuth connection, no
  credits left), the contact takes the "no" path, its fields are left unchanged and the error is written to Mautic's
  log. It is verified again the next time a campaign reaches the condition.

## Contact fields

On the first evaluation the plugin creates these contact fields (group *Core*) and fills them with the API answer:

| Field | Content |
|-------|---------|
| `eev_result` | `valid`, `invalid` or `unknown` (no reliable answer: neither valid nor invalid) |
| `eev_reason` | reason for the result |
| `eev_safe_to_send` | overall recommendation (`true`/`false`) |
| `eev_disposable` | disposable address (`true`/`false`) |
| `eev_accept_all` | the domain accepts any address (`true`/`false`) |
| `eev_role` | role address such as `info@` (`true`/`false`) |
| `eev_free` | free email provider (`true`/`false`) |
| `eev_domain` | domain of the address |
| `eev_mx_record` | mail server |
| `eev_mx_domain` | domain of the mail server |
| `eev_did_you_mean` | suggested correction for a typo |
| `eev_success` | the API answered (`true`/`false`) |
| `eev_message` | message from the API |
| `eev_http_code` | HTTP code of the API answer |
| `eev_validate_time` | date and time of the verification |

## Troubleshooting

- **The plugin does not appear in Settings → Plugins**: check that the folder is `docroot/plugins/MauticEasyBundle`,
  that it contains `MauticEasyBundle.php`, and that the web server user owns it; then clear the cache and click
  **Install/Upgrade Plugins** again. Mautic 4.4 needs plugin 1.x.
- **Every contact takes the "no" path**: look for `Easy Email Verification` in Mautic's log (`var/logs/`).
  - `API error (HTTP 401)`: the API key is wrong (or is a widget key).
  - `API key is missing`: none is saved, or **Authentication** is set to the method you did not configure.
  - `not connected with OAuth` or `the OAuth connection was revoked or has expired`: click **Authorize** again.
  - `API error (HTTP 402)` or "credits": see the [plans](https://www.easyemailverification.com/en-US/pricing).
- **Authorize does nothing**: allow pop-ups for Mautic. An error such as "could not be registered" means Mautic is
  not served over https, or is behind a proxy that Mautic does not trust (`trusted_proxies`), so it builds `http://`
  addresses.
- **The condition does not appear in the campaign builder**: clear the cache after installing or updating.

Still stuck? Write to support@easyemailverification.com or
[open an issue](https://github.com/EasyEmailVerification/plugin-mautic/issues).

## Development

```sh
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md).

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
