# Easy Email Verification for Mautic

Verify email addresses inside Mautic campaigns with [Easy Email Verification](https://www.easyemailverification.com).
The plugin adds a campaign **condition** that checks the contact's address and stores the result in contact
fields, so a campaign can continue for deliverable addresses and handle the others (tag, remove, skip, ...).
Each verification uses one credit.

Step-by-step setup guide: https://www.easyemailverification.com/mautic.md

## Compatibility

Plugin versions follow [semantic versioning](https://semver.org/) and are independent of Mautic's version
numbers. Each major version targets one Mautic line:

| Plugin | Mautic | PHP | Branch |
|--------|--------|-----|--------|
| 1.x    | 4.4    | 7.4 – 8.0 | `1.x` |
| 2.x    | 7      | 8.2+ | `main` (upcoming) |

Version 1.x does not run on Mautic 5 or later. Mautic 4.4 itself is no longer maintained by the Mautic
project; plan an upgrade to Mautic 7 and plugin 2.x.

## Installation

### With Composer

In a Composer-based Mautic 4.4 installation:

```sh
composer require easyemailverification/plugin-mautic:^1.0
php bin/console cache:clear
php bin/console mautic:plugins:reload
```

Composer installs the plugin as `docroot/plugins/MauticEasyBundle` (the plugin directory of the
`mautic/recommended-project` layout).

### From the zip file

1. Download `MauticEasyBundle-vX.Y.Z.zip` from the
   [latest release](https://github.com/EasyEmailVerification/plugin-mautic/releases).
2. Unzip it into Mautic's `plugins/` directory, so that `plugins/MauticEasyBundle/MauticEasyBundle.php` exists,
   and give the folder to the web server user:

   ```sh
   cd /path/to/mautic/plugins
   unzip MauticEasyBundle-vX.Y.Z.zip
   chown -R www-data:www-data MauticEasyBundle
   ```

   With the official Docker image (container named `mautic`):

   ```sh
   docker cp MauticEasyBundle-vX.Y.Z.zip mautic:/var/www/html/plugins/
   docker exec -w /var/www/html/plugins mautic sh -c 'unzip MauticEasyBundle-vX.Y.Z.zip && chown -R www-data:www-data MauticEasyBundle'
   ```

3. Clear the cache and register the plugin:

   ```sh
   php bin/console cache:clear
   php bin/console mautic:plugins:reload
   ```

   or, in Mautic, open **Settings (gear icon) → Plugins** and click **Install/Upgrade Plugins**.

## Configuration

1. Create an account at the [Easy Email Verification dashboard](https://dashboard.easyemailverification.com/register).
2. In the dashboard, open **Integration → API Settings** and click **Create API Key**.
   Use an **API key**: a widget key does not work with the plugin.
3. In Mautic, open **Settings → Plugins → Easy**, paste the key, set **Published** to *Yes* and save.
4. In the **Features** tab, choose what decides the condition:
   - **Use the field 'Safe To Send' for decision** (default): the address passes only when the API reports it as
     safe to send. This is the stricter option: it also fails disposable, risky and unknown addresses.
   - **Use the field Result for decision**: the address passes unless the result is `invalid` or `unknown`.
     Accept-all (catch-all) addresses are reported as `valid` and pass.

### Testing without credits

The sandbox API key `eev_sandbox_key` answers without using credits, for addresses at
`sandbox.easyemailverification.com` only:

| Address | result | safe_to_send |
|---------|--------|--------------|
| `valid@sandbox.easyemailverification.com` | valid | true |
| `disposable@sandbox.easyemailverification.com` | valid | false |
| `catchall@sandbox.easyemailverification.com` | valid | false |
| `invalid@sandbox.easyemailverification.com` | invalid | false |
| `unknown@sandbox.easyemailverification.com` | unknown | false |

## Campaign condition

In the campaign builder, add the **Easy Email Verification** condition and connect its two paths: the "yes" path
for addresses that pass and the "no" path for the others.

- A contact verified less than **30 days** ago is not verified again: the stored result is reused, so no
  credit is spent. The result is verified again when the contact's address moves to another domain.
- A contact without an email address takes the "no" path.
- When the API cannot answer (timeout, connection error, invalid API key, no credits left), the contact takes the
  "no" path, its fields are left unchanged and the error is written to Mautic's log. It is verified again the next
  time a campaign reaches the condition.

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

- **The plugin does not appear in Settings → Plugins**: check that the folder is named exactly
  `MauticEasyBundle`, that it contains `MauticEasyBundle.php`, and that the web server user owns it; then clear the
  cache and click **Install/Upgrade Plugins** again.
- **Every contact takes the "no" path**: look for `Easy Email Verification` in Mautic's log (`var/logs/`).
  `API error (HTTP 401)` means the API key is wrong (or is a widget key); `API key is missing` means none is saved.
- **"Running out of your credit limit"**: see the [plans](https://www.easyemailverification.com/en-US/pricing).
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
