<?php

// Plugin activation hook - runs when plugin is first activated
register_activation_hook(__FILE__, 'ecf_activate_plugin');

function ecf_activate_plugin() {
    ecf_seed_default_config();
}

// Check for plugin updates on init
add_action('init', function () {
    $current_version = '0.1.0'; // Update this when you change defaultConfig.json
    $stored_version = get_option('ecf_plugin_version', '0.0.0');

    if (version_compare($stored_version, $current_version, '<')) {
        ecf_seed_default_config();
        update_option('ecf_plugin_version', $current_version, false);
    }
});

function ecf_seed_default_config() {
    $path = plugin_dir_path(__FILE__) . 'defaultConfig.json';
    if (!file_exists($path)) {
        return; // silently skip if not present
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return;
    }

    // Validate JSON before storing
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return; // don't store invalid JSON
    }

    // Store the raw JSON string so PHP/JS can decode consistently
    update_option('ecf_config_default', $raw, false);

    // Initialize empty form registry (no default form in registry)
    if (!get_option('ecf_forms')) {
        $forms = []; // Empty - users must create forms
        update_option('ecf_forms', $forms, false);
    }

    // Initialize global settings (add new fields if missing)
    $default_driver_config = ['smtp_host' => '', 'smtp_port' => 587, 'smtp_username' => '', 'smtp_password' => '', 'smtp_security' => 'tls'];
    $default_global_settings = [
        'recaptcha_site_key' => '',
        'recaptcha_secret_key' => '',
        'recaptcha_score_threshold' => 0.5,
        'recaptcha_enabled' => false,
        'mail_driver' => 'sendgrid',
        'driver_configs' => [
            'sendgrid'    => $default_driver_config,
            'mailpit'     => ['smtp_host' => 'host.docker.internal', 'smtp_port' => 1025, 'smtp_username' => '', 'smtp_password' => '', 'smtp_security' => 'none'],
            'custom_smtp' => $default_driver_config,
        ],
        'admin_emails' => '',
        'admin_emails_enabled' => false
    ];
    $existing_settings = get_option('ecf_global_settings');
    if (!$existing_settings) {
        update_option('ecf_global_settings', $default_global_settings, false);
    } else {
        // Add missing fields to existing settings
        $changed = false;
        foreach ($default_global_settings as $key => $val) {
            if (!array_key_exists($key, $existing_settings)) {
                $existing_settings[$key] = $val;
                $changed = true;
            }
        }
        // Migrate flat smtp_* fields into driver_configs['sendgrid']
        if (!empty($existing_settings['smtp_host']) && isset($existing_settings['driver_configs'])) {
            $existing_settings['driver_configs']['sendgrid'] = [
                'smtp_host'     => $existing_settings['smtp_host'],
                'smtp_port'     => $existing_settings['smtp_port'] ?? 587,
                'smtp_username' => $existing_settings['smtp_username'] ?? '',
                'smtp_password' => $existing_settings['smtp_password'] ?? '',
                'smtp_security' => $existing_settings['smtp_security'] ?? 'tls',
            ];
            $changed = true;
        }
        if ($changed) {
            update_option('ecf_global_settings', $existing_settings, false);
        }
    }
}

