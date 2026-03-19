<?php
if (!defined('ABSPATH')) exit;

/**
 * WPilot Customer Engagement Tools Module
 * Contains 15 tool cases across 4 categories:
 *   - Booking System (6 tools)
 *   - Live Chat Widget (4 tools)
 *   - Competitor Analysis (2 tools)
 *   - Email Automation (3 tools)
 */

// ── DB table creation on first use ──────────────────────────
function wpilot_engage_ensure_booking_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'wpi_bookings';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) return;

    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        service VARCHAR(200) NOT NULL,
        client_name VARCHAR(200) NOT NULL,
        client_email VARCHAR(200) NOT NULL,
        client_phone VARCHAR(50) DEFAULT '',
        date DATE NOT NULL,
        time_slot VARCHAR(20) NOT NULL,
        status VARCHAR(30) DEFAULT 'pending',
        notes TEXT DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date (date),
        INDEX idx_status (status),
        INDEX idx_email (client_email)
    ) {$charset}");
}

function wpilot_engage_ensure_chat_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'wpi_chat_messages';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) return;

    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        visitor_name VARCHAR(200) DEFAULT '',
        visitor_email VARCHAR(200) DEFAULT '',
        message TEXT NOT NULL,
        page_url VARCHAR(500) DEFAULT '',
        status VARCHAR(30) DEFAULT 'unread',
        admin_reply TEXT DEFAULT '',
        replied_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) {$charset}");
}

// ── Tool dispatcher ─────────────────────────────────────────
function wpilot_run_engage_tools($tool, $params = []) {
    switch ($tool) {

        // ── Booking System ──
        case 'create_booking_system':
            return wpilot_create_booking_system($params);
        case 'list_bookings':
            return wpilot_list_bookings($params);
        case 'confirm_booking':
            return wpilot_confirm_booking($params);
        case 'cancel_booking':
            return wpilot_cancel_booking($params);
        case 'booking_settings':
            return wpilot_booking_settings($params);
        case 'booking_stats':
            return wpilot_booking_stats($params);

        // ── Live Chat Widget ──
        case 'enable_live_chat':
            return wpilot_enable_live_chat($params);
        case 'disable_live_chat':
            return wpilot_disable_live_chat($params);
        case 'list_chat_messages':
            return wpilot_list_chat_messages($params);
        case 'reply_chat':
            return wpilot_reply_chat($params);

        // ── Competitor Analysis ──
        case 'analyze_competitor':
            return wpilot_analyze_competitor($params);
        case 'compare_with_competitor':
            return wpilot_compare_with_competitor($params);

        // ── Email Automation ──
        case 'create_email_sequence':
            return wpilot_create_email_sequence($params);
        case 'list_email_sequences':
            return wpilot_list_email_sequences($params);
        case 'delete_email_sequence':
            return wpilot_delete_email_sequence($params);

        default:
            return null;
    }
}


