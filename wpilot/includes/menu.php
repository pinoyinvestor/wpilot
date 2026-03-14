<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Register menu ──────────────────────────────────────────────
add_action( 'admin_menu', function () {
    $svg = 'data:image/svg+xml;base64,' . base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" stroke="rgba(255,255,255,.9)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    );
    add_menu_page( 'WPilot', 'WPilot', 'manage_options', CA_SLUG, 'wpilot_page_dashboard', $svg, 25 );
    $pages = [
        [ CA_SLUG,               'Dashboard',       'wpilot_page_dashboard'    ],
        [ CA_SLUG.'-chat',       'Chat',            'wpilot_page_chat'         ],
        [ CA_SLUG.'-analyze',    'Analyze Website', 'wpilot_page_analyze'      ],
        [ CA_SLUG.'-build',      'Build',           'wpilot_page_build'        ],
        [ CA_SLUG.'-plugins',    'Plugins',         'wpilot_page_plugins'      ],
        [ CA_SLUG.'-media',      'Media',           'wpilot_page_media'        ],
        [ CA_SLUG.'-instr',      'AI Instructions', 'wpilot_page_instructions' ],
        [ CA_SLUG.'-guide',      'Getting Started', 'wpilot_page_guide'        ],
        [ CA_SLUG.'-settings',   'Settings',        'wpilot_page_settings'     ],
        [ CA_SLUG.'-license',    'License',         'wpilot_page_license'      ],
        [ CA_SLUG.'-restore',    'Restore History', 'wpilot_page_restore'      ],
        [ CA_SLUG.'-brain',      'WPilot Brain',        'wpilot_page_brain'        ],
        [ CA_SLUG.'-activity',   'Activity Log',    'wpilot_page_activity'     ],
        [ CA_SLUG.'-scheduler',  'Scheduler',       'wpilot_page_scheduler'    ],
        [ CA_SLUG.'-sitebuilder','Site Builder',    'wpilot_page_sitebuilder'  ],
        [ CA_SLUG.'-training',   '🧠 AI Training',   'wpilot_page_training'     ],
    ];
    foreach ( $pages as [$slug,$title,$cb] ) {
        add_submenu_page( CA_SLUG, $title.' — WPilot', $title, 'manage_options', $slug, $cb );
    }
} );

// ── Page wrapper ───────────────────────────────────────────────
function wpilot_wrap( $title, $icon, $html, $narrow = false ) {
    $theme  = wpilot_theme();
    $conn   = wpilot_is_connected();
    $cls    = $narrow ? ' ca-narrow' : '';
    echo '<div class="ca-page'.$cls.'" data-theme="'.esc_attr($theme).'">';
    echo '<div class="ca-page-header">';
    echo '<span class="ca-page-icon">'.esc_html($icon).'</span>';
    echo '<h1>'.esc_html($title).'</h1>';
    if ( $conn ) {
        echo '<span class="ca-status-pill ca-status-ok">● Connected</span>';
    } else {
        echo '<span class="ca-status-pill ca-status-off">○ Not Connected — <a href="'.esc_url(admin_url('admin.php?page='.CA_SLUG.'-settings')).'">Connect →</a></span>';
    }
    echo '</div>';
    echo '<div class="ca-content">'.$html.'</div>';
    echo '</div>';
}

// wpilot_md_to_html is defined in helpers.php

// ══════════════════════════════════════════════════════════════
// DASHBOARD
// ══════════════════════════════════════════════════════════════
function wpilot_page_dashboard() {
    $used    = wpilot_prompts_used();
    $remain  = wpilot_prompts_remaining();
    $woo     = class_exists( 'WooCommerce' );
    $bld     = ucfirst( wpilot_detect_builder() );
    $conn    = wpilot_is_connected();
    $lic     = wpilot_is_licensed();
    $stats = [
        ['AI Prompts',  $lic ? '∞ Pro' : $used.' used',  $lic ? 'Unlimited — Pro license' : $remain.' of '.CA_FREE_LIMIT.' remaining'],
        ['Builder',     $bld,                             'AI adapts to your setup'],
        ['WooCommerce', $woo ? '✅ Active' : '—',         $woo ? 'Full WooCommerce AI' : 'Not installed'],
        ['License',     $lic  ? '🔓 Pro' : '🔒 Free',    $lic  ? 'Unlimited prompts' : 'Upgrade for unlimited'],
    ];
    $cards = [
        [CA_SLUG.'-chat',     '💬', 'Chat',            'Ask Claude anything about your site'],
        [CA_SLUG.'-analyze',  '🔍', 'Analyze Website', 'Full site intelligence & action plan'],
        [CA_SLUG.'-build',    '🏗️', 'Build',           'Create pages, menus & sections'],
        [CA_SLUG.'-plugins',  '🔌', 'Plugins',         'Audit, activate & deactivate plugins'],
        [CA_SLUG.'-media',    '🖼️', 'Media',           'Fix missing alt texts with AI'],
        [CA_SLUG.'-instr',    '📝', 'AI Instructions', 'Give Claude context about your site'],
        [CA_SLUG.'-guide',    '📖', 'Getting Started', 'Setup guide, credits & API info'],
        [CA_SLUG.'-settings', '⚙️', 'Settings',        'API key, theme & preferences'],
        [CA_SLUG.'-license',  '🔑', 'License',         'Plan, usage & subscription'],
        [CA_SLUG.'-restore',  '🔄', 'Restore History', 'Undo any AI change'],
            [CA_SLUG.'-brain',    '🧠', 'WPilot Brain',         'Your site-specific AI memory'],
    ];
    ob_start(); ?>

    <?php if ( ! $conn ): ?>
    <div class="ca-alert ca-alert-warn ca-onboard-banner">
        <span class="ca-alert-icon">⚡</span>
        <div>
            <strong>Connect Claude AI to get started</strong>
            <p>Paste your Claude API key to start designing and building your WordPress site with AI. Takes 2 minutes.</p>
        </div>
        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-settings') ) ?>" class="ca-btn ca-btn-primary">Connect Now →</a>
    </div>
    <?php elseif ( ! $lic && $used >= CA_FREE_LIMIT ): ?>
    <div class="ca-alert ca-alert-danger ca-onboard-banner">
        <span class="ca-alert-icon">🔒</span>
        <div>
            <strong>Free limit reached — upgrade to continue</strong>
            <p>You've used all <?= CA_FREE_LIMIT ?> free prompts. Activate a Pro license for unlimited access.</p>
        </div>
        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-license') ) ?>" class="ca-btn ca-btn-primary">Upgrade →</a>
    </div>
    <?php endif; ?>

    <div class="ca-stat-row">
        <?php
        $stats = [
            ['AI Prompts',  $lic ? '∞ Pro' : $used.' used',  $lic ? 'Unlimited — Pro license' : $remain.' of '.CA_FREE_LIMIT.' remaining'],
            ['Builder',     $bld,                             'AI adapts to your setup'],
            ['WooCommerce', $woo ? '✅ Active' : '—',         $woo ? 'Full WooCommerce AI' : 'Not installed'],
            ['License',     $lic  ? '🔓 Pro' : '🔒 Free',    $lic  ? 'Unlimited prompts' : 'Upgrade for unlimited'],
        ];
        foreach ( $stats as [$lbl,$val,$sub] ): ?>
        <div class="ca-stat-card">
            <div class="ca-sc-label"><?= esc_html($lbl) ?></div>
            <div class="ca-sc-val"><?= esc_html($val) ?></div>
            <div class="ca-sc-sub"><?= esc_html($sub) ?></div>
            <?php if ( $lbl === 'AI Prompts' && ! $lic ): ?>
            <div class="ca-sc-bar"><div class="ca-sc-bar-fill" style="width:<?= min(100,($used/CA_FREE_LIMIT)*100) ?>%"></div></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="ca-section-lbl" style="margin-bottom:10px">All Tools</div>
    <div class="ca-nav-grid">
        <?php
        $cards = [
            [CA_SLUG.'-chat',     '💬', 'Chat',            'Ask Claude anything about your site'],
            [CA_SLUG.'-analyze',  '🔍', 'Analyze Website', 'Full site intelligence & action plan'],
            [CA_SLUG.'-build',    '🏗️', 'Build',           'Create pages, menus & sections'],
            [CA_SLUG.'-plugins',  '🔌', 'Plugins',         'Audit, activate & deactivate plugins'],
            [CA_SLUG.'-media',    '🖼️', 'Media',           'Fix missing alt texts with AI'],
            [CA_SLUG.'-instr',    '📝', 'AI Instructions', 'Give Claude context about your site'],
            [CA_SLUG.'-guide',    '📖', 'Getting Started', 'Setup guide, credits & API info'],
            [CA_SLUG.'-settings', '⚙️', 'Settings',        'API key, theme & preferences'],
            [CA_SLUG.'-license',  '🔑', 'License',         'Plan, usage & subscription'],
            [CA_SLUG.'-restore',  '🔄', 'Restore History', 'Undo any AI change'],
            [CA_SLUG.'-brain',    '🧠', 'WPilot Brain',         'Your site-specific AI memory'],
        ];
        foreach ( $cards as [$slug,$icon,$title,$desc] ): ?>
        <a href="<?= esc_url( admin_url("admin.php?page={$slug}") ) ?>" class="ca-nav-card">
            <span class="ca-nc-icon"><?= $icon ?></span>
            <div>
                <div class="ca-nc-title"><?= esc_html($title) ?></div>
                <div class="ca-nc-desc"><?= esc_html($desc) ?></div>
            </div>
            <span class="ca-nc-arr">→</span>
        </a>
        <?php endforeach; ?>
    </div>


    <?php wpilot_wrap( 'WPilot', '⚡', ob_get_clean() );
}

// ══════════════════════════════════════════════════════════════
// CHAT
// ══════════════════════════════════════════════════════════════
function wpilot_page_chat() {
    $history = get_option( 'ca_chat_history', [] );
    $locked  = wpilot_is_locked();
    $conn    = wpilot_is_connected();
    ob_start(); ?>
    <div class="ca-chat-layout" data-page="chat">
        <aside class="ca-sidebar">
            <?php if ( ! wpilot_is_licensed() ): ?>
            <div class="ca-prompt-meter">
                <div class="ca-pm-label">Free Prompts</div>
                <div class="ca-pm-bar"><div class="ca-pm-fill" style="width:<?= min(100,(wpilot_prompts_used()/CA_FREE_LIMIT)*100) ?>%"></div></div>
                <div class="ca-pm-text"><?= wpilot_prompts_used() ?> / <?= CA_FREE_LIMIT ?> used</div>
                <?php if ($locked): ?>
                <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-license')) ?>" class="ca-btn ca-btn-primary ca-btn-sm ca-btn-block" style="margin-top:8px">Upgrade to Pro →</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="ca-sb-section">
                <div class="ca-sb-label">Mode</div>
                <div class="ca-mode-pills">
                    <?php foreach (['chat'=>'💬 General','analyze'=>'🔍 Analyze','build'=>'🏗️ Build','seo'=>'📈 SEO','woo'=>'🛒 WooCommerce'] as $m=>$lbl): ?>
                    <button class="ca-mode-pill <?= $m==='chat'?'active':'' ?>" data-mode="<?= $m ?>"><?= $lbl ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ca-sb-section">
                <div class="ca-sb-label">Quick prompts</div>
                <div class="ca-qp-list">
                    <?php foreach ([
                        '💡' => 'What should I improve on my site right now?',
                        '🔍' => 'Analyze my homepage and list the top 5 issues.',
                        '📈' => 'Audit my SEO and give me a priority list.',
                        '🔌' => 'Review my plugins — which should I remove?',
                        '🏗️' => 'Help me create a new services page.',
                        '🎨' => 'Suggest CSS improvements for a more premium look.',
                        '🛒' => 'Analyze my WooCommerce store.',
                        '🖼️' => 'Fix all missing image alt texts.',
                    ] as $ico=>$msg): ?>
                    <button class="ca-qp" data-msg="<?= esc_attr($msg) ?>"><?= $ico ?> <?= esc_html($msg) ?></button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="ca-sb-footer">
                <button id="caClearHist" class="ca-btn ca-btn-ghost ca-btn-sm">🗑️ Clear History</button>
                <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-restore')) ?>" class="ca-btn ca-btn-ghost ca-btn-sm">🔄 Restore</a>
            </div>
        </aside>

        <main class="ca-chat-main">
            <div class="ca-chat-mode-bar">
                <span id="caModeLbl">💬 General</span>
                <span class="ca-ctx-pill">📍 <?= esc_html(get_bloginfo('name')) ?></span>
            </div>
            <div class="ca-msgs" id="caMsgs">
                <?php if ( empty($history) ): ?>
                <div class="ca-welcome" id="caWelcome">
                    <div class="ca-wlc-av">⚡</div>
                    <h3>Ready to build <?= esc_html(get_bloginfo('name')) ?></h3>
                    <p>Your AI co-developer. Ask me to analyze, design, build or fix anything on your WordPress site.</p>
                    <div class="ca-wlc-chips">
                        <button class="ca-wlc-chip" data-msg="Analyze my site and list the top 5 things to improve.">🔍 Analyze site</button>
                        <button class="ca-wlc-chip" data-msg="Help me create a new premium services page.">🏗️ Build a page</button>
                        <button class="ca-wlc-chip" data-msg="Audit my SEO and show me what needs fixing.">📈 SEO audit</button>
                        <button class="ca-wlc-chip" data-msg="Review my plugins and identify anything I should remove.">🔌 Plugin audit</button>
                    </div>
                </div>
                <?php else: foreach ($history as $msg) wpilot_render_msg($msg); endif; ?>
                <div id="caTyping" style="display:none">
                    <div class="ca-msg ca-msg-ai">
                        <div class="ca-msg-av">⚡</div>
                        <div class="ca-msg-body"><div class="ca-typing"><span></span><span></span><span></span></div></div>
                    </div>
                </div>
            </div>
            <div class="ca-input-wrap">
                <?php if (!$conn): ?>
                <div class="ca-alert ca-alert-warn" style="margin:0 0 8px;padding:10px 14px">⚠️ Not connected. <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-settings')) ?>">Connect Claude →</a></div>
                <?php elseif ($locked): ?>
                <div class="ca-alert ca-alert-danger" style="margin:0 0 8px;padding:10px 14px">🔒 Limit reached. <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-license')) ?>">Activate license →</a></div>
                <?php endif; ?>
                <div class="ca-input-row">
                    <textarea id="caIn" placeholder="Ask Claude to build, design or fix something…" rows="1" <?= ($locked||!$conn)?'disabled':'' ?>></textarea>
                    <button id="caSend" class="ca-send-btn" <?= ($locked||!$conn)?'disabled':'' ?>>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </button>
                </div>
                <div class="ca-input-hint">Enter to send · Shift+Enter for new line · Alt+C for bubble</div>
            </div>
        </main>
    </div>
    <?php wpilot_wrap( 'Chat', '💬', ob_get_clean() );
}

function wpilot_render_msg( $msg ) {
    $role    = $msg['role']    ?? 'user';
    $content = $msg['content'] ?? '';
    $time    = $msg['time']    ?? '';
    $actions = $msg['actions'] ?? [];
    $clean   = preg_replace( '/\[ACTION:[^\]]+\]/', '', $content );
    ?>
    <div class="ca-msg ca-msg-<?= esc_attr($role) ?>">
        <?php if ($role==='assistant'): ?><div class="ca-msg-av">⚡</div><?php endif; ?>
        <div class="ca-msg-body">
            <div class="ca-msg-text"><?= wpilot_md_to_html($clean) ?></div>
            <?php if ($actions && current_user_can('manage_options')): wpilot_render_action_cards($actions); endif; ?>
            <?php if ($time): ?><div class="ca-msg-time"><?= esc_html($time) ?></div><?php endif; ?>
        </div>
    </div>
    <?php
}

function wpilot_render_action_cards( $actions ) {
    echo '<div class="ca-action-cards">';
    foreach ($actions as $a): ?>
    <div class="ca-ac">
        <div class="ca-ac-l">
            <span class="ca-ac-ico"><?= esc_html($a['icon']??'🔧') ?></span>
            <div>
                <div class="ca-ac-title"><?= esc_html($a['label']??'') ?></div>
                <div class="ca-ac-desc"><?= esc_html($a['description']??'') ?></div>
            </div>
        </div>
        <div class="ca-ac-btns">
            <button class="ca-btn ca-btn-primary ca-btn-xs ca-do-action"
                data-tool="<?= esc_attr($a['tool']??'') ?>"
                data-params='<?= esc_attr(wp_json_encode($a['params']??[])) ?>'>✅ Apply</button>
            <button class="ca-btn ca-btn-ghost ca-btn-xs ca-skip-action">Skip</button>
        </div>
    </div>
    <?php endforeach;
    echo '</div>';
}

// ══════════════════════════════════════════════════════════════
// ANALYZE
// ══════════════════════════════════════════════════════════════
function wpilot_page_analyze() {
    ob_start(); ?>
    <div class="ca-hero-box">
        <h2>🔍 Full Website Intelligence</h2>
        <p>Claude scans your entire site and returns a prioritized action plan. Choose your analysis scope below.</p>
        <div class="ca-scope-row" style="margin-top:16px">
            <?php foreach (['full'=>'🌐 Full Analysis','seo'=>'📈 SEO Only','plugins'=>'🔌 Plugins Only','build'=>'🏗️ Structure & Design'] as $v=>$l): ?>
            <label><input type="radio" name="caScope" value="<?= $v ?>" <?= $v==='full'?'checked':'' ?>> <?= esc_html($l) ?></label>
            <?php endforeach; ?>
            <?php if(class_exists('WooCommerce')): ?><label><input type="radio" name="caScope" value="woo"> 🛒 WooCommerce</label><?php endif; ?>
        </div>
        <div style="margin-top:16px">
            <button class="ca-btn ca-btn-primary ca-btn-lg" id="caRunAnalysis">🔍 Run Analysis</button>
        </div>
    </div>
    <div id="caAnalysisResult" class="ca-result-box" style="display:none"></div>

    <div class="ca-card">
        <h3>💡 How analysis works</h3>
        <p class="ca-card-sub">Claude reads your site's pages, posts, plugins, SEO data, images and structure — then gives you a prioritized list of what to fix first.</p>
        <div class="ca-feature-grid" style="margin-top:14px">
            <?php foreach ([
                ['📈','SEO Analysis','Checks titles, meta descriptions, heading structure and internal links'],
                ['🏗️','Structure & Design','Evaluates page layout, navigation and conversion flow'],
                ['🔌','Plugin Audit','Identifies conflicts, overlap and unused plugins'],
                ['🖼️','Media Check','Finds missing alt texts and oversized images'],
                ['🛒','WooCommerce','Reviews products, descriptions, pricing and checkout'],
                ['⚡','Action Plan','Ends with a prioritized list of what to fix first'],
            ] as [$ico,$t,$d]): ?>
            <div class="ca-feature-item">
                <div class="ca-fi-icon"><?= $ico ?></div>
                <div class="ca-fi-title"><?= esc_html($t) ?></div>
                <div class="ca-fi-desc"><?= esc_html($d) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php wpilot_wrap( 'Analyze Website', '🔍', ob_get_clean() );
}

// ══════════════════════════════════════════════════════════════
// BUILD
// ══════════════════════════════════════════════════════════════
function wpilot_page_build() {
    $builder = ucfirst(wpilot_detect_builder());
    ob_start(); ?>
    <div class="ca-alert ca-alert-info" style="margin-bottom:16px">
        <span class="ca-alert-icon">💡</span>
        <div>
            <strong>Builder detected: <?= esc_html($builder) ?></strong>
            Claude knows your page builder and will format output accordingly. It always asks before applying major changes.
        </div>
    </div>

    <div class="ca-two-col">
        <div class="ca-card">
            <h3>⚡ Quick Build Actions</h3>
            <p class="ca-card-sub">Click to ask Claude. It will plan and confirm before applying.</p>
            <div class="ca-action-grid">
                <?php foreach ([
                    ['🏠','Create Homepage',      'Help me create a premium homepage for my site.'],
                    ['📋','Services Page',         'Create a professional services page.'],
                    ['📞','Contact Page',          'Build a clean contact page with a form.'],
                    ['ℹ️','About Page',            'Create an about page that builds trust.'],
                    ['🛒','Optimize Shop',         'Analyze and improve my WooCommerce shop.'],
                    ['🗺️','Build Navigation',      'Create or improve my main navigation menu.'],
                    ['🦶','Improve Footer',        'Redesign my footer for a more premium look.'],
                    ['🎨','Improve CSS/Design',    'Suggest and apply CSS improvements site-wide.'],
                ] as [$ico,$t,$msg]): ?>
                <button class="ca-ab-btn" data-msg="<?= esc_attr($msg) ?>"><span><?= $ico ?></span><span><?= esc_html($t) ?></span></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ca-card">
            <h3>✏️ Custom Build Request</h3>
            <p class="ca-card-sub">Describe what you want. Claude will clarify if needed before doing anything.</p>
            <textarea id="caBuildInput" class="ca-big-ta" placeholder="e.g. Create a premium hero section with a bold CTA and my logo…"></textarea>
            <div class="ca-build-opts">
                <label><input type="checkbox" id="caBuildAsk" checked> Ask before applying</label>
            </div>
            <button class="ca-btn ca-btn-primary" id="caBuildGo" style="margin-top:8px">⚡ Ask Claude</button>
        </div>
    </div>
    <div id="caBuildResult" class="ca-result-box" style="display:none"></div>

    <div class="ca-card">
        <h3>🛡️ Safety — Claude always asks first</h3>
        <p class="ca-card-sub">For any significant change, Claude explains the plan and asks for your confirmation before touching the site. All changes are logged in <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-restore')) ?>">Restore History</a> so you can undo them.</p>
    </div>
    <?php wpilot_wrap( 'Build', '🏗️', ob_get_clean() );
}

