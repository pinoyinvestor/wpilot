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
if ( ! defined( 'ABSPATH' ) ) exit;


// ── Register menu ──────────────────────────────────────────────
add_action( 'admin_menu', function () {
    $svg = 'data:image/svg+xml;base64,' . base64_encode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" stroke="rgba(255,255,255,.9)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>'
    );
    add_menu_page( 'WPilot', 'WPilot', 'manage_options', CA_SLUG, 'wpilot_page_dashboard', $svg, 25 );
    $pages = [
        [ CA_SLUG,               'Dashboard',       'wpilot_page_dashboard'    ],
        [ CA_SLUG.'-mcp',        'Claude Code',     'wpilot_page_mcp'    ],
        [ CA_SLUG.'-mcp',   'Settings',        'wpilot_page_settings'     ],
        [ CA_SLUG.'-license',    'License',         'wpilot_page_license'      ],
        [ CA_SLUG.'-restore',    'History',         'wpilot_page_restore'      ],
    ];
    foreach ( $pages as [$slug,$title,$cb] ) {
        add_submenu_page( CA_SLUG, $title.' — WPilot', $title, 'manage_options', $slug, $cb );
    }

    // Hidden pages (accessible via direct URL, not in sidebar)
    $hidden = [
        [CA_SLUG.'-build',      'Build',           'wpilot_page_build'        ],
        [CA_SLUG.'-plugins',    'Plugins',         'wpilot_page_plugins'      ],
        [CA_SLUG.'-media',      'Media',           'wpilot_page_media'        ],
        [CA_SLUG.'-guide',      'Getting Started', 'wpilot_page_guide'        ],
        [CA_SLUG.'-activity',   'Activity Log',    'wpilot_page_activity'     ],
        [CA_SLUG.'-feedback',   'Feedback',        'wpilot_page_feedback'     ],
        [CA_SLUG.'-instr',      'AI Instructions', 'wpilot_page_instructions'],
        [CA_SLUG.'-mcp',        'Advanced Tools',      'wpilot_page_mcp'          ],
        [CA_SLUG.'-brain',      'WPilot Brain',    'wpilot_page_brain'],
        [CA_SLUG.'-scheduler',  'Scheduler',       'wpilot_page_scheduler'],
    ];
    foreach ($hidden as [$slug, $title, $cb]) {
        add_submenu_page(null, $title.' — WPilot', $title, 'manage_options', $slug, $cb);
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
        $t = wpilot_get_license_tier();
        $tl = ['free'=>'Free','pro'=>'⚡ Pro','team'=>'👥 Team','lifetime'=>'🏆 Lifetime'];
        echo '<span class="ca-status-pill ca-status-ok">● '.esc_html($tl[$t] ?? ucfirst($t)).'</span>';
    } else {
        echo '<span class="ca-status-pill ca-status-off">○ Not Connected — <a href="'.esc_url(admin_url('admin.php?page='.CA_SLUG.'-mcp')).'">Connect →</a></span>';
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
    $usage   = function_exists('wpilot_get_usage_summary') ? wpilot_get_usage_summary() : ['today'=>0,'week'=>0,'month'=>0,'total'=>$used,'est_cost_month'=>0,'est_cost_total'=>0];
    $remain  = wpilot_prompts_remaining();
    $woo     = class_exists( 'WooCommerce' );
    $bld     = ucfirst( wpilot_detect_builder() );
    $conn    = wpilot_is_connected();
    $lic     = wpilot_is_licensed();
    $tier    = wpilot_get_license_tier();
    // Plan display name
    $plan_names = ['free' => 'Free', 'pro' => 'Pro', 'team' => 'Team', 'lifetime' => 'Lifetime', 'agency' => 'Agency'];
    $plan_label = $plan_names[$tier] ?? ucfirst($tier);
    $plan_icons = ['free' => '🆓', 'pro' => '⚡', 'team' => '👥', 'lifetime' => '🏆'];
    $plan_icon  = $plan_icons[$tier] ?? '🔑';
    ob_start(); ?>

    <?php if ( ! $conn && !(function_exists("wpilot_get_ai_method") && wpilot_get_ai_method() === "claude_code") ): ?>
    <div class="ca-alert ca-alert-warn ca-onboard-banner">
        <span class="ca-alert-icon">⚡</span>
        <div>
            <strong>Connect Claude AI to get started</strong>
            <p>Connect Claude AI to get started. WPilot can set it up automatically — just click Connect Now.</p>
        </div>
        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-mcp') ) ?>" class="ca-btn ca-btn-primary">Connect Now →</a>
    </div>
    <?php elseif ( ! $lic && $used >= CA_FREE_LIMIT ): ?>
    <div class="ca-alert ca-alert-danger ca-onboard-banner">
        <span class="ca-alert-icon">🔒</span>
        <div>
            <strong>Free limit reached — upgrade to continue</strong>
            <p>You've used all <?= CA_FREE_LIMIT ?> free prompts. Upgrade to Pro for unlimited access.</p>
        </div>
        <a href="<?= esc_url( admin_url('admin.php?page='.CA_SLUG.'-license') ) ?>" class="ca-btn ca-btn-primary">Upgrade →</a>
    </div>
    <?php endif; ?>

    <div class="ca-stat-row">
        <?php
        $stats = [
            ['AI Prompts',  $lic ? '∞ Unlimited' : $used.' used',  $lic ? 'Unlimited — '.$plan_label.' plan' : $remain.' of '.CA_FREE_LIMIT.' remaining'],
            ['Builder',     $bld,                             'AI adapts to your page builder'],
            ['WooCommerce', $woo ? '✅ Active' : '—',         $woo ? 'Full WooCommerce AI support' : 'Not installed'],
            ['Plan',        $plan_icon.' '.$plan_label,       $lic ? 'All features unlocked' : 'Upgrade for unlimited prompts'],
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
            [CA_SLUG.'-chat',     '💬', 'Chat with AI',      'Design, build, and fix your site by chatting'],
            [CA_SLUG.'-analyze',  '🔍', 'Site Analysis',     'Full report on SEO, speed, and design'],
            [CA_SLUG.'-mcp', '⚙️', 'Connect Account',   'Connect your Claude AI account'],
            [CA_SLUG.'-license',  '🔑', 'Your Plan',         'Manage your WPilot subscription'],
            [CA_SLUG.'-restore',  '🔄', 'Undo Changes',      'Restore anything the AI changed'],
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

    <div class="glass-card p-5 mt-6" style="background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:20px;margin-top:24px">
        <h3 style="font-size:11px;font-weight:700;color:#5E6E91;text-transform:uppercase;letter-spacing:.07em;margin:0 0 12px">Advanced</h3>
        <div style="display:flex;flex-wrap:wrap;gap:12px">
            <a href="<?= admin_url('admin.php?page='.CA_SLUG.'-brain') ?>" style="font-size:13px;color:#4F7EFF;text-decoration:none">🧠 WPilot Brain</a>
            <a href="<?= admin_url('admin.php?page='.CA_SLUG.'-scheduler') ?>" style="font-size:13px;color:#4F7EFF;text-decoration:none">📅 Scheduler</a>
            <a href="<?= admin_url('admin.php?page='.CA_SLUG.'-instr') ?>" style="font-size:13px;color:#4F7EFF;text-decoration:none">📝 AI Instructions</a>
        </div>
    </div>

    <?php wpilot_wrap( 'WPilot', '⚡', ob_get_clean() );
}

// ══════════════════════════════════════════════════════════════
// CHAT
// ══════════════════════════════════════════════════════════════
function wpilot_page_chat() {
    echo '<div class="ca-page"><p>This feature now works through MCP. Use Claude Code.</p></div>';
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
    echo '<div class="ca-page"><p>This feature now works through MCP. Use Claude Code.</p></div>';
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

    <div class="ca-hero-box" style="margin-bottom:28px">
        <h2>Getting started with WPilot</h2>
        <p style="font-size:14px;color:var(--ca-text2);margin:8px 0 0">WPilot connects your WordPress site to Claude AI via MCP. Follow these steps to get started.</p>
        <?php if ($conn): ?>
        <div style="margin-top:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap">
            <span style="background:rgba(16,185,129,.15);color:#6ee7b7;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700">Connected — you are ready!</span>
        </div>
        <?php endif; ?>
    </div>
    <!-- Built by Weblease -->
    <div class="ca-section-lbl" style="margin-bottom:16px">Setup — 3 simple steps</div>

    <div style="display:flex;flex-direction:column;gap:16px;margin-bottom:28px">
        <div class="ca-card" style="padding:24px">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                <span style="background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px">1</span>
                <strong style="font-size:15px;color:var(--ca-text,#fff)">Get Claude</strong>
            </div>
            <p style="font-size:13px;color:var(--ca-text2);line-height:1.6;margin:0">Download <a href="https://claude.ai/download" target="_blank" style="color:#4F7EFF">Claude Desktop</a> (free, Mac/Windows/Linux) or install <a href="https://docs.anthropic.com/en/docs/claude-code" target="_blank" style="color:#4F7EFF">Claude Code</a> via terminal. You need a Claude account from <a href="https://anthropic.com" target="_blank" style="color:#4F7EFF">Anthropic</a>.</p>
        </div>

        <div class="ca-card" style="padding:24px">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                <span style="background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px">2</span>
                <strong style="font-size:15px;color:var(--ca-text,#fff)">Connect your site</strong>
            </div>
            <p style="font-size:13px;color:var(--ca-text2);line-height:1.6;margin:0 0 12px">Go to <strong>WPilot &gt; Settings</strong> and copy the MCP Server URL. Then add it to your Claude client:</p>
            <ul style="font-size:13px;color:var(--ca-text2);line-height:1.8;margin:0;padding-left:20px">
                <li><strong>Claude Desktop:</strong> Settings &rarr; Connectors &rarr; Add custom connector</li>
                <li><strong>Claude Code:</strong> <code>claude mcp add wpilot --transport streamable-http URL</code></li>
            </ul>
        </div>

        <div class="ca-card" style="padding:24px">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px">
                <span style="background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px">3</span>
                <strong style="font-size:15px;color:var(--ca-text,#fff)">Start building</strong>
            </div>
            <p style="font-size:13px;color:var(--ca-text2);line-height:1.6;margin:0">Just talk to Claude. Try: <em style="color:var(--ca-text,#fff)">"Analyze my site and suggest improvements"</em> or <em style="color:var(--ca-text,#fff)">"Build a modern homepage for my business"</em></p>
        </div>
    </div>

    <div class="ca-section-lbl" style="margin-bottom:16px">What can WPilot do?</div>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:28px">
        <?php foreach ([
            ["Pages", "Create, edit, and manage pages with AI"],
            ["Design", "Colors, fonts, layouts, and CSS"],
            ["SEO", "Meta titles, descriptions, schema, sitemap"],
            ["WooCommerce", "Products, shipping, payments, checkout"],
            ["Plugins", "Install, activate, and configure plugins"],
            ["Security", "Scan vulnerabilities, add headers"],
            ["Media", "Upload and manage images"],
            ["Content", "Write, translate, and edit text"],
            ["Forms", "Create contact and signup forms"],
            ["Marketing", "Popups, CTAs, social proof"],
        ] as [$title, $desc]): ?>
        <div class="ca-card" style="padding:16px">
            <strong style="font-size:13px;color:var(--ca-text)"><?= esc_html($title) ?></strong>
            <p style="font-size:12px;color:var(--ca-text2);margin:4px 0 0;line-height:1.5"><?= esc_html($desc) ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="ca-section-lbl" style="margin-bottom:16px">Pricing</div>
    <div class="ca-card" style="padding:20px;margin-bottom:28px">
        <p style="font-size:13px;color:var(--ca-text2);line-height:1.8;margin:0">
            <strong style="color:var(--ca-text)">Free:</strong> 20 prompts, no credit card needed<br>
            <strong style="color:var(--ca-text)">Pro:</strong> $9/month, unlimited prompts, 1 site<br>
            <strong style="color:var(--ca-text)">Team:</strong> $29/month, unlimited prompts, 3 sites<br>
            <strong style="color:var(--ca-text)">Lifetime:</strong> $149 one-time, unlimited forever
        </p>
        <p style="font-size:12px;color:var(--ca-text3);margin:12px 0 0">Note: You also need a Claude account from Anthropic. Claude has its own pricing at <a href="https://anthropic.com/pricing" target="_blank" style="color:#4F7EFF">anthropic.com/pricing</a>.</p>
    </div>

    <?php
    echo ob_get_clean();
}


function wpilot_page_settings() {
    // Handle terminal key generation/revocation
    if (isset($_POST['wpilot_mcp_action']) && wp_verify_nonce($_POST['_wpnonce'], 'wpilot_mcp_action')) {
        $mcp_action = sanitize_text_field($_POST['wpilot_mcp_action']);
        if ($mcp_action === 'generate' && function_exists('wpilot_mcp_generate_key')) {
            $generated_key = wpilot_mcp_generate_key();
        } elseif ($mcp_action === 'revoke' && function_exists('wpilot_mcp_revoke_key')) {
            wpilot_mcp_revoke_key();
        }
    }
    $key = function_exists('wpilot_get_claude_key') ? wpilot_get_claude_key() : get_option('ca_api_key','');
    $masked = $key ? substr($key,0,12).'••••'.substr($key,-4) : '';
    $conn   = wpilot_is_connected();
    ob_start(); ?>
    <!-- Connect Claude — Simple -->
    <div class="ca-card" style="text-align:center;padding:32px 24px">
        <?php
        // Built by Weblease
        $mcp_token = get_option('wpilot_mcp_token', '');
        $site_url = get_site_url();
        $mcp_url = $site_url . '/?rest_route=/wpilot/v1/mcp';

        if (empty($mcp_token)) {
            $mcp_token = wp_generate_password(32, false);
            update_option('wpilot_mcp_token', $mcp_token);
        }
        ?>

        <div style="font-size:48px;margin-bottom:16px">&#9889;</div>
        <h2 style="font-size:24px;font-weight:800;margin-bottom:8px;color:var(--ca-text,#fff)">Connect Claude</h2>
        <p style="font-size:15px;color:var(--ca-text2,#5E6E91);margin-bottom:28px;line-height:1.6">
            Connect your Claude app to your WordPress site.<br>
            Claude can then build, design, and manage your website.
        </p>

        <!-- Step 1 -->
        <div style="background:var(--ca-bg3,#0B0E18);border:1px solid var(--ca-border2,#1a1a2e);border-radius:14px;padding:24px;margin-bottom:20px;text-align:left">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                <span style="background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0">1</span>
                <span style="font-size:15px;font-weight:600;color:var(--ca-text,#fff)">Download Claude Desktop</span>
            </div>
            <a href="https://claude.ai/download" target="_blank" style="display:block;width:100%;padding:14px;background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;border:none;border-radius:10px;font-weight:700;font-size:15px;text-align:center;text-decoration:none;cursor:pointer;transition:all .3s">
                Download Claude &rarr;
            </a>
            <p style="font-size:12px;color:var(--ca-text3,#3E4A6D);margin-top:10px;text-align:center">Free. Available for Mac, Windows, and Linux.</p>
        </div>

        <!-- Step 2 -->
        <div style="background:var(--ca-bg3,#0B0E18);border:1px solid var(--ca-border2,#1a1a2e);border-radius:14px;padding:24px;margin-bottom:20px;text-align:left">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                <span style="background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0">2</span>
                <span style="font-size:15px;font-weight:600;color:var(--ca-text,#fff)">Connect your site</span>
            </div>
            <p style="font-size:13px;color:var(--ca-text2,#5E6E91);margin-bottom:12px;line-height:1.5">
                Open Claude Desktop &rarr; Settings &rarr; Connectors &rarr; "Add custom connector"
            </p>
            <div style="margin-bottom:8px">
                <label style="font-size:12px;font-weight:600;color:var(--ca-text2,#5E6E91);display:block;margin-bottom:4px">Name:</label>
                <div style="display:flex;gap:8px">
                    <input type="text" value="WPilot" readonly style="flex:1;padding:10px 14px;background:var(--ca-bg,#050608);border:1px solid var(--ca-border2,#333);border-radius:8px;color:var(--ca-text,#fff);font-size:14px;font-family:monospace">
                    <button onclick="navigator.clipboard.writeText('WPilot');this.textContent='&#10003;';setTimeout(()=>this.textContent='Kopiera',1500)" style="padding:10px 16px;background:var(--ca-bg4,#111);border:1px solid var(--ca-border2,#333);border-radius:8px;color:var(--ca-text2);font-size:13px;cursor:pointer;white-space:nowrap">Copy</button>
                </div>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600;color:var(--ca-text2,#5E6E91);display:block;margin-bottom:4px">Server URL:</label>
                <div style="display:flex;gap:8px">
                    <input type="text" value="<?= esc_attr($mcp_url) ?>" readonly id="wpilotMcpUrl" style="flex:1;padding:10px 14px;background:var(--ca-bg,#050608);border:1px solid var(--ca-border2,#333);border-radius:8px;color:var(--ca-text,#fff);font-size:13px;font-family:monospace;overflow:hidden;text-overflow:ellipsis">
                    <button onclick="navigator.clipboard.writeText(document.getElementById('wpilotMcpUrl').value);this.textContent='&#10003;';setTimeout(()=>this.textContent='Kopiera',1500)" style="padding:10px 16px;background:var(--ca-bg4,#111);border:1px solid var(--ca-border2,#333);border-radius:8px;color:var(--ca-text2);font-size:13px;cursor:pointer;white-space:nowrap">Copy</button>
                </div>
            </div>
        </div>

        <!-- Step 3 -->
        <div style="background:var(--ca-bg3,#0B0E18);border:1px solid var(--ca-border2,#1a1a2e);border-radius:14px;padding:24px;text-align:left">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                <span style="background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex-shrink:0">3</span>
                <span style="font-size:15px;font-weight:600;color:var(--ca-text,#fff)">Start building</span>
            </div>
            <p style="font-size:14px;color:var(--ca-text2,#5E6E91);line-height:1.6">
                Type in Claude: <strong style="color:var(--ca-text,#fff)">"Build a modern website for my business"</strong>
            </p>
            <p style="font-size:13px;color:var(--ca-text3,#3E4A6D);margin-top:8px">
                Claude can build pages, change designs, install plugins, fix SEO — everything on your WordPress site.
            </p>
        </div>
    </div>



    <div class="ca-card">
        <h3>🔌 <?php wpilot_te('connect_api_title') ?></h3>
        <p class="ca-card-sub">
            <?php wpilot_te('connect_api_sub') ?>
            <a href="https://anthropic.com" target="_blank" rel="noopener">anthropic.com</a>.
        </p>
        <?php
        $cc_status = function_exists('wpilot_claude_code_status') ? wpilot_claude_code_status() : 'not_installed';
        $server = function_exists('wpilot_server_can_install') ? wpilot_server_can_install() : ['exec'=>false];
        ?>

        <?php if ($conn && $cc_status === 'ready'): ?>
        <!-- Fully connected -->
        <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);border-radius:12px;padding:20px;text-align:center;margin-bottom:14px">
            <div style="font-size:32px;margin-bottom:8px">&#10003;</div>
            <strong style="color:#10B981;font-size:16px">Claude connected</strong>
            <p style="font-size:13px;color:var(--ca-text2,#5E6E91);margin-top:6px">WPilot is ready. Connect via MCP to start building.</p>
        </div>
        <div style="text-align:center">
            <button class="ca-btn ca-btn-ghost" id="wpilotDisconnect" style="font-size:12px;color:var(--ca-danger,#EF4444)">Disconnect</button>
        </div>

        <?php elseif ($cc_status === 'not_signed_in'): ?>
        <!-- Installed but not signed in -->
        <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:12px;padding:20px;text-align:center;margin-bottom:14px">
            <div style="font-size:32px;margin-bottom:8px">&#9888;</div>
            <strong style="color:#FCD34D;font-size:16px">Sign in to activate Claude</strong>
            <p style="font-size:13px;color:var(--ca-text2,#5E6E91);margin-top:8px">Claude is installed on your server. Click below to sign in with your Anthropic account.</p>
            <a href="<?= esc_url(site_url("/wpilot-login.php")) ?>" target="_blank" class="ca-btn ca-btn-primary" style="display:inline-block;margin-top:14px;font-size:15px;padding:14px 28px;text-decoration:none;text-align:center">Sign in with Claude</a>
            <a href="<?= esc_url(admin_url("admin.php?page=".CA_SLUG."-settings")) ?>" class="ca-btn ca-btn-ghost" style="display:inline-block;margin-top:8px;font-size:13px;padding:10px 20px;text-decoration:none;text-align:center">I have signed in — check again</a>
            <p style="margin-top:10px;font-size:12px;color:var(--ca-text2,#5E6E91);text-align:center">Click the button above, sign in, then come back and click check again.</p>
        </div>

        <?php elseif (!empty($server['exec']) && !empty($server['node'])): ?>
        <!-- Server ready for auto-install -->
        <div style="text-align:center;padding:10px 0">
            <p style="font-size:14px;color:var(--ca-text2,#5E6E91);margin-bottom:16px">Your server supports automatic setup.</p>
            <button class="ca-btn ca-btn-primary" id="wpilotAutoSetup" style="font-size:16px;padding:16px 32px">Set up Claude</button>
            <div id="wpilotSetupStatus" style="margin-top:12px;font-size:13px;min-height:20px"></div>
        </div>
        <script>
        jQuery('#wpilotAutoSetup').on('click', function(){
            var b=jQuery(this),s=jQuery('#wpilotSetupStatus');
            b.text('Setting up...').prop('disabled',true);
            function st(n,m,nx){s.html('<span style="color:#93B4FF">'+m+'</span>');jQuery.post(ajaxurl,{action:'wpilot_auto_install',nonce:CA.nonce,step:n},function(r){if(r.success)nx();else{s.html('<span style="color:#F87171">Failed</span>');b.text('Retry').prop('disabled',false);}});}
            st('install_claude','Installing Claude...',function(){st('setup_mcp','Connecting...',function(){s.html('<span style="color:#10B981">Done! Reload page.</span>');setTimeout(function(){location.reload();},1500);});});
        });
        </script>

        <?php elseif ($conn): ?>
        <!-- Connected via API key -->
        <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);border-radius:12px;padding:20px;text-align:center;margin-bottom:14px">
            <div style="font-size:32px;margin-bottom:8px">&#10003;</div>
            <strong style="color:#10B981;font-size:16px">Connected</strong>
            <p style="font-size:13px;color:var(--ca-text2,#5E6E91);margin-top:6px"><?= esc_html($masked) ?></p>
        </div>
        <div style="text-align:center">
            <button class="ca-btn ca-btn-ghost" id="wpilotDisconnect" style="font-size:12px;color:var(--ca-danger,#EF4444)">Disconnect</button>
        </div>

        <?php else: ?>
        <!-- Not connected at all -->
        <div style="text-align:center;padding:10px 0">
            <p style="font-size:14px;color:var(--ca-text2,#5E6E91);margin-bottom:12px">Connect your Claude account to start.</p>
            <div class="ca-api-input-row">
                <input type="password" id="caApiKey" placeholder="sk-ant-api03-..." value="" autocomplete="off">
                <button class="ca-btn ca-btn-primary" id="caTestConn">Connect</button>
            </div>
            <div id="caApiResult" class="ca-inline-fb" style="display:none;margin-top:10px"></div>
            <p style="font-size:12px;color:var(--ca-text3,#2A3550);margin-top:10px">Get your key at <a href="https://console.anthropic.com/settings/keys" target="_blank" style="color:var(--ca-accent,#4F7EFF)">console.anthropic.com</a></p>
        </div>
        <?php endif; ?>
        <script>
        jQuery('#wpilotDisconnect').on('click', function(){
            if(!confirm('Disconnect Claude from this site?')) return;
            jQuery.post(ajaxurl,{action:'wpilot_disconnect',nonce:CA.nonce},function(){location.reload();});
        });
        </script>
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
                ['Plugin','WPilot v'.CA_VERSION],
                ['Developer','Weblease'],
                ['Powered by','Claude'],
                ['Connection','Claude Desktop via WPilot Connector'],
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
        case 'lifetime': $badge = ['🏆 Lifetime Access', '#f59e0b', '1 site, unlimited forever, locked to domain']; break;
        case 'team':     $badge = ['⚡ Team',             '#8b5cf6', '3 sites (1 license each), unlimited, locked to domain']; break;
        case 'pro':      $badge = ['✅ Pro',               '#10b981', 'Unlimited prompts, 1 site, locked to domain']; break;
        default:         $badge = ['🆓 Free',             '#6b7280', $used.' / '.CA_FREE_LIMIT.' free prompts used']; break;
    }
    ?>

    <div class="ca-card" style="margin-bottom:24px;border-color:<?= $badge[1] ?>;padding:20px 24px">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div>
                <div style="font-size:20px;font-weight:900;color:<?= $badge[1] ?>;margin-bottom:4px"><?= $badge[0] ?></div>
                <div style="font-size:13px;color:var(--ca-text2)"><?= esc_html($badge[2]) ?></div>
            </div>
            <?php if($type === 'pro' || $type === 'team'): ?>
            <a href="https://weblease.se/wpilot-account" target="_blank" class="ca-btn ca-btn-ghost ca-btn-sm">Manage subscription →</a>
            <?php endif; ?>
        </div>
        <?php if($type === 'free'): ?>
        <div style="margin-top:12px;background:var(--ca-bg);border-radius:6px;overflow:hidden;height:6px">
            <div style="height:100%;background:#4f80f7;width:<?= min(100,round($used/CA_FREE_LIMIT*100)) ?>%"></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if($type !== 'lifetime' && $type !== 'pro' && $type !== 'team'): ?>

    <!-- Pricing cards — checkout happens via Stripe redirect -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:28px">

        <!-- Pro card -->
        <div class="ca-card" style="border-color:#10b981;position:relative">
            <div style="position:absolute;top:0;right:0;background:#10b981;color:#000;font-size:10px;font-weight:800;padding:3px 10px;border-radius:0 12px 0 8px">MOST POPULAR</div>
            <div style="font-size:22px;font-weight:900;color:#10b981;margin-bottom:2px">$9<span style="font-size:13px;font-weight:400">/mo</span></div>
            <div style="font-size:12px;color:var(--ca-text3);margin-bottom:12px">7-day free trial</div>
            <div style="font-size:15px;font-weight:800;margin-bottom:8px">⚡ Pro</div>
            <ul style="font-size:12.5px;color:var(--ca-text2);padding:0;margin:0 0 16px;list-style:none">
                <li style="padding:3px 0">✓ Unlimited prompts</li>
                <li style="padding:3px 0">✓ All features included</li>
                <li style="padding:3px 0">✓ Brain AI memory</li>
                <li style="padding:3px 0">✓ Priority support</li>
            </ul>
            <button onclick="wpiStartCheckout('pro')" class="ca-btn ca-btn-primary" style="width:100%;text-align:center">
                Start free trial →
            </button>
        </div>

        <!-- Team card -->
        <div class="ca-card" style="border-color:#8b5cf6">
            <div style="font-size:22px;font-weight:900;color:#8b5cf6;margin-bottom:2px">$24<span style="font-size:13px;font-weight:400">/mo</span></div>
            <div style="font-size:12px;color:var(--ca-text3);margin-bottom:12px">3 sites included</div>
            <div style="font-size:15px;font-weight:800;margin-bottom:8px">👥 Team</div>
            <ul style="font-size:12.5px;color:var(--ca-text2);padding:0;margin:0 0 16px;list-style:none">
                <li style="padding:3px 0">✓ 3 sites (1 license each)</li>
                <li style="padding:3px 0">✓ Unlimited prompts</li>
                <li style="padding:3px 0">✓ All features included</li>
                <li style="padding:3px 0">✓ Priority support</li>
            </ul>
            <button onclick="wpiStartCheckout('team')" class="ca-btn" style="width:100%;text-align:center;background:#8b5cf6;border-color:#8b5cf6;color:#fff">
                Start Team →
            </button>
        </div>

        <?php // Lifetime card — only if slots remain
        if($slots > 0): ?>
        <div class="ca-card" style="border-color:#f59e0b;position:relative;overflow:hidden">
            <div style="position:absolute;top:0;right:0;background:#f59e0b;color:#000;font-size:10px;font-weight:800;padding:3px 10px;border-radius:0 12px 0 8px">🔥 <?= $slots ?> left</div>
            <div style="font-size:22px;font-weight:900;color:#f59e0b;margin-bottom:2px">$149</div>
            <div style="font-size:12px;color:var(--ca-text3);margin-bottom:12px">one-time payment</div>
            <div style="font-size:15px;font-weight:800;margin-bottom:8px">🏆 Lifetime</div>
            <ul style="font-size:12.5px;color:var(--ca-text2);padding:0;margin:0 0 16px;list-style:none">
                <li style="padding:3px 0">✓ Pay once, own forever</li>
                <li style="padding:3px 0">✓ All features included</li>
                <li style="padding:3px 0">✓ All future updates</li>
                <li style="padding:3px 0">✓ Only <?= $slots ?> slots remaining</li>
            </ul>
            <button onclick="wpiStartCheckout('lifetime')" class="ca-btn ca-btn-primary" style="width:100%;text-align:center;background:#f59e0b;border-color:#f59e0b;color:#000">
                Buy Lifetime →
            </button>
        </div>
        <?php endif; ?>

    </div>

    <div id="wpiCheckoutMsg" style="display:none;text-align:center;padding:16px;font-size:14px;color:var(--ca-text2)">
        Redirecting to secure checkout…
    </div>

    <?php endif; ?>

    <!-- License key input (for Pro/Team/Lifetime) -->
    <?php if($type !== 'lifetime'): ?>
    <div class="ca-card">
        <div class="ca-section-lbl">Activate with license key</div>
        <p style="font-size:13px;color:var(--ca-text2);margin:0 0 12px">
            You receive your key via email after purchase. Format: CA-XXXX-XXXX-XXXX
        </p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <input type="text" id="wpiLicenseKey" placeholder="CA-XXXX-XXXX-XXXX"
                   value="<?= esc_attr($key) ?>"
                   style="flex:1;min-width:200px;background:var(--ca-bg);border:1px solid var(--ca-border);border-radius:8px;padding:10px 14px;color:var(--ca-text);font-size:13.5px;font-family:monospace">
            <button class="ca-btn ca-btn-primary" onclick="wpiActivateLicense()">Activate</button>
            <?php if($key): ?>
            <button class="ca-btn ca-btn-ghost ca-btn-sm" onclick="wpiDeactivateLicense()" style="color:#ef4444;border-color:#ef4444">Deactivate</button>
            <?php endif; ?>
        </div>
        <div id="wpiLicenseMsg" style="margin-top:10px;font-size:13px"></div>
    </div>
    <?php endif; ?>
// Built by Weblease

    <script>
    function wpiActivateLicense() {
        var key = document.getElementById('wpiLicenseKey').value.trim();
        var msg = document.getElementById('wpiLicenseMsg');
        if (!key) { msg.textContent = 'Enter your license key.'; return; }
        msg.textContent = 'Verifying…';
        jQuery.post(ajaxurl, {action:'ca_activate_license',nonce:CA.nonce,key:key}, function(r) {
            if (r.success) { msg.style.color='#10b981'; msg.textContent='✅ '+r.data.message; setTimeout(()=>location.reload(),1500); }
            else { msg.style.color='#ef4444'; msg.textContent='❌ '+(r.data||'Invalid key'); }
        });
    }
    function wpiStartCheckout(plan) {
        var msg = document.getElementById('wpiCheckoutMsg');
        if (msg) { msg.style.display='block'; msg.textContent='Redirecting to secure checkout…'; }
        jQuery.post(ajaxurl, {action:'wpi_create_checkout', nonce:CA.nonce, plan:plan}, function(r) {
            if (r.success && r.data.checkout_url) {
                window.open(r.data.checkout_url, '_blank');
                if (msg) {
                    msg.textContent = 'Checkout opened in new tab.';
                    var btn = document.createElement('button');
                    btn.className = 'ca-btn ca-btn-primary ca-btn-sm';
                    btn.style.marginTop = '10px';
                    btn.textContent = 'I completed payment — activate my license';
                    btn.onclick = wpiCheckPayment;
                    msg.appendChild(document.createElement('br'));
                    msg.appendChild(btn);
                }
            } else {
                if (msg) { msg.style.color='#ef4444'; msg.textContent='Error: '+(r.data||'Could not create checkout. Try again.'); }
            }
        });
    }
    function wpiCheckPayment() {
        var msg = document.getElementById('wpiCheckoutMsg');
        if (msg) msg.textContent = 'Checking payment status…';
        jQuery.post(ajaxurl, {action:'wpi_check_payment_status', nonce:CA.nonce}, function(r) {
            if (r.success && r.data.activated) {
                if (msg) { msg.style.color='#10b981'; msg.textContent='✅ '+r.data.message; }
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                if (msg) msg.textContent = r.data.message || 'Payment not processed yet. Wait a moment and try again.';
            }
        });
    }
    function wpiDeactivateLicense() {
        if (!confirm('Deactivate the license on this site?')) return;
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
    echo '<div class="ca-page"><p>This feature now works through MCP. Use Claude Code.</p></div>';
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
    echo '<div class="ca-page"><p>This feature now works through MCP. Use Claude Code.</p></div>';
}
// ═══════════════════════════════════════════════════════════════
//  AI TRAINING PAGE
// ═══════════════════════════════════════════════════════════════
function wpilot_page_training() {
    echo '<div class="ca-page"><p>This feature now works through MCP. Use Claude Code.</p></div>';
}

// ══════════════════════════════════════════════════════════════
// FEEDBACK
// ══════════════════════════════════════════════════════════════
function wpilot_page_feedback() {
    $feedbacks = get_option('wpilot_feedbacks', []);
    $admin_email = get_option('admin_email');
    ob_start(); ?>

    <div class="ca-grid ca-grid-2" style="gap:24px;">

      <!-- Submit Feedback -->
      <div class="ca-card" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:16px;padding:28px;">
        <h3 style="margin:0 0 20px;font-size:17px;font-weight:600;color:#EEF2FF;">Send Feedback</h3>

        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:13px;color:#8B9DC3;margin-bottom:6px;">Type</label>
          <select id="wpi-fb-type" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:#EEF2FF;font-size:14px;">
            <option value="feedback">General Feedback</option>
            <option value="bug">Bug Report</option>
            <option value="feature">Feature Request</option>
            <option value="praise">Praise</option>
          </select>
        </div>

        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:13px;color:#8B9DC3;margin-bottom:6px;">Rating</label>
          <div id="wpi-fb-stars" style="display:flex;gap:6px;cursor:pointer;">
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <span class="wpi-star" data-val="<?= $i ?>" style="font-size:28px;color:rgba(255,255,255,0.15);transition:color .15s;">&#9733;</span>
            <?php endfor; ?>
          </div>
          <input type="hidden" id="wpi-fb-rating" value="0">
        </div>

        <div style="margin-bottom:16px;">
          <label style="display:block;font-size:13px;color:#8B9DC3;margin-bottom:6px;">Message</label>
          <textarea id="wpi-fb-message" rows="5" placeholder="Tell us what you think..." style="width:100%;padding:12px 14px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:#EEF2FF;font-size:14px;resize:vertical;font-family:inherit;"></textarea>
        </div>

        <div style="margin-bottom:20px;">
          <label style="display:block;font-size:13px;color:#8B9DC3;margin-bottom:6px;">Email (for replies)</label>
          <input type="email" id="wpi-fb-email" value="<?= esc_attr($admin_email) ?>" style="width:100%;padding:10px 14px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:#EEF2FF;font-size:14px;">
        </div>

        <button id="wpi-fb-submit" class="ca-btn ca-btn-primary" style="width:100%;padding:12px;font-size:15px;font-weight:600;border-radius:10px;">
          Send Feedback
        </button>
        <div id="wpi-fb-status" style="margin-top:12px;font-size:13px;text-align:center;"></div>
      </div>

      <!-- Previous Feedbacks -->
      <div class="ca-card" style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.08);border-radius:16px;padding:28px;">
        <h3 style="margin:0 0 20px;font-size:17px;font-weight:600;color:#EEF2FF;">Your Feedbacks</h3>
        <div id="wpi-fb-list" style="display:flex;flex-direction:column;gap:14px;max-height:520px;overflow-y:auto;">
          <?php if (empty($feedbacks)): ?>
            <p style="color:#5E6E91;font-size:14px;">No feedback sent yet.</p>
          <?php else:
            $sorted = $feedbacks;
            uasort($sorted, fn($a,$b) => strtotime($b['date']) - strtotime($a['date']));
            $type_colors = ['bug'=>'#EF4444','feature'=>'#3B82F6','feedback'=>'#6B7280','praise'=>'#22C55E'];
            $type_labels = ['bug'=>'Bug','feature'=>'Feature','feedback'=>'Feedback','praise'=>'Praise'];
            foreach ($sorted as $fid => $fb):
              $tc = $type_colors[$fb['type']] ?? '#6B7280';
              $tl = $type_labels[$fb['type']] ?? ucfirst($fb['type']);
          ?>
            <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:12px;padding:16px;">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600;color:<?= $tc ?>;background:<?= $tc ?>15;border:1px solid <?= $tc ?>30;"><?= esc_html($tl) ?></span>
                <?php if ($fb['rating'] > 0): ?>
                  <span style="color:#FBBF24;font-size:13px;"><?= str_repeat('&#9733;', $fb['rating']) ?><?= str_repeat('&#9734;', 5 - $fb['rating']) ?></span>
                <?php endif; ?>
                <span style="margin-left:auto;color:#5E6E91;font-size:12px;"><?= esc_html($fb['date']) ?></span>
              </div>
              <p style="color:#C8D4E6;font-size:14px;margin:0 0 4px;line-height:1.5;"><?= esc_html($fb['message']) ?></p>
              <?php if (!empty($fb['reply'])): ?>
                <div style="margin-top:10px;padding:12px;background:rgba(79,126,255,0.08);border:1px solid rgba(79,126,255,0.2);border-radius:10px;">
                  <p style="font-size:11px;font-weight:600;color:#4F7EFF;margin:0 0 4px;">Reply from WPilot Team</p>
                  <p style="color:#A8BFEE;font-size:13px;margin:0;line-height:1.5;"><?= esc_html($fb['reply']) ?></p>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </div>

    <script>
    jQuery(function($) {
        var nonce = typeof CA !== 'undefined' ? CA.nonce : '<?= wp_create_nonce('ca_nonce') ?>';
        var ajaxurl = typeof CA !== 'undefined' ? CA.ajax_url : ajaxurl;
        var rating = 0;

        // Star rating
        $('#wpi-fb-stars .wpi-star').on('mouseenter', function() {
            var v = $(this).data('val');
            $('#wpi-fb-stars .wpi-star').each(function() {
                $(this).css('color', $(this).data('val') <= v ? '#FBBF24' : 'rgba(255,255,255,0.15)');
            });
        }).on('mouseleave', function() {
            $('#wpi-fb-stars .wpi-star').each(function() {
                $(this).css('color', $(this).data('val') <= rating ? '#FBBF24' : 'rgba(255,255,255,0.15)');
            });
        }).on('click', function() {
            rating = $(this).data('val');
            $('#wpi-fb-rating').val(rating);
            $('#wpi-fb-stars .wpi-star').each(function() {
                $(this).css('color', $(this).data('val') <= rating ? '#FBBF24' : 'rgba(255,255,255,0.15)');
            });
        });

        // Submit feedback
        // Built by Weblease
        $('#wpi-fb-submit').on('click', function() {
            var $btn = $(this);
            var msg = $('#wpi-fb-message').val().trim();
            if (!msg) { $('#wpi-fb-status').css('color','#EF4444').text('Please enter a message.'); return; }
            $btn.prop('disabled', true).text('Sending...');
            $.post(ajaxurl, {
                action: 'wpi_send_feedback',
                nonce: nonce,
                type: $('#wpi-fb-type').val(),
                message: msg,
                rating: rating,
                email: $('#wpi-fb-email').val()
            }, function(r) {
                $btn.prop('disabled', false).text('Send Feedback');
                if (r.success) {
                    $('#wpi-fb-status').css('color','#22C55E').text(r.data.message);
                    $('#wpi-fb-message').val('');
                    rating = 0;
                    $('#wpi-fb-rating').val(0);
                    $('#wpi-fb-stars .wpi-star').css('color','rgba(255,255,255,0.15)');
                    // Reload after short delay to show new feedback
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    $('#wpi-fb-status').css('color','#EF4444').text(r.data || 'Failed to send.');
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Send Feedback');
                $('#wpi-fb-status').css('color','#EF4444').text('Network error.');
            });
        });
    });
    </script>
    <?php
    wpilot_wrap('Feedback', '&#128172;', ob_get_clean());
}
