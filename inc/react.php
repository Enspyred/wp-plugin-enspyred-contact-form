<?php
// React Scripts
function ecf_enqueue_react_assets() {
    try {
        // Use the main plugin file to get the correct plugin root
        $plugin_file = dirname(__DIR__) . '/enspyred-contact-form.php';
        $base = plugin_dir_path($plugin_file);
        $uri  = plugin_dir_url($plugin_file);

        // Get global settings for reCAPTCHA
        $global_settings = get_option('ecf_global_settings', []);
        $recaptcha_enabled = $global_settings['recaptcha_enabled'] ?? false;
        $recaptcha_site_key = $global_settings['recaptcha_site_key'] ?? '';

        // Load reCAPTCHA script if enabled and site key exists
        if ($recaptcha_enabled && !empty($recaptcha_site_key)) {
            wp_enqueue_script(
                'google-recaptcha-v3',
                'https://www.google.com/recaptcha/api.js?render=' . esc_attr($recaptcha_site_key),
                [],
                null,
                true // Load in footer
            );
        }

        $manifest_path = $base . '/build/.vite/manifest.json';
        if ( ! file_exists($manifest_path) ) {
            enspyred_log('React: Manifest missing at ' . $manifest_path);
            return; // build not present yet
        }

        $manifest_raw = file_get_contents($manifest_path);
        $manifest = json_decode($manifest_raw, true);
        if ( ! is_array($manifest) ) {
            enspyred_log('React: Manifest invalid JSON');
            return; // invalid manifest
        }

        $entries = array_values(array_filter($manifest, fn($v) => !empty($v['isEntry'])));
        if ( empty($entries) ) {
            enspyred_log('React: No entry found in manifest');
            return; // nothing to enqueue
        }

        $entry = $entries[0];
        wp_enqueue_script(
            'ecf-app',
            $uri . 'build/' . $entry['file'],
            [],
            null,
            [ 'in_footer' => true ]
        );

        if (!empty($entry['css'])) {
            foreach ($entry['css'] as $css) {
                wp_enqueue_style('ecf-style-' . md5($css), $uri . 'build/' . $css, [], null);
            }
        }

        // Provide REST info to JS (for fetching config JSON)
        wp_localize_script('ecf-app', 'ECF_DATA', [
            'root'  => esc_url_raw( rest_url('enspyred-contact-form/v1/') ),
            'nonce' => wp_create_nonce('wp_rest'),
            'debug' => !empty($global_settings['debug_mode']),
        ]);
    } catch (Throwable $e) {
        enspyred_log('React: Enqueue error - ' . $e->getMessage());
    }
}

add_action('wp_enqueue_scripts', 'ecf_enqueue_react_assets');
add_action('admin_enqueue_scripts', 'ecf_enqueue_react_assets');

// Force module type on our app bundles (plugin + theme) even if ordering prevents wp_script_add_data from applying.
add_filter('script_loader_tag', function ($tag, $handle, $src) {
    $module_handles = ['ecf-app', 'enspyred-theme-app'];
    if (in_array($handle, $module_handles, true)) {
        // If no type attribute, inject it; if present but classic, replace it.
        if (strpos($tag, ' type=') === false) {
            $tag = str_replace('<script ', '<script type="module" ', $tag);
        } else {
            $tag = preg_replace('/type=("|\')text\/javascript\1/i', 'type="module"', $tag);
        }
    }
    return $tag;
}, 10, 3);



// filepath: inc/react.php
add_action('wp_footer', function () {
    global $wp_scripts;
    echo '<!-- Enqueued scripts: ' . esc_html(implode(', ', $wp_scripts->queue)) . ' -->';
});

