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

        if ($action === 'generate') {
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
    $tool_count  = count(wpilot_mcp_get_tool_registry());
    $total_reqs  = intval($stats['total_requests'] ?? 0);
    $last_req    = $stats['last_request'] ?? null;

    ob_start();

    // ── Key generated banner ──
    if ($notice === 'generated' && !empty($new_key)) : ?>
    <div class="ca-alert ca-alert-ok" style="flex-direction:column;gap:16px;padding:20px 22px">
        <div style="display:flex;align-items:center;gap:10px">
            <span style="font-size:20px">🔑</span>
            <strong style="font-size:14px">API key generated — copy it now, it won't be shown again</strong>
        </div>

        <div style="position:relative">
            <div id="mcp-key-val" style="background:var(--ca-bg3);border:1px solid var(--ca-border3);border-radius:var(--ca-r2);padding:12px 52px 12px 14px;font-family:var(--ca-mono);font-size:12px;word-break:break-all;color:var(--ca-cyan);line-height:1.6">
                <?php echo esc_html($new_key); ?>
            </div>
            <button type="button" class="ca-btn ca-btn-ghost" style="position:absolute;right:6px;top:6px;padding:5px 10px;font-size:11px"
                onclick="navigator.clipboard.writeText('<?php echo esc_js($new_key); ?>');this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500);">Copy</button>
        </div>

        <div>
            <div style="font-size:12px;font-weight:700;color:var(--ca-text2);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Run in your terminal</div>
            <div style="position:relative">
                <pre id="mcp-cmd-val" style="background:var(--ca-bg);border:1px solid var(--ca-border2);border-radius:var(--ca-r2);padding:14px 52px 14px 16px;font-family:var(--ca-mono);font-size:11.5px;color:var(--ca-text);line-height:1.7;white-space:pre-wrap;word-break:break-all;margin:0;overflow:hidden">claude mcp add --transport http wpilot <?php echo esc_html($endpoint); ?> --header "Authorization: Bearer <?php echo esc_html($new_key); ?>"</pre>
                <button type="button" class="ca-btn ca-btn-ghost" style="position:absolute;right:6px;top:6px;padding:5px 10px;font-size:11px"
                    onclick="navigator.clipboard.writeText(document.getElementById('mcp-cmd-val').textContent.trim());this.textContent='Copied!';setTimeout(()=>this.textContent='Copy',1500);">Copy</button>
            </div>
        </div>
    </div>
    <?php elseif ($notice === 'revoked') : ?>
    <div class="ca-alert ca-alert-warn" style="padding:14px 18px">
        <span class="ca-alert-icon">⚠️</span>
        <div><strong>API key revoked.</strong> Claude Code can no longer connect. Generate a new key to reconnect.</div>
    </div>
    <?php elseif ($notice === 'undo_ok') : ?>
    <div class="ca-alert ca-alert-ok" style="padding:14px 18px">
        <span class="ca-alert-icon">✅</span>
        <div><strong>Snapshot restored.</strong> <?php echo esc_html($undo_message); ?></div>
    </div>
    <?php elseif ($notice === 'undo_fail') : ?>
    <div class="ca-alert ca-alert-warn" style="padding:14px 18px">
        <span class="ca-alert-icon">⚠️</span>
        <div><strong>Undo failed.</strong> <?php echo esc_html($undo_message); ?></div>
    </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="ca-stat-row" style="margin-bottom:18px">
        <div class="ca-stat-card">
            <div class="ca-sc-label">Status</div>
            <div class="ca-sc-val"><?php echo $has_key ? '● Active' : '○ Inactive'; ?></div>
            <div class="ca-sc-sub"><?php echo $has_key ? 'Key created ' . esc_html($created) : 'No API key'; ?></div>
        </div>
        <div class="ca-stat-card">
            <div class="ca-sc-label">Requests</div>
            <div class="ca-sc-val"><?php echo number_format($total_reqs); ?></div>
            <div class="ca-sc-sub"><?php echo $last_req ? 'Last: ' . esc_html($last_req) : 'No requests yet'; ?></div>
        </div>
        <div class="ca-stat-card">
            <div class="ca-sc-label">Tools</div>
            <div class="ca-sc-val"><?php echo $tool_count; ?></div>
            <div class="ca-sc-sub">Available tools</div>
        </div>
        <div class="ca-stat-card">
            <div class="ca-sc-label">Rate Limit</div>
            <div class="ca-sc-val">120/min</div>
            <div class="ca-sc-sub">Requests per minute per IP</div>
        </div>
    </div>

    <!-- Built by Weblease -->

    <!-- Get Claude Code Guide -->
    <div class="ca-card ca-card-glow" style="border-color:rgba(79,126,255,.25)">
        <h3>📥 Get Claude Code</h3>
        <p class="ca-card-sub">Claude Code is Anthropic's AI coding tool. Install it once, then connect to your WordPress site.</p>

        <div style="display:grid;gap:16px;margin-top:8px">
            <div style="display:flex;gap:14px;align-items:flex-start">
                <div style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--ca-grad);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff">1</div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--ca-text)">Install Claude Code</div>
                    <div style="font-size:12px;color:var(--ca-text2);margin-top:3px">Open your terminal and run:</div>
                    <div style="background:var(--ca-bg);border:1px solid var(--ca-border2);border-radius:var(--ca-r2);padding:10px 14px;margin-top:6px;font-family:var(--ca-mono);font-size:11.5px;color:var(--ca-cyan)">npm install -g @anthropic-ai/claude-code</div>
                    <div style="font-size:11px;color:var(--ca-text3);margin-top:4px">Requires Node.js 18+. On Mac you can also use: <code style="color:var(--ca-text2)">brew install claude-code</code></div>
                </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-start">
                <div style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--ca-grad);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff">2</div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--ca-text)">Sign in to Anthropic</div>
                    <div style="font-size:12px;color:var(--ca-text2);margin-top:3px">Run <code style="font-family:var(--ca-mono);color:var(--ca-cyan)">claude</code> in your terminal. It will open a browser window to sign in with your Anthropic account.</div>
                    <div style="font-size:11px;color:var(--ca-text3);margin-top:4px">Need an account? <a href="https://claude.ai" target="_blank" rel="noopener" style="color:var(--ca-accent)">Sign up at claude.ai</a> — you need a Max plan or API account.</div>
                </div>
            </div>

            <div style="display:flex;gap:14px;align-items:flex-start">
                <div style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--ca-grad);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff">3</div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--ca-text)">Connect to your WordPress site</div>
                    <div style="font-size:12px;color:var(--ca-text2);margin-top:3px">Generate your API key below, then run the connection command in your terminal. Done!</div>
                </div>
            </div>
        </div>

        <div class="ca-card-footer">
            <a href="https://docs.anthropic.com/en/docs/claude-code" target="_blank" rel="noopener" style="font-size:12px;color:var(--ca-accent)">Claude Code documentation →</a>
        </div>
    </div>
    <!-- API Key Card -->
    <div class="ca-card <?php echo !$has_key ? 'ca-card-glow' : ''; ?>">
        <h3>🔑 API Key</h3>
        <?php if ($has_key) : ?>
            <p class="ca-card-sub">Your API key is active. Claude Code can connect to this site.</p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <form method="post">
                    <?php wp_nonce_field('wpilot_mcp_action'); ?>
                    <input type="hidden" name="wpilot_mcp_action" value="generate">
                    <button type="submit" class="ca-btn ca-btn-ghost">Regenerate Key</button>
                </form>
                <form method="post">
                    <?php wp_nonce_field('wpilot_mcp_action'); ?>
                    <input type="hidden" name="wpilot_mcp_action" value="revoke">
                    <button type="submit" class="ca-btn ca-btn-danger" onclick="return confirm('Revoke this key? Claude Code will immediately lose access.');">Revoke Key</button>
                </form>
            </div>
        <?php else : ?>
            <p class="ca-card-sub">Generate an API key to let Claude Code connect directly to your site.</p>
            <form method="post">
                <?php wp_nonce_field('wpilot_mcp_action'); ?>
                <input type="hidden" name="wpilot_mcp_action" value="generate">
                <button type="submit" class="ca-btn ca-btn-primary" style="font-size:13.5px;padding:10px 24px">Generate API Key</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Setup -->
    <div class="ca-card">
        <h3>🔌 Setup</h3>
        <p class="ca-card-sub">Connect Claude Code to your WordPress site in three steps.</p>

        <div style="display:grid;gap:14px;margin-top:4px">
            <?php
            $steps = [
                ['1', 'Generate API Key', 'Click the button above — you\'ll see the key once'],
                ['2', 'Copy Terminal Command', 'The full <code>claude mcp add</code> command is shown after generation'],
                ['3', 'Start Building', 'Claude Code now sees all ' . $tool_count . ' tools — ask it anything'],
            ];
            foreach ($steps as [$num, $title, $desc]) : ?>
            <div style="display:flex;gap:14px;align-items:flex-start">
                <div style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--ca-grad);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#fff"><?php echo $num; ?></div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--ca-text)"><?php echo $title; ?></div>
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
        <h3>💡 Example Commands</h3>
        <p class="ca-card-sub">Once connected, type directly in Claude Code:</p>

        <div style="display:grid;gap:8px;margin-top:4px">
            <?php
            $examples = [
                'Create an About page with a hero section and team grid',
                'Make the Shop button black with white text',
                'List all products under 100kr and set them on sale',
                'Install Wordfence and run a security scan',
                'Design the footer with 3 columns and social links',
                'Run a full SEO audit and fix all issues',
            ];
            foreach ($examples as $ex) : ?>
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:10px 14px;font-family:var(--ca-mono);font-size:12px;color:var(--ca-text2)">
                &gt; <?php echo esc_html($ex); ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="ca-card-footer">
            <div style="font-size:12px;color:var(--ca-text3)">
                Claude Code calls WPilot tools directly — no API costs for you, no parser, no auto-continue.
            </div>
        </div>
    </div>

    <!-- Security -->
    <div class="ca-card">
        <h3>🛡️ Security</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:4px">
            <?php
            $sec = [
                ['Encrypted API key', 'Key is never stored in plaintext'],
                ['Rate limiting', '120 requests per minute'],
                ['Token authentication', 'Every request must include a valid token'],
                ['Tool validation', 'Only whitelisted tool names accepted'],
            ];
            foreach ($sec as [$title, $desc]) : ?>
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:12px 14px">
                <div style="font-size:12px;font-weight:700;color:var(--ca-green)"><?php echo esc_html($title); ?></div>
                <div style="font-size:12px;color:var(--ca-text2);margin-top:3px"><?php echo esc_html($desc); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Audit Log -->
    <div class="ca-card">
        <h3>📋 Activity Log</h3>
        <p class="ca-card-sub">Every tool call through Claude Code is logged here.</p>

        <?php
        $audit_stats = function_exists('wpilot_mcp_audit_stats') ? wpilot_mcp_audit_stats() : ['total' => 0, 'today' => 0, 'errors' => 0, 'top_tools' => []];
        ?>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px">
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:12px 14px;text-align:center">
                <div style="font-size:20px;font-weight:800;color:var(--ca-cyan)"><?php echo number_format($audit_stats['total']); ?></div>
                <div style="font-size:11px;color:var(--ca-text3)">Total calls</div>
            </div>
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:12px 14px;text-align:center">
                <div style="font-size:20px;font-weight:800;color:var(--ca-green)"><?php echo number_format($audit_stats['today']); ?></div>
                <div style="font-size:11px;color:var(--ca-text3)">Today</div>
            </div>
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:var(--ca-r2);padding:12px 14px;text-align:center">
                <div style="font-size:20px;font-weight:800;color:<?php echo $audit_stats['errors'] > 0 ? 'var(--ca-red,#f87171)' : 'var(--ca-green)'; ?>"><?php echo number_format($audit_stats['errors']); ?></div>
                <div style="font-size:11px;color:var(--ca-text3)">Errors</div>
            </div>
        </div>

        <?php
        $recent = function_exists('wpilot_mcp_audit_recent') ? wpilot_mcp_audit_recent(20) : [];
        if (!empty($recent)) : ?>
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:12px">
                <thead>
                    <tr style="border-bottom:1px solid var(--ca-border)">
                        <th style="text-align:left;padding:8px 10px;color:var(--ca-text3);font-weight:600">Tool</th>
                        <th style="text-align:left;padding:8px 10px;color:var(--ca-text3);font-weight:600">Action</th>
                        <th style="text-align:left;padding:8px 10px;color:var(--ca-text3);font-weight:600">Result</th>
                        <th style="text-align:right;padding:8px 10px;color:var(--ca-text3);font-weight:600">Time</th>
                        <th style="text-align:right;padding:8px 10px;color:var(--ca-text3);font-weight:600">When</th>
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
                                <?php echo $is_err ? '✕ Error' : '✓ OK'; ?>
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
        <p style="color:var(--ca-text3);font-size:12px;text-align:center;padding:20px 0">No tool calls yet. Connect Claude Code to get started.</p>
        <?php endif; ?>
    </div>

    <!-- Undo History -->
    <div class="ca-card">
        <h3>↩️ Undo History</h3>
        <p class="ca-card-sub">Auto-saved snapshots before every destructive change. Click to restore.</p>

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
                        onclick="return confirm('Restore this snapshot?');">Undo</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
        <p style="color:var(--ca-text3);font-size:12px;text-align:center;padding:20px 0">No undo snapshots yet. Changes are auto-saved when Claude Code modifies your site.</p>
        <?php endif; ?>
    </div>

    <?php
    wpilot_wrap('Claude Code', '⚡', ob_get_clean());
}
