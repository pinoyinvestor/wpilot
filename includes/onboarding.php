<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function wpilot_onboarding_active() {
    return get_option('wpi_onboarding_done','no') !== 'yes';
}

add_action('admin_footer', function() {
    if ( !current_user_can('manage_options') ) return;
    if ( !wpilot_onboarding_active() ) return;
    if ( strpos($_GET['page'] ?? '', CA_SLUG) === false ) return;
    $step = (int) get_option('wpi_onboarding_step', 1);
    $site = get_bloginfo('name');
    $url  = get_site_url();
    $saved_email = get_option('wpilot_user_email', '');
    $consent = get_option('wpi_data_consent', 'no');
    // Built by Weblease
    $server_check = function_exists('wpilot_server_can_install') ? wpilot_server_can_install() : ['exec'=>false];
    $has_claude = !empty($server_check['claude_code']);
    $has_node = !empty($server_check['node']);
    $can_exec = !empty($server_check['exec']);
    ?>
    <div id="aibWizard" class="aib-wiz-overlay">
      <div class="aib-wiz-box">

        <div class="aib-wiz-progress">
          <?php for($i=1;$i<=5;$i++): ?>
          <div class="aib-wiz-dot <?= $i<=$step?'active':'' ?> <?= $i<$step?'done':'' ?>">
            <?= $i < $step ? '&#10003;' : $i ?>
          </div>
          <?php if($i<5): ?><div class="aib-wiz-line <?= $i<$step?'active':'' ?>"></div><?php endif; ?>
          <?php endfor; ?>
        </div>

        <!-- Step 1: Welcome -->
        <div class="aib-wiz-step <?= $step===1?'active':'' ?>" data-step="1">
          <div class="aib-wiz-icon" style="font-size:48px">&#9889;</div>
          <h2>Welcome to WPilot</h2>
          <p>AI assistant for your WordPress site. Design, build, fix SEO, manage WooCommerce — just chat.</p>
          <div class="aib-wiz-features">
            <?php foreach([
              ['&#128269;','Instant site scan','Full SEO, speed & design report'],
              ['&#128483;','Just chat','Describe what you want, AI does it'],
              ['&#129504;','Gets smarter','Learns your site preferences'],
              ['&#8617;','Always safe','Every change is reversible'],
            ] as [$i,$t,$d]): ?>
            <div class="aib-wiz-feat">
              <span><?=$i?></span>
              <div><strong><?=esc_html($t)?></strong><br><small><?=esc_html($d)?></small></div>
            </div>
            <?php endforeach; ?>
          </div>
          <button class="aib-wiz-btn" data-next="2">Get started</button>
        </div>

        <!-- Step 2: Email -->
        <div class="aib-wiz-step <?= $step===2?'active':'' ?>" data-step="2">
          <div class="aib-wiz-icon" style="font-size:48px">&#128231;</div>
          <h2>Your email</h2>
          <p>Used for your WPilot license, support, and updates.</p>
          <label class="aib-wiz-label">Email address</label>
          <input type="email" id="aibUserEmail" placeholder="you@example.com" value="<?= esc_attr($saved_email) ?>" class="aib-wiz-input">
          <button class="aib-wiz-btn" id="aibConnectEmail" style="margin-top:12px">Continue</button>
          <div id="aibEmailMsg" style="margin-top:10px;font-size:13px;min-height:20px;text-align:center"></div>
        </div>

        <!-- Step 3: Connect Claude -->
        <div class="aib-wiz-step <?= $step===3?'active':'' ?>" data-step="3">
          <div class="aib-wiz-icon" style="font-size:48px">&#128279;</div>
          <h2>Connect your Claude account</h2>
          <p>WPilot needs Claude AI to work. You need a Claude account from Anthropic.</p>

          <?php if ($has_claude): ?>
          <div style="background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.2);border-radius:10px;padding:16px;margin-bottom:16px;text-align:center">
            <div style="font-size:24px;margin-bottom:8px">&#10003;</div>
            <strong style="color:#10B981">Claude already installed on your server!</strong>
            <p style="font-size:12px;color:var(--ca-text2,#5E6E91);margin-top:6px">Just sign in with your Claude account to activate.</p>
          </div>
          <button class="aib-wiz-btn" id="aibSkipToConsent">Continue</button>
          <?php elseif ($can_exec && $has_node): ?>
          <div style="background:rgba(79,126,255,.08);border:1px solid rgba(79,126,255,.15);border-radius:10px;padding:16px;margin-bottom:16px">
            <strong style="color:var(--ca-accent,#4F7EFF)">One-click setup available</strong>
            <p style="font-size:12px;color:var(--ca-text2,#5E6E91);margin-top:6px">Your server supports automatic installation. We will set up Claude for you.</p>
          </div>
          <button class="aib-wiz-btn" id="aibAutoInstall">Set up Claude automatically</button>
          <div id="aibInstallStatus" style="margin-top:12px;font-size:13px;text-align:center;min-height:20px"></div>
          <div style="margin-top:12px;text-align:center">
            <button class="aib-wiz-btn" id="aibUseApiKey" style="background:transparent;border:1px solid rgba(255,255,255,.1);color:var(--ca-text2,#64748b);font-size:13px;padding:10px">Or connect with an API key instead</button>
          </div>
          <?php elseif ($can_exec): ?>
          <div style="background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);border-radius:10px;padding:16px;margin-bottom:16px">
            <strong style="color:#FCD34D">Node.js needed</strong>
            <p style="font-size:12px;color:var(--ca-text2,#5E6E91);margin-top:6px">We can install everything for you. This takes about 1 minute.</p>
          </div>
          <button class="aib-wiz-btn" id="aibInstallAll">Install everything automatically</button>
          <div id="aibInstallStatus" style="margin-top:12px;font-size:13px;text-align:center;min-height:20px"></div>
          <?php else: ?>
          <div style="margin-bottom:16px">
            <p style="font-size:13px;color:var(--ca-text2,#5E6E91)">Your hosting doesn't support automatic setup. Sign in with your Claude account from <a href="https://anthropic.com" target="_blank" style="color:var(--ca-accent,#4F7EFF)">console.anthropic.com</a> instead:</p>
          </div>
          <label class="aib-wiz-label">Claude Account</label>
          <input type="password" id="aibApiKeyInput" placeholder="sk-ant-api03-..." class="aib-wiz-input">
          <button class="aib-wiz-btn" id="aibTestKey" style="margin-top:12px">Connect</button>
          <div id="aibKeyMsg" style="margin-top:10px;font-size:13px;min-height:20px;text-align:center"></div>
          <?php endif; ?>

          <div id="aibApiKeyFallback" style="display:none;margin-top:16px">
            <label class="aib-wiz-label">Claude Account</label>
            <input type="password" id="aibApiKeyInput2" placeholder="sk-ant-api03-..." class="aib-wiz-input">
            <button class="aib-wiz-btn" id="aibTestKey2" style="margin-top:12px">Connect</button>
            <div id="aibKeyMsg2" style="margin-top:10px;font-size:13px;min-height:20px;text-align:center"></div>
          </div>
        </div>

        <!-- Step 4: GDPR -->
        <div class="aib-wiz-step <?= $step===4?'active':'' ?>" data-step="4">
          <div class="aib-wiz-icon" style="font-size:48px">&#128737;</div>
          <h2>Privacy (GDPR)</h2>
          <p>WPilot can share anonymized data to improve the AI. Completely optional.</p>

          <div class="aib-wiz-steps-box" style="margin-bottom:10px">
            <div class="aib-wiz-steps-title">Shared (if you agree):</div>
            <div class="aib-wiz-step-row"><span style="color:#0FBD81;flex-shrink:0">&#10003;</span> <span>Anonymized tool usage data</span></div>
            <div class="aib-wiz-step-row"><span style="color:#0FBD81;flex-shrink:0">&#10003;</span> <span>WordPress/PHP version info</span></div>
          </div>
          <div class="aib-wiz-steps-box" style="margin-bottom:16px">
            <div class="aib-wiz-steps-title">Never shared:</div>
            <div class="aib-wiz-step-row"><span style="color:#EF4444;flex-shrink:0">&#10007;</span> <span>Your site URL, email, content, Claude accounts</span></div>
          </div>

          <div style="display:flex;flex-direction:column;gap:8px">
            <button class="aib-wiz-btn" id="aibConsentYes">I agree</button>
            <button class="aib-wiz-btn" id="aibConsentSkip" style="background:transparent;border:1px solid rgba(255,255,255,.1);color:var(--ca-text2,#64748b)">No thanks</button>
          </div>
        </div>

        <!-- Step 5: Done -->
        <div class="aib-wiz-step <?= $step===5?'active':'' ?>" data-step="5">
          <div class="aib-wiz-icon" style="font-size:48px">&#127881;</div>
          <h2>You're ready!</h2>
          <p>WPilot is set up. Open the chat bubble and start building.</p>
          <div class="aib-wiz-tips">
            <div>&#128172; Click the chat bubble (bottom right) to talk to AI</div>
            <div>&#128269; Go to Analyze for a full site report</div>
            <div>&#8617; Every AI change can be undone in History</div>
          </div>
          <button class="aib-wiz-btn" id="aibWizDone">Start using WPilot</button>
        </div>

        <button class="aib-wiz-close" id="aibWizClose" title="Skip">&#10005;</button>
      </div>
    </div>

    <script>
    jQuery(function($){
      var nonce = (typeof CA!=='undefined') ? CA.nonce : '';
      var step = <?= $step ?>;
      var dashUrl = '<?= esc_url(admin_url('admin.php?page='.CA_SLUG)) ?>';

      function goStep(n){
        step = n;
        $('.aib-wiz-step').removeClass('active');
        $('[data-step="'+n+'"]').addClass('active');
        $('.aib-wiz-dot').each(function(i){ $(this).toggleClass('active',i+1<=n).toggleClass('done',i+1<n); });
        $('.aib-wiz-line').each(function(i){ $(this).toggleClass('active',i+1<n); });
        $.post(ajaxurl,{action:'wpi_set_onboarding_step',nonce:nonce,step:n});
      }

      $('[data-next]').on('click',function(){ goStep(parseInt($(this).data('next'))); });

      // Step 2: Email
      $('#aibConnectEmail').on('click', function(){
        var email = $('#aibUserEmail').val().trim();
        if(!email || email.indexOf('@')===-1){ $('#aibEmailMsg').html('<span style="color:#F87171">Enter a valid email</span>'); return; }
        $(this).text('Saving...').prop('disabled',true);
        $.post(ajaxurl,{action:'wpi_save_user_email',nonce:nonce,email:email},function(r){
          if(r.success){
            $.ajax({url:'https://weblease.se/plugin/activate',method:'POST',contentType:'application/json',
              data:JSON.stringify({email:email,site_url:'<?= esc_js($url) ?>',plugin_version:typeof CA!=='undefined'?CA.version:'2.6.1',wp_version:'<?= esc_js(get_bloginfo("version")) ?>'}),
              success:function(resp){ if(resp&&resp.license_key) $.post(ajaxurl,{action:'wpi_save_license_from_server',nonce:nonce,key:resp.license_key}); },
              complete:function(){ goStep(3); }
            });
          } else { $('#aibEmailMsg').html('<span style="color:#F87171">Error saving email</span>'); $('#aibConnectEmail').text('Continue').prop('disabled',false); }
        });
      });

      // Step 3: Auto-install
      $('#aibAutoInstall, #aibInstallAll').on('click', function(){
        var $btn = $(this); var $status = $('#aibInstallStatus');
        $btn.text('Installing...').prop('disabled',true);
        var needsNode = $(this).attr('id') === 'aibInstallAll';

        function doStep(step, msg, next) {
          $status.html('<span style="color:#93B4FF">' + msg + '</span>');
          $.post(ajaxurl,{action:'wpilot_auto_install',nonce:nonce,step:step},function(r){
            if(r.success) { next(); }
            else { $status.html('<span style="color:#F87171">Failed: '+step+'</span>'); $btn.text('Retry').prop('disabled',false); }
          }).fail(function(){ $status.html('<span style="color:#F87171">Connection error</span>'); $btn.text('Retry').prop('disabled',false); });
        }

        if(needsNode) {
          doStep('install_node','Installing Node.js...',function(){
            doStep('install_claude','Setting up Claude...',function(){
              doStep('setup_mcp','Connecting to your site...',function(){
                $status.html('<span style="color:#10B981">Done! Claude connected.</span>');
                setTimeout(function(){ goStep(4); },1000);
              });
            });
          });
        } else {
          doStep('install_claude','Setting up Claude...',function(){
            doStep('setup_mcp','Connecting to your site...',function(){
              $status.html('<span style="color:#10B981">Done!</span>');
              setTimeout(function(){ goStep(4); },1000);
            });
          });
        }
      });

      // Skip to consent if already installed
      $('#aibSkipToConsent').on('click', function(){ goStep(4); });

      // Claude account fallback
      $('#aibUseApiKey').on('click', function(){ $('#aibApiKeyFallback').show(); $(this).hide(); });

      // Test Claude account (both inputs)
      $('#aibTestKey, #aibTestKey2').on('click', function(){
        var $input = $(this).siblings('input[type=password]');
        var $msg = $(this).siblings('[id^=aibKeyMsg]');
        var key = $input.val().trim();
        if(!key){ $msg.html('<span style="color:#F87171">Sign in with your Claude account</span>'); return; }
        $(this).text('Testing...').prop('disabled',true);
        var $btn = $(this);
        $.post(ajaxurl,{action:'ca_test_connection',nonce:nonce,key:key},function(r){
          if(r.success){ $msg.html('<span style="color:#10B981">Connected!</span>'); setTimeout(function(){ goStep(4); },700); }
          else { $msg.html('<span style="color:#F87171">'+(r.data||'Invalid key')+'</span>'); $btn.text('Try again').prop('disabled',false); }
        });
      });

      // Step 4: Consent
      $('#aibConsentYes').on('click', function(){
        $.post(ajaxurl,{action:'wpi_set_consent',nonce:nonce,consent:'yes'},function(){ goStep(5); });
      });
      $('#aibConsentSkip').on('click', function(){
        $.post(ajaxurl,{action:'wpi_set_consent',nonce:nonce,consent:'no'},function(){ goStep(5); });
      });

      // Step 5: Done
      $('#aibWizDone').on('click', function(){
        $.post(ajaxurl,{action:'wpi_complete_onboarding',nonce:nonce});
        $('#aibWizard').fadeOut(300,function(){$(this).remove();});
      });
      $('#aibWizClose').on('click', function(){
        $.post(ajaxurl,{action:'wpi_complete_onboarding',nonce:nonce});
        $('#aibWizard').fadeOut(200,function(){$(this).remove();});
      });
    });

    <style>
    .aib-wiz-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
    .aib-wiz-box{background:#07090F;border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:36px 40px;max-width:540px;width:calc(100% - 32px);position:relative;box-shadow:0 24px 80px rgba(0,0,0,.6)}
    .aib-wiz-progress{display:flex;align-items:center;justify-content:center;margin-bottom:28px;gap:0}
    .aib-wiz-dot{width:28px;height:28px;border-radius:50%;background:#0F1320;border:2px solid #1A2035;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#2A3550;transition:all .3s}
    .aib-wiz-dot.active{background:#4F7EFF;border-color:#4F7EFF;color:#fff;box-shadow:0 0 12px rgba(79,126,255,.4)}
    .aib-wiz-dot.done{background:#0FBD81;border-color:#0FBD81;color:#fff}
    .aib-wiz-line{width:28px;height:2px;background:#1A2035;transition:background .3s}
    .aib-wiz-line.active{background:#0FBD81}
    .aib-wiz-step{display:none}.aib-wiz-step.active{display:block;animation:aibFadeIn .25s ease}
    @keyframes aibFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
    .aib-wiz-icon{margin-bottom:12px}
    .aib-wiz-box h2{font-size:22px;font-weight:800;color:#EEF2FF;margin:0 0 8px}
    .aib-wiz-box p{font-size:13.5px;color:#5E6E91;margin:0 0 18px;line-height:1.65}
    .aib-wiz-features{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:22px}
    .aib-wiz-feat{display:flex;gap:10px;align-items:flex-start;background:#0B0E18;border:1px solid rgba(255,255,255,.06);border-radius:9px;padding:12px}
    .aib-wiz-feat span{font-size:18px;flex-shrink:0}
    .aib-wiz-feat strong{font-size:12.5px;color:#C8D0E8;display:block;margin-bottom:2px}
    .aib-wiz-feat small{font-size:11.5px;color:#3A4A68}
    .aib-wiz-btn{width:100%;padding:13px;background:linear-gradient(135deg,#4F7EFF,#6B4FFC);color:#fff;font-weight:800;font-size:14.5px;border:none;border-radius:10px;cursor:pointer;transition:opacity .2s;font-family:inherit}
    .aib-wiz-btn:hover{opacity:.88}
    .aib-wiz-btn:disabled{opacity:.4;cursor:not-allowed}
    .aib-wiz-steps-box{background:#0B0E18;border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:14px 16px;margin-bottom:14px}
    .aib-wiz-steps-title{font-size:10.5px;font-weight:800;color:#2A3550;text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px}
    .aib-wiz-step-row{display:flex;align-items:center;gap:10px;padding:5px 0;font-size:13px;color:#5E6E91;border-bottom:1px solid rgba(255,255,255,.04)}
    .aib-wiz-step-row:last-child{border-bottom:none}
    .aib-wiz-label{font-size:11.5px;font-weight:700;color:#2A3550;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
    .aib-wiz-input{width:100%;background:#0B0E18;border:1px solid rgba(255,255,255,.08);border-radius:9px;padding:11px 14px;color:#EEF2FF;font-size:13.5px;font-family:monospace;box-sizing:border-box;outline:none}
    .aib-wiz-input:focus{border-color:#4F7EFF;box-shadow:0 0 0 3px rgba(79,126,255,.1)}
    .aib-wiz-tips{background:#0B0E18;border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:16px;margin-bottom:16px;display:flex;flex-direction:column;gap:9px;font-size:13px;color:#5E6E91}
    .aib-wiz-close{position:absolute;top:14px;right:16px;background:none;border:none;color:#2A3550;font-size:18px;cursor:pointer}
    .aib-wiz-close:hover{color:#5E6E91}
    </style>
    </script>
    <?php
});

// AJAX handlers
add_action('wp_ajax_wpi_set_onboarding_step', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    update_option('wpi_onboarding_step', (int)($_POST['step'] ?? 1));
    wp_send_json_success();
});

add_action('wp_ajax_wpi_save_user_email', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    $email = sanitize_email($_POST['email'] ?? '');
    if(!is_email($email)) wp_send_json_error('Invalid email');
    update_option('wpilot_user_email', $email);
    wp_send_json_success();
});

add_action('wp_ajax_wpi_save_license_from_server', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    $key = sanitize_text_field($_POST['key'] ?? '');
    if(!$key) wp_send_json_error('No key');
    update_option('ca_license_key', $key);
    update_option('ca_license_status', 'active');
    if(!wpilot_license_type()) update_option('wpilot_license_type', 'free');
    wp_send_json_success();
});

add_action('wp_ajax_wpi_complete_onboarding', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    update_option('wpi_onboarding_done','yes');
    wp_send_json_success();
});
