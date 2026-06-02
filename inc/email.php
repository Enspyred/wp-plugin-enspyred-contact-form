<?php
class ValidationException extends Exception {}

function configure_smtp($phpmailer) {
    enspyred_log("Running configure_smtp");

    $global_settings = get_option('ecf_global_settings', []);
    $mail_driver = $global_settings['mail_driver'] ?? 'sendgrid';
    enspyred_log("MAIL_DRIVER is: " . $mail_driver);

    $cfg = $global_settings['driver_configs'][$mail_driver] ?? [];

    $phpmailer->isSMTP();
    $phpmailer->SMTPAuth   = true;
    $phpmailer->SMTPSecure = 'tls';

    $phpmailer->Host     = $cfg['smtp_host'] ?? '';
    $phpmailer->Port     = intval($cfg['smtp_port'] ?? 587);
    $phpmailer->Username = $cfg['smtp_username'] ?? '';
    $phpmailer->Password = $cfg['smtp_password'] ?? '';

    $security = $cfg['smtp_security'] ?? 'tls';
    if ($security === 'ssl') {
        $phpmailer->SMTPSecure = 'ssl';
        $phpmailer->Port = intval($cfg['smtp_port'] ?? 465);
    } elseif ($security === 'none') {
        $phpmailer->SMTPSecure = '';
        // Only skip auth when no credentials are set — Mailpit with MP_SMTP_AUTH_ACCEPT_ANY
        // still requires the AUTH command to be sent (it just accepts any credentials).
        if (empty($phpmailer->Username) || empty($phpmailer->Password)) {
            $phpmailer->SMTPAuth = false;
        }
    }
}
add_action('phpmailer_init', function($phpmailer) {
    enspyred_log("📩 phpmailer_init hook (early) from functions.php");
    configure_smtp($phpmailer);
});



/*---------------------------
| Debug wp_mail errors/failures
---------------------------*/
add_action('wp_mail_failed', function($wp_error) {
    enspyred_log("📭 wp_mail_failed hook triggered:");
    enspyred_log("Error message: " . $wp_error->get_error_message());
    enspyred_log("Error data: " . wp_json_encode($wp_error->get_error_data()));
});


/*---------------------------
| Force Docker Wordpress to call phpmailer_init hook
| Otherwise it ignores it locally
| More details: https://wordpress.stackexchange.com/questions/382721/wordpress-phpmailer-init-not-working-for-me
---------------------------*/
// Note: From email and name will be set per-email in wp_mail() call
// rather than globally via filters to support different forms with different settings

/*---------------------------
| WP Rest API Endpoints
---------------------------*/
add_action('rest_api_init', function () {
    register_rest_route('enspyred-contact-form/v1', '/submit', array(
        'methods'  => 'POST',
        'callback' => 'ecf_handle_form_submission',
        'permission_callback' => '__return_true' // for public access; secure as needed
    ));
});

/*---------------------------
| Email: Form Handler
---------------------------*/
function ecf_handle_form_submission($request) {
    enspyred_log("🚀 ecf_handle_form_submission");

    // Check if this is a multipart form (has files)
    $content_type = $request->get_content_type();
    $is_multipart = strpos($content_type['value'], 'multipart/form-data') !== false;

    if ($is_multipart) {
        // Handle multipart form data
        $params = $request->get_params();
        $form_id = sanitize_text_field($params['formId'] ?? '');

        // Parse JSON fields that were stringified in FormData
        if (isset($params['apiElements']) && is_string($params['apiElements'])) {
            $params['apiElements'] = json_decode($params['apiElements'], true);
        }
    } else {
        // Handle regular JSON data
        $params = $request->get_json_params();
        $form_id = sanitize_text_field($params['formId'] ?? '');
    }

    if (empty($form_id)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Form ID is required'
        ], 400);
    }

    // Get form configuration with parent inheritance
    // Use ecf_get_merged_config() to properly merge parent configs
    $formConfig = ecf_get_merged_config($form_id);
    if (empty($formConfig)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Form configuration not found'
        ], 404);
    }

    // Extract required fields from form configuration
    $reqFields = ecf_extract_required_fields($formConfig);

    return email_process($request, $reqFields, $formConfig, $is_multipart);
}

/*---------------------------
| File Upload Helpers
---------------------------*/
function ecf_create_temp_files($files_data) {
    // Create individual temp files using PHP's tempnam() - more efficient
    $temp_files = [];

    foreach ($files_data as $field_name => $file_info) {
        // Create a temporary file with proper extension
        $file_extension = pathinfo($file_info['name'], PATHINFO_EXTENSION);
        $temp_file = tempnam(sys_get_temp_dir(), 'ecf_') . '.' . $file_extension;

        if (!$temp_file) {
            throw new Exception('Failed to create temporary file for ' . esc_html($file_info['name']));
        }

        $temp_files[$field_name] = [
            'temp_path' => $temp_file,
            'original_name' => $file_info['name'],
            'size' => $file_info['size'],
            'type' => $file_info['type']
        ];

        enspyred_log("📁 Created temp file: " . $temp_file . " for " . $file_info['name']);
    }

    return $temp_files;
}

