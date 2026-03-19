<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  SCHEDULER — Automated AI tasks
//  Tasks run via WP-Cron and results are stored + emailed
// ═══════════════════════════════════════════════════════════════

function wpilot_get_tasks() {
    return get_option('wpi_scheduled_tasks', []);
}

function wpilot_save_tasks( $tasks ) {
    update_option('wpi_scheduled_tasks', $tasks);
}

function wpilot_add_task( $type, $schedule, $options = [] ) {
    $tasks   = wpilot_get_tasks();
    $task_id = 'task_' . uniqid();
    $tasks[$task_id] = [
        'id'       => $task_id,
        'type'     => $type,
        'schedule' => $schedule, // daily | weekly | monthly
        'options'  => $options,
        'enabled'  => true,
        'last_run' => null,
        'last_result' => null,
        'created'  => current_time('mysql'),
    ];
    wpilot_save_tasks($tasks);

    // Schedule the cron event
    $hook = 'wpi_run_task_' . $task_id;
    if ( !wp_next_scheduled($hook) ) {
        wp_schedule_event(time(), $schedule, $hook);
    }
    add_action($hook, function() use ($task_id) { wpilot_execute_task($task_id); });
    return $task_id;
}

function wpilot_delete_task( $task_id ) {
    $tasks = wpilot_get_tasks();
    if ( isset($tasks[$task_id]) ) {
        wp_clear_scheduled_hook('wpi_run_task_'.$task_id);
        unset($tasks[$task_id]);
        wpilot_save_tasks($tasks);
    }
}

// Register all saved task hooks on init
add_action('init', function() {
    foreach ( wpilot_get_tasks() as $task_id => $task ) {
        if ( empty($task['enabled']) ) continue;
        $hook = 'wpi_run_task_' . $task_id;
        add_action($hook, function() use ($task_id) { wpilot_execute_task($task_id); });
        if ( !wp_next_scheduled($hook) ) {
            wp_schedule_event(time() + 3600, $task['schedule'], $hook);
        }
    }
});

// Execute a task
function wpilot_execute_task( $task_id ) {
    $tasks = wpilot_get_tasks();
    if ( empty($tasks[$task_id]) ) return;
    $task = $tasks[$task_id];
    if ( !wpilot_is_connected() ) return;

    $result = null;

    switch ($task['type']) {
        case 'seo_report':
            $context = wpilot_build_context('seo');
            $result  = wpilot_call_claude(
                "Analyze this site's SEO and give a concise weekly report. List: 3 critical issues, 3 improvements made or still needed, and an overall SEO health score out of 10. Be specific.",
                'seo', $context, []
            );
            break;

        case 'content_check':
            $context = wpilot_build_context('analyze');
            $result  = wpilot_call_claude(
                "Review the recent posts and pages on this site. Report: any pages with missing meta descriptions, thin content (under 300 words), broken images, or missing alt text. Give a prioritized fix list.",
                'analyze', $context, []
            );
            break;

        case 'security_check':
            $result = wpilot_call_claude(
                "Run a WordPress security checklist for this site. Check: plugin update status, admin user count, SSL, file permissions, and common vulnerabilities. Give a security score and top 3 actions to take.",
                'chat', wpilot_build_context('general'), []
            );
            break;

        // Built by Weblease
        case 'performance_check':
            $result = wpilot_call_claude(
                "Analyze this WordPress site for performance issues. Review: active plugins count, image optimization status, caching setup, and theme. Give a performance score and specific recommendations.",
                'chat', wpilot_build_context('general'), []
            );
            break;

        case 'custom':
            $prompt = $task['options']['prompt'] ?? '';
            if ($prompt) {
                $result = wpilot_call_claude($prompt, 'chat', wpilot_build_context('general'), []);
            }
            break;
    }

    if ( is_wp_error($result) ) {
        $tasks[$task_id]['last_result'] = ['error' => $result->get_error_message()];
    } elseif ($result) {
        $tasks[$task_id]['last_result'] = ['text' => $result, 'ts' => current_time('mysql')];
        // Store in activity log
        wpilot_log_activity('scheduled_task', 'Scheduled: '.$task['type'], mb_substr($result,0,100).'…');
        // Email admin if configured
        if ( !empty($task['options']['email']) ) {
            $subject = '[WPilot] Scheduled Report: ' . ucfirst(str_replace('_',' ',$task['type']));
            wp_mail(get_option('admin_email'), $subject,
                "WPilot scheduled report for " . get_bloginfo('name') . ":\n\n" . $result
            );
        }
    }

    $tasks[$task_id]['last_run'] = current_time('mysql');
    wpilot_save_tasks($tasks);
    wpilot_increment_prompts();
}

// AJAX: create task
add_action('wp_ajax_wpi_create_task', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    $type     = sanitize_text_field($_POST['type']     ?? '');
    $schedule = sanitize_text_field($_POST['schedule'] ?? 'weekly');
    $email    = (bool)($_POST['email'] ?? false);
    $prompt   = sanitize_textarea_field($_POST['prompt'] ?? '');
    $options  = ['email'=>$email];
    if($prompt) $options['prompt'] = $prompt;
    $id = wpilot_add_task($type, $schedule, $options);
    wp_send_json_success(['task_id'=>$id]);
});

// AJAX: delete task
add_action('wp_ajax_wpi_delete_task', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    wpilot_delete_task(sanitize_text_field($_POST['task_id'] ?? ''));
    wp_send_json_success();
});

// AJAX: run task now
add_action('wp_ajax_wpi_run_task_now', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    $id = sanitize_text_field($_POST['task_id'] ?? '');
    wpilot_execute_task($id);
    $tasks = wpilot_get_tasks();
    wp_send_json_success($tasks[$id] ?? []);
});

// AJAX: get tasks + results
add_action('wp_ajax_wpi_get_tasks', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    wp_send_json_success(array_values(wpilot_get_tasks()));
});

// Register weekly schedule if not exists
add_filter('cron_schedules', function($s){
    if(!isset($s['weekly'])) $s['weekly'] = ['interval'=>604800,'display'=>'Weekly'];
    if(!isset($s['monthly'])) $s['monthly'] = ['interval'=>2592000,'display'=>'Monthly'];
    return $s;
});
