/* global CA, jQuery */
(function($){
  'use strict';

  /* ── AJAX helper ────────────────────────────────────────────── */
  function ajax(action, data, cb){
    $.post(CA.ajax_url, Object.assign({action, nonce: CA.nonce}, data), function(res){
      cb(res.success, res.data, res);
    });
  }

  /* ── Inline feedback ────────────────────────────────────────── */
  function fb($el, msg, ok){
    $el.text(msg).removeClass('ok err').addClass(ok?'ok':'err').show();
    setTimeout(()=>$el.fadeOut(), 3000);
  }

  /* ── Apply CA theme ─────────────────────────────────────────── */
  function applyTheme(theme){
    $('.ca-page, #caRoot').attr('data-theme', theme);
    localStorage.setItem('ca_theme', theme);
  }
  applyTheme(CA.theme || localStorage.getItem('ca_theme') || 'dark');

  /* ── Chat page: remove WP default padding ───────────────────── */
  if ($('.ca-chat-layout').length) {
    $('html,body,#wpwrap,#wpcontent,#wpbody,#wpbody-content').css({
      'background': '#030507',
      'padding'   : '0',
      'margin'    : '0'
    });
    $('#wpbody-content .wrap').css({'padding':'0','margin':'0','max-width':'none'});
  }

  // ══════════════════════════════════════════════════════════════
  //  DASHBOARD REDIRECT
  // ══════════════════════════════════════════════════════════════
  if( CA.onboarded === 'no' && !window.location.href.includes('setup') ){
    // Subtle nudge only — don't force redirect
  }

  // ══════════════════════════════════════════════════════════════
  //  CHAT PAGE
  // ══════════════════════════════════════════════════════════════
  if( $('[data-page="chat"]').length ){

    var history = [];
    var currentMode = 'chat';
    var modeLabels = {chat:'💬 General',analyze:'🔍 Analyze',build:'🏗️ Build',seo:'📈 SEO',woo:'🛒 WooCommerce'};

    function scrollBottom(){ var $m = $('#caMsgs'); $m.scrollTop($m[0].scrollHeight); }

    /* Mode pills */
    $(document).on('click','.ca-mode-pill',function(){
      $('.ca-mode-pill').removeClass('active');
      $(this).addClass('active');
      currentMode = $(this).data('mode');
      $('#caModeLbl').text(modeLabels[currentMode]||'💬 General');
    });

    /* Quick prompts */
    $(document).on('click','.ca-qp,.ca-wlc-chip',function(){
      $('#caIn').val($(this).data('msg')).trigger('input');
      sendMessage();
    });

    /* Auto-resize textarea */
    $('#caIn').on('input',function(){
      this.style.height='auto';
      this.style.height=Math.min(this.scrollHeight, 130)+'px';
    });

    /* Send on Enter (not shift) */
    $('#caIn').on('keydown',function(e){
      if(e.key==='Enter'&&!e.shiftKey){ e.preventDefault(); sendMessage(); }
    });
    $('#caSend').on('click', sendMessage);

    /* Clear history */
    $('#caClearHist').on('click',function(){
      if(!confirm('Clear all chat history?')) return;
      ajax('ca_clear_history',{},function(ok){
        if(ok){ history=[]; $('#caMsgs').html(buildWelcome()); scrollBottom(); }
      });
    });

    function buildWelcome(){
      return '<div class="ca-welcome" id="caWelcome"><div class="ca-wlc-av">⚡</div><h3>Ready to help</h3><p>Ask me to analyze, build, or improve your WordPress site.</p></div>';
    }

    function sendMessage(){
      var msg = $.trim($('#caIn').val());
      if(!msg) return;
      if(CA.locked==='yes'){ alert('Limit reached. Activate a license to continue.'); return; }
      if(CA.connected!=='yes'){ alert('Connect Claude in Settings first.'); return; }

      $('#caWelcome').remove();
      appendMsg('user', msg);
      $('#caIn').val('').css('height','auto');
      $('#caTyping').show(); scrollBottom();

      history.push({role:'user',content:msg});

      ajax('ca_chat', {message:msg, mode:currentMode, history:JSON.stringify(history.slice(-14))}, function(ok, data){
        $('#caTyping').hide();
        if(!ok){ appendMsg('assistant','⚠️ '+data); return; }
        appendMsg('assistant', data.response, data.actions);
        history.push({role:'assistant',content:data.response});
        CA.used = data.used;
        if(data.locked){ CA.locked='yes'; $('#caIn,#caSend').prop('disabled',true); }
        updatePromptMeter(data.used);
        scrollBottom();
      });
    }

    function appendMsg(role, text, actions){
      var clean = text.replace(/\[ACTION:[^\]]+\]/g,'');
      var html  = mdToHtml(clean);
      var av    = role==='assistant' ? '<div class="ca-msg-av">⚡</div>' : '';
      var time  = new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
      var actHtml = actions&&actions.length ? buildActionCards(actions) : '';
      var cls   = role==='assistant' ? 'ca-msg-ai' : 'ca-msg-user';
      var $msg  = $('<div class="ca-msg '+cls+'">'+av+'<div class="ca-msg-body"><div class="ca-msg-text">'+html+'</div>'+actHtml+'<div class="ca-msg-time">'+time+'</div></div></div>');
      $('#caMsgs').append($msg);
      scrollBottom();
    }

    function buildActionCards(actions){
      return '<div class="ca-action-cards">'+actions.map(a=>
        '<div class="ca-ac"><div class="ca-ac-l"><span class="ca-ac-ico">'+escHtml(a.icon||'🔧')+'</span><div><div class="ca-ac-title">'+escHtml(a.label)+'</div><div class="ca-ac-desc">'+escHtml(a.description)+'</div></div></div>'+
        '<div class="ca-ac-btns"><button class="ca-btn ca-btn-primary ca-btn-xs ca-do-action" data-tool="'+escAttr(a.tool)+'" data-params="'+escAttr(JSON.stringify(a.params||{}))+'">✅ Apply</button>'+
        '<button class="ca-btn ca-btn-ghost ca-btn-xs ca-skip-action">Skip</button></div></div>'
      ).join('')+'</div>';
    }

    function updatePromptMeter(used){
      var pct = Math.min(100,(used/CA.limit)*100);
      $('.ca-pm-fill').css('width',pct+'%');
      $('.ca-pm-text').text(used+' / '+CA.limit+' used');
    }

    /* Execute action cards */
    $(document).on('click','.ca-do-action',function(){
      var $btn  = $(this);
      var tool  = $btn.data('tool');
      var params= {};
      try { params = JSON.parse($btn.data('params')||'{}'); } catch(e){}
      $btn.text('…').prop('disabled',true);
      ajax('ca_tool',{tool:tool,params:JSON.stringify(params)},function(ok,data){
        if(ok){
          $btn.addClass('applied').text('✅ Applied');
          appendMsg('assistant','✅ '+(data.message||'Done.'));
        } else {
          $btn.text('Retry').prop('disabled',false);
          appendMsg('assistant','⚠️ Error: '+(data||'Unknown error'));
        }
        scrollBottom();
      });
    });

    $(document).on('click','.ca-skip-action',function(){ $(this).closest('.ca-ac').fadeOut(200,function(){$(this).remove();}); });
  }

  // ══════════════════════════════════════════════════════════════
  //  ANALYZE PAGE
  // ══════════════════════════════════════════════════════════════
  $('#caRunAnalysis').on('click',function(){
    var scope = $('input[name="caScope"]:checked').val()||'full';
    var $btn  = $(this).text('⏳ Scanning…').prop('disabled',true);
    var $res  = $('#caAnalysisResult').show().html('<div style="padding:20px;text-align:center;color:var(--ca-text2)">⚡ Scanning your site…</div>');

    ajax('ca_scan',{scope:scope},function(ok,ctx){
      if(!ok){ $res.html('<div style="color:#FCA5A5">Scan failed.</div>'); $btn.text('🔍 Run Analysis').prop('disabled',false); return; }

      var summary = 'Mode: '+scope+'\n\nSite: '+ctx.site.name+'\nTheme: '+ctx.theme.name+'\nBuilder: '+ctx.builder+'\nActive plugins: '+ctx.plugins.active_count;
      if(ctx.pages) summary += '\nPages: '+ctx.pages.length;
      if(ctx.seo)   summary += '\nPages missing meta: '+ctx.seo.pages_missing_meta;
      if(ctx.images) summary += '\nImages: '+ctx.images.length+' (missing alt: '+ctx.images.filter(i=>i.missing_alt).length+')';

      var prompt = 'Analyze this WordPress site based on the scope "'+scope+'" and return a structured report.\n\nSITE CONTEXT:\n'+JSON.stringify(ctx,null,2)+'\n\nFormat with severity ratings (🔴 Critical, 🟡 Warning, 🟢 Info). End with: "Would you like me to fix any of these?"';

      ajax('ca_chat',{message:prompt,mode:'analyze',history:'[]'},function(ok2,data){
        $btn.text('🔍 Run Analysis').prop('disabled',false);
        if(!ok2){ $res.html('<div style="color:#FCA5A5">Analysis failed: '+escHtml(data)+'</div>'); return; }
        var clean = (data.response||'').replace(/\[ACTION:[^\]]+\]/g,'');
        $res.html(mdToHtml(clean));
        if(data.actions&&data.actions.length) $res.append(buildActionCardsHtml(data.actions));
      });
    });
  });

  // ══════════════════════════════════════════════════════════════
  //  BUILD PAGE
  // ══════════════════════════════════════════════════════════════
  $(document).on('click','.ca-ab-btn',function(){
    $('#caBuildInput').val($(this).data('msg'));
  });

  $('#caBuildGo').on('click',function(){
    var msg = $.trim($('#caBuildInput').val());
    if(!msg) return;
    $(this).text('⏳ Asking…').prop('disabled',true);
    var self = this;
    ajax('ca_chat',{message:msg,mode:'build',history:'[]'},function(ok,data){
      $(self).text('⚡ Send to Claude').prop('disabled',false);
      var $res = $('#caBuildResult').show();
      if(!ok){ $res.html('<div style="color:#FCA5A5">Error: '+escHtml(data)+'</div>'); return; }
      var clean = (data.response||'').replace(/\[ACTION:[^\]]+\]/g,'');
      $res.html(mdToHtml(clean));
      if(data.actions&&data.actions.length) $res.append(buildActionCardsHtml(data.actions));
    });
  });

  // ══════════════════════════════════════════════════════════════
  //  PLUGINS PAGE
  // ══════════════════════════════════════════════════════════════
  $('#caPluginAudit').on('click',function(){
    var $btn=$(this).text('⏳…').prop('disabled',true);
    ajax('ca_scan',{scope:'plugins'},function(ok,ctx){
      var prompt='Audit these installed WordPress plugins and return a structured report with: what each plugin does, any conflicts or overlap, performance concerns, security concerns, and what can be safely removed.\n\nPLUGIN DATA:\n'+JSON.stringify(ctx.plugins,null,2);
      ajax('ca_chat',{message:prompt,mode:'plugins',history:'[]'},function(ok2,data){
        $btn.text('⚡ AI Plugin Audit').prop('disabled',false);
        var clean=(data.response||'').replace(/\[ACTION:[^\]]+\]/g,'');
        $('#caPluginAuditResult').show().html(mdToHtml(clean));
      });
    });
  });

  $(document).on('click','.ca-plugin-deact',function(){
    var $btn=$(this), file=$btn.data('file');
    if(!confirm('Deactivate plugin: '+file+'?')) return;
    ajax('ca_tool',{tool:'deactivate_plugin',params:JSON.stringify({file})},function(ok,data){
      if(ok){ $btn.text('Deactivated').prop('disabled',true); }
      else   { alert('Error: '+(data||'Unknown')); }
    });
  });

  $(document).on('click','.ca-plugin-del',function(){
    var $btn=$(this), file=$btn.data('file');
    if(!confirm('Permanently DELETE plugin: '+file+'? This cannot be undone.')) return;
    ajax('ca_tool',{tool:'delete_plugin',params:JSON.stringify({file})},function(ok,data){
      if(ok){ $btn.closest('.ca-table-row').fadeOut(); }
      else   { alert('Error: '+(data||'Unknown')); }
    });
  });

  // ══════════════════════════════════════════════════════════════
  //  MEDIA PAGE
  // ══════════════════════════════════════════════════════════════
  $(document).on('click','.ca-save-alt',function(){
    var $btn=$(this), id=$btn.data('id');
    var alt = $btn.closest('.ca-img-info').find('.ca-img-alt').val();
    ajax('ca_tool',{tool:'update_image_alt',params:JSON.stringify({id,alt})},function(ok){
      if(ok){ $btn.text('✓ Saved'); setTimeout(()=>$btn.text('Save'),2000); $btn.closest('.ca-img-card').removeClass('ca-img-missing'); }
    });
  });

  $(document).on('click','.ca-ai-alt',function(){
    var $btn=$(this).text('⏳').prop('disabled',true);
    var id=$btn.data('id'), name=$btn.data('name');
    ajax('ca_chat',{message:'Generate a concise, descriptive alt text for a WordPress image titled: "'+name+'". Return only the alt text, nothing else.',mode:'chat',history:'[]'},function(ok,data){
      $btn.text('⚡ AI').prop('disabled',false);
      if(!ok) return;
      var alt = (data.response||'').replace(/^"|"$/g,'').trim();
      $btn.closest('.ca-img-info').find('.ca-img-alt').val(alt);
    });
  });

  $('#caFixAllAlts').on('click',function(){
    if(!confirm('Fix all missing alt texts using image titles? This applies immediately.')) return;
    ajax('ca_tool',{tool:'bulk_fix_alt_text',params:'{}'},function(ok,data){
      if(ok) alert(data.message||'Done!');
      else   alert('Error: '+(data||'Unknown'));
    });
  });

  $('#caMediaAudit').on('click',function(){
    var $btn=$(this).text('⏳…').prop('disabled',true);
    ajax('ca_scan',{scope:'media'},function(ok,ctx){
      var missing = (ctx.images||[]).filter(i=>i.missing_alt).length;
      var prompt='Audit these WordPress images. Report: missing alt texts ('+missing+' found), file size concerns, naming suggestions, and any improvements.\n\nIMAGE DATA:\n'+JSON.stringify((ctx.images||[]).slice(0,30),null,2);
      ajax('ca_chat',{message:prompt,mode:'chat',history:'[]'},function(ok2,data){
        $btn.text('🔍 AI Media Audit').prop('disabled',false);
        var clean=(data.response||'').replace(/\[ACTION:[^\]]+\]/g,'');
        $('#caMediaAuditResult').show().html(mdToHtml(clean));
      });
    });
  });

  // ══════════════════════════════════════════════════════════════
  //  AI INSTRUCTIONS PAGE
  // ══════════════════════════════════════════════════════════════
  $('#caSaveInstr').on('click',function(){
    var val = $('#caCustomInstr').val();
    ajax('ca_save_setting',{key:'ca_custom_instructions',value:val},function(ok){
      fb($('#caInstrFeedback'), ok?'✅ Saved!':'❌ Error', ok);
    });
  });

  // ══════════════════════════════════════════════════════════════
  //  SETTINGS PAGE
  // ══════════════════════════════════════════════════════════════
  $('#caTestConn').on('click',function(){
    var key = $.trim($('#caApiKey').val());
    if(!key){ fb($('#caApiResult'),'Enter an API key first.',false); return; }
    $(this).text('⏳ Testing…').prop('disabled',true);
    var self=this;
    ajax('ca_test_connection',{key},function(ok,data){
      $(self).text('Test & Save').prop('disabled',false);
      var $res = $('#caApiResult');
      if(ok){
        fb($res,'✅ '+(data.message||'Connected!'),true);
        $('.ca-api-box').addClass('ca-api-connected');
        $('.ca-conn-dot').css({background:'#10B981',boxShadow:'0 0 6px rgba(16,185,129,.5)'});
        $('#caConnStatus').text('Connected — '+key.slice(0,10)+'••••'+key.slice(-4));
        CA.connected='yes';
      } else {
        fb($res,'❌ '+(data||'Connection failed.'),false);
      }
    });
  });

  $('#caSavePrefs').on('click',function(){
    var theme = $('#caThemeSel').val();
    var auto  = $('#caAutoApprove').is(':checked')?'yes':'no';
    ajax('ca_save_setting',{key:'ca_theme',value:theme},function(){
      ajax('ca_save_setting',{key:'ca_auto_approve',value:auto},function(ok){
        fb($('#caPrefFb'),ok?'✅ Saved!':'❌ Error',ok);
        applyTheme(theme);
        CA.auto_approve=auto;
      });
    });
  });

  // ══════════════════════════════════════════════════════════════
  //  LICENSE PAGE
  // ══════════════════════════════════════════════════════════════
  $('#caActivateLic').on('click',function(){
    var key = $.trim($('#caLicKey').val());
    if(!key){ fb($('#caLicFb'),'Enter a license key.',false); return; }
    $(this).text('⏳…').prop('disabled',true);
    var self=this;
    ajax('ca_activate_license',{key},function(ok,data){
      $(self).text('Activate').prop('disabled',false);
      fb($('#caLicFb'),(ok?'✅ ':'❌ ')+(data.message||data||'Unknown'),ok);
      if(ok){ setTimeout(()=>location.reload(),1500); }
    });
  });

  $('#caDeactivateLic').on('click',function(){
    if(!confirm('Deactivate license?')) return;
    ajax('ca_deactivate_license',{},function(ok){
      if(ok) location.reload();
    });
  });

  // ══════════════════════════════════════════════════════════════
  //  RESTORE HISTORY PAGE
  // ══════════════════════════════════════════════════════════════
  function loadBackups(){
    ajax('ca_get_backups',{},function(ok,rows){
      var $body = $('#caBackupRows');
      if(!ok||!rows.length){ $body.html('<div class="ca-tbl-loading">No backup history found.</div>'); return; }
      $body.html(rows.map(r=>{
        var date = new Date(r.created_at).toLocaleString();
        var restored = r.restored==='1'||r.restored===1 ? '<span style="color:var(--ca-green)">✓ Restored</span>' : '—';
        var btn = (r.restored==='1'||r.restored===1) ? '—' : '<button class="ca-btn ca-btn-ghost ca-btn-xs ca-restore-btn" data-id="'+r.id+'">Restore</button>';
        return '<div class="ca-table-row ca-restore-cols"><span>'+r.id+'</span><span>'+escHtml(r.tool)+'</span><span>'+(r.target_type||'—')+'</span><span>'+date+'</span><span>'+restored+'</span><span>'+btn+'</span></div>';
      }).join(''));
    });
  }
  if($('#caBackupRows').length) loadBackups();
  $('#caLoadBackups').on('click', loadBackups);

  $(document).on('click','.ca-restore-btn',function(){
    var id=$(this).data('id');
    if(!confirm('Restore backup #'+id+'? This will overwrite the current version.')) return;
    ajax('ca_restore',{backup_id:id},function(ok,data){
      if(ok){ alert(data.message||'Restored!'); loadBackups(); }
      else   { alert('Error: '+(data||'Unknown')); }
    });
  });

  // ══════════════════════════════════════════════════════════════
  //  SHARED UTILITIES
  // ══════════════════════════════════════════════════════════════
  function buildActionCardsHtml(actions){
    return '<div class="ca-action-cards">'+actions.map(a=>
      '<div class="ca-ac"><div class="ca-ac-l"><span class="ca-ac-ico">'+escHtml(a.icon||'🔧')+'</span><div><div class="ca-ac-title">'+escHtml(a.label)+'</div><div class="ca-ac-desc">'+escHtml(a.description)+'</div></div></div>'+
      '<div class="ca-ac-btns"><button class="ca-btn ca-btn-primary ca-btn-xs ca-do-action" data-tool="'+escAttr(a.tool)+'" data-params="'+escAttr(JSON.stringify(a.params||{}))+'">✅ Apply</button>'+
      '<button class="ca-btn ca-btn-ghost ca-btn-xs ca-skip-action">Skip</button></div></div>'
    ).join('')+'</div>';
  }

  function mdToHtml(text){
    text = text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    text = text.replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>');
    text = text.replace(/\*(.*?)\*/g,'<em>$1</em>');
    text = text.replace(/^#{1,3}\s+(.+)$/gm,'<div class="ca-md-h">$1</div>');
    text = text.replace(/^[-•]\s+(.+)$/gm,'<li>$1</li>');
    text = text.replace(/(<li>[\s\S]*?<\/li>)+/g,'<ul class="ca-md-ul">$&</ul>');
    text = text.replace(/`([^`]+)`/g,'<code class="ca-md-code">$1</code>');
    return text.replace(/\n/g,'<br>');
  }

  function escHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
  function escAttr(s){ return String(s||'').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

  // Expose for action cards outside chat
  window._ca_mdToHtml = mdToHtml;
  window._ca_ajax    = ajax;
  window._ca_buildActionCardsHtml = buildActionCardsHtml;

})(jQuery);
