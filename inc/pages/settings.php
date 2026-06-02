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
                    <select id="mail_driver" name="mail_driver">
                        <option value="sendgrid" <?php selected($global_settings['mail_driver'] ?? 'sendgrid', 'sendgrid'); ?>>
                            Sendgrid
                        </option>
                        <option value="mailpit" <?php selected($global_settings['mail_driver'] ?? 'sendgrid', 'mailpit'); ?>>
                            Mailpit
                        </option>
                        <option value="custom_smtp" <?php selected($global_settings['mail_driver'] ?? 'sendgrid', 'custom_smtp'); ?>>
                            Custom SMTP
                        </option>
                    </select>
                    <p class="description">Configure credentials for each driver below. Switch drivers freely without losing saved credentials.</p>
                </td>
            </tr>
        </table>

        <?php
        $drivers = [
            'sendgrid'   => 'Sendgrid',
            'mailpit'    => 'Mailpit',
            'custom_smtp'=> 'Custom SMTP',
        ];
        $driver_placeholders = [
            'sendgrid'    => ['host' => 'smtp.sendgrid.net',        'port' => '587',  'user' => 'apikey',  'pass' => 'Your Sendgrid API key'],
            'mailpit'     => ['host' => 'host.docker.internal',     'port' => '1025', 'user' => '',        'pass' => ''],
            'custom_smtp' => ['host' => 'smtp.example.com',         'port' => '587',  'user' => '',        'pass' => ''],
        ];
        foreach ($drivers as $driver_key => $driver_label):
            $cfg = $global_settings['driver_configs'][$driver_key] ?? [];
            $ph  = $driver_placeholders[$driver_key];
        ?>
        <h3><?php echo esc_html($driver_label); ?> Configuration</h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label>SMTP Host</label></th>
                <td>
                    <input type="text" name="driver_configs[<?php echo esc_attr($driver_key); ?>][smtp_host]"
                        value="<?php echo esc_attr($cfg['smtp_host'] ?? ''); ?>"
                        class="regular-text" placeholder="<?php echo esc_attr($ph['host']); ?>" />
                    <?php if ($driver_key === 'mailpit'): ?>
                    <p class="description">Local: <code>host.docker.internal</code> (Mac) / <code>172.17.0.1</code> (Linux)</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>SMTP Port</label></th>
                <td>
                    <input type="number" name="driver_configs[<?php echo esc_attr($driver_key); ?>][smtp_port]"
                        value="<?php echo esc_attr($cfg['smtp_port'] ?? $ph['port']); ?>"
                        class="small-text" min="1" max="65535" />
                    <?php if ($driver_key === 'mailpit'): ?>
                    <p class="description">Default: <code>1025</code></p>
                    <?php else: ?>
                    <p class="description">TLS: <code>587</code> &mdash; SSL: <code>465</code></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Security</label></th>
                <td>
                    <select name="driver_configs[<?php echo esc_attr($driver_key); ?>][smtp_security]">
                        <option value="tls"  <?php selected($cfg['smtp_security'] ?? ($driver_key === 'mailpit' ? 'none' : 'tls'), 'tls'); ?>>TLS (recommended)</option>
                        <option value="ssl"  <?php selected($cfg['smtp_security'] ?? 'tls', 'ssl'); ?>>SSL</option>
                        <option value="none" <?php selected($cfg['smtp_security'] ?? ($driver_key === 'mailpit' ? 'none' : 'tls'), 'none'); ?>>None</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Username</label></th>
                <td>
                    <input type="text" name="driver_configs[<?php echo esc_attr($driver_key); ?>][smtp_username]"
                        value="<?php echo esc_attr($cfg['smtp_username'] ?? ''); ?>"
                        class="regular-text" placeholder="<?php echo esc_attr($ph['user']); ?>" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label>Password</label></th>
                <td>
                    <input type="password" name="driver_configs[<?php echo esc_attr($driver_key); ?>][smtp_password]"
                        value="<?php echo esc_attr($cfg['smtp_password'] ?? ''); ?>"
                        class="regular-text" placeholder="<?php echo esc_attr($ph['pass']); ?>" />
                </td>
            </tr>
        </table>
        <?php endforeach; ?>

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
