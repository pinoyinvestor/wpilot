<?php
/**
 * WPilot Emergency Recovery
 * Access: yoursite.com/wp-admin/?wpilot-recover=1
 * Works even during white screen of death
 */
if (!defined('ABSPATH')) return;

// Only activate on the recovery URL
if (!isset($_GET['wpilot-recover']) || !is_admin()) return;

// Check user is logged in and admin
add_action('admin_init', function() {
    if (!isset($_GET['wpilot-recover'])) return;
    if (!current_user_can('manage_options')) return;

    // Handle restore action
    if (isset($_POST['wpilot_restore_action'])) {
        check_admin_referer('wpilot_emergency_restore');
        $action = sanitize_text_field($_POST['wpilot_restore_action']);

        global $wpdb;
        $table = $wpdb->prefix . 'ca_backups';

        switch ($action) {
            case 'restore_last':
                $last = $wpdb->get_row("SELECT * FROM {$table} WHERE data_before IS NOT NULL AND restored = 0 ORDER BY id DESC LIMIT 1");
                if ($last) {
                    wpilot_emergency_restore($last);
                    $msg = 'Last change restored (backup #' . $last->id . ')';
                } else {
                    $msg = 'No restorable backup found';
                }
                break;

            case 'restore_all':
                $backups = $wpdb->get_results("SELECT * FROM {$table} WHERE data_before IS NOT NULL AND restored = 0 ORDER BY id DESC LIMIT 20");
                $count = 0;
                foreach ($backups as $b) { wpilot_emergency_restore($b); $count++; }
                $msg = "Restored {$count} changes";
                break;

            case 'deactivate_last_plugin':
                $backups = $wpdb->get_results("SELECT * FROM {$table} WHERE tool LIKE '%plugin%' AND restored = 0 ORDER BY id DESC LIMIT 5");
                $deactivated = [];
                foreach ($backups as $b) {
                    $params = json_decode($b->params, true);
                    if (!empty($params['slug'])) {
                        $plugins = get_plugins();
                        foreach ($plugins as $file => $data) {
                            if (strpos($file, $params['slug']) !== false && is_plugin_active($file)) {
                                deactivate_plugins($file);
                                $deactivated[] = $data['Name'];
                            }
                        }
                    }
                }
                $msg = empty($deactivated) ? 'No recently installed plugins to deactivate' : 'Deactivated: ' . implode(', ', $deactivated);
                break;

            case 'restore_css':
                $css_backup = $wpdb->get_row("SELECT * FROM {$table} WHERE tool = 'css_snapshot' AND data_before IS NOT NULL ORDER BY id DESC LIMIT 1");
                if ($css_backup) {
                    $data = json_decode($css_backup->data_before, true);
                    if (isset($data['css'])) {
                        wp_update_custom_css_post($data['css']);
                        $msg = 'CSS restored to previous version';
                    }
                } else {
                    $msg = 'No CSS backup found';
                }
                break;

            case 'disable_wpilot':
                deactivate_plugins('wpilot/wpilot.php');
                $msg = 'WPilot deactivated. Your site should work now.';
                break;
        }

        wp_redirect(admin_url('?wpilot-recover=1&msg=' . urlencode($msg)));
        exit;
    }

    // Show recovery page
    wpilot_show_recovery_page();
    exit;
}, 1);

function wpilot_emergency_restore($backup) {
    global $wpdb;
    $data = json_decode($backup->data_before, true);
    if (!$data) return;

    switch ($backup->target_type) {
        case 'page': case 'post': case 'product':
            if (!empty($data['post'])) {
                wp_update_post(array_intersect_key($data['post'], array_flip(['ID','post_title','post_content','post_excerpt','post_status'])));
            }
            break;
        case 'custom_css':
            if (isset($data['css'])) wp_update_custom_css_post($data['css']);
            break;
        case 'image':
            $original = $data['original_path'] ?? '';
            $backup_path = $data['backup_path'] ?? '';
            if ($backup_path && file_exists($backup_path) && $backup->target_id) {
                copy($backup_path, $original);
                update_attached_file($backup->target_id, $original);
                wp_update_post(['ID'=>$backup->target_id, 'post_mime_type'=>$data['original_mime']??'image/jpeg']);
            }
            break;
    }
    $wpdb->update($wpdb->prefix . 'ca_backups', ['restored'=>1], ['id'=>$backup->id]);
}