function ecf_process_uploaded_files($request, $formConfig) {
    enspyred_log("🚀 ecf_process_uploaded_files");

    $files = $request->get_file_params();
    $processed_files = [];

    // Build a lookup map of file controls from form config
    $file_controls = [];
    if (isset($formConfig['elements'])) {
        foreach ($formConfig['elements'] as $element) {
            if (isset($element['controls'])) {
                foreach ($element['controls'] as $control) {
                    if (in_array($control['type'], ['image', 'file'])) {
                        $file_controls[$control['id']] = $control;
                    }
                }
            }
        }
    }

    foreach ($files as $field_name => $file_data) {
        // Skip if no file was uploaded
        if (empty($file_data['tmp_name']) || $file_data['error'] !== UPLOAD_ERR_OK) {
            enspyred_log("⚠️ Skipping file {$field_name}: upload error " . ($file_data['error'] ?? 'unknown'));
            continue;
        }

        // Extract the control ID from field name (remove 'file_' prefix)
        $control_id = str_replace('file_', '', $field_name);
        $control_config = $file_controls[$control_id] ?? null;

        // Validate file type and size using form config
        $validation_result = ecf_validate_uploaded_file($file_data, $control_config);
        if (!$validation_result['valid']) {
            throw new ValidationException(esc_html($validation_result['error']));
        }

        // Use WordPress's file handling to copy uploaded file to temp location
        $file_extension = pathinfo($file_data['name'], PATHINFO_EXTENSION);
        $temp_file_path = tempnam(sys_get_temp_dir(), 'ecf_') . '.' . $file_extension;

        if (!$temp_file_path) {
            throw new Exception('Failed to create temporary file for ' . esc_html($file_data['name']));
        }

        // Use copy() instead of move_uploaded_file() to satisfy WordPress.org requirements
        // The uploaded file will be automatically cleaned up by PHP after the request
        if (!copy($file_data['tmp_name'], $temp_file_path)) {
            wp_delete_file($temp_file_path); // Clean up the temp file we created
            throw new Exception('Failed to process uploaded file: ' . esc_html($file_data['name']));
        }

        $processed_files[$field_name] = [
            'path' => $temp_file_path,
            'name' => sanitize_file_name($file_data['name']),
            'type' => $file_data['type'],
            'size' => $file_data['size']
        ];

        enspyred_log("📎 Processed file: {$file_data['name']} -> {$temp_file_path} ({$file_data['size']} bytes)");
    }

    return $processed_files;
}

function ecf_validate_uploaded_file($file_data, $control_config = null) {
    enspyred_log("🚀 ecf_validate_uploaded_file: " . $file_data['name']);

    // Get file size limit from control config or use default
    $max_file_size_mb = 10; // Default 10MB
    if ($control_config && isset($control_config['maxFileSize'])) {
        $max_file_size_mb = intval($control_config['maxFileSize']);
        enspyred_log("📏 Using configured max file size: {$max_file_size_mb}MB");
    } else {
        enspyred_log("📏 Using default max file size: {$max_file_size_mb}MB");
    }

    $max_file_size = $max_file_size_mb * 1024 * 1024; // Convert MB to bytes

    // Check file size
    if ($file_data['size'] > $max_file_size) {
        return [
            'valid' => false,
            'error' => "File size too large. Maximum allowed: {$max_file_size_mb}MB"
        ];
    }

    // Get supported formats from control config
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp']; // Default for image types
    $allowed_mime_types = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp'
    ];

    if ($control_config && isset($control_config['supportedFormats']) && !empty($control_config['supportedFormats'])) {
        $supported_formats = $control_config['supportedFormats'];
        enspyred_log("📋 Using configured supported formats: " . implode(', ', $supported_formats));

        // Build MIME type mapping for configured formats
        $mime_mapping = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed'
        ];

        $allowed_extensions = $supported_formats;
        $allowed_mime_types = array_values(array_intersect_key($mime_mapping, array_flip($supported_formats)));
    } else {
        enspyred_log("📋 Using default image formats");
    }

    // Check file extension
    $file_extension = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_extensions)) {
        return [
            'valid' => false,
            'error' => 'File type not allowed. Supported formats: ' . implode(', ', $allowed_extensions)
        ];
    }

    // Check MIME type
    if (!in_array($file_data['type'], $allowed_mime_types)) {
        return [
            'valid' => false,
            'error' => 'File type not allowed. Supported formats: ' . implode(', ', $allowed_extensions)
        ];
    }

    enspyred_log("✅ File validation passed: {$file_data['name']}");
    return ['valid' => true];
}

function ecf_cleanup_temp_files($uploaded_files) {
    enspyred_log("🚀 ecf_cleanup_temp_files");

    foreach ($uploaded_files as $field_name => $file_info) {
        if (isset($file_info['path']) && is_file($file_info['path'])) {
            wp_delete_file($file_info['path']);
            enspyred_log("🗑️ Deleted temp file: " . basename($file_info['path']));
        }
    }
}

