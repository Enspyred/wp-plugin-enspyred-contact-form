<?php
// Handle edit form submissions
function ecf_handle_edit_form_submission($form_id) {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $forms = get_option('ecf_forms', []);
    if (!isset($forms[$form_id])) {
        wp_die('Form not found.');
    }

    $new_name = isset($_POST['form_name']) ? sanitize_text_field(wp_unslash($_POST['form_name'])) : '';
    $new_config = isset($_POST['form_config']) ? wp_unslash($_POST['form_config']) : '';
    $new_parent_id = isset($_POST['parent_id']) ? sanitize_text_field(wp_unslash($_POST['parent_id'])) : '';

    if (empty($new_name)) {
        add_settings_error('ecf_edit_form', 'name_required', 'Form name is required.', 'error');
        return;
    }

    // Validate JSON
    $decoded_config = json_decode($new_config, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        add_settings_error('ecf_edit_form', 'invalid_json', 'Invalid JSON configuration: ' . json_last_error_msg(), 'error');
        return;
    }

    $new_config = wp_json_encode($decoded_config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    // Update form name and slug
    $old_name = $forms[$form_id]['name'];
    $forms[$form_id]['name'] = $new_name;
    // Only update slug if name changed
    if ($old_name !== $new_name) {
        $new_slug = ecf_generate_unique_slug($new_name, $form_id);
        $forms[$form_id]['slug'] = $new_slug;
    }
    // Prevent circular parent references: parent cannot be self or descendant
    if ($new_parent_id && ($new_parent_id == $form_id || in_array($new_parent_id, ecf_find_descendants($forms, $form_id), true))) {
        add_settings_error('ecf_edit_form', 'invalid_parent', 'Invalid parent config: cannot select self or descendant.', 'error');
        return;
    }
    $forms[$form_id]['parent_id'] = $new_parent_id ? $new_parent_id : null;

    // Update config
    update_option('ecf_config_' . $form_id, $new_config, false);
    update_option('ecf_forms', $forms, false);

    add_settings_error('ecf_edit_form', 'form_updated', 'Form updated successfully.', 'updated');
}


// Edit form page
function ecf_admin_edit_form_page($form_id) {
    // Handle form submissions
    if ($_POST && isset($_POST['ecf_nonce']) && wp_verify_nonce(wp_unslash($_POST['ecf_nonce']), 'ecf_edit_form')) {
        ecf_handle_edit_form_submission($form_id);
    }

    $forms = get_option('ecf_forms', []);
    if (!isset($forms[$form_id])) {
        wp_die('Form not found.');
    }

    $form = $forms[$form_id];

    // Get config for the specific form
    $config_raw = get_option('ecf_config_' . $form_id, '{}');
    // Prettify JSON for editing
    $config_json = json_encode(json_decode($config_raw, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    // Enqueue CodeMirror JSON editor (bundled with WordPress)
    $editor_settings = wp_enqueue_code_editor(['type' => 'application/json']);
    wp_enqueue_script('wp-theme-plugin-editor');
    wp_enqueue_style('wp-codemirror');

    // Build parent config dropdown (exclude self and descendants, sort by name)
    $parent_id = isset($form['parent_id']) ? $form['parent_id'] : '';
    $descendants = ecf_find_descendants($forms, $form_id);
    // Filter out self and descendants first
    $parent_candidates = array_filter($forms, function($f, $id) use ($form_id, $descendants) {
        return $id !== $form_id && !in_array($id, $descendants, true);
    }, ARRAY_FILTER_USE_BOTH);
    // Sort by name (alphanumeric)
    uasort($parent_candidates, function($a, $b) {
        return strnatcasecmp($a['name'], $b['name']);
    });

    settings_errors('ecf_edit_form');
    ?>
    <style>
    .json-editor-container {
        position: relative;
        border: 1px solid #ccd0d4;
        border-radius: 4px;
        background: #fff;
    }

    .json-editor-toolbar {
        background: #f6f7f7;
        border-bottom: 1px solid #ccd0d4;
        padding: 8px 12px;
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .json-editor-textarea {
        font-family: Consolas, Monaco, 'Courier New', monospace !important;
        font-size: 13px !important;
        line-height: 1.4 !important;
        border: none !important;
        resize: vertical !important;
        padding: 15px !important;
        background: #fff !important;
        border-radius: 0 0 4px 4px !important;
    }

    .json-error {
        color: #d63638;
        background: #fcf0f1;
        border: 1px solid #d63638;
        padding: 8px 12px;
        border-radius: 4px;
        margin-top: 5px;
        display: none;
    }
    </style>

    <div class="wrap">
        <h1>Edit Form: <?php echo esc_html($form['name']); ?></h1>

        <p><a href="?page=enspyred-contact-forms">&larr; Back to Contact Forms</a></p>        <form method="post" action="">
            <?php wp_nonce_field('ecf_edit_form', 'ecf_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="parent_id">Parent Config</label>
                    </th>
                    <td>
                        <select id="parent_id" name="parent_id">
                            <option value="">(None)</option>
                            <?php foreach ($parent_candidates as $id => $f): ?>
                                <option value="<?php echo esc_attr($id); ?>" <?php selected($parent_id, $id); ?>>
                                    <?php echo esc_html($f['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Select a parent config to inherit from. Only non-descendant forms are shown.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="form_name">Form Name</label>
                    </th>
                    <td>
                        <input
                            type="text"
                            id="form_name"
                            name="form_name"
                            value="<?php echo esc_attr($form['name']); ?>"
                            class="regular-text"
                            required
                        />
                        <p class="description">
                            Current slug: <code><?php echo esc_html($form['slug']); ?></code>
                            <?php if ($form_id !== 'default'): ?>
                            <br><em>Changing the name will update the slug and shortcode</em>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <td colspan="2"><h2 style="margin: 20px 0 5px; padding-bottom: 8px; border-bottom: 1px solid #ccd0d4;">JSON Configuration</h2></td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="form_config">Form Configuration</label>
                    </th>
                    <td>
                        <div class="json-editor-container">
                            <div class="json-editor-toolbar">
                                <button type="button" id="format-json" class="button button-small">Format JSON</button>
                                <button type="button" id="validate-json" class="button button-small">Validate</button>
                                <span id="json-status" style="color: #008a00; font-size: 12px;"></span>
                            </div>
                            <textarea
                                id="form_config"
                                name="form_config"
                                rows="25"
                                class="json-editor-textarea"
                                style="width: 100%;"
                                required
                            ><?php echo esc_textarea($config_json); ?></textarea>
                        </div>
                        <div id="json-error" class="json-error"></div>
                        <p class="description">Edit the JSON configuration for this form.</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <?php submit_button('Save Changes', 'primary', 'submit', false); ?>
                <a href="?page=enspyred-contact-forms" class="button button-secondary" style="margin-left: 10px;">Cancel</a>
            </p>
        </form>

        <div style="margin-top: 30px; padding: 15px; background: #f9f9f9; border-radius: 5px;">
            <h3>Shortcode Usage</h3>
            <p>Use this shortcode to display this form:</p>
            <p><code>[enspyred_contact_form form="<?php echo esc_attr($form['slug']); ?>"]</code></p>
            <button type="button" onclick="navigator.clipboard.writeText('[enspyred_contact_form form=&quot;<?php echo esc_attr($form['slug']); ?>&quot;]')" class="button button-small">Copy Shortcode</button>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const textarea  = document.getElementById('form_config');
            const formatBtn  = document.getElementById('format-json');
            const validateBtn = document.getElementById('validate-json');
            const statusSpan = document.getElementById('json-status');
            const errorDiv   = document.getElementById('json-error');

            const editorSettings = <?php echo wp_json_encode($editor_settings); ?>;
            const useCodeMirror  = editorSettings !== false && typeof wp !== 'undefined' && wp.codeEditor;

            let cm = null;

            function getValue() { return useCodeMirror ? cm.getValue() : textarea.value; }
            function setValue(v) { useCodeMirror ? cm.setValue(v) : (textarea.value = v); }

            function validateJSON() {
                try {
                    const parsed = JSON.parse(getValue());
                    statusSpan.textContent = '✓ Valid JSON';
                    statusSpan.style.color = '#008a00';
                    errorDiv.style.display = 'none';
                    return parsed;
                } catch (e) {
                    statusSpan.textContent = '✗ Invalid JSON';
                    statusSpan.style.color = '#d63638';
                    errorDiv.textContent = 'JSON Error: ' + e.message;
                    errorDiv.style.display = 'block';
                    return null;
                }
            }

            formatBtn.onclick = function() {
                const parsed = validateJSON();
                if (parsed) {
                    setValue(JSON.stringify(parsed, null, 2));
                    statusSpan.textContent = '✓ Formatted';
                }
            };

            validateBtn.onclick = validateJSON;

            if (useCodeMirror) {
                const instance = wp.codeEditor.initialize(textarea, editorSettings);
                cm = instance.codemirror;
                cm.on('change', validateJSON);
                // Sync CodeMirror value back to textarea before form submit
                document.querySelector('form').addEventListener('submit', function() {
                    textarea.value = cm.getValue();
                });
            } else {
                textarea.addEventListener('input', function() {
                    clearTimeout(this.validateTimeout);
                    this.validateTimeout = setTimeout(validateJSON, 500);
                });
            }

            validateJSON();
        });
        </script>
    </div>
    <?php
}