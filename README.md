# Mautic Easy Email Verification Plugin

This plugin integrates Mautic with the Easy Email Verification service to verify email addresses within your Mautic campaigns.

## More Information

For more information about the Easy Email Verification service, please visit: [Easy Email Verification](https://www.easyemailverification.com)

## Installation

Follow these steps to install the Mautic Easy Email Verification plugin:

1. Copy the zip file to the `plugins/` directory of your Mautic installation.

    ```sh
    cp MauticEasyBundle.zip /path/to/mautic/plugins/
    ```

    If you are using Docker:

    ```sh
    docker cp MauticEasyBundle.zip mautic:/var/www/html/plugins
    ```

2. Unzip the plugin file.

    ```sh
    unzip MauticEasyBundle.zip
    ```

3. Set the correct permissions for the plugin directory.

    ```sh
    chown -R www-data:www-data MauticEasyBundle
    chmod 775 -R MauticEasyBundle
    ```

4. Clear the Mautic cache to recognize the new plugin.

    ```sh
    cd /path/to/mautic
    php /var/www/html/bin/console cache:clear --env=prod
    ```
    
5. Install the plugin via the Mautic UI.

    - Go to the Mautic UI.
    - Click the gear icon and select "Plugins" (or go to [http://localhost:8080/s/plugins](http://localhost:8080/s/plugins)).
    - Click the "Install/Upgrade Plugins" button.


## Usage

Once installed, the Easy Email Verification plugin will add new conditions to your Mautic campaigns. You can use these to verify email addresses and make decisions based on the verification results.

### Free Email Verification

When you create an account at [Easy Email Verification Dashboard](https://dashboard.easyemailverification.com/login), you will receive 50 free email verifications. Use these to test and validate the integration with your Mautic instance.

### Generating an API Key

To use the plugin, you need to generate an API Key:

1. Log in to your account at the [Easy Email Verification Dashboard](https://dashboard.easyemailverification.com).
2. Navigate to the API section.
3. Generate a new API key.
4. Copy the API key and paste it into the plugin interface within your Mautic instance.

### Custom Fields

The plugin creates the following custom fields in Mautic to store the results of email verifications:

- `eev_result`
- `eev_reason`
- `eev_disposable`
- `eev_accept_all`
- `eev_role`
- `eev_free`
- `eev_domain`
- `eev_mx_record`
- `eev_mx_domain`
- `eev_safe_to_send`
- `eev_did_you_mean`
- `eev_success`
- `eev_message`
- `eev_http_code`
- `eev_validate_time`

### Email Verification Conditions

You can add conditions related to email verification in the campaign builder to check if an email is verified using the Easy Email Verification service.

### Validity Criteria

The plugin allows you to decide whether an email will be considered valid based on either the `result` field or the `safe_to_send` field:

- **Result**: This field indicates the overall result of the email verification. A value of 'valid' means the email address is considered valid by the verification service. An email accept_all is considered valid.
- **Safe to Send**: This field indicates whether the email address is safe to send emails to. It considers factors such as deliverability and risk of the email address.

## Troubleshooting

If you encounter any issues with the plugin, please ensure that:

1. The plugin directory has the correct permissions.
2. The Mautic cache has been cleared.
3. The Easy Email Verification service API key is correctly configured in the plugin settings.

For additional support, refer to the Easy Email Verification service documentation or the Mautic community forums.