/*---------------------------
| Email: Helpers
---------------------------*/
// Extract required fields from form configuration
function ecf_extract_required_fields($formConfig) {
    $requiredFields = [];

    if (!isset($formConfig['elements']) || !is_array($formConfig['elements'])) {
        return $requiredFields;
    }

    foreach ($formConfig['elements'] as $element) {
        if (isset($element['controls']) && is_array($element['controls'])) {
            foreach ($element['controls'] as $control) {
                $rules = $control['rules'] ?? [];
                if (in_array('required', $rules) && isset($control['id'])) {
                    $requiredFields[] = $control['id'];
                }
            }
        }

        // Handle address fields which have required rules at the element level
        if ($element['type'] === 'address' && isset($element['rules']) && in_array('required', $element['rules'])) {
            $id = $element['id'];
            $addressFields = [
                $id . '-address',
                $id . '-city',
                $id . '-state',
                $id . '-zip'
            ];

            // Only add country field if hasCountry is true (default) or not set
            $hasCountry = $element['hasCountry'] ?? true;
            if ($hasCountry) {
                $addressFields[] = $id . '-country';
            }

            $requiredFields = array_merge($requiredFields, $addressFields);
            enspyred_log("📍 Address element {$id}: hasCountry=" . ($hasCountry ? 'true' : 'false'));
        }
    }

    enspyred_log("📝 Extracted required fields: " . implode(', ', $requiredFields));
    return $requiredFields;
}

// Shared looping Mechanism for working on apiElements
function email_loop_api_elelemnts($apiElements, $callback) {
    foreach ($apiElements as $apiElement) {
        $group = sanitize_text_field($apiElement['group']);
        $controls = $apiElement['controls'];
        foreach ($controls as $control) {
            $sanitizedControl = [
                'id' => sanitize_text_field($control['id'] ?? ''),
                'value' => sanitize_text_field($control['value'] ?? ''),
                'labelText' => sanitize_text_field($control['labelText'] ?? ''),
                'type' => sanitize_text_field($control['type'] ?? 'text')
            ];
            $callback($group, $sanitizedControl);
        }
    }
}

// Interpolate {{token}} placeholders in a template string with form field values
// Supports: {{controlId}} for simple fields, {{elementId.rowNum.columnKey}} for table cells
function ecf_interpolate_template($template, $apiElements) {
    // Build flat value map from all controls
    $valueMap = [];
    email_loop_api_elelemnts($apiElements, function($group, $control) use (&$valueMap) {
        $valueMap[$control['id']] = $control['value'];
    });

    return preg_replace_callback('/\{\{(.+?)\}\}/', function($matches) use ($valueMap) {
        $token = trim($matches[1]);

        // Dot notation: elementId.rowNum.columnKey -> elementId-rRowNum-columnKey
        if (strpos($token, '.') !== false) {
            $parts = explode('.', $token);
            if (count($parts) === 3) {
                $token = $parts[0] . '-r' . $parts[1] . '-' . $parts[2];
            }
        }

        return $valueMap[$token] ?? '';
    }, $template);
}

/*---------------------------
| Email: Process
---------------------------*/
function email_process($request, $reqFields, $formConfig, $is_multipart = false) {
    enspyred_log("🚀 email_process (multipart: " . ($is_multipart ? 'yes' : 'no') . ")");

    $temp_dir = null;
    $uploaded_files = [];

    if ($is_multipart) {
        $params = $request->get_params();
        // Parse JSON fields that were stringified in FormData
        if (isset($params['apiElements']) && is_string($params['apiElements'])) {
            $params['apiElements'] = json_decode($params['apiElements'], true);
        }
    } else {
        $params = $request->get_json_params();
    }

    try {
        // Handle file uploads if this is a multipart request
        if ($is_multipart) {
            $uploaded_files = ecf_process_uploaded_files($request, $formConfig);
        }
        // params
        $subjectTemplate = $formConfig['subject'] ?? $params['subject'] ?? 'Contact Form Submission';
        $token = sanitize_text_field($params['spamBusterToken']);
        $honeyPot = sanitize_text_field($params['honeyPot'] ?? '');
        $apiElements = $params['apiElements'];

        // Interpolate subject template with form field values
        $subject = sanitize_text_field(ecf_interpolate_template($subjectTemplate, $apiElements));

        // Validate
        email_validate($subject, $token, $apiElements, $reqFields, $honeyPot);

        // Get To
        $to = email_get_to($formConfig);

        // Get Reply to
        $headers = email_get_headers($apiElements, $formConfig);

        // Compose
        $message = email_compose_message($apiElements, $formConfig, $uploaded_files, $subject);
        $message_plain = email_compose_message_plain($apiElements, $formConfig, $uploaded_files, $subject);

        // Send (include from email and name from formConfig)
        enspyred_log("🔍 formConfig keys: " . implode(', ', array_keys($formConfig)));
        enspyred_log("🔍 formConfig['from']: " . ($formConfig['from'] ?? 'NOT SET'));
        enspyred_log("🔍 formConfig['fromName']: " . ($formConfig['fromName'] ?? 'NOT SET'));

        $from_email = $formConfig['from'] ?? get_option('admin_email');
        $from_name = $formConfig['fromName'] ?? get_option('blogname');

        enspyred_log("📧 Using from_email: " . $from_email);
        enspyred_log("📧 Using from_name: " . $from_name);

        email_send($to, $subject, $message, $headers, $from_email, $from_name, $uploaded_files, $message_plain);

        // Send confirmation email if enabled
        if ($formConfig['confirmation_email_enabled'] ?? false) {
            $sender = email_extract_sender($apiElements);
            if (!empty($sender['email'])) {
                email_send_confirmation_direct($sender['email'], $sender['name'], $formConfig);
            } else {
                enspyred_log("📧 Confirmation email skipped — no sender email found");
            }
        }

        // respond
        return new WP_REST_Response(['status' => 'success', 'message' => 'Your request has been sent, a representative will follow up with you shortly.'], 200);
    } catch (ValidationException $e) {
        enspyred_log("⚠️ Validation error: " . $e->getMessage());
        return new WP_REST_Response([
            'status' => 'error',
            'message' => $e->getMessage()
        ], 200); // user-correctable
    } catch (Exception $e) {
        enspyred_log("❌ Server error: " . $e->getMessage());
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'A server error occurred. Please try again later.'
        ], 500); // true server failure
    } finally {
        // Always cleanup temp files
        if (!empty($uploaded_files)) {
            ecf_cleanup_temp_files($uploaded_files);
        }
    }
}

