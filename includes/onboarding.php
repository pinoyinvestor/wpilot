<?php
/**
 * WPilot - AI Website Builder for WordPress
 * Copyright (c) 2026 Weblease. All rights reserved.
 *
 * This software is licensed, not sold. Unauthorized copying,
 * modification, or distribution is strictly prohibited.
 * License: https://weblease.se/terms
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Built by Weblease

function wpilot_onboarding_active() {
    return get_option( 'wpi_onboarding_done', 'no' ) !== 'yes';
}

// ── Redirect to WPilot on first activation ─────────────────────
add_action( 'admin_init', function() {
    if ( get_transient( 'wpilot_activation_redirect' ) ) {
        delete_transient( 'wpilot_activation_redirect' );
        if ( ! wp_doing_ajax() && ! isset( $_GET['activate-multi'] ) ) {
            wp_safe_redirect( admin_url( 'admin.php?page=' . CA_SLUG ) );
            exit;
        }
    }
} );

// ── Onboarding wizard ──────────────────────────────────────────
add_action( 'admin_footer', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( ! wpilot_onboarding_active() ) return;
    if ( strpos( $_GET['page'] ?? '', CA_SLUG ) === false ) return;

    $step = (int) get_option( 'wpi_onboarding_step', 1 );
    $saved_email = get_option( 'wpilot_user_email', '' );
    $site_url = get_site_url();
    $endpoint = $site_url . '/wp-json/wpilot/v1/mcp';
    ?>

    <style>
    .wpi-onb{position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(8px)}
    .wpi-onb-box{background:#07090F;border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:40px 44px;max-width:520px;width:calc(100% - 32px);position:relative;box-shadow:0 24px 80px rgba(0,0,0,.6)}
    .wpi-onb-step{display:none}.wpi-onb-step.active{display:block;animation:wpiIn .3s ease}
    @keyframes wpiIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
    .wpi-onb h2{font-size:24px;font-weight:800;color:#EEF2FF;margin:0 0 10px}
    .wpi-onb p{font-size:14px;color:#5E6E91;margin:0 0 20px;line-height:1.7}
    .wpi-onb-btn{width:100%;padding:14px;background:linear-gradient(135deg,#4F7EFF,#6B4FFC);color:#fff;font-weight:700;font-size:15px;border:none;border-radius:12px;cursor:pointer;font-family:inherit;transition:opacity .2s}
    .wpi-onb-btn:hover{opacity:.9}
    .wpi-onb-btn:disabled{opacity:.4;cursor:not-allowed}
    .wpi-onb-btn-ghost{width:100%;padding:11px;background:transparent;border:1px solid rgba(255,255,255,.1);color:#5E6E91;font-weight:600;font-size:13px;border-radius:12px;cursor:pointer;margin-top:8px;font-family:inherit}
    .wpi-onb-btn-ghost:hover{border-color:rgba(255,255,255,.2);color:#8898B9}
    .wpi-onb-input{width:100%;background:#0B0E18;border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:12px 16px;color:#EEF2FF;font-size:14px;box-sizing:border-box;outline:none;font-family:inherit}
    .wpi-onb-input:focus{border-color:#4F7EFF;box-shadow:0 0 0 3px rgba(79,126,255,.15)}
    .wpi-onb-label{font-size:12px;font-weight:700;color:#3A4A68;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:.5px}
    .wpi-onb-msg{margin-top:10px;font-size:13px;text-align:center;min-height:20px}
    .wpi-onb-close{position:absolute;top:16px;right:18px;background:none;border:none;color:#2A3550;font-size:20px;cursor:pointer;padding:4px}
    .wpi-onb-close:hover{color:#5E6E91}
    .wpi-onb-dots{display:flex;justify-content:center;gap:8px;margin-bottom:24px}
    .wpi-onb-dot{width:8px;height:8px;border-radius:50%;background:#1A2035;transition:all .3s}
    .wpi-onb-dot.active{background:#4F7EFF;width:24px;border-radius:4px}
    .wpi-onb-card{background:#0B0E18;border:1px solid rgba(255,255,255,.06);border-radius:12px;padding:16px;margin-bottom:12px}
    .wpi-onb-code{background:#050608;border:1px solid rgba(255,255,255,.08);border-radius:8px;padding:12px 16px;font-family:'JetBrains Mono',monospace;font-size:12px;color:#93B4FF;word-break:break-all;position:relative;line-height:1.6}
    .wpi-onb-copy{position:absolute;right:8px;top:8px;padding:4px 12px;background:rgba(79,126,255,.15);border:none;border-radius:6px;color:#4F7EFF;font-size:11px;font-weight:600;cursor:pointer}
    .wpi-onb-copy:hover{background:rgba(79,126,255,.25)}
    @media(max-width:480px){.wpi-onb-box{padding:28px 24px}.wpi-onb h2{font-size:20px}}
    </style>

    <div class="wpi-onb" id="wpiOnboard">
      <div class="wpi-onb-box">
        <div class="wpi-onb-dots">
          <?php for($i=1;$i<=3;$i++): ?>
          <div class="wpi-onb-dot <?= $i<=$step?'active':'' ?>" data-dot="<?=$i?>"></div>
          <?php endfor; ?>
        </div>

        <!-- STEP 1: Welcome -->
        <div class="wpi-onb-step <?= $step===1?'active':'' ?>" data-step="1">
          <div style="font-size:48px;margin-bottom:16px">&#9889;</div>
          <h2><?php echo wpilot_t('onb_welcome_title'); ?></h2>
          <p><?php echo wpilot_t('onb_welcome_text'); ?></p>

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:24px">
            <div class="wpi-onb-card" style="text-align:center">
              <div style="font-size:24px;margin-bottom:6px">&#128172;</div>
              <div style="font-size:12px;color:#C8D0E8;font-weight:600"><?php echo wpilot_t('onb_just_talk'); ?></div>
              <div style="font-size:11px;color:#3A4A68;margin-top:2px"><?php echo wpilot_t('onb_just_talk_sub'); ?></div>
            </div>
            <div class="wpi-onb-card" style="text-align:center">
              <div style="font-size:24px;margin-bottom:6px">&#128274;</div>
              <div style="font-size:12px;color:#C8D0E8;font-weight:600"><?php echo wpilot_t('onb_always_safe'); ?></div>
              <div style="font-size:11px;color:#3A4A68;margin-top:2px"><?php echo wpilot_t('onb_always_safe_sub'); ?></div>
            </div>
          </div>

          <button class="wpi-onb-btn" id="wpiNext1"><?php echo wpilot_t('onb_get_started'); ?></button>
        </div>

        <!-- STEP 2: Email + License -->
        <div class="wpi-onb-step <?= $step===2?'active':'' ?>" data-step="2">
          <div style="font-size:48px;margin-bottom:16px">&#128231;</div>
          <h2><?php echo wpilot_t('onb_email_title'); ?></h2>
          <p><?php echo wpilot_t('onb_email_text'); ?></p>

          <label class="wpi-onb-label">Email</label>
          <input type="email" id="wpiEmail" class="wpi-onb-input" placeholder="you@example.com" value="<?= esc_attr($saved_email) ?>">
          <button class="wpi-onb-btn" id="wpiSaveEmail" style="margin-top:14px"><?php echo wpilot_t('onb_email_btn'); ?></button>
          <button class="wpi-onb-btn-ghost" id="wpiBack1"><?php echo wpilot_t('onb_back'); ?></button>
          <div class="wpi-onb-msg" id="wpiEmailMsg"></div>
        </div>

        <!-- STEP 3: Connect + Done -->
        <div class="wpi-onb-step <?= $step===3?'active':'' ?>" data-step="3">
          <div style="font-size:48px;margin-bottom:16px">&#127881;</div>
          <h2><?php echo wpilot_t('onb_connect_title'); ?></h2>
          <p><?php echo wpilot_t('onb_connect_text'); ?></p>

          <div class="wpi-onb-card">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
              <span style="font-size:20px">&#128187;</span>
              <strong style="font-size:14px;color:#EEF2FF"><?php echo wpilot_t('onb_option_terminal'); ?></strong>
            </div>
            <p style="font-size:12px;color:#5E6E91;margin:0 0 10px"><?php echo wpilot_t('onb_run_command'); ?></p>
            <div class="wpi-onb-code" id="wpiTermCmd">
              claude mcp add --transport http wpilot <?= esc_html($endpoint) ?>
            </div>
            <p style="font-size:11px;color:#3A4A68;margin:8px 0 0">Don't have Claude Code? Run: <code style="color:#93B4FF">npm install -g @anthropic-ai/claude-code</code></p>
          </div>

          <div class="wpi-onb-card">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
              <span style="font-size:20px">&#128421;</span>
              <strong style="font-size:14px;color:#EEF2FF"><?php echo wpilot_t('onb_option_desktop'); ?></strong>
            </div>
            <ol style="font-size:12px;color:#5E6E91;margin:0;padding-left:20px;line-height:2">
              <li>Download <a href="https://claude.ai/download" target="_blank" style="color:#4F7EFF">Claude Desktop</a> (free)</li>
              <li>Open Settings &#8594; Connectors &#8594; Add custom connector</li>
              <li>Name: <strong style="color:#EEF2FF">WPilot</strong></li>
              <li>URL: <strong style="color:#93B4FF;word-break:break-all"><?= esc_html($endpoint) ?></strong></li>
            </ol>
          </div>

          <p style="font-size:12px;color:#3A4A68;text-align:center;margin:16px 0 8px">
            Need an API key? Go to <strong>WPilot &#8594; Claude Code</strong> after setup.
          </p>

          <button class="wpi-onb-btn" id="wpiFinish"><?php echo wpilot_t('onb_done_btn'); ?></button>
          <button class="wpi-onb-btn-ghost" id="wpiBack2"><?php echo wpilot_t('onb_back'); ?></button>
        </div>

        <button class="wpi-onb-close" id="wpiClose" title="Close">&#10005;</button>
      </div>
    </div>

    <script>
    (function($){
      var nonce = (typeof CA !== 'undefined') ? CA.nonce : '';
      var step = <?= $step ?>;

      function go(n) {
        step = n;
        $('.wpi-onb-step').removeClass('active');
        $('[data-step="'+n+'"]').addClass('active');
        $('.wpi-onb-dot').each(function(i){ $(this).toggleClass('active', i+1 === n); });
        $.post(ajaxurl, {action:'wpi_set_onboarding_step', nonce:nonce, step:n});
      }

      // Step 1 → 2
      $('#wpiNext1').on('click', function(){ go(2); });

      // Step 2: Save email
      $('#wpiSaveEmail').on('click', function(){
        var email = $('#wpiEmail').val().trim();
        if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/)) {
          $('#wpiEmailMsg').html('<span style="color:#F87171">Please enter a valid email</span>');
          return;
        }
        var $btn = $(this);
        $btn.text('<?php echo esc_js(wpilot_t("onb_creating")); ?>').prop('disabled', true);
        $.post(ajaxurl, {action:'wpi_save_user_email', nonce:nonce, email:email}, function(r){
          if (r.success) {
            // Activate license silently
            $.ajax({
              url: 'https://weblease.se/plugin/activate',
              method: 'POST',
              contentType: 'application/json',
              data: JSON.stringify({
                email: email,
                site_url: '<?= esc_js($site_url) ?>',
                plugin_version: typeof CA !== 'undefined' ? CA.version : '3.0.0',
                wp_version: '<?= esc_js(get_bloginfo("version")) ?>'
              }),
              success: function(resp) {
                if (resp && resp.license_key) {
                  $.post(ajaxurl, {action:'wpi_save_license_from_server', nonce:nonce, key:resp.license_key});
                }
              },
              complete: function(){ go(3); }
            });
          } else {
            $('#wpiEmailMsg').html('<span style="color:#F87171">Something went wrong. Try again.</span>');
            $btn.text('Create free account').prop('disabled', false);
          }
        });
      });

      // Back buttons
      $('#wpiBack1').on('click', function(){ go(1); });
      $('#wpiBack2').on('click', function(){ go(2); });

      // Finish
      $('#wpiFinish').on('click', function(){
        $.post(ajaxurl, {action:'wpi_complete_onboarding', nonce:nonce});
        $('#wpiOnboard').fadeOut(300, function(){ $(this).remove(); });
      });

      // Close = dismiss this session only
      $('#wpiClose').on('click', function(){
        $('#wpiOnboard').fadeOut(200, function(){ $(this).remove(); });
      });

      // Enter key on email input
      $('#wpiEmail').on('keydown', function(e){
        if (e.key === 'Enter') { e.preventDefault(); $('#wpiSaveEmail').click(); }
      });
    })(jQuery);
    </script>
    <?php
});

// ── AJAX handlers ──────────────────────────────────────────────
add_action('wp_ajax_wpi_set_onboarding_step', function(){
    check_ajax_referer('ca_nonce','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    update_option('wpi_onboarding_step', (int)($_POST['step'] ?? 1));
    wp_send_json_success();
});

add_action('wp_ajax_wpi_save_user_email', function(){
    check_ajax_referer('ca_nonce','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    $email = sanitize_email($_POST['email'] ?? '');
    if (!is_email($email)) wp_send_json_error('Invalid email');
    update_option('wpilot_user_email', $email);
    wp_send_json_success();
});

add_action('wp_ajax_wpi_save_license_from_server', function(){
    check_ajax_referer('ca_nonce','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    $key = sanitize_text_field($_POST['key'] ?? '');
    if (!$key) wp_send_json_error('No key');
    update_option('ca_license_key', $key);
    update_option('ca_license_status', 'active');
    if (!function_exists('wpilot_license_type') || !wpilot_license_type()) update_option('wpilot_license_type', 'free');
    wp_send_json_success();
});

add_action('wp_ajax_wpi_complete_onboarding', function(){
    check_ajax_referer('ca_nonce','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    update_option('wpi_onboarding_done', 'yes');
    update_option('ca_onboarded', 'yes');
    wp_send_json_success();
});

add_action('wp_ajax_wpi_set_consent', function(){
    check_ajax_referer('ca_nonce','nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    update_option('wpi_data_consent', sanitize_text_field($_POST['consent'] ?? 'no'));
    wp_send_json_success();
});
