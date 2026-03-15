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
    // Built by Christos Ferlachidis & Daniel Hedenberg
    $saved_email = get_option('wpilot_user_email', '');
    $consent = get_option('wpi_data_consent', 'no');
    ?>
    <div id="aibWizard" class="aib-wiz-overlay">
      <div class="aib-wiz-box">

        <!-- Progress dots -->
        <div class="aib-wiz-progress">
          <?php for($i=1;$i<=6;$i++): ?>
          <div class="aib-wiz-dot <?= $i<=$step?'active':'' ?> <?= $i<$step?'done':'' ?>">
            <?= $i < $step ? '✓' : $i ?>
          </div>
          <?php if($i<6): ?><div class="aib-wiz-line <?= $i<$step?'active':'' ?>"></div><?php endif; ?>
          <?php endfor; ?>
        </div>

        <!-- ── Step 1: Welcome ──────────────────────────────── -->
        <div class="aib-wiz-step <?= $step===1?'active':'' ?>" data-step="1">
          <div class="aib-wiz-icon">⚡</div>
          <h2>Welcome to WPilot</h2>
          <p>Your AI WordPress co-developer. We'll connect Claude AI to your site and run a full analysis — takes 3 minutes.</p>
          <div class="aib-wiz-features">
            <?php foreach([
              ['🔍','Instant site analysis','AI scans SEO, plugins, design & content'],
              ['🏗️','Build & improve','Create pages, fix CSS, improve copy'],
              ['🧠','Gets smarter','Learns your site over time'],
              ['↩️','Always safe','Every change is reversible'],
            ] as [$i,$t,$d]): ?>
            <div class="aib-wiz-feat">
              <span><?=$i?></span>
              <div><strong><?=esc_html($t)?></strong><br><small><?=esc_html($d)?></small></div>
            </div>
            <?php endforeach; ?>
          </div>
          <button class="aib-wiz-btn" data-next="2">Get Started →</button>
        </div>

        <!-- ── Step 2: Connect WPilot Account ─────────────── -->
        <div class="aib-wiz-step <?= $step===2?'active':'' ?>" data-step="2">
          <div class="aib-wiz-icon">👤</div>
          <h2>Connect your WPilot account</h2>
          <p>Enter your email to connect your WPilot license. This is used for your license, support, and updates.</p>

          <label class="aib-wiz-label">Your email address:</label>
          <input type="email" id="aibUserEmail" placeholder="you@example.com"
            value="<?= esc_attr($saved_email) ?>"
            class="aib-wiz-input">
          <button class="aib-wiz-btn" id="aibConnectEmail" style="margin-top:12px">Connect →</button>
          <div id="aibEmailMsg" style="margin-top:10px;font-size:13px;min-height:20px;text-align:center"></div>
        </div>

        <!-- ── Step 3: API Key ──────────────────────────────── -->
        <div class="aib-wiz-step <?= $step===3?'active':'' ?>" data-step="3">
          <div class="aib-wiz-icon">🔑</div>
          <h2>Connect Claude AI</h2>
          <p>Paste your Claude API key. It takes 3 minutes to get one — follow the steps below.</p>

          <div class="aib-wiz-warn">
            ⚠️ <strong>Have claude.ai?</strong> That's not enough. API keys are a separate system at <strong>console.anthropic.com</strong>
          </div>

          <div class="aib-wiz-steps-box">
            <div class="aib-wiz-steps-title">How to get your key (3 min):</div>
            <?php foreach([
              ["1","Go to console.anthropic.com","https://console.anthropic.com"],
              ["2","Create account with email + password",""],
              ["3","Click API Keys → Create Key","https://console.anthropic.com/settings/keys"],
              ["4","Name it \"WPilot\" and copy it",""],
              ["5","Go to Billing → add $5 credit","https://console.anthropic.com/settings/billing"],
            ] as [$n,$t,$u]): ?>
            <div class="aib-wiz-step-row">
              <span class="aib-wiz-num"><?= $n ?></span>
              <span><?= $t ?></span>
              <?php if($u): ?><a href="<?= $u ?>" target="_blank" rel="noopener" class="aib-wiz-link-sm">Open →</a><?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>

          <label class="aib-wiz-label">Paste your API key:</label>
          <input type="password" id="aibApiKeyInput" placeholder="sk-ant-api03-..."
            class="aib-wiz-input">
          <button class="aib-wiz-btn" id="aibTestKey" style="margin-top:12px">Test & Connect ⚡</button>
          <div id="aibKeyMsg" style="margin-top:10px;font-size:13px;min-height:20px;text-align:center"></div>
          <div style="margin-top:10px;font-size:12px;color:#64748b;text-align:center">🔒 Key stored on your server only — never shared with Weblease</div>
        </div>

        <!-- ── Step 4: Data Consent (GDPR) ────────────────── -->
        <div class="aib-wiz-step <?= $step===4?'active':'' ?>" data-step="4">
          <div class="aib-wiz-icon">🛡️</div>
          <h2>Help improve WPilot</h2>
          <p>WPilot can send anonymized usage data to help train our AI model. No personal data, URLs, emails, or site names are ever shared.</p>

          <div class="aib-wiz-steps-box" style="margin-bottom:10px">
            <div class="aib-wiz-steps-title">What IS shared (only if you agree):</div>
            <div class="aib-wiz-step-row"><span style="color:#0FBD81;flex-shrink:0">✓</span> <span>Anonymized question/answer pairs (rated 4-5 stars only)</span></div>
            <div class="aib-wiz-step-row"><span style="color:#0FBD81;flex-shrink:0">✓</span> <span>WordPress version, active plugin slugs, theme name</span></div>
          </div>

          <div class="aib-wiz-steps-box" style="margin-bottom:16px">
            <div class="aib-wiz-steps-title">What is NOT shared:</div>
            <div class="aib-wiz-step-row"><span style="color:#EF4444;flex-shrink:0">✕</span> <span>Your API key, site URL, email, IP, content, passwords</span></div>
          </div>

          <div class="aib-wiz-consent-toggle" style="display:flex;align-items:center;gap:12px;padding:14px;background:#0B0E18;border:1px solid rgba(255,255,255,.06);border-radius:10px;margin-bottom:12px;cursor:pointer" id="aibConsentRow">
            <div class="aib-toggle <?= $consent==='yes'?'on':'' ?>" id="aibConsentToggle">
              <div class="aib-toggle-knob"></div>
            </div>
            <span style="font-size:13.5px;color:#C8D0E8;font-weight:600">I agree to share anonymized data</span>
          </div>

          <div style="text-align:center;margin-bottom:16px">
            <a href="https://weblease.se/privacy" target="_blank" rel="noopener" style="font-size:12px;color:#4F7EFF;text-decoration:none">Read our privacy policy →</a>
          </div>

          <div style="display:flex;flex-direction:column;gap:8px">
            <button class="aib-wiz-btn" id="aibConsentYes">Yes, help improve WPilot →</button>
            <button class="aib-wiz-btn" id="aibConsentSkip" style="background:transparent;border:1px solid rgba(255,255,255,.1);color:#64748b">No thanks</button>
          </div>
        </div>

        <!-- ── Step 5: Analyzing ────────────────────────────── -->
        <div class="aib-wiz-step <?= $step===5?'active':'' ?>" data-step="5">
          <div class="aib-wiz-icon" id="aibAnalysisIcon">🔍</div>
          <h2 id="aibAnalysisTitle">Analyzing <em><?= esc_html($site) ?></em>…</h2>
          <p id="aibAnalysisSub">Claude is scanning your site — SEO, plugins, design, content. This takes about 30 seconds.</p>

          <!-- Animated progress bar -->
          <div class="aib-wiz-progress-bar">
            <div class="aib-wiz-progress-fill" id="aibProgressFill"></div>
          </div>
          <div class="aib-wiz-scanning-steps" id="aibScanSteps">
            <div class="aib-scan-step active" id="scan1">📊 Reading pages & posts…</div>
            <div class="aib-scan-step" id="scan2">🔌 Checking plugins…</div>
            <div class="aib-scan-step" id="scan3">📈 Analyzing SEO data…</div>
            <div class="aib-scan-step" id="scan4">🎨 Reviewing design & structure…</div>
            <div class="aib-scan-step" id="scan5">⚡ Generating action plan…</div>
          </div>

          <!-- Result box (hidden until done) -->
          <div id="aibAnalysisResult" style="display:none">
            <div class="aib-wiz-result-box" id="aibResultText"></div>
            <button class="aib-wiz-btn" id="aibToStep6">Continue →</button>
          </div>
        </div>

        <!-- ── Step 6: Done ─────────────────────────────────── -->
        <div class="aib-wiz-step <?= $step===6?'active':'' ?>" data-step="6">
          <div class="aib-wiz-icon">🎉</div>
          <h2>You're set up!</h2>
          <p>WPilot is ready. Your analysis is saved — open the Chat to start acting on the results.</p>
          <div class="aib-wiz-tips">
            <div>🔍 Your full site analysis is ready in <strong>Analyze</strong></div>
            <div>💬 Chat with Claude about your site at any time</div>
            <div>↩️ Every change Claude makes can be undone in <strong>Restore History</strong></div>
            <div>⚡ AI bubble works on every page of your WordPress admin</div>
          </div>
          <div class="aib-wiz-license-info">
            Your license: <strong>Free plan</strong> — 20 prompts. <a href="https://weblease.se/wpilot-account" target="_blank" rel="noopener">Upgrade at weblease.se/wpilot-account</a>
          </div>
          <div style="display:flex;flex-direction:column;gap:8px">
            <button class="aib-wiz-btn" id="aibGoToChat">💬 Open Chat →</button>
            <button class="aib-wiz-btn" style="background:transparent;border:1px solid rgba(255,255,255,.1);color:#64748b" id="aibWizDone">Go to Dashboard</button>
          </div>
        </div>

        <button class="aib-wiz-close" id="aibWizClose" title="Skip setup">✕</button>
      </div>
    </div>

    <style>
    .aib-wiz-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(6px)}
    .aib-wiz-box{background:#07090F;border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:36px 40px;max-width:540px;width:calc(100% - 32px);position:relative;box-shadow:0 24px 80px rgba(0,0,0,.6),0 0 0 1px rgba(79,126,255,.06)}
    .aib-wiz-progress{display:flex;align-items:center;justify-content:center;margin-bottom:28px;gap:0}
    .aib-wiz-dot{width:28px;height:28px;border-radius:50%;background:#0F1320;border:2px solid #1A2035;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#2A3550;transition:all .3s}
    .aib-wiz-dot.active{background:#4F7EFF;border-color:#4F7EFF;color:#fff;box-shadow:0 0 12px rgba(79,126,255,.4)}
    .aib-wiz-dot.done{background:#0FBD81;border-color:#0FBD81;color:#fff}
    .aib-wiz-line{width:28px;height:2px;background:#1A2035;transition:background .3s}
    .aib-wiz-line.active{background:#0FBD81}
    .aib-wiz-step{display:none}.aib-wiz-step.active{display:block;animation:aibFadeIn .25s ease}
    @keyframes aibFadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
    .aib-wiz-icon{font-size:42px;margin-bottom:12px}
    .aib-wiz-box h2{font-size:22px;font-weight:800;color:#EEF2FF;margin:0 0 8px;letter-spacing:-.02em}
    .aib-wiz-box h2 em{font-style:normal;color:#4F7EFF}
    .aib-wiz-box p{font-size:13.5px;color:#5E6E91;margin:0 0 18px;line-height:1.65}
    .aib-wiz-features{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-bottom:22px}
    .aib-wiz-feat{display:flex;gap:10px;align-items:flex-start;background:#0B0E18;border:1px solid rgba(255,255,255,.06);border-radius:9px;padding:12px}
    .aib-wiz-feat span{font-size:18px;flex-shrink:0;margin-top:1px}
    .aib-wiz-feat strong{font-size:12.5px;color:#C8D0E8;display:block;margin-bottom:2px}
    .aib-wiz-feat small{font-size:11.5px;color:#3A4A68}
    .aib-wiz-btn{width:100%;padding:13px;background:linear-gradient(135deg,#4F7EFF,#6B4FFC);color:#fff;font-weight:800;font-size:14.5px;border:none;border-radius:10px;cursor:pointer;transition:opacity .2s;font-family:inherit}
    .aib-wiz-btn:hover{opacity:.88}
    .aib-wiz-btn:disabled{opacity:.4;cursor:not-allowed}
    .aib-wiz-warn{background:rgba(245,158,11,.07);border:1px solid rgba(245,158,11,.2);border-radius:9px;padding:12px 14px;font-size:13px;color:#FCD34D;margin-bottom:14px;line-height:1.6}
    .aib-wiz-steps-box{background:#0B0E18;border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:14px 16px;margin-bottom:14px}
    .aib-wiz-steps-title{font-size:10.5px;font-weight:800;color:#2A3550;text-transform:uppercase;letter-spacing:.07em;margin-bottom:10px}
    .aib-wiz-step-row{display:flex;align-items:center;gap:10px;padding:5px 0;font-size:13px;color:#5E6E91;border-bottom:1px solid rgba(255,255,255,.04)}
    .aib-wiz-step-row:last-child{border-bottom:none}
    .aib-wiz-num{flex-shrink:0;width:20px;height:20px;background:#4F7EFF;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;color:#fff}
    .aib-wiz-link-sm{font-size:11px;color:#4F7EFF;text-decoration:none;margin-left:auto;flex-shrink:0}
    .aib-wiz-label{font-size:11.5px;font-weight:700;color:#2A3550;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:.05em}
    .aib-wiz-input{width:100%;background:#0B0E18;border:1px solid rgba(255,255,255,.08);border-radius:9px;padding:11px 14px;color:#EEF2FF;font-size:13.5px;font-family:monospace;box-sizing:border-box;outline:none;transition:border-color .15s}
    .aib-wiz-input:focus{border-color:#4F7EFF;box-shadow:0 0 0 3px rgba(79,126,255,.1)}
    /* Toggle switch */
    .aib-toggle{width:42px;height:24px;background:#1A2035;border-radius:12px;position:relative;transition:background .2s;flex-shrink:0;cursor:pointer}
    .aib-toggle.on{background:#0FBD81}
    .aib-toggle-knob{width:18px;height:18px;background:#fff;border-radius:50%;position:absolute;top:3px;left:3px;transition:transform .2s}
    .aib-toggle.on .aib-toggle-knob{transform:translateX(18px)}
    /* Progress bar */
    .aib-wiz-progress-bar{height:5px;background:#0F1320;border-radius:3px;overflow:hidden;margin:16px 0}
    .aib-wiz-progress-fill{height:100%;background:linear-gradient(90deg,#4F7EFF,#6B4FFC);border-radius:3px;width:0%;transition:width .6s ease}
    /* Scan steps */
    .aib-wiz-scanning-steps{display:flex;flex-direction:column;gap:6px;margin-bottom:16px}
    .aib-scan-step{font-size:13px;color:#2A3550;padding:8px 12px;border-radius:7px;border:1px solid transparent;transition:all .3s}
    .aib-scan-step.active{color:#93B4FF;background:rgba(79,126,255,.08);border-color:rgba(79,126,255,.15)}
    .aib-scan-step.done{color:#0FBD81}
    .aib-scan-step.done::before{content:'✓ '}
    /* Result box */
    .aib-wiz-result-box{background:#0B0E18;border:1px solid rgba(79,126,255,.15);border-radius:10px;padding:16px;margin-bottom:14px;font-size:13px;color:#8899BB;line-height:1.75;max-height:220px;overflow-y:auto;white-space:pre-wrap}
    /* Tips */
    .aib-wiz-tips{background:#0B0E18;border:1px solid rgba(255,255,255,.06);border-radius:10px;padding:16px;margin-bottom:12px;display:flex;flex-direction:column;gap:9px;font-size:13px;color:#5E6E91;line-height:1.6}
    .aib-wiz-tips strong{color:#93B4FF}
    /* License info */
    .aib-wiz-license-info{background:rgba(79,126,255,.06);border:1px solid rgba(79,126,255,.15);border-radius:9px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#5E6E91;text-align:center;line-height:1.6}
    .aib-wiz-license-info strong{color:#EEF2FF}
    .aib-wiz-license-info a{color:#4F7EFF;text-decoration:none}
    .aib-wiz-license-info a:hover{text-decoration:underline}
    .aib-wiz-close{position:absolute;top:14px;right:16px;background:none;border:none;color:#2A3550;font-size:18px;cursor:pointer;line-height:1;transition:color .15s}
    .aib-wiz-close:hover{color:#5E6E91}
    </style>

    <script>
    jQuery(function($){
      var nonce  = (typeof CA!=='undefined') ? CA.nonce : '';
      var step   = <?= $step ?>;
      var chatUrl = '<?= esc_url(admin_url('admin.php?page='.CA_SLUG.'-chat')) ?>';
      var dashUrl = '<?= esc_url(admin_url('admin.php?page='.CA_SLUG)) ?>';
      var siteUrl = '<?= esc_url($url) ?>';
      var siteName = '<?= esc_js($site) ?>';

      function goStep(n){
        step = n;
        $('.aib-wiz-step').removeClass('active');
        $('[data-step="'+n+'"]').addClass('active');
        $('.aib-wiz-dot').each(function(i){
          $(this).toggleClass('active', i+1<=n).toggleClass('done', i+1<n);
        });
        $('.aib-wiz-line').each(function(i){ $(this).toggleClass('active', i+1<n); });
        $.post(ajaxurl,{action:'wpi_set_onboarding_step',nonce:nonce,step:n});
      }

      $('[data-next]').on('click',function(){ goStep(parseInt($(this).data('next'))); });

      // ── Step 2: Connect email ───────────────────────────────
      $('#aibConnectEmail').on('click', function(){
        var email = $('#aibUserEmail').val().trim();
        var $msg = $('#aibEmailMsg');
        if(!email || email.indexOf('@') === -1){
          $msg.html('<span style="color:#F87171">Please enter a valid email address.</span>');
          return;
        }
        $(this).text('Connecting…').prop('disabled',true);
        $msg.html('<span style="color:#5E6E91">Saving your email…</span>');

        // Save email to wp_option
        $.post(ajaxurl, {action:'wpi_save_user_email',nonce:nonce,email:email}, function(r){
          if(r.success){
            // Send activation to weblease.se — auto-creates account + free license
            $.ajax({
              url: 'https://weblease.se/plugin/activate',
              method: 'POST',
              contentType: 'application/json',
              data: JSON.stringify({
                email: email,
                site_url: siteUrl,
                plugin_version: (typeof CA!=='undefined') ? CA.version : '1.1.0',
                wp_version: '<?= esc_js(get_bloginfo("version")) ?>',
                php_version: '<?= esc_js(PHP_VERSION) ?>'
              }),
              success: function(resp){
                // Save license key if server returned one
                if(resp && resp.license_key){
                  $.post(ajaxurl, {action:'wpi_save_license_from_server', nonce:nonce, key:resp.license_key});
                }
                var newUser = (resp && resp.new_user) ? ' Account created — check your email!' : '';
                $msg.text('✅ Connected!' + newUser + ' Moving to next step…');
                $msg.css('color', '#2EE89B');
                setTimeout(function(){ goStep(3); }, 1000);
              },
              error: function(){
                // Still proceed even if remote call fails
                $msg.text('✅ Email saved! Moving to next step…');
                $msg.css('color', '#2EE89B');
                setTimeout(function(){ goStep(3); }, 700);
              }
            });
          } else {
            $msg.html('<span style="color:#F87171">❌ Could not save email. Try again.</span>');
            $('#aibConnectEmail').text('Connect →').prop('disabled',false);
          }
        }).fail(function(){
          $msg.html('<span style="color:#F87171">❌ Network error. Try again.</span>');
          $('#aibConnectEmail').text('Connect →').prop('disabled',false);
        });
      });

      // ── Step 3: Test & save API key ─────────────────────────
      $('#aibTestKey').on('click', function(){
        var key = $('#aibApiKeyInput').val().trim();
        var $msg = $('#aibKeyMsg');
        if(!key){ $msg.html('<span style="color:#F87171">Please paste your API key first.</span>'); return; }
        $(this).text('Testing…').prop('disabled',true);
        $msg.html('<span style="color:#5E6E91">Connecting to Claude…</span>');
        $.post(ajaxurl,{action:'ca_test_connection',nonce:nonce,key:key}, function(r){
          if(r.success){
            $msg.html('<span style="color:#2EE89B">✅ Connected! Next: privacy settings…</span>');
            setTimeout(function(){ goStep(4); }, 700);
          } else {
            $msg.html('<span style="color:#F87171">❌ '+(r.data||'Invalid key — check console.anthropic.com')+'</span>');
            $('#aibTestKey').text('Try Again').prop('disabled',false);
          }
        });
      });

      // ── Step 4: Data consent ────────────────────────────────
      var consentOn = false;
      $('#aibConsentRow, #aibConsentToggle').on('click', function(e){
        e.stopPropagation();
        consentOn = !consentOn;
        $('#aibConsentToggle').toggleClass('on', consentOn);
      });

      $('#aibConsentYes').on('click', function(){
        // Set consent to yes (toggle state) and proceed
        $.post(ajaxurl, {action:'wpi_set_consent', nonce:nonce, consent: consentOn ? 'yes' : 'no'});
        goStep(5);
        startAnalysis();
      });

      $('#aibConsentSkip').on('click', function(){
        // Keep consent as no and proceed
        $.post(ajaxurl, {action:'wpi_set_consent', nonce:nonce, consent:'no'});
        goStep(5);
        startAnalysis();
      });

      // ── Auto-run site analysis on step 5 ───────────────────
      function startAnalysis(){
        var pct = 0;
        var steps = ['scan1','scan2','scan3','scan4','scan5'];
        var interval = setInterval(function(){
          pct = Math.min(pct + (pct < 80 ? 3 : 1), 95);
          $('#aibProgressFill').css('width', pct+'%');
        }, 400);

        // Animate scan steps
        var si = 0;
        function nextScanStep(){
          if(si > 0) $('#'+steps[si-1]).removeClass('active').addClass('done');
          if(si < steps.length) { $('#'+steps[si]).addClass('active'); si++; }
        }
        nextScanStep();
        var scanTimer = setInterval(function(){
          nextScanStep();
          if(si >= steps.length) clearInterval(scanTimer);
        }, 5000);

        // Call the same analyze AJAX as the Analyze page
        $.post(ajaxurl, {
          action : 'wpi_smart_scan',
          nonce  : nonce
        }, function(r){
          clearInterval(interval);
          clearInterval(scanTimer);
          // Mark all steps done
          $.each(steps,function(i,s){ $('#'+s).removeClass('active').addClass('done'); });
          $('#aibProgressFill').css('width','100%');

          setTimeout(function(){
            var text = r.success ? (r.data.scan || r.data.text || r.data.result || r.data || 'Analysis complete!') : 'Could not connect to Claude. Check your API key and credits, then try again from the Analyze page.';
            if(typeof text === 'object') text = JSON.stringify(text);

            $('#aibAnalysisIcon').text('✅');
            $('#aibAnalysisTitle').html('Analysis complete!');
            $('#aibAnalysisSub').text('Here\'s what Claude found on your site:');
            $('#aibResultText').text(text);
            $('#aibAnalysisResult').fadeIn(300);
          }, 400);
        }).fail(function(){
          clearInterval(interval);
          $('#aibProgressFill').css('width','100%');
          $('#aibAnalysisIcon').text('✅');
          $('#aibAnalysisTitle').text('Connected!');
          $('#aibAnalysisSub').text('Claude is ready. Run a full analysis from the Analyze page anytime.');
          $('#aibResultText').text('Analysis can be run from the Analyze page. Click Continue to get started.');
          $('#aibAnalysisResult').fadeIn(300);
        });
      }

      // If we reload on step 5 (e.g. page refresh), auto-start
      if(step === 5){
        setTimeout(startAnalysis, 500);
      }

      $('#aibToStep6').on('click', function(){ goStep(6); });

      // Done buttons
      $('#aibGoToChat').on('click', function(){
        $.post(ajaxurl,{action:'wpi_complete_onboarding',nonce:nonce});
        $('#aibWizard').fadeOut(300);
        window.location.href = chatUrl;
      });
      $('#aibWizDone').on('click', function(){
        $.post(ajaxurl,{action:'wpi_complete_onboarding',nonce:nonce});
        $('#aibWizard').fadeOut(300, function(){$(this).remove();});
      });
      $('#aibWizClose').on('click', function(){
        $.post(ajaxurl,{action:'wpi_complete_onboarding',nonce:nonce});
        $('#aibWizard').fadeOut(200, function(){$(this).remove();});
      });
    });
    </script>
    <?php
});

// AJAX: save step
add_action('wp_ajax_wpi_set_onboarding_step', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    update_option('wpi_onboarding_step', (int)($_POST['step'] ?? 1));
    if(!empty($_POST['first_action'])) update_option('wpi_first_action', sanitize_text_field($_POST['first_action']));
    wp_send_json_success();
});

// AJAX: save user email
add_action('wp_ajax_wpi_save_user_email', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    $email = sanitize_email($_POST['email'] ?? '');
    if(!is_email($email)) wp_send_json_error('Invalid email');
    update_option('wpilot_user_email', $email);
    wp_send_json_success();
});

// AJAX: save license key from server response
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

// AJAX: complete
add_action('wp_ajax_wpi_complete_onboarding', function(){
    check_ajax_referer('ca_nonce','nonce');
    if(!current_user_can('manage_options')) wp_send_json_error();
    update_option('wpi_onboarding_done','yes');
    wp_send_json_success();
});