// ══════════════════════════════════════════════════════════════
// PLUGINS
// ══════════════════════════════════════════════════════════════
function wpilot_page_plugins() {
    if (!function_exists('get_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
    $all    = get_plugins();
    $active = get_option('active_plugins',[]);
    ob_start(); ?>
    <div class="ca-hero-box">
        <h2>🔌 Plugin Manager</h2>
        <p>Claude can audit all your plugins for conflicts, overlap and security issues. View and manage all installed plugins below.</p>
        <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="ca-btn ca-btn-primary" id="caPluginAudit">⚡ AI Plugin Audit</button>
            <span class="ca-pill ca-pill-blue"><?= count($active) ?> active</span>
            <span class="ca-pill ca-pill-gray"><?= count($all) - count($active) ?> inactive</span>
        </div>
    </div>
    <div id="caPluginAuditResult" class="ca-result-box" style="display:none"></div>
    <div class="ca-table-wrap">
        <div class="ca-table-head ca-plugins-cols"><span>Plugin</span><span>Version</span><span>Status</span><span>Actions</span></div>
        <div class="ca-table-body">
            <?php foreach ($all as $file=>$p):
                $on = in_array($file,$active); ?>
            <div class="ca-table-row ca-plugins-cols">
                <span><strong><?= esc_html($p['Name']) ?></strong><small><?= esc_html($p['AuthorName']??'') ?></small></span>
                <span><?= esc_html($p['Version']) ?></span>
                <span><span class="ca-dot-badge <?= $on?'ca-dot-on':'ca-dot-off' ?>"><?= $on?'● Active':'○ Inactive' ?></span></span>
                <span class="ca-row-acts">
                    <?php if ($on): ?>
                    <button class="ca-btn ca-btn-xs ca-btn-ghost ca-plugin-deact" data-file="<?= esc_attr($file) ?>">Deactivate</button>
                    <?php else: ?>
                    <button class="ca-btn ca-btn-xs ca-btn-danger ca-plugin-del" data-file="<?= esc_attr($file) ?>">Delete</button>
                    <?php endif; ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php wpilot_wrap( 'Plugins', '🔌', ob_get_clean() );
}

// ══════════════════════════════════════════════════════════════
// MEDIA
// ══════════════════════════════════════════════════════════════
function wpilot_page_media() {
    $images  = get_posts(['post_type'=>'attachment','post_mime_type'=>'image','numberposts'=>80]);
    $missing = count(array_filter($images, fn($i)=>empty(get_post_meta($i->ID,'_wp_attachment_image_alt',true))));
    ob_start(); ?>
    <div class="ca-hero-box">
        <h2>🖼️ Media & Alt Texts</h2>
        <p>Missing alt texts hurt your SEO and accessibility. Claude can generate descriptive alt texts for all images automatically.</p>
        <div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <button class="ca-btn ca-btn-primary" id="caFixAllAlts">⚡ Fix All Missing Alt Texts</button>
            <button class="ca-btn ca-btn-ghost" id="caMediaAudit">🔍 AI Media Audit</button>
            <?php if($missing): ?>
            <span class="ca-pill ca-pill-warn">⚠️ <?= $missing ?> missing</span>
            <?php else: ?>
            <span class="ca-pill ca-pill-green">✅ All alt texts set</span>
            <?php endif; ?>
            <span class="ca-pill ca-pill-gray"><?= count($images) ?> images total</span>
        </div>
    </div>
    <div id="caMediaAuditResult" class="ca-result-box" style="display:none"></div>
    <div class="ca-img-grid" id="caImgGrid">
        <?php foreach ($images as $img):
            $alt  = get_post_meta($img->ID,'_wp_attachment_image_alt',true);
            $url  = wp_get_attachment_image_url($img->ID,'thumbnail');
            $miss = empty(trim((string)$alt)); ?>
        <div class="ca-img-card <?= $miss?'ca-img-missing':'' ?>">
            <div class="ca-img-thumb">
                <?php if($url): ?><img src="<?= esc_url($url) ?>" loading="lazy" alt=""><?php else: ?><span class="ca-img-ph">🖼️</span><?php endif; ?>
                <?php if($miss): ?><span class="ca-miss-dot">!</span><?php endif; ?>
            </div>
            <div class="ca-img-info">
                <div class="ca-img-name"><?= esc_html(mb_substr($img->post_title,0,18)) ?></div>
                <input class="ca-img-alt" type="text" placeholder="<?= $miss?'⚠️ Missing':'Alt text…' ?>" value="<?= esc_attr($alt) ?>" data-id="<?= $img->ID ?>">
                <div class="ca-img-acts">
                    <button class="ca-btn ca-btn-xs ca-btn-success ca-save-alt" data-id="<?= $img->ID ?>">Save</button>
                    <button class="ca-btn ca-btn-xs ca-btn-ghost ca-ai-alt" data-id="<?= $img->ID ?>" data-name="<?= esc_attr($img->post_title) ?>">⚡ AI</button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php wpilot_wrap( 'Media', '🖼️', ob_get_clean() );
}

// ══════════════════════════════════════════════════════════════
// AI INSTRUCTIONS
// ══════════════════════════════════════════════════════════════
function wpilot_page_instructions() {
    $instr = get_option('ca_custom_instructions','');
    ob_start(); ?>
    <div class="ca-hero-box">
        <h2>📝 Custom AI Instructions</h2>
        <p>Give Claude extra context about your site. This text is included in every conversation so Claude always understands your business, style and goals.</p>
    </div>

    <div class="ca-two-col" style="margin-bottom:16px">
        <?php foreach ([
            ['🏪','Business type','Tell Claude what kind of business you run and who your customers are'],
            ['🎨','Design preferences','Minimal? Bold? Colors? Typography preferences?'],
            ['🎯','Main goal','Bookings, sales, leads, informational? What drives your site?'],
            ['🌍','Language','Should Claude respond in Swedish, English or another language?'],
        ] as [$ico,$t,$d]): ?>
        <div class="ca-feature-item">
            <div class="ca-fi-icon"><?= $ico ?></div>
            <div class="ca-fi-title"><?= esc_html($t) ?></div>
            <div class="ca-fi-desc"><?= esc_html($d) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="ca-card">
        <label class="ca-field-label">Your Instructions</label>
        <textarea id="caCustomInstr" class="ca-big-ta" style="min-height:180px" placeholder="Example: My site is a premium barber shop in Stockholm targeting men 25–45. I prefer minimal dark design. Main goal is booking appointments. Always respond in Swedish."><?= esc_textarea($instr) ?></textarea>
        <div class="ca-info-box" style="margin-top:12px">
            <strong>Tip:</strong> The more specific you are, the better Claude performs. Include your industry, target audience, design style, tone of voice and main business goal.
        </div>
        <div class="ca-card-footer">
            <button class="ca-btn ca-btn-primary" id="caSaveInstr">💾 Save Instructions</button>
            <span id="caInstrFeedback" class="ca-inline-fb" style="display:none"></span>
        </div>
    </div>
    <?php wpilot_wrap( 'AI Instructions', '📝', ob_get_clean(), true );
}

// ══════════════════════════════════════════════════════════════
// GETTING STARTED GUIDE
// ══════════════════════════════════════════════════════════════
function wpilot_page_guide() {
    $conn = wpilot_is_connected();
    ob_start(); ?>

    <!-- Hero -->
    <div class="ca-hero-box" style="margin-bottom:28px">
        <h2>🚀 Kom igång med WPilot</h2>
        <p style="font-size:14px;color:var(--ca-text2);margin:8px 0 0">WPilot kopplar din WordPress-sajt till Claude AI. Du använder din <strong>egen API-nyckel</strong> från Anthropic — vi ger dig verktygen. Följ guiden nedan så är du igång på 5 minuter.</p>
        <?php if ($conn): ?>
        <div style="margin-top:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span style="background:rgba(16,185,129,.15);color:#6ee7b7;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700">✅ Claude är kopplat — du är redo!</span>
            <a href="<?= esc_url(admin_url("admin.php?page=".CA_SLUG."-chat")) ?>" class="ca-btn ca-btn-primary ca-btn-sm">Öppna chatten →</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Step-by-step -->
    <div class="ca-section-lbl" style="margin-bottom:16px">Steg-för-steg — 4 enkla steg</div>

    <?php
    $step_data = [
        [
            "num"   => "1",
            "icon"  => "🌐",
            "title" => "Skapa ett konto på Anthropic",
            "time"  => "2 min",
            "url"   => "https://console.anthropic.com",
            "link"  => "Gå till console.anthropic.com →",
            "done"  => false,
            "desc"  => "Anthropic är företaget bakom Claude AI — samma AI som driver WPilot. Du behöver ett konto på deras plattform för att få en API-nyckel.",
            "note"  => "Viktigt: Har du redan ett claude.ai-konto (den vanliga chatten)? Det räcker INTE. API-nycklar är ett separat system på console.anthropic.com.",
            "steps" => [
                "Öppna console.anthropic.com i en ny flik",
                "Klicka på \"Sign Up\" uppe till höger",
                "Fyll i e-post och lösenord",
                "Verifiera din e-postadress",
                "Logga in på konsolen",
            ],
        ],
        [
            "num"   => "2",
            "icon"  => "🔑",
            "title" => "Skapa din API-nyckel",
            "time"  => "1 min",
            "url"   => "https://console.anthropic.com/settings/keys",
            "link"  => "Gå till API Keys →",
            "done"  => false,
            "desc"  => "API-nyckeln är det som låter WPilot prata med Claude. Den är unik för dig och ska aldrig delas med någon.",
            "note"  => "Nyckeln visas bara EN gång. Kopiera den direkt och spara den någonstans säkert — om du missar det måste du skapa en ny.",
            "steps" => [
                "I Anthropic Console — klicka på \"API Keys\" i vänstermenyn",
                "Klicka på den orange knappen \"Create Key\"",
                "Ge nyckeln ett namn, t.ex. \"WPilot – Min Sajt\"",
                "Klicka \"Create Key\"",
                "Kopiera nyckeln (börjar med sk-ant-api03-...)",
                "Spara den på ett säkert ställe",
            ],
        ],
        [
            "num"   => "3",
            "icon"  => "💳",
            "title" => "Lägg till betalning hos Anthropic",
            "time"  => "2 min",
            "url"   => "https://console.anthropic.com/settings/billing",
            "link"  => "Gå till Billing →",
            "done"  => false,
            "desc"  => "Claude API är betala-per-användning. Du betalar direkt till Anthropic baserat på hur mycket du använder — inte en fast månadsavgift.",
            "note"  => "Börja med $5. Det räcker i månader för normal användning. En vanlig AI-konversation kostar ungefär 0,05–0,50 kr.",
            "steps" => [
                "Klicka på \"Billing\" i vänstermenyn",
                "Klicka \"Add payment method\"",
                "Fyll i kortuppgifter",
                "Klicka \"Add credits\" och välj $5 eller $10",
                "Tips: Sätt ett månatligt spending limit på $10 så det aldrig spårar iväg",
            ],
            "costs" => [
                ["Snabb fråga / fix",         "~0,05 kr"],
                ["Normal konversation",       "0,10–0,50 kr"],
                ["Full sajt-analys",          "0,50–1,50 kr"],
                ["Intensiv build-session",    "5–20 kr"],
            ],
        ],
        [
            "num"   => "4",
            "icon"  => "⚡",
            "title" => "Klistra in nyckeln i WPilot",
            "time"  => "30 sek",
            "url"   => admin_url("admin.php?page=".CA_SLUG."-settings"),
            "link"  => "Öppna Inställningar →",
            "done"  => $conn,
            "desc"  => "Nu kopplar du ihop WPilot med din API-nyckel. Nyckeln lagras bara på din server — den skickas aldrig till oss på Weblease.",
            "steps" => [
                "Klicka på \"Inställningar\" i WPilot-menyn till vänster",
                "Klistra in din API-nyckel i fältet \"Claude API Key\"",
                "Klicka \"Test & Save\"",
                "Du ser en grön bekräftelse: \"✅ Connected\"",
                "Klar! Öppna chatten och börja bygga.",
            ],
        ],
    ];

    foreach ($step_data as $i => $s):
        $done = $s["done"] || ($i < 3 && $conn);
    ?>
    <div class="ca-guide-step-v2 <?= $done ? "done" : "" ?>" style="margin-bottom:16px">
        <div style="display:flex;gap:16px;align-items:flex-start">

            <!-- Step number -->
            <div style="flex-shrink:0;width:40px;height:40px;border-radius:50%;background:<?= $done ? "#10b981" : "var(--ca-accent)" ?>;display:flex;align-items:center;justify-content:center;font-size:<?= $done ? "18px" : "16px" ?>;font-weight:900;color:#fff">
                <?= $done ? "✓" : $s["num"] ?>
            </div>

            <!-- Content -->
            <div style="flex:1;min-width:0">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px">
                    <span style="font-size:16px;font-weight:800;color:var(--ca-text)"><?= $s["icon"] ?> <?= esc_html($s["title"]) ?></span>
                    <span style="font-size:11px;color:var(--ca-text3);background:var(--ca-bg);padding:2px 8px;border-radius:4px">⏱ <?= $s["time"] ?></span>
                    <?php if($done): ?>
                    <span style="font-size:11px;color:#6ee7b7;background:rgba(16,185,129,.12);padding:2px 8px;border-radius:4px">✅ Klar</span>
                    <?php endif; ?>
                </div>

                <p style="font-size:13px;color:var(--ca-text2);margin:0 0 10px;line-height:1.6"><?= esc_html($s["desc"]) ?></p>

                <!-- Substeps -->
                <div style="background:var(--ca-bg);border-radius:8px;padding:14px 16px;margin-bottom:10px">
                    <div style="font-size:11px;font-weight:700;color:var(--ca-text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Så här gör du:</div>
                    <?php foreach ($s["steps"] as $si => $step): ?>
                    <div style="display:flex;gap:10px;padding:4px 0;font-size:13px;color:var(--ca-text2)">
                        <span style="flex-shrink:0;width:18px;height:18px;background:var(--ca-accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff;margin-top:1px"><?= $si+1 ?></span>
                        <span><?= esc_html($step) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Cost table (step 3 only) -->
                <?php if (!empty($s["costs"])): ?>
                <div style="background:var(--ca-bg);border-radius:8px;padding:14px 16px;margin-bottom:10px">
                    <div style="font-size:11px;font-weight:700;color:var(--ca-text3);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Vad kostar det?</div>
                    <?php foreach ($s["costs"] as [$what,$cost]): ?>
                    <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:13px;color:var(--ca-text2);border-bottom:1px solid var(--ca-border)">
                        <span><?= esc_html($what) ?></span>
                        <strong style="color:var(--ca-text)"><?= esc_html($cost) ?></strong>
                    </div>
                    <?php endforeach; ?>
                    <div style="font-size:12px;color:var(--ca-text3);margin-top:8px">💡 $5 räcker i månader för normal användning. Börja alltid med $5.</div>
                </div>
                <?php endif; ?>

                <!-- Warning note -->
                <?php if (!empty($s["note"])): ?>
                <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.25);border-radius:8px;padding:10px 14px;font-size:12.5px;color:#fcd34d;line-height:1.6;margin-bottom:10px">
                    ⚠️ <?= esc_html($s["note"]) ?>
                </div>
                <?php endif; ?>

                <!-- CTA button -->
                <?php if (!$done): ?>
                <a href="<?= esc_url($s["url"]) ?>" target="<?= ( strpos($s["url"], "http") === 0 ) ? "_blank" : "_self" ?>"
                   class="ca-btn ca-btn-primary ca-btn-sm" rel="noopener">
                    <?= esc_html($s["link"]) ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div style="margin-bottom:28px"></div>    <!-- API Credits guide -->
    <div class="ca-section-lbl">Understanding API Credits</div>
    <div class="ca-card" style="margin-bottom:16px">
        <h3>💳 What are API credits and how much do I need?</h3>
        <p class="ca-card-sub">API credits are how Anthropic charges for Claude usage. You pay per message — not a flat monthly fee for the API.</p>
        <div class="ca-warn-box" style="margin-bottom:14px">
            <strong>Important:</strong> A claude.ai subscription (Pro, Max etc.) does NOT give API access. These are two completely separate products from Anthropic. You need credits on console.anthropic.com.
        </div>
        <div class="ca-two-col">
            <div>
                <div class="ca-section-lbl">Typical costs</div>
                <?php foreach ([
                    ['Quick question / fix',   '$0.005',  '~0.05 kr'],
                    ['Normal conversation',    '$0.01–0.05', '0.10–0.50 kr'],
                    ['Full site analysis',     '$0.05–0.15', '0.50–1.50 kr'],
                    ['Intensive build session','$0.50–2.00', '5–20 kr'],
                ] as [$what,$usd,$sek]): ?>
                <div class="ca-info-row">
                    <span><?= esc_html($what) ?></span>
                    <strong><?= esc_html($usd) ?> (<?= esc_html($sek) ?>)</strong>
                </div>
                <?php endforeach; ?>
            </div>
            <div>
                <div class="ca-section-lbl">Recommended starting amounts</div>
                <?php foreach ([
                    ['$5 (~55 kr)',  'Casual use — lasts months for occasional sessions'],
                    ['$10 (~110 kr)','Regular use — lasts a month of active building'],
                    ['$25 (~270 kr)','Heavy use — intensive daily development sessions'],
                ] as [$amt,$desc]): ?>
                <div class="ca-info-row">
                    <strong><?= esc_html($amt) ?></strong>
                    <span style="text-align:right;max-width:180px"><?= esc_html($desc) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ca-info-box" style="margin-top:14px">
            <strong>Tip:</strong> Set a monthly spending limit in <a href="https://console.anthropic.com/settings/billing" target="_blank" rel="noopener">Anthropic billing</a> so it never goes over what you want. You'll get an email warning before you run out.
        </div>
    </div>

    <!-- Features overview -->
    <div class="ca-section-lbl">What you can do with WPilot</div>
    <div class="ca-feature-grid" style="margin-bottom:24px">
        <?php foreach ([
            ['💬','Chat','Ask Claude anything about your site — it knows your pages, plugins, SEO data and builder'],
            ['🔍','Analyze','Get a full site analysis with prioritized fixes for SEO, design, plugins and content'],
            ['🏗️','Build','Create pages, sections, menus and content — Claude adapts to your page builder'],
            ['📈','SEO','Audit and fix title tags, meta descriptions, heading structure and alt texts'],
            ['🔌','Plugins','Identify conflicts, overlap and unused plugins — manage them from one place'],
            ['🖼️','Media','Auto-generate alt texts for all images — improves both SEO and accessibility'],
            ['🛒','WooCommerce','Improve products, categories, pricing and checkout with AI assistance'],
            ['↩️','Restore','Every AI change is backed up — undo anything with one click'],
        ] as [$ico,$t,$d]): ?>
        <div class="ca-feature-item">
            <div class="ca-fi-icon"><?= $ico ?></div>
            <div class="ca-fi-title"><?= esc_html($t) ?></div>
            <div class="ca-fi-desc"><?= esc_html($d) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Legal -->
    <div class="ca-section-lbl">Legal & transparency</div>
    <div class="ca-legal-box">
        <div class="ca-legal-row"><span class="ca-legal-ico">⚡</span><div><strong>What is WPilot?</strong> A WordPress plugin by Weblease that connects your site to Claude AI — letting you design, build and improve your site through a chat interface.</div></div>
        <div class="ca-legal-row"><span class="ca-legal-ico">🤖</span><div><strong>Powered by Claude AI</strong> — Claude is made by Anthropic. WPilot is the plugin; Anthropic provides the AI. We are not affiliated with or endorsed by Anthropic.</div></div>
        <div class="ca-legal-row"><span class="ca-legal-ico">🔑</span><div><strong>Your own API key</strong> — All AI responses come from your own Anthropic account. Your key is stored on your server only. Weblease never sees your conversations.</div></div>
        <div class="ca-legal-row"><span class="ca-legal-ico">👁️</span><div><strong>Review before applying</strong> — Claude always explains what it plans to do and asks for confirmation before major changes. You are always in control.</div></div>
        <div class="ca-legal-row"><span class="ca-legal-ico">📋</span><div><strong>Anthropic's policies apply</strong> — Since this plugin uses the Claude API, Anthropic's usage rules apply to your account. <a href="https://www.anthropic.com/legal/usage-policy" target="_blank" rel="noopener">Anthropic Usage Policy →</a> · <a href="https://www.anthropic.com/legal/privacy" target="_blank" rel="noopener">Privacy Policy →</a></div></div>
        <div class="ca-legal-row"><span class="ca-legal-ico">⚠️</span><div><strong>No warranty</strong> — This software is provided "as is" without warranties. The developer is not liable for damages resulting from AI-generated content. Always use Restore History to undo unwanted changes.</div></div>
    </div>

    <?php wpilot_wrap( 'Getting Started', '📖', ob_get_clean(), true );
}

// ══════════════════════════════════════════════════════════════
// SETTINGS
// ══════════════════════════════════════════════════════════════
function wpilot_page_settings() {
    $key    = get_option('ca_api_key','');
    $masked = $key ? substr($key,0,12).'••••'.substr($key,-4) : '';
    $conn   = wpilot_is_connected();
    ob_start(); ?>

    <?php if ( ! $conn ): ?>
    <!-- BIG credits notice - shown until connected -->
    <div style="background:rgba(245,158,11,.08);border:2px solid rgba(245,158,11,.35);border-radius:14px;padding:22px 24px;margin-bottom:20px">
        <div style="display:flex;align-items:flex-start;gap:14px">
            <span style="font-size:28px;flex-shrink:0">⚠️</span>
            <div>
                <div style="font-family:var(--ca-head);font-size:16px;font-weight:800;color:#fbbf24;margin-bottom:8px">
                    <?php wpilot_te('credits_warning_title') ?>
                </div>
                <p style="font-size:13.5px;color:#fde68a;line-height:1.7;margin-bottom:14px">
                    <?php wpilot_te('credits_warning_body') ?>
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
                    <div style="background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.3);border-radius:9px;padding:13px 15px">
                        <div style="font-size:12px;font-weight:800;color:#f87171;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em"><?php wpilot_te('does_not_work') ?></div>
                        <div style="font-size:13px;color:#fca5a5;line-height:1.7"><?= nl2br( esc_html( wpilot_t('does_not_work_list') ) ) ?></div>
                    </div>
                    <div style="background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.3);border-radius:9px;padding:13px 15px">
                        <div style="font-size:12px;font-weight:800;color:#34d399;margin-bottom:5px;text-transform:uppercase;letter-spacing:.05em"><?php wpilot_te('required_label') ?></div>
                        <div style="font-size:13px;color:#6ee7b7;line-height:1.7"><?= nl2br( esc_html( wpilot_t('required_list') ) ) ?></div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <a href="https://console.anthropic.com" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:linear-gradient(135deg,#4F80F7,#7C3AED);color:#fff;font-weight:800;font-size:13.5px;border-radius:9px;text-decoration:none">
                        <?php wpilot_te('create_api_account') ?>
                    </a>
                    <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-guide')) ?>"
                       style="display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);color:#fde68a;font-weight:700;font-size:13px;border-radius:9px;text-decoration:none">
                        <?php wpilot_te('step_by_step_guide') ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="ca-card">
        <h3>🔌 <?php wpilot_te('connect_api_title') ?></h3>
        <p class="ca-card-sub">
            <?php wpilot_te('connect_api_sub') ?>
            <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com/settings/keys</a>.
        </p>
        <div class="ca-api-box <?= $conn?'ca-api-connected':'' ?>">
            <div class="ca-conn-row">
                <span class="ca-conn-dot"></span>
                <span id="caConnStatus"><?= $conn ? '● ' . wpilot_t('status_connected') . ' — ' . $masked : '○ ' . wpilot_t('status_not_connected') ?></span>
            </div>
            <div class="ca-api-input-row">
                <input type="password" id="caApiKey" placeholder="sk-ant-api03-…" value="<?= esc_attr($key) ?>" autocomplete="off">
                <button class="ca-btn ca-btn-primary" id="caTestConn"><?php wpilot_te('test_and_save') ?></button>
            </div>
            <div id="caApiResult" class="ca-inline-fb" style="display:none;margin-top:10px"></div>
            <div class="ca-api-note">
                <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener"><?php wpilot_te('get_api_key') ?></a> ·
                <a href="https://console.anthropic.com/settings/billing" target="_blank" rel="noopener"><?php wpilot_te('add_credits_btn') ?></a> ·
                <a href="<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-guide')) ?>"><?php wpilot_te('step_by_step_guide') ?></a>
            </div>
        </div>
    </div>

    <!-- Always-visible credits reminder -->
    <div style="background:var(--ca-bg3);border:1px solid var(--ca-border2);border-radius:var(--ca-r);padding:18px 20px;margin-bottom:16px">
        <div style="font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--ca-text3);margin-bottom:12px">
            <?php wpilot_te('credits_reminder_title') ?>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
            <?php foreach ([
                ['💳','credits_tip_1_title','credits_tip_1_body'],
                ['💰','credits_tip_2_title','credits_tip_2_body'],
                ['🚀','credits_tip_3_title','credits_tip_3_body'],
                ['🔒','credits_tip_4_title','credits_tip_4_body'],
            ] as [$ico,$t,$d]): ?>
            <div style="background:var(--ca-bg2);border:1px solid var(--ca-border);border-radius:9px;padding:13px 14px">
                <div style="font-size:18px;margin-bottom:7px"><?= $ico ?></div>
                <div style="font-size:12.5px;font-weight:700;color:var(--ca-text);margin-bottom:4px"><?php wpilot_te($t) ?></div>
                <div style="font-size:12px;color:var(--ca-text2);line-height:1.6"><?php wpilot_te($d) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="ca-card">
        <h3>🎛️ Preferences</h3>
        <div class="ca-pref-rows">
            <div class="ca-pref-row">
                <div>
                    <div class="ca-pref-label">UI Theme</div>
                    <div class="ca-pref-sub">Appearance of the WPilot admin interface</div>
                </div>
                <select id="caThemeSel" class="ca-select" style="width:auto">
                    <option value="dark"  <?= selected(wpilot_theme(),'dark', false) ?>>🌙 Dark</option>
                    <option value="light" <?= selected(wpilot_theme(),'light',false) ?>>☀️ Light</option>
                </select>
            </div>
            <div class="ca-pref-row">
                <div>
                    <div class="ca-pref-label">Auto-approve changes</div>
                    <div class="ca-pref-sub">Apply AI suggestions without manual confirmation (not recommended)</div>
                </div>
                <label class="ca-toggle">
                    <input type="checkbox" id="caAutoApprove" <?= checked(wpilot_auto_approve(),true,false) ?>>
                    <span class="ca-toggle-track"></span>
                </label>
            </div>
        </div>
        <button class="ca-btn ca-btn-primary" id="caSavePrefs">💾 Save Preferences</button>
        <span id="caPrefFb" class="ca-inline-fb" style="display:none;margin-left:10px"></span>
    </div>

    <div class="ca-card">
        <h3>ℹ️ Plugin Information</h3>
        <div class="ca-info-rows">
            <?php foreach ([
                ['Plugin','WPilot powered by Claude AI v'.CA_VERSION],
                ['Developer','Weblease — weblease.se'],
                ['AI Provider','Claude AI by Anthropic'],
                ['API Type','Direct — your key, your Anthropic account'],
                ['Builder detected', ucfirst(wpilot_detect_builder())],
                ['WooCommerce', class_exists('WooCommerce') ? '✅ Active' : '—'],
                ['SEO Plugin', wpilot_detect_seo_plugin()],
            ] as [$lbl,$val]): ?>
            <div class="ca-info-row"><span><?= esc_html($lbl) ?></span><strong><?= esc_html($val) ?></strong></div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php wpilot_wrap( 'Settings', '⚙️', ob_get_clean(), true );
}

// ══════════════════════════════════════════════════════════════
// LICENSE
// ══════════════════════════════════════════════════════════════
function wpilot_page_license() {
    $type      = wpilot_license_type();
    $key       = get_option('ca_license_key','');
    $used      = wpilot_prompts_used();
    $connected = wpilot_is_connected();
    $warning   = get_option('wpi_license_warning','');
    $slots     = wpilot_lifetime_slots_remaining();

    ob_start();

    // Warning banner
    if ($warning): ?>
    <div class="notice notice-warning" style="margin:0 0 16px;padding:10px 16px;border-radius:8px"><p>⚠️ <?= esc_html($warning) ?> — <a href="https://weblease.se/wpilot-account" target="_blank">Manage subscription →</a></p></div>
    <?php endif;

    // Current status badge
    switch ($type) {
        case 'lifetime': $badge = ['🏆 Lifetime Access', '#f59e0b', 'Obegränsat för alltid. Tack för ditt tidiga stöd!']; break;
        case 'agency':   $badge = ['⚡ Agency',           '#8b5cf6', 'Upp till 5 sajter, alla funktioner']; break;
        case 'pro':      $badge = ['✅ Pro',               '#10b981', 'Alla funktioner, obegränsat']; break;
        default:         $badge = ['🆓 Free',             '#6b7280', $used.' / '.CA_FREE_LIMIT.' gratis prompts använda']; break;
    }
    ?>

    <div class="ca-card" style="margin-bottom:24px;border-color:<?= $badge[1] ?>;padding:20px 24px">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div>
                <div style="font-size:20px;font-weight:900;color:<?= $badge[1] ?>;margin-bottom:4px"><?= $badge[0] ?></div>
                <div style="font-size:13px;color:var(--ca-text2)"><?= esc_html($badge[2]) ?></div>
            </div>
            <?php if($type === 'pro' || $type === 'agency'): ?>
            <a href="https://weblease.se/wpilot-account" target="_blank" class="ca-btn ca-btn-ghost ca-btn-sm">Manage subscription →</a>
            <?php endif; ?>
        </div>
        <?php if($type === 'free'): ?>
        <div style="margin-top:12px;background:var(--ca-bg);border-radius:6px;overflow:hidden;height:6px">
            <div style="height:100%;background:#4f80f7;width:<?= min(100,round($used/CA_FREE_LIMIT*100)) ?>%"></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if($type !== 'lifetime' && $type !== 'pro' && $type !== 'agency'): ?>

    <!-- Pricing cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:28px">

        <?php // Lifetime card — only if slots remain
        if($slots > 0): ?>
        <div class="ca-card" style="border-color:#f59e0b;position:relative;overflow:hidden">
            <div style="position:absolute;top:0;right:0;background:#f59e0b;color:#000;font-size:10px;font-weight:800;padding:3px 10px;border-radius:0 12px 0 8px">🔥 <?= $slots ?> kvar</div>
            <div style="font-size:22px;font-weight:900;color:#f59e0b;margin-bottom:2px">$149</div>
            <div style="font-size:12px;color:var(--ca-text3);margin-bottom:12px">engångsbetalning</div>
            <div style="font-size:15px;font-weight:800;margin-bottom:8px">🏆 Lifetime</div>
            <ul style="font-size:12.5px;color:var(--ca-text2);padding:0;margin:0 0 16px;list-style:none">
                <li style="padding:3px 0">✓ Betala en gång, äg för alltid</li>
                <li style="padding:3px 0">✓ Alla Pro-funktioner</li>
                <li style="padding:3px 0">✓ Alla framtida uppdateringar</li>
                <li style="padding:3px 0">✓ 1 sajt</li>
            </ul>
            <a href="https://weblease.se/wpilot-checkout?tier=lifetime" target="_blank"
               class="ca-btn ca-btn-primary" style="width:100%;text-align:center;background:#f59e0b;border-color:#f59e0b;color:#000">
                Köp Lifetime →
            </a>
        </div>
        <?php endif; ?>

        <!-- Pro card -->
        <div class="ca-card" style="border-color:#10b981;position:relative">
            <div style="position:absolute;top:0;right:0;background:#10b981;color:#000;font-size:10px;font-weight:800;padding:3px 10px;border-radius:0 12px 0 8px">POPULÄRAST</div>
            <div style="font-size:22px;font-weight:900;color:#10b981;margin-bottom:2px">$19<span style="font-size:13px;font-weight:400">/mån</span></div>
            <div style="font-size:12px;color:var(--ca-text3);margin-bottom:12px">7 dagar gratis</div>
            <div style="font-size:15px;font-weight:800;margin-bottom:8px">⚡ Pro</div>
            <ul style="font-size:12.5px;color:var(--ca-text2);padding:0;margin:0 0 16px;list-style:none">
                <li style="padding:3px 0">✓ Obegränsat antal prompts</li>
                <li style="padding:3px 0">✓ Plugin-konfiguration via AI</li>
                <li style="padding:3px 0">✓ Scheduler + Brain-minne</li>
                <li style="padding:3px 0">✓ 1 sajt</li>
            </ul>
            <a href="https://weblease.se/wpilot-checkout?tier=pro" target="_blank"
               class="ca-btn ca-btn-primary" style="width:100%;text-align:center">
                Starta gratis trial →
            </a>
        </div>

        <!-- Agency card -->
        <div class="ca-card" style="border-color:#8b5cf6">
            <div style="font-size:22px;font-weight:900;color:#8b5cf6;margin-bottom:2px">$49<span style="font-size:13px;font-weight:400">/mån</span></div>
            <div style="font-size:12px;color:var(--ca-text3);margin-bottom:12px">7 dagar gratis</div>
            <div style="font-size:15px;font-weight:800;margin-bottom:8px">🏢 Agency</div>
            <ul style="font-size:12.5px;color:var(--ca-text2);padding:0;margin:0 0 16px;list-style:none">
                <li style="padding:3px 0">✓ Upp till 5 sajter</li>
                <li style="padding:3px 0">✓ Alla Pro-funktioner</li>
                <li style="padding:3px 0">✓ White-label möjlighet</li>
                <li style="padding:3px 0">✓ Prioriterad support</li>
            </ul>
            <a href="https://weblease.se/wpilot-checkout?tier=agency" target="_blank"
               class="ca-btn" style="width:100%;text-align:center;background:#8b5cf6;border-color:#8b5cf6;color:#fff">
                Starta gratis trial →
            </a>
        </div>

    </div>
    <?php endif; ?>

    <!-- License key input (for Pro/Agency/Lifetime) -->
    <?php if($type !== 'lifetime'): ?>
    <div class="ca-card">
        <div class="ca-section-lbl">Aktivera med licensnyckel</div>
        <p style="font-size:13px;color:var(--ca-text2);margin:0 0 12px">
            Du får din nyckel via e-post efter köp. Format: CA-XXXX-XXXX-XXXX
        </p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <input type="text" id="wpiLicenseKey" placeholder="CA-XXXX-XXXX-XXXX"
                   value="<?= esc_attr($key) ?>"
                   style="flex:1;min-width:200px;background:var(--ca-bg);border:1px solid var(--ca-border);border-radius:8px;padding:10px 14px;color:var(--ca-text);font-size:13.5px;font-family:monospace">
            <button class="ca-btn ca-btn-primary" onclick="wpiActivateLicense()">Aktivera</button>
            <?php if($key): ?>
            <button class="ca-btn ca-btn-ghost ca-btn-sm" onclick="wpiDeactivateLicense()" style="color:#ef4444;border-color:#ef4444">Avaktivera</button>
            <?php endif; ?>
        </div>
        <div id="wpiLicenseMsg" style="margin-top:10px;font-size:13px"></div>
    </div>
    <?php endif; ?>

    <script>
    function wpiActivateLicense() {
        var key = document.getElementById('wpiLicenseKey').value.trim();
        var msg = document.getElementById('wpiLicenseMsg');
        if (!key) { msg.textContent = 'Ange din licensnyckel.'; return; }
        msg.textContent = 'Kontrollerar…';
        jQuery.post(ajaxurl, {action:'ca_activate_license',nonce:CA.nonce,license_key:key}, function(r) {
            if (r.success) { msg.style.color='#10b981'; msg.textContent='✅ '+r.data.message; setTimeout(()=>location.reload(),1500); }
            else { msg.style.color='#ef4444'; msg.textContent='❌ '+(r.data||'Ogiltig nyckel'); }
        });
    }
    function wpiDeactivateLicense() {
        if (!confirm('Avaktivera licensen på den här sajten?')) return;
        jQuery.post(ajaxurl, {action:'ca_deactivate_license',nonce:CA.nonce}, function(r) {
            if (r.success) location.reload();
        });
    }
    </script>
    <?php wpilot_wrap('License', '🔑', ob_get_clean());
}

function wpilot_page_restore() {
    ob_start(); ?>
    <div class="ca-hero-box">
        <h2>🔄 Restore History</h2>
        <p>Every change Claude makes is logged here automatically. Click Restore to undo any change — your original content is saved before each action.</p>
    </div>
    <div class="ca-mod-actions">
        <button class="ca-btn ca-btn-ghost" id="caLoadBackups">🔄 Refresh</button>
    </div>
    <div class="ca-table-wrap">
        <div class="ca-table-head ca-restore-cols"><span>ID</span><span>Action</span><span>Type</span><span>Date</span><span>Status</span><span>Restore</span></div>
        <div class="ca-table-body" id="caBackupRows">
            <div class="ca-tbl-loading">Loading backup history…</div>
        </div>
    </div>
    <?php wpilot_wrap( 'Restore History', '🔄', ob_get_clean() );
}

// ══════════════════════════════════════════════════════════════
// AI BRAIN PAGE
// ══════════════════════════════════════════════════════════════
function wpilot_page_brain() {
    $stats  = wpilot_brain_stats();
    $active = get_option('wpi_brain_active','yes') === 'yes';
    $total  = $stats['total'];
    $appr   = $stats['approved'];
    $pct    = $total > 0 ? round(($appr/$total)*100) : 0;
    ob_start(); ?>

    <!-- Header stats row -->
    <div class="ca-stat-row" style="margin-bottom:24px">
        <div class="ca-stat-card">
            <div class="ca-sc-label">Total Memories</div>
            <div class="ca-sc-val"><?= $total ?></div>
            <div class="ca-sc-sub">Stored in Brain database</div>
        </div>
        <div class="ca-stat-card">
            <div class="ca-sc-label">Approved</div>
            <div class="ca-sc-val" style="color:var(--ca-green)"><?= $appr ?></div>
            <div class="ca-sc-sub">High-confidence memories</div>
            <div class="ca-sc-bar"><div class="ca-sc-bar-fill" style="width:<?= $pct ?>%"></div></div>
        </div>
        <div class="ca-stat-card">
            <div class="ca-sc-label">Pending</div>
            <div class="ca-sc-val" style="color:var(--ca-warn)"><?= $stats['pending'] ?></div>
            <div class="ca-sc-sub">Learned but not approved</div>
        </div>
        <div class="ca-stat-card">
            <div class="ca-sc-label">Brain Status</div>
            <div class="ca-sc-val" style="color:<?= $active?'var(--ca-green)':'var(--ca-text3)' ?>"><?= $active ? 'Active' : 'Off' ?></div>
            <div class="ca-sc-sub">
                <label class="ca-toggle" style="margin-top:6px">
                    <input type="checkbox" id="aibBrainToggle" <?= $active?'checked':'' ?>>
                    <span class="ca-toggle-track"></span>
                </label>
            </div>
        </div>
    </div>

    <!-- Savings meter -->
    <?php
    $routes  = wpilot_route_stats();
    $shadow  = wpilot_shadow_stats();
    $shadow_active = get_option('wpi_shadow_active','yes') === 'yes';
    $savings = wpilot_estimate_savings();
    $cstats  = wpilot_collector_stats();
    ?>
    <div style="background:linear-gradient(135deg,rgba(16,185,129,.08),rgba(79,128,247,.06));border:1px solid rgba(16,185,129,.2);border-radius:14px;padding:24px 28px;margin-bottom:20px">
        <div style="font-family:var(--ca-head);font-size:17px;font-weight:800;margin-bottom:4px">
            🧠 WPilot AI is saving you money
        </div>
        <p style="font-size:13px;color:var(--ca-text2);margin-bottom:18px">
            Every question your Brain or WPilot AI answers is a Claude API call you didn't pay for. The more you use it, the cheaper it gets.
        </p>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px">
            <?php foreach([
                ['🧠 Local Brain', $routes['brain'], $routes['brain_pct'].'%', 'var(--ca-green)', 'Free — instant answer from memory'],
                ['⚡ WPilot AI',   $routes['webleas'], $routes['webleas_pct'].'%', 'var(--ca-accent)', '10× cheaper than Claude'],
                ['🤖 Claude',      $routes['claude'], $routes['claude_pct'].'%', 'var(--ca-text3)', 'Full power — for hard questions'],
            ] as [$label,$count,$pct,$color,$desc]): ?>
            <div style="background:var(--ca-bg2);border:1px solid var(--ca-border2);border-radius:10px;padding:14px 16px">
                <div style="font-size:13px;font-weight:700;color:<?=$color?>;margin-bottom:4px"><?=$label?></div>
                <div style="font-family:var(--ca-head);font-size:24px;font-weight:800"><?=$count?></div>
                <div style="font-size:12px;color:var(--ca-text3);margin-bottom:8px"><?=$pct?> of all requests</div>
                <div style="height:4px;background:var(--ca-bg4);border-radius:2px;overflow:hidden">
                    <div style="height:100%;width:<?=$pct?>;background:<?=$color?>;border-radius:2px"></div>
                </div>
                <div style="font-size:11px;color:var(--ca-text3);margin-top:5px"><?=$desc?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
            <div style="font-size:13px;color:var(--ca-text2)">
                💰 Estimated savings: <strong style="color:var(--ca-green);font-size:15px"><?=$savings['saved_sek']?> kr</strong>
                <span style="color:var(--ca-text3);font-size:12px">(<?=$savings['pct']?>% of calls handled without Claude)</span>
            </div>
            <div style="font-size:12px;color:var(--ca-text3);margin-left:auto">
                📡 Training data sent: <strong><?=$cstats['stats']['total']?> exchanges</strong>
                · Last: <?=$cstats['stats']['last'] ?: 'never'?>
            </div>
        </div>
    </div>

    <!-- How it works -->
    <div class="ca-hero-box" style="margin-bottom:20px">
        <h2>How the routing works</h2>
        <p>Three layers handle your questions in order. Over time the first two layers handle almost everything.</p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-top:16px">
            <?php foreach([
                ['💬','You ask something','Chat as usual — routing is invisible'],
                ['🧠','Brain checks first','Searches site-specific memories. Free.'],
                ['⚡','WPilot AI next','Trained WordPress AI. 10× cheaper than Claude.'],
                ['🤖','Claude as fallback','Only for new or complex questions'],
                ['📚','Everything is stored','Every Claude answer trains WPilot AI'],
                ['📈','Gets cheaper over time','Month 1: 100% Claude. Month 6: ~20%'],
            ] as [$i,$t,$d]): ?>
            <div style="background:var(--ca-bg3);border:1px solid var(--ca-border);border-radius:9px;padding:12px 14px">
                <div style="font-size:18px;margin-bottom:6px"><?=$i?></div>
                <div style="font-size:12.5px;font-weight:700;color:var(--ca-text);margin-bottom:3px"><?=esc_html($t)?></div>
                <div style="font-size:12px;color:var(--ca-text2)"><?=esc_html($d)?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Data collection consent -->
    <?php $consent = wpilot_has_consent(); ?>
    <div style="background:<?=$consent?'rgba(16,185,129,.06)':' rgba(245,158,11,.06)'?>;border:1px solid <?=$consent?'rgba(16,185,129,.2)':' rgba(245,158,11,.25)'?>;border-radius:12px;padding:18px 22px;margin-bottom:20px">
        <div style="display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap">
            <div style="flex:1;min-width:260px">
                <div style="font-size:14px;font-weight:800;margin-bottom:5px">
                    <?=$consent?'✅ Contributing to WPilot AI training':' ⚠️ Not contributing to WPilot AI training'?>
                </div>
                <div style="font-size:12.5px;color:var(--ca-text2);line-height:1.65">
                    <?php if($consent): ?>
                    Your anonymized WordPress Q&A pairs are being sent to improve WPilot AI. No personal data, URLs, or site content is sent — only the question and answer. This makes the AI cheaper for you and everyone over time.
                    <?php else: ?>
                    Opt in to help train WPilot AI. Only anonymized question/answer pairs about WordPress are sent — no personal data, no site content, no URLs. In return, you get cheaper AI responses over time as WPilot AI improves.
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-shrink:0">
                <label class="ca-toggle">
                    <input type="checkbox" id="aibConsentToggle" <?=$consent?'checked':'' ?>>
                    <span class="ca-toggle-track"></span>
                </label>
                <span id="aibConsentLbl" style="font-size:13px;font-weight:700;color:<?=$consent?'var(--ca-green)':' var(--ca-text3)'?>"><?=$consent?'On':' Off'?></span>
            </div>
        </div>
        <?php if($consent && $cstats['queued'] > 0): ?>
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--ca-border);display:flex;align-items:center;gap:10px;font-size:12px;color:var(--ca-text3)">
            <span>📬 <?=$cstats['queued']?> exchanges queued to send</span>
            <button class="ca-btn ca-btn-ghost ca-btn-sm" id="aibFlushNow">Send now</button>
            <span id="aibFlushFb" style="display:none"></span>
        </div>
        <?php endif; ?>
    </div>

    <div class="ca-two-col" style="margin-bottom:20px">

        <!-- Teach preferences -->
        <div class="ca-card">
            <h3>🎓 Teach a Preference</h3>
            <p class="ca-card-sub">Tell the Brain something about how you work. It will use this in every conversation.</p>
            <div style="margin-bottom:10px">
                <label class="ca-field-label">What (e.g. "design style")</label>
                <input type="text" id="aibPrefKey" placeholder="design style, language, font, brand color…" class="ca-input">
            </div>
            <div style="margin-bottom:12px">
                <label class="ca-field-label">Value (e.g. "minimal, dark, Swedish")</label>
                <input type="text" id="aibPrefVal" placeholder="minimal dark, Swedish, Inter font, #4F80F7…" class="ca-input">
            </div>
            <button class="ca-btn ca-btn-primary" id="aibSavePref">💾 Save Preference</button>
            <span id="aibPrefFb" class="ca-inline-fb" style="display:none;margin-left:10px"></span>

            <?php if(!empty($stats['prefs'])): ?>
            <div style="margin-top:16px;border-top:1px solid var(--ca-border);padding-top:14px">
                <div class="ca-section-lbl">Stored Preferences</div>
                <?php foreach($stats['prefs'] as $k=>$v): ?>
                <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--ca-border);font-size:12.5px">
                    <span style="color:var(--ca-text2)"><?=esc_html($k)?></span>
                    <strong><?=esc_html(is_array($v)?$v['value']:$v)?></strong>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Top memories -->
        <div class="ca-card">
            <h3>🏆 Most Used Memories</h3>
            <p class="ca-card-sub">These are the solutions your Brain reaches for most often.</p>
            <?php if(empty($stats['top'])): ?>
            <div style="text-align:center;padding:24px 0;color:var(--ca-text3);font-size:13px">No approved memories yet.<br>Chat with Claude and approve responses to start building your Brain.</div>
            <?php else: foreach($stats['top'] as $m): ?>
            <div style="padding:10px 0;border-bottom:1px solid var(--ca-border)">
                <div style="display:flex;justify-content:space-between;margin-bottom:3px">
                    <span class="ca-pill ca-pill-blue" style="font-size:10.5px"><?=esc_html($m->memory_type)?></span>
                    <span style="font-size:11px;color:var(--ca-text3)">used <?=$m->use_count?>×</span>
                </div>
                <div style="font-size:12.5px;color:var(--ca-text2);line-height:1.5"><?=esc_html(mb_substr($m->trigger_key,0,70))?></div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Memory table -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:10px">
        <div class="ca-section-lbl" style="margin:0">All Memories</div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select id="aibTypeFilter" class="ca-select" style="width:auto;font-size:12px">
                <option value="">All types</option>
                <?php foreach(['chat','css','seo','build','plugin','woo','media','preference'] as $t): ?>
                <option value="<?=$t?>"><?=$t?></option>
                <?php endforeach; ?>
            </select>
            <button class="ca-btn ca-btn-ghost ca-btn-sm" id="aibLoadMems">🔄 Refresh</button>
            <button class="ca-btn ca-btn-danger ca-btn-sm" id="aibResetBrain">🗑️ Reset Brain</button>
        </div>
    </div>

    <div class="ca-table-wrap">
        <div class="ca-table-head" style="grid-template-columns:90px 1fr 100px 80px 70px 90px">
            <span>Type</span><span>Question / Trigger</span><span>Confidence</span><span>Used</span><span>Status</span><span>Actions</span>
        </div>
        <div class="ca-table-body" id="aibMemTable">
            <div class="ca-tbl-loading">Loading memories…</div>
        </div>
    </div>

    <script>
    jQuery(function($){
        var nonce = (typeof CA !== 'undefined') ? CA.nonce : '';

        // Toggle Brain on/off
        $('#aibBrainToggle').on('change', function(){
            $.post(ajaxurl, {action:'wpi_toggle_brain',nonce:nonce,active:this.checked?'yes':'no'});
        });

        // Save preference
        $('#aibSavePref').on('click', function(){
            var k=$('#aibPrefKey').val().trim(), v=$('#aibPrefVal').val().trim();
            if(!k||!v) return;
            $.post(ajaxurl,{action:'wpi_learn_preference',nonce:nonce,pref_key:k,pref_value:v},function(r){
                var fb=$('#aibPrefFb');
                if(r.success){fb.text('✅ Saved').addClass('ca-fb-ok').show(); setTimeout(()=>location.reload(),800);}
                else{fb.text('❌ Error').addClass('ca-fb-err').show();}
            });
        });

        // Load memories
        function loadMems(){
            var type=$('#aibTypeFilter').val();
            $.post(ajaxurl,{action:'wpi_brain_list',nonce:nonce,type:type,offset:0},function(r){
                var $t=$('#aibMemTable');
                if(!r.success||!r.data.length){$t.html('<div class="ca-tbl-loading">No memories found.</div>');return;}
                var html='';
                $.each(r.data,function(i,m){
                    var conf=parseFloat(m.confidence)*100;
                    var confColor = conf>=80?'var(--ca-green)':conf>=60?'var(--ca-warn)':'var(--ca-danger)';
                    var status = m.approved=='1'
                        ? '<span class="ca-pill ca-pill-green" style="font-size:10px">✅ Approved</span>'
                        : '<span class="ca-pill ca-pill-warn" style="font-size:10px">⏳ Pending</span>';
                    var trigger = m.trigger_key.length > 60 ? m.trigger_key.substr(0,60)+'…' : m.trigger_key;
                    html += '<div class="ca-table-row" style="grid-template-columns:90px 1fr 100px 80px 70px 90px">'
                        +'<span><span class="ca-pill ca-pill-blue" style="font-size:10px">'+m.memory_type+'</span></span>'
                        +'<span style="font-size:12.5px;color:var(--ca-text2)" title="'+$('<div>').text(m.trigger_key).html()+'">'+$('<div>').text(trigger).html()+'</span>'
                        +'<span><div style="font-size:12px;font-weight:700;color:'+confColor+'">'+Math.round(conf)+'%</div><div style="height:3px;background:var(--ca-bg4);border-radius:2px;margin-top:3px"><div style="height:100%;width:'+Math.round(conf)+'%;background:'+confColor+';border-radius:2px"></div></div></span>'
                        +'<span style="font-size:12px;color:var(--ca-text3)">'+m.use_count+'×</span>'
                        +'<span>'+status+'</span>'
                        +'<span><button class="ca-btn ca-btn-xs ca-btn-danger aib-forget" data-id="'+m.id+'">🗑️</button>'
                        +(m.approved!='1' ? ' <button class="ca-btn ca-btn-xs ca-btn-success aib-approve-mem" data-id="'+m.id+'">✅</button>' : '')
                        +'</span>'
                        +'</div>';
                });
                $t.html(html);
            });
        }
        loadMems();
        $('#aibLoadMems, #aibTypeFilter').on('click change', loadMems);

        // Forget memory
        $(document).on('click','.aib-forget',function(){
            if(!confirm('Delete this memory?')) return;
            var id=$(this).data('id'); var $row=$(this).closest('.ca-table-row');
            $.post(ajaxurl,{action:'wpi_forget_memory',nonce:nonce,id:id},function(r){if(r.success)$row.remove();});
        });

        // Approve pending memory
        $(document).on('click','.aib-approve-mem',function(){
            var id=$(this).data('id'); var $btn=$(this);
            $.post(ajaxurl,{action:'wpi_approve_memory',nonce:nonce,memory_id:id},function(r){
                if(r.success){ $btn.closest('.ca-table-row').find('.aib-approve-mem').remove(); loadMems(); }
            });
        });

        // Consent toggle
        $('#aibConsentToggle').on('change', function(){
            var on = this.checked;
            $.post(ajaxurl,{action:'wpi_set_consent',nonce:nonce,consent:on?'yes':'no'});
            $('#aibConsentLbl').text(on?'On':'Off');
        });

        // Flush now
        $('#aibFlushNow').on('click', function(){
            var $btn=$(this);
            $btn.prop('disabled',true).text('Sending…');
            $.post(ajaxurl,{action:'wpi_flush_now',nonce:nonce},function(r){
                if(r.success){
                    $('#aibFlushFb').text('✅ Sent '+r.data.total+' total').show();
                } else {
                    $('#aibFlushFb').text('❌ Failed').show();
                }
                $btn.prop('disabled',false).text('Send now');
            });
        });

        // Reset Brain
        $('#aibResetBrain').on('click',function(){
            if(!confirm('Reset all Brain memories? This cannot be undone.')) return;
            $.post(ajaxurl,{action:'wpilot_brain_reset',nonce:nonce},function(r){
                if(r.success){ alert('Brain reset.'); location.reload(); }
            });
        });
    });
    </script>

    <?php wpilot_wrap('WPilot Brain','🧠', ob_get_clean());
}

// ══════════════════════════════════════════════════════════════
// ACTIVITY LOG PAGE
// ══════════════════════════════════════════════════════════════
function wpilot_page_activity() {
    $log = wpilot_get_activity_log(100);
    ob_start(); ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <div class="ca-section-lbl" style="margin:0">All AI Actions (<?= count($log) ?>)</div>
        <button class="ca-btn ca-btn-danger ca-btn-sm" id="aibClearLog">🗑️ Clear Log</button>
    </div>
    <?php if(empty($log)): ?>
    <div style="text-align:center;padding:48px 0;color:var(--ca-text3)">
        <div style="font-size:32px;margin-bottom:12px">📋</div>
        No activity yet. Actions will appear here once you start using WPilot.
    </div>
    <?php else: ?>
    <div class="ca-table-wrap">
        <div class="ca-table-head" style="grid-template-columns:150px 1fr 120px 90px 90px">
            <span>Time</span><span>Action</span><span>Detail</span><span>Status</span><span>Undo</span>
        </div>
        <div class="ca-table-body">
        <?php foreach($log as $entry): ?>
        <div class="ca-table-row" style="grid-template-columns:150px 1fr 120px 90px 90px">
            <span style="font-size:11.5px;color:var(--ca-text3)"><?= date('d M H:i', $entry['ts']) ?></span>
            <span style="font-size:13px;font-weight:600"><?= esc_html($entry['label']) ?></span>
            <span style="font-size:12px;color:var(--ca-text2)"><?= esc_html(mb_substr($entry['detail']??'',0,40)) ?></span>
            <span><?php
                $s = $entry['status'] ?? 'ok';
                $colors = ['ok'=>'var(--ca-green)','error'=>'var(--ca-danger)','restored'=>'var(--ca-accent)'];
                $labels = ['ok'=>'✅ OK','error'=>'❌ Error','restored'=>'↩️ Restored'];
                echo '<span style="font-size:11.5px;color:'.($colors[$s]??'var(--ca-text3)').'">'.($labels[$s]??$s).'</span>';
            ?></span>
            <span><?php if(!empty($entry['backup_id'])): ?>
            <button class="ca-btn ca-btn-xs cap-restore-btn" data-backup="<?= esc_attr($entry['backup_id']) ?>">↩️ Undo</button>
            <?php endif; ?></span>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <script>
    jQuery(function($){
        $('#aibClearLog').on('click',function(){
            if(!confirm('Clear all activity log?')) return;
            $.post(ajaxurl,{action:'wpilot_clear_activity_log',nonce:CA.nonce},function(r){
                if(r.success) location.reload();
            });
        });
        // Restore from log
        $(document).on('click','.cap-restore-btn',function(){
            var id=$(this).data('backup');
            if(!confirm('Restore to state before this action?')) return;
            var $btn=$(this).text('…').prop('disabled',true);
            $.post(ajaxurl,{action:'ca_restore_backup',nonce:CA.nonce,backup_id:id},function(r){
                $btn.text(r.success?'✅ Done':'❌ Failed');
            });
        });
    });
    </script>
    <?php wpilot_wrap('Activity Log','📋', ob_get_clean());
}

// ══════════════════════════════════════════════════════════════
// SCHEDULER PAGE
// ══════════════════════════════════════════════════════════════
function wpilot_page_scheduler() {
    $tasks = array_values(wpilot_get_tasks());
    ob_start(); ?>

    <div class="ca-hero-box" style="margin-bottom:20px">
        <h2>⏰ Scheduled AI Tasks</h2>
        <p>Set up recurring tasks that run automatically. WPilot will analyze your site on a schedule and email you the results.</p>
    </div>

    <!-- Create task -->
    <div class="ca-card" style="margin-bottom:20px">
        <h3>➕ Add Scheduled Task</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr 140px;gap:12px;margin-bottom:12px">
            <div>
                <label class="ca-field-label">Task type</label>
                <select id="aibTaskType" class="ca-select">
                    <option value="seo_report">📈 Weekly SEO Report</option>
                    <option value="content_check">📝 Content Quality Check</option>
                    <option value="security_check">🔒 Security Audit</option>
                    <option value="performance_check">⚡ Performance Check</option>
                    <option value="custom">✏️ Custom prompt</option>
                </select>
            </div>
            <div>
                <label class="ca-field-label">Schedule</label>
                <select id="aibTaskSchedule" class="ca-select">
                    <option value="daily">Daily</option>
                    <option value="weekly" selected>Weekly</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
            <div style="display:flex;align-items:flex-end">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;padding-bottom:8px">
                    <input type="checkbox" id="aibTaskEmail"> Email results
                </label>
            </div>
        </div>
        <div id="aibCustomPromptWrap" style="display:none;margin-bottom:12px">
            <label class="ca-field-label">Custom prompt</label>
            <textarea id="aibCustomPrompt" class="ca-input" rows="2" placeholder="E.g. Check if any WooCommerce products are missing descriptions…"></textarea>
        </div>
        <button class="ca-btn ca-btn-primary" id="aibCreateTask">⏰ Schedule Task</button>
        <span id="aibTaskFb" class="ca-inline-fb" style="display:none;margin-left:10px"></span>
    </div>

    <!-- Task list -->
    <?php if(empty($tasks)): ?>
    <div style="text-align:center;padding:32px 0;color:var(--ca-text3);font-size:13px">
        No scheduled tasks yet. Add one above.
    </div>
    <?php else: ?>
    <div class="ca-section-lbl">Active Tasks</div>
    <div style="display:flex;flex-direction:column;gap:12px;margin-top:12px" id="aibTaskList">
    <?php foreach($tasks as $task): ?>
    <div class="ca-card" style="padding:16px 20px" data-task-id="<?= esc_attr($task['id']) ?>">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
            <div>
                <div style="font-size:13.5px;font-weight:700"><?= esc_html(ucfirst(str_replace('_',' ',$task['type']))) ?></div>
                <div style="font-size:12px;color:var(--ca-text3);margin-top:3px">
                    <?= esc_html(ucfirst($task['schedule'])) ?> ·
                    Last run: <?= $task['last_run'] ? esc_html(date('d M H:i', strtotime($task['last_run']))) : 'Never' ?>
                </div>
            </div>
            <div style="display:flex;gap:8px">
                <button class="ca-btn ca-btn-ghost ca-btn-sm aib-run-now" data-id="<?= esc_attr($task['id']) ?>">▶ Run now</button>
                <button class="ca-btn ca-btn-danger ca-btn-sm aib-del-task" data-id="<?= esc_attr($task['id']) ?>">🗑️</button>
            </div>
        </div>
        <?php if(!empty($task['last_result']['text'])): ?>
        <div style="margin-top:12px;padding:12px 14px;background:var(--ca-bg3);border-radius:8px;font-size:12px;color:var(--ca-text2);line-height:1.6;max-height:120px;overflow:auto">
            <?= esc_html(mb_substr($task['last_result']['text'],0,500)) ?>…
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <script>
    jQuery(function($){
        var nonce = CA.nonce;
        $('#aibTaskType').on('change',function(){
            $('#aibCustomPromptWrap').toggle($(this).val()==='custom');
        });
        $('#aibCreateTask').on('click',function(){
            var type = $('#aibTaskType').val();
            var sched= $('#aibTaskSchedule').val();
            var email= $('#aibTaskEmail').is(':checked') ? 1 : 0;
            var prompt=$('#aibCustomPrompt').val().trim();
            if(type==='custom' && !prompt){ $('#aibTaskFb').text('Enter a custom prompt').show(); return; }
            $(this).prop('disabled',true).text('Scheduling…');
            $.post(ajaxurl,{action:'wpi_create_task',nonce:nonce,type:type,schedule:sched,email:email,prompt:prompt},function(r){
                if(r.success){ location.reload(); }
                else{ $('#aibTaskFb').text('Error: '+(r.data||'Failed')).show(); }
                $('#aibCreateTask').prop('disabled',false).text('⏰ Schedule Task');
            });
        });
        $(document).on('click','.aib-del-task',function(){
            if(!confirm('Delete this task?')) return;
            var id=$(this).data('id');
            $.post(ajaxurl,{action:'wpilot_delete_task',nonce:nonce,task_id:id},function(r){
                if(r.success) $('[data-task-id="'+id+'"]').fadeOut(200,function(){$(this).remove();});
            });
        });
        $(document).on('click','.aib-run-now',function(){
            var id=$(this).data('id'), $btn=$(this);
            $btn.prop('disabled',true).text('Running…');
            $.post(ajaxurl,{action:'wpi_run_task_now',nonce:nonce,task_id:id},function(r){
                $btn.prop('disabled',false).text('▶ Run now');
                if(r.success) location.reload();
            });
        });
    });
    </script>
    <?php wpilot_wrap('Scheduler','⏰', ob_get_clean());
}

