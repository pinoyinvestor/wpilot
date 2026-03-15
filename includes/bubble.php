<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Shared render function used by both admin and frontend
function wpilot_render_bubble() {
    if ( ! wpilot_user_has_access() || ! wpilot_can_use() ) return;

    $connected = wpilot_is_connected();
    $is_admin  = current_user_can( 'manage_options' );
    $can_modify = wpilot_user_can_modify();
    $locked    = wpilot_is_locked();

    // ── Inline JS data object (replaces wp_localize_script) ──
    // Injected directly before the bubble HTML so CA is always available
    $ca_data = [
        'ajax_url'     => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'ca_nonce' ),
        'auto_approve' => wpilot_auto_approve() ? 'yes' : 'no',
        'theme'        => wpilot_theme(),
        'limit'        => CA_FREE_LIMIT,
        'used'         => wpilot_prompts_used(),
        'licensed'     => wpilot_is_licensed()  ? 'yes' : 'no',
        'locked'       => $locked           ? 'yes' : 'no',
        'connected'    => $connected        ? 'yes' : 'no',
            'license_type' => wpilot_license_type(),
            'is_lifetime'  => wpilot_is_lifetime() ? 'yes' : 'no',
        'woo'          => class_exists( 'WooCommerce' ) ? 'yes' : 'no',
        'page_title'   => is_admin() ? get_admin_page_title() : wp_title('', false),
        'current_url'  => (is_ssl() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? ''),
        'current_post_id' => (!is_admin() && is_singular()) ? get_the_ID() : 0,
        'current_post_type' => (!is_admin() && is_singular()) ? get_post_type() : '',
        'is_front_page' => (!is_admin() && is_front_page()) ? 'yes' : 'no',
        'site_name'    => get_bloginfo( 'name' ),
        'site_url'     => get_site_url(),
        'builder'      => wpilot_detect_builder(),
        'settings_url' => admin_url( 'admin.php?page=' . CA_SLUG . '-settings' ),
        'license_url'  => admin_url( 'admin.php?page=' . CA_SLUG . '-license' ),
        'is_admin'     => $is_admin ? 'yes' : 'no',
        'can_modify'   => $can_modify ? 'yes' : 'no',
        'plugin_name'  => 'WPilot',
        'i18n'         => [
            'error_no_credits'   => wpilot_t( 'error_no_credits' ),
            'add_credits'        => wpilot_t( 'add_credits_btn' ),
            'guide'              => wpilot_t( 'step_by_step_guide' ),
        ],
    ];
    echo '<script>var CA = ' . wp_json_encode( $ca_data ) . ';</script>';
    ?>

    <div id="caRoot" data-theme="<?= esc_attr( wpilot_theme() ) ?>">

        <button id="caTrigger" aria-label="WPilot — powered by Claude AI (Alt+C)">
            <span class="ca-t-idle">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </span>
            <span class="ca-t-open" style="display:none">✕</span>
            <span id="caBubbleBadge" style="display:none"></span>
        </button>

        <div id="caPanel" style="display:none">

            <div class="cap-hdr">
                <div class="cap-hdr-l">
                    <div class="cap-av">⚡</div>
                    <div>
                        <div class="cap-name">WPilot</div>
                        <div class="cap-powered">Powered by <a href="https://anthropic.com" target="_blank" rel="noopener">Claude AI</a></div>
                    </div>
                </div>
                <div class="cap-hdr-r">
                    <?php if ( $is_admin ): ?>
                    <button class="cap-ib" id="capCfgBtn" title="Quick settings">⚙️</button>
                    <?php endif; ?>
                    <button class="cap-ib" id="capClose">✕</button>
                </div>
            </div>

            <?php if ( $is_admin ): ?>
            <div id="capCfgPanel" style="display:none;padding:9px 14px;border-bottom:1px solid var(--ca-border);font-size:12px;background:var(--ca-bg3)">
                <label style="display:flex;align-items:center;justify-content:space-between;color:var(--ca-text2);margin-bottom:8px">
                    <span>Auto-approve changes</span>
                    <input type="checkbox" id="capAutoApprove" <?= wpilot_auto_approve() ? 'checked' : '' ?>>
                </label>
                <div style="display:flex;gap:5px;flex-wrap:wrap">
                    <a href="<?= esc_url( admin_url( 'admin.php?page=' . CA_SLUG ) ) ?>" class="cap-cfg-link">📊 Dashboard</a>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=' . CA_SLUG . '-chat' ) ) ?>" class="cap-cfg-link">💬 Full Chat</a>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=' . CA_SLUG . '-analyze' ) ) ?>" class="cap-cfg-link">🔍 Analyze</a>
                    <a href="<?= esc_url( admin_url( 'admin.php?page=' . CA_SLUG . '-settings' ) ) ?>" class="cap-cfg-link">⚙️ Settings</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="cap-ctx">
                <span>📍 <?= esc_html( get_bloginfo('name') ) ?></span>
                <span class="cap-ctx-r">
                    <?php
                    $tier_name = wpilot_get_license_tier();
                    $tier_labels = ['free'=>'Free','pro'=>'⚡ Pro','team'=>'👥 Team','lifetime'=>'🏆 Lifetime'];
                    $tier_label = $tier_labels[$tier_name] ?? ucfirst($tier_name);
                    if ( $connected && ! $locked ): ?>
                        <span class="cap-pill-ok">● <?= esc_html($tier_label) ?></span>
                    <?php elseif ( $locked ): ?>
                        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-license') ) ?>" class="cap-pill-warn">🔒 Upgrade</a>
                    <?php elseif ( $is_admin ): ?>
                        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-settings') ) ?>" class="cap-pill-warn">⚡ Connect →</a>
                    <?php else: ?>
                        <span class="cap-pill-off">○ Not connected</span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="cap-msgs" id="capMsgs">
                <?php if ( ! $connected ): ?>

                <div class="cap-nc">
                    <div class="cap-nc-logo">⚡</div>
                    <h3 class="cap-nc-h"><?php wpilot_te('bubble_connect_title') ?></h3>
                    <?php if ( $is_admin ): ?>
                    <div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);border-radius:8px;padding:10px 12px;margin:8px 0 12px;text-align:left;font-size:11.5px;line-height:1.6;color:#fde68a">
                        <strong style="display:block;margin-bottom:3px;font-size:12px"><?php wpilot_te('bubble_subscription_warning') ?></strong>
                        <?php wpilot_te('bubble_subscription_body') ?>
                    </div>
                    <div class="cap-nc-steps">
                        <div class="cap-nc-step"><span class="cap-nc-n">1</span><div><?php wpilot_te('bubble_step1') ?> — <a href="https://console.anthropic.com" target="_blank" rel="noopener">console.anthropic.com</a></div></div>
                        <div class="cap-nc-step"><span class="cap-nc-n">2</span><div><?php wpilot_te('bubble_step2') ?></div></div>
                        <div class="cap-nc-step"><span class="cap-nc-n">3</span><div><?php wpilot_te('bubble_step3') ?> — <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-settings') ) ?>"><?php wpilot_te('settings_link') ?></a></div></div>
                    </div>
                    <div class="cap-nc-btns">
                        <a href="https://console.anthropic.com/settings/billing" target="_blank" rel="noopener" class="cap-nc-btn cap-nc-btn-primary"><?php wpilot_te('add_credits_btn') ?></a>
                        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-guide') ) ?>" class="cap-nc-btn cap-nc-btn-ghost"><?php wpilot_te('step_by_step_guide') ?></a>
                    </div>
                    <?php else: ?>
                    <p class="cap-nc-sub"><?php wpilot_te('bubble_ask_admin') ?></p>
                    <?php endif; ?>
                </div>

                <?php elseif ( $locked ): ?>

                <?php
                $slots_remaining = get_transient('wpi_lifetime_slots');
                $has_lifetime_slots = $slots_remaining !== false && (int)$slots_remaining > 0;
                ?>
                <div class="cap-nc">
                    <div class="cap-nc-logo">🔒</div>
                    <h3 class="cap-nc-h">Free limit reached</h3>
                    <?php if($has_lifetime_slots): ?>
                    <div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:12px 14px;margin:10px 0;text-align:left">
                        <div style="font-size:13px;font-weight:700;color:#fbbf24;margin-bottom:4px">🏆 <?= (int)$slots_remaining ?> Lifetime slots left</div>
                        <div style="font-size:12px;color:#d97706;line-height:1.5">You connected your API key early. Your access is <strong>free forever</strong> — no $<?= CA_MONTHLY_PRICE ?>/month fee.</div>
                    </div>
                    <?php if($is_admin): ?>
                    <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-license')) ?>" class="cap-nc-btn cap-nc-btn-primary" style="margin-top:8px;background:linear-gradient(135deg,#f59e0b,#d97706)">🏆 Claim Lifetime Access →</a>
                    <?php endif; ?>
                    <?php else: ?>
                    <p class="cap-nc-sub">You've used all <?= CA_FREE_LIMIT ?> free prompts.</p>
                    <div style="font-size:13px;font-weight:700;color:#93c5fd;margin:8px 0 4px">Pro — $<?= CA_MONTHLY_PRICE ?>/month</div>
                    <p style="font-size:12px;color:var(--cap-text2,#94a3b8);margin:0 0 10px">Unlimited prompts, all features, your own Claude API key.</p>
                    <?php if($is_admin): ?>
                    <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-license')) ?>" class="cap-nc-btn cap-nc-btn-primary" style="margin-top:4px">⚡ Upgrade to Pro — $<?= CA_MONTHLY_PRICE ?>/mo →</a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>


                <?php else: ?>

                <div class="cap-welcome" id="capWelcome">
                    <div class="cap-wlc-av">⚡</div>
                    <div class="cap-wlc-title">Ready to build</div>
                    <div class="cap-wlc-sub">Ask Claude to design, fix, or improve anything on your WordPress site — live.</div>
                    <div class="cap-chips">
                        <button class="cap-chip" data-msg="What can I improve on this page right now?">💡 Improve this page</button>
                        <button class="cap-chip" data-msg="Analyze my site and give me the top 3 issues to fix.">🔍 Site analysis</button>
                        <button class="cap-chip" data-msg="Suggest CSS improvements to make my site look more premium.">🎨 Improve design</button>
                        <button class="cap-chip" data-msg="What are 3 quick SEO wins I can apply today?">📈 SEO wins</button>
                        <button class="cap-chip" data-msg="Help me create a new page for my site.">🏗️ Build a page</button>
                    </div>
                </div>

                <?php endif; ?>

                <div id="capTyping" style="display:none">
                    <div class="cap-m cap-m-ai">
                        <div class="cap-av">⚡</div>
                        <div class="cap-dots"><span></span><span></span><span></span></div>
                    </div>
                </div>
            </div>

            <div class="cap-inp-wrap">
                <?php if ( $connected && ! $locked ): ?>
                    <textarea id="capIn" placeholder="Ask Claude to build, design, or fix…" rows="1"></textarea>
                    <button id="capSend">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                <?php elseif ( ! $connected ): ?>
                    <div class="cap-inp-cta">
                        <?php if ( $is_admin ): ?>
                            <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-settings') ) ?>">⚡ Connect Claude API to start →</a>
                        <?php else: ?>
                            <span>Claude not connected — ask admin</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="cap-inp-cta">
                        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-license') ) ?>">🔒 Upgrade for unlimited prompts →</a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="cap-footer">
                <span>WPilot by <a href="https://weblease.se" target="_blank" rel="noopener">Weblease</a></span>
                <span class="cap-footer-dot">·</span>
                <span>Powered by <a href="https://anthropic.com" target="_blank" rel="noopener">Claude AI</a></span>
                <?php
                $slots_msg = '';
                if (!wpilot_is_licensed()) {
                    $slots_cached = get_transient('wpi_lifetime_slots');
                    if ($slots_cached !== false && (int)$slots_cached > 0) {
                        $slots_msg = '<div class="cap-lifetime-hint">🏆 ' . (int)$slots_cached . ' Lifetime slots left</div>';
                    }
                }
                echo $slots_msg;
                ?>
                <?php if ( $connected ): ?>
                <span class="cap-footer-dot">·</span>
                <?php if ( wpilot_is_licensed() ): ?>
                <span class="cap-footer-prompts" style="color:var(--ca-green,#10b981)"><?= esc_html($tier_label) ?> · ∞</span>
                <?php else: ?>
                <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-license') ) ?>" class="cap-footer-prompts"><?= wpilot_prompts_remaining() ?> prompts left</a>
                <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Floating Undo Button — appears after any AI change -->
    <div id="wpiFloatingUndo">
        <button onclick="wpiUndoLastChange()">↩ Undo last change</button>
    </div>

    <!-- Crash Recovery Banner — shows if last change caused issues -->
    <div id="wpiCrashRecovery">
        <p>⚠ WPilot detected a problem after the last change.</p>
        <button onclick="wpiAutoRestore()">↩ Restore previous state</button>
        <button onclick="document.getElementById('wpiCrashRecovery').style.display='none'" style="background:transparent;color:#fecaca;border:1px solid rgba(254,202,202,.3)">Dismiss</button>
    </div>

    <script>
    (function(){
        // Built by Christos Ferlachidis & Daniel Hedenberg

        function wpiLS(key,val){try{if(val!==undefined)localStorage.setItem(key,val);else return localStorage.getItem(key);}catch(e){return null;}}

        var lastBackupId = null;

        // Listen for tool executions — show undo button
        jQuery(document).on('click', '.cap-ac-apply', function(){
            // After tool runs, capture backup_id from response
            var origDone = jQuery._data ? null : null; // handled in bubble.js
        });

        // Global: store last backup ID when tool succeeds
        window.wpiSetLastBackup = function(id) {
            lastBackupId = id;
            // Store in localStorage for crash recovery
            wpiLS('wpi_last_backup', JSON.stringify({id: id, time: Date.now()}));
            // Show floating undo
            var el = document.getElementById('wpiFloatingUndo');
            if (el) el.classList.add('visible');
            // Auto-hide after 60 seconds
            setTimeout(function(){ if(el) el.classList.remove('visible'); }, 60000);
        };

        // Undo last change
        window.wpiUndoLastChange = function() {
            if (!lastBackupId) {
                var stored = wpiLS('wpi_last_backup');
                if (stored) lastBackupId = JSON.parse(stored).id;
            }
            if (!lastBackupId) { alert('No changes to undo.'); return; }

            var btn = document.querySelector('#wpiFloatingUndo button');
            if (btn) btn.textContent = 'Restoring...';

            jQuery.post(
                (typeof CA !== 'undefined' ? CA.ajax_url : ajaxurl),
                {action: 'ca_restore_backup', nonce: (typeof CA !== 'undefined' ? CA.nonce : ''), backup_id: lastBackupId},
                function(r) {
                    if (r && r.success) {
                        if (btn) btn.textContent = '✓ Restored!';
                        wpiLS('wpi_last_backup', '');
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        if (btn) btn.textContent = '↩ Undo last change';
                        alert('Could not restore: ' + (r && r.data ? (typeof r.data === 'string' ? r.data : r.data.message) : 'Unknown error'));
                    }
                }
            );
        };

        // Auto-restore for crash recovery
        window.wpiAutoRestore = function() {
            var stored = wpiLS('wpi_last_backup');
            if (!stored) return;
            var data = JSON.parse(stored);
            jQuery.post(
                (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'),
                {action: 'ca_restore_backup', nonce: (typeof CA !== 'undefined' ? CA.nonce : ''), backup_id: data.id},
                function(r) {
                    wpiLS('wpi_last_backup', '');
                    location.reload();
                }
            );
        };

        // Check on page load: was there a pending change that might have caused issues?
        jQuery(function(){
            var stored = wpiLS('wpi_last_backup');
            if (!stored) return;
            var data = JSON.parse(stored);
            // If change was made less than 5 minutes ago AND page had PHP errors
            var age = Date.now() - data.time;
            if (age < 300000) {
                // Check if page has WordPress critical error or PHP notice
                var bodyText = document.body ? (document.body.textContent || '') : '';
                var hasError = bodyText.indexOf('critical error') !== -1 ||
                               bodyText.indexOf('Fatal error') !== -1 ||
                               bodyText.indexOf('Parse error') !== -1 ||
                               document.querySelector('.error, .notice-error, #error-page');
                if (hasError) {
                    var el = document.getElementById('wpiCrashRecovery');
                    if (el) el.classList.add('visible');
                }
                // Also show floating undo if recent change
                lastBackupId = data.id;
                var undo = document.getElementById('wpiFloatingUndo');
                if (undo) undo.classList.add('visible');
            } else {
                // Old backup — clean up
                wpiLS('wpi_last_backup', '');
            }
        });
    })();
    </script>
    <?php
}

// Render on both admin and frontend for allowed roles
add_action( 'admin_footer', 'wpilot_render_bubble' );
add_action( 'wp_footer',    'wpilot_render_bubble' );
