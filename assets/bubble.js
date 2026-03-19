/**
 * WPilot — Bubble Chat JS
 * Handles the floating chat bubble UI, message sending, sessions, and action cards.
 */
(function($){
    'use strict';

    var $trigger, $panel, $msgs, $input, $send, $typing, $welcome;
    var chatHistory = [];
    var currentMode = 'chat';
    var isSending = false;
    var thinkTimer = null;
    var thinkStart = 0;
    // Built by Weblease
    // ── Session state ─────────────────────────────────────────
    var sessionKey = '';
    var sessionTTL = 0;
    var sessionTimer = null;
    var heartbeatTimer = null;
    var SESSION_WARN_SECS = 120; // Warn 2 min before expiry

    // ── Init on DOM ready ─────────────────────────────────────
    $(function(){ if (typeof CA === "undefined") return;
        $trigger = $('#caTrigger');
        $panel   = $('#caPanel');
        $msgs    = $('#capMsgs');
        $input   = $('#capIn');
        $send    = $('#capSend');
        $typing  = $('#capTyping');
        $welcome = $('#capWelcome');

        if (!$trigger.length) return;

        // Global: add tool result to chat history (called from inline wpiApply)
        window.wpiAddToHistory = function(role, content) {
            chatHistory.push({role: role, content: content});
        };

        // Toggle panel
        $trigger.on('click', function(e){
            e.stopPropagation();
            var open = $panel.is(':visible');
            $panel.toggle(!open);
            if (!open) $panel.addClass('cap-visible');
            $trigger.find('.ca-t-idle').toggle(open);
            $trigger.find('.ca-t-open').toggle(!open);
            if (!open && $input.length) $input.focus();
        });

        // Close button
        $('#capClose').on('click', function(e){
            e.stopPropagation();
            $panel.removeClass('cap-visible').hide();
            $trigger.find('.ca-t-idle').show();
            $trigger.find('.ca-t-open').hide();
        });

        // Escape closes panel
        $(document).on('keydown', function(e){
            if (e.key === 'Escape' && $panel.is(':visible')) {
                $('#capClose').click();
            }
        });

        // Click outside closes panel
        $(document).on('click', function(e){
            if ($panel.is(':visible') && !$('#caRoot').is(e.target) && $('#caRoot').has(e.target).length === 0) {
                $('#capClose').click();
            }
        });
        $('#caRoot').on('click', function(e){ e.stopPropagation(); });

        // Settings toggle
        $('#capCfgBtn').on('click', function(e){
            e.stopPropagation();
            $('#capCfgPanel').slideToggle(150);
        });

        // Auto-approve toggle
        $('#capAutoApprove').on('change', function(){
            $.post(CA.ajax_url, {
                action: 'ca_save_setting',
                nonce: CA.nonce,
                key: 'wpilot_auto_approve',
                value: this.checked ? 'yes' : 'no'
            });
            CA.auto_approve = this.checked ? 'yes' : 'no';
        });

        // Send on button click
        $send.on('click', function(e){ e.preventDefault(); sendMessage(); });

        // Send on Enter (Shift+Enter = newline)
        $input.on('keydown', function(e){
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto-resize textarea
        $input.on('input', function(){
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        // Quick chip buttons
        $(document).on('click', '.cap-chip', function(){
            var msg = $(this).data('msg');
            if (msg) { $input.val(msg); sendMessage(); }
        });
        $(document).on('click', '.ca-qp', function(){
            var msg = $(this).data('msg');
            if (msg) { $input.val(msg); sendMessage(); }
        });

        // Alt+C keyboard shortcut
        $(document).on('keydown', function(e){
            if (e.altKey && (e.key === 'c' || e.key === 'C')) {
                e.preventDefault();
                $trigger.click();
            }
        });

        // ── New Chat button ───────────────────────────────────
        $('#capNewChat').on('click', function(e){
            e.stopPropagation();
            startNewChat();
        });

        // ── Clear chat (legacy, now starts new session) ───────
        $('#capClearChat').on('click', function(e){
            e.stopPropagation();
            startNewChat();
        });

        // Restart + re-scan button
        $('#capRestartScan').on('click', function(e){
            e.stopPropagation();
            startNewChat(function(){
                appendMessage('ai', 'Jag scannar din sida...');
                scrollToBottom();
                $.post(CA.ajax_url, {action:'wpi_smart_scan', nonce:CA.nonce}, function(r) {
                    $msgs.find('.cap-m:last').remove();
                    if (r && r.success && r.data) {
                        appendMessage('ai', r.data.scan || 'Klar! Vad vill du att jag ska göra?');
                    } else {
                        appendMessage('ai', 'Klar! Vad vill du att jag ska göra?');
                    }
                    scrollToBottom();
                });
            });
        });

        // Export chat
        $('#capExportChat').on('click', function(e){
            e.stopPropagation();
            var lines = ['WPilot Chat Export', '========================', ''];
            $msgs.find('.cap-m').each(function(){
                var role = $(this).hasClass('cap-m-user') ? 'You' : 'WPilot';
                var text = $(this).find('.cap-mt').text().trim();
                if (text) { lines.push('[' + role + '] ' + text); lines.push(''); }
            });
            var blob = new Blob([lines.join('\n')], {type:'text/plain'});
            var url  = URL.createObjectURL(blob);
            var a    = document.createElement('a');
            a.href = url; a.download = 'wpilot-chat-' + new Date().toISOString().slice(0,10) + '.txt';
            a.click(); URL.revokeObjectURL(url);
        });

        // File upload handler
        $(document).on('change', '#capFileUpload', function() {
            var file = this.files[0];
            if (!file) return;
            this.value = '';
            appendMessage('user', 'Uploading: ' + escHtml(file.name) + ' (' + Math.round(file.size/1024) + 'KB)');
            scrollToBottom();
            var formData = new FormData();
            formData.append('file', file);
            formData.append('action', 'wpi_upload_file');
            formData.append('nonce', CA.nonce);
            $.ajax({
                url: CA.ajax_url, type: 'POST', data: formData,
                processData: false, contentType: false,
                success: function(res) {
                    if (res && res.success) {
                        appendMessage('ai', escHtml(res.data.message || 'File uploaded.'));
                    } else {
                        appendMessage('ai', '<span class="cap-err">Uppladdningen misslyckades.</span>');
                    }
                    scrollToBottom();
                },
                error: function() {
                    appendMessage('ai', '<span class="cap-err">Nätverksfel vid uppladdning.</span>');
                    scrollToBottom();
                }
            });
        });


        // ── Load session on init ──────────────────────────────
        loadSession();
        startHeartbeat();
    });

    // ── Session management ────────────────────────────────────

    function loadSession() {
        $.post(CA.ajax_url, {
            action: 'wpilot_load_session',
            nonce: CA.nonce
        }, function(r) {
            if (r && r.success && r.data) {
                sessionKey = r.data.session_key || '';
                sessionTTL = r.data.ttl || 0;
                var msgs = r.data.messages || [];

                if (sessionKey && msgs.length) {
                    // Resume existing session
                    for (var i = 0; i < msgs.length; i++) {
                        var m = msgs[i];
                        if (m.role === 'user') {
                            appendMessage('user', escHtml(m.content));
                        } else {
                            appendMessage('ai', cleanResponse(m.content));
                        }
                        chatHistory.push({role: m.role, content: m.content});
                    }
                    if ($welcome.length && msgs.length) $welcome.hide();
                    scrollToBottom();
                    updateSessionIndicator();
                }
                // If no session, show welcome — user will get a session on first message
            }
        });
    }

    function ensureSession(callback) {
        if (sessionKey) {
            // Check if still alive
            $.post(CA.ajax_url, {
                action: 'wpilot_session_ping',
                nonce: CA.nonce,
                session_key: sessionKey
            }, function(r) {
                if (r && r.success && r.data && r.data.alive) {
                    sessionTTL = r.data.ttl;
                    updateSessionIndicator();
                    callback(sessionKey);
                } else {
                    // Session expired, start new one
                    createNewSession(callback);
                }
            });
        } else {
            createNewSession(callback);
        }
    }

    function createNewSession(callback) {
        $.post(CA.ajax_url, {
            action: 'wpilot_new_session',
            nonce: CA.nonce
        }, function(r) {
            if (r && r.success && r.data) {
                sessionKey = r.data.session_key;
                sessionTTL = 1800; // 30 min
                updateSessionIndicator();
                if (callback) callback(sessionKey);
            }
        });
    }

    function startNewChat(afterCallback) {
        // End current session
        if (sessionKey) {
            $.post(CA.ajax_url, {
                action: 'wpilot_end_session',
                nonce: CA.nonce,
                session_key: sessionKey
            });
        }
        // Clear UI
        sessionKey = '';
        chatHistory = [];
        $msgs.find('.cap-m, .cap-actions, .cap-actions-group').remove();
        if ($welcome.length) $welcome.fadeIn(150);
        updateSessionIndicator();

        // Create new session
        createNewSession(function() {
            if (afterCallback) {
                afterCallback();
            } else {
                appendMessage('ai', (CA.i18n&&CA.i18n.new_chat)||'New chat started! How can I help?');
                scrollToBottom();
            }
        });
    }

    function startHeartbeat() {
        // Check session every 60 seconds
        heartbeatTimer = setInterval(function() {
            if (!sessionKey) return;
            $.post(CA.ajax_url, {
                action: 'wpilot_session_ping',
                nonce: CA.nonce,
                session_key: sessionKey
            }, function(r) {
                if (r && r.success && r.data) {
                    if (!r.data.alive) {
                        handleSessionExpired();
                    } else {
                        sessionTTL = r.data.ttl;
                        updateSessionIndicator();
                        // Warn if about to expire
                        if (sessionTTL > 0 && sessionTTL <= SESSION_WARN_SECS) {
                            showSessionWarning();
                        }
                    }
                }
            });
        }, 60000);
    }

    function handleSessionExpired() {
        sessionKey = '';
        chatHistory = [];
        updateSessionIndicator();

        // Show friendly expiry message
        appendMessage('ai', (CA.i18n&&CA.i18n.session_expired)||'Chat paused due to inactivity. Start a new chat to continue!');
        $input.prop('disabled', true).attr('placeholder', (CA.i18n&&CA.i18n.session_expired)||'Session expired');
        scrollToBottom();
    }

    function showSessionWarning() {
        var existing = document.getElementById('wpiSessionWarn');
        if (existing) return; // Already showing
        var mins = Math.ceil(sessionTTL / 60);
        var warn = $('<div id="wpiSessionWarn" style="padding:8px 12px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.2);border-radius:8px;margin:6px 14px;font-size:12px;color:#FCD34D;text-align:center">' +
            (CA.i18n&&CA.i18n.session_expired)||'Chat closing soon due to inactivity.' +
        '</div>');
        $msgs.append(warn);
        scrollToBottom();
        // Auto-remove after reply
        setTimeout(function(){ $('#wpiSessionWarn').fadeOut(300, function(){ $(this).remove(); }); }, 30000);
    }

    function updateSessionIndicator() {
        var $dot = $('.cap-status-dot');
        var $label = $('.cap-status-label');
        if (!$dot.length) return;

        if (sessionKey && sessionTTL > 0) {
            $dot.removeClass('cap-status-offline').addClass('cap-status-online');
            $label.text('Online');
            $input.prop('disabled', false).attr('placeholder', (CA.i18n&&CA.i18n.type_message)||'Type a message...');
        } else if (!sessionKey) {
            $dot.removeClass('cap-status-online').addClass('cap-status-offline');
            $label.text('Redo');
        }
    }

    // ── Send message to server ────────────────────────────────
    function sendMessage() {
        if (isSending) return;
        var msg = ($input.val() || '').trim();
        if (!msg) return;

        isSending = true;
        $input.val('').css('height', 'auto');

        // Remove session warning if showing
        $('#wpiSessionWarn').remove();

        // Hide welcome screen
        if ($welcome.length) $welcome.fadeOut(150);

        // Add user message to UI
        appendMessage('user', escHtml(msg));
        chatHistory.push({role: 'user', content: msg});

        // Show typing indicator
        $typing.show();
        thinkStart = Date.now();
        $('#capThinkText').text((CA.i18n&&CA.i18n.thinking)||'Thinking...');
        $('#capThinkTimer').text('');
        if (thinkTimer) clearInterval(thinkTimer);
        thinkTimer = setInterval(function() {
            var secs = Math.floor((Date.now() - thinkStart) / 1000);
            if (secs > 3) $('#capThinkText').text((CA.i18n&&CA.i18n.working)||'Working on it...');
            if (secs > 10) $('#capThinkText').text((CA.i18n&&CA.i18n.almost_done)||'Almost done...');
            if (secs > 25) $('#capThinkText').text((CA.i18n&&CA.i18n.almost_done)||'Almost done...');
        }, 1000);
        scrollToBottom();

        // Ensure we have an active session before sending
        ensureSession(function(key) {
            var context = JSON.stringify({
                page: CA.page_title || document.title || 'unknown',
                url: CA.current_url || window.location.href,
                post_id: CA.current_post_id || 0,
                post_type: CA.current_post_type || '',
                is_front_page: CA.is_front_page || 'no',
                site: {name: CA.site_name, url: CA.site_url}
            });

            $.post(CA.ajax_url, {
                action: 'ca_chat',
                nonce: CA.nonce,
                message: msg,
                mode: currentMode,
                history: JSON.stringify(chatHistory.slice(-20)),
                context: context,
                session_key: key
            })
            .done(function(r){
                $typing.hide();
                if (thinkTimer) { clearInterval(thinkTimer); thinkTimer = null; }
                isSending = false;

                if (r && r.success && r.data) {
                    var d = r.data;
                    // Update session info
                    if (d.session_key) sessionKey = d.session_key;
                    if (d.session_ttl) sessionTTL = d.session_ttl;
                    updateSessionIndicator();

                    var html = formatResponse(d.response, d.actions, d.source);
                    appendMessage('ai', html, d.source);
                    chatHistory.push({role: 'assistant', content: d.response});

                    if (typeof d.used !== 'undefined') CA.used = d.used;
                    if (d.locked) {
                        $input.prop('disabled', true).attr('placeholder', 'Gratisgränsen nådd — uppgradera för att fortsätta.');
                    }
                } else {
                    var errMsg = (r && r.data) ? (typeof r.data === 'string' ? r.data : r.data.message || 'Something went wrong') : 'Connection error.';
                    appendMessage('ai', '<span class="cap-err">' + escHtml(errMsg) + '</span>');
                }
                scrollToBottom();
            })
            .fail(function(xhr){
                $typing.hide();
                if (thinkTimer) { clearInterval(thinkTimer); thinkTimer = null; }
                isSending = false;
                var errText = 'Request failed.';
                if (xhr.status === 0) errText = 'No connection. Check your internet.';
                if (xhr.status === 403) errText = 'Access denied.';
                if (xhr.status === 429) errText = 'Too many requests. Please wait.';
                appendMessage('ai', '<span class="cap-err">' + errText + '</span>');
                scrollToBottom();
            });
        });
    }

    // ── Clean response text — strip ALL technical content ─────
    function cleanResponse(text) {
        if (!text) return '';
        // Strip [ACTION:...] blocks
        text = text.replace(/\[ACTION:[^\]]*\]/g, '');
        // Strip fenced code blocks entirely
        text = text.replace(/```[\s\S]*?```/g, '');
        // Strip inline code backticks but keep the text inside
        text = text.replace(/`([^`]+)`/g, '$1');
        // Strip any remaining JSON-like blocks
        text = text.replace(/\{[\s\S]*?"[\w]+"[\s\S]*?\}/g, '');
        // Strip HTML tags that leaked through
        text = text.replace(/<\/?(?:style|script|div|span|a|pre|code)[^>]*>/gi, '');
        // Strip CSS-like content
        text = text.replace(/[a-z-]+\s*:\s*[^;]+;/g, '');
        // Strip file paths
        text = text.replace(/\/[\w\/-]+\.\w{2,4}/g, '');
        // Clean up excessive whitespace
        text = text.replace(/\n{3,}/g, '\n\n').trim();
        return mdToHtml(text);
    }

    // ── Format AI response with action cards ──────────────────
    function formatResponse(text, actions, source) {
        var html = cleanResponse(text);

        // Action cards (admin/editor only)
        var cards = '';
        if (actions && actions.length && (CA.is_admin === 'yes' || CA.can_modify === 'yes')) {
            cards = '<div class="cap-actions">';
            for (var i = 0; i < actions.length; i++) {
                var a = actions[i];
                var autoStatus = a.auto_status || '';
                var cardClass  = 'cap-ac';
                if (autoStatus === 'done')   cardClass += ' cap-ac-done';
                if (autoStatus === 'failed') cardClass += ' cap-ac-fail';

                cards += '<div class="' + cardClass + '" data-tool="' + escAttr(a.tool) + '" data-params=\'' + escAttr(JSON.stringify(a.params || {})) + '\'>';
                cards += '<div style="flex:1;min-width:0">';
                cards += '<div class="cap-ac-title">' + escHtml(a.icon || '') + ' ' + escHtml(a.label) + '</div>';
                cards += '<div class="cap-ac-desc">' + escHtml(a.description || '') + '</div>';

                if (autoStatus === 'done') {
                    cards += '<div class="cap-ac-btns"><span style="color:var(--ca-green);font-weight:700;font-size:11.5px">Klart</span></div>';
                } else if (autoStatus === 'failed') {
                    var errText = a.auto_error ? escHtml(a.auto_error) : 'Failed';
                    cards += '<div class="cap-ac-err" style="font-size:11px;color:#FCA5A5;margin-top:4px">' + errText + '</div>';
                    cards += '<div class="cap-ac-btns"><button class="cap-ac-apply">Försök igen</button></div>';
                } else {
                    cards += '<div class="cap-ac-btns">';
                    cards += '<button class="cap-ac-apply">Tillämpa</button>';
                    cards += '<button class="cap-ac-skip">Hoppa över</button>';
                    cards += '</div>';
                }
                cards += '</div></div>';
            }
            cards += '</div>';
        }

        return html + cards;
    }

    // ── Apply action card ─────────────────────────────────────
    $(document).on('click', '.cap-ac-apply', function(){
        var $card = $(this).closest('.cap-ac');
        var tool = $card.data('tool');
        var params = $card.data('params') || {};
        var $btn = $(this);
        var $skipBtn = $card.find('.cap-ac-skip');

        $btn.text((CA.i18n&&CA.i18n.working)||'Working...').prop('disabled', true);
        if ($skipBtn.length) $skipBtn.hide();

        $.post(CA.ajax_url, {
            action: 'ca_tool',
            nonce: CA.nonce,
            tool: tool,
            params: JSON.stringify(params)
        }, function(r){
            if (r && r.success) {
                $card.addClass('cap-ac-done').css('border-left-color', '#10B981');
                $btn.text((CA.i18n&&CA.i18n.done_btn)||'Done').css({'background':'#10B981','cursor':'default'});
                var msg = (r.data && r.data.message) ? r.data.message : (CA.i18n&&CA.i18n.done_btn)||'Done';
                $card.append('<div style="margin-top:8px;padding:8px 10px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.18);border-radius:7px;font-size:12px;color:#6EE7B7">' + escHtml(msg) + '</div>');
                if (r.data && r.data.backup_id && window.wpiSetLastBackup) {
                    window.wpiSetLastBackup(r.data.backup_id);
                }
            } else {
                $card.css('border-left-color', '#EF4444');
                $btn.text((CA.i18n&&CA.i18n.retry_btn)||'Retry').prop('disabled', false).css('background','#EF4444');
                if ($skipBtn.length) $skipBtn.show();
                var err = (r && r.data) ? (r.data.message || 'Failed') : 'Failed';
                $card.append('<div style="margin-top:8px;padding:8px 10px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.18);border-radius:7px;font-size:12px;color:#FCA5A5">' + escHtml(err) + '</div>');
            }
            scrollToBottom();
        }).fail(function(){
            $btn.text('Fel').css('background','#EF4444').prop('disabled', false);
            if ($skipBtn.length) $skipBtn.show();
            scrollToBottom();
        });
    });

    // Skip action card
    $(document).on('click', '.cap-ac-skip', function(){
        $(this).closest('.cap-ac').slideUp(200, function(){ $(this).remove(); });
    });

    // ── Append message to chat ────────────────────────────────
    function appendMessage(role, content, source) {
        var isAi = (role === 'ai' || role === 'assistant');
        var cls = isAi ? 'cap-m-ai' : 'cap-m-user';
        var av  = isAi ? '<div class="cap-av">' + String.fromCodePoint(0x26A1) + '</div>' : '';
        var $m = $('<div class="cap-m ' + cls + '">' + av + '<div class="cap-mt">' + content + '</div></div>');
        if ($typing && $typing.length) {
            $typing.before($m);
        } else {
            $msgs.append($m);
        }
    }

    // ── Markdown to HTML ─────────────────────────────────────
    function mdToHtml(text) {
        // Strip fenced code blocks completely
        text = text.replace(/```[\s\S]*?```/g, '');
        text = escHtml(text);
        // Bold
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Headings → just bold text
        text = text.replace(/^#{1,3}\s+(.+)$/gm, '<div style="font-weight:700;margin:6px 0 3px">$1</div>');
        // Bullet lists
        text = text.replace(/^[-*]\s+(.+)$/gm, '<div style="padding-left:12px;margin:2px 0">&bull; $1</div>');
        // Numbered lists
        text = text.replace(/^(\d+)\.\s+(.+)$/gm, '<div style="padding-left:12px;margin:2px 0">$1. $2</div>');
        // Line breaks
        text = text.replace(/\n/g, '<br>');
        text = text.replace(/(<br>){3,}/g, '<br><br>');
        return text;
    }

    // ── Helpers ───────────────────────────────────────────────
    function scrollToBottom() {
        if ($msgs && $msgs.length) {
            setTimeout(function(){ $msgs.scrollTop($msgs[0].scrollHeight); }, 50);
        }
    }

    function escHtml(s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(s)));
        return div.innerHTML;
    }

    function escAttr(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // Claude login button handler
    $(document).on('click', '#capClaudeLogin', function(){
        var b=$(this), s=$('#capLoginStatus');
        b.text('Hämtar länk...').prop('disabled',true);
        $.post(CA.ajax_url,{action:'wpilot_claude_login',nonce:CA.nonce},function(r){
            if(r.success && r.data && r.data.url){
                s.html('<span style="color:#10B981">Öppnar inloggning...</span>');
                window.location.href = r.data.url;
                b.text('Jag har loggat in').prop('disabled',false);
                b.off('click').on('click',function(){
                    b.text('Kontrollerar...').prop('disabled',true);
                    $.post(CA.ajax_url,{action:'wpilot_check_login',nonce:CA.nonce},function(r2){
                        if(r2.success && r2.data && r2.data.ready) location.reload();
                        else{s.html('<span style="color:#F87171">Inte redo än. Slutför inloggningen i den andra fliken först.</span>');b.text('Kontrollera igen').prop('disabled',false);}
                    });
                });
            } else {
                s.html('<span style="color:#F87171">'+(r.data||'Fel vid hämtning av länk')+'</span>');
                b.text((CA.i18n&&CA.i18n.retry_btn)||'Retry').prop('disabled',false);
            }
        }).fail(function(){ s.html('<span style="color:#F87171">Anslutningsfel</span>'); b.text((CA.i18n&&CA.i18n.retry_btn)||'Retry').prop('disabled',false); });
    });

})(jQuery);

// ── OAuth Flow — Connect Claude Account ──────────────────────
(function($){
    $(function(){
        if (typeof CA === "undefined") return;

        // Start OAuth — open Claude login
        $(document).on("click", "#wpiOAuthStart", function(){
            var $btn = $(this);
            var $step2 = $("#wpiOAuthStep2");
            var $status = $("#wpiOAuthStatus");

            $btn.text("Oppnar Claude...").prop("disabled", true);

            $.post(CA.ajax_url, {
                action: "wpilot_oauth_start",
                nonce: CA.nonce
            }, function(r){
                if (r && r.success && r.data && r.data.url) {
                    // Open Claude login in new tab
                    window.open(r.data.url, "_blank");
                    // Show step 2 — paste code
                    $btn.text("Vantar pa kod...").css("opacity","0.6");
                    $step2.slideDown(200);
                    $("#wpiOAuthCode").focus();
                } else {
                    $status.html("<span style=\"color:#EF4444\">" + ((r && r.data) || "Fel vid start") + "</span>").show();
                    $btn.text("Anslut Claude-konto").prop("disabled", false);
                }
            }).fail(function(){
                $status.html("<span style=\"color:#EF4444\">Natverksfel</span>").show();
                $btn.text("Anslut Claude-konto").prop("disabled", false);
            });
        });

        // Exchange code for token
        $(document).on("click", "#wpiOAuthExchange", function(){
            var $btn = $(this);
            var $status = $("#wpiOAuthStatus");
            var code = $.trim($("#wpiOAuthCode").val());

            if (!code) {
                $status.html("<span style=\"color:#EF4444\">Ange koden du fick fran Claude</span>").show();
                return;
            }

            $btn.text("Ansluter...").prop("disabled", true);
            $status.html("<span style=\"color:var(--ca-text2)\">Verifierar kod...</span>").show();

            $.post(CA.ajax_url, {
                action: "wpilot_oauth_exchange",
                nonce: CA.nonce,
                code: code
            }, function(r){
                if (r && r.success) {
                    $status.html("<span style=\"color:#10B981\">Ansluten! Laddar om...</span>").show();
                    $btn.text("Ansluten!").css("background", "#10B981");
                    setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    var msg = (r && r.data) ? (typeof r.data === "string" ? r.data : r.data.message || "Fel") : "Fel";
                    $status.html("<span style=\"color:#EF4444\">" + msg + "</span>").show();
                    $btn.text("Forsok igen").prop("disabled", false);
                }
            }).fail(function(){
                $status.html("<span style=\"color:#EF4444\">Natverksfel</span>").show();
                $btn.text("Forsok igen").prop("disabled", false);
            });
        });

        // Enter key in code input
        $(document).on("keydown", "#wpiOAuthCode", function(e){
            if (e.key === "Enter") {
                e.preventDefault();
                $("#wpiOAuthExchange").click();
            }
        });
    });
})(jQuery);