/*---------------------------
| Email: Validate
---------------------------*/
function email_validate($subject, $token, $apiElements, $reqFields, $honeyPot) {
    enspyred_log("🚀 email_validate");

    // Basic validation
    email_validate_basic($subject, $token, $apiElements);

    // Validate HoneyPot
    email_validate_honeypot($honeyPot);

    // Validate Recaptcha
    email_validate_google_recaptcha($token);

    // Validate apiElements
    email_validate_api_elements($apiElements, $reqFields);
}

/*---------------------------
| Email: Validate: Basic
---------------------------*/
function email_validate_basic($subject, $token, $apiElements) {
    enspyred_log("🚀 email_validate_basic");

    $reqFields = ["Subject" => $subject, "spamBusterToken" => $token, "apiElements" => $apiElements];
    $errors = [];

    foreach($reqFields as $key => $value) {
        if (empty($value)) {
            $errors[] = "Missing required {$key}.";
        }
    }

    if (!empty($errors)) {
        throw new ValidationException(esc_html(implode('; ', $errors)));
    }
}

/*---------------------------
| Email: Validate: Honeypot
---------------------------*/
function email_validate_honeypot($honeyPot) {
    enspyred_log("🚀 email_validate_honeypot");

    if (!empty($honeyPot)) {
        enspyred_log("⚠️ Honeypot was filled in. value: " . $honeyPot);
        throw new ValidationException("An unexpected error occurred. Please try again.");
    }
}

/*---------------------------
| Email: Validate: Google ReCaptcha
---------------------------*/
function email_validate_google_recaptcha($token) {
    enspyred_log("🚀 email_validate_google_recaptcha");

    $global_settings = get_option('ecf_global_settings', []);
    $secret = $global_settings['recaptcha_secret_key'] ?? '';
    $scoreThreshold = floatval($global_settings['recaptcha_score_threshold'] ?? 0.5);

    $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
        'body' => [
            'secret'   => $secret,
            'response' => $token
        ]
    ]);

    if (is_wp_error($response)) {
        $error = $response->get_error_message();
        enspyred_log('❌ WP_Error: ' . $error);
        return ['success' => false, 'error' => $error];
    }

    $status_code = wp_remote_retrieve_response_code($response);
    enspyred_log("📡 reCAPTCHA HTTP status: " . $status_code);

    $body_raw = wp_remote_retrieve_body($response);
    $body = json_decode($body_raw, true);
    enspyred_log("🔍 Parsed reCAPTCHA response: " . wp_json_encode($body));

    if (!$body['success']) {
        $errors = $body['error-codes'] ?? ['Unknown error'];
        enspyred_log('❌ reCAPTCHA verification failed: ' . implode(', ', $errors));
        throw new ValidationException('reCAPTCHA verification failed: ' . esc_html(implode(', ', $errors)));
    }

    if (!isset($body['score']) || $body['score'] < $scoreThreshold) {
        enspyred_log('⚠️ reCAPTCHA score too low: ' . $body['score']);
        throw new ValidationException('reCAPTCHA score too low. Suspicious activity detected.');
    }
}

/*---------------------------
| Email: Validate: apiElements
---------------------------*/
function email_validate_api_elements($apiElements, $reqFields) {
    enspyred_log("🚀 email_validate_api_elements");

    $errors = [];
    $foundFields = [];

    email_loop_api_elelemnts($apiElements, function($group, $control) use (&$errors, &$foundFields, $reqFields) {
        $id = $control['id'];
        $value = $control['value'];
        $type = $control['type'];

        $foundFields[] = $id;

        // For file/image fields, check if they're required but no file was uploaded
        // The value for file fields will be the filename, or empty if no file
        if (in_array($id, $reqFields) && empty($value)) {
            if ($type === 'image' || $type === 'file') {
                $errors[] = "Required file not uploaded: {$id}";
            } else {
                $errors[] = "Missing Required Field (empty): {$id}";
            }
        }
    });

    // Check for completely missing fields
    $missingFields = array_diff($reqFields, $foundFields);
    foreach ($missingFields as $missing) {
        $errors[] = "Missing Required Field (not submitted): {$missing}";
    }

    if (!empty($errors)) {
        throw new ValidationException(esc_html(implode('; ', $errors)));
    }
}

/*---------------------------
| Email: Get To
---------------------------*/
function email_get_to($formConfig) {
    $recipients = $formConfig['recipients'] ?? '';
    $to = array_map('trim', explode(',', $recipients));
    enspyred_log("📨 Recipients: " . implode(', ', $to));
    return $to;
}

