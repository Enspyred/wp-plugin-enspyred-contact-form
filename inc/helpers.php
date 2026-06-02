<?php
/**
 * Debug Logging Helper
 */
if (!function_exists('enspyred_log')) {
    function enspyred_log($message) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            // Force logs directly to Docker stderr
            file_put_contents('php://stderr', '[ECF] ' . $message . PHP_EOL);
            // Also use error_log as backup
            error_log('[ECF] ' . $message);
        }
    }
}

/**
 * Helper to find all descendants of a form (to prevent circular parent references)
 */
function ecf_find_descendants($forms, $start_id) {
    $descendants = [];
    foreach ($forms as $id => $f) {
        if (isset($f['parent_id']) && $f['parent_id'] == $start_id) {
            $descendants[] = $id;
            $descendants = array_merge($descendants, ecf_find_descendants($forms, $id));
        }
    }
    return $descendants;
}

// Generate unique slug for forms
function ecf_generate_unique_slug($name, $exclude_id = null) {
    $base_slug = sanitize_title($name);
    $slug = $base_slug;
    $counter = 1;

    $forms = get_option('ecf_forms', []);

    while (true) {
        $slug_exists = false;
        foreach ($forms as $id => $form) {
            if ($id !== $exclude_id && $form['slug'] === $slug) {
                $slug_exists = true;
                break;
            }
        }

        if (!$slug_exists) {
            break;
        }

        $counter++;
        $slug = $base_slug . '-' . $counter;
    }

    return $slug;
}

// Helper to create new forms
function ecf_create_form($name, $config_json = null) {
    $forms = get_option('ecf_forms', []);

    // Get next numeric ID (start from 2 since default is not numbered)
    $numeric_ids = array_filter(array_keys($forms), 'is_numeric');
    $next_id = empty($numeric_ids) ? 2 : max($numeric_ids) + 1;

    // Use default config if none provided
    if (!$config_json) {
        $config_json = get_option('ecf_config_default', '{}');
    }

    // Generate unique slug
    $slug = ecf_generate_unique_slug($name);

    // Store form config
    update_option('ecf_config_' . $next_id, $config_json, false);

    // Update registry
    $forms[$next_id] = [
        'id' => $next_id,
        'name' => sanitize_text_field($name),
        'slug' => $slug,
        'created' => current_time('mysql')
    ];
    update_option('ecf_forms', $forms, false);

    return $next_id;
}

// Helper to delete forms
function ecf_delete_form($form_id) {
    // Remove form config
    delete_option('ecf_config_' . $form_id);

    // Remove from registry
    $forms = get_option('ecf_forms', []);
    unset($forms[$form_id]);
    update_option('ecf_forms', $forms, false);
}
