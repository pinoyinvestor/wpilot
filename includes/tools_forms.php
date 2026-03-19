<?php
/**
 * WPilot - AI Website Builder for WordPress
 * Copyright (c) 2026 Weblease. All rights reserved.
 *
 * This software is licensed, not sold. Unauthorized copying,
 * modification, or distribution is strictly prohibited.
 * License: https://weblease.se/terms
 *
 * Each copy is bound to a specific domain via license key.
 * Tampered or unlicensed copies will be disabled remotely.
 */
if (!defined('ABSPATH')) exit;

/**
 * WPilot Forms & Newsletter Tools Module
 * Native form builder — no CF7, Gravity Forms, or any plugin needed.
 * Contains 8 tool cases for form/newsletter operations.
 */

// ── DB table creation on first use ──────────────────────────
function wpilot_forms_ensure_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();

    $entries_table = $wpdb->prefix . 'wpi_form_entries';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$entries_table}'") !== $entries_table) {
        $wpdb->query("CREATE TABLE {$entries_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            form_id BIGINT UNSIGNED NOT NULL,
            data LONGTEXT NOT NULL,
            ip VARCHAR(45) DEFAULT '',
            user_agent VARCHAR(255) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_form_id (form_id),
            INDEX idx_created (created_at)
        ) {$charset}");
    }

    $subs_table = $wpdb->prefix . 'wpi_subscribers';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$subs_table}'") !== $subs_table) {
        $wpdb->query("CREATE TABLE {$subs_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            status VARCHAR(20) DEFAULT 'pending',
            confirm_token VARCHAR(64) DEFAULT '',
            confirmed_at DATETIME DEFAULT NULL,
            source VARCHAR(100) DEFAULT 'newsletter',
            ip VARCHAR(45) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_email (email),
            INDEX idx_status (status)
        ) {$charset}");
    }
}

// ── Tool dispatcher ─────────────────────────────────────────
function wpilot_run_form_tools($tool, $params = []) {
    switch ($tool) {

        case 'create_contact_form':
            return wpilot_create_contact_form($params);

        case 'list_forms':
            return wpilot_list_forms($params);

        case 'get_form_entries':
            return wpilot_get_form_entries($params);

        case 'delete_form':
            return wpilot_delete_form($params);

        case 'export_form_data':
            return wpilot_export_form_data($params);

        case 'create_newsletter_form':
            return wpilot_create_newsletter_form($params);

        case 'list_subscribers':
            return wpilot_list_subscribers($params);

        case 'export_subscribers':
            return wpilot_export_subscribers($params);

        default:
            return null;
    }
}

