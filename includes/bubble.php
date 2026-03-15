<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Shared render function used by both admin and frontend
function wpilot_render_bubble() {
    if ( ! wpilot_user_has_access() && ! current_user_can( "manage_options" ) ) return;

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
                    <button class="cap-ib" id="capClearChat" title="Clear chat">🗑️</button>
                    <button class="cap-ib" id="capRestartScan" title="Clear + re-analyze site">🔄</button>
                    <button class="cap-ib" id="capExportChat" title="Export chat">📋</button>
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
                        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-license') ) ?>" class="cap-pill-warn">🔑 Activate</a>
                    <?php elseif ( $is_admin ): ?>
                        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-settings') ) ?>" class="cap-pill-warn">⚡ Connect →</a>
                    <?php else: ?>
                        <span class="cap-pill-off">○ Not connected</span>
                    <?php endif; ?>
                </span>
            </div>

            <div class="cap-msgs" id="capMsgs">
                <?php if ( ! $connected ): ?>

                <div class="cap-nc" style="padding:20px 14px">
                    <div class="cap-nc-logo">⚡</div>
                    <h3 class="cap-nc-h">Connect Claude AI</h3>
                    <?php if ( $is_admin ): ?>
                    <p class="cap-nc-sub" style="margin-bottom:12px">Paste your Claude API key to start. Get one free at <a href="https://console.anthropic.com" target="_blank" rel="noopener" style="color:#4F7EFF">console.anthropic.com</a></p>
                    
                    <div style="width:100%;margin-bottom:12px">
                        <input type="password" id="capApiKeyInput" placeholder="sk-ant-api03-..." onkeydown="if(event.key==='Enter'){event.preventDefault();document.getElementById('capConnectBtn').click()}" 
                            style="width:100%;padding:10px 14px;background:var(--ca-bg3);border:1px solid var(--ca-border2);border-radius:10px;color:var(--ca-text);font-family:var(--ca-mono);font-size:12px;outline:none" />
                    </div>
                    
                    <button id="capConnectBtn" style="width:100%;padding:10px;background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;border:none;border-radius:10px;font-weight:700;font-size:13px;cursor:pointer" onclick="var b=this,i=document.getElementById('capApiKeyInput'),s=document.getElementById('capConnectStatus'),k=i?i.value.trim():'';if(!k||k.indexOf('sk-')!==0){if(s){s.textContent='Enter a valid key (starts with sk-)';s.style.color='#EF4444';s.style.display='block'}return}b.textContent='Connecting...';b.disabled=true;if(s){s.textContent='Testing...';s.style.color='#5E6E91';s.style.display='block'}var x=new XMLHttpRequest();x.open('POST',(typeof ajaxurl!=='undefined'?ajaxurl:'/wp-admin/admin-ajax.php'),true);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');x.onload=function(){try{var r=JSON.parse(x.responseText);if(r&&r.success){if(s){s.textContent='Connected! Reloading...';s.style.color='#10B981'}setTimeout(function(){location.reload()},1000)}else{if(s){s.textContent=(r&&r.data?(typeof r.data==='string'?r.data:r.data.message||'Failed'):'Failed');s.style.color='#EF4444'}b.textContent='Connect & Start';b.disabled=false}}catch(e){if(s){s.textContent='Error: '+e.message;s.style.color='#EF4444'}b.textContent='Connect & Start';b.disabled=false}};x.onerror=function(){if(s){s.textContent='Network error';s.style.color='#EF4444'}b.textContent='Connect & Start';b.disabled=false};x.send('action=ca_test_connection&nonce='+(typeof CA!=='undefined'&&CA.nonce?CA.nonce:'')+'&key='+encodeURIComponent(k))">
                        Connect & Start
                    </button>
                    
                    <p id="capConnectStatus" style="display:none;margin-top:8px;font-size:12px;text-align:center"></p>
                    
                    <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--ca-border)">
                        <p style="font-size:11px;color:var(--ca-text3);text-align:center;line-height:1.6">
                            Your key stays on your server only.<br/>Never shared with Weblease.
                        </p>
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
                    <h3 class="cap-nc-h">Activate License</h3>
                    <?php if($has_lifetime_slots): ?>
                    <div style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);border-radius:10px;padding:12px 14px;margin:10px 0;text-align:left">
                        <div style="font-size:13px;font-weight:700;color:#fbbf24;margin-bottom:4px">🏆 <?= (int)$slots_remaining ?> Lifetime slots left</div>
                        <div style="font-size:12px;color:#d97706;line-height:1.5">You connected your API key early. Your access is <strong>free forever</strong> — no $<?= CA_MONTHLY_PRICE ?>/month fee.</div>
                    </div>
                    <?php if($is_admin): ?>
                    <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-license')) ?>" class="cap-nc-btn cap-nc-btn-primary" style="margin-top:8px;background:linear-gradient(135deg,#f59e0b,#d97706)">🏆 Claim Lifetime Access →</a>
                    <?php endif; ?>
                    <?php else: ?>
                    <p class="cap-nc-sub">Activate your WPilot license to continue using the AI assistant.</p>
                    <div style="font-size:13px;font-weight:700;color:#93c5fd;margin:8px 0 4px">Pro — $<?= CA_MONTHLY_PRICE ?>/month</div>
                    
                    <?php if($is_admin): ?>
                    <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-license')) ?>" class="cap-nc-btn cap-nc-btn-primary" style="margin-top:4px">⚡ Activate License →</a>
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
                        <div class="cap-mt" style="display:flex;align-items:center;gap:8px">
                            <div class="cap-dots"><span></span><span></span><span></span></div>
                            <span id="capThinkText" style="font-size:12px;color:var(--ca-text2)">Thinking...</span>
                            <span id="capThinkTimer" style="font-size:11px;color:var(--ca-text3);font-family:var(--ca-mono)">0s</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cap-inp-wrap">
                <?php if ( $connected && ! $locked ): ?>
                    <div class="cap-input-row" style="display:flex;gap:7px;align-items:flex-end">
                    <?php if ( wpilot_is_licensed() ): ?>
                    <label for="capFileUpload" style="cursor:pointer;padding:8px;color:var(--ca-text3,#5E6E91);font-size:16px;flex-shrink:0" title="Upload file (image, CSV, PDF)">📎</label>
                    <input type="file" id="capFileUpload" accept="image/*,.csv,.xlsx,.xls,.pdf,.txt,.json" style="display:none" />
                    <?php endif; ?>
                    <textarea id="capIn" placeholder="Ask Claude to build, design, or fix…" rows="1"></textarea>
                    <button id="capSend">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                    </div>
                <?php elseif ( ! $connected ): ?>
                    <div class="cap-inp-cta" style="text-align:center;padding:8px 12px">
                        <?php if ( $is_admin ): ?>
                            <p style="font-size:11px;color:var(--ca-text3);margin:0">Paste your API key above or type it here to connect</p>
                        <?php else: ?>
                            <span style="font-size:11px;color:var(--ca-text3)">Claude not connected — ask admin</span>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="cap-inp-cta">
                        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-license') ) ?>">🔑 Activate License →</a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="cap-footer">
                <span>WPilot by <a href="https://weblease.se" target="_blank" rel="noopener">Weblease</a></span>
                
                <span class="cap-footer-dot">·</span>
                <span>Powered by <a href="https://anthropic.com" target="_blank" rel="noopener">Claude AI</a></span>
                <span class="cap-footer-dot">&middot;</span><span><a href="#" onclick="wpiShowFeedback();return false" style="color:var(--ca-text3)">Feedback</a></span>
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


        // Connect API key - inline so it works without external JS
        window.wpiConnect = function() {
            var btn = document.getElementById('capConnectBtn');
            var inp = document.getElementById('capApiKeyInput');
            var st = document.getElementById('capConnectStatus');
            if (!btn || !inp) return;
            var key = (inp.value || '').trim();
            if (!key || key.indexOf('sk-') !== 0) {
                if(st){st.textContent='Enter a valid Claude API key (starts with sk-)';st.style.color='#EF4444';st.style.display='block';}
                return;
            }
            btn.textContent = 'Connecting...';
            btn.disabled = true;
            if(st){st.textContent='Testing connection...';st.style.color='#5E6E91';st.style.display='block';}
            var xhr = new XMLHttpRequest();
            var url = (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php';
            var nonce = (typeof CA !== 'undefined' && CA.nonce) ? CA.nonce : '';
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r && r.success) {
                        if(st){st.textContent='Connected! Reloading...';st.style.color='#10B981';}
                        setTimeout(function(){location.reload()},1000);
                    } else {
                        var m = r && r.data ? (typeof r.data === 'string' ? r.data : r.data.message || 'Failed') : 'Failed';
                        if(st){st.textContent=m;st.style.color='#EF4444';}
                        btn.textContent='Connect & Start';btn.disabled=false;
                    }
                } catch(e) {
                    if(st){st.textContent='Error parsing response';st.style.color='#EF4444';}
                    btn.textContent='Connect & Start';btn.disabled=false;
                }
            };
            xhr.onerror = function() {
                if(st){st.textContent='Network error';st.style.color='#EF4444';}
                btn.textContent='Connect & Start';btn.disabled=false;
            };
            xhr.send('action=ca_test_connection&nonce=' + encodeURIComponent(nonce) + '&key=' + encodeURIComponent(key));
        };

        // Debug removed - was causing JS syntax errors


        // Apply tool - inline so it works without bubble.js event delegation
        window.wpiApply = function(btn) {
            if (btn.disabled) return;
            var tool = btn.getAttribute('data-tool') || '';
            var params = {};
            try { params = JSON.parse(btn.getAttribute('data-params') || '{}'); } catch(e) {}
            var lbl = btn.getAttribute('data-label') || '';
            var dsc = btn.getAttribute('data-desc') || '';
            var card = btn.closest('.cap-ac');
            var skipBtn = card ? card.querySelector('.cap-ac-skip') : null;

            // Thinking state with timer
            var startTime = Date.now();
            btn.textContent = '\u23f3 Thinking...';
            btn.disabled = true;
            btn.style.cssText = 'padding:5px 11px;background:rgba(91,127,255,.15);color:#93B4FF;border:none;border-radius:6px;font-size:11.5px;font-weight:700;cursor:wait;opacity:0.7;pointer-events:none';
            if (skipBtn) skipBtn.style.display = 'none';
            var thinkSecs = 0;
            var thinkInterval = setInterval(function() {
                thinkSecs++;
                var label = thinkSecs < 5 ? 'Thinking' : thinkSecs < 15 ? 'Working' : 'Almost done';
                btn.textContent = '\u23f3 ' + label + '... ' + thinkSecs + 's';
            }, 1000);

            var url = (typeof CA !== 'undefined' && CA.ajax_url) ? CA.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            var nc = (typeof CA !== 'undefined' && CA.nonce) ? CA.nonce : '';

            var x = new XMLHttpRequest();
            x.open('POST', url, true);
            x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            x.onload = function() {
                try {
                    var r = JSON.parse(x.responseText);
                    if (r && r.success) {
                        clearInterval(thinkInterval);
                        btn.textContent = '\u2705 Done';
                        btn.disabled = true;
                        btn.onclick = null;
                        btn.style.cssText = 'padding:5px 11px;background:#10B981;color:#fff;border:none;border-radius:6px;font-size:11.5px;font-weight:700;cursor:default;opacity:1;pointer-events:none';
                        if (card) card.style.borderLeftColor = '#10B981';
                        var msg = r.data && r.data.message ? r.data.message : 'Done';
                        var bid = r.data && r.data.backup_id ? r.data.backup_id : null;
                        if (card) {
                            var wrap = document.createElement('div');
                            wrap.style.cssText = 'margin-top:10px;padding:10px 12px;background:rgba(16,185,129,.06);border:1px solid rgba(16,185,129,.15);border-radius:8px';
                            var msgDiv = document.createElement('div');
                            msgDiv.style.cssText = 'font-size:12px;color:#6EE7B7;line-height:1.5';
                            msgDiv.textContent = '\u2705 ' + msg;
                            wrap.appendChild(msgDiv);
                            if (bid) {
                                var btnsDiv = document.createElement('div');
                                btnsDiv.style.cssText = 'display:flex;gap:6px;margin-top:8px;flex-wrap:wrap';
                                var undoBtn = document.createElement('button');
                                undoBtn.textContent = '\u21a9\ufe0f Undo';
                                undoBtn.style.cssText = 'padding:5px 10px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);border-radius:6px;color:#FCD34D;font-size:11px;font-weight:600;cursor:pointer';
                                undoBtn.onclick = function() { wpiUndo(bid, undoBtn); };
                                btnsDiv.appendChild(undoBtn);
                                var refBtn = document.createElement('button');
                                refBtn.textContent = '\ud83d\udd04 Refresh';
                                refBtn.style.cssText = 'padding:5px 10px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);border-radius:6px;color:#6EE7B7;font-size:11px;font-weight:600;cursor:pointer';
                                refBtn.onclick = function() { location.reload(); };
                                btnsDiv.appendChild(refBtn);
                                wrap.appendChild(btnsDiv);
                            }
                            card.appendChild(wrap);
                        }
                        if (typeof window.wpiAddToHistory === 'function') {
                            window.wpiAddToHistory('assistant', '[TOOL EXECUTED] ' + tool + ': ' + msg);
                        }
                    } else {
                        clearInterval(thinkInterval);
                        var errMsg = r && r.data ? (typeof r.data === 'string' ? r.data : r.data.message || 'Error') : 'Error';
                        btn.textContent = '\u274c Retry';
                        btn.style.cssText = 'padding:5px 11px;background:#EF4444;color:#fff;border:none;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;opacity:1';
                        btn.disabled = false;
                        if (card) {
                            card.style.borderLeftColor = '#EF4444';
                            var errDiv = document.createElement('div');
                            errDiv.style.cssText = 'margin-top:10px;padding:10px 12px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);border-radius:8px;font-size:12px;color:#FCA5A5;line-height:1.5';
                            errDiv.textContent = '\u26a0\ufe0f ' + errMsg;
                            card.appendChild(errDiv);
                        }
                        if (typeof window.wpiAddToHistory === 'function') {
                            window.wpiAddToHistory('assistant', '[TOOL FAILED] ' + tool + ': ' + errMsg);
                        }
                    }
                } catch(e) {
                    clearInterval(thinkInterval);
                    btn.textContent = '\u274c Error';
                    btn.style.cssText = 'padding:5px 11px;background:#EF4444;color:#fff;border:none;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;opacity:1';
                    btn.disabled = false;
                }
            };
            x.onerror = function() {
                clearInterval(thinkInterval);
                btn.textContent = '\u274c Network error';
                btn.style.cssText = 'padding:5px 11px;background:#EF4444;color:#fff;border:none;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;opacity:1';
                btn.disabled = false;
            };
            x.send('action=ca_tool&nonce=' + encodeURIComponent(nc) + '&tool=' + encodeURIComponent(tool) + '&params=' + encodeURIComponent(JSON.stringify(params)) + '&label=' + encodeURIComponent(lbl) + '&description=' + encodeURIComponent(dsc));
        };

        window.wpiUndo = function(backupId, btn) {
            if (btn) { btn.textContent = 'Restoring...'; btn.disabled = true; }
            var url = (typeof CA !== 'undefined' && CA.ajax_url) ? CA.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
            var nc = (typeof CA !== 'undefined' && CA.nonce) ? CA.nonce : '';
            var x = new XMLHttpRequest();
            x.open('POST', url, true);
            x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            x.onload = function() {
                try {
                    var r = JSON.parse(x.responseText);
                    if (r && r.success) {
                        if (btn) { btn.textContent = '\u2705 Restored'; btn.style.color = '#10B981'; }
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        if (btn) { btn.textContent = '\u274c Could not restore'; btn.disabled = false; }
                    }
                } catch(e) { if (btn) { btn.textContent = '\u274c Error'; btn.disabled = false; } }
            };
            x.onerror = function() { if (btn) { btn.textContent = '\u274c Network error'; btn.disabled = false; } };
            x.send('action=ca_restore_backup&nonce=' + encodeURIComponent(nc) + '&backup_id=' + backupId);
        };


        // Chat thinking indicator - works independently of bubble.js
        window.wpiShowThinking = function() {
            var el = document.getElementById('capTyping');
            if (el) {
                el.style.display = 'block';
                var txt = document.getElementById('capThinkText');
                var tmr = document.getElementById('capThinkTimer');
                if (txt) txt.textContent = 'Thinking...';
                if (tmr) tmr.textContent = '0s';
                var start = Date.now();
                window._wpiThinkTimer = setInterval(function() {
                    var s = Math.floor((Date.now() - start) / 1000);
                    if (tmr) tmr.textContent = s + 's';
                    if (txt && s > 5) txt.textContent = 'Working...';
                    if (txt && s > 15) txt.textContent = 'Almost done...';
                }, 1000);
                // Scroll to it
                var msgs = document.getElementById('capMsgs');
                if (msgs) msgs.scrollTop = msgs.scrollHeight + 9999;
            }
        };
        window.wpiHideThinking = function() {
            var el = document.getElementById('capTyping');
            if (el) el.style.display = 'none';
            if (window._wpiThinkTimer) { clearInterval(window._wpiThinkTimer); window._wpiThinkTimer = null; }
        };

        // Feedback form
        window.wpiShowFeedback = function() {
            var msgs = document.getElementById('capMsgs');
            if (!msgs) return;
            var existing = document.getElementById('wpiFeedbackForm');
            if (existing) { existing.remove(); return; }
            var fbType = 'feedback';
            var form = document.createElement('div');
            form.id = 'wpiFeedbackForm';
            form.style.cssText = 'padding:14px;background:var(--ca-bg3,#0B0E18);border:1px solid var(--ca-border2,#1a1a2e);border-radius:12px;margin:8px 0';

            var title = document.createElement('div');
            title.textContent = 'Send Feedback';
            title.style.cssText = 'font-size:13px;font-weight:700;margin-bottom:8px;color:var(--ca-text,#fff)';
            form.appendChild(title);

            var types = document.createElement('div');
            types.style.cssText = 'display:flex;gap:6px;margin-bottom:8px';
            ['Feedback','Bug','Feature'].forEach(function(t) {
                var btn = document.createElement('button');
                btn.textContent = t;
                btn.style.cssText = 'padding:4px 10px;border-radius:6px;border:1px solid var(--ca-border2,#333);background:var(--ca-bg4,#111);color:var(--ca-text2,#888);font-size:11px;cursor:pointer';
                btn.onclick = function() {
                    fbType = t.toLowerCase();
                    types.querySelectorAll('button').forEach(function(b){b.style.background='var(--ca-bg4,#111)';b.style.color='var(--ca-text2,#888)';});
                    btn.style.background='#4F7EFF';btn.style.color='#fff';
                };
                types.appendChild(btn);
            });
            form.appendChild(types);

            var textarea = document.createElement('textarea');
            textarea.id = 'wpiFbMsg';
            textarea.placeholder = 'Tell us what you think...';
            textarea.style.cssText = 'width:100%;height:60px;padding:8px;background:var(--ca-bg,#050608);border:1px solid var(--ca-border2,#333);border-radius:8px;color:var(--ca-text,#fff);font-size:12px;resize:none;font-family:inherit;box-sizing:border-box';
            form.appendChild(textarea);

            var btns = document.createElement('div');
            btns.style.cssText = 'display:flex;gap:6px;margin-top:8px';
            var sendBtn = document.createElement('button');
            sendBtn.textContent = 'Send';
            sendBtn.style.cssText = 'padding:6px 14px;background:#4F7EFF;color:#fff;border:none;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer';
            sendBtn.onclick = function() {
                var msg = document.getElementById('wpiFbMsg');
                if (!msg || !msg.value.trim()) return;
                sendBtn.textContent = 'Sending...';
                sendBtn.disabled = true;
                var url = (typeof CA !== 'undefined' && CA.ajax_url) ? CA.ajax_url : '/wp-admin/admin-ajax.php';
                var nc = (typeof CA !== 'undefined' && CA.nonce) ? CA.nonce : '';
                var x = new XMLHttpRequest();
                x.open('POST', url, true);
                x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                x.onload = function() {
                    try {
                        var r = JSON.parse(x.responseText);
                        if (r.success) { sendBtn.textContent = 'Thank you!'; sendBtn.style.background='#10B981'; setTimeout(function(){form.remove()},1500); }
                        else { sendBtn.textContent = 'Send'; sendBtn.disabled = false; }
                    } catch(e) { sendBtn.textContent = 'Send'; sendBtn.disabled = false; }
                };
                x.send('action=wpi_send_feedback&nonce='+encodeURIComponent(nc)+'&message='+encodeURIComponent(msg.value.trim())+'&type='+encodeURIComponent(fbType));
            };
            btns.appendChild(sendBtn);
            var cancelBtn = document.createElement('button');
            cancelBtn.textContent = 'Cancel';
            cancelBtn.style.cssText = 'padding:6px 14px;background:transparent;color:var(--ca-text3,#555);border:1px solid var(--ca-border2,#333);border-radius:6px;font-size:12px;cursor:pointer';
            cancelBtn.onclick = function() { form.remove(); };
            btns.appendChild(cancelBtn);
            form.appendChild(btns);

            msgs.appendChild(form);
            msgs.scrollTop = msgs.scrollHeight;
        };

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
