/**
 * WPilot — Bubble Chat JS
 * Handles the floating chat bubble UI, message sending, and action cards.
 */
(function($){
    'use strict';

    if (typeof CA === 'undefined') return;

    var $trigger, $panel, $msgs, $input, $send, $typing, $welcome;
    var chatHistory = [];
    var currentMode = 'chat';
    var isSending = false;
    var thinkTimer = null;
    var thinkStart = 0;

    // ── Init on DOM ready ─────────────────────────────────────
    $(function(){
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
            if (msg) {
                $input.val(msg);
                sendMessage();
            }
        });

        // Quick prompt buttons (full chat page)
        $(document).on('click', '.ca-qp', function(){
            var msg = $(this).data('msg');
            if (msg) {
                $input.val(msg);
                sendMessage();
            }
        });

        // Alt+C keyboard shortcut
        $(document).on('keydown', function(e){
            if (e.altKey && (e.key === 'c' || e.key === 'C')) {
                e.preventDefault();
                $trigger.click();
            }
        });

        // Clear chat button
        $('#capClearChat').on('click', function(e){
            e.stopPropagation();
            $.post(CA.ajax_url, { action: 'ca_clear_history', nonce: CA.nonce });
            chatHistory = [];
            $msgs.find('.cap-m, .cap-actions, .cap-actions-group').remove();
            appendMessage('ai', 'Chat cleared. How can I help?');
            scrollToBottom();
        });

        // Restart + re-scan button
        $('#capRestartScan').on('click', function(e){
            e.stopPropagation();
            $.post(CA.ajax_url, { action: 'ca_clear_history', nonce: CA.nonce });
            chatHistory = [];
            $msgs.find('.cap-m, .cap-actions, .cap-actions-group').remove();
            appendMessage('ai', 'Scanning your WordPress site...');
            scrollToBottom();
            $.post(CA.ajax_url, {action:'wpi_smart_scan', nonce:CA.nonce}, function(r) {
                $msgs.find('.cap-m:last').remove();
                if (r && r.success && r.data) {
                    appendMessage('ai', r.data.scan || 'Ready! Tell me what you need.');
                } else {
                    appendMessage('ai', 'Ready! Tell me what you need.');
                }
                scrollToBottom();
            });
        });

        // Export chat
        $('#capExportChat').on('click', function(e){
            e.stopPropagation();
            var lines = ['WPilot Chat Export', '========================', ''];
            $msgs.find('.cap-m').each(function(){
                var role = $(this).hasClass('cap-m-user') ? 'You' : 'AI';
                var text = $(this).find('.cap-mt').text().trim();
                if (text) {
                    lines.push('[' + role + '] ' + text);
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
                        appendMessage('ai', '<span class="cap-err">Upload failed.</span>');
                    }
                    scrollToBottom();
                },
                error: function() {
                    appendMessage('ai', '<span class="cap-err">Upload failed — network error.</span>');
                    scrollToBottom();
                }
            });
        });

        // Connect API key from bubble
        $(document).on('click', '#capConnectBtn', function() {
            var $btn = $(this);
            var $inp = $('#capApiKeyInput');
            var $st  = $('#capConnectStatus');
            var key  = $.trim($inp.val());
            if (!key || key.indexOf('sk-') !== 0) {
                $st.text('Enter a valid Claude API key (starts with sk-)').css('color','#EF4444').show();
                return;
            }
            $btn.text('Connecting...').prop('disabled', true);
            $st.text('Testing connection...').css('color','var(--ca-text2)').show();
            $.post(CA.ajax_url, { action: 'ca_test_connection', nonce: CA.nonce, key: key })
            .done(function(res) {
                if (res && res.success) {
                    $st.text('Connected! Reloading...').css('color','#10B981');
                    setTimeout(function(){ location.reload(); }, 1000);
                } else {
                    var msg = (res && res.data) ? (typeof res.data === 'string' ? res.data : res.data.message || 'Failed') : 'Failed';
                    $st.text(msg).css('color','#EF4444');
                    $btn.text('Connect & Start').prop('disabled', false);
                }
            })
            .fail(function() {
                $st.text('Network error').css('color','#EF4444');
                $btn.text('Connect & Start').prop('disabled', false);
            });
        });
        $(document).on('keydown', '#capApiKeyInput', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); $('#capConnectBtn').click(); }
        });

        // Load history on init
        loadHistory();
    });

    // ── Send message to server ────────────────────────────────
    function sendMessage() {
        if (isSending) return;
        var msg = ($input.val() || '').trim();
        if (!msg) return;

        isSending = true;
        $input.val('').css('height', 'auto');

        // Built by Christos Ferlachidis & Daniel Hedenberg

        // Hide welcome screen
        if ($welcome.length) $welcome.fadeOut(150);

        // Add user message to UI
        appendMessage('user', escHtml(msg));
        chatHistory.push({role: 'user', content: msg});

        // Show typing indicator with timer
        $typing.show();
        thinkStart = Date.now();
        $('#capThinkText').text('Thinking...');
        $('#capThinkTimer').text('0s');
        if (thinkTimer) clearInterval(thinkTimer);
        thinkTimer = setInterval(function() {
            var secs = Math.floor((Date.now() - thinkStart) / 1000);
            $('#capThinkTimer').text(secs + 's');
            if (secs > 5) $('#capThinkText').text('Still working...');
            if (secs > 15) $('#capThinkText').text('Almost there...');
            if (secs > 30) $('#capThinkText').text('Complex request...');
        }, 1000);
        scrollToBottom();

        // Build page-aware context
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
            context: context
        })
        .done(function(r){
            $typing.hide();
            if (thinkTimer) { clearInterval(thinkTimer); thinkTimer = null; }
            isSending = false;

            if (r && r.success && r.data) {
                var d = r.data;
                var html = formatResponse(d.response, d.actions, d.source);
                appendMessage('ai', html, d.source);
                chatHistory.push({role: 'assistant', content: d.response});

                // Update prompt counter
                if (typeof d.used !== 'undefined') {
                    CA.used = d.used;
                }

                // Check if locked
                if (d.locked) {
                    $input.prop('disabled', true).attr('placeholder', 'Free limit reached — upgrade to continue.');
                }
            } else {
                var errMsg = (r && r.data) ? (typeof r.data === 'string' ? r.data : r.data.message || 'Unknown error') : 'Connection error.';
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
            if (xhr.status === 401) errText = 'API key invalid or expired.';
            if (xhr.status === 403) errText = 'Permission denied.';
            if (xhr.status === 429) errText = 'Rate limited. Wait and try again.';
            appendMessage('ai', '<span class="cap-err">' + errText + '</span>');
            scrollToBottom();
        });
    }

    // ── Format AI response with action cards ──────────────────
    function formatResponse(text, actions, source) {
        // Strip [ACTION:...] blocks then convert markdown to HTML
        var clean = (text || '').replace(/\[ACTION:[^\]]+\]/g, '').trim();
        var html = mdToHtml(clean);

        // Source badge
        var badge = '';
        if (source === 'brain') badge = '<span class="cap-source-badge cap-source-brain">Brain</span>';
        else if (source === 'webleas') badge = '<span class="cap-source-badge cap-source-wpilot">WPilot AI</span>';

        // Action cards
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
                    cards += '<div class="cap-ac-btns"><span style="color:var(--ca-green);font-weight:700;font-size:11.5px">Done (auto)</span></div>';
                } else if (autoStatus === 'failed') {
                    var errText = a.auto_error ? escHtml(a.auto_error) : 'Failed';
                    cards += '<div class="cap-ac-err" style="font-size:11px;color:#FCA5A5;margin-top:4px">Auto-failed: ' + errText + '</div>';
                    cards += '<div class="cap-ac-btns"><button class="cap-ac-apply">Retry</button></div>';
                } else {
                    cards += '<div class="cap-ac-btns">';
                    cards += '<button class="cap-ac-apply">Apply</button>';
                    cards += '<button class="cap-ac-skip">Skip</button>';
                    cards += '</div>';
                }
                cards += '</div></div>';
            }
            cards += '</div>';
        }

        return badge + html + cards;
    }

    // ── Apply action card ─────────────────────────────────────
    $(document).on('click', '.cap-ac-apply', function(){
        var $card = $(this).closest('.cap-ac');
        var tool = $card.data('tool');
        var params = $card.data('params') || {};
        var $btn = $(this);
        var $skipBtn = $card.find('.cap-ac-skip');

        $btn.text('Applying...').prop('disabled', true);
        if ($skipBtn.length) $skipBtn.hide();

        $.post(CA.ajax_url, {
            action: 'ca_tool',
            nonce: CA.nonce,
            tool: tool,
            params: JSON.stringify(params)
        }, function(r){
            if (r && r.success) {
                $card.addClass('cap-ac-done');
                $card.css('border-left-color', '#10B981');
                $btn.text('Done').css({'background':'#10B981','cursor':'default'});
                var msg = (r.data && r.data.message) ? r.data.message : 'Done';
                $card.append('<div style="margin-top:8px;padding:8px 10px;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.18);border-radius:7px;font-size:12px;color:#6EE7B7">' + escHtml(msg) + '</div>');
                // Store backup for undo
                if (r.data && r.data.backup_id && window.wpiSetLastBackup) {
                    window.wpiSetLastBackup(r.data.backup_id);
                }
            } else {
                $card.css('border-left-color', '#EF4444');
                $btn.text('Retry').prop('disabled', false).css('background','#EF4444');
                if ($skipBtn.length) $skipBtn.show();
                var err = (r && r.data) ? (r.data.message || 'Failed') : 'Failed';
                $card.append('<div style="margin-top:8px;padding:8px 10px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.18);border-radius:7px;font-size:12px;color:#FCA5A5">' + escHtml(err) + '</div>');
            }
            scrollToBottom();
        }).fail(function(){
            $btn.text('Error').css('background','#EF4444').prop('disabled', false);
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
        var $m = $('<div class="cap-m ' + cls + '">' +
            av +
            '<div class="cap-mt">' + content + '</div>' +
        '</div>');
        // Insert before typing indicator, keeping it at the end
        if ($typing && $typing.length) {
            $typing.before($m);
        } else {
            $msgs.append($m);
        }
    }

    // ── Load chat history ─────────────────────────────────────
    function loadHistory() {
        $.post(CA.ajax_url, {
            action: 'wpi_load_history',
            nonce: CA.nonce
        }, function(r){
            if (r && r.success && r.data && r.data.length) {
                // Only load last 10 messages for bubble
                var recent = r.data.slice(-10);
                for (var i = 0; i < recent.length; i++) {
                    var m = recent[i];
                    if (m.role === 'user') {
                        appendMessage('user', escHtml(m.content));
                    } else if (m.role === 'assistant') {
                        var html = formatResponse(m.content, m.actions || [], m.source || 'claude');
                        appendMessage('ai', html, m.source);
                    }
                    chatHistory.push({role: m.role, content: m.content});
                }
                if ($welcome.length && recent.length) $welcome.hide();
                scrollToBottom();
            }
        });
    }

    // ── Markdown to HTML ─────────────────────────────────────
    function mdToHtml(text) {
        text = escHtml(text);
        // Bold
        text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Headings
        text = text.replace(/^#{1,3}\s+(.+)$/gm, '<div style="font-weight:700;font-size:13px;margin:8px 0 4px">$1</div>');
        // Bullet lists
        text = text.replace(/^[-*]\s+(.+)$/gm, '<div style="padding-left:12px;margin:2px 0">&bull; $1</div>');
        // Numbered lists
        text = text.replace(/^(\d+)\.\s+(.+)$/gm, '<div style="padding-left:12px;margin:2px 0">$1. $2</div>');
        // Inline code
        text = text.replace(/`([^`]+)`/g, '<code>$1</code>');
        // Blockquotes
        text = text.replace(/^&gt;\s*(.+)$/gm, '<div style="border-left:3px solid var(--ca-border2);padding-left:8px;color:var(--ca-text2);font-style:italic;margin:4px 0">$1</div>');
        // Line breaks (clean up excessive ones)
        text = text.replace(/\n/g, '<br>');
        text = text.replace(/(<br>){3,}/g, '<br><br>');
        return text;
    }

    // ── Helpers ───────────────────────────────────────────────
    function scrollToBottom() {
        if ($msgs && $msgs.length) {
            setTimeout(function(){
                $msgs.scrollTop($msgs[0].scrollHeight);
            }, 50);
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

})(jQuery);