// ═══════════════════════════════════════════════════════════════
//  1. create_contact_form
// ═══════════════════════════════════════════════════════════════
function wpilot_create_contact_form($params) {
    wpilot_forms_ensure_tables();

    $fields          = $params['fields'] ?? [];
    $email_to        = sanitize_email($params['email_to'] ?? get_option('admin_email'));
    $success_message = sanitize_text_field($params['success_message'] ?? 'Thank you! Your message has been sent.');
    $button_text     = sanitize_text_field($params['button_text'] ?? 'Send Message');
    $form_name       = sanitize_text_field($params['name'] ?? $params['form_name'] ?? 'Contact Form');

    if (empty($fields)) {
        $fields = [
            ['name' => 'name',    'type' => 'text',     'label' => 'Name',    'required' => true,  'placeholder' => 'Your name'],
            ['name' => 'email',   'type' => 'email',    'label' => 'Email',   'required' => true,  'placeholder' => 'your@email.com'],
            ['name' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true,  'placeholder' => 'Your message...'],
        ];
    }

    // Sanitize each field definition
    $clean_fields = [];
    $allowed_types = ['text','email','tel','textarea','select','checkbox','radio','date','number','file','hidden'];
    foreach ($fields as $f) {
        $type = sanitize_text_field($f['type'] ?? 'text');
        if (!in_array($type, $allowed_types)) $type = 'text';
        $clean_fields[] = [
            'name'        => sanitize_key($f['name'] ?? 'field_' . count($clean_fields)),
            'type'        => $type,
            'label'       => sanitize_text_field($f['label'] ?? ucfirst($f['name'] ?? 'Field')),
            'required'    => !empty($f['required']),
            'placeholder' => sanitize_text_field($f['placeholder'] ?? ''),
            'options'     => isset($f['options']) ? array_map('sanitize_text_field', (array)$f['options']) : [],
        ];
    }

    // Store form as a custom post type (wpi_form)
    wpilot_register_form_cpt();
    $post_id = wp_insert_post([
        'post_type'   => 'wpi_form',
        'post_title'  => $form_name,
        'post_status' => 'publish',
        'post_content' => '',
    ]);

    if (is_wp_error($post_id)) return wpilot_err('Failed to create form: ' . $post_id->get_error_message());

    update_post_meta($post_id, '_wpi_form_fields',   $clean_fields);
    update_post_meta($post_id, '_wpi_form_email_to',  $email_to);
    update_post_meta($post_id, '_wpi_form_success',   $success_message);
    update_post_meta($post_id, '_wpi_form_button',    $button_text);

    // Built by Weblease

    $shortcode = '[wpilot_form id="' . $post_id . '"]';
    return wpilot_ok("Contact form \"{$form_name}\" created (ID: {$post_id}). Use shortcode: {$shortcode}", [
        'form_id'   => $post_id,
        'shortcode' => $shortcode,
        'fields'    => count($clean_fields),
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  2. list_forms
// ═══════════════════════════════════════════════════════════════
function wpilot_list_forms($params) {
    wpilot_register_form_cpt();
    $forms = get_posts([
        'post_type'   => 'wpi_form',
        'numberposts' => intval($params['limit'] ?? 50),
        'post_status' => 'any',
    ]);

    if (empty($forms)) return wpilot_ok('No forms found.', ['forms' => []]);

    $list = [];
    foreach ($forms as $form) {
        $fields = get_post_meta($form->ID, '_wpi_form_fields', true);
        $list[] = [
            'id'        => $form->ID,
            'name'      => $form->post_title,
            'shortcode' => '[wpilot_form id="' . $form->ID . '"]',
            'fields'    => is_array($fields) ? count($fields) : 0,
            'created'   => $form->post_date,
        ];
    }

    return wpilot_ok(count($list) . ' form(s) found.', ['forms' => $list]);
}

// ═══════════════════════════════════════════════════════════════
//  3. get_form_entries
// ═══════════════════════════════════════════════════════════════
function wpilot_get_form_entries($params) {
    global $wpdb;
    wpilot_forms_ensure_tables();

    $form_id  = intval($params['form_id'] ?? $params['id'] ?? 0);
    $per_page = intval($params['per_page'] ?? 20);
    $page     = max(1, intval($params['page'] ?? 1));
    $offset   = ($page - 1) * $per_page;

    if (!$form_id) return wpilot_err('form_id is required.');

    $table = $wpdb->prefix . 'wpi_form_entries';
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id));
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT id, data, ip, created_at FROM {$table} WHERE form_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $form_id, $per_page, $offset
    ), ARRAY_A);

    $entries = [];
    foreach ($rows as $row) {
        $entry = json_decode($row['data'], true) ?: [];
        $entry['_entry_id']   = (int)$row['id'];
        $entry['_ip']         = $row['ip'];
        $entry['_submitted']  = $row['created_at'];
        $entries[] = $entry;
    }

    return wpilot_ok("{$total} entries for form #{$form_id}.", [
        'entries'  => $entries,
        'total'    => $total,
        'page'     => $page,
        'pages'    => ceil($total / $per_page),
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  4. delete_form
// ═══════════════════════════════════════════════════════════════
function wpilot_delete_form($params) {
    $form_id = intval($params['form_id'] ?? $params['id'] ?? 0);
    if (!$form_id) return wpilot_err('form_id is required.');

    $post = get_post($form_id);
    if (!$post || $post->post_type !== 'wpi_form') return wpilot_err("Form #{$form_id} not found.");

    wp_delete_post($form_id, true);

    // Optionally delete entries
    if (!empty($params['delete_entries'])) {
        global $wpdb;
        $table = $wpdb->prefix . 'wpi_form_entries';
        $wpdb->delete($table, ['form_id' => $form_id], ['%d']);
    }

    return wpilot_ok("Form #{$form_id} deleted.");
}

// ═══════════════════════════════════════════════════════════════
//  5. export_form_data
// ═══════════════════════════════════════════════════════════════
function wpilot_export_form_data($params) {
    global $wpdb;
    wpilot_forms_ensure_tables();

    $form_id = intval($params['form_id'] ?? $params['id'] ?? 0);
    if (!$form_id) return wpilot_err('form_id is required.');

    $table = $wpdb->prefix . 'wpi_form_entries';
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT data, ip, created_at FROM {$table} WHERE form_id = %d ORDER BY created_at ASC",
        $form_id
    ), ARRAY_A);

    if (empty($rows)) return wpilot_err("No entries found for form #{$form_id}.");

    // Gather all field keys
    $all_keys = [];
    $decoded  = [];
    foreach ($rows as $row) {
        $d = json_decode($row['data'], true) ?: [];
        $d['_ip']        = $row['ip'];
        $d['_submitted'] = $row['created_at'];
        $decoded[] = $d;
        foreach (array_keys($d) as $k) {
            if (!in_array($k, $all_keys)) $all_keys[] = $k;
        }
    }

    // Build CSV
    $csv = implode(',', array_map(function($k) {
        return '"' . str_replace('"', '""', $k) . '"';
    }, $all_keys)) . "\n";

    foreach ($decoded as $entry) {
        $line = [];
        foreach ($all_keys as $k) {
            $val = isset($entry[$k]) ? str_replace('"', '""', (string)$entry[$k]) : '';
            $line[] = '"' . $val . '"';
        }
        $csv .= implode(',', $line) . "\n";
    }

    // Save to uploads
    $upload_dir = wp_upload_dir();
    $filename   = 'wpilot-form-' . $form_id . '-export-' . date('Y-m-d-His') . '.csv';
    $filepath   = $upload_dir['basedir'] . '/' . $filename;
    file_put_contents($filepath, $csv);

    $url = $upload_dir['baseurl'] . '/' . $filename;
    return wpilot_ok("Exported " . count($decoded) . " entries.", ['csv_url' => $url, 'count' => count($decoded)]);
}