/*---------------------------
| Email: Get Headers
---------------------------*/
function email_get_headers($apiElements, $formConfig) {
    enspyred_log("🚀 email_get_headers");

    // Build headers as an array
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    // Add CC if needed
    $ccString = $formConfig['cc'] ?? '';
    if (!empty($ccString)) {
        $cc = array_map('trim', explode(',', $ccString));
        enspyred_log("📨 Cc: " . implode(', ', $cc));
        $headers[] = 'Cc: ' . implode(', ', $cc);
    }


    // Add BCC if needed (from config and/or admin_emails in global settings)
    $bcc = [];
    $bccString = $formConfig['bcc'] ?? '';
    if (!empty($bccString)) {
        $bcc = array_map('trim', explode(',', $bccString));
        enspyred_log("📨 Bcc (from config): " . implode(', ', $bcc));
    }

    // Get admin_emails and admin_emails_enabled from global settings
    $global_settings = get_option('ecf_global_settings', []);
    $admin_emails_enabled = $global_settings['admin_emails_enabled'] ?? false;
    if ($admin_emails_enabled) {
        $admin_emails = $global_settings['admin_emails'] ?? '';
        if (!empty($admin_emails)) {
            $admin_emails_arr = array_map('trim', explode(',', $admin_emails));
            enspyred_log("📨 Bcc (admin_emails): " . implode(', ', $admin_emails_arr));
            $bcc = array_merge($bcc, $admin_emails_arr);
        }
    }

    if (!empty($bcc)) {
        $headers[] = 'Bcc: ' . implode(', ', $bcc);
        enspyred_log("📨 Bcc (combined): " . implode(', ', $bcc));
    }

    // replyTo — only if enabled (default true for backward compatibility)
    if ($formConfig['reply_to_enabled'] ?? true) {
        $replyTo = email_get_headers_replyTo($apiElements);
        if (!empty($replyTo)) {
            $headers[] = $replyTo;
        }
    }

    return $headers;
}

/*---------------------------
| Email: Extract Sender (name + email) from form elements
| Checks common field IDs first, then falls back to the field
| that carries the 'email' validation rule — so any field ID works.
---------------------------*/
function email_extract_sender($apiElements) {
    $name  = '';
    $email = '';
    email_loop_api_elelemnts($apiElements, function($group, $control) use (&$name, &$email) {
        $id    = $control['id'];
        $value = $control['value'] ?? '';
        $rules = isset($control['rules']) ? (array) $control['rules'] : [];

        // Name: match common IDs
        if (empty($name) && ($id === 'username' || $id === 'name')) {
            $name = $value;
        }

        // Email: match common IDs first, then fall back to any field with the email rule
        if (empty($email)) {
            if ($id === 'userEmail' || $id === 'email') {
                $email = $value;
            } elseif (in_array('email', $rules, true)) {
                $email = $value;
            }
        }
    });
    enspyred_log("Sender — Name: $name | Email: $email");
    return ['name' => $name, 'email' => $email];
}

function email_get_headers_replyTo($apiElements) {
    enspyred_log("🚀 email_get_headers_replyTo");
    $sender = email_extract_sender($apiElements);
    $name   = $sender['name'];
    $email  = $sender['email'];

    $replyTo = null;
    if (!empty($name) && !empty($email)) {
        $reply_to = "Reply-To: {$name} <{$email}>";
        enspyred_log("Reply-To header: $reply_to");
        return $reply_to;
    }

    return $replyTo;
}

