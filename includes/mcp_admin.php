<?php
/**
 * WPilot Claude Code — Admin Page
 *
 * Provides the UI for generating API keys, viewing the Claude Code
 * connection command, and monitoring request stats.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render the Claude Code admin page.
 */
function wpilot_page_mcp() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    $notice = '';
    $new_key = '';
    $undo_message = '';

    // Handle form actions
    if (isset($_POST['wpilot_mcp_action']) && wp_verify_nonce($_POST['_wpnonce'], 'wpilot_mcp_action')) {
        $action = sanitize_text_field($_POST['wpilot_mcp_action']);

        if ($action === 'generate_v2' && function_exists('wpilot_mcp_generate_key_v2')) {
            $label = sanitize_text_field($_POST['wpilot_key_label'] ?? 'API Key');
            $role = sanitize_text_field($_POST['wpilot_key_role'] ?? 'admin');
            $new_key = wpilot_mcp_generate_key_v2($label, $role);
            $notice = 'generated';
        } elseif ($action === 'revoke_v2' && function_exists('wpilot_mcp_revoke_key_v2')) {
            $hash = sanitize_text_field($_POST['wpilot_key_hash'] ?? '');
            if ($hash) wpilot_mcp_revoke_key_v2($hash);
            $notice = 'revoked';
        } elseif ($action === 'generate') {
            $new_key = wpilot_mcp_generate_key();
            $notice  = 'generated';
        } elseif ($action === 'revoke') {
            wpilot_mcp_revoke_key();
            $notice  = 'revoked';
        }
    }

    // Handle undo action
    if (isset($_POST['wpilot_mcp_do_undo']) && wp_verify_nonce($_POST['_wpnonce'], 'wpilot_mcp_undo_action')) {
        $undo_id = sanitize_text_field($_POST['wpilot_undo_id']);
        if (function_exists('wpilot_mcp_undo')) {
            $undo_result = wpilot_mcp_undo($undo_id);
            $notice = $undo_result['success'] ? 'undo_ok' : 'undo_fail';
            $undo_message = $undo_result['message'] ?? '';
        }
    }

    $has_key     = wpilot_mcp_has_key();
    $stats       = get_option('wpilot_mcp_stats', []);
    $created     = get_option('wpilot_mcp_key_created', '');
    $site_url    = get_site_url();
    $endpoint    = $site_url . '/wp-json/wpilot/v1/mcp';
    $tool_count  = count(wpilot_mcp_grouped_tool_definitions());
    $total_reqs  = intval($stats['total_requests'] ?? 0);
    $last_req    = $stats['last_request'] ?? null;

    ob_start();

    // ── Key generated banner ──
    if ($notice === 'generated' && !empty($new_key)) : ?>
    <div class="ca-alert ca-alert-ok" style="flex-direction:column;gap:16px;padding:20px 22px">
        <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:20px">🔑</span>
            <strong style="font-size:14px"><?php echo esc_html(wpilot_t('mcp_key_generated_banner')); ?></strong>
        </div>

        <div style="position:relative">
            <div id="mcp-key-val" style="background:var(--ca-bg3);border:1px solid var(--ca-border3);border-radius:var(--ca-r2);padding:12px 52px 12px 14px;font-family:var(--ca-mono);font-size:12px;word-break:break-all;color:var(--ca-cyan);line-height:1.6">
                <?php echo esc_html($new_key); ?>
            </div>
            <button type="button" class="ca-btn ca-btn-ghost" style="position:absolute;right:6px;top:6px;padding:5px 10px;font-size:11px"
                onclick="navigator.clipboard.writeText('<?php echo esc_js($new_key); ?>');this.textContent='<?php echo esc_js(wpilot_t('mcp_copied')); ?>';setTimeout(()=>this.textContent='<?php echo esc_js(wpilot_t('mcp_copy')); ?>',1500);"><?php echo esc_html(wpilot_t('mcp_copy')); ?></button>
        </div>

        <div>
            <div style="font-size:12px;font-weight:700;color:var(--ca-text2);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px"><?php echo esc_html(wpilot_t('mcp_run_in_terminal')); ?></div>
            <div style="position:relative">
                <pre id="mcp-cmd-val" style="background:var(--ca-bg);border:1px solid var(--ca-border2);border-radius:var(--ca-r2);padding:14px 52px 14px 16px;font-family:var(--ca-mono);font-size:11.5px;color:var(--ca-text);line-height:1.7;white-space:pre-wrap;word-break:break-all;margin:0;overflow:hidden">claude mcp add --transport http wpilot <?php echo esc_html($endpoint); ?> --header "Authorization: Bearer <?php echo esc_html($new_key); ?>"</pre>
                <button type="button" class="ca-btn ca-btn-ghost" style="position:absolute;right:6px;top:6px;padding:5px 10px;font-size:11px"
                    onclick="navigator.clipboard.writeText(document.getElementById('mcp-cmd-val').textContent.trim());this.textContent='<?php echo esc_js(wpilot_t('mcp_copied')); ?>';setTimeout(()=>this.textContent='<?php echo esc_js(wpilot_t('mcp_copy')); ?>',1500);"><?php echo esc_html(wpilot_t('mcp_copy')); ?></button>
            </div>
        </div>
    </div>
    <?php elseif ($notice === 'revoked') : ?>
    <div class="ca-alert ca-alert-warn" style="padding:14px 18px">
        <span class="ca-alert-icon">⚠️</span>
        <div><strong><?php echo esc_html(wpilot_t('mcp_key_revoked_title')); ?></strong> <?php echo esc_html(wpilot_t('mcp_key_revoked_desc')); ?></div>
    </div>
    <?php elseif ($notice === 'undo_ok') : ?>
    <div class="ca-alert ca-alert-ok" style="padding:14px 18px">
        <span class="ca-alert-icon">✅</span>
        <div><strong><?php echo esc_html(wpilot_t('mcp_snapshot_restored')); ?></strong> <?php echo esc_html($undo_message); ?></div>
    </div>
    <?php elseif ($notice === 'undo_fail') : ?>
    <div class="ca-alert ca-alert-warn" style="padding:14px 18px">
        <span class="ca-alert-icon">⚠️</span>
        <div><strong><?php echo esc_html(wpilot_t('mcp_undo_failed')); ?></strong> <?php echo esc_html($undo_message); ?></div>
    </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="ca-stat-row" style="margin-bottom:18px">
        <div class="ca-stat-card">
            <div class="ca-sc-label"><?php echo esc_html(wpilot_t('mcp_status')); ?></div>
            <div class="ca-sc-val"><?php echo $has_key ? '● ' . esc_html(wpilot_t('mcp_active')) : '○ ' . esc_html(wpilot_t('mcp_inactive')); ?></div>
            <div class="ca-sc-sub"><?php echo $has_key ? esc_html(wpilot_t('mcp_key_created')) . ' ' . esc_html($created) : esc_html(wpilot_t('mcp_no_api_key')); ?></div>
        </div>
        <div class="ca-stat-card">
            <div class="ca-sc-label"><?php echo esc_html(wpilot_t('mcp_requests')); ?></div>
            <div class="ca-sc-val"><?php echo number_format($total_reqs); ?></div>
            <div class="ca-sc-sub"><?php echo $last_req ? esc_html(wpilot_t('mcp_last')) . ' ' . esc_html($last_req) : esc_html(wpilot_t('mcp_no_requests_yet')); ?></div>
        </div>
        <div class="ca-stat-card">
            <div class="ca-sc-label"><?php echo esc_html(wpilot_t('mcp_tools')); ?></div>
            <div class="ca-sc-val"><?php echo $tool_count; ?></div>
            <div class="ca-sc-sub"><?php echo esc_html(wpilot_t('mcp_available_tools')); ?></div>
        </div>
        <div class="ca-stat-card">
            <div class="ca-sc-label"><?php echo esc_html(wpilot_t('mcp_rate_limit')); ?></div>
            <div class="ca-sc-val">120/min</div>
            <div class="ca-sc-sub"><?php echo esc_html(wpilot_t('mcp_requests_per_min')); ?></div>
        </div>
    </div>

    <!-- Built by Weblease -->

    <!-- Get Claude Code Guide -->
    <div class="ca-card ca-card-glow" style="border-color:rgba(79,126,255,.25)">
        <h3>📥 <?php echo esc_html(wpilot_t('mcp_get_claude_code')); ?></h3>
        <p class="ca-card-sub"><?php echo esc_html(wpilot_t('mcp_get_claude_code_desc')); ?></p>

        <div style="display:grid;gap:16px;margin-top:8px">
            <div style="display:flex;gap:14px;align-items:flex-start">
                <div style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--ca-grad);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff">1</div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--ca-text)"><?php echo esc_html(wpilot_t('mcp_install_claude_code')); ?></div>
                    <div style="font-size:12px;color:var(--ca-text2);margin-top:3px"><?php echo esc_html(wpilot_t('mcp_open_terminal_run')); ?></div>
                    <div style="background:var(--ca-bg);border:1px solid var(--ca-border2);border-radius:var(--ca-r2);padding:10px 14px;margin-top:6px;font-family:var(--ca-mono);font-size:11.5px;color:var(--ca-cyan)">npm install -g @anthropic-ai/claude-code</div>
                    <div style="font-size:11px;color:var(--ca-text3);margin-top:4px"><?php echo wpilot_t('mcp_requires_node'); ?></div>
                </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-start">
                <div style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--ca-grad);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff">2</div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--ca-text)"><?php echo esc_html(wpilot_t('mcp_sign_in_anthropic')); ?></div>
                    <div style="font-size:12px;color:var(--ca-text2);margin-top:3px"><?php echo wpilot_t('mcp_sign_in_anthropic_desc'); ?></div>
                    <div style="font-size:11px;color:var(--ca-text3);margin-top:4px"><?php echo wpilot_t('mcp_need_account'); ?></div>
                </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-start">
                <div style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--ca-grad);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff">3</div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--ca-text)"><?php echo esc_html(wpilot_t('mcp_connect_wp_site')); ?></div>
                    <div style="font-size:12px;color:var(--ca-text2);margin-top:3px"><?php echo esc_html(wpilot_t('mcp_connect_wp_site_desc')); ?></div>
                </div>
            </div>
        </div>

        <div class="ca-card-footer">
            <a href="https://docs.anthropic.com/en/docs/claude-code" target="_blank" rel="noopener" style="font-size:12px;color:var(--ca-accent)"><?php echo esc_html(wpilot_t('mcp_claude_code_docs')); ?> →</a>
        </div>
    </div>
    <!-- API Key Card -->
    <div class="ca-card <?php echo !$has_key ? 'ca-card-glow' : ''; ?>">
        <h3>🔑 <?php echo esc_html(wpilot_t('mcp_api_key')); ?></h3>
        <?php if ($has_key) : ?>
            <p class="ca-card-sub"><?php echo esc_html(wpilot_t('mcp_key_active_desc')); ?></p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="post">
                    <?php wp_nonce_field('wpilot_mcp_action'); ?>
                    <input type="hidden" name="wpilot_mcp_action" value="generate">
                    <button type="submit" class="ca-btn ca-btn-ghost"><?php echo esc_html(wpilot_t('mcp_regenerate_key')); ?></button>
                </form>
                <form method="post">
                    <?php wp_nonce_field('wpilot_mcp_action'); ?>
                    <input type="hidden" name="wpilot_mcp_action" value="revoke">
                    <button type="submit" class="ca-btn ca-btn-danger" onclick="return confirm('<?php echo esc_js(wpilot_t('mcp_revoke_confirm')); ?>');"><?php echo esc_html(wpilot_t('mcp_revoke_key')); ?></button>
                </form>
            </div>
        <?php else : ?>
            <p class="ca-card-sub"><?php echo esc_html(wpilot_t('mcp_key_inactive_desc')); ?></p>
            <form method="post">
                <?php wp_nonce_field('wpilot_mcp_action'); ?>
                <input type="hidden" name="wpilot_mcp_action" value="generate">
                <button type="submit" class="ca-btn ca-btn-primary" style="font-size:13.5px;padding:10px 24px"><?php echo esc_html(wpilot_t('mcp_generate_api_key')); ?></button>
            </form>
        <?php endif; ?>
    </div>

    <!-- All API Keys -->
    <div class="ca-card">
        <h3>🔐 <?php echo esc_html(wpilot_t('mcp_api_keys')); ?></h3>
        <p class="ca-card-sub"><?php echo esc_html(wpilot_t('mcp_api_keys_desc')); ?></p>

        <?php
        $all_keys = function_exists('wpilot_mcp_keys_get') ? wpilot_mcp_keys_get() : [];
        $roles = function_exists('wpilot_mcp_key_roles') ? wpilot_mcp_key_roles() : [];

        if (!empty($all_keys)) : ?>
        <div style="display:grid;gap:8px;margin-bottom:16px">
            <?php foreach ($all_keys as $k) :
                $role_info = $roles[$k['role']] ?? ['label' => $k['role'], 'color' => '#666'];
            ?>
            <div style="display:flex;align-items:center;gap:12px;background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:12px 14px">
                <div style="width:8px;height:8px;border-radius:50%;background:<?php echo esc_attr($role_info['color']); ?>;flex-shrink:0"></div>
                <div style="flex:1">
                    <div style="font-size:13px;font-weight:600;color:var(--ca-text)"><?php echo esc_html($k['label']); ?></div>
                    <div style="font-size:11px;color:var(--ca-text3);margin-top:2px">
                        <?php echo esc_html($role_info['label']); ?> &middot;
                        <?php echo esc_html(wpilot_t('mcp_created')); ?> <?php echo esc_html($k['created']); ?> &middot;
                        <?php echo intval($k['requests'] ?? 0); ?> <?php echo esc_html(wpilot_t('mcp_requests_lc')); ?>
                        <?php if ($k['last_used']) : ?> &middot; <?php echo esc_html(wpilot_t('mcp_last')); ?> <?php echo esc_html($k['last_used']); ?><?php endif; ?>
                    </div>
                </div>
                <form method="post" style="margin:0">
                    <?php wp_nonce_field('wpilot_mcp_action'); ?>
                    <input type="hidden" name="wpilot_mcp_action" value="revoke_v2">
                    <input type="hidden" name="wpilot_key_hash" value="<?php echo esc_attr($k['hash']); ?>">
                    <button type="submit" class="ca-btn ca-btn-ghost" style="font-size:11px;padding:4px 12px;color:var(--ca-red,#f87171)"
                        onclick="return confirm('<?php echo esc_js(wpilot_t('mcp_revoke_confirm_v2')); ?>');"><?php echo esc_html(wpilot_t('mcp_revoke')); ?></button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
            <?php wp_nonce_field('wpilot_mcp_action'); ?>
            <input type="hidden" name="wpilot_mcp_action" value="generate_v2">
            <div style="flex:1;min-width:150px">
                <label style="font-size:11px;font-weight:600;color:var(--ca-text3);display:block;margin-bottom:4px"><?php echo esc_html(wpilot_t('mcp_key_name')); ?></label>
                <input type="text" name="wpilot_key_label" placeholder="<?php echo esc_attr(wpilot_t('mcp_key_name_placeholder')); ?>" required
                    style="width:100%;padding:8px 12px;background:var(--ca-bg);border:1px solid var(--ca-border2);border-radius:var(--ca-r2);color:var(--ca-text);font-size:13px">
            </div>
            <div style="min-width:120px">
                <label style="font-size:11px;font-weight:600;color:var(--ca-text3);display:block;margin-bottom:4px"><?php echo esc_html(wpilot_t('mcp_access_level')); ?></label>
                <select name="wpilot_key_role" style="width:100%;padding:8px 12px;background:var(--ca-bg);border:1px solid var(--ca-border2);border-radius:var(--ca-r2);color:var(--ca-text);font-size:13px">
                    <?php foreach ($roles as $slug => $info) : ?>
                    <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($info['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="ca-btn ca-btn-primary" style="padding:8px 16px;font-size:13px;white-space:nowrap"><?php echo esc_html(wpilot_t('mcp_create_key')); ?></button>
        </form>

        <div style="margin-top:12px;font-size:12px;color:var(--ca-text3)">
            <?php echo wpilot_t('mcp_roles_desc'); ?>
        </div>
    </div>

    <!-- Setup -->
    <div class="ca-card">
        <h3>🔌 <?php echo esc_html(wpilot_t('mcp_setup')); ?></h3>
        <p class="ca-card-sub"><?php echo esc_html(wpilot_t('mcp_setup_desc')); ?></p>

        <div style="display:grid;gap:14px;margin-top:4px">
            <?php
            $steps = [
                ['1', wpilot_t('mcp_step1_title'), wpilot_t('mcp_step1_desc')],
                ['2', wpilot_t('mcp_step2_title'), wpilot_t('mcp_step2_desc')],
                ['3', wpilot_t('mcp_step3_title'), str_replace('{tools}', $tool_count, wpilot_t('mcp_step3_desc'))],
            ];
            foreach ($steps as [$num, $title, $desc]) : ?>
            <div style="display:flex;gap:14px;align-items:flex-start">
                <div style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--ca-grad);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff"><?php echo $num; ?></div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--ca-text)"><?php echo esc_html($title); ?></div>
                    <div style="font-size:12.5px;color:var(--ca-text2);margin-top:2px"><?php echo $desc; ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ca-card-footer">
            <div style="font-size:12px;color:var(--ca-text3)">
                Endpoint: <code style="font-family:var(--ca-mono);color:var(--ca-text2);font-size:11px"><?php echo esc_html($endpoint); ?></code>
            </div>
        </div>
    </div>

    <!-- What You Can Do -->
    <div class="ca-card">
        <h3>💡 <?php echo esc_html(wpilot_t('mcp_example_commands')); ?></h3>
        <p class="ca-card-sub"><?php echo esc_html(wpilot_t('mcp_example_commands_desc')); ?></p>

        <div style="display:grid;gap:8px;margin-top:4px">
            <?php
            $example_keys = [
                'mcp_ex_about_page',
                'mcp_ex_shop_button',
                'mcp_ex_products_sale',
                'mcp_ex_wordfence',
                'mcp_ex_footer',
                'mcp_ex_seo_audit',
            ];
            foreach ($example_keys as $ex_key) : ?>
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:10px 14px;font-family:var(--ca-mono);font-size:12px;color:var(--ca-text2)">
                &gt; <?php echo esc_html(wpilot_t($ex_key)); ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ca-card-footer">
            <div style="font-size:12px;color:var(--ca-text3)">
                <?php echo esc_html(wpilot_t('mcp_example_footer')); ?>
            </div>
        </div>
    </div>

    <!-- Security -->
    <div class="ca-card">
        <h3>🛡️ <?php echo esc_html(wpilot_t('mcp_security')); ?></h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:4px">
            <?php
            $sec = [
                ['mcp_sec_encrypted_title', 'mcp_sec_encrypted_desc'],
                ['mcp_sec_rate_limit_title', 'mcp_sec_rate_limit_desc'],
                ['mcp_sec_token_title', 'mcp_sec_token_desc'],
                ['mcp_sec_tool_title', 'mcp_sec_tool_desc'],
            ];
            foreach ($sec as [$title_key, $desc_key]) : ?>
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:12px 14px">
                <div style="font-size:12px;font-weight:700;color:var(--ca-green)"><?php echo esc_html(wpilot_t($title_key)); ?></div>
                <div style="font-size:12px;color:var(--ca-text2);margin-top:3px"><?php echo esc_html(wpilot_t($desc_key)); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Audit Log -->
    <div class="ca-card">
        <h3>📋 <?php echo esc_html(wpilot_t('mcp_activity_log')); ?></h3>
        <p class="ca-card-sub"><?php echo esc_html(wpilot_t('mcp_activity_log_desc')); ?></p>

        <?php
        $audit_stats = function_exists('wpilot_mcp_audit_stats') ? wpilot_mcp_audit_stats() : ['total' => 0, 'today' => 0, 'errors' => 0, 'top_tools' => []];
        ?>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px">
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:12px 14px;text-align:center">
                <div style="font-size:20px;font-weight:800;color:var(--ca-cyan)"><?php echo number_format($audit_stats['total']); ?></div>
                <div style="font-size:11px;color:var(--ca-text3)"><?php echo esc_html(wpilot_t('mcp_total_calls')); ?></div>
            </div>
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:12px 14px;text-align:center">
                <div style="font-size:20px;font-weight:800;color:var(--ca-green)"><?php echo number_format($audit_stats['today']); ?></div>
                <div style="font-size:11px;color:var(--ca-text3)"><?php echo esc_html(wpilot_t('mcp_today')); ?></div>
            </div>
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:12px 14px;text-align:center">
                <div style="font-size:20px;font-weight:800;color:<?php echo $audit_stats['errors'] > 0 ? 'var(--ca-red,#f87171)' : 'var(--ca-green)'; ?>"><?php echo number_format($audit_stats['errors']); ?></div>
                <div style="font-size:11px;color:var(--ca-text3)"><?php echo esc_html(wpilot_t('mcp_errors')); ?></div>
            </div>
        </div>

        <?php
        $recent = function_exists('wpilot_mcp_audit_recent') ? wpilot_mcp_audit_recent(20) : [];
        if (!empty($recent)) : ?>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead>
                    <tr style="border-bottom:1px solid var(--ca-border)">
                        <th style="text-align:left;padding:8px 10px;color:var(--ca-text3);font-weight:600"><?php echo esc_html(wpilot_t('mcp_col_tool')); ?></th>
                        <th style="text-align:left;padding:8px 10px;color:var(--ca-text3);font-weight:600"><?php echo esc_html(wpilot_t('mcp_col_action')); ?></th>
                        <th style="text-align:left;padding:8px 10px;color:var(--ca-text3);font-weight:600"><?php echo esc_html(wpilot_t('mcp_col_result')); ?></th>
                        <th style="text-align:right;padding:8px 10px;color:var(--ca-text3);font-weight:600"><?php echo esc_html(wpilot_t('mcp_col_time')); ?></th>
                        <th style="text-align:right;padding:8px 10px;color:var(--ca-text3);font-weight:600"><?php echo esc_html(wpilot_t('mcp_col_when')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $entry) :
                        $is_err = $entry->is_error;
                    ?>
                    <tr style="border-bottom:1px solid var(--ca-border2)">
                        <td style="padding:8px 10px;color:var(--ca-cyan);font-family:var(--ca-mono)"><?php echo esc_html($entry->tool); ?></td>
                        <td style="padding:8px 10px;color:var(--ca-text2)"><?php echo esc_html($entry->action ?: '—'); ?></td>
                        <td style="padding:8px 10px">
                            <span style="color:<?php echo $is_err ? 'var(--ca-red,#f87171)' : 'var(--ca-green)'; ?>">
                                <?php echo $is_err ? '✕ ' . esc_html(wpilot_t('mcp_error_label')) : '✓ OK'; ?>
                            </span>
                        </td>
                        <td style="padding:8px 10px;text-align:right;color:var(--ca-text3);font-family:var(--ca-mono)"><?php echo $entry->duration_ms; ?>ms</td>
                        <td style="padding:8px 10px;text-align:right;color:var(--ca-text3);font-size:11px"><?php echo esc_html($entry->created_at); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else : ?>
        <p style="color:var(--ca-text3);font-size:12px;text-align:center;padding:20px 0"><?php echo esc_html(wpilot_t('mcp_no_tool_calls')); ?></p>
        <?php endif; ?>
    </div>

    <!-- Undo History -->
    <div class="ca-card">
        <h3>↩️ <?php echo esc_html(wpilot_t('mcp_undo_history')); ?></h3>
        <p class="ca-card-sub"><?php echo esc_html(wpilot_t('mcp_undo_history_desc')); ?></p>

        <?php
        $undos = function_exists('wpilot_mcp_undo_list') ? wpilot_mcp_undo_list() : [];
        if (!empty($undos)) : ?>
        <div style="display:grid;gap:8px">
            <?php foreach (array_slice($undos, 0, 10) as $undo) : ?>
            <div style="display:flex;align-items:center;gap:12px;background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:10px 14px">
                <div style="flex:1">
                    <div style="font-size:12px;font-weight:600;color:var(--ca-text)"><?php echo esc_html($undo['summary']); ?></div>
                    <div style="font-size:11px;color:var(--ca-text3);margin-top:2px">
                        <?php echo esc_html($undo['tool']); ?> — <?php echo esc_html($undo['timestamp']); ?>
                    </div>
                </div>
                <form method="post" style="margin:0">
                    <?php wp_nonce_field('wpilot_mcp_undo_action'); ?>
                    <input type="hidden" name="wpilot_undo_id" value="<?php echo esc_attr($undo['id']); ?>">
                    <button type="submit" name="wpilot_mcp_do_undo" class="ca-btn ca-btn-ghost" style="font-size:11px;padding:4px 12px"
                        onclick="return confirm('<?php echo esc_js(wpilot_t('mcp_restore_confirm')); ?>');"><?php echo esc_html(wpilot_t('undo')); ?></button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
        <p style="color:var(--ca-text3);font-size:12px;text-align:center;padding:20px 0"><?php echo esc_html(wpilot_t('mcp_no_undo_snapshots')); ?></p>
        <?php endif; ?>
    </div>

    <?php
    wpilot_wrap('Claude Code', '⚡', ob_get_clean());
}