// ═══════════════════════════════════════════════════════════════
//  BOOKING SYSTEM — 1. create_booking_system
// ═══════════════════════════════════════════════════════════════
function wpilot_create_booking_system($params) {
    wpilot_engage_ensure_booking_table();

    $services = $params['services'] ?? [];
    if (empty($services)) {
        $services = [
            ['name' => 'Consultation', 'duration_minutes' => 30, 'price' => 0],
        ];
    }

    $clean_services = [];
    foreach ($services as $s) {
        $clean_services[] = [
            'name'             => sanitize_text_field($s['name'] ?? 'Service'),
            'duration_minutes' => max(5, intval($s['duration_minutes'] ?? 30)),
            'price'            => floatval($s['price'] ?? 0),
        ];
    }

    $business_hours = [];
    $raw_hours = $params['business_hours'] ?? [
        'monday' => '09:00-17:00', 'tuesday' => '09:00-17:00',
        'wednesday' => '09:00-17:00', 'thursday' => '09:00-17:00',
        'friday' => '09:00-17:00', 'saturday' => '', 'sunday' => '',
    ];
    $valid_days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    foreach ($valid_days as $day) {
        $val = sanitize_text_field($raw_hours[$day] ?? '');
        if ($val && preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $val)) {
            $business_hours[$day] = $val;
        } else {
            $business_hours[$day] = '';
        }
    }

    $slot_interval    = in_array(intval($params['slot_interval'] ?? 30), [15, 30, 60]) ? intval($params['slot_interval']) : 30;
    $max_advance_days = max(1, min(365, intval($params['max_advance_days'] ?? 30)));
    $confirmation_email = isset($params['confirmation_email']) ? (bool)$params['confirmation_email'] : true;
    $buffer_minutes   = max(0, intval($params['buffer_minutes'] ?? 0));

    $config = [
        'services'           => $clean_services,
        'business_hours'     => $business_hours,
        'slot_interval'      => $slot_interval,
        'max_advance_days'   => $max_advance_days,
        'confirmation_email' => $confirmation_email,
        'buffer_minutes'     => $buffer_minutes,
        'created_at'         => current_time('mysql'),
    ];
    update_option('wpilot_booking_config', $config, false);

    // Register shortcode rendering via mu-plugin
    $mu_code = wpilot_booking_generate_mu_code();
    wpilot_mu_register('booking-widget', $mu_code);

    return wpilot_ok('Booking system created with ' . count($clean_services) . ' service(s). Use shortcode [wpilot_booking] on any page.', [
        'shortcode' => '[wpilot_booking]',
        'services'  => count($clean_services),
        'hours'     => $business_hours,
        'interval'  => $slot_interval,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  BOOKING SYSTEM — 2. list_bookings
// ═══════════════════════════════════════════════════════════════
function wpilot_list_bookings($params) {
    global $wpdb;
    wpilot_engage_ensure_booking_table();

    $table     = $wpdb->prefix . 'wpi_bookings';
    $status    = sanitize_text_field($params['status'] ?? 'all');
    $date_from = sanitize_text_field($params['date_from'] ?? '');
    $date_to   = sanitize_text_field($params['date_to'] ?? '');
    $limit     = max(1, min(200, intval($params['limit'] ?? 50)));

    $where = [];
    $vals  = [];

    if ($status !== 'all') {
        $where[] = 'status = %s';
        $vals[]  = $status;
    }
    if ($date_from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
        $where[] = 'date >= %s';
        $vals[]  = $date_from;
    }
    if ($date_to && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
        $where[] = 'date <= %s';
        $vals[]  = $date_to;
    }

    $sql = "SELECT * FROM {$table}";
    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY date DESC, time_slot ASC LIMIT %d';
    $vals[] = $limit;

    $rows = $wpdb->get_results($wpdb->prepare($sql, ...$vals), ARRAY_A);

    return wpilot_ok(count($rows) . ' booking(s) found.', ['bookings' => $rows]);
}

// ═══════════════════════════════════════════════════════════════
//  BOOKING SYSTEM — 3. confirm_booking
// ═══════════════════════════════════════════════════════════════
function wpilot_confirm_booking($params) {
    global $wpdb;
    wpilot_engage_ensure_booking_table();

    $id    = intval($params['booking_id'] ?? $params['id'] ?? 0);
    $table = $wpdb->prefix . 'wpi_bookings';

    if (!$id) return wpilot_err('booking_id is required.');

    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
    if (!$booking) return wpilot_err("Booking #{$id} not found.");
    if ($booking['status'] === 'confirmed') return wpilot_ok("Booking #{$id} is already confirmed.");

    $wpdb->update($table, ['status' => 'confirmed'], ['id' => $id], ['%s'], ['%d']);

    // Send confirmation email
    $config = get_option('wpilot_booking_config', []);
    if (!empty($config['confirmation_email']) && is_email($booking['client_email'])) {
        $site = get_bloginfo('name');
        $body = "Hi {$booking['client_name']},\n\n";
        $body .= "Your booking has been confirmed!\n\n";
        $body .= "Service: {$booking['service']}\n";
        $body .= "Date: {$booking['date']}\n";
        $body .= "Time: {$booking['time_slot']}\n\n";
        $body .= "If you need to make changes, please contact us.\n\n";
        $body .= "Best regards,\n{$site}";

        wp_mail(
            $booking['client_email'],
            "[{$site}] Booking Confirmed — {$booking['service']}",
            $body,
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

    return wpilot_ok("Booking #{$id} confirmed. Confirmation email sent to {$booking['client_email']}.");
}

// ═══════════════════════════════════════════════════════════════
//  BOOKING SYSTEM — 4. cancel_booking
// ═══════════════════════════════════════════════════════════════
function wpilot_cancel_booking($params) {
    global $wpdb;
    wpilot_engage_ensure_booking_table();

    $id     = intval($params['booking_id'] ?? $params['id'] ?? 0);
    $reason = sanitize_text_field($params['reason'] ?? '');
    $table  = $wpdb->prefix . 'wpi_bookings';

    if (!$id) return wpilot_err('booking_id is required.');

    $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
    if (!$booking) return wpilot_err("Booking #{$id} not found.");
    if ($booking['status'] === 'cancelled') return wpilot_ok("Booking #{$id} is already cancelled.");

    $notes = $booking['notes'];
    if ($reason) {
        $notes .= ($notes ? "\n" : '') . 'Cancelled: ' . $reason;
    }

    $wpdb->update($table, ['status' => 'cancelled', 'notes' => $notes], ['id' => $id], ['%s', '%s'], ['%d']);

    // Send cancellation email
    if (is_email($booking['client_email'])) {
        $site = get_bloginfo('name');
        $body = "Hi {$booking['client_name']},\n\n";
        $body .= "Your booking has been cancelled.\n\n";
        $body .= "Service: {$booking['service']}\n";
        $body .= "Date: {$booking['date']}\n";
        $body .= "Time: {$booking['time_slot']}\n";
        if ($reason) $body .= "Reason: {$reason}\n";
        $body .= "\nIf you'd like to rebook, please visit our website.\n\n";
        $body .= "Best regards,\n{$site}";

        wp_mail(
            $booking['client_email'],
            "[{$site}] Booking Cancelled — {$booking['service']}",
            $body,
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

    return wpilot_ok("Booking #{$id} cancelled." . ($reason ? " Reason: {$reason}" : ''));
}

// ═══════════════════════════════════════════════════════════════
//  BOOKING SYSTEM — 5. booking_settings
// ═══════════════════════════════════════════════════════════════
function wpilot_booking_settings($params) {
    $config = get_option('wpilot_booking_config', []);
    if (empty($config)) return wpilot_err('No booking system found. Run create_booking_system first.');

    if (isset($params['services']) && is_array($params['services'])) {
        $clean = [];
        foreach ($params['services'] as $s) {
            $clean[] = [
                'name'             => sanitize_text_field($s['name'] ?? 'Service'),
                'duration_minutes' => max(5, intval($s['duration_minutes'] ?? 30)),
                'price'            => floatval($s['price'] ?? 0),
            ];
        }
        $config['services'] = $clean;
    }

    if (isset($params['business_hours']) && is_array($params['business_hours'])) {
        $valid_days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        foreach ($valid_days as $day) {
            if (isset($params['business_hours'][$day])) {
                $val = sanitize_text_field($params['business_hours'][$day]);
                $config['business_hours'][$day] = ($val && preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $val)) ? $val : '';
            }
        }
    }

    if (isset($params['slot_interval'])) {
        $config['slot_interval'] = in_array(intval($params['slot_interval']), [15, 30, 60]) ? intval($params['slot_interval']) : $config['slot_interval'];
    }
    if (isset($params['max_advance_days'])) {
        $config['max_advance_days'] = max(1, min(365, intval($params['max_advance_days'])));
    }
    if (isset($params['buffer_minutes'])) {
        $config['buffer_minutes'] = max(0, intval($params['buffer_minutes']));
    }

    update_option('wpilot_booking_config', $config, false);

    // Regenerate mu-plugin with updated config
    $mu_code = wpilot_booking_generate_mu_code();
    wpilot_mu_register('booking-widget', $mu_code);

    return wpilot_ok('Booking settings updated.', [
        'services'  => count($config['services'] ?? []),
        'interval'  => $config['slot_interval'] ?? 30,
        'advance'   => $config['max_advance_days'] ?? 30,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  BOOKING SYSTEM — 6. booking_stats
// ═══════════════════════════════════════════════════════════════
function wpilot_booking_stats($params) {
    global $wpdb;
    wpilot_engage_ensure_booking_table();

    $table = $wpdb->prefix . 'wpi_bookings';
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

    if ($total === 0) return wpilot_ok('No bookings yet.', ['total' => 0]);

    $by_status  = $wpdb->get_results("SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status", ARRAY_A);
    $by_service = $wpdb->get_results("SELECT service, COUNT(*) as cnt FROM {$table} GROUP BY service ORDER BY cnt DESC", ARRAY_A);
    $by_day     = $wpdb->get_results("SELECT DAYNAME(date) as day_name, COUNT(*) as cnt FROM {$table} GROUP BY DAYNAME(date) ORDER BY cnt DESC", ARRAY_A);
    $by_hour    = $wpdb->get_results("SELECT SUBSTRING(time_slot, 1, 2) as hour, COUNT(*) as cnt FROM {$table} GROUP BY SUBSTRING(time_slot, 1, 2) ORDER BY cnt DESC LIMIT 5", ARRAY_A);

    $status_map  = [];
    foreach ($by_status as $r) $status_map[$r['status']] = (int)$r['cnt'];

    $service_map = [];
    foreach ($by_service as $r) $service_map[$r['service']] = (int)$r['cnt'];

    $day_map = [];
    foreach ($by_day as $r) $day_map[$r['day_name']] = (int)$r['cnt'];

    $busiest_hours = [];
    foreach ($by_hour as $r) $busiest_hours[$r['hour'] . ':00'] = (int)$r['cnt'];

    return wpilot_ok("Booking statistics: {$total} total bookings.", [
        'total'          => $total,
        'by_status'      => $status_map,
        'by_service'     => $service_map,
        'by_day_of_week' => $day_map,
        'busiest_hours'  => $busiest_hours,
    ]);
}


// ═══════════════════════════════════════════════════════════════
//  BOOKING — AJAX handlers for slot fetching and submission
// ═══════════════════════════════════════════════════════════════
add_action('wp_ajax_wpi_booking_slots', 'wpilot_ajax_booking_slots');
add_action('wp_ajax_nopriv_wpi_booking_slots', 'wpilot_ajax_booking_slots');

function wpilot_ajax_booking_slots() {
    $config = get_option('wpilot_booking_config', []);
    if (empty($config)) wp_send_json_error(['message' => 'Booking system not configured.']);

    $date = sanitize_text_field($_POST['date'] ?? $_GET['date'] ?? '');
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        wp_send_json_error(['message' => 'Invalid date.']);
    }

    $service_name = sanitize_text_field($_POST['service'] ?? $_GET['service'] ?? '');
    $day_name     = strtolower(gmdate('l', strtotime($date)));
    $hours_str    = $config['business_hours'][$day_name] ?? '';

    if (!$hours_str) {
        wp_send_json_success(['slots' => [], 'message' => 'Closed on this day.']);
        return;
    }

    // Find service duration
    $duration = 30;
    foreach ($config['services'] ?? [] as $svc) {
        if ($svc['name'] === $service_name) {
            $duration = $svc['duration_minutes'];
            break;
        }
    }

    $parts     = explode('-', $hours_str);
    $start     = strtotime($date . ' ' . $parts[0]);
    $end       = strtotime($date . ' ' . $parts[1]);
    $interval  = max(15, intval($config['slot_interval'] ?? 30));
    $buffer    = intval($config['buffer_minutes'] ?? 0);

    // Get existing bookings for this date
    global $wpdb;
    wpilot_engage_ensure_booking_table();
    $table    = $wpdb->prefix . 'wpi_bookings';
    $booked   = $wpdb->get_col($wpdb->prepare(
        "SELECT time_slot FROM {$table} WHERE date = %s AND status != 'cancelled'",
        $date
    ));
    $booked_set = array_flip($booked);

    $slots = [];
    $cursor = $start;
    while ($cursor + ($duration * 60) <= $end) {
        $slot_str = gmdate('H:i', $cursor);
        if (!isset($booked_set[$slot_str])) {
            $slots[] = $slot_str;
        }
        $cursor += ($interval + $buffer) * 60;
    }

    wp_send_json_success(['slots' => $slots]);
}

add_action('wp_ajax_wpi_booking_submit', 'wpilot_ajax_booking_submit');
add_action('wp_ajax_nopriv_wpi_booking_submit', 'wpilot_ajax_booking_submit');

function wpilot_ajax_booking_submit() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['_wpi_nonce'] ?? '', 'wpi_booking_submit')) {
        wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.']);
    }

    // Honeypot
    if (!empty($_POST['wpi_hp_field'])) {
        wp_send_json_success(['message' => 'Booked']);
        return;
    }

    // Time check (< 3 seconds = bot)
    $ts = intval($_POST['wpi_ts'] ?? 0);
    if ($ts && (time() - $ts) < 3) {
        wp_send_json_success(['message' => 'Booked']);
        return;
    }

    $config = get_option('wpilot_booking_config', []);
    if (empty($config)) wp_send_json_error(['message' => 'Booking system not configured.']);

    $service = sanitize_text_field($_POST['service'] ?? '');
    $date    = sanitize_text_field($_POST['date'] ?? '');
    $slot    = sanitize_text_field($_POST['time_slot'] ?? '');
    $name    = sanitize_text_field($_POST['client_name'] ?? '');
    $email   = sanitize_email($_POST['client_email'] ?? '');
    $phone   = sanitize_text_field($_POST['client_phone'] ?? '');
    $notes   = sanitize_textarea_field($_POST['notes'] ?? '');

    if (!$service) wp_send_json_error(['message' => 'Please select a service.']);
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) wp_send_json_error(['message' => 'Please select a date.']);
    if (!$slot) wp_send_json_error(['message' => 'Please select a time slot.']);
    if (!$name) wp_send_json_error(['message' => 'Name is required.']);
    if (!is_email($email)) wp_send_json_error(['message' => 'Valid email is required.']);

    // Check slot still available (race condition guard)
    global $wpdb;
    wpilot_engage_ensure_booking_table();
    $table = $wpdb->prefix . 'wpi_bookings';
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE date = %s AND time_slot = %s AND status != 'cancelled'",
        $date, $slot
    ));
    if ($exists > 0) {
        wp_send_json_error(['message' => 'This time slot is no longer available. Please choose another.']);
    }

    // Validate date is within max_advance_days
    $max_days = intval($config['max_advance_days'] ?? 30);
    $date_ts  = strtotime($date);
    $today_ts = strtotime(current_time('Y-m-d'));
    if ($date_ts < $today_ts) wp_send_json_error(['message' => 'Cannot book in the past.']);
    if ($date_ts > $today_ts + ($max_days * 86400)) wp_send_json_error(['message' => "Cannot book more than {$max_days} days ahead."]);

    // Insert booking
    $wpdb->insert($table, [
        'service'      => $service,
        'client_name'  => $name,
        'client_email' => $email,
        'client_phone' => $phone,
        'date'         => $date,
        'time_slot'    => $slot,
        'status'       => 'pending',
        'notes'        => $notes,
        'created_at'   => current_time('mysql'),
    ], ['%s','%s','%s','%s','%s','%s','%s','%s','%s']);

    $booking_id = $wpdb->insert_id;

    // Fire hook for email automation sequences
    do_action('wpilot_booking_created', [
        'booking_id'   => $booking_id,
        'service'      => $service,
        'client_name'  => $name,
        'client_email' => $email,
        'date'         => $date,
        'time_slot'    => $slot,
    ]);

    // Send notification to admin
    $site = get_bloginfo('name');
    $admin_email = get_option('admin_email');
    $admin_body  = "New booking received!\n\n";
    $admin_body .= "Service: {$service}\n";
    $admin_body .= "Date: {$date}\n";
    $admin_body .= "Time: {$slot}\n";
    $admin_body .= "Client: {$name}\n";
    $admin_body .= "Email: {$email}\n";
    $admin_body .= "Phone: {$phone}\n";
    if ($notes) $admin_body .= "Notes: {$notes}\n";
    $admin_body .= "\nBooking ID: #{$booking_id}\n";
    $admin_body .= "Status: Pending confirmation";

    wp_mail(
        $admin_email,
        "[{$site}] New Booking: {$service} — {$date} {$slot}",
        $admin_body,
        ['Content-Type: text/plain; charset=UTF-8']
    );

    // Send confirmation to client if enabled
    if (!empty($config['confirmation_email'])) {
        $client_body = "Hi {$name},\n\n";
        $client_body .= "Thank you for your booking!\n\n";
        $client_body .= "Service: {$service}\n";
        $client_body .= "Date: {$date}\n";
        $client_body .= "Time: {$slot}\n\n";
        $client_body .= "We'll confirm your booking shortly.\n\n";
        $client_body .= "Best regards,\n{$site}";

        wp_mail(
            $email,
            "[{$site}] Booking Received — {$service}",
            $client_body,
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

    wp_send_json_success(['message' => 'Booking received! We will confirm shortly.', 'booking_id' => $booking_id]);
}


// ═══════════════════════════════════════════════════════════════
//  BOOKING — Shortcode [wpilot_booking]
// ═══════════════════════════════════════════════════════════════
function wpilot_render_booking_shortcode($atts) {
    $config = get_option('wpilot_booking_config', []);
    if (empty($config)) return '<!-- WPilot: booking system not configured -->';

    $services = $config['services'] ?? [];
    $hours    = $config['business_hours'] ?? [];
    $max_days = intval($config['max_advance_days'] ?? 30);
    $uid      = 'wpi-booking-' . wp_rand(1000, 9999);

    // Build closed days array for JS
    $closed_days = [];
    $day_map = ['sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6];
    foreach ($day_map as $day => $num) {
        if (empty($hours[$day])) $closed_days[] = $num;
    }

    ob_start();
    ?>
    <div class="wpi-booking-wrap" id="<?php echo esc_attr($uid); ?>">
        <form class="wpi-booking-form" novalidate>
            <?php wp_nonce_field('wpi_booking_submit', '_wpi_nonce'); ?>
            <input type="hidden" name="action" value="wpi_booking_submit">
            <!-- Honeypot -->
            <div style="position:absolute;left:-9999px;opacity:0;height:0;overflow:hidden" aria-hidden="true">
                <input type="text" name="wpi_hp_field" tabindex="-1" autocomplete="off">
            </div>
            <input type="hidden" name="wpi_ts" value="<?php echo esc_attr(time()); ?>">

            <!-- Step 1: Select service -->
            <div class="wpi-booking-step wpi-booking-step--active" data-step="1">
                <h3 class="wpi-booking-step-title">Select a Service</h3>
                <div class="wpi-booking-services">
                    <?php foreach ($services as $i => $svc): ?>
                    <label class="wpi-booking-service-card">
                        <input type="radio" name="service" value="<?php echo esc_attr($svc['name']); ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                        <span class="wpi-booking-service-name"><?php echo esc_html($svc['name']); ?></span>
                        <span class="wpi-booking-service-meta">
                            <?php echo esc_html($svc['duration_minutes']); ?> min
                            <?php if ($svc['price'] > 0): ?>
                                &middot; <?php echo esc_html(number_format($svc['price'], 2)); ?>
                            <?php else: ?>
                                &middot; Free
                            <?php endif; ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="wpi-btn wpi-booking-next" data-next="2">Continue</button>
            </div>

            <!-- Step 2: Pick date -->
            <div class="wpi-booking-step" data-step="2">
                <h3 class="wpi-booking-step-title">Pick a Date</h3>
                <div class="wpi-booking-calendar" id="<?php echo esc_attr($uid); ?>-cal"></div>
                <input type="hidden" name="date" class="wpi-booking-date-input">
                <div class="wpi-booking-nav-row">
                    <button type="button" class="wpi-btn wpi-btn--outline wpi-booking-prev" data-prev="1">Back</button>
                    <button type="button" class="wpi-btn wpi-booking-next" data-next="3" disabled>Continue</button>
                </div>
            </div>

            <!-- Step 3: Pick time slot -->
            <div class="wpi-booking-step" data-step="3">
                <h3 class="wpi-booking-step-title">Pick a Time</h3>
                <div class="wpi-booking-slots" id="<?php echo esc_attr($uid); ?>-slots">
                    <p class="wpi-booking-loading">Loading available times...</p>
                </div>
                <input type="hidden" name="time_slot" class="wpi-booking-slot-input">
                <div class="wpi-booking-nav-row">
                    <button type="button" class="wpi-btn wpi-btn--outline wpi-booking-prev" data-prev="2">Back</button>
                    <button type="button" class="wpi-btn wpi-booking-next" data-next="4" disabled>Continue</button>
                </div>
            </div>

            <!-- Step 4: Contact info -->
            <div class="wpi-booking-step" data-step="4">
                <h3 class="wpi-booking-step-title">Your Details</h3>
                <div class="wpi-field">
                    <label>Name <span class="wpi-required">*</span></label>
                    <input type="text" name="client_name" required placeholder="Your name">
                </div>
                <div class="wpi-field">
                    <label>Email <span class="wpi-required">*</span></label>
                    <input type="email" name="client_email" required placeholder="your@email.com">
                </div>
                <div class="wpi-field">
                    <label>Phone</label>
                    <input type="tel" name="client_phone" placeholder="Your phone number">
                </div>
                <div class="wpi-field">
                    <label>Notes</label>
                    <textarea name="notes" rows="3" placeholder="Any special requests..."></textarea>
                </div>
                <div class="wpi-booking-nav-row">
                    <button type="button" class="wpi-btn wpi-btn--outline wpi-booking-prev" data-prev="3">Back</button>
                    <button type="button" class="wpi-btn wpi-booking-next" data-next="5">Review</button>
                </div>
            </div>

            <!-- Step 5: Confirm -->
            <div class="wpi-booking-step" data-step="5">
                <h3 class="wpi-booking-step-title">Confirm Booking</h3>
                <div class="wpi-booking-summary"></div>
                <div class="wpi-booking-nav-row">
                    <button type="button" class="wpi-btn wpi-btn--outline wpi-booking-prev" data-prev="4">Back</button>
                    <button type="submit" class="wpi-btn">Confirm Booking</button>
                </div>
            </div>

            <div class="wpi-booking-msg" style="display:none"></div>
        </form>
    </div>
    <?php
    wpilot_booking_inline_assets($uid, $services, $closed_days, $max_days);
    return ob_get_clean();
}
add_shortcode('wpilot_booking', 'wpilot_render_booking_shortcode');


// ═══════════════════════════════════════════════════════════════
//  BOOKING — Inline CSS & JS (pure CSS+JS calendar, no libs)
// ═══════════════════════════════════════════════════════════════
function wpilot_booking_inline_assets($uid, $services, $closed_days, $max_days) {
    static $css_loaded = false;
    if (!$css_loaded) {
        $css_loaded = true;
        ?>
        <style>
        .wpi-booking-wrap{max-width:600px;margin:0 auto;font-family:inherit}
        .wpi-booking-step{display:none}
        .wpi-booking-step--active{display:block}
        .wpi-booking-step-title{margin:0 0 1em;font-size:1.2em;font-weight:700;color:var(--wp-text,#222)}
        .wpi-booking-services{display:flex;flex-direction:column;gap:.6em;margin-bottom:1.2em}
        .wpi-booking-service-card{display:flex;align-items:center;gap:.8em;padding:.9em 1em;border:2px solid var(--wp-border,#d0d0d0);border-radius:var(--wp-radius,6px);cursor:pointer;transition:border-color .2s,background .2s}
        .wpi-booking-service-card:hover{border-color:var(--wp-primary,#5B8DEF)}
        .wpi-booking-service-card input{margin:0}
        .wpi-booking-service-card input:checked~.wpi-booking-service-name{color:var(--wp-primary,#5B8DEF);font-weight:700}
        .wpi-booking-service-card:has(input:checked){border-color:var(--wp-primary,#5B8DEF);background:rgba(91,141,239,.05)}
        .wpi-booking-service-name{font-weight:600;color:var(--wp-text,#222)}
        .wpi-booking-service-meta{font-size:.85em;color:#888;margin-left:auto}
        .wpi-booking-calendar{margin-bottom:1em}
        .wpi-cal{border:1px solid var(--wp-border,#d0d0d0);border-radius:var(--wp-radius,6px);overflow:hidden;user-select:none}
        .wpi-cal-header{display:flex;justify-content:space-between;align-items:center;padding:.7em 1em;background:var(--wp-primary,#5B8DEF);color:#fff}
        .wpi-cal-header button{background:none;border:none;color:#fff;font-size:1.2em;cursor:pointer;padding:.2em .5em;border-radius:4px}
        .wpi-cal-header button:hover{background:rgba(255,255,255,.2)}
        .wpi-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);text-align:center}
        .wpi-cal-dow{padding:.5em;font-size:.75em;font-weight:700;color:#888;text-transform:uppercase}
        .wpi-cal-day{padding:.6em;cursor:pointer;border-radius:var(--wp-radius,6px);transition:background .15s,color .15s;font-size:.95em}
        .wpi-cal-day:hover:not(.wpi-cal-day--disabled){background:rgba(91,141,239,.1)}
        .wpi-cal-day--today{font-weight:700}
        .wpi-cal-day--selected{background:var(--wp-primary,#5B8DEF)!important;color:#fff!important;font-weight:700}
        .wpi-cal-day--disabled{color:#ccc;cursor:default;pointer-events:none}
        .wpi-cal-day--empty{pointer-events:none}
        .wpi-booking-slots{display:flex;flex-wrap:wrap;gap:.5em;margin-bottom:1em;min-height:48px}
        .wpi-booking-slot{padding:.5em 1em;border:2px solid var(--wp-border,#d0d0d0);border-radius:var(--wp-radius,6px);cursor:pointer;transition:border-color .2s,background .2s;font-size:.95em}
        .wpi-booking-slot:hover{border-color:var(--wp-primary,#5B8DEF)}
        .wpi-booking-slot--selected{border-color:var(--wp-primary,#5B8DEF);background:var(--wp-primary,#5B8DEF);color:#fff}
        .wpi-booking-loading{color:#888;font-style:italic}
        .wpi-booking-nav-row{display:flex;gap:.8em;margin-top:1.2em}
        .wpi-booking-summary{padding:1em;background:var(--wp-bg,#f8f9fa);border:1px solid var(--wp-border,#d0d0d0);border-radius:var(--wp-radius,6px);margin-bottom:1em}
        .wpi-booking-summary p{margin:.3em 0;font-size:.95em}
        .wpi-booking-summary strong{min-width:80px;display:inline-block}
        .wpi-btn--outline{background:transparent;color:var(--wp-primary,#5B8DEF);border:2px solid var(--wp-primary,#5B8DEF)}
        .wpi-btn--outline:hover{background:rgba(91,141,239,.08)}
        .wpi-booking-msg{padding:.8em 1em;border-radius:var(--wp-radius,6px);margin-top:1em;font-size:.95em}
        .wpi-booking-msg--ok{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
        .wpi-booking-msg--err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
        .wpi-booking-wrap .wpi-field{margin-bottom:1em}
        .wpi-booking-wrap .wpi-field label{display:block;margin-bottom:.3em;font-weight:600;color:var(--wp-text,#222);font-size:.95em}
        .wpi-booking-wrap .wpi-field input,.wpi-booking-wrap .wpi-field textarea{width:100%;padding:.7em .9em;border:1px solid var(--wp-border,#d0d0d0);border-radius:var(--wp-radius,6px);background:var(--wp-bg,#fff);color:var(--wp-text,#222);font-size:1em;box-sizing:border-box}
        .wpi-booking-wrap .wpi-field input:focus,.wpi-booking-wrap .wpi-field textarea:focus{outline:none;border-color:var(--wp-primary,#5B8DEF);box-shadow:0 0 0 3px rgba(91,141,239,.15)}
        .wpi-booking-wrap .wpi-field textarea{resize:vertical}
        .wpi-booking-wrap .wpi-btn{display:inline-block;padding:.75em 2em;background:var(--wp-primary,#5B8DEF);color:#fff;border:none;border-radius:var(--wp-radius,6px);font-size:1em;font-weight:600;cursor:pointer;transition:opacity .2s}
        .wpi-booking-wrap .wpi-btn:hover{opacity:.9}
        .wpi-booking-wrap .wpi-btn:disabled{opacity:.5;cursor:not-allowed}
        .wpi-required{color:#e53e3e}
        @media(max-width:480px){.wpi-booking-nav-row{flex-direction:column}.wpi-booking-slot{flex:1 1 45%}}
        </style>
        <?php
    }
    $ajax_url    = esc_url(admin_url('admin-ajax.php'));
    $closed_json = wp_json_encode($closed_days);
    $uid_js      = wp_json_encode($uid);
    $cal_id_js   = wp_json_encode($uid . '-cal');
    $slots_id_js = wp_json_encode($uid . '-slots');
    ?>
    <script>
    (function(){
        var wrap=document.getElementById(<?php echo $uid_js; ?>);
        if(!wrap)return;
        var form=wrap.querySelector('.wpi-booking-form');
        var ajaxUrl=<?php echo wp_json_encode($ajax_url); ?>;
        var closedDays=<?php echo $closed_json; ?>;
        var maxDays=<?php echo intval($max_days); ?>;
        var currentStep=1;
        var selectedDate='';
        var selectedSlot='';

        function showStep(n){
            var steps=wrap.querySelectorAll('.wpi-booking-step');
            for(var i=0;i<steps.length;i++){steps[i].classList.remove('wpi-booking-step--active');}
            var s=wrap.querySelector('[data-step="'+n+'"]');
            if(s)s.classList.add('wpi-booking-step--active');
            currentStep=n;
            if(n===5)buildSummary();
        }

        wrap.addEventListener('click',function(e){
            var btn=e.target.closest('.wpi-booking-next');
            if(btn){
                var next=parseInt(btn.getAttribute('data-next'));
                if(next===3&&selectedDate)loadSlots();
                showStep(next);
                return;
            }
            var back=e.target.closest('.wpi-booking-prev');
            if(back){showStep(parseInt(back.getAttribute('data-prev')));return;}
        });

        // ── Pure CSS+JS Calendar ──
        var calEl=document.getElementById(<?php echo $cal_id_js; ?>);
        var today=new Date();today.setHours(0,0,0,0);
        var viewMonth=today.getMonth();
        var viewYear=today.getFullYear();

        function pad(n){return n<10?'0'+n:''+n;}
        function isoDate(d){return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate());}

        function renderCal(){
            var first=new Date(viewYear,viewMonth,1);
            var lastDay=new Date(viewYear,viewMonth+1,0).getDate();
            var startDay=first.getDay();
            var months=['January','February','March','April','May','June','July','August','September','October','November','December'];
            var dows=['Su','Mo','Tu','We','Th','Fr','Sa'];
            var maxDate=new Date(today);maxDate.setDate(maxDate.getDate()+maxDays);

            // Build calendar using DOM methods
            var cal=document.createElement('div');cal.className='wpi-cal';

            // Header
            var header=document.createElement('div');header.className='wpi-cal-header';
            var prevBtn=document.createElement('button');prevBtn.type='button';prevBtn.className='wpi-cal-prev';prevBtn.textContent='\u2039';
            var title=document.createElement('span');title.textContent=months[viewMonth]+' '+viewYear;
            var nextBtn=document.createElement('button');nextBtn.type='button';nextBtn.className='wpi-cal-next-m';nextBtn.textContent='\u203A';
            header.appendChild(prevBtn);header.appendChild(title);header.appendChild(nextBtn);
            cal.appendChild(header);

            // Grid
            var grid=document.createElement('div');grid.className='wpi-cal-grid';
            for(var d=0;d<7;d++){var dow=document.createElement('div');dow.className='wpi-cal-dow';dow.textContent=dows[d];grid.appendChild(dow);}
            for(var e=0;e<startDay;e++){var empty=document.createElement('div');empty.className='wpi-cal-day wpi-cal-day--empty';grid.appendChild(empty);}
            for(var i=1;i<=lastDay;i++){
                var dt=new Date(viewYear,viewMonth,i);
                var iso=isoDate(dt);
                var cell=document.createElement('div');
                cell.className='wpi-cal-day';
                cell.setAttribute('data-date',iso);
                cell.textContent=i;
                var isToday=dt.getTime()===today.getTime();
                if(isToday)cell.classList.add('wpi-cal-day--today');
                if(iso===selectedDate)cell.classList.add('wpi-cal-day--selected');
                var disabled=dt<today||dt>maxDate||closedDays.indexOf(dt.getDay())!==-1;
                if(disabled)cell.classList.add('wpi-cal-day--disabled');
                grid.appendChild(cell);
            }
            cal.appendChild(grid);

            // Replace content
            while(calEl.firstChild)calEl.removeChild(calEl.firstChild);
            calEl.appendChild(cal);

            // Event listeners
            prevBtn.addEventListener('click',function(ev){
                ev.preventDefault();
                if(viewMonth===0){viewMonth=11;viewYear--;}else viewMonth--;
                renderCal();
            });
            nextBtn.addEventListener('click',function(ev){
                ev.preventDefault();
                if(viewMonth===11){viewMonth=0;viewYear++;}else viewMonth++;
                renderCal();
            });

            var days=grid.querySelectorAll('.wpi-cal-day:not(.wpi-cal-day--disabled):not(.wpi-cal-day--empty)');
            for(var j=0;j<days.length;j++){
                days[j].addEventListener('click',function(){
                    var prev=grid.querySelector('.wpi-cal-day--selected');
                    if(prev)prev.classList.remove('wpi-cal-day--selected');
                    this.classList.add('wpi-cal-day--selected');
                    selectedDate=this.getAttribute('data-date');
                    form.querySelector('.wpi-booking-date-input').value=selectedDate;
                    var nb=wrap.querySelector('[data-step="2"] .wpi-booking-next');
                    if(nb)nb.disabled=false;
                });
            }
        }
        renderCal();

        // ── Load time slots via AJAX ──
        function loadSlots(){
            var slotsEl=document.getElementById(<?php echo $slots_id_js; ?>);
            // Clear slots safely
            while(slotsEl.firstChild)slotsEl.removeChild(slotsEl.firstChild);
            var loadMsg=document.createElement('p');loadMsg.className='wpi-booking-loading';loadMsg.textContent='Loading available times...';
            slotsEl.appendChild(loadMsg);
            selectedSlot='';
            form.querySelector('.wpi-booking-slot-input').value='';
            var nb=wrap.querySelector('[data-step="3"] .wpi-booking-next');
            if(nb)nb.disabled=true;

            var svc=form.querySelector('[name="service"]:checked');
            var fd=new FormData();
            fd.append('action','wpi_booking_slots');
            fd.append('date',selectedDate);
            fd.append('service',svc?svc.value:'');

            var xhr=new XMLHttpRequest();
            xhr.open('POST',ajaxUrl);
            xhr.onload=function(){
                try{
                    var r=JSON.parse(xhr.responseText);
                    var slots=(r.data&&r.data.slots)?r.data.slots:[];
                    while(slotsEl.firstChild)slotsEl.removeChild(slotsEl.firstChild);
                    if(!slots.length){
                        var noSlot=document.createElement('p');noSlot.className='wpi-booking-loading';noSlot.textContent='No available times for this date.';
                        slotsEl.appendChild(noSlot);
                        return;
                    }
                    for(var i=0;i<slots.length;i++){
                        var slotDiv=document.createElement('div');
                        slotDiv.className='wpi-booking-slot';
                        slotDiv.setAttribute('data-slot',slots[i]);
                        slotDiv.textContent=slots[i];
                        slotsEl.appendChild(slotDiv);
                    }
                    var slotEls=slotsEl.querySelectorAll('.wpi-booking-slot');
                    for(var j=0;j<slotEls.length;j++){
                        slotEls[j].addEventListener('click',function(){
                            var prev=slotsEl.querySelector('.wpi-booking-slot--selected');
                            if(prev)prev.classList.remove('wpi-booking-slot--selected');
                            this.classList.add('wpi-booking-slot--selected');
                            selectedSlot=this.getAttribute('data-slot');
                            form.querySelector('.wpi-booking-slot-input').value=selectedSlot;
                            var nb2=wrap.querySelector('[data-step="3"] .wpi-booking-next');
                            if(nb2)nb2.disabled=false;
                        });
                    }
                }catch(ex){
                    while(slotsEl.firstChild)slotsEl.removeChild(slotsEl.firstChild);
                    var errMsg=document.createElement('p');errMsg.className='wpi-booking-loading';errMsg.textContent='Error loading times.';
                    slotsEl.appendChild(errMsg);
                }
            };
            xhr.onerror=function(){
                while(slotsEl.firstChild)slotsEl.removeChild(slotsEl.firstChild);
                var errMsg=document.createElement('p');errMsg.className='wpi-booking-loading';errMsg.textContent='Connection error.';
                slotsEl.appendChild(errMsg);
            };
            xhr.send(fd);
        }

        // ── Build confirmation summary ──
        function buildSummary(){
            var sum=wrap.querySelector('.wpi-booking-summary');
            while(sum.firstChild)sum.removeChild(sum.firstChild);
            var svc=form.querySelector('[name="service"]:checked');
            var fields=[
                ['Service',svc?svc.value:''],
                ['Date',selectedDate],
                ['Time',selectedSlot],
                ['Name',form.querySelector('[name="client_name"]').value||''],
                ['Email',form.querySelector('[name="client_email"]').value||''],
                ['Phone',form.querySelector('[name="client_phone"]').value||'-']
            ];
            var notes=form.querySelector('[name="notes"]').value;
            if(notes)fields.push(['Notes',notes]);
            for(var f=0;f<fields.length;f++){
                var p=document.createElement('p');
                var strong=document.createElement('strong');strong.textContent=fields[f][0]+': ';
                p.appendChild(strong);
                p.appendChild(document.createTextNode(fields[f][1]));
                sum.appendChild(p);
            }
        }

        // ── Submit booking ──
        form.addEventListener('submit',function(e){
            e.preventDefault();
            if(form.classList.contains('wpi-sending'))return;
            form.classList.add('wpi-sending');
            var submitBtn=form.querySelector('[type="submit"]');
            if(submitBtn)submitBtn.disabled=true;

            var msgEl=wrap.querySelector('.wpi-booking-msg');
            msgEl.style.display='none';

            var fd=new FormData(form);
            var xhr=new XMLHttpRequest();
            xhr.open('POST',ajaxUrl);
            xhr.onload=function(){
                form.classList.remove('wpi-sending');
                if(submitBtn)submitBtn.disabled=false;
                try{
                    var r=JSON.parse(xhr.responseText);
                    if(r.success){
                        msgEl.textContent=(r.data&&r.data.message)?r.data.message:'Booking confirmed!';
                        msgEl.className='wpi-booking-msg wpi-booking-msg--ok';
                        var steps=wrap.querySelectorAll('.wpi-booking-step');
                        for(var i=0;i<steps.length;i++)steps[i].style.display='none';
                    }else{
                        msgEl.textContent=(r.data&&r.data.message)?r.data.message:'Something went wrong.';
                        msgEl.className='wpi-booking-msg wpi-booking-msg--err';
                    }
                }catch(ex){
                    msgEl.textContent='Server error.';
                    msgEl.className='wpi-booking-msg wpi-booking-msg--err';
                }
                msgEl.style.display='block';
            };
            xhr.onerror=function(){
                form.classList.remove('wpi-sending');
                if(submitBtn)submitBtn.disabled=false;
                msgEl.textContent='Connection error.';
                msgEl.className='wpi-booking-msg wpi-booking-msg--err';
                msgEl.style.display='block';
            };
            xhr.send(fd);
        });
    })();
    </script>
    <?php
}

// Localize AJAX URL for frontend booking
add_action('wp_head', function() {
    global $post;
    if (!is_a($post, 'WP_Post')) return;
    if (has_shortcode($post->post_content, 'wpilot_booking')) {
        echo '<script>var wpi_booking_ajax={url:"' . esc_url(admin_url('admin-ajax.php')) . '"};</script>' . "\n";
    }
});


// ═══════════════════════════════════════════════════════════════
//  BOOKING — Generate mu-plugin code
// ═══════════════════════════════════════════════════════════════
function wpilot_booking_generate_mu_code() {
    return '
// WPilot Booking Widget — ensures AJAX endpoints available on frontend
add_action("wp_ajax_wpi_booking_slots", function(){ do_action("wpilot_booking_slots_proxy"); });
add_action("wp_ajax_nopriv_wpi_booking_slots", function(){ do_action("wpilot_booking_slots_proxy"); });
add_action("wp_ajax_wpi_booking_submit", function(){ do_action("wpilot_booking_submit_proxy"); });
add_action("wp_ajax_nopriv_wpi_booking_submit", function(){ do_action("wpilot_booking_submit_proxy"); });
';
}


// ═══════════════════════════════════════════════════════════════
//  LIVE CHAT WIDGET — 7. enable_live_chat
// ═══════════════════════════════════════════════════════════════
// Built by Weblease
function wpilot_enable_live_chat($params) {
    wpilot_engage_ensure_chat_table();

    $greeting        = sanitize_text_field($params['greeting'] ?? 'Hi! How can we help?');
    $position        = in_array($params['position'] ?? 'bottom-right', ['bottom-right', 'bottom-left']) ? $params['position'] : 'bottom-right';
    $require_email   = isset($params['require_email']) ? (bool)$params['require_email'] : true;
    $notification_email = sanitize_email($params['notification_email'] ?? get_option('admin_email'));
    $auto_reply      = sanitize_text_field($params['auto_reply'] ?? '');
    $online_hours    = sanitize_text_field($params['online_hours'] ?? '');
    $offline_message = sanitize_text_field($params['offline_message'] ?? 'We are currently offline. Leave us a message and we will get back to you!');

    if ($online_hours && !preg_match('/^\d{2}:\d{2}-\d{2}:\d{2}$/', $online_hours)) {
        $online_hours = '';
    }

    $chat_config = [
        'enabled'            => true,
        'greeting'           => $greeting,
        'position'           => $position,
        'require_email'      => $require_email,
        'notification_email' => $notification_email,
        'auto_reply'         => $auto_reply,
        'online_hours'       => $online_hours,
        'offline_message'    => $offline_message,
        'created_at'         => current_time('mysql'),
    ];
    update_option('wpilot_chat_config', $chat_config, false);

    // Generate and register mu-plugin for chat widget injection
    $mu_code = wpilot_chat_generate_mu_code($chat_config);
    wpilot_mu_register('live-chat', $mu_code);

    return wpilot_ok('Live chat widget enabled (' . $position . '). Greeting: "' . $greeting . '"', [
        'position'      => $position,
        'require_email' => $require_email,
        'online_hours'  => $online_hours ?: 'always',
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  LIVE CHAT WIDGET — 8. disable_live_chat
// ═══════════════════════════════════════════════════════════════
function wpilot_disable_live_chat($params) {
    $config = get_option('wpilot_chat_config', []);
    $config['enabled'] = false;
    update_option('wpilot_chat_config', $config, false);

    wpilot_mu_remove('live-chat');

    return wpilot_ok('Live chat widget disabled and removed.');
}

// ═══════════════════════════════════════════════════════════════
//  LIVE CHAT WIDGET — 9. list_chat_messages
// ═══════════════════════════════════════════════════════════════
function wpilot_list_chat_messages($params) {
    global $wpdb;
    wpilot_engage_ensure_chat_table();

    $table  = $wpdb->prefix . 'wpi_chat_messages';
    $status = sanitize_text_field($params['status'] ?? 'all');
    $limit  = max(1, min(200, intval($params['limit'] ?? 50)));

    if ($status !== 'all') {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
            $status, $limit
        ), ARRAY_A);
    } else {
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    $unread = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'unread'");

    return wpilot_ok(count($rows) . ' message(s) found. ' . $unread . ' unread.', [
        'messages' => $rows,
        'unread'   => $unread,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  LIVE CHAT WIDGET — 10. reply_chat
// ═══════════════════════════════════════════════════════════════
function wpilot_reply_chat($params) {
    global $wpdb;
    wpilot_engage_ensure_chat_table();

    $id    = intval($params['message_id'] ?? $params['id'] ?? 0);
    $reply = sanitize_textarea_field($params['reply'] ?? '');
    $table = $wpdb->prefix . 'wpi_chat_messages';

    if (!$id) return wpilot_err('message_id is required.');
    if (!$reply) return wpilot_err('Reply text is required.');

    $msg = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
    if (!$msg) return wpilot_err("Message #{$id} not found.");

    $wpdb->update($table, [
        'admin_reply' => $reply,
        'status'      => 'replied',
        'replied_at'  => current_time('mysql'),
    ], ['id' => $id], ['%s', '%s', '%s'], ['%d']);

    // Send reply email to visitor
    if (is_email($msg['visitor_email'])) {
        $site = get_bloginfo('name');
        $body = "Hi" . ($msg['visitor_name'] ? " {$msg['visitor_name']}" : "") . ",\n\n";
        $body .= "Thank you for your message. Here is our reply:\n\n";
        $body .= $reply . "\n\n";
        $body .= "Your original message:\n\"{$msg['message']}\"\n\n";
        $body .= "Best regards,\n{$site}";

        wp_mail(
            $msg['visitor_email'],
            "[{$site}] Reply to your message",
            $body,
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }

    return wpilot_ok("Reply sent to message #{$id}." . (is_email($msg['visitor_email']) ? " Email sent to {$msg['visitor_email']}." : ''));
}


// ═══════════════════════════════════════════════════════════════
//  LIVE CHAT — Generate mu-plugin code for widget injection
// ═══════════════════════════════════════════════════════════════
function wpilot_chat_generate_mu_code($config) {
    $position_css = ($config['position'] ?? 'bottom-right') === 'bottom-left' ? 'left' : 'right';
    $require_email_php = ($config['require_email'] ?? true) ? 'true' : 'false';

    return '
// WPilot Live Chat Widget — injected via mu-plugin
add_action("wp_footer", function(){
    if(is_admin()) return;
    $cfg = get_option("wpilot_chat_config", []);
    if(empty($cfg["enabled"])) return;
    $greeting = esc_attr($cfg["greeting"] ?? "Hi! How can we help?");
    $auto_reply = esc_attr($cfg["auto_reply"] ?? "");
    $online_hours = esc_attr($cfg["online_hours"] ?? "");
    $offline_msg = esc_attr($cfg["offline_message"] ?? "");
    $ajax_url = esc_url(admin_url("admin-ajax.php"));
    $nonce = wp_create_nonce("wpi_chat_submit");
    $require_email = ' . $require_email_php . ';
    $pos_css = "' . $position_css . '";
    ?>
    <style>
    .wpi-chat-widget{position:fixed;bottom:20px;<?php echo $pos_css; ?>:20px;width:56px;height:56px;border-radius:50%;background:var(--wp-primary,#5B8DEF);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.2);z-index:99999;transition:transform .2s;font-size:24px}
    .wpi-chat-widget:hover{transform:scale(1.08)}
    .wpi-chat-dot{position:absolute;bottom:2px;right:2px;width:12px;height:12px;border-radius:50%;border:2px solid #fff}
    .wpi-chat-dot--online{background:#10b981}
    .wpi-chat-dot--offline{background:#9ca3af}
    .wpi-chat-panel{position:fixed;bottom:86px;<?php echo $pos_css; ?>:20px;width:360px;max-width:calc(100vw - 40px);max-height:480px;background:var(--wp-bg,#fff);border:1px solid var(--wp-border,#e0e0e0);border-radius:var(--wp-radius,12px);box-shadow:0 8px 32px rgba(0,0,0,.15);z-index:99999;display:none;flex-direction:column;overflow:hidden;font-family:inherit}
    .wpi-chat-panel--open{display:flex}
    .wpi-chat-head{padding:14px 16px;background:var(--wp-primary,#5B8DEF);color:#fff;font-weight:700;font-size:1em;display:flex;align-items:center;justify-content:space-between}
    .wpi-chat-close{background:none;border:none;color:#fff;font-size:1.3em;cursor:pointer;padding:0 4px;opacity:.8}
    .wpi-chat-close:hover{opacity:1}
    .wpi-chat-body{flex:1;padding:16px;overflow-y:auto;display:flex;flex-direction:column;gap:10px}
    .wpi-chat-greeting{background:rgba(91,141,239,.08);padding:10px 14px;border-radius:12px 12px 12px 2px;font-size:.95em;color:var(--wp-text,#333);max-width:85%;line-height:1.5}
    .wpi-chat-form{padding:12px 16px;border-top:1px solid var(--wp-border,#e0e0e0)}
    .wpi-chat-form input,.wpi-chat-form textarea{width:100%;padding:.55em .7em;border:1px solid var(--wp-border,#d0d0d0);border-radius:var(--wp-radius,6px);font-size:.9em;box-sizing:border-box;margin-bottom:8px;background:var(--wp-bg,#fff);color:var(--wp-text,#222)}
    .wpi-chat-form input:focus,.wpi-chat-form textarea:focus{outline:none;border-color:var(--wp-primary,#5B8DEF)}
    .wpi-chat-form textarea{resize:none;min-height:60px}
    .wpi-chat-send{width:100%;padding:.6em;background:var(--wp-primary,#5B8DEF);color:#fff;border:none;border-radius:var(--wp-radius,6px);font-weight:600;cursor:pointer;font-size:.9em}
    .wpi-chat-send:hover{opacity:.9}
    .wpi-chat-send:disabled{opacity:.5;cursor:not-allowed}
    .wpi-chat-sent{text-align:center;color:#15803d;padding:20px;font-size:.95em}
    @media(max-width:400px){.wpi-chat-panel{width:calc(100vw - 20px);bottom:80px;<?php echo $pos_css; ?>:10px}}
    </style>
    <div class="wpi-chat-widget" id="wpi-chat-toggle">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        <span class="wpi-chat-dot" id="wpi-chat-dot"></span>
    </div>
    <div class="wpi-chat-panel" id="wpi-chat-panel">
        <div class="wpi-chat-head">
            <span>Chat with us</span>
            <button class="wpi-chat-close" id="wpi-chat-close" type="button">&times;</button>
        </div>
        <div class="wpi-chat-body" id="wpi-chat-body">
            <div class="wpi-chat-greeting"><?php echo esc_html($cfg["greeting"]); ?></div>
        </div>
        <div class="wpi-chat-form" id="wpi-chat-form-wrap">
            <form id="wpi-chat-form">
                <input type="hidden" name="action" value="wpi_chat_submit">
                <input type="hidden" name="_wpi_nonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="text" name="visitor_name" placeholder="Your name" required>
                <?php if($require_email): ?>
                <input type="email" name="visitor_email" placeholder="Your email" required>
                <?php else: ?>
                <input type="email" name="visitor_email" placeholder="Your email (optional)">
                <?php endif; ?>
                <textarea name="message" placeholder="Type your message..." required></textarea>
                <button type="submit" class="wpi-chat-send">Send Message</button>
            </form>
        </div>
    </div>
    <script>
    (function(){
        var toggle=document.getElementById("wpi-chat-toggle");
        var panel=document.getElementById("wpi-chat-panel");
        var closeBtn=document.getElementById("wpi-chat-close");
        var dot=document.getElementById("wpi-chat-dot");
        var chatForm=document.getElementById("wpi-chat-form");
        var oh="<?php echo esc_js($online_hours); ?>";
        function isOnline(){
            if(!oh)return true;
            var parts=oh.split("-");if(parts.length!==2)return true;
            var now=new Date();var h=now.getHours();var m=now.getMinutes();var cur=h*60+m;
            var sp=parts[0].split(":");var ep=parts[1].split(":");
            var start=parseInt(sp[0])*60+parseInt(sp[1]);
            var end=parseInt(ep[0])*60+parseInt(ep[1]);
            return cur>=start&&cur<end;
        }
        dot.className="wpi-chat-dot "+(isOnline()?"wpi-chat-dot--online":"wpi-chat-dot--offline");
        toggle.addEventListener("click",function(){panel.classList.toggle("wpi-chat-panel--open");});
        closeBtn.addEventListener("click",function(){panel.classList.remove("wpi-chat-panel--open");});

        chatForm.addEventListener("submit",function(e){
            e.preventDefault();
            var btn=chatForm.querySelector(".wpi-chat-send");
            btn.disabled=true;btn.textContent="Sending...";
            var fd=new FormData(chatForm);
            fd.append("page_url",window.location.href);
            var xhr=new XMLHttpRequest();
            xhr.open("POST","<?php echo esc_js($ajax_url); ?>");
            xhr.onload=function(){
                try{
                    var r=JSON.parse(xhr.responseText);
                    if(r.success){
                        var fw=document.getElementById("wpi-chat-form-wrap");
                        var autoReply="<?php echo esc_js($auto_reply); ?>";
                        var sentDiv=document.createElement("div");
                        sentDiv.className="wpi-chat-sent";
                        sentDiv.textContent="Message sent!";
                        if(autoReply){
                            var br=document.createElement("br");sentDiv.appendChild(br);
                            var br2=document.createElement("br");sentDiv.appendChild(br2);
                            sentDiv.appendChild(document.createTextNode(autoReply));
                        }
                        while(fw.firstChild)fw.removeChild(fw.firstChild);
                        fw.appendChild(sentDiv);
                    }else{
                        btn.disabled=false;btn.textContent="Send Message";
                    }
                }catch(ex){btn.disabled=false;btn.textContent="Send Message";}
            };
            xhr.onerror=function(){btn.disabled=false;btn.textContent="Send Message";};
            xhr.send(fd);
        });
    })();
    </script>
    <?php
});

// Chat AJAX handler
add_action("wp_ajax_wpi_chat_submit", "wpilot_engage_chat_ajax_handler");
add_action("wp_ajax_nopriv_wpi_chat_submit", "wpilot_engage_chat_ajax_handler");
if(!function_exists("wpilot_engage_chat_ajax_handler")){
function wpilot_engage_chat_ajax_handler(){
    if(!wp_verify_nonce($_POST["_wpi_nonce"] ?? "","wpi_chat_submit")){
        wp_send_json_error(["message"=>"Security check failed."]);
    }
    global $wpdb;
    $table = $wpdb->prefix . "wpi_chat_messages";
    if($wpdb->get_var("SHOW TABLES LIKE \x27".$table."\x27")!==$table){
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . "wp-admin/includes/upgrade.php";
        dbDelta("CREATE TABLE ".$table." (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            visitor_name VARCHAR(200) DEFAULT \x27\x27,
            visitor_email VARCHAR(200) DEFAULT \x27\x27,
            message TEXT NOT NULL,
            page_url VARCHAR(500) DEFAULT \x27\x27,
            status VARCHAR(30) DEFAULT \x27unread\x27,
            admin_reply TEXT DEFAULT \x27\x27,
            replied_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ".$charset);
    }
    $name = sanitize_text_field(wp_unslash($_POST["visitor_name"] ?? ""));
    $email = sanitize_email($_POST["visitor_email"] ?? "");
    $message = sanitize_textarea_field(wp_unslash($_POST["message"] ?? ""));
    $page = esc_url_raw($_POST["page_url"] ?? "");
    if(!$message){wp_send_json_error(["message"=>"Message is required."]);}
    $wpdb->insert($table,[
        "visitor_name"=>$name,"visitor_email"=>$email,"message"=>$message,
        "page_url"=>$page,"status"=>"unread","created_at"=>current_time("mysql")
    ],["%s","%s","%s","%s","%s","%s"]);
    $cfg = get_option("wpilot_chat_config",[]);
    $to = !empty($cfg["notification_email"]) ? $cfg["notification_email"] : get_option("admin_email");
    $site = get_bloginfo("name");
    $body = "New chat message on {$site}\n\nFrom: {$name}\nEmail: {$email}\nPage: {$page}\n\nMessage:\n{$message}";
    wp_mail($to,"[{$site}] New chat message from {$name}",$body,["Content-Type: text/plain; charset=UTF-8"]);
    wp_send_json_success(["message"=>"Sent"]);
}
}
';
}


// ═══════════════════════════════════════════════════════════════
//  COMPETITOR ANALYSIS — 11. analyze_competitor
// ═══════════════════════════════════════════════════════════════
function wpilot_analyze_competitor($params) {
    $url = esc_url_raw($params['url'] ?? '');
    if (!$url) return wpilot_err('URL is required.');

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return wpilot_err('Invalid URL format.');
    }

    $start_time = microtime(true);
    $response = wp_remote_get($url, [
        'timeout'    => 15,
        'user-agent' => 'Mozilla/5.0 (compatible; WPilot Site Analyzer)',
        'sslverify'  => false,
    ]);

    if (is_wp_error($response)) {
        return wpilot_err('Could not fetch URL: ' . $response->get_error_message());
    }

    $load_time = round(microtime(true) - $start_time, 2);
    $body = wp_remote_retrieve_body($response);
    $code = wp_remote_retrieve_response_code($response);

    if ($code !== 200) {
        return wpilot_err("URL returned HTTP {$code}.");
    }

    $analysis = [
        'url'       => $url,
        'load_time' => $load_time . 's',
    ];

    // Page title
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
        $analysis['title'] = html_entity_decode(strip_tags(trim($m[1])), ENT_QUOTES, 'UTF-8');
    }

    // Meta description
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']/is', $body, $m)) {
        $analysis['meta_description'] = html_entity_decode(trim($m[1]), ENT_QUOTES, 'UTF-8');
    }

    // H1 tags
    preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $body, $h1s);
    $analysis['h1_tags'] = array_map(function($h) {
        return html_entity_decode(strip_tags(trim($h)), ENT_QUOTES, 'UTF-8');
    }, $h1s[1] ?? []);

    // Color scheme from inline styles and CSS
    $colors = [];
    preg_match_all('/(?:color|background(?:-color)?)\s*:\s*(#[0-9a-fA-F]{3,8}|rgb[a]?\([^)]+\))/i', $body, $cm);
    if (!empty($cm[1])) {
        $colors = array_unique(array_slice($cm[1], 0, 10));
    }
    $analysis['colors'] = array_values($colors);

    // Fonts — Google Fonts links and font-face
    $fonts = [];
    preg_match_all('/fonts\.googleapis\.com\/css2?\?family=([^"&\s]+)/i', $body, $fm);
    if (!empty($fm[1])) {
        foreach ($fm[1] as $f) {
            $decoded = urldecode($f);
            $parts = explode('|', $decoded);
            foreach ($parts as $p) {
                $name = explode(':', $p)[0];
                $name = str_replace('+', ' ', $name);
                if ($name && !in_array($name, $fonts)) $fonts[] = $name;
            }
        }
    }
    preg_match_all('/font-family\s*:\s*["\']?([^"\';\}]+)/i', $body, $ff);
    if (!empty($ff[1])) {
        $skip_fonts = ['inherit','initial','unset','sans-serif','serif','monospace','cursive','fantasy','system-ui'];
        foreach (array_slice($ff[1], 0, 5) as $f) {
            $f = trim($f, " \t\n\r'\"");
            if ($f && !in_array($f, $fonts) && !in_array(strtolower($f), $skip_fonts)) {
                $fonts[] = $f;
            }
        }
    }
    $analysis['fonts'] = array_values(array_unique($fonts));

    // Technology detection
    $tech = [];
    if (stripos($body, 'wp-content') !== false || stripos($body, 'wp-includes') !== false) $tech[] = 'WordPress';
    if (stripos($body, 'Shopify') !== false || stripos($body, 'cdn.shopify.com') !== false) $tech[] = 'Shopify';
    if (stripos($body, 'wix.com') !== false || stripos($body, 'wixsite') !== false) $tech[] = 'Wix';
    if (stripos($body, 'squarespace') !== false) $tech[] = 'Squarespace';
    if (stripos($body, 'webflow') !== false) $tech[] = 'Webflow';

    // WordPress plugin detection
    if (in_array('WordPress', $tech)) {
        if (stripos($body, 'elementor') !== false) $tech[] = 'Elementor';
        if (stripos($body, 'divi') !== false || stripos($body, 'et-boc') !== false) $tech[] = 'Divi';
        if (stripos($body, 'woocommerce') !== false) $tech[] = 'WooCommerce';
        if (stripos($body, 'yoast') !== false || stripos($body, 'yoast-seo') !== false) $tech[] = 'Yoast SEO';
        if (stripos($body, 'rank-math') !== false) $tech[] = 'Rank Math';
        if (stripos($body, 'contact-form-7') !== false) $tech[] = 'Contact Form 7';
        if (stripos($body, 'wpbakery') !== false || stripos($body, 'js_composer') !== false) $tech[] = 'WPBakery';
        if (stripos($body, 'litespeed') !== false) $tech[] = 'LiteSpeed Cache';
        if (stripos($body, 'wp-rocket') !== false) $tech[] = 'WP Rocket';
    }

    // JS frameworks
    if (stripos($body, 'react') !== false || stripos($body, 'reactdom') !== false) $tech[] = 'React';
    if (stripos($body, 'vue.js') !== false || stripos($body, 'vue.min.js') !== false) $tech[] = 'Vue.js';
    if (stripos($body, 'jquery') !== false) $tech[] = 'jQuery';
    if (stripos($body, 'bootstrap') !== false) $tech[] = 'Bootstrap';
    if (stripos($body, 'tailwind') !== false) $tech[] = 'Tailwind CSS';

    $analysis['technology'] = array_unique($tech);

    // Content stats
    $text = strip_tags($body);
    $analysis['word_count'] = str_word_count($text);

    // Image count
    preg_match_all('/<img[^>]+>/i', $body, $imgs);
    $analysis['image_count'] = count($imgs[0] ?? []);

    // SSL check
    $analysis['has_ssl'] = strpos($url, 'https://') === 0;

    // Suggestions
    $suggestions = [];
    if (empty($analysis['meta_description'])) $suggestions[] = 'They lack a meta description — you can gain SEO advantage here.';
    if (count($analysis['h1_tags'] ?? []) === 0) $suggestions[] = 'No H1 tag found — poor SEO structure.';
    if (count($analysis['h1_tags'] ?? []) > 1) $suggestions[] = 'Multiple H1 tags — their heading structure may be suboptimal.';
    if ($load_time > 3) $suggestions[] = 'Their page loads slowly (' . $load_time . 's) — speed could be your advantage.';
    if (count($analysis['fonts'] ?? []) > 3) $suggestions[] = 'They use ' . count($analysis['fonts']) . ' fonts — this can slow their page.';
    $analysis['suggestions'] = $suggestions;

    return wpilot_ok("Competitor analysis complete for {$url}.", ['analysis' => $analysis]);
}

// ═══════════════════════════════════════════════════════════════
//  COMPETITOR ANALYSIS — 12. compare_with_competitor
// ═══════════════════════════════════════════════════════════════
function wpilot_compare_with_competitor($params) {
    $competitor_url = esc_url_raw($params['competitor_url'] ?? $params['url'] ?? '');
    if (!$competitor_url) return wpilot_err('competitor_url is required.');

    // Analyze competitor
    $competitor = wpilot_analyze_competitor(['url' => $competitor_url]);
    if (empty($competitor['success'])) {
        return wpilot_err('Failed to analyze competitor: ' . ($competitor['message'] ?? 'Unknown error'));
    }

    // Analyze own site
    $own = wpilot_analyze_competitor(['url' => home_url('/')]);
    if (empty($own['success'])) {
        return wpilot_err('Failed to analyze own site: ' . ($own['message'] ?? 'Unknown error'));
    }

    $comp_data = $competitor['analysis'] ?? [];
    $own_data  = $own['analysis'] ?? [];

    $comparison = [
        'your_site'  => $own_data,
        'competitor'  => $comp_data,
    ];

    $you_better  = [];
    $they_better = [];
    $suggestions = [];

    // Load time
    $own_time  = floatval(str_replace('s', '', $own_data['load_time'] ?? '99'));
    $comp_time = floatval(str_replace('s', '', $comp_data['load_time'] ?? '99'));
    if ($own_time < $comp_time) {
        $you_better[] = "Faster load time ({$own_data['load_time']} vs {$comp_data['load_time']})";
    } elseif ($comp_time < $own_time) {
        $they_better[] = "Faster load time ({$comp_data['load_time']} vs {$own_data['load_time']})";
        $suggestions[] = 'Consider optimizing your page speed — enable caching, compress images, minimize CSS/JS.';
    }

    // Meta description
    if (!empty($own_data['meta_description']) && empty($comp_data['meta_description'])) {
        $you_better[] = 'You have a meta description, they do not.';
    } elseif (empty($own_data['meta_description']) && !empty($comp_data['meta_description'])) {
        $they_better[] = 'They have a meta description, you do not.';
        $suggestions[] = 'Add a meta description to improve your SEO snippet in search results.';
    }

    // H1 tags
    $own_h1  = count($own_data['h1_tags'] ?? []);
    $comp_h1 = count($comp_data['h1_tags'] ?? []);
    if ($own_h1 === 1 && $comp_h1 !== 1) {
        $you_better[] = 'Proper H1 structure (single H1 tag).';
    } elseif ($comp_h1 === 1 && $own_h1 !== 1) {
        $they_better[] = 'Better H1 structure.';
        $suggestions[] = 'Ensure you have exactly one H1 tag per page for optimal SEO.';
    }

    // SSL
    if (!empty($own_data['has_ssl']) && empty($comp_data['has_ssl'])) {
        $you_better[] = 'Your site uses HTTPS, theirs does not.';
    } elseif (empty($own_data['has_ssl']) && !empty($comp_data['has_ssl'])) {
        $they_better[] = 'They use HTTPS, you do not.';
        $suggestions[] = 'Enable SSL/HTTPS for better security and SEO ranking.';
    }

    // Word count
    $own_words  = intval($own_data['word_count'] ?? 0);
    $comp_words = intval($comp_data['word_count'] ?? 0);
    if ($own_words > $comp_words * 1.2) {
        $you_better[] = "More content ({$own_words} words vs {$comp_words}).";
    } elseif ($comp_words > $own_words * 1.2) {
        $they_better[] = "More content ({$comp_words} words vs {$own_words}).";
        $suggestions[] = 'Consider adding more relevant content to improve SEO rankings.';
    }

    $comparison['you_do_better']  = $you_better;
    $comparison['they_do_better'] = $they_better;
    $comparison['suggestions']    = $suggestions;

    $summary = count($you_better) . ' advantage(s) for you, ' . count($they_better) . ' for competitor.';
    return wpilot_ok("Site comparison complete. {$summary}", ['comparison' => $comparison]);
}


// ═══════════════════════════════════════════════════════════════
//  EMAIL AUTOMATION — 13. create_email_sequence
// ═══════════════════════════════════════════════════════════════
function wpilot_create_email_sequence($params) {
    $trigger = sanitize_text_field($params['trigger'] ?? '');
    $emails  = $params['emails'] ?? [];

    $valid_triggers = ['purchase', 'signup', 'abandoned-cart', 'booking'];
    if (!$trigger || !in_array($trigger, $valid_triggers)) {
        return wpilot_err('Valid trigger required: ' . implode(', ', $valid_triggers));
    }

    if (empty($emails) || !is_array($emails)) {
        return wpilot_err('At least one email is required in the sequence.');
    }

    $clean_emails = [];
    foreach ($emails as $i => $email) {
        $clean_emails[] = [
            'delay_days'    => max(0, intval($email['delay_days'] ?? $i)),
            'subject'       => sanitize_text_field($email['subject'] ?? 'Follow up'),
            'body_template' => wp_kses_post($email['body_template'] ?? $email['body'] ?? ''),
        ];
    }

    // Sort by delay
    usort($clean_emails, function($a, $b) { return $a['delay_days'] - $b['delay_days']; });

    $sequences = get_option('wpilot_email_sequences', []);
    $seq_id    = 'seq_' . uniqid();

    $sequences[$seq_id] = [
        'id'         => $seq_id,
        'trigger'    => $trigger,
        'emails'     => $clean_emails,
        'enabled'    => true,
        'created_at' => current_time('mysql'),
        'sent_count' => 0,
    ];
    update_option('wpilot_email_sequences', $sequences, false);

    // Schedule WP Cron to process email queue
    if (!wp_next_scheduled('wpilot_process_email_queue')) {
        wp_schedule_event(time(), 'hourly', 'wpilot_process_email_queue');
    }

    $count = count($clean_emails);
    return wpilot_ok("Email sequence created (ID: {$seq_id}). Trigger: {$trigger}, {$count} email(s).", [
        'sequence_id' => $seq_id,
        'trigger'     => $trigger,
        'email_count' => $count,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  EMAIL AUTOMATION — 14. list_email_sequences
// ═══════════════════════════════════════════════════════════════
function wpilot_list_email_sequences($params) {
    $sequences = get_option('wpilot_email_sequences', []);

    if (empty($sequences)) {
        return wpilot_ok('No email sequences configured.', ['sequences' => []]);
    }

    $list = [];
    foreach ($sequences as $seq) {
        $list[] = [
            'id'          => $seq['id'],
            'trigger'     => $seq['trigger'],
            'email_count' => count($seq['emails'] ?? []),
            'enabled'     => $seq['enabled'] ?? true,
            'sent_count'  => $seq['sent_count'] ?? 0,
            'created_at'  => $seq['created_at'] ?? '',
        ];
    }

    return wpilot_ok(count($list) . ' email sequence(s).', ['sequences' => $list]);
}

// ═══════════════════════════════════════════════════════════════
//  EMAIL AUTOMATION — 15. delete_email_sequence
// ═══════════════════════════════════════════════════════════════
function wpilot_delete_email_sequence($params) {
    $seq_id = sanitize_text_field($params['sequence_id'] ?? $params['id'] ?? '');
    if (!$seq_id) return wpilot_err('sequence_id is required.');

    $sequences = get_option('wpilot_email_sequences', []);
    if (!isset($sequences[$seq_id])) return wpilot_err("Sequence '{$seq_id}' not found.");

    $trigger = $sequences[$seq_id]['trigger'] ?? '';
    unset($sequences[$seq_id]);
    update_option('wpilot_email_sequences', $sequences, false);

    // Clean up queued emails for this sequence
    $queue = get_option('wpilot_email_queue', []);
    $queue = array_filter($queue, function($item) use ($seq_id) {
        return ($item['sequence_id'] ?? '') !== $seq_id;
    });
    update_option('wpilot_email_queue', array_values($queue), false);

    // If no sequences left, clear the cron
    if (empty($sequences)) {
        wp_clear_scheduled_hook('wpilot_process_email_queue');
    }

    return wpilot_ok("Email sequence '{$seq_id}' ({$trigger}) deleted.");
}


// ═══════════════════════════════════════════════════════════════
//  EMAIL AUTOMATION — Cron processor (hourly)
// ═══════════════════════════════════════════════════════════════
add_action('wpilot_process_email_queue', 'wpilot_process_email_queue_handler');

function wpilot_process_email_queue_handler() {
    $queue = get_option('wpilot_email_queue', []);
    if (empty($queue)) return;

    $sequences = get_option('wpilot_email_sequences', []);
    $now       = time();
    $processed = 0;
    $max_batch = 20;

    foreach ($queue as $key => $item) {
        if ($processed >= $max_batch) break;

        $send_at = strtotime($item['send_at'] ?? '');
        if (!$send_at || $send_at > $now) continue;

        $seq_id = $item['sequence_id'] ?? '';
        if (!isset($sequences[$seq_id]) || empty($sequences[$seq_id]['enabled'])) {
            unset($queue[$key]);
            continue;
        }

        $seq     = $sequences[$seq_id];
        $email_i = intval($item['email_index'] ?? 0);
        $tmpl    = $seq['emails'][$email_i] ?? null;

        if (!$tmpl) {
            unset($queue[$key]);
            continue;
        }

        // Replace template variables
        $vars = [
            '{customer_name}'  => $item['customer_name'] ?? '',
            '{product_name}'   => $item['product_name'] ?? '',
            '{order_total}'    => $item['order_total'] ?? '',
            '{site_name}'      => get_bloginfo('name'),
            '{site_url}'       => home_url('/'),
        ];
        $subject = strtr($tmpl['subject'], $vars);
        $body    = strtr($tmpl['body_template'], $vars);

        $to = sanitize_email($item['customer_email'] ?? '');
        if (is_email($to)) {
            wp_mail($to, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
            $sequences[$seq_id]['sent_count'] = ($sequences[$seq_id]['sent_count'] ?? 0) + 1;
            $processed++;
        }

        unset($queue[$key]);
    }

    update_option('wpilot_email_queue', array_values($queue), false);
    update_option('wpilot_email_sequences', $sequences, false);
}

// ═══════════════════════════════════════════════════════════════
//  EMAIL AUTOMATION — Trigger hooks (WooCommerce, user, booking)
// ═══════════════════════════════════════════════════════════════

// Purchase trigger — WooCommerce order completed
add_action('woocommerce_order_status_completed', function($order_id) {
    $sequences = get_option('wpilot_email_sequences', []);
    $queue     = get_option('wpilot_email_queue', []);

    foreach ($sequences as $seq_id => $seq) {
        if ($seq['trigger'] !== 'purchase' || empty($seq['enabled'])) continue;

        $order = wc_get_order($order_id);
        if (!$order) continue;

        $items = $order->get_items();
        $product_name = !empty($items) ? reset($items)->get_name() : '';

        foreach ($seq['emails'] as $i => $email) {
            $queue[] = [
                'sequence_id'    => $seq_id,
                'email_index'    => $i,
                'customer_email' => $order->get_billing_email(),
                'customer_name'  => $order->get_billing_first_name(),
                'product_name'   => $product_name,
                'order_total'    => $order->get_formatted_order_total(),
                'send_at'        => gmdate('Y-m-d H:i:s', time() + ($email['delay_days'] * 86400)),
            ];
        }
    }

    update_option('wpilot_email_queue', $queue, false);
});

// Signup trigger — new user registration
add_action('user_register', function($user_id) {
    $sequences = get_option('wpilot_email_sequences', []);
    $queue     = get_option('wpilot_email_queue', []);
    $user      = get_userdata($user_id);
    if (!$user) return;

    foreach ($sequences as $seq_id => $seq) {
        if ($seq['trigger'] !== 'signup' || empty($seq['enabled'])) continue;

        foreach ($seq['emails'] as $i => $email) {
            $queue[] = [
                'sequence_id'    => $seq_id,
                'email_index'    => $i,
                'customer_email' => $user->user_email,
                'customer_name'  => $user->display_name ?: $user->user_login,
                'product_name'   => '',
                'order_total'    => '',
                'send_at'        => gmdate('Y-m-d H:i:s', time() + ($email['delay_days'] * 86400)),
            ];
        }
    }

    update_option('wpilot_email_queue', $queue, false);
});

// Booking trigger — fires when a new WPilot booking is created
add_action('wpilot_booking_created', function($booking_data) {
    $sequences = get_option('wpilot_email_sequences', []);
    $queue     = get_option('wpilot_email_queue', []);

    foreach ($sequences as $seq_id => $seq) {
        if ($seq['trigger'] !== 'booking' || empty($seq['enabled'])) continue;

        foreach ($seq['emails'] as $i => $email) {
            $queue[] = [
                'sequence_id'    => $seq_id,
                'email_index'    => $i,
                'customer_email' => $booking_data['client_email'] ?? '',
                'customer_name'  => $booking_data['client_name'] ?? '',
                'product_name'   => $booking_data['service'] ?? '',
                'order_total'    => '',
                'send_at'        => gmdate('Y-m-d H:i:s', time() + ($email['delay_days'] * 86400)),
            ];
        }
    }

    update_option('wpilot_email_queue', $queue, false);
});