function wpilot_show_recovery_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'ca_backups';
    $msg = isset($_GET['msg']) ? sanitize_text_field($_GET['msg']) : '';

    // Get recent backups
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    $backups = $table_exists ? $wpdb->get_results("SELECT id, tool, target_type, created_at, restored FROM {$table} WHERE data_before IS NOT NULL ORDER BY id DESC LIMIT 20") : [];
    $nonce = wp_create_nonce('wpilot_emergency_restore');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>WPilot Emergency Recovery</title>
        <style>
            *{box-sizing:border-box;margin:0;padding:0}
            body{font-family:-apple-system,sans-serif;background:#0a0e17;color:#e0e6f0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
            .wrap{max-width:600px;width:100%}
            h1{color:#EF4444;font-size:24px;margin-bottom:8px}
            .sub{color:#5E6E91;font-size:14px;margin-bottom:24px}
            .msg{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-size:14px}
            .msg-ok{background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);color:#6EE7B7}
            .card{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:20px;margin-bottom:12px}
            .card h3{font-size:14px;margin-bottom:6px}
            .card p{font-size:12px;color:#5E6E91;margin-bottom:12px;line-height:1.5}
            .btn{display:inline-block;padding:10px 20px;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;width:100%;text-align:center;margin-bottom:8px}
            .btn-danger{background:#EF4444;color:#fff}
            .btn-warn{background:#F59E0B;color:#000}
            .btn-safe{background:#10B981;color:#fff}
            .btn-ghost{background:transparent;border:1px solid rgba(255,255,255,.1);color:#5E6E91}
            .btn:hover{opacity:.9}
            .history{margin-top:20px}
            .history h3{font-size:13px;color:#5E6E91;text-transform:uppercase;letter-spacing:1px;margin-bottom:10px}
            .history-item{display:flex;justify-content:space-between;padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.04);font-size:12px}
            .history-item .tool{color:#93B4FF;font-family:monospace}
            .history-item .date{color:#5E6E91}
            .history-item .status{color:#10B981}
            .restored{opacity:.4}
            .back{display:block;text-align:center;margin-top:16px;color:#4F7EFF;font-size:13px;text-decoration:none}
        </style>
    </head>
    <body>
        <div class="wrap">
            <h1>WPilot Emergency Recovery</h1>
            <p class="sub">If WPilot broke your site, use these tools to restore it without FTP or database access.</p>

            <?php if ($msg): ?>
            <div class="msg msg-ok"><?php echo esc_html($msg); ?></div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="_wpnonce" value="<?php echo $nonce; ?>">

                <div class="card">
                    <h3>Undo Last Change</h3>
                    <p>Restores the most recent WPilot change (page edit, CSS, image, etc.)</p>
                    <button type="submit" name="wpilot_restore_action" value="restore_last" class="btn btn-safe">Restore Last Change</button>
                </div>

                <div class="card">
                    <h3>Undo ALL Recent Changes</h3>
                    <p>Restores the last 20 WPilot changes at once.</p>
                    <button type="submit" name="wpilot_restore_action" value="restore_all" class="btn btn-warn">Restore All Changes</button>
                </div>

                <div class="card">
                    <h3>Deactivate Recently Installed Plugins</h3>
                    <p>If a plugin WPilot installed caused the crash.</p>
                    <button type="submit" name="wpilot_restore_action" value="deactivate_last_plugin" class="btn btn-warn">Deactivate Recent Plugins</button>
                </div>

                <div class="card">
                    <h3>Restore CSS</h3>
                    <p>If a CSS change broke the design.</p>
                    <button type="submit" name="wpilot_restore_action" value="restore_css" class="btn btn-safe">Restore Previous CSS</button>
                </div>

                <div class="card">
                    <h3>Disable WPilot Completely</h3>
                    <p>Nuclear option — deactivates WPilot entirely. Your site goes back to normal.</p>
                    <button type="submit" name="wpilot_restore_action" value="disable_wpilot" class="btn btn-danger">Disable WPilot</button>
                </div>
            </form>

            <?php if (!empty($backups)): ?>
            <div class="history">
                <h3>Recent Changes (<?php echo count($backups); ?>)</h3>
                <?php foreach ($backups as $b): ?>
                <div class="history-item <?php echo $b->restored ? 'restored' : ''; ?>">
                    <span class="tool"><?php echo esc_html($b->tool); ?></span>
                    <span class="date"><?php echo esc_html($b->created_at); ?></span>
                    <span class="status"><?php echo $b->restored ? 'Restored' : 'Active'; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <a href="<?php echo admin_url(); ?>" class="back">Back to WordPress Admin</a>
        </div>
    </body>
    </html>
    <?php
}
