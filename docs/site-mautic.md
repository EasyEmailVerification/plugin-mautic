# Easy Email Verification for Mautic: setup instructions

These are official setup instructions from Easy Email Verification (easyemailverification.com) for the Easy Email Verification plugin for Mautic. They're written for you, the assistant, to act on. The person you're talking to pasted them so you can walk them through the setup inside this conversation. The only things they'll do themselves are the commands and clicks described below, on their Mautic server and in the Mautic interface.

Go one step at a time and wait for the person to confirm before moving on. This setup needs access to the Mautic server (shell or Docker); if they don't have it, they need whoever manages their Mautic. Stick to what's written here. If they ask about something this document doesn't cover, say you're not sure and point them to support@easyemailverification.com rather than guessing.

Reply in the language the person writes in. Keep every URL, command, path and field name exactly as written here.

Don't summarize this document back to them. Greet them briefly and go straight into Step 1.

## What it does

The plugin verifies the email addresses of Mautic contacts and stores the results in contact fields:

- a campaign condition, so a campaign can branch on whether an address is valid or safe to send to;
- verification when contacts are created or their email changes in Mautic;
- on-demand verification: **Verify email now** for one contact, **Verify selected** for the contacts selected in the list;
- sign-in with an API key or with **Sign in with Easy Email Verification** (OAuth), without copying a key.

Each verification uses one credit. The plugin is free and open source (GPL-3.0-or-later).

- Source code, releases and issues: https://github.com/EasyEmailVerification/plugin-mautic
- Composer package on Packagist: https://packagist.org/packages/easyemailverification/plugin-mautic
- Video: https://youtu.be/zIH7vHQawac

## Step 1: check the Mautic version

Ask which Mautic version they run (shown at the bottom of the Mautic menu, or in **Settings → System Info**).

- **Mautic 5, 6 or 7**: use plugin version **2.0.1** (the steps below).
- **Mautic 4.4**: use plugin version **1.0.2**: https://github.com/EasyEmailVerification/plugin-mautic/releases/download/v1.0.2/MauticEasyBundle-v1.0.2.zip, unzipped into `plugins/` instead of `docroot/plugins/`, then Steps 4 to 6 with an API key. Sign-in with OAuth needs 2.x.
- Older than 4.4: the plugin does not support it; they need to upgrade Mautic first.

Then say: "Once you know the version, type it here."

## Step 2: choose how Mautic signs in

Two options; they can change later:

- **Sign in with Easy Email Verification (OAuth)**: no key to copy. Mautic must be reachable over **https**. They need an Easy Email Verification account (create one at https://dashboard.easyemailverification.com/register?utm_source=mautic_md; the free plan includes 50 verifications a day, enough to test).
- **API key**: in the dashboard, open **Integration → API Settings** and click **Create API Key** (for example named `mautic`). It must be an API key, not a widget key. Remind them to treat it like a password: never paste it into this chat.

Then say: "Once you've chosen (and created the key, if you chose an API key), type "Yes" here."

## Step 3: install the plugin on the Mautic server

**With Composer** (Mautic installed with `mautic/recommended-project`), in the Mautic directory:

```sh
composer require easyemailverification/plugin-mautic:^2.0
php bin/console cache:clear
```

**From the zip file**:

1. Download the plugin: https://github.com/EasyEmailVerification/plugin-mautic/releases/download/v2.0.1/MauticEasyBundle-v2.0.1.zip
2. Copy it to the `docroot/plugins/` directory of the Mautic installation:

```sh
cp MauticEasyBundle-v2.0.1.zip /path/to/mautic/docroot/plugins/
```

With Docker (official image, container named `mautic`):

```sh
docker cp MauticEasyBundle-v2.0.1.zip mautic:/var/www/html/docroot/plugins/
```

3. In the `docroot/plugins/` directory, unzip it so the folder `MauticEasyBundle` is created, and give it to the web server user. If a `MauticEasyBundle` folder from version 1.x is already there, move it out of `plugins/` first:

```sh
unzip MauticEasyBundle-v2.0.1.zip
chown -R www-data:www-data MauticEasyBundle
```

4. Clear the Mautic cache:

```sh
php /var/www/html/bin/console cache:clear
```

(Adjust `/var/www/html` to their Mautic directory if it is not the Docker default. With Docker: `docker exec -u www-data mautic php /var/www/html/bin/console cache:clear`.)

Then say: "Once the cache is cleared, type "Yes" here."

## Step 4: enable and configure the plugin in Mautic

1. In Mautic, click the gear icon → **Plugins**.
2. Click **Install/Upgrade Plugins**. **Easy Email Verification** appears in the list.
3. Open it and set **Published** to *Yes*.
4. On the **Details** tab, under **Authentication**:
   - **API key**: choose *API key*, paste the key from Step 2 and click **Save & Close**.
   - **OAuth**: choose *Sign in with Easy Email Verification (OAuth)* and click **Authorize**. A window opens: they sign in to Easy Email Verification and click **Allow**. When it says Mautic is connected, they close it. If nothing opens, they allow pop-ups for Mautic.
5. On the **Features** tab, **Decide by**:
   - **Use the field 'Safe To Send' for decision** (default, stricter): passes only addresses that are safe to send to; disposable, catch-all, risky and unknown addresses take the "no" path.
   - **Use the field Result for decision**: passes everything except `invalid` and `unknown` (catch-all addresses, reported as valid, pass).
   - **Verify on contact save** (default *Yes*): contacts created or edited in Mautic's contact form are verified when they have no result yet or their email changed.
   - **Also verify on import/API** (default *No*): also verify contacts from imports, the API, forms and integrations. Warn them: each contact uses one credit, so a large import can use many credits.
6. Save.

Upgrading from 1.x keeps the saved API key and decision.

The plugin creates these contact fields to store the results: `eev_result`, `eev_reason`, `eev_disposable`, `eev_accept_all`, `eev_role`, `eev_free`, `eev_domain`, `eev_mx_record`, `eev_mx_domain`, `eev_safe_to_send`, `eev_did_you_mean`, `eev_success`, `eev_message`, `eev_http_code` and `eev_validate_time`.

Then say: "Once it's saved, type "Yes" here."

## Step 5: use it in a campaign

In the campaign builder, add the **Easy Email Verification** condition and connect both paths (for example, continue on "yes" and tag or remove on "no").

- A contact verified in the last 30 days is not verified again (no credit is used), unless the address moved to another domain.
- A contact without an email address, or any verification that fails (wrong key, revoked connection, no credits, timeout), takes the "no" path, keeps its fields unchanged and is logged.

To test without credits, use the **API key** method with the sandbox key `eev_sandbox_key` and contacts at `sandbox.easyemailverification.com`: `valid@`, `disposable@`, `catchall@`, `invalid@` and `unknown@`. With "Safe To Send" only `valid@` takes the "yes" path; with "Result", `valid@`, `disposable@` and `catchall@` do. Through OAuth, every verification uses real credits.

To verify one contact right away, they open it and click **Verify email now** (also in each row of the contact list; **Verify selected**, shown when contacts are selected in the list, verifies several at once and shows how many credits it uses).

## Reading the results

- **eev_result**: valid, invalid or unknown. unknown means no reliable answer; it is neither valid nor invalid.
- **eev_safe_to_send**: the overall recommendation.
- **eev_disposable, eev_role, eev_free, eev_accept_all**: risk signals.
- **eev_did_you_mean**: suggested fix for a typo.

## If something isn't working

1. The plugin does not appear: check the folder (`docroot/plugins/MauticEasyBundle`) and its owner, clear the cache again and click **Install/Upgrade Plugins**. Mautic 4.4 needs plugin 1.0.2.
2. Every contact takes the "no" path: look for "Easy Email Verification" in Mautic's log (`var/logs/`). "API error (HTTP 401)" means a wrong key (or a widget key); "not connected with OAuth" or "revoked or has expired" means they click **Authorize** again.
3. **Authorize** fails: Mautic must be served over https (behind a proxy, Mautic's `trusted_proxies` setting must include it).
4. "Running out of your credit limit": plans at https://www.easyemailverification.com/en-US/pricing
5. To disconnect OAuth: in the plugin settings set **Disconnect from Easy Email Verification** to *Yes* and save, or remove Mautic from **Connected apps** in the dashboard.

Otherwise point them to support@easyemailverification.com.

## Other integrations

- All integrations: https://www.easyemailverification.com/en-US/integrations
- AI integrations: https://www.easyemailverification.com/en-US/agents
