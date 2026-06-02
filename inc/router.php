<?php

// Router for Contact Forms tab navigation
function ecf_admin_contact_forms_router() {
    $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'forms';
    // Handle form submissions
    if ($_POST && isset($_POST['ecf_nonce']) && wp_verify_nonce(wp_unslash($_POST['ecf_nonce']), 'ecf_settings')) {
        ecf_handle_admin_form_submission();
    }
    // Check if we're editing a specific form
    $editing_form = isset($_GET['edit']) ? sanitize_text_field(wp_unslash($_GET['edit'])) : null;
    if ($editing_form) {
        ecf_admin_edit_form_page($editing_form);
        return;
    }
    echo '<div class="wrap">';
    echo '<h1>Contact Forms</h1>';
    echo '<nav class="nav-tab-wrapper">';
    echo '<a href="?page=enspyred-contact-forms&tab=forms" class="nav-tab' . ($tab === 'forms' ? ' nav-tab-active' : '') . '">Contact Forms</a>';
    echo '<a href="?page=enspyred-contact-forms&tab=settings" class="nav-tab' . ($tab === 'settings' ? ' nav-tab-active' : '') . '">Settings</a>';
    echo '</nav>';
    echo '<div style="margin-top: 30px;">';
    switch ($tab) {
        case 'settings':
            $stab = isset($_GET['stab']) ? sanitize_text_field(wp_unslash($_GET['stab'])) : 'mail';
            ecf_admin_settings_page($stab);
            break;
        default:
            ecf_admin_contact_forms_page();
            break;
    }
    echo '</div>';
    echo '</div>';
}

// Handle admin form submissions
function ecf_handle_admin_form_submission() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $action = isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '';

    switch ($action) {
        case 'update_recaptcha':
            $global_settings = get_option('ecf_global_settings', []);

            $global_settings['recaptcha_site_key'] = isset($_POST['recaptcha_site_key']) ? sanitize_text_field(wp_unslash($_POST['recaptcha_site_key'])) : '';
            $global_settings['recaptcha_secret_key'] = isset($_POST['recaptcha_secret_key']) ? sanitize_text_field(wp_unslash($_POST['recaptcha_secret_key'])) : '';
            $global_settings['recaptcha_score_threshold'] = isset($_POST['recaptcha_score_threshold']) ? floatval(wp_unslash($_POST['recaptcha_score_threshold'])) : 0.5;
            $global_settings['recaptcha_enabled'] = !empty($_POST['recaptcha_enabled']);

            // Admin BCC fields (may be present on this form)
            $global_settings['admin_emails'] = isset($_POST['admin_emails']) ? sanitize_text_field(wp_unslash($_POST['admin_emails'])) : ($global_settings['admin_emails'] ?? '');
            $global_settings['admin_emails_enabled'] = !empty($_POST['admin_emails_enabled']);

            update_option('ecf_global_settings', $global_settings, false);

            add_settings_error('ecf_settings', 'recaptcha_updated', 'reCAPTCHA settings saved.', 'updated');
            break;

        case 'update_mail':
            $global_settings = get_option('ecf_global_settings', []);

            $global_settings['mail_driver'] = isset($_POST['mail_driver']) ? sanitize_text_field(wp_unslash($_POST['mail_driver'])) : 'custom';
            $global_settings['mailgun_api_key'] = isset($_POST['mailgun_api_key']) ? sanitize_text_field(wp_unslash($_POST['mailgun_api_key'])) : '';
            $global_settings['mailgun_domain'] = isset($_POST['mailgun_domain']) ? sanitize_text_field(wp_unslash($_POST['mailgun_domain'])) : '';
            $global_settings['mailgun_region'] = isset($_POST['mailgun_region']) ? sanitize_text_field(wp_unslash($_POST['mailgun_region'])) : 'us';
            $global_settings['smtp_host'] = isset($_POST['smtp_host']) ? sanitize_text_field(wp_unslash($_POST['smtp_host'])) : '';
            $global_settings['smtp_port'] = isset($_POST['smtp_port']) ? intval(wp_unslash($_POST['smtp_port'])) : 587;
            $global_settings['smtp_username'] = isset($_POST['smtp_username']) ? sanitize_text_field(wp_unslash($_POST['smtp_username'])) : '';
            $global_settings['smtp_password'] = isset($_POST['smtp_password']) ? sanitize_text_field(wp_unslash($_POST['smtp_password'])) : '';
            $global_settings['smtp_security'] = isset($_POST['smtp_security']) ? sanitize_text_field(wp_unslash($_POST['smtp_security'])) : 'tls';

            // Admin BCC fields (may be present on this form)
            $global_settings['admin_emails'] = isset($_POST['admin_emails']) ? sanitize_text_field(wp_unslash($_POST['admin_emails'])) : ($global_settings['admin_emails'] ?? '');
            $global_settings['admin_emails_enabled'] = !empty($_POST['admin_emails_enabled']);

            update_option('ecf_global_settings', $global_settings, false);

            add_settings_error('ecf_settings', 'mail_updated', 'Mail settings saved.', 'updated');
            break;

        case 'update_debug':
            $global_settings = get_option('ecf_global_settings', []);
            $global_settings['debug_mode'] = !empty($_POST['debug_mode']);
            update_option('ecf_global_settings', $global_settings, false);
            add_settings_error('ecf_settings', 'debug_updated', 'Debug settings saved.', 'updated');
            break;

        case 'create_form':
            $form_name = isset($_POST['new_form_name']) ? sanitize_text_field(wp_unslash($_POST['new_form_name'])) : '';
            if (!empty($form_name)) {
                $form_id = ecf_create_form($form_name);
                add_settings_error('ecf_settings', 'form_created', "Form '{$form_name}' created with ID {$form_id}.", 'updated');
            }
            break;

        case 'delete_form':
            $form_id = isset($_POST['form_id']) ? sanitize_text_field(wp_unslash($_POST['form_id'])) : '';
            if ($form_id) {
                ecf_delete_form($form_id);
                add_settings_error('ecf_settings', 'form_deleted', 'Form deleted.', 'updated');
            }
            break;

    }
}