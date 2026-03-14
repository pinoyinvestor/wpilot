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
    var history  = [];

    // Bail if bubble HTML not present
    if ( !$root.length || !$trigger.length ) return;

    // Apply theme
    if ( CA && CA.theme ) $root.attr('data-theme', CA.theme);

    /* ── Toggle ────────────────────────────────────────────── */
    var wpiScanned = false;
    function runSmartScan() {
        if (wpiScanned || !CA.connected) return;
        wpiScanned = true;
        appendMsg('ai', '🔍 Scannar din WordPress-sajt och dina plugins…');
        jQuery.post(ajaxurl, {action:'wpi_smart_scan', nonce:CA.nonce}, function(r) {
            // Remove scanning message
            $panel.find('.ca-msg:last').remove();
            if (r.success) appendMsg('ai', r.data.scan);
            else appendMsg('ai', 'Hej! Jag är WPilot. Skriv vad du vill ha hjälp med så hjälper jag dig!');
        });
    }

    function openPanel() {
      open = true;
      setTimeout(runSmartScan, 300);
      $panel.addClass('cap-visible').show();
      $trigger.find('.ca-t-idle').hide();
      $trigger.find('.ca-t-open').show();
      hideBadge();
      if($in.length) setTimeout(function(){ $in.focus(); }, 80);
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

    /* ── Config panel ──────────────────────────────────────── */
    $('#capCfgBtn').on('click', function(e){
      e.stopPropagation();
      $('#capCfgPanel').slideToggle(140);
    });
    $('#capAutoApprove').on('change', function(){
      var val = $(this).is(':checked') ? 'yes' : 'no';
      $.post(CA.ajax_url, { action:'ca_save_setting', nonce:CA.nonce, key:'ca_auto_approve', value:val });
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
        // Slash command autocomplete on Tab
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
      {cmd:'/scan',     label:'Scanna mina plugins', mode:'plugins', msg:'Scanna alla mina installerade plugins. Vad fungerar bra? Vad saknas? Vad kan optimeras? Var alltid tydlig med vad som är gratis och vad som kostar.'},
      {cmd:'/gratis',    label:'Gratis-optimeringar', mode:'chat',    msg:'Visa mig allt jag kan förbättra på min WordPress-sajt med enbart gratis plugins och inställningar. Inga betalda lösningar.'},
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
      sendMsgWithContent(cmd.msg, cmd.mode);
    }

    /* ── Token estimator ─────────────────────────────────────── */
    var $tokenHint = null;
    function aibUpdateTokenEstimate(val){
      if(!val || val.length < 30){ if($tokenHint) $tokenHint.hide(); return; }
      var words  = val.trim().split(/\s+/).length;
      var tokens = Math.round(words * 1.3 + 200); // rough estimate incl system prompt
      var cost   = (tokens / 1000000 * 3).toFixed(5); // $3/MTok input
      if(!$tokenHint){
        $tokenHint = $('<div class="cap-token-hint"></div>');
        $panel.find('.cap-footer').prepend($tokenHint);
      }
      $tokenHint.html('~'+tokens+' tokens · $'+cost+' input cost').show();
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
      $msgs.find('.cap-msg').each(function(){
        var role = $(this).hasClass('cap-msg-user') ? 'You' : 'AI';
        var text = $(this).find('.cap-msg-text').text().trim();
        lines.push('['+role+'] ' + text);
        lines.push('');
      });
      var blob = new Blob([lines.join('\n')], {type:'text/plain'});
      var url  = URL.createObjectURL(blob);
      var a    = document.createElement('a');
      a.href   = url;
      a.download = 'wpilot-chat-' + new Date().toISOString().slice(0,10) + '.txt';
      a.click();
      URL.revokeObjectURL(url);
    });

    /* ── Send message ────────────────────────────────────────── */
    function sendMsg(){
      if(!$in || !$in.length) return;
      var msg = $.trim($in.val());
      if(!msg) return;
      // Handle slash command typed in full
      var slash = slashCmds.find(function(c){ return c.cmd === msg.toLowerCase().trim(); });
      if(slash){ aibFireSlash(slash); return; }
      sendMsgWithContent(msg, 'chat');
    }

    function sendMsgWithContent(msg, mode){
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

      // Remove welcome screen on first message
      $('#capWelcome').fadeOut(150, function(){ $(this).remove(); });

      appendMsg('user', msg);
      $in.val('').css('height','auto');
      if($send.length) $send.prop('disabled', true);
      $('#capTyping').show();
      scrollBottom();
      history.push({role:'user', content:msg});

      $.post(CA.ajax_url, {
        action:  'ca_chat',
        nonce:   CA.nonce,
        message: msg,
        mode:    mode || 'chat',
        history: JSON.stringify(history.slice(-12)),
        context: JSON.stringify({
          page: 'WordPress admin: ' + (CA.page_title || document.title || 'unknown'),
          site: {name: CA.site_name, url: CA.site_url}
        })
      })
      .done(function(res){
        $('#capTyping').hide();
        if($send.length) $send.prop('disabled', false);

        if(!res || !res.success){
          var errMsg = (res && res.data) ? res.data : 'Something went wrong. Please try again.';
          if(errMsg && (errMsg.toLowerCase().indexOf('credit') !== -1 || errMsg.toLowerCase().indexOf('balance') !== -1 || errMsg.toLowerCase().indexOf('billing') !== -1)){
            errMsg = (CA.i18n && CA.i18n.error_no_credits) ? CA.i18n.error_no_credits : errMsg;
          }
          appendMsg('ai', '⚠️ ' + errMsg);
          scrollBottom();
          return;
        }

        var d = res.data;
        appendMsg('ai', d.response, d.actions, d.source);
        history.push({role:'assistant', content:d.response});

        if(d.locked){ CA.locked='yes'; }
        if(!open) showBadge('!');
        scrollBottom();
      })
      .fail(function(xhr){
        $('#capTyping').hide();
        if($send.length) $send.prop('disabled', false);
        var msg = '⚠️ Request failed.';
        if(xhr.status===0) msg = '⚠️ No connection. Check your internet.';
        if(xhr.status===403) msg = '⚠️ Permission denied. Admin or editor role required.';
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
      var $m    = $('<div class="cap-m '+cls+'">'+av+'<div class="cap-mt">'+html+'</div></div>');
      $msgs.append($m);
      if(isAi && actions && actions.length && CA.is_admin==='yes'){
        $msgs.append(buildActionCards(actions));
      }
    }

    /* ── Action cards (admin only) ──────────────────────────── */
    function buildActionCards(actions){
      var $wrap = $('<div style="display:flex;flex-direction:column;gap:5px"></div>');
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
      var label  = $btn.data('label') || tool;
      var params = {};
      try { params = JSON.parse($btn.data('params') || '{}'); } catch(e) {}

      $.post(CA.ajax_url, {
        action: 'ca_tool', nonce: CA.nonce,
        tool: tool, params: JSON.stringify(params)
      })
      .done(function(res) {
        if (res && res.success) {
          // ── SUCCESS ────────────────────────────────────────────
          $btn.text('✅ Done').css({'background':'var(--ca-green)','cursor':'default'});
          // Training signal: Apply = rating 5
          if(lastPairId) sendRating(lastPairId, 5);

          // Always show Restore button after a successful change
          var backupId = res.data && res.data.backup_id ? res.data.backup_id : null;
          var successHtml = '<div class="cap-tool-result cap-tool-ok">'
            + '<span class="cap-tr-icon">✅</span>'
            + '<span class="cap-tr-msg">' + escHtml(res.data && res.data.message ? res.data.message : 'Done') + '</span>';
          if (backupId) {
            successHtml += '<button class="cap-restore-btn" data-backup="' + backupId + '">↩️ Undo</button>';
          }
          successHtml += '</div>';
          $card.append(successHtml);

        } else {
          // ── ERROR ─────────────────────────────────────────────
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

    // ── Restore / Undo button ──────────────────────────────────
    $(document).on('click', '.cap-restore-btn', function() {
      var $btn     = $(this);
      var backupId = $btn.data('backup');
      if (!backupId) return;

      $btn.text('Restoring…').prop('disabled', true).css('opacity', '0.7');
      // Training signal: Undo = rating 1 (answer caused a problem)
      if(lastPairId) sendRating(lastPairId, 1);

      $.post(CA.ajax_url, {
        action: 'ca_restore_backup', nonce: CA.nonce,
        backup_id: backupId
      })
      .done(function(res) {
        if (res && res.success) {
          $btn.text('✅ Restored').css({'background':'var(--ca-green)','cursor':'default'});
          appendMsg('ai', '↩️ **Restored.** The previous state has been recovered successfully.');
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
      // Training signal: Skip = rating 2 (not useful enough)
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
      return text.replace(/\n/g,'<br>');
    }

    function esc(s)    { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
    function escAttr(s){ return String(s||'').replace(/"/g,'&quot;'); }

  }); // end document.ready

})(jQuery);