// ══════════════════════════════════════════════════════════════
// SITE BUILDER PAGE — Välj sajttyp, se plugins, starta AI-analys
// ══════════════════════════════════════════════════════════════
function wpilot_page_sitebuilder() {
    $kb       = wpilot_site_knowledge();
    $detected = wpilot_detect_site_type();
    ob_start(); ?>

    <div class="ca-hero-box" style="margin-bottom:24px">
        <h2>🏗️ Build anything in WordPress</h2>
        <p>Välj vilken typ av sajt du bygger. WPilot visar dig exakt vilka plugins du behöver, varför, hur du sätter upp dem — och maximerar allt.</p>
    </div>

    <!-- Site type grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:28px">
    <?php foreach($kb as $type_id => $type): ?>
    <div class="ca-type-card <?= in_array($type_id,$detected)?'ca-type-detected':'' ?>"
         data-type="<?= esc_attr($type_id) ?>" onclick="wpiShowType('<?= esc_attr($type_id) ?>')">
        <div class="ca-type-icon"><?= $type['icon'] ?></div>
        <div class="ca-type-label"><?= esc_html($type['label']) ?></div>
        <div class="ca-type-desc"><?= esc_html($type['desc']) ?></div>
        <?php if(in_array($type_id,$detected)): ?>
        <div class="ca-type-badge">✓ Detected</div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- Plugin detail panel (shown on click) -->
    <div id="wpiTypeDetail" style="display:none">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
            <h3 id="wpiDetailTitle" style="margin:0;font-size:17px;font-weight:800"></h3>
            <div style="display:flex;gap:10px">
                <button class="ca-btn ca-btn-primary" id="wpiAnalyzeBtn">🔍 AI Analyze this type</button>
                <button class="ca-btn ca-btn-ghost ca-btn-sm" onclick="document.getElementById('wpiTypeDetail').style.display='none'">✕ Close</button>
            </div>
        </div>

        <!-- Must-have list -->
        <div id="wpiMustHave" class="ca-card" style="margin-bottom:16px;border-color:rgba(79,128,247,.3)">
            <div style="font-size:11px;font-weight:800;color:var(--ca-accent);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px">✅ Must-have för denna sajttyp</div>
            <div id="wpiMustHaveList"></div>
        </div>

        <!-- Plugin cards -->
        <div id="wpiPluginCards" style="display:flex;flex-direction:column;gap:12px"></div>

        <!-- AI analysis result -->
        <div id="wpiAnalysisResult" style="display:none;margin-top:20px">
            <div class="ca-section-lbl">🤖 AI-analys</div>
            <div id="wpiAnalysisText" class="ca-card" style="margin-top:10px;font-size:13px;line-height:1.8;white-space:pre-wrap"></div>
        </div>
    </div>

    <style>
    .ca-type-card{background:var(--ca-bg2);border:1px solid var(--ca-border);border-radius:12px;padding:16px;cursor:pointer;transition:all .18s;position:relative}
    .ca-type-card:hover{border-color:var(--ca-accent);background:rgba(79,128,247,.06);transform:translateY(-2px)}
    .ca-type-detected{border-color:rgba(16,185,129,.4);background:rgba(16,185,129,.05)}
    .ca-type-icon{font-size:28px;margin-bottom:8px}
    .ca-type-label{font-size:13.5px;font-weight:800;color:var(--ca-text);margin-bottom:4px}
    .ca-type-desc{font-size:11.5px;color:var(--ca-text3);line-height:1.4}
    .ca-type-badge{position:absolute;top:10px;right:10px;background:rgba(16,185,129,.15);color:#6ee7b7;font-size:10px;font-weight:700;padding:2px 7px;border-radius:4px}
    .wpi-plugin-card{background:var(--ca-bg2);border:1px solid var(--ca-border);border-radius:12px;padding:18px 20px}
    .wpi-plugin-card:hover{border-color:var(--ca-border-hover)}
    .wpi-stars{color:#fbbf24;font-size:12px;letter-spacing:1px}
    .wpi-tip{background:rgba(79,128,247,.08);border-left:3px solid var(--ca-accent);padding:8px 12px;border-radius:0 6px 6px 0;font-size:12px;color:var(--ca-text2);margin-top:8px;line-height:1.6}
    </style>

    <script>
    var wpiKB = <?= json_encode($kb) ?>;
    var wpiNonce = (typeof CA !== 'undefined') ? CA.nonce : '';
    var wpiCurrentType = null;

    function wpiShowType(typeId) {
        var t = wpiKB[typeId];
        if (!t) return;
        wpiCurrentType = typeId;

        document.getElementById('wpiDetailTitle').textContent = t.icon + ' ' + t.label;
        document.getElementById('wpiTypeDetail').style.display = 'block';
        document.getElementById('wpiAnalysisResult').style.display = 'none';

        // Must-haves
        var mh = document.getElementById('wpiMustHaveList');
        mh.innerHTML = (t.must_have||[]).map(function(m){
            return '<div style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;color:var(--ca-text2);border-bottom:1px solid var(--ca-border)"><span style="color:#6ee7b7;font-size:14px">✓</span>' + m + '</div>';
        }).join('');

        // Plugin cards
        var pc = document.getElementById('wpiPluginCards');
        pc.innerHTML = (t.plugins||[]).map(function(p){
            var stars = '★'.repeat(p.stars||4) + '☆'.repeat(5-(p.stars||4));
            return '<div class="wpi-plugin-card">'
                + '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">'
                + '<div><div style="font-size:15px;font-weight:800;color:var(--ca-text);margin-bottom:3px">' + p.name + '</div>'
                + '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">'
                + '<span class="wpi-stars">' + stars + '</span>'
                + '<span style="font-size:11.5px;color:var(--ca-text3)">' + (p.category||'') + '</span></div>'
                + '<div style="font-size:13px;color:var(--ca-text2);line-height:1.6">' + p.why + '</div>'
                + '</div>'
                + '<div style="text-align:right;flex-shrink:0">'
                + '<div style="font-size:13px;font-weight:700;color:var(--ca-accent)">' + p.price + '</div>'
                + '<a href="https://wordpress.org/plugins/" target="_blank" style="font-size:11.5px;color:var(--ca-text3);text-decoration:none">Install →</a>'
                + '</div></div>'
                + '<div class="wpi-tip">💡 <strong>Maximera:</strong> ' + p.tip + '</div>'
                + '</div>';
        }).join('');

        // Scroll to detail
        document.getElementById('wpiTypeDetail').scrollIntoView({behavior:'smooth', block:'start'});
    }

    document.getElementById('wpiAnalyzeBtn').addEventListener('click', function() {
        if (!wpiCurrentType) return;
        var $btn = jQuery(this);
        $btn.prop('disabled',true).text('Analyzing…');
        document.getElementById('wpiAnalysisResult').style.display = 'block';
        document.getElementById('wpiAnalysisText').textContent = 'WPilot analyserar din sajt…';

        jQuery.post(ajaxurl, {
            action: 'wpi_analyze_site_type',
            nonce: wpiNonce,
            type: wpiCurrentType
        }, function(r) {
            $btn.prop('disabled',false).text('🔍 AI Analyze this type');
            if (r.success) {
                document.getElementById('wpiAnalysisText').textContent = r.data.analysis;
            } else {
                document.getElementById('wpiAnalysisText').textContent = 'Error: ' + (r.data || 'Failed');
            }
        });
    });
    </script>
    <?php wpilot_wrap('Site Builder','🏗️', ob_get_clean());
}
// ═══════════════════════════════════════════════════════════════
//  AI TRAINING PAGE
// ═══════════════════════════════════════════════════════════════
function wpilot_page_training() {
    $stats = wpilot_route_stats();
    ?>
    <div class="ca-page" data-theme="<?=esc_attr(get_option('wpilot_theme','dark'))?>">
    <div class="ca-page-header">
        <span class="ca-page-icon">🧠</span>
        <h1>AI Training</h1>
        <?php if(wpilot_is_connected()):?>
        <span class="ca-status-pill ca-status-ok">● Connected</span>
        <?php else:?>
        <span class="ca-status-pill ca-status-off">○ Not Connected</span>
        <?php endif;?>
    </div>
    <div class="ca-content">

    <!-- Progress toward own model -->
    <div class="ca-hero-box" style="margin-bottom:20px">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:12px">
            <div style="font-size:36px">🧠</div>
            <div>
                <div style="font-size:18px;font-weight:800;letter-spacing:-.02em">Din AI tränas i bakgrunden</div>
                <div style="color:var(--ca-text2);font-size:13px;margin-top:3px">Varje konversation bidrar. När du har tillräckligt med data tränar vi Llama 3.3 — din egna modell som ersätter Claude.</div>
            </div>
        </div>
        <div id="wpiTrainingProgress">
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--ca-text3);margin-bottom:6px">
                <span>TRÄNINGSPAR INSAMLADE</span>
                <span id="wpiPairCount">Laddar…</span>
            </div>
            <div class="ca-ub-track" style="height:8px;margin-bottom:8px">
                <div class="ca-ub-fill" id="wpiProgressBar" style="width:0%"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--ca-text3)">
                <span>0</span>
                <span id="wpiNextMilestone" style="color:var(--ca-accent)">Laddar milstolpe…</span>
                <span>50 000</span>
            </div>
        </div>
    </div>

    <!-- Milestones -->
    <div class="ca-section-lbl">MILSTOLPAR</div>
    <div class="ca-feature-grid" style="margin-bottom:24px">
        <div class="ca-feature-item" id="ms1000">
            <div class="ca-fi-icon">🌱</div>
            <div class="ca-fi-title">1 000 par</div>
            <div class="ca-fi-desc">Llama 3.2 3B — Enkel WordPress-hjälp. Kostar ~$5 att träna.</div>
            <div class="ca-badge ca-badge-gray" style="margin-top:8px" id="ms1000badge">Ej redo</div>
        </div>
        <div class="ca-feature-item" id="ms10000">
            <div class="ca-fi-icon">🚀</div>
            <div class="ca-fi-title">10 000 par</div>
            <div class="ca-fi-desc">Llama 3.2 7B — Täcker 60% av frågor utan Claude.</div>
            <div class="ca-badge ca-badge-gray" style="margin-top:8px" id="ms10000badge">Ej redo</div>
        </div>
        <div class="ca-feature-item" id="ms50000">
            <div class="ca-fi-icon">⚡</div>
            <div class="ca-fi-title">50 000 par</div>
            <div class="ca-fi-desc">Llama 3.3 70B — Ersätter Claude helt. ~$12 att träna, ~$150/mån hosting.</div>
            <div class="ca-badge ca-badge-gray" style="margin-top:8px" id="ms50000badge">Ej redo</div>
        </div>
        <div class="ca-feature-item">
            <div class="ca-fi-icon">🏆</div>
            <div class="ca-fi-title">Eget API</div>
            <div class="ca-fi-desc">Weblease AI API — sälj access till andra plugin-utvecklare och byråer.</div>
            <div class="ca-badge ca-badge-blue" style="margin-top:8px">Slutmål</div>
        </div>
    </div>

    <div class="ca-two-col">

    <!-- Data sources -->
    <div>
        <div class="ca-section-lbl">DATAKÄLLOR</div>
        <div class="ca-card">
            <h3>📊 Insamlad data</h3>
            <div id="wpiDataSources">
                <div style="display:flex;flex-direction:column;gap:10px">
                    <div class="ca-info-row">
                        <span>🧠 Brain-minnen (godkända)</span>
                        <span id="wpi_brain_pairs" class="ca-badge ca-badge-blue">–</span>
                    </div>
                    <div class="ca-info-row">
                        <span>📦 Kollektorn (rating 4-5★)</span>
                        <span id="wpi_collector_pairs" class="ca-badge ca-badge-green">–</span>
                    </div>
                    <div class="ca-info-row">
                        <span>⭐ Snitt kvalitetspoäng</span>
                        <span id="wpi_avg_quality">–</span>
                    </div>
                    <div class="ca-info-row">
                        <span>📤 Skickade till VPS</span>
                        <span id="wpi_sent_vps">–</span>
                    </div>
                </div>
            </div>
            <div class="ca-card-footer">
                <button class="ca-btn ca-btn-primary ca-btn-sm" id="wpiBtnPushVps">📤 Skicka till VPS nu</button>
                <button class="ca-btn ca-btn-ghost ca-btn-sm" id="wpiBtnExportJsonl">⬇️ Ladda ner JSONL</button>
            </div>
        </div>

        <!-- Topic breakdown -->
        <div class="ca-card">
            <h3>📂 Ämnesfördelning</h3>
            <div id="wpiTopicBreakdown" style="display:flex;flex-direction:column;gap:6px">
                <div style="color:var(--ca-text3);font-size:12px">Laddar…</div>
            </div>
        </div>
    </div>

    <!-- Route stats + signals -->
    <div>
        <div class="ca-section-lbl">AI-ROUTING IDAG</div>
        <div class="ca-card ca-card-glow">
            <h3>⚡ Hur svaras frågor</h3>
            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px">
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                        <span style="color:var(--ca-green)">🧠 Brain (gratis)</span>
                        <span id="wpi_brain_pct">–%</span>
                    </div>
                    <div class="ca-ub-track"><div class="ca-ub-fill" id="bar_brain" style="width:0%;background:var(--ca-green)"></div></div>
                </div>
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                        <span style="color:var(--ca-accent)">⚡ WPilot AI (billig)</span>
                        <span id="wpi_webleas_pct">–%</span>
                    </div>
                    <div class="ca-ub-track"><div class="ca-ub-fill" id="bar_webleas" style="width:0%"></div></div>
                </div>
                <div>
                    <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:4px">
                        <span style="color:var(--ca-text2)">🤖 Claude (din API-nyckel)</span>
                        <span id="wpi_claude_pct">–%</span>
                    </div>
                    <div class="ca-ub-track"><div class="ca-ub-fill" id="bar_claude" style="width:0%;background:var(--ca-text3)"></div></div>
                </div>
            </div>
            <div class="ca-info-box">
                💡 Målet: Brain + WPilot AI hanterar <strong>80%+</strong> av alla frågor. Claude används bara för komplexa nya problem.
            </div>
        </div>

        <!-- Signals collected -->
        <div class="ca-card">
            <h3>🎯 Kvalitetssignaler</h3>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13px">
                <div class="ca-info-row">
                    <span>✅ Apply-klick (rating 5)</span>
                    <span class="ca-badge ca-badge-green" id="sig_apply">–</span>
                </div>
                <div class="ca-info-row">
                    <span>⏭️ Skip (rating 2)</span>
                    <span class="ca-badge ca-badge-gray" id="sig_skip">–</span>
                </div>
                <div class="ca-info-row">
                    <span>↩️ Undo (rating 1)</span>
                    <span class="ca-badge ca-badge-red" id="sig_undo">–</span>
                </div>
            </div>
            <div class="ca-card-footer">
                <div style="font-size:11px;color:var(--ca-text3)">Signaler skickas automatiskt i bakgrunden. Användaren märker ingenting.</div>
            </div>
        </div>

        <!-- VPS status -->
        <div class="ca-card">
            <h3>🖥️ VPS Status</h3>
            <div style="font-size:13px;display:flex;flex-direction:column;gap:8px">
                <div class="ca-info-row">
                    <span>Endpoint</span>
                    <code style="font-size:11px;background:var(--ca-bg4);padding:2px 6px;border-radius:4px">weblease.se/ai-training</code>
                </div>
                <div class="ca-info-row">
                    <span>Senaste batch</span>
                    <span id="wpi_last_batch" style="color:var(--ca-text2)">–</span>
                </div>
                <div class="ca-info-row">
                    <span>Totalt skickat</span>
                    <span id="wpi_total_sent">–</span>
                </div>
            </div>
        </div>
    </div>
    </div><!-- .ca-two-col -->

    <!-- How it works -->
    <div class="ca-section-lbl" style="margin-top:8px">SÅ HÄR FUNKAR DET</div>
    <div class="ca-card">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px">
            <div style="text-align:center;padding:12px">
                <div style="font-size:28px;margin-bottom:8px">💬</div>
                <div style="font-weight:700;margin-bottom:4px;font-size:13px">1. Användare chattar</div>
                <div style="font-size:12px;color:var(--ca-text2)">Varje fråga + svar sparas anonymiserat</div>
            </div>
            <div style="text-align:center;padding:12px">
                <div style="font-size:28px;margin-bottom:8px">⭐</div>
                <div style="font-weight:700;margin-bottom:4px;font-size:13px">2. Signaler samlas</div>
                <div style="font-size:12px;color:var(--ca-text2)">Apply/Undo/Skip betygsätter automatiskt</div>
            </div>
            <div style="text-align:center;padding:12px">
                <div style="font-size:28px;margin-bottom:8px">📤</div>
                <div style="font-weight:700;margin-bottom:4px;font-size:13px">3. Skickas till VPS</div>
                <div style="font-size:12px;color:var(--ca-text2)">Bara bra par (4-5★) skickas till din server</div>
            </div>
            <div style="text-align:center;padding:12px">
                <div style="font-size:28px;margin-bottom:8px">🦙</div>
                <div style="font-weight:700;margin-bottom:4px;font-size:13px">4. Llama tränas</div>
                <div style="font-size:12px;color:var(--ca-text2)">Vid 1000+ par kör du train_llama.py på RunPod</div>
            </div>
            <div style="text-align:center;padding:12px">
                <div style="font-size:28px;margin-bottom:8px">🚀</div>
                <div style="font-weight:700;margin-bottom:4px;font-size:13px">5. Din AI svarar</div>
                <div style="font-size:12px;color:var(--ca-text2)">WPilot routar till din modell istället för Claude</div>
            </div>
        </div>
    </div>

    </div><!-- .ca-content -->
    </div><!-- .ca-page -->

    <script>
    jQuery(function($){
        var nonce = (typeof CA !== 'undefined') ? CA.nonce : '';

        function loadStats() {
            $.post(ajaxurl, {action:'wpi_training_stats', nonce:nonce}, function(r) {
                if (!r.success) return;
                var d = r.data;

                // Progress
                var pct = d.pct || 0;
                $('#wpiPairCount').text(d.total.toLocaleString() + ' / 50 000 par');
                $('#wpiProgressBar').css('width', pct + '%');

                // Next milestone
                if (d.next_milestone) {
                    var m = d.next_milestone;
                    $('#wpiNextMilestone').text('Nästa: ' + m.pairs.toLocaleString() + ' par → ' + m.model + ' (' + m.remaining.toLocaleString() + ' kvar)');
                } else {
                    $('#wpiNextMilestone').text('✅ Redo för 70B-träning!').css('color','var(--ca-green)');
                }

                // Milestones
                if (d.total >= 1000)  { $('#ms1000badge').attr('class','ca-badge ca-badge-green').text('✅ Redo'); $('#ms1000').css('border-color','rgba(16,185,129,.3)'); }
                if (d.total >= 10000) { $('#ms10000badge').attr('class','ca-badge ca-badge-green').text('✅ Redo'); $('#ms10000').css('border-color','rgba(16,185,129,.3)'); }
                if (d.total >= 50000) { $('#ms50000badge').attr('class','ca-badge ca-badge-green').text('✅ Redo'); $('#ms50000').css('border-color','rgba(16,185,129,.3)'); }

                // Sources
                var brainPairs = d.by_topic ? Object.values(d.by_topic).reduce((a,b)=>a+b,0) : 0;
                $('#wpi_brain_pairs').text(brainPairs);
                $('#wpi_collector_pairs').text(d.collector ? d.collector.unsent_good || 0 : 0);
                $('#wpi_avg_quality').text((d.avg_quality || 0) + '/100');
                $('#wpi_sent_vps').text(d.collector && d.collector.stats ? (d.collector.stats.good || 0) + ' par' : '–');

                // Routes
                var rs = d.route_stats || {};
                $('#wpi_brain_pct').text((rs.brain_pct||0) + '%');
                $('#wpi_webleas_pct').text((rs.webleas_pct||0) + '%');
                $('#wpi_claude_pct').text((rs.claude_pct||0) + '%');
                $('#bar_brain').css('width',(rs.brain_pct||0)+'%');
                $('#bar_webleas').css('width',(rs.webleas_pct||0)+'%');
                $('#bar_claude').css('width',(rs.claude_pct||0)+'%');

                // Topics
                if (d.by_topic) {
                    var html = '';
                    $.each(d.by_topic, function(topic, count) {
                        var w = Math.round(count / Math.max(1, d.total) * 100);
                        html += '<div style="margin-bottom:6px">'
                            + '<div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">'
                            + '<span style="color:var(--ca-text2)">' + topic.replace('_',' ') + '</span>'
                            + '<span style="color:var(--ca-text3)">' + count + '</span></div>'
                            + '<div class="ca-ub-track" style="height:3px"><div class="ca-ub-fill" style="width:'+w+'%"></div></div>'
                            + '</div>';
                    });
                    $('#wpiTopicBreakdown').html(html || '<div style="color:var(--ca-text3);font-size:12px">Inga ämnen ännu</div>');
                }

                // VPS stats
                var cs = d.collector && d.collector.stats ? d.collector.stats : {};
                $('#wpi_last_batch').text(cs.last || '–');
                $('#wpi_total_sent').text((cs.good || 0) + ' högkvalitativa par');

                // Signals (from queue)
                var queue = d.collector ? d.collector.queued || 0 : 0;
                $('#sig_apply').text('–');
                $('#sig_skip').text('–');
                $('#sig_undo').text('–');
            });
        }

        loadStats();

        // Push to VPS
        $('#wpiBtnPushVps').on('click', function() {
            var $b = $(this).text('Skickar…').prop('disabled',true);
            $.post(ajaxurl, {action:'wpi_push_to_vps', nonce:nonce}, function(r) {
                $b.text('📤 Skicka till VPS nu').prop('disabled',false);
                if (r.success) {
                    alert('✅ Skickade ' + r.data.sent + ' par till VPS!');
                    loadStats();
                } else {
                    alert('⚠️ ' + (r.data || 'Fel vid sändning'));
                }
            });
        });

        // Export JSONL
        $('#wpiBtnExportJsonl').on('click', function() {
            var $b = $(this).text('Exporterar…').prop('disabled',true);
            $.post(ajaxurl, {action:'wpi_export_training', nonce:nonce, min_quality:50}, function(r) {
                $b.text('⬇️ Ladda ner JSONL').prop('disabled',false);
                if (r.success && r.data.jsonl) {
                    var blob = new Blob([r.data.jsonl], {type:'application/json'});
                    var url  = URL.createObjectURL(blob);
                    var a    = document.createElement('a');
                    a.href   = url;
                    a.download = 'wpilot_training_' + new Date().toISOString().slice(0,10) + '.jsonl';
                    a.click();
                    URL.revokeObjectURL(url);
                } else {
                    alert('⚠️ Ingen data att exportera ännu.');
                }
            });
        });
    });
    </script>
    <?php
}