// ═══════════════════════════════════════════════════════════════
//  6. create_newsletter_form
// ═══════════════════════════════════════════════════════════════
function wpilot_create_newsletter_form($params) {
    wpilot_forms_ensure_tables();

    $button_text     = sanitize_text_field($params['button_text'] ?? 'Subscribe');
    $success_message = sanitize_text_field($params['success_message'] ?? 'Thanks for subscribing! Check your email to confirm.');
    $placeholder     = sanitize_text_field($params['placeholder'] ?? 'Enter your email');
    $form_name       = sanitize_text_field($params['name'] ?? 'Newsletter');
    $double_optin    = isset($params['double_optin']) ? (bool)$params['double_optin'] : true;
    $mailchimp_key   = sanitize_text_field($params['mailchimp_api_key'] ?? '');
    $mailchimp_list  = sanitize_text_field($params['mailchimp_list_id'] ?? '');
    $brevo_key       = sanitize_text_field($params['brevo_api_key'] ?? '');
    $brevo_list      = sanitize_text_field($params['brevo_list_id'] ?? '');

    wpilot_register_form_cpt();
    $post_id = wp_insert_post([
        'post_type'   => 'wpi_form',
        'post_title'  => $form_name,
        'post_status' => 'publish',
        'post_content' => '',
    ]);

    if (is_wp_error($post_id)) return wpilot_err('Failed to create newsletter form.');

    update_post_meta($post_id, '_wpi_form_type',     'newsletter');
    update_post_meta($post_id, '_wpi_form_button',    $button_text);
    update_post_meta($post_id, '_wpi_form_success',   $success_message);
    update_post_meta($post_id, '_wpi_form_placeholder', $placeholder);
    update_post_meta($post_id, '_wpi_form_double_optin', $double_optin ? '1' : '0');

    if ($mailchimp_key) {
        update_post_meta($post_id, '_wpi_mailchimp_key',  $mailchimp_key);
        update_post_meta($post_id, '_wpi_mailchimp_list', $mailchimp_list);
    }
    if ($brevo_key) {
        update_post_meta($post_id, '_wpi_brevo_key',  $brevo_key);
        update_post_meta($post_id, '_wpi_brevo_list', $brevo_list);
    }

    $shortcode = '[wpilot_newsletter id="' . $post_id . '"]';
    return wpilot_ok("Newsletter form \"{$form_name}\" created (ID: {$post_id}). Shortcode: {$shortcode}", [
        'form_id'      => $post_id,
        'shortcode'    => $shortcode,
        'double_optin' => $double_optin,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  7. list_subscribers
// ═══════════════════════════════════════════════════════════════
function wpilot_list_subscribers($params) {
    global $wpdb;
    wpilot_forms_ensure_tables();

    $status   = sanitize_text_field($params['status'] ?? 'all');
    $per_page = intval($params['per_page'] ?? 50);
    $page     = max(1, intval($params['page'] ?? 1));
    $offset   = ($page - 1) * $per_page;

    $table = $wpdb->prefix . 'wpi_subscribers';

    if ($status !== 'all') {
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $status));
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, status, source, created_at, confirmed_at FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $status, $per_page, $offset
        ), ARRAY_A);
    } else {
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email, status, source, created_at, confirmed_at FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A);
    }

    return wpilot_ok("{$total} subscriber(s).", [
        'subscribers' => $rows,
        'total'       => $total,
        'page'        => $page,
        'pages'       => ceil($total / max(1, $per_page)),
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  8. export_subscribers
// ═══════════════════════════════════════════════════════════════
function wpilot_export_subscribers($params) {
    global $wpdb;
    wpilot_forms_ensure_tables();

    $status = sanitize_text_field($params['status'] ?? 'confirmed');
    $table  = $wpdb->prefix . 'wpi_subscribers';

    if ($status === 'all') {
        $rows = $wpdb->get_results("SELECT email, status, source, created_at, confirmed_at FROM {$table} ORDER BY created_at ASC", ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT email, status, source, created_at, confirmed_at FROM {$table} WHERE status = %s ORDER BY created_at ASC",
            $status
        ), ARRAY_A);
    }

    if (empty($rows)) return wpilot_err('No subscribers found.');

    $csv = "email,status,source,subscribed,confirmed\n";
    foreach ($rows as $r) {
        $csv .= '"' . str_replace('"', '""', $r['email']) . '",';
        $csv .= '"' . $r['status'] . '",';
        $csv .= '"' . $r['source'] . '",';
        $csv .= '"' . $r['created_at'] . '",';
        $csv .= '"' . ($r['confirmed_at'] ?: '') . '"' . "\n";
    }

    $upload_dir = wp_upload_dir();
    $filename   = 'wpilot-subscribers-' . date('Y-m-d-His') . '.csv';
    $filepath   = $upload_dir['basedir'] . '/' . $filename;
    file_put_contents($filepath, $csv);

    $url = $upload_dir['baseurl'] . '/' . $filename;
    return wpilot_ok("Exported " . count($rows) . " subscribers.", ['csv_url' => $url, 'count' => count($rows)]);
}


// ═══════════════════════════════════════════════════════════════
//  Custom Post Type for Forms
// ═══════════════════════════════════════════════════════════════
function wpilot_register_form_cpt() {
    if (post_type_exists('wpi_form')) return;
    register_post_type('wpi_form', [
        'public'       => false,
        'show_ui'      => false,
        'label'        => 'WPilot Forms',
        'supports'     => ['title'],
        'query_var'    => false,
        'rewrite'      => false,
    ]);
}
add_action('init', 'wpilot_register_form_cpt');


// ═══════════════════════════════════════════════════════════════
//  Shortcode Rendering — [wpilot_form id="X"]
// ═══════════════════════════════════════════════════════════════
function wpilot_render_form_shortcode($atts) {
    $atts    = shortcode_atts(['id' => 0], $atts, 'wpilot_form');
    $form_id = intval($atts['id']);
    if (!$form_id) return '<!-- WPilot: missing form ID -->';

    $fields    = get_post_meta($form_id, '_wpi_form_fields', true);
    $button    = get_post_meta($form_id, '_wpi_form_button', true) ?: 'Send';
    $success   = get_post_meta($form_id, '_wpi_form_success', true) ?: 'Thank you!';
    if (!is_array($fields) || empty($fields)) return '<!-- WPilot: form not found -->';

    $uid = 'wpi-form-' . $form_id . '-' . wp_rand(1000, 9999);

    ob_start();
    ?>
    <div class="wpi-form-wrap" id="<?php echo esc_attr($uid); ?>">
        <form class="wpi-form" data-form-id="<?php echo esc_attr($form_id); ?>" novalidate>
            <?php wp_nonce_field('wpi_form_submit_' . $form_id, '_wpi_nonce'); ?>
            <input type="hidden" name="action" value="wpi_form_submit">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">

            <!-- Honeypot — hidden from humans -->
            <div style="position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden" aria-hidden="true">
                <label for="<?php echo esc_attr($uid); ?>-hp">Leave blank</label>
                <input type="text" name="wpi_hp_field" id="<?php echo esc_attr($uid); ?>-hp" tabindex="-1" autocomplete="off">
            </div>
            <input type="hidden" name="wpi_ts" value="<?php echo esc_attr(time()); ?>">

            <?php foreach ($fields as $f):
                $name     = esc_attr($f['name']);
                $label    = esc_html($f['label']);
                $type     = esc_attr($f['type']);
                $req      = !empty($f['required']);
                $ph       = esc_attr($f['placeholder']);
                $req_attr = $req ? 'required' : '';
                $req_star = $req ? ' <span class="wpi-required">*</span>' : '';
            ?>
            <div class="wpi-field wpi-field--<?php echo $type; ?>">
                <?php if ($type !== 'hidden'): ?>
                    <label for="<?php echo esc_attr($uid . '-' . $name); ?>"><?php echo $label . $req_star; ?></label>
                <?php endif; ?>

                <?php if ($type === 'textarea'): ?>
                    <textarea name="<?php echo $name; ?>" id="<?php echo esc_attr($uid . '-' . $name); ?>"
                        placeholder="<?php echo $ph; ?>" rows="5" <?php echo $req_attr; ?>></textarea>

                <?php elseif ($type === 'select'): ?>
                    <select name="<?php echo $name; ?>" id="<?php echo esc_attr($uid . '-' . $name); ?>" <?php echo $req_attr; ?>>
                        <option value=""><?php echo $ph ?: '— Select —'; ?></option>
                        <?php foreach (($f['options'] ?? []) as $opt): ?>
                            <option value="<?php echo esc_attr($opt); ?>"><?php echo esc_html($opt); ?></option>
                        <?php endforeach; ?>
                    </select>

                <?php elseif ($type === 'radio'): ?>
                    <div class="wpi-radio-group">
                        <?php foreach (($f['options'] ?? []) as $i => $opt): ?>
                            <label class="wpi-radio-label">
                                <input type="radio" name="<?php echo $name; ?>" value="<?php echo esc_attr($opt); ?>" <?php echo ($i === 0 && $req) ? 'required' : ''; ?>>
                                <?php echo esc_html($opt); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                <?php elseif ($type === 'checkbox'): ?>
                    <label class="wpi-checkbox-label">
                        <input type="checkbox" name="<?php echo $name; ?>" value="1" <?php echo $req_attr; ?>>
                        <?php echo $ph ?: $label; ?>
                    </label>

                <?php elseif ($type === 'file'): ?>
                    <input type="file" name="<?php echo $name; ?>" id="<?php echo esc_attr($uid . '-' . $name); ?>" <?php echo $req_attr; ?>>

                <?php elseif ($type === 'hidden'): ?>
                    <input type="hidden" name="<?php echo $name; ?>" value="<?php echo $ph; ?>">

                <?php else: ?>
                    <input type="<?php echo $type; ?>" name="<?php echo $name; ?>" id="<?php echo esc_attr($uid . '-' . $name); ?>"
                        placeholder="<?php echo $ph; ?>" <?php echo $req_attr; ?>>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <div class="wpi-form-actions">
                <button type="submit" class="wpi-btn"><?php echo esc_html($button); ?></button>
            </div>
            <div class="wpi-form-msg" style="display:none" data-success="<?php echo esc_attr($success); ?>"></div>
        </form>
    </div>
    <?php
    wpilot_form_inline_assets();
    return ob_get_clean();
}
add_shortcode('wpilot_form', 'wpilot_render_form_shortcode');


// ═══════════════════════════════════════════════════════════════
//  Shortcode Rendering — [wpilot_newsletter id="X"]
// ═══════════════════════════════════════════════════════════════
function wpilot_render_newsletter_shortcode($atts) {
    $atts    = shortcode_atts(['id' => 0], $atts, 'wpilot_newsletter');
    $form_id = intval($atts['id']);
    if (!$form_id) return '<!-- WPilot: missing newsletter ID -->';

    $button  = get_post_meta($form_id, '_wpi_form_button', true) ?: 'Subscribe';
    $ph      = get_post_meta($form_id, '_wpi_form_placeholder', true) ?: 'Enter your email';
    $success = get_post_meta($form_id, '_wpi_form_success', true) ?: 'Thanks for subscribing!';

    $uid = 'wpi-nl-' . $form_id . '-' . wp_rand(1000, 9999);

    ob_start();
    ?>
    <div class="wpi-form-wrap wpi-newsletter-wrap" id="<?php echo esc_attr($uid); ?>">
        <form class="wpi-form wpi-newsletter" data-form-id="<?php echo esc_attr($form_id); ?>" data-type="newsletter" novalidate>
            <?php wp_nonce_field('wpi_form_submit_' . $form_id, '_wpi_nonce'); ?>
            <input type="hidden" name="action" value="wpi_form_submit">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
            <input type="hidden" name="wpi_form_type" value="newsletter">

            <!-- Honeypot -->
            <div style="position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden" aria-hidden="true">
                <input type="text" name="wpi_hp_field" tabindex="-1" autocomplete="off">
            </div>
            <input type="hidden" name="wpi_ts" value="<?php echo esc_attr(time()); ?>">

            <div class="wpi-newsletter-row">
                <input type="email" name="email" placeholder="<?php echo esc_attr($ph); ?>" required>
                <button type="submit" class="wpi-btn"><?php echo esc_html($button); ?></button>
            </div>
            <div class="wpi-form-msg" style="display:none" data-success="<?php echo esc_attr($success); ?>"></div>
        </form>
    </div>
    <?php
    wpilot_form_inline_assets();
    return ob_get_clean();
}
add_shortcode('wpilot_newsletter', 'wpilot_render_newsletter_shortcode');


// ═══════════════════════════════════════════════════════════════
//  Inline CSS & JS (loaded once per page)
// ═══════════════════════════════════════════════════════════════
function wpilot_form_inline_assets() {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    ?>
    <style>
    .wpi-form-wrap{max-width:640px;margin:0 auto;font-family:inherit}
    .wpi-form .wpi-field{margin-bottom:1.2em}
    .wpi-form label{display:block;margin-bottom:.35em;font-weight:600;color:var(--wp-text,#222);font-size:.95em}
    .wpi-form input[type="text"],.wpi-form input[type="email"],.wpi-form input[type="tel"],
    .wpi-form input[type="date"],.wpi-form input[type="number"],.wpi-form input[type="file"],
    .wpi-form textarea,.wpi-form select{
        width:100%;padding:.7em .9em;border:1px solid var(--wp-border,#d0d0d0);
        border-radius:var(--wp-radius,6px);background:var(--wp-bg,#fff);color:var(--wp-text,#222);
        font-size:1em;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
    .wpi-form input:focus,.wpi-form textarea:focus,.wpi-form select:focus{
        outline:none;border-color:var(--wp-primary,#5B8DEF);box-shadow:0 0 0 3px rgba(91,141,239,.15)}
    .wpi-form textarea{resize:vertical;min-height:100px}
    .wpi-required{color:#e53e3e}
    .wpi-radio-group{display:flex;flex-wrap:wrap;gap:.8em}
    .wpi-radio-label,.wpi-checkbox-label{display:flex;align-items:center;gap:.4em;font-weight:400;cursor:pointer}
    .wpi-btn{
        display:inline-block;padding:.75em 2em;background:var(--wp-primary,#5B8DEF);color:#fff;
        border:none;border-radius:var(--wp-radius,6px);font-size:1em;font-weight:600;cursor:pointer;
        transition:opacity .2s,transform .1s}
    .wpi-btn:hover{opacity:.9}.wpi-btn:active{transform:scale(.97)}
    .wpi-btn:disabled{opacity:.5;cursor:not-allowed}
    .wpi-form-msg{padding:.8em 1em;border-radius:var(--wp-radius,6px);margin-top:1em;font-size:.95em}
    .wpi-form-msg--ok{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
    .wpi-form-msg--err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
    .wpi-newsletter-row{display:flex;gap:.5em}
    .wpi-newsletter-row input[type="email"]{flex:1}
    .wpi-form.wpi-sending .wpi-btn::after{content:" ..."}
    @media(max-width:480px){.wpi-newsletter-row{flex-direction:column}}
    </style>
    <script>
    (function(){
        if(window._wpiFormInit)return;window._wpiFormInit=true;
        document.addEventListener('submit',function(e){
            var form=e.target;if(!form.classList.contains('wpi-form'))return;
            e.preventDefault();
            if(form.classList.contains('wpi-sending'))return;

            // Honeypot check
            var hp=form.querySelector('[name="wpi_hp_field"]');
            if(hp&&hp.value)return;

            form.classList.add('wpi-sending');
            var btn=form.querySelector('.wpi-btn');
            if(btn)btn.disabled=true;

            var msgEl=form.querySelector('.wpi-form-msg');
            msgEl.style.display='none';
            msgEl.className='wpi-form-msg';

            var fd=new FormData(form);
            var xhr=new XMLHttpRequest();
            xhr.open('POST',typeof wpi_form_ajax!=='undefined'?wpi_form_ajax.url:(window.ajaxurl||'/wp-admin/admin-ajax.php'));
            xhr.onload=function(){
                form.classList.remove('wpi-sending');
                if(btn)btn.disabled=false;
                try{
                    var r=JSON.parse(xhr.responseText);
                    if(r.success){
                        msgEl.textContent=msgEl.getAttribute('data-success')||'Sent!';
                        msgEl.className='wpi-form-msg wpi-form-msg--ok';
                        form.reset();
                    }else{
                        msgEl.textContent=r.data&&r.data.message?r.data.message:'Something went wrong.';
                        msgEl.className='wpi-form-msg wpi-form-msg--err';
                    }
                }catch(ex){
                    msgEl.textContent='Server error. Please try again.';
                    msgEl.className='wpi-form-msg wpi-form-msg--err';
                }
                msgEl.style.display='block';
            };
            xhr.onerror=function(){
                form.classList.remove('wpi-sending');
                if(btn)btn.disabled=false;
                msgEl.textContent='Connection error.';
                msgEl.className='wpi-form-msg wpi-form-msg--err';
                msgEl.style.display='block';
            };
            xhr.send(fd);
        });
    })();
    </script>
    <?php
}


// ═══════════════════════════════════════════════════════════════
//  AJAX Handler — form submission
// ═══════════════════════════════════════════════════════════════
add_action('wp_ajax_wpi_form_submit',        'wpilot_handle_form_submit');
add_action('wp_ajax_nopriv_wpi_form_submit', 'wpilot_handle_form_submit');

function wpilot_handle_form_submit() {
    $form_id = intval($_POST['form_id'] ?? 0);
    if (!$form_id) wp_send_json_error(['message' => 'Invalid form.']);

    // Verify nonce
    if (!wp_verify_nonce($_POST['_wpi_nonce'] ?? '', 'wpi_form_submit_' . $form_id)) {
        wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.']);
    }

    // Anti-spam: honeypot
    if (!empty($_POST['wpi_hp_field'])) {
        // Silently succeed for bots
        wp_send_json_success(['message' => 'Sent']);
    }

    // Anti-spam: time check (< 3 seconds = bot)
    $ts = intval($_POST['wpi_ts'] ?? 0);
    if ($ts && (time() - $ts) < 3) {
        wp_send_json_success(['message' => 'Sent']);
    }

    $form_type = sanitize_text_field($_POST['wpi_form_type'] ?? 'contact');

    // ── Newsletter submission ──
    if ($form_type === 'newsletter') {
        wpilot_handle_newsletter_submit($form_id);
        return;
    }

    // ── Contact form submission ──
    wpilot_forms_ensure_tables();

    $fields = get_post_meta($form_id, '_wpi_form_fields', true);
    if (!is_array($fields)) wp_send_json_error(['message' => 'Form not found.']);

    $data = [];
    foreach ($fields as $f) {
        $name = $f['name'];
        $val  = isset($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : '';

        // Validate required
        if (!empty($f['required']) && empty($val) && $f['type'] !== 'checkbox') {
            wp_send_json_error(['message' => sprintf('%s is required.', $f['label'])]);
        }

        // Validate email type
        if ($f['type'] === 'email' && !empty($val) && !is_email($val)) {
            wp_send_json_error(['message' => 'Please enter a valid email address.']);
        }

        // Textarea allows kses
        if ($f['type'] === 'textarea') {
            $val = wp_kses_post(wp_unslash($_POST[$name] ?? ''));
        }

        $data[$name] = $val;
    }

    // Save to DB
    global $wpdb;
    $table = $wpdb->prefix . 'wpi_form_entries';
    $wpdb->insert($table, [
        'form_id'    => $form_id,
        'data'       => wp_json_encode($data),
        'ip'         => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => sanitize_text_field(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)),
        'created_at' => current_time('mysql'),
    ], ['%d', '%s', '%s', '%s', '%s']);

    // Send email notification
    $email_to = get_post_meta($form_id, '_wpi_form_email_to', true) ?: get_option('admin_email');
    $form_name = get_the_title($form_id);
    $site_name = get_bloginfo('name');

    $body = "New submission from \"{$form_name}\" on {$site_name}\n\n";
    foreach ($data as $key => $val) {
        $body .= ucfirst(str_replace('_', ' ', $key)) . ": {$val}\n";
    }
    $body .= "\nIP: " . sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $body .= "\nTime: " . current_time('Y-m-d H:i:s');

    wp_mail(
        $email_to,
        "[{$site_name}] New form submission: {$form_name}",
        $body,
        ['Content-Type: text/plain; charset=UTF-8']
    );

    wp_send_json_success(['message' => 'Sent']);
}


// ═══════════════════════════════════════════════════════════════
//  Newsletter Submission Handler
// ═══════════════════════════════════════════════════════════════
function wpilot_handle_newsletter_submit($form_id) {
    global $wpdb;
    wpilot_forms_ensure_tables();

    $email = sanitize_email($_POST['email'] ?? '');
    if (!is_email($email)) {
        wp_send_json_error(['message' => 'Please enter a valid email address.']);
    }

    $table = $wpdb->prefix . 'wpi_subscribers';

    // Check if already subscribed
    $existing = $wpdb->get_row($wpdb->prepare("SELECT id, status FROM {$table} WHERE email = %s", $email));
    if ($existing && $existing->status === 'confirmed') {
        wp_send_json_success(['message' => 'Already subscribed']);
        return;
    }

    $double_optin = get_post_meta($form_id, '_wpi_form_double_optin', true) === '1';
    $token        = wp_generate_password(48, false);
    $status       = $double_optin ? 'pending' : 'confirmed';
    $now          = current_time('mysql');

    if ($existing) {
        $wpdb->update($table, [
            'status'        => $status,
            'confirm_token' => $double_optin ? $token : '',
            'confirmed_at'  => $double_optin ? null : $now,
        ], ['id' => $existing->id], ['%s', '%s', '%s'], ['%d']);
    } else {
        $wpdb->insert($table, [
            'email'         => $email,
            'status'        => $status,
            'confirm_token' => $double_optin ? $token : '',
            'confirmed_at'  => $double_optin ? null : $now,
            'source'        => 'newsletter',
            'ip'            => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'created_at'    => $now,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    // Double opt-in: send confirmation email
    if ($double_optin) {
        $confirm_url = add_query_arg([
            'wpi_confirm' => $token,
            'email'       => rawurlencode($email),
        ], home_url('/'));

        $site_name = get_bloginfo('name');
        wp_mail(
            $email,
            "Confirm your subscription — {$site_name}",
            "Hi!\n\nPlease confirm your subscription to {$site_name} by clicking the link below:\n\n{$confirm_url}\n\nIf you didn't subscribe, you can ignore this email.\n\nThanks!",
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

    // Sync to Mailchimp if configured
    $mc_key  = get_post_meta($form_id, '_wpi_mailchimp_key', true);
    $mc_list = get_post_meta($form_id, '_wpi_mailchimp_list', true);
    if ($mc_key && $mc_list) {
        wpilot_sync_mailchimp($email, $mc_key, $mc_list, $double_optin ? 'pending' : 'subscribed');
    }

    // Sync to Brevo if configured
    $brevo_key  = get_post_meta($form_id, '_wpi_brevo_key', true);
    $brevo_list = get_post_meta($form_id, '_wpi_brevo_list', true);
    if ($brevo_key && $brevo_list) {
        wpilot_sync_brevo($email, $brevo_key, $brevo_list);
    }

    wp_send_json_success(['message' => 'Subscribed']);
}


// ═══════════════════════════════════════════════════════════════
//  Double Opt-in Confirmation Endpoint
// ═══════════════════════════════════════════════════════════════
add_action('template_redirect', function() {
    if (empty($_GET['wpi_confirm'])) return;

    global $wpdb;
    wpilot_forms_ensure_tables();

    $token = sanitize_text_field($_GET['wpi_confirm']);
    $email = sanitize_email($_GET['email'] ?? '');

    $table = $wpdb->prefix . 'wpi_subscribers';
    $sub   = $wpdb->get_row($wpdb->prepare(
        "SELECT id FROM {$table} WHERE email = %s AND confirm_token = %s AND status = 'pending'",
        $email, $token
    ));

    if ($sub) {
        $wpdb->update($table, [
            'status'        => 'confirmed',
            'confirm_token' => '',
            'confirmed_at'  => current_time('mysql'),
        ], ['id' => $sub->id], ['%s', '%s', '%s'], ['%d']);

        wp_die(
            '<div style="text-align:center;padding:60px 20px;font-family:sans-serif">'
            . '<h2 style="color:#15803d">Subscription confirmed!</h2>'
            . '<p>Thank you for subscribing to ' . esc_html(get_bloginfo('name')) . '.</p>'
            . '<p><a href="' . esc_url(home_url('/')) . '">Back to site</a></p></div>',
            'Subscription Confirmed'
        );
    } else {
        wp_die(
            '<div style="text-align:center;padding:60px 20px;font-family:sans-serif">'
            . '<h2 style="color:#b91c1c">Invalid or expired link</h2>'
            . '<p>This confirmation link is no longer valid.</p>'
            . '<p><a href="' . esc_url(home_url('/')) . '">Back to site</a></p></div>',
            'Invalid Link'
        );
    }
    exit;
});


// ═══════════════════════════════════════════════════════════════
//  Mailchimp Sync (non-blocking)
// ═══════════════════════════════════════════════════════════════
function wpilot_sync_mailchimp($email, $api_key, $list_id, $status = 'subscribed') {
    $dc = 'us1';
    if (strpos($api_key, '-') !== false) {
        $dc = explode('-', $api_key)[1];
    }
    $hash = md5(strtolower($email));

    wp_remote_request("https://{$dc}.api.mailchimp.com/3.0/lists/{$list_id}/members/{$hash}", [
        'method'   => 'PUT',
        'timeout'  => 5,
        'blocking' => false,
        'headers'  => [
            'Authorization' => 'Basic ' . base64_encode('wpilot:' . $api_key),
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode([
            'email_address' => $email,
            'status_if_new' => $status,
        ]),
    ]);
}


// ═══════════════════════════════════════════════════════════════
//  Brevo (Sendinblue) Sync (non-blocking)
// ═══════════════════════════════════════════════════════════════
function wpilot_sync_brevo($email, $api_key, $list_id) {
    wp_remote_post('https://api.brevo.com/v3/contacts', [
        'timeout'  => 5,
        'blocking' => false,
        'headers'  => [
            'api-key'      => $api_key,
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode([
            'email'            => $email,
            'listIds'          => [intval($list_id)],
            'updateEnabled'    => true,
        ]),
    ]);
}


// ═══════════════════════════════════════════════════════════════
//  Localize AJAX URL for frontend
// ═══════════════════════════════════════════════════════════════
add_action('wp_enqueue_scripts', function() {
    // Only load when shortcode is present on the page
    global $post;
    if (!is_a($post, 'WP_Post')) return;
    if (has_shortcode($post->post_content, 'wpilot_form') || has_shortcode($post->post_content, 'wpilot_newsletter')) {
        wp_enqueue_script('wpi-form-ajax', false);
        wp_localize_script('wpi-form-ajax', 'wpi_form_ajax', ['url' => admin_url('admin-ajax.php')]);
    }
});

// Fallback: always print ajaxurl variable for logged-out users on pages with forms
add_action('wp_head', function() {
    global $post;
    if (!is_a($post, 'WP_Post')) return;
    if (has_shortcode($post->post_content, 'wpilot_form') || has_shortcode($post->post_content, 'wpilot_newsletter')) {
        echo '<script>var wpi_form_ajax=wpi_form_ajax||{url:"' . esc_url(admin_url('admin-ajax.php')) . '"};</script>' . "\n";
    }
});
