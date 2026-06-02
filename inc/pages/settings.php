<?php
// Settings page (just settings)
function ecf_admin_settings_page($stab = 'mail') {
    if ($_POST && isset($_POST['ecf_nonce']) && wp_verify_nonce(wp_unslash($_POST['ecf_nonce']), 'ecf_settings')) {
        ecf_handle_admin_form_submission();
    }
    settings_errors('ecf_settings');
    $global_settings = get_option('ecf_global_settings', []);

    $base_url = '?page=enspyred-contact-forms&tab=settings';
    ?>
    <h2 class="nav-tab-wrapper" style="margin-bottom: 20px;">
        <a href="<?php echo esc_url($base_url . '&stab=mail'); ?>" class="nav-tab<?php echo $stab === 'mail' ? ' nav-tab-active' : ''; ?>">Mail</a>
        <a href="<?php echo esc_url($base_url . '&stab=security'); ?>" class="nav-tab<?php echo $stab === 'security' ? ' nav-tab-active' : ''; ?>">Security</a>
        <a href="<?php echo esc_url($base_url . '&stab=debug'); ?>" class="nav-tab<?php echo $stab === 'debug' ? ' nav-tab-active' : ''; ?>">Debug</a>
    </h2>

    <?php if ($stab === 'mail'): ?>

    <form method="post" action="">
        <?php wp_nonce_field('ecf_settings', 'ecf_nonce'); ?>
        <input type="hidden" name="action" value="update_mail">
        <h2>Mail Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="mail_driver">Mail Driver</label>
                </th>
                <td>
                    <select id="mail_driver" name="mail_driver" onchange="toggleMailSettings()">
                        <option value="custom" <?php selected($global_settings['mail_driver'] ?? 'custom', 'custom'); ?>>
                            Custom SMTP
                        </option>
                        <option value="mailgun" <?php selected($global_settings['mail_driver'] ?? 'custom', 'mailgun'); ?>>
                            Mailgun API
                        </option>
                    </select>
                    <p class="description">Custom SMTP handles Mailpit, standard SMTP, and any other SMTP server. Mailgun uses the Mailgun HTTP API.</p>
                </td>
            </tr>
        </table>

        <!-- Custom SMTP Settings -->
        <div id="custom-smtp-settings" style="display: <?php echo ($global_settings['mail_driver'] ?? 'custom') === 'custom' ? 'block' : 'none'; ?>;">
            <h3>Custom SMTP Configuration</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="smtp_host">SMTP Host</label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="smtp_host"
                            name="smtp_host"
                            value="<?php echo esc_attr($global_settings['smtp_host'] ?? ''); ?>"
                            class="regular-text"
                            placeholder="host.docker.internal"
                        />
                        <p class="description">
                            Mailpit local: <code>host.docker.internal</code> (Mac) / <code>172.17.0.1</code> (Linux) &mdash;
                            Mailpit remote: <code>mailpit.enspyred.com</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="smtp_port">SMTP Port</label>
                    </th>
                    <td>
                        <input
                            type="number"
                            id="smtp_port"
                            name="smtp_port"
                            value="<?php echo esc_attr($global_settings['smtp_port'] ?? '587'); ?>"
                            class="small-text"
                            min="1"
                            max="65535"
                        />
                        <p class="description">Mailpit: <code>1025</code> &mdash; Standard TLS: <code>587</code> &mdash; SSL: <code>465</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="smtp_security">Security</label>
                    </th>
                    <td>
                        <select id="smtp_security" name="smtp_security">
                            <option value="tls" <?php selected($global_settings['smtp_security'] ?? 'tls', 'tls'); ?>>
                                TLS (recommended)
                            </option>
                            <option value="ssl" <?php selected($global_settings['smtp_security'] ?? 'tls', 'ssl'); ?>>
                                SSL
                            </option>
                            <option value="none" <?php selected($global_settings['smtp_security'] ?? 'tls', 'none'); ?>>
                                None (use for Mailpit)
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="smtp_username">Username</label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="smtp_username"
                            name="smtp_username"
                            value="<?php echo esc_attr($global_settings['smtp_username'] ?? ''); ?>"
                            class="regular-text"
                            placeholder="Leave blank or use any value for Mailpit"
                        />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="smtp_password">Password</label>
                    </th>
                    <td>
                        <input
                            type="password"
                            id="smtp_password"
                            name="smtp_password"
                            value="<?php echo esc_attr($global_settings['smtp_password'] ?? ''); ?>"
                            class="regular-text"
                            placeholder="Leave blank or use any value for Mailpit"
                        />
                    </td>
                </tr>
            </table>
        </div>

        <!-- Mailgun API Settings -->
        <div id="mailgun-settings" style="display: <?php echo ($global_settings['mail_driver'] ?? 'custom') === 'mailgun' ? 'block' : 'none'; ?>;">
            <h3>Mailgun API Configuration</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="mailgun_api_key">API Key</label>
                    </th>
                    <td>
                        <div style="position: relative; display: inline-block;">
                            <input
                                type="password"
                                id="mailgun_api_key"
                                name="mailgun_api_key"
                                value="<?php echo esc_attr($global_settings['mailgun_api_key'] ?? ''); ?>"
                                class="regular-text"
                                style="padding-right: 40px;"
                            />
                            <button
                                type="button"
                                id="toggle_mailgun_api_key"
                                style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #666; font-size: 14px; padding: 0; width: 20px; height: 20px;"
                                title="Show/Hide API Key"
                                onclick="toggleMailgunApiKeyVisibility()"
                            >
                                👁️
                            </button>
                        </div>
                        <p class="description">Your Mailgun API key (starts with "key-")</p>
                        <script>
                        function toggleMailgunApiKeyVisibility() {
                            const input = document.getElementById('mailgun_api_key');
                            const button = document.getElementById('toggle_mailgun_api_key');
                            if (input.type === 'password') {
                                input.type = 'text';
                                button.innerHTML = '🙈';
                                button.title = 'Hide API Key';
                            } else {
                                input.type = 'password';
                                button.innerHTML = '👁️';
                                button.title = 'Show API Key';
                            }
                        }
                        </script>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mailgun_domain">Domain</label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="mailgun_domain"
                            name="mailgun_domain"
                            value="<?php echo esc_attr($global_settings['mailgun_domain'] ?? ''); ?>"
                            class="regular-text"
                            placeholder="mg.example.com"
                        />
                        <p class="description">Your Mailgun sending domain</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mailgun_region">Region</label>
                    </th>
                    <td>
                        <select id="mailgun_region" name="mailgun_region">
                            <option value="us" <?php selected($global_settings['mailgun_region'] ?? 'us', 'us'); ?>>
                                US (api.mailgun.net)
                            </option>
                            <option value="eu" <?php selected($global_settings['mailgun_region'] ?? 'us', 'eu'); ?>>
                                EU (api.eu.mailgun.net)
                            </option>
                        </select>
                    </td>
                </tr>
            </table>
        </div>

        <script>
        function toggleMailSettings() {
            const driver = document.getElementById('mail_driver').value;
            document.getElementById('custom-smtp-settings').style.display = driver === 'custom'  ? 'block' : 'none';
            document.getElementById('mailgun-settings').style.display     = driver === 'mailgun' ? 'block' : 'none';
        }
        </script>

        <h2>Admin Email BCC</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="admin_emails_enabled">Enable Admin BCC</label>
                </th>
                <td>
                    <input
                        type="checkbox"
                        id="admin_emails_enabled"
                        name="admin_emails_enabled"
                        value="1"
                        <?php checked($global_settings['admin_emails_enabled'] ?? false); ?>
                    />
                    <label for="admin_emails_enabled">Send BCC to admin emails on all form submissions</label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="admin_emails">Admin Emails</label>
                </th>
                <td>
                    <input
                        type="text"
                        id="admin_emails"
                        name="admin_emails"
                        value="<?php echo esc_attr($global_settings['admin_emails'] ?? ''); ?>"
                        class="regular-text"
                        placeholder="admin1@example.com, admin2@example.com"
                    />
                    <p class="description">Comma-separated list of emails to BCC on all form submissions</p>
                </td>
            </tr>
        </table>

        <?php submit_button('Save Mail Settings'); ?>
    </form>

    <?php elseif ($stab === 'security'): ?>

    <form method="post" action="">
        <?php wp_nonce_field('ecf_settings', 'ecf_nonce'); ?>
        <input type="hidden" name="action" value="update_recaptcha">
        <h2>reCAPTCHA Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="recaptcha_site_key">Site Key</label>
                </th>
                <td>
                    <input
                        type="text"
                        id="recaptcha_site_key"
                        name="recaptcha_site_key"
                        value="<?php echo esc_attr($global_settings['recaptcha_site_key'] ?? ''); ?>"
                        class="regular-text"
                    />
                    <p class="description">Your reCAPTCHA v3 site key</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="recaptcha_secret_key">Secret Key</label>
                </th>
                <td>
                    <div style="position: relative; display: inline-block;">
                        <input
                            type="password"
                            id="recaptcha_secret_key"
                            name="recaptcha_secret_key"
                            value="<?php echo esc_attr($global_settings['recaptcha_secret_key'] ?? ''); ?>"
                            class="regular-text"
                            style="padding-right: 40px;"
                        />
                        <button
                            type="button"
                            id="toggle_secret_key"
                            style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #666; font-size: 14px; padding: 0; width: 20px; height: 20px;"
                            title="Show/Hide Secret Key"
                            onclick="toggleSecretKeyVisibility()"
                        >
                            👁️
                        </button>
                    </div>
                    <p class="description">Your reCAPTCHA v3 secret key</p>
                    <script>
                    function toggleSecretKeyVisibility() {
                        const input = document.getElementById('recaptcha_secret_key');
                        const button = document.getElementById('toggle_secret_key');
                        if (input.type === 'password') {
                            input.type = 'text';
                            button.innerHTML = '🙈';
                            button.title = 'Hide Secret Key';
                        } else {
                            input.type = 'password';
                            button.innerHTML = '👁️';
                            button.title = 'Show Secret Key';
                        }
                    }
                    </script>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="recaptcha_score_threshold">Score Threshold</label>
                </th>
                <td>
                    <input
                        type="number"
                        id="recaptcha_score_threshold"
                        name="recaptcha_score_threshold"
                        value="<?php echo esc_attr($global_settings['recaptcha_score_threshold'] ?? '0.5'); ?>"
                        min="0"
                        max="1"
                        step="0.1"
                        class="small-text"
                    />
                    <p class="description">Minimum score required (0.0 - 1.0). Lower = more strict</p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="recaptcha_enabled">Enable reCAPTCHA</label>
                </th>
                <td>
                    <input
                        type="checkbox"
                        id="recaptcha_enabled"
                        name="recaptcha_enabled"
                        value="1"
                        <?php checked($global_settings['recaptcha_enabled'] ?? false); ?>
                    />
                    <label for="recaptcha_enabled">Enable reCAPTCHA protection on all forms</label>
                </td>
            </tr>
        </table>
        <?php submit_button('Save Security Settings'); ?>
    </form>

    <?php elseif ($stab === 'debug'): ?>

    <form method="post" action="">
        <?php wp_nonce_field('ecf_settings', 'ecf_nonce'); ?>
        <input type="hidden" name="action" value="update_debug">
        <h2>Debug Settings</h2>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="debug_mode">Enable Debug Logging</label>
                </th>
                <td>
                    <input
                        type="checkbox"
                        id="debug_mode"
                        name="debug_mode"
                        value="1"
                        <?php checked($global_settings['debug_mode'] ?? false); ?>
                    />
                    <label for="debug_mode">Enable console logging for debugging</label>
                    <p class="description">When enabled, the plugin outputs debug information to the server log</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Save Debug Settings'); ?>
    </form>

    <?php endif; ?>
    <?php
}
