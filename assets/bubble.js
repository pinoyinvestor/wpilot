/* global CA, jQuery */
(function($){
  'use strict';

  $(document).ready(function(){

    var $root    = $('#caRoot');
    var $trigger = $('#caTrigger');
    var $panel   = $('#caPanel');
    var $msgs    = $('#capMsgs');
    var $in      = $('#capIn');
    var $send    = $('#capSend');
    var open     = false;
    var sending  = false;
    var history  = [];
    var lastPairId = null;
    var lastBackupStack = [];

    if ( !$root.length || !$trigger.length ) return;
    if ( CA && CA.theme ) $root.attr('data-theme', CA.theme);

    /* ── Input row rendered by bubble.php — no JS duplication needed ── */

    /* ── Toggle ────────────────────────────────────────────── */
    var wpiScanned    = false;
    var historyLoaded = false;

    function loadChatHistory(cb){
      if (historyLoaded) { if(cb) cb(); return; }
      $.post(CA.ajax_url, {action:'wpi_load_history', nonce:CA.nonce}, function(r){
        historyLoaded = true;
        if (r && r.success && r.data && r.data.length) {
          // Remove welcome screen — we have history
          $('#capWelcome').remove();
          var msgs = r.data;
          for (var i = 0; i < msgs.length; i++) {
            var m = msgs[i];
            if (m.role === 'user') {
              appendMsg('user', m.content);
              history.push({role:'user', content:m.content});
            } else if (m.role === 'assistant') {
              appendMsg('ai', m.content, m.actions || null, m.source || null);
              history.push({role:'assistant', content:m.content});
              if (m.id) lastPairId = m.id;
            }
          }
          scrollBottom();
        }
        if(cb) cb();
      }).fail(function(){ historyLoaded = true; if(cb) cb(); });
    }

    function runSmartScan() {
      if (wpiScanned || CA.connected !== 'yes') return;
      wpiScanned = true;
      appendMsg('ai', '🔍 Scanning your WordPress site...');
      $.post(CA.ajax_url, {action:'wpi_smart_scan', nonce:CA.nonce}, function(r) {
        $msgs.find('.cap-m:last').remove();
        if (r.success) appendMsg('ai', r.data.scan);
        else appendMsg('ai', 'Hi! I\'m WPilot. Tell me what you need help with!');
        scrollBottom();
      });
    }

    function openPanel() {
      open = true;
      $panel.addClass('cap-visible').show();
      $trigger.find('.ca-t-idle').hide();
      $trigger.find('.ca-t-open').show();
      hideBadge();

      // Load history first, then smart scan
      loadChatHistory(function(){
        if (!wpiScanned && history.length === 0) {
          setTimeout(runSmartScan, 300);
        }
        if($in.length) setTimeout(function(){ $in.focus(); }, 80);
        scrollBottom();
      });
    }

    function closePanel() {
      open = false;
      $panel.removeClass('cap-visible').hide();
      $trigger.find('.ca-t-idle').show();
      $trigger.find('.ca-t-open').hide();
    }

    function togglePanel() { open ? closePanel() : openPanel(); }

    $trigger.on('click', function(e){ e.stopPropagation(); togglePanel(); });
    $('#capClose').on('click', function(e){ e.stopPropagation(); closePanel(); });

    // Alt+C shortcut
    $(document).on('keydown', function(e){
      if ( e.altKey && (e.key==='c'||e.key==='C') ) { e.preventDefault(); togglePanel(); }
    });
    // Escape closes
    $(document).on('keydown', function(e){
      if ( e.key==='Escape' && open ) closePanel();
    });
    // Click outside closes
    $(document).on('click', function(e){
      if ( open && !$root.is(e.target) && $root.has(e.target).length===0 ) closePanel();
    });
    $root.on('click', function(e){ e.stopPropagation(); });

    /* ── Connect API key directly from bubble ──────────── */
    $(document).on('click', '#capConnectBtn', function() {
        var $btn = $(this);
        var $input = $('#capApiKeyInput');
        var $status = $('#capConnectStatus');
        var key = $.trim($input.val());
        
        if (!key || !key.startsWith('sk-')) {
            $status.text('Enter a valid Claude API key (starts with sk-)').css('color', 'var(--ca-red)').show();
            return;
        }
        
        $btn.text('Connecting...').prop('disabled', true);
        $status.text('Testing connection...').css('color', 'var(--ca-text2)').show();
        
        $.post(CA.ajax_url, {
            action: 'ca_test_connection',
            nonce: CA.nonce,
            key: key
        })
        .done(function(res) {
            if (res && res.success) {
                $status.text('Connected! Reloading...').css('color', 'var(--ca-green)');
                CA.connected = 'yes';
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                var msg = (res && res.data) ? (typeof res.data === 'string' ? res.data : res.data.message || 'Connection failed') : 'Connection failed';
                $status.text(msg).css('color', 'var(--ca-red)');
                $btn.text('Connect & Start').prop('disabled', false);
            }
        })
        .fail(function() {
            $status.text('Network error. Try again.').css('color', 'var(--ca-red)');
            $btn.text('Connect & Start').prop('disabled', false);
        });
    });
    
    // Enter key on API input
    $(document).on('keydown', '#capApiKeyInput', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); $('#capConnectBtn').click(); }
    });

    /* ── Config panel ──────────────────────────────────────── */
    $('#capCfgBtn').on('click', function(e){
      e.stopPropagation();
      $('#capCfgPanel').slideToggle(140);
    });

    /* ── Clear chat button ──────────────────────────────── */
    $('#capClearChat').on('click', function(e){
      e.stopPropagation();
      $.post(CA.ajax_url, { action: 'ca_clear_history', nonce: CA.nonce });
      history = []; $msgs.empty();
      wpiScanned = false;
      appendMsg('ai', '🔍 Scanning your site...');
      $.post(CA.ajax_url, {action:'wpi_smart_scan', nonce:CA.nonce}, function(r) {
        $msgs.find('.cap-m:last').remove();
        if (r.success) appendMsg('ai', r.data.scan);
        else appendMsg('ai', 'Ready! Tell me what you need.');
        scrollBottom();
      });
      try { localStorage.setItem('wpi_chat_sync', Date.now().toString()); } catch(ex){}
    });

    /* ── Restart + re-analyze button ────────────────────── */
    $('#capRestartScan').on('click', function(e){
      e.stopPropagation();
      $.post(CA.ajax_url, { action: 'ca_clear_history', nonce: CA.nonce });
      history = []; $msgs.empty();
      wpiScanned = false;
      appendMsg('ai', '🔍 Scanning your WordPress site...');
      $.post(CA.ajax_url, {action:'wpi_smart_scan', nonce:CA.nonce}, function(r) {
        $msgs.find('.cap-m:last').remove();
        if (r.success) appendMsg('ai', r.data.scan);
        else appendMsg('ai', 'Ready! Tell me what you need.');
        scrollBottom();
      });
      try { localStorage.setItem('wpi_chat_sync', Date.now().toString()); } catch(ex){}
    });
    $('#capAutoApprove').on('change', function(){
      var val = $(this).is(':checked') ? 'yes' : 'no';
      $.post(CA.ajax_url, { action:'ca_save_setting', nonce:CA.nonce, key:'wpilot_auto_approve', value:val });
    });

    /* ── Badge ─────────────────────────────────────────────── */
    function showBadge(n){ $('#caBubbleBadge').text(n||'•').show(); }
    function hideBadge()  { $('#caBubbleBadge').hide(); }

    /* ── Quick chips ────────────────────────────────────────── */
    $(document).on('click', '.cap-chip', function(){
      var msg = $(this).data('msg');
      if(!msg) return;
      if($in.length){
        $in.val(msg);
        sendMsg();
      }
    });

    /* ── Input handlers ─────────────────────────────────────── */
    if($in.length){
      $in.on('keydown', function(e){
        if(e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendMsg(); }
        if(e.key==='Tab' && $in.val().startsWith('/')) { e.preventDefault(); aibCompleteSlash(); }
      });
      $in.on('input', function(){
        this.style.height='auto';
        this.style.height = Math.min(this.scrollHeight, 90)+'px';
        aibHandleSlash($(this).val());
        aibUpdateTokenEstimate($(this).val());
      });
    }
    if($send.length) $send.on('click', function(e){ e.preventDefault(); sendMsg(); });

    /* ── Slash command definitions ───────────────────────────── */
    var slashCmds = [
      {cmd:'/analyze',  label:'Analyze site',      mode:'analyze', msg:'Do a full site analysis covering SEO, design, content quality, plugins, and performance. Group findings by severity.'},
      {cmd:'/seo',      label:'SEO report',         mode:'seo',     msg:'Analyze SEO across all pages. List pages with missing meta descriptions, bad titles, thin content, and missing alt text. Prioritize fixes.'},
      {cmd:'/css',      label:'CSS improvements',   mode:'chat',    msg:'Review the current site design and suggest CSS improvements for typography, spacing, colors, and mobile responsiveness.'},
      {cmd:'/speed',    label:'Speed check',        mode:'chat',    msg:'Analyze this site for performance issues. Check caching, image optimization, plugin count, and scripts. Give a speed score and top 5 fixes.'},
      {cmd:'/security', label:'Security audit',     mode:'chat',    msg:'Run a WordPress security audit. Check for outdated plugins, admin user issues, SSL, login protection, and vulnerabilities.'},
      {cmd:'/woo',      label:'WooCommerce review', mode:'woo',     msg:'Review the WooCommerce store. Check product pages, checkout flow, missing descriptions, pricing, and conversion opportunities.'},
      {cmd:'/build',    label:'Build mode',         mode:'build',   msg:'I want to build a new page. Ask me what page I want to create and help me design it step by step.'},
      {cmd:'/plugins',  label:'Plugin audit',       mode:'plugins', msg:'Audit all installed plugins. Identify conflicts, overlap, outdated plugins, security risks, and performance impact.'},
      {cmd:'/scan',     label:'Scan my plugins', mode:'plugins', msg:'Scan all installed plugins. What works? What\'s missing? What can be optimized? Be clear about what\'s free and what costs money.'},
      {cmd:'/gratis',    label:'Free optimizations', mode:'chat',    msg:'Show me everything I can improve on my WordPress site using only free plugins and settings. No paid solutions.'},
      {cmd:'/settings', label:'Show settings',  mode:'chat', msg:'Show my current WPilot settings: theme, auto-approve status, and API connection status.'},
      {cmd:'/connect',  label:'Connect API key', mode:'chat', msg:'I need to connect my Claude API key.'},
      {cmd:'/help',     label:'Show all commands',  mode:'chat',    msg:'List all available slash commands and what each one does.'},
    ];

    var $slashMenu = null;

    function aibHandleSlash(val){
      if(!val.startsWith('/') || val.includes(' ')){
        if($slashMenu) { $slashMenu.remove(); $slashMenu=null; }
        return;
      }
      var matches = slashCmds.filter(function(c){ return c.cmd.startsWith(val.toLowerCase()); });
      if(!matches.length){ if($slashMenu){$slashMenu.remove();$slashMenu=null;} return; }

      if(!$slashMenu){
        $slashMenu = $('<div class="cap-slash-menu"></div>');
        var $footer = $panel.find('.cap-footer');
        $footer.before($slashMenu);
      }
      $slashMenu.empty();
      matches.forEach(function(c){
        var $item = $('<div class="cap-slash-item"><strong>'+esc(c.cmd)+'</strong><span>'+esc(c.label)+'</span></div>');
        $item.on('mousedown', function(e){
          e.preventDefault();
          aibFireSlash(c);
        });
        $slashMenu.append($item);
      });
    }

    function aibCompleteSlash(){
      var val = $in.val();
      var matches = slashCmds.filter(function(c){ return c.cmd.startsWith(val.toLowerCase()); });
      if(matches.length===1){ aibFireSlash(matches[0]); }
    }

    function aibFireSlash(cmd){
      if($slashMenu){$slashMenu.remove();$slashMenu=null;}
      $in.val('');
      // Handle clear chat
      if (cmd.msg === '__CLEAR__') {
        $.post(CA.ajax_url, { action: 'ca_clear_history', nonce: CA.nonce });
        history = []; $msgs.empty();
        wpiScanned = false;
        appendMsg('ai', '🗑️ Chat cleared. Type anything to start fresh.');
        try { localStorage.setItem('wpi_chat_sync', Date.now().toString()); } catch(e){}
        scrollBottom();
        return;
      }
      // Handle restart + re-analyze
      if (cmd.msg === '__RESTART__') {
        $.post(CA.ajax_url, { action: 'ca_clear_history', nonce: CA.nonce });
        history = []; $msgs.empty();
        wpiScanned = false;
        appendMsg('ai', '🔍 Scanning your WordPress site...');
        $.post(CA.ajax_url, {action:'wpi_smart_scan', nonce:CA.nonce}, function(r) {
          $msgs.find('.cap-m:last').remove();
          if (r.success) appendMsg('ai', r.data.scan);
          else appendMsg('ai', 'Ready! Tell me what you need.');
          scrollBottom();
        });
        try { localStorage.setItem('wpi_chat_sync', Date.now().toString()); } catch(e){}
        return;
      }
      sendMsgWithContent(cmd.msg, cmd.mode);
    }

    /* ── Token estimator ─────────────────────────────────────── */
    var $tokenHint = null;
    function aibUpdateTokenEstimate(val){
      if(!val || val.length < 30){ if($tokenHint) $tokenHint.hide(); return; }
      var words  = val.trim().split(/\s+/).length;
      var tokens = Math.round(words * 1.3 + 200);
      var cost   = (tokens / 1000000 * 3).toFixed(5);
      return; // disabled
    }

    /* ── Voice input ─────────────────────────────────────────── */
    var recognition = null;
    var $voiceBtn   = $panel.find('.cap-voice-btn');

    if('webkitSpeechRecognition' in window || 'SpeechRecognition' in window){
      var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      recognition = new SR();
      recognition.continuous    = false;
      recognition.interimResults = true;
      recognition.lang          = document.documentElement.lang || 'en-US';

      recognition.onstart = function(){ $voiceBtn.addClass('cap-voice-active').text('🔴'); };
      recognition.onend   = function(){ $voiceBtn.removeClass('cap-voice-active').text('🎤'); };
      recognition.onerror = function(){ $voiceBtn.removeClass('cap-voice-active').text('🎤'); };
      recognition.onresult = function(e){
        var transcript = '';
        for(var i=e.resultIndex;i<e.results.length;i++) transcript += e.results[i][0].transcript;
        $in.val(transcript).trigger('input');
        if(e.results[e.results.length-1].isFinal) sendMsg();
      };

      $voiceBtn.show().on('click', function(){
        if($voiceBtn.hasClass('cap-voice-active')) { recognition.stop(); }
        else { recognition.start(); }
      });
    } else {
      $voiceBtn.hide();
    }

    /* ── Export chat ──────────────────────────────────────────── */
    $(document).on('click','#capExportChat', function(){
      var lines = ['WPilot Chat Export', '========================', ''];
      $msgs.find('.cap-m').each(function(){
        var role = $(this).hasClass('cap-m-user') ? 'You' : 'AI';
        var text = $(this).find('.cap-mt').text().trim();
        if (text) {
          lines.push('['+role+'] ' + text);
          lines.push('');
        }
      });
      var blob = new Blob([lines.join('\n')], {type:'text/plain'});
      var url  = URL.createObjectURL(blob);
      var a    = document.createElement('a');
      a.href   = url;
      a.download = 'wpilot-chat-' + new Date().toISOString().slice(0,10) + '.txt';
      a.click();
      URL.revokeObjectURL(url);
    });

    /* ── localStorage sync bridge ────────────────────────────── */
    function syncToStorage(){
      try {
        localStorage.setItem('wpi_chat_sync', JSON.stringify({
          ts: Date.now(),
          count: history.length
        }));
      } catch(e){}
    }

    // Listen for changes from admin chat page or other tabs
    $(window).on('storage', function(e){
      var ev = e.originalEvent || e;
      if (ev.key === 'wpi_chat_sync' && ev.newValue) {
        // Another tab updated the chat — reload history
        historyLoaded = false;
        $msgs.find('.cap-m').remove();
        history = [];
        if (open) loadChatHistory();
      }
    });

    /* ── Custom instructions interceptor ─────────────────────── */
    var instructionPrefixes = ['remember:', 'always:', 'min regler:', 'min regel:'];

    function isCustomInstruction(msg){
      var lower = msg.toLowerCase().trim();
      for (var i = 0; i < instructionPrefixes.length; i++) {
        if (lower.startsWith(instructionPrefixes[i])) return true;
      }
      return false;
    }

    function extractInstruction(msg){
      var lower = msg.toLowerCase().trim();
      for (var i = 0; i < instructionPrefixes.length; i++) {
        if (lower.startsWith(instructionPrefixes[i])) {
          return msg.trim().substring(instructionPrefixes[i].length).trim();
        }
      }
      return msg;
    }

    function saveCustomInstruction(instruction, originalMsg){
      appendMsg('user', originalMsg);
      $in.val('').css('height','auto');

      // Append instruction via dedicated endpoint
      $.post(CA.ajax_url, {
        action: 'wpi_save_instruction',
        nonce:  CA.nonce,
        instruction: instruction
      }).done(function(r){
        if (r && r.success) {
          appendMsg('ai', '✅ **Saved!** Instruction added to your AI rules.\n\n> ' + instruction + '\n\nI will follow this in future responses.');
        } else {
          appendMsg('ai', '⚠️ Could not save instruction.');
        }
        scrollBottom();
      }).fail(function(){
        appendMsg('ai', '⚠️ Network error.');
        scrollBottom();
      });
    }

    /* ── Send message ────────────────────────────────────────── */
    function sendMsg(){
      if(!$in || !$in.length) return;
      var msg = $.trim($in.val());
      if(!msg) return;

      // Check for custom instruction prefix
      if (isCustomInstruction(msg)) {
        saveCustomInstruction(extractInstruction(msg), msg);
        return;
      }

      // Handle slash command typed in full
      var slash = slashCmds.find(function(c){ return c.cmd === msg.toLowerCase().trim(); });
      if(slash){ aibFireSlash(slash); return; }
      // Handle settings commands directly in chat
        var lmsg = msg.toLowerCase().trim();
        
        // API key change
        if (lmsg.startsWith('api key:') || lmsg.startsWith('api-key:') || lmsg.startsWith('sk-ant-')) {
            var newKey = msg.replace(/^(api[- ]?key:\s*)/i, '').trim();
            if (!newKey.startsWith('sk-')) newKey = msg.trim();
            appendMsg('user', 'Connecting API key...');
            $.post(CA.ajax_url, { action: 'ca_test_connection', nonce: CA.nonce, key: newKey })
            .done(function(r) {
                if (r && r.success) {
                    appendMsg('ai', '✅ Claude API key connected! Reloading...');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    appendMsg('ai', '❌ Invalid API key. Get one at console.anthropic.com');
                }
            });
            $in.val('');
            return;
        }
        
        // Theme change
        if (lmsg === 'dark mode' || lmsg === 'dark theme') {
            $.post(CA.ajax_url, { action: 'ca_save_setting', nonce: CA.nonce, key: 'wpilot_theme', value: 'dark' });
            $root.attr('data-theme', 'dark');
            appendMsg('user', msg);
            appendMsg('ai', '🌙 Dark mode activated.');
            $in.val('');
            return;
        }
        if (lmsg === 'light mode' || lmsg === 'light theme') {
            $.post(CA.ajax_url, { action: 'ca_save_setting', nonce: CA.nonce, key: 'wpilot_theme', value: 'light' });
            $root.attr('data-theme', 'light');
            appendMsg('user', msg);
            appendMsg('ai', '☀️ Light mode activated.');
            $in.val('');
            return;
        }
        
        // Auto-approve toggle
        if (lmsg === 'auto approve on' || lmsg === 'auto-approve on') {
            $.post(CA.ajax_url, { action: 'ca_save_setting', nonce: CA.nonce, key: 'wpilot_auto_approve', value: 'yes' });
            appendMsg('user', msg);
            appendMsg('ai', '✅ Auto-approve enabled. Changes will apply automatically without clicking Apply.');
            $in.val('');
            return;
        }
        if (lmsg === 'auto approve off' || lmsg === 'auto-approve off') {
            $.post(CA.ajax_url, { action: 'ca_save_setting', nonce: CA.nonce, key: 'wpilot_auto_approve', value: 'no' });
            appendMsg('user', msg);
            appendMsg('ai', '✅ Auto-approve disabled. You\'ll need to click Apply on each change.');
            $in.val('');
            return;
        }
        
        // Clear chat
        if (lmsg === 'clear chat' || lmsg === 'clear history' || lmsg === '/clear') {
            $.post(CA.ajax_url, { action: 'ca_clear_history', nonce: CA.nonce });
            history = [];
            $msgs.empty();
            appendMsg('ai', '🗑️ Chat cleared.');
            $in.val('');
            return;
        }

      sendMsgWithContent(msg, 'chat');
    }

    // Built by Christos Ferlachidis & Daniel Hedenberg

    function sendMsgWithContent(msg, mode){
      if(sending) return;
      if(!CA || CA.connected!=='yes'){
        appendMsg('ai','⚠️ Connect a Claude API key in Settings first.');
        return;
      }
      if(CA.locked==='yes'){
        appendMsg('ai','⚠️ Free limit reached. Upgrade to continue.');
        return;
      }
      if($slashMenu){$slashMenu.remove();$slashMenu=null;}
      if($tokenHint) $tokenHint.hide();

      $('#capWelcome').fadeOut(150, function(){ $(this).remove(); });

      appendMsg('user', msg);
      $in.val('').css('height','auto');
      if($send.length) $send.prop('disabled', true);
      $('#capTyping').show();
      scrollBottom();
      history.push({role:'user', content:msg});

      sending = true;
      $.post(CA.ajax_url, {
        action:  'ca_chat',
        nonce:   CA.nonce,
        message: msg,
        mode:    mode || 'chat',
        history: JSON.stringify(history.slice(-12)),
        context: JSON.stringify({
          page: CA.page_title || document.title || 'unknown',
          url: CA.current_url || window.location.href,
          post_id: CA.current_post_id || 0,
          post_type: CA.current_post_type || '',
          is_front_page: CA.is_front_page || 'no',
          site: {name: CA.site_name, url: CA.site_url}
        })
      })
      .done(function(res){
        sending = false;
        $('#capTyping').hide();
        if($send.length) $send.prop('disabled', false);

        if(!res || !res.success){
          var errMsg = (res && res.data) ? res.data : 'Something went wrong. Please try again.';
          if(errMsg && (errMsg.toLowerCase().indexOf('credit') !== -1 || errMsg.toLowerCase().indexOf('balance') !== -1 || errMsg.toLowerCase().indexOf('billing') !== -1)){
            errMsg = 'Your Claude API account needs credits. Add them at console.anthropic.com/settings/billing';
          }
          appendMsg('ai', '⚠️ ' + errMsg);
          scrollBottom();
          return;
        }

        var d = res.data;
        // Store pair ID for training signals
        if (d.memory_id) lastPairId = d.memory_id;

        appendMsg('ai', d.response, d.actions, d.source);
        history.push({role:'assistant', content:d.response});

        // Sync to other tabs
        syncToStorage();

        if(d.locked){ CA.locked='yes'; }
        if(!open) showBadge('!');
        scrollBottom();
      })
      .fail(function(xhr){
        sending = false;
        $('#capTyping').hide();
        if($send.length) $send.prop('disabled', false);
        var msg = '⚠️ Request failed.';
        if(xhr.status===0) msg = '⚠️ No connection. Check your internet.';
        if(xhr.status===401) msg = '⚠️ API key invalid or expired. Check Settings.';
        if(xhr.status===403) msg = '⚠️ Permission denied. Admin or editor role required.';
        if(xhr.status===429) msg = '⚠️ Rate limited. Wait a moment and try again.';
        appendMsg('ai', msg);
        scrollBottom();
      });
    }

    /* ── Append message ─────────────────────────────────────── */
    function appendMsg(role, text, actions, source){
      var clean = (text||'').replace(/\[ACTION:[^\]]+\]/g, '').trim();
      var html  = mdToHtml(clean);
      var sourceBadge = '';
      if(role==='ai' && source && source !== 'claude'){
        var badgeLabel = source==='brain' ? '🧠 Brain' : '⚡ WPilot AI';
        var badgeColor = source==='brain' ? 'rgba(16,185,129,.15)' : 'rgba(79,128,247,.15)';
        sourceBadge = '<span class="cap-source-badge" style="background:'+badgeColor+'">'+badgeLabel+'</span>';
      }
      var isAi  = (role==='ai'||role==='assistant');
      var av    = isAi ? '<div class="cap-av">⚡</div>' : '';
      var cls   = isAi ? 'cap-m-ai' : 'cap-m-user';
      var $m    = $('<div class="cap-m '+cls+'">'+av+'<div class="cap-mt">'+sourceBadge+html+'</div></div>');
      var $typing = $('#capTyping');
      if ($typing.length) $typing.before($m);
      else $msgs.append($m);

      if(isAi && actions && actions.length && CA.is_admin==='yes'){
        var $cards = buildActionCards(actions);
        if ($typing.length) $typing.before($cards);
        else $msgs.append($cards);
      }
    }

    /* ── Action cards (admin only) ──────────────────────────── */
    function buildActionCards(actions){
      var $wrap = $('<div class="cap-actions-group" style="display:flex;flex-direction:column;gap:5px"></div>');
      $.each(actions, function(i,a){
        var $ac = $([
          '<div class="cap-ac">',
            '<div style="flex:1;min-width:0">',
              '<div class="cap-ac-title">'+esc(a.icon||'🔧')+' '+esc(a.label||'')+'</div>',
              '<div class="cap-ac-desc">'+esc(a.description||'')+'</div>',
              '<div class="cap-ac-btns">',
                '<button class="cap-ac-apply" data-tool="'+escAttr(a.tool)+'" data-params="'+escAttr(JSON.stringify(a.params||{}))+'">✅ Apply</button>',
                '<button class="cap-ac-skip">Skip</button>',
              '</div>',
            '</div>',
          '</div>'
        ].join(''));
        $wrap.append($ac);
      });
      return $wrap;
    }

    $(document).on('click', '.cap-ac-apply', function(){
      var $btn    = $(this);
      var $card   = $btn.closest('.cap-ac');
      $btn.text('…').prop('disabled', true);
      var tool   = $btn.data('tool');
      var params = {};
      try { params = JSON.parse($btn.data('params') || '{}'); } catch(e) {}

      $.post(CA.ajax_url, {
        action: 'ca_tool', nonce: CA.nonce,
        tool: tool, params: JSON.stringify(params)
      })
      .done(function(res) {
        if (res && res.success) {
          $btn.text('✅ Done').css({'background':'var(--ca-green)','cursor':'default'});
          if(lastPairId) sendRating(lastPairId, 5);

          var backupId = res.data && res.data.backup_id ? res.data.backup_id : null;

          // Push to undo stack
          if (backupId) {
            lastBackupStack.push(backupId);
            if (typeof window.wpiSetLastBackup === 'function') {
              window.wpiSetLastBackup(backupId);
            }
            updateStickyUndo();
          }

          var successHtml = '<div class="cap-tool-result cap-tool-ok">'
            + '<span class="cap-tr-icon">✅</span>'
            + '<span class="cap-tr-msg">' + escHtml(res.data && res.data.message ? res.data.message : 'Done') + '</span>';
          if (backupId) {
            successHtml += '<button class="cap-restore-btn" data-backup="' + backupId + '">↩️ Undo</button>';
          }
          // Show refresh button for content/page changes so user sees the result
          var pageTools = ['edit_current_page','update_page_content','create_page','update_custom_css','append_custom_css','builder_create_page','generate_full_site','update_post_title','set_homepage','create_post','update_post','fix_heading_structure','bulk_fix_seo'];
          if (pageTools.indexOf(tool) !== -1) {
            successHtml += '<button class="cap-refresh-btn" onclick="location.reload()">🔄 Refresh to see changes</button>';
          }
          successHtml += '</div>';
          $card.append(successHtml);

        } else {
          $btn.text('❌ Failed').css({'background':'var(--ca-danger)','cursor':'default'});

          var errData  = res && res.data ? res.data : {};
          var errMsg   = typeof errData === 'string' ? errData : (errData.message || 'Something went wrong');
          var backupId = errData.backup_id || null;

          var errorHtml = '<div class="cap-tool-result cap-tool-err">'
            + '<span class="cap-tr-icon">⚠️</span>'
            + '<div class="cap-tr-body">'
            + '<span class="cap-tr-msg">' + escHtml(errMsg) + '</span>';

          if (backupId) {
            errorHtml += '<button class="cap-restore-btn cap-restore-urgent" data-backup="' + backupId + '">↩️ Restore previous state</button>';
          }
          errorHtml += '</div></div>';
          $card.append(errorHtml);
        }
        scrollBottom();
      })
      .fail(function() {
        $btn.text('❌ Failed').prop('disabled', false);
        $card.append('<div class="cap-tool-result cap-tool-err"><span class="cap-tr-icon">⚠️</span><span class="cap-tr-msg">Network error — no changes were saved.</span></div>');
        scrollBottom();
      });
    });

    /* ── Sticky "Undo last" button at bottom of messages ─────── */
    var $stickyUndo = null;

    function updateStickyUndo(){
      if (!lastBackupStack.length) {
        if ($stickyUndo) $stickyUndo.remove();
        $stickyUndo = null;
        return;
      }
      if (!$stickyUndo) {
        $stickyUndo = $('<div class="cap-sticky-undo">' +
          '<button class="cap-sticky-undo-btn">↩ Undo last</button>' +
          '</div>');
        $stickyUndo.find('.cap-sticky-undo-btn').on('click', function(){
          undoLastFromStack();
        });
      }
      // Position: insert before typing indicator or at end of messages
      var $typing = $('#capTyping');
      if ($typing.length) $typing.before($stickyUndo);
      else $msgs.append($stickyUndo);
      scrollBottom();
    }

    function undoLastFromStack(){
      if (!lastBackupStack.length) return;
      var backupId = lastBackupStack.pop();
      if ($stickyUndo) {
        $stickyUndo.find('.cap-sticky-undo-btn').text('Restoring…').prop('disabled', true);
      }
      if(lastPairId) sendRating(lastPairId, 1);

      $.post(CA.ajax_url, {
        action: 'ca_restore_backup', nonce: CA.nonce,
        backup_id: backupId
      })
      .done(function(res){
        if (res && res.success) {
          appendMsg('ai', '↩️ **Restored.** The most recent change has been undone.');
          // Disable the per-card undo button for this backup
          $msgs.find('.cap-restore-btn[data-backup="'+backupId+'"]')
            .text('✅ Restored').prop('disabled', true)
            .css({'opacity':'0.5','cursor':'default'});
        } else {
          var errMsg = res && res.data ? (typeof res.data === 'string' ? res.data : res.data.message) : 'Restore failed';
          appendMsg('ai', '⚠️ ' + errMsg);
        }
        updateStickyUndo();
        scrollBottom();
      })
      .fail(function(){
        appendMsg('ai', '⚠️ Network error during restore.');
        lastBackupStack.push(backupId);
        updateStickyUndo();
        scrollBottom();
      });
    }

    /* ── Restore / Undo button (per-card) ───────────────────── */
    $(document).on('click', '.cap-restore-btn', function() {
      var $btn     = $(this);
      var backupId = $btn.data('backup');
      if (!backupId) return;

      $btn.text('Restoring…').prop('disabled', true).css('opacity', '0.7');
      if(lastPairId) sendRating(lastPairId, 1);

      $.post(CA.ajax_url, {
        action: 'ca_restore_backup', nonce: CA.nonce,
        backup_id: backupId
      })
      .done(function(res) {
        if (res && res.success) {
          $btn.text('✅ Restored').css({'background':'var(--ca-green)','cursor':'default'});
          appendMsg('ai', '↩️ **Restored.** The previous state has been recovered successfully.');
          // Remove from stack if present
          lastBackupStack = lastBackupStack.filter(function(id){ return id !== backupId; });
          updateStickyUndo();
        } else {
          $btn.text('❌ Could not restore').prop('disabled', false).css('opacity','1');
          var errMsg = res && res.data ? (typeof res.data === 'string' ? res.data : res.data.message) : 'Restore failed';
          appendMsg('ai', '⚠️ ' + errMsg);
        }
        scrollBottom();
      })
      .fail(function() {
        $btn.text('↩️ Restore').prop('disabled', false).css('opacity','1');
        appendMsg('ai', '⚠️ Network error during restore.');
        scrollBottom();
      });
    });

    $(document).on('click', '.cap-ac-skip', function(){
      if(lastPairId) sendRating(lastPairId, 2);
      $(this).closest('.cap-ac').fadeOut(150,function(){ $(this).remove(); });
    });

    /* ── Training signal ───────────────────────────────────── */
    function sendRating(pairId, rating){
      if(!pairId || !CA.nonce) return;
      $.post(CA.ajax_url, {
        action: 'wpi_rate_pair',
        nonce:   CA.nonce,
        pair_id: pairId,
        rating:  rating
      });
    }

    /* ── Scroll ─────────────────────────────────────────────── */
    function scrollBottom(){
      if($msgs.length) $msgs.scrollTop($msgs[0].scrollHeight + 9999);
    }

    /* ── Markdown to HTML ───────────────────────────────────── */
    function mdToHtml(text){
      text = String(text||'')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
      text = text.replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>');
      text = text.replace(/^#{1,3}\s+(.+)$/gm,'<strong class="cap-h">$1</strong>');
      text = text.replace(/^[-•*]\s+(.+)$/gm,'<span class="cap-li">$1</span>');
      text = text.replace(/`([^`]+)`/g,'<code>$1</code>');
      text = text.replace(/^>\s*(.+)$/gm,'<span class="cap-quote" style="border-left:3px solid var(--ca-border2);padding-left:8px;display:block;color:var(--ca-text2);font-style:italic">$1</span>');
      return text.replace(/\n/g,'<br>');
    }

    function esc(s)    { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function escAttr(s){ return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    /* ── Inject sticky undo CSS ──────────────────────────────── */
    $('<style>' +
      '.cap-sticky-undo{text-align:center;padding:6px 0;position:sticky;bottom:0;z-index:5;background:linear-gradient(transparent,var(--ca-bg2) 40%)}' +
      '.cap-sticky-undo-btn{background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.35);color:#fbbf24;' +
        'padding:6px 16px;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;' +
        'font-family:var(--ca-font);transition:background .15s,transform .1s}' +
      '.cap-sticky-undo-btn:hover{background:rgba(245,158,11,.25);transform:translateY(-1px)}' +
      '.cap-sticky-undo-btn:disabled{opacity:.4;cursor:not-allowed;transform:none}' +
    '</style>').appendTo('head');

  }); // end document.ready

})(jQuery);



/* Global Connect Handler - works even if bubble HTML loads late */
jQuery(function(jq){
    jq(document).on('click', '#capConnectBtn', function() {
        var btn = jq(this), inp = jq('#capApiKeyInput'), st = jq('#capConnectStatus');
        var key = jq.trim(inp.val());
        if (!key || !key.startsWith('sk-')) { st.text('Enter a valid Claude API key (starts with sk-)').css('color','#EF4444').show(); return; }
        btn.text('Connecting...').prop('disabled', true);
        st.text('Testing connection...').css('color','#5E6E91').show();
        var url = (typeof CA !== 'undefined' && CA.ajax_url) ? CA.ajax_url : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php');
        var nc = (typeof CA !== 'undefined' && CA.nonce) ? CA.nonce : '';
        jq.post(url, {action:'ca_test_connection', nonce:nc, key:key})
        .done(function(r) {
            if (r && r.success) { st.text('Connected! Reloading...').css('color','#10B981'); setTimeout(function(){location.reload()},1000); }
            else { var m = r && r.data ? (typeof r.data === 'string' ? r.data : r.data.message || 'Failed') : 'Failed'; st.text(m).css('color','#EF4444'); btn.text('Connect & Start').prop('disabled',false); }
        }).fail(function() { st.text('Network error').css('color','#EF4444'); btn.text('Connect & Start').prop('disabled',false); });
    });
    jq(document).on('keydown', '#capApiKeyInput', function(e) { if(e.key==='Enter'){e.preventDefault();jq('#capConnectBtn').click();} });
});