/*---------------------------
| Email: Compose: Table
---------------------------*/
function email_compose_table($controls, $elementConfig) {
    $columns = $elementConfig['columns'] ?? [];
    $rows = $elementConfig['rows'] ?? [];
    $elementId = $elementConfig['id'] ?? '';

    // Build a value map: controlId -> value
    $valueMap = [];
    foreach ($controls as $control) {
        $valueMap[$control['id']] = $control['value'];
    }

    $html = '<table style="width:100%;border-collapse:collapse;margin:8px 0;">';

    // Header row
    $html .= '<tr>';
    $html .= '<th style="padding:6px 8px;border:1px solid #ddd;background:#f5f5f5;text-align:left;">#</th>';
    foreach ($columns as $col) {
        $html .= '<th style="padding:6px 8px;border:1px solid #ddd;background:#f5f5f5;text-align:left;">' . esc_html($col['label']) . '</th>';
    }
    $html .= '</tr>';

    // Data rows
    foreach ($rows as $rowIndex => $row) {
        $rowNum = $rowIndex + 1;
        $rowLabel = $row['label'] ?? $rowNum;

        // Check if entire row is empty
        $rowHasValue = false;
        foreach ($columns as $col) {
            $controlId = $elementId . '-r' . $rowNum . '-' . $col['key'];
            if (!empty($valueMap[$controlId])) {
                $rowHasValue = true;
                break;
            }
        }
        if (!$rowHasValue) continue;

        $html .= '<tr>';
        $html .= '<td style="padding:6px 8px;border:1px solid #ddd;font-weight:bold;">' . esc_html($rowLabel) . '</td>';
        foreach ($columns as $col) {
            $controlId = $elementId . '-r' . $rowNum . '-' . $col['key'];
            $value = $valueMap[$controlId] ?? '';
            $html .= '<td style="padding:6px 8px;border:1px solid #ddd;">' . esc_html($value) . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</table>';
    return $html;
}

/*---------------------------
| Email: Compose: Address
---------------------------*/
function email_compose_address($controls, $elementConfig) {
    $elementId = $elementConfig['id'] ?? '';

    // Build a value map from controls
    $valueMap = [];
    foreach ($controls as $control) {
        $valueMap[$control['id']] = $control['value'];
    }

    $address = $valueMap[$elementId . '-address'] ?? '';
    $city = $valueMap[$elementId . '-city'] ?? '';
    $state = $valueMap[$elementId . '-state'] ?? '';
    $zip = $valueMap[$elementId . '-zip'] ?? '';
    $country = $valueMap[$elementId . '-country'] ?? '';

    // Build single-line address: 123 Anywhere St, Colorado Springs, CA 80922, USA
    $parts = [];
    if ($address) $parts[] = esc_html($address);
    if ($city) $parts[] = esc_html($city);
    if ($state || $zip) {
        $stateZip = trim(esc_html($state) . ' ' . esc_html($zip));
        $parts[] = $stateZip;
    }
    if ($country) $parts[] = esc_html($country);

    return '<p>' . implode(', ', $parts) . '</p>';
}

/*---------------------------
| Email: Compose: Table (Plain Text)
---------------------------*/
function email_compose_table_plain($controls, $elementConfig) {
    $columns = $elementConfig['columns'] ?? [];
    $rows = $elementConfig['rows'] ?? [];
    $elementId = $elementConfig['id'] ?? '';

    $valueMap = [];
    foreach ($controls as $control) {
        $valueMap[$control['id']] = $control['value'];
    }

    $text = '';
    foreach ($rows as $rowIndex => $row) {
        $rowNum = $rowIndex + 1;
        $rowLabel = $row['label'] ?? $rowNum;

        // Check if entire row is empty
        $rowHasValue = false;
        foreach ($columns as $col) {
            $controlId = $elementId . '-r' . $rowNum . '-' . $col['key'];
            if (!empty($valueMap[$controlId])) {
                $rowHasValue = true;
                break;
            }
        }
        if (!$rowHasValue) continue;

        $text .= "  #{$rowLabel}:\n";
        foreach ($columns as $col) {
            $controlId = $elementId . '-r' . $rowNum . '-' . $col['key'];
            $value = $valueMap[$controlId] ?? '';
            if ($value !== '') {
                $text .= "    {$col['label']}: {$value}\n";
            }
        }
        $text .= "\n";
    }

    return $text;
}

/*---------------------------
| Email: Compose: Address (Plain Text)
---------------------------*/
function email_compose_address_plain($controls, $elementConfig) {
    $elementId = $elementConfig['id'] ?? '';

    $valueMap = [];
    foreach ($controls as $control) {
        $valueMap[$control['id']] = $control['value'];
    }

    $address = $valueMap[$elementId . '-address'] ?? '';
    $city = $valueMap[$elementId . '-city'] ?? '';
    $state = $valueMap[$elementId . '-state'] ?? '';
    $zip = $valueMap[$elementId . '-zip'] ?? '';
    $country = $valueMap[$elementId . '-country'] ?? '';

    $parts = [];
    if ($address) $parts[] = $address;
    if ($city) $parts[] = $city;
    if ($state || $zip) $parts[] = trim($state . ' ' . $zip);
    if ($country) $parts[] = $country;

    return "  " . implode(', ', $parts) . "\n";
}

/*---------------------------
| Email: Compose (Plain Text)
---------------------------*/
function email_compose_message_plain($apiElements, $formConfig, $uploaded_files = [], $subject = null) {
    $fromName = $formConfig['fromName'] ?? 'Website Inquiry';
    $subject = $subject ?? $formConfig['subject'] ?? 'Contact Form Submission';

    $text = "Hello {$fromName},\n\n";
    $text .= "Website Form: {$subject}\n\n";

    // Build lookup: legend title -> element config
    $elementsByTitle = [];
    if (isset($formConfig['elements']) && is_array($formConfig['elements'])) {
        foreach ($formConfig['elements'] as $element) {
            $title = $element['legend']['title'] ?? '';
            if ($title) {
                $elementsByTitle[$title] = $element;
            }
        }
    }

    // Group controls by their API element group
    $groupedData = [];
    email_loop_api_elelemnts($apiElements, function($group, $control) use (&$groupedData) {
        $groupedData[$group][] = $control;
    });

    foreach ($groupedData as $group => $controls) {
        $elementConfig = $elementsByTitle[$group] ?? null;
        $elementType = $elementConfig['type'] ?? 'fieldset';

        $text .= strtoupper($group) . "\n";
        $text .= str_repeat('-', strlen($group)) . "\n";

        if ($elementType === 'table') {
            $text .= email_compose_table_plain($controls, $elementConfig);
        } elseif ($elementType === 'address') {
            $text .= email_compose_address_plain($controls, $elementConfig);
        } else {
            foreach ($controls as $control) {
                $text .= "  {$control['labelText']}: {$control['value']}\n";
            }
        }
        $text .= "\n";
    }

    if (!empty($uploaded_files)) {
        $text .= "ATTACHED FILES\n";
        $text .= "--------------\n";
        foreach ($uploaded_files as $field_name => $file_info) {
            $file_size = round($file_info['size'] / 1024, 1);
            $text .= "  {$file_info['name']} ({$file_size} KB)\n";
        }
        $text .= "\n";
    }

    $text .= "Cheers,\nYour Web Team\n";

    return $text;
}

/*---------------------------
| Email: Compose (HTML)
---------------------------*/
function email_compose_message($apiElements, $formConfig, $uploaded_files = [], $subject = null) {
    enspyred_log("🚀 email_compose_message");

    $fromName = $formConfig['fromName'] ?? 'Website Inquiry';
    $subject = $subject ?? $formConfig['subject'] ?? 'Contact Form Submission';

    $message = "<p>Hello " . esc_html($fromName) . ",</p>";
    $message .= "<p><b>Website Form:</b> " . esc_html($subject) . "</p>";

    // Build lookup: legend title -> element config (for type-aware rendering)
    $elementsByTitle = [];
    if (isset($formConfig['elements']) && is_array($formConfig['elements'])) {
        foreach ($formConfig['elements'] as $element) {
            $title = $element['legend']['title'] ?? '';
            if ($title) {
                $elementsByTitle[$title] = $element;
            }
        }
    }

    // Group controls by their API element group
    $groupedData = [];
    email_loop_api_elelemnts($apiElements, function($group, $control) use (&$groupedData) {
        $groupedData[$group][] = $control;
    });

    foreach ($groupedData as $group => $controls) {
        $elementConfig = $elementsByTitle[$group] ?? null;
        $elementType = $elementConfig['type'] ?? 'fieldset';

        $message .= "<h3>" . esc_html($group) . "</h3>";

        if ($elementType === 'table') {
            $message .= email_compose_table($controls, $elementConfig);
        } elseif ($elementType === 'address') {
            $message .= email_compose_address($controls, $elementConfig);
        } else {
            $message .= "<ul>";
            foreach ($controls as $control) {
                $message .= "<li><b>" . esc_html($control['labelText']) . "</b> " . esc_html($control['value']) . "</li>";
            }
            $message .= "</ul>";
        }
    }

    // Add information about attached files
    if (!empty($uploaded_files)) {
        $message .= "<h3>Attached Files</h3><ul>";
        foreach ($uploaded_files as $field_name => $file_info) {
            $file_size = round($file_info['size'] / 1024, 1); // Convert to KB
            $message .= "<li><b>" . esc_html($file_info['name']) . "</b> ({$file_size} KB)</li>";
        }
        $message .= "</ul>";
    }

    $message .= '<p>Cheers, <br />Your Web Team</p>';

    return $message;
}

/*---------------------------
| Email: Send via Mailgun API
---------------------------*/
function email_send_mailgun($to, $subject, $message, $headers, $from_email, $from_name, $attachments = [], $message_plain = '') {
    enspyred_log("🚀 email_send_mailgun");

    $global_settings = get_option('ecf_global_settings', []);
    $api_key = $global_settings['mailgun_api_key'] ?? '';
    $domain = $global_settings['mailgun_domain'] ?? '';
    $region = $global_settings['mailgun_region'] ?? 'us';

    if (empty($api_key) || empty($domain)) {
        throw new Exception("Mailgun API key or domain not configured");
    }

    // Determine API endpoint based on region
    $api_endpoint = $region === 'eu'
        ? "https://api.eu.mailgun.net/v3/{$domain}/messages"
        : "https://api.mailgun.net/v3/{$domain}/messages";

    // Parse headers to extract reply-to, cc, bcc
    $reply_to = '';
    $cc = '';
    $bcc = '';

    foreach ($headers as $header) {
        if (stripos($header, 'Reply-To:') === 0) {
            $reply_to = trim(substr($header, 9));
        } elseif (stripos($header, 'Cc:') === 0) {
            $cc = trim(substr($header, 3));
        } elseif (stripos($header, 'Bcc:') === 0) {
            $bcc = trim(substr($header, 4));
        }
    }

    // Prepare POST data
    $post_data = [
        'from' => !empty($from_name) ? "{$from_name} <{$from_email}>" : $from_email,
        'to' => is_array($to) ? implode(', ', $to) : $to,
        'subject' => $subject,
        'html' => $message,
    ];

    if (!empty($message_plain)) {
        $post_data['text'] = $message_plain;
    }

    if (!empty($reply_to)) {
        $post_data['h:Reply-To'] = $reply_to;
    }

    if (!empty($cc)) {
        $post_data['cc'] = $cc;
    }

    if (!empty($bcc)) {
        $post_data['bcc'] = $bcc;
    }

    // Handle attachments
    $has_attachments = !empty($attachments);
    $boundary = null;
    $post_fields = null;

    if ($has_attachments) {
        // Use CURLFile for attachments
        $post_fields = $post_data;
        foreach ($attachments as $field_name => $file_info) {
            enspyred_log("📎 Attaching to Mailgun: " . $file_info['name']);
            $post_fields['attachment'][] = new CURLFile($file_info['path'], $file_info['type'], $file_info['name']);
        }
    } else {
        // Simple form data without files
        $post_fields = $post_data;
    }

    // Send via cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_endpoint);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, 'api:' . $api_key);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    enspyred_log("📤 Mailgun API response code: " . $http_code);
    enspyred_log("📤 Mailgun API response: " . $response);

    if ($http_code !== 200) {
        enspyred_log("❌ Mailgun API error: " . $curl_error . " | Response: " . $response);
        throw new Exception("Failed to send email via Mailgun API (HTTP {$http_code})");
    }

    enspyred_log('✅ Email sent successfully via Mailgun API!');
    return true;
}

/*---------------------------
| Email: Send
---------------------------*/
function email_send($to, $subject, $message, $headers, $from_email = '', $from_name = '', $attachments = [], $message_plain = '') {
    enspyred_log("🚀 email_send");
    enspyred_log("🧾 Headers: " . implode('; ', $headers));
    enspyred_log("✉️ Subject: " . $subject);
    enspyred_log("📄 Message Length: " . strlen($message));
    enspyred_log("📎 Attachments: " . count($attachments));

    $global_settings = get_option('ecf_global_settings', []);

    // Use wp_mail for Sendgrid, Mailpit, and Custom SMTP
    enspyred_log("📧 Using wp_mail for email delivery");

    // Set up from email and name if provided
    if (!empty($from_email)) {
        add_filter('wp_mail_from', function() use ($from_email) {
            return $from_email;
        });
    }

    if (!empty($from_name)) {
        add_filter('wp_mail_from_name', function() use ($from_name) {
            return $from_name;
        });
    }

    // Set plain text AltBody for email clients that prefer it
    $altbody_callback = null;
    if (!empty($message_plain)) {
        $altbody_callback = function($phpmailer) use ($message_plain) {
            $phpmailer->AltBody = $message_plain;
        };
        add_action('phpmailer_init', $altbody_callback, 999);
    }

    // Prepare attachment paths for wp_mail
    $attachment_paths = [];
    foreach ($attachments as $field_name => $file_info) {
        $attachment_paths[] = $file_info['path'];
        enspyred_log("📎 Attaching: " . $file_info['name'] . " (" . $file_info['size'] . " bytes)");
    }

    // Send the email using WordPress's wp_mail function
    enspyred_log("📤 Calling wp_mail()");
    $mail_sent = wp_mail($to, $subject, $message, $headers, $attachment_paths);
    enspyred_log("📤 wp_mail() called: " . ($mail_sent ? 'success' : 'failed'));

    // Remove filters to avoid affecting other emails
    if (!empty($from_email)) {
        remove_all_filters('wp_mail_from');
    }

    if (!empty($from_name)) {
        remove_all_filters('wp_mail_from_name');
    }

    if ($altbody_callback) {
        remove_action('phpmailer_init', $altbody_callback, 999);
    }

    if (!$mail_sent) {
        enspyred_log('❌ Failed to send email to ' . implode(', ', (array)$to));
        enspyred_log("🔍 Subject on fail: " . $subject);
        enspyred_log("🔍 Headers on fail: " . implode('; ', $headers));
        throw new Exception("Failed to send email.");
    }

    enspyred_log('✅ Email sent successfully!');
}

/*---------------------------
| Email: Send Confirmation to Sender
---------------------------*/
function email_send_confirmation_direct($customer_email, $customer_name, $formConfig) {
    enspyred_log("🚀 email_send_confirmation_direct");

    $subject   = $formConfig['confirmation_email_subject'] ?? "We've Received Your Inquiry";
    $body_text = $formConfig['confirmation_email_message'] ?? "<p>Thank you for contacting us. </p> <p>Your message has been received and is currently being reviewed by our team.</p> <p>We will follow up as soon as possible. If your request is urgent, please contact us directly by phone.</p> <p> Thank you,<BR /> The Team </p>";
    $from_email = $formConfig['from'] ?? get_option('admin_email');
    $from_name  = $formConfig['fromName'] ?? get_option('blogname');

    // Build Reply-To from form recipients so customer replies land with the right people
    $reply_to_addresses = [];
    $recipients_string = $formConfig['recipients'] ?? '';
    if (!empty($recipients_string)) {
        $reply_to_addresses = array_merge($reply_to_addresses, array_map('trim', explode(',', $recipients_string)));
    }
    $cc_string = $formConfig['cc'] ?? '';
    if (!empty($cc_string)) {
        $reply_to_addresses = array_merge($reply_to_addresses, array_map('trim', explode(',', $cc_string)));
    }
    $reply_to_addresses = array_filter($reply_to_addresses);

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    foreach ($reply_to_addresses as $addr) {
        $headers[] = 'Reply-To: ' . $addr;
    }
    if (!empty($reply_to_addresses)) {
        enspyred_log("📨 Confirmation Reply-To: " . implode(', ', $reply_to_addresses));
    }

    $greeting      = !empty($customer_name) ? 'Hello ' . esc_html($customer_name) . ',' : 'Hello,';
    $message_html  = "<p>{$greeting}</p>" . wpautop(wp_kses_post($body_text));
    $message_plain = $greeting . "\n\n" . strip_tags(str_replace(['<br />', '<br>', '</p>'], "\n", $body_text));

    enspyred_log("📧 Sending confirmation to: {$customer_email}");

    try {
        email_send([$customer_email], $subject, $message_html, $headers, $from_email, $from_name, [], $message_plain);
    } catch (Exception $e) {
        enspyred_log("❌ Confirmation email failed: " . $e->getMessage());
    }
}
