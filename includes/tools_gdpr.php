<?php
if (!defined('ABSPATH')) exit;

/**
 * WPilot GDPR Compliance Tools Module
 * Contains 6 tool cases for GDPR/privacy operations.
 */
function wpilot_run_gdpr_tools($tool, $params = []) {
    switch ($tool) {

        // ═════════════════════════════════════════════════════
        //  1. gdpr_audit — Scan site for GDPR issues
        // ═════════════════════════════════════════════════════
        case 'gdpr_audit':
            $issues = [];

            // Check privacy policy page
            $privacy_id = (int) get_option('wp_page_for_privacy_policy', 0);
            if (!$privacy_id) {
                $issues[] = ['severity' => 'critical', 'issue' => 'No privacy policy page configured', 'fix' => 'Go to Settings → Privacy or use gdpr_configure to set one.'];
            } else {
                $privacy_post = get_post($privacy_id);
                if (!$privacy_post || $privacy_post->post_status !== 'publish') {
                    $issues[] = ['severity' => 'critical', 'issue' => 'Privacy policy page exists but is not published', 'fix' => 'Publish the privacy policy page (ID: ' . $privacy_id . ').'];
                } elseif (str_word_count(strip_tags($privacy_post->post_content)) < 100) {
                    $issues[] = ['severity' => 'warning', 'issue' => 'Privacy policy page is very short (< 100 words)', 'fix' => 'Add comprehensive privacy information.'];
                }
            }

            // Check cookie consent banner plugins
            $active = get_option('active_plugins', []);
            $cookie_plugins = [
                'cookie-law-info',
                'cookieyes',
                'gdpr-cookie-consent',
                'cookie-notice',
                'complianz-gdpr',
                'real-cookie-banner',
                'iubenda-cookie-law-solution',
            ];
            $has_cookie_banner = false;
            foreach ($active as $p) {
                foreach ($cookie_plugins as $cp) {
                    if (strpos($p, $cp) !== false) { $has_cookie_banner = true; break 2; }
                }
            }
            // Also check WPilot mu-module cookie banner
            $mu_modules = get_option('wpilot_mu_modules', []);
            if (isset($mu_modules['cookie-banner'])) $has_cookie_banner = true;

            if (!$has_cookie_banner) {
                $issues[] = ['severity' => 'critical', 'issue' => 'No cookie consent banner detected', 'fix' => 'Use gdpr_cookie_banner tool to install one, or install CookieYes/Complianz.'];
            }

            // Check for analytics scripts without consent mechanism
            $head_code = get_option('wpilot_head_code', '');
            $analytics_patterns = ['google-analytics', 'gtag', 'ga.js', 'analytics.js', 'fbevents.js', 'facebook.*pixel', 'hotjar', 'clarity.ms'];
            $unguarded_scripts = [];
            foreach ($analytics_patterns as $pat) {
                if (preg_match('/' . $pat . '/i', $head_code)) {
                    $unguarded_scripts[] = $pat;
                }
            }
            if (!empty($unguarded_scripts) && !$has_cookie_banner) {
                $issues[] = ['severity' => 'critical', 'issue' => 'Analytics/tracking scripts found without cookie consent: ' . implode(', ', $unguarded_scripts), 'fix' => 'Install cookie banner and block these scripts until user consents.'];
            }

            // Check comment form consent
            $comment_consent = get_option('show_comments_cookies_opt_in', 0);
            if (get_option('default_comment_status') === 'open' && !$comment_consent) {
                $issues[] = ['severity' => 'warning', 'issue' => 'Comment form cookie consent checkbox not enabled', 'fix' => 'Enable under Settings → Discussion → "Show comments cookies opt-in checkbox".'];
            }

            // Check data retention — WPilot form entries
            $retention = get_option('wpilot_gdpr_retention_days', 0);
            if (!$retention) {
                $issues[] = ['severity' => 'info', 'issue' => 'No automatic data retention policy configured', 'fix' => 'Use gdpr_configure to set retention_days for form entries.'];
            }

            // Check SSL
            if (!is_ssl()) {
                $issues[] = ['severity' => 'warning', 'issue' => 'Site not served over HTTPS', 'fix' => 'Install SSL certificate and force HTTPS.'];
            }

            // Check user registration
            if (get_option('users_can_register')) {
                // Check if registration page mentions data processing
                $issues[] = ['severity' => 'info', 'issue' => 'User registration is open — ensure registration form includes privacy notice', 'fix' => 'Add privacy policy link to registration page.'];
            }

            // WooCommerce checks
            if (class_exists('WooCommerce')) {
                $woo_privacy = get_option('woocommerce_erasure_request_removes_order_data', 'no');
                if ($woo_privacy !== 'yes') {
                    $issues[] = ['severity' => 'info', 'issue' => 'WooCommerce: order data not set to anonymize on erasure request', 'fix' => 'Go to WooCommerce → Settings → Accounts & Privacy.'];
                }
            }

            $critical = count(array_filter($issues, function($i) { return $i['severity'] === 'critical'; }));
            $warnings = count(array_filter($issues, function($i) { return $i['severity'] === 'warning'; }));
            $info     = count(array_filter($issues, function($i) { return $i['severity'] === 'info'; }));

            $score = 100 - ($critical * 25) - ($warnings * 10) - ($info * 3);
            $score = max(0, min(100, $score));

            if (empty($issues)) {
                return wpilot_ok('GDPR audit passed — no issues found.', ['score' => 100, 'issues' => []]);
            }

            return wpilot_ok("GDPR audit complete: {$critical} critical, {$warnings} warnings, {$info} info.", [
                'score'    => $score,
                'issues'   => $issues,
                'critical' => $critical,
                'warnings' => $warnings,
                'info'     => $info,
            ]);

        // ═════════════════════════════════════════════════════
        //  2. gdpr_export_user_data — DSAR export
        // ═════════════════════════════════════════════════════
        case 'gdpr_export_user_data':
            $email   = sanitize_email($params['email'] ?? '');
            $user_id = intval($params['user_id'] ?? 0);

            if (!$email && $user_id) {
                $user = get_userdata($user_id);
                if ($user) $email = $user->user_email;
            }
            if (!$email) return wpilot_err('Email or user_id required.');

            $user = get_user_by('email', $email);
            $format = sanitize_text_field($params['format'] ?? 'json');

            // Use WordPress built-in personal data exporters
            $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
            $all_data  = [];

            foreach ($exporters as $exporter_key => $exporter) {
                if (!is_callable($exporter['callback'])) continue;
                $page = 1;
                do {
                    $response = call_user_func($exporter['callback'], $email, $page);
                    if (is_wp_error($response)) break;
                    $data = $response['data'] ?? [];
                    $done = $response['done'] ?? true;
                    foreach ($data as $group) {
                        $group_label = $group['group_label'] ?? $exporter_key;
                        if (!isset($all_data[$group_label])) $all_data[$group_label] = [];
                        foreach ($group['data'] ?? [] as $item) {
                            $all_data[$group_label][] = [
                                'name'  => $item['name'] ?? '',
                                'value' => $item['value'] ?? '',
                            ];
                        }
                    }
                    $page++;
                } while (!$done && $page < 100);
            }

            // Also export WPilot form entries
            global $wpdb;
            $entries_table = $wpdb->prefix . 'wpi_form_entries';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$entries_table}'") === $entries_table) {
                $entries = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM {$entries_table} WHERE data LIKE %s ORDER BY created_at DESC",
                    '%' . $wpdb->esc_like($email) . '%'
                ));
                if ($entries) {
                    $all_data['WPilot Form Entries'] = [];
                    foreach ($entries as $entry) {
                        $entry_data = json_decode($entry->data, true);
                        $all_data['WPilot Form Entries'][] = [
                            'entry_id'   => $entry->id,
                            'form_id'    => $entry->form_id,
                            'data'       => $entry_data,
                            'submitted'  => $entry->created_at,
                        ];
                    }
                }
            }
            if (empty($all_data)) {
                return wpilot_ok("No personal data found for {$email}.", ['email' => $email, 'data' => []]);
            }

            // Generate downloadable file
            $upload_dir = wp_upload_dir();
            $filename   = 'wpilot-gdpr-export-' . sanitize_file_name($email) . '-' . date('Y-m-d-His');

            if ($format === 'csv') {
                $csv_path = $upload_dir['basedir'] . '/' . $filename . '.csv';
                $fp = fopen($csv_path, 'w');
                fputcsv($fp, ['Category', 'Field', 'Value']);
                foreach ($all_data as $group => $items) {
                    foreach ($items as $item) {
                        if (isset($item['name'])) {
                            fputcsv($fp, [$group, $item['name'], $item['value']]);
                        } else {
                            fputcsv($fp, [$group, '', wp_json_encode($item)]);
                        }
                    }
                }
                fclose($fp);
                $file_url = $upload_dir['baseurl'] . '/' . $filename . '.csv';
            } else {
                $json_path = $upload_dir['basedir'] . '/' . $filename . '.json';
                file_put_contents($json_path, wp_json_encode([
                    'export_date' => current_time('mysql'),
                    'email'       => $email,
                    'data'        => $all_data,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $file_url = $upload_dir['baseurl'] . '/' . $filename . '.json';
            }

            // Schedule file cleanup after 48 hours
            wp_schedule_single_event(time() + 172800, 'wpilot_cleanup_gdpr_export', [$format === 'csv' ? $csv_path : $json_path]);

            $group_count = count($all_data);
            return wpilot_ok("Exported personal data for {$email} ({$group_count} categories).", [
                'email'      => $email,
                'categories' => $group_count,
                'format'     => $format,
                'download'   => $file_url,
                'expires'    => '48 hours',
            ]);

        // ═════════════════════════════════════════════════════
        //  3. gdpr_delete_user_data — Right to be forgotten
        // ═════════════════════════════════════════════════════
        case 'gdpr_delete_user_data':
            $email   = sanitize_email($params['email'] ?? '');
            $user_id = intval($params['user_id'] ?? 0);
            $confirm = ($params['confirm'] ?? '') === 'yes';

            if (!$email && $user_id) {
                $user = get_userdata($user_id);
                if ($user) $email = $user->user_email;
            }
            if (!$email) return wpilot_err('Email or user_id required.');
            if (!$confirm) return wpilot_err('This action is destructive. Pass confirm: "yes" to proceed. This will anonymize orders, delete comments, form entries, and user meta for ' . $email . '.');

            $user    = get_user_by('email', $email);
            $results = [];

            // Use WordPress built-in erasers
            $erasers = apply_filters('wp_privacy_personal_data_erasers', []);
            $items_removed  = 0;
            $items_retained = 0;

            foreach ($erasers as $eraser_key => $eraser) {
                if (!is_callable($eraser['callback'])) continue;
                $page = 1;
                do {
                    $response = call_user_func($eraser['callback'], $email, $page);
                    if (is_wp_error($response)) break;
                    $items_removed  += intval($response['items_removed'] ?? 0);
                    $items_retained += intval($response['items_retained'] ?? 0);
                    $done = $response['done'] ?? true;
                    if (!empty($response['messages'])) {
                        $results = array_merge($results, (array) $response['messages']);
                    }
                    $page++;
                } while (!$done && $page < 100);
            }

            // Delete WPilot form entries containing this email
            global $wpdb;
            $entries_table = $wpdb->prefix . 'wpi_form_entries';
            $form_deleted  = 0;
            if ($wpdb->get_var("SHOW TABLES LIKE '{$entries_table}'") === $entries_table) {
                $form_deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$entries_table} WHERE data LIKE %s",
                    '%' . $wpdb->esc_like($email) . '%'
                ));
            }

            // Delete WPilot subscriber entry
            $subs_table = $wpdb->prefix . 'wpi_subscribers';
            $sub_deleted = 0;
            if ($wpdb->get_var("SHOW TABLES LIKE '{$subs_table}'") === $subs_table) {
                $sub_deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$subs_table} WHERE email = %s",
                    $email
                ));
            }

            // Anonymize user meta if user exists but keep the account shell
            if ($user) {
                $meta_keys_to_delete = [
                    'first_name', 'last_name', 'nickname', 'description',
                    'billing_first_name', 'billing_last_name', 'billing_company',
                    'billing_address_1', 'billing_address_2', 'billing_city',
                    'billing_postcode', 'billing_phone', 'billing_email',
                    'shipping_first_name', 'shipping_last_name', 'shipping_company',
                    'shipping_address_1', 'shipping_address_2', 'shipping_city',
                    'shipping_postcode',
                ];
                $meta_cleared = 0;
                foreach ($meta_keys_to_delete as $mk) {
                    if (get_user_meta($user->ID, $mk, true)) {
                        delete_user_meta($user->ID, $mk);
                        $meta_cleared++;
                    }
                }
                $results[] = "Cleared {$meta_cleared} user meta fields.";
            }

            return wpilot_ok("Right to be forgotten processed for {$email}.", [
                'email'          => $email,
                'items_removed'  => $items_removed,
                'items_retained' => $items_retained,
                'form_entries'   => $form_deleted,
                'subscribers'    => $sub_deleted,
                'details'        => $results,
            ]);

        // ═════════════════════════════════════════════════════
        //  4. gdpr_cookie_banner — Install consent banner
        // ═════════════════════════════════════════════════════
        case 'gdpr_cookie_banner':
            $action = sanitize_text_field($params['action'] ?? 'install');

            if ($action === 'remove') {
                if (function_exists('wpilot_mu_remove')) {
                    wpilot_mu_remove('cookie-banner');
                }
                delete_option('wpilot_cookie_banner_config');
                return wpilot_ok('Cookie consent banner removed.');
            }

            // Banner configuration
            $config = [
                'title'            => sanitize_text_field($params['title'] ?? 'We use cookies'),
                'message'          => wp_kses_post($params['message'] ?? 'This website uses cookies to ensure you get the best experience. Some cookies are necessary for the site to work, while others help us improve your experience.'),
                'accept_text'      => sanitize_text_field($params['accept_text'] ?? 'Accept All'),
                'reject_text'      => sanitize_text_field($params['reject_text'] ?? 'Reject Non-Essential'),
                'settings_text'    => sanitize_text_field($params['settings_text'] ?? 'Cookie Settings'),
                'privacy_url'      => esc_url($params['privacy_url'] ?? get_privacy_policy_url()),
                'position'         => in_array($params['position'] ?? 'bottom', ['bottom', 'top', 'center']) ? $params['position'] : 'bottom',
                'categories'       => $params['categories'] ?? ['necessary', 'analytics', 'marketing'],
                'expire_days'      => intval($params['expire_days'] ?? 365),
            ];

            update_option('wpilot_cookie_banner_config', $config, false);
// Built by Weblease
            // Build category checkboxes HTML
            $cat_labels = [
                'necessary' => ['label' => 'Necessary', 'desc' => 'Required for the site to function. Cannot be disabled.', 'required' => true],
                'analytics' => ['label' => 'Analytics', 'desc' => 'Help us understand how visitors use the site.', 'required' => false],
                'marketing' => ['label' => 'Marketing', 'desc' => 'Used for targeted advertising and tracking.', 'required' => false],
                'preferences' => ['label' => 'Preferences', 'desc' => 'Remember your settings and preferences.', 'required' => false],
            ];

            $categories_html = '';
            foreach ((array)$config['categories'] as $cat) {
                $cat = sanitize_key($cat);
                $info = $cat_labels[$cat] ?? ['label' => ucfirst($cat), 'desc' => '', 'required' => false];
                $checked = $info['required'] ? 'checked disabled' : '';
                $categories_html .= '<label class="wpi-cb-cat">'
                    . '<input type="checkbox" name="wpi_cookie_' . esc_attr($cat) . '" value="1" ' . $checked . '> '
                    . '<strong>' . esc_html($info['label']) . '</strong>'
                    . ($info['desc'] ? '<span class="wpi-cb-desc">' . esc_html($info['desc']) . '</span>' : '')
                    . '</label>';
            }

            $position_css = '';
            if ($config['position'] === 'top') {
                $position_css = 'top:0;bottom:auto;';
            } elseif ($config['position'] === 'center') {
                $position_css = 'top:50%;bottom:auto;transform:translateX(-50%) translateY(-50%);max-width:600px;border-radius:12px;';
            }

            $privacy_link = $config['privacy_url'] ? '<a href="' . esc_url($config['privacy_url']) . '" target="_blank" style="color:var(--wpi-cb-link,#93c5fd);text-decoration:underline;">Privacy Policy</a>' : '';

            $expire_days = intval($config['expire_days']);

            // Generate mu-plugin PHP code
            $mu_code = <<<'MUPHP'
// WPilot Cookie Consent Banner — GDPR + CCPA compliant
add_action('wp_footer', function() {
    if (is_admin()) return;
    $config = get_option('wpilot_cookie_banner_config', []);
    if (empty($config)) return;
    $title = esc_html($config['title'] ?? 'We use cookies');
    $message = wp_kses_post($config['message'] ?? '');
    $accept = esc_attr($config['accept_text'] ?? 'Accept All');
    $reject = esc_attr($config['reject_text'] ?? 'Reject Non-Essential');
    $settings_text = esc_attr($config['settings_text'] ?? 'Cookie Settings');
    $privacy_url = esc_url($config['privacy_url'] ?? '');
    $expire = intval($config['expire_days'] ?? 365);
    $categories = $config['categories'] ?? ['necessary','analytics','marketing'];
    $position_css = '';
    $pos = $config['position'] ?? 'bottom';
    if ($pos === 'top') $position_css = 'top:0;bottom:auto;';
    elseif ($pos === 'center') $position_css = 'top:50%;bottom:auto;transform:translateX(-50%) translateY(-50%);max-width:600px;border-radius:12px;';

    $cat_labels = [
        'necessary' => ['label' => 'Necessary', 'desc' => 'Required for the site to function.', 'req' => true],
        'analytics' => ['label' => 'Analytics', 'desc' => 'Help us understand site usage.', 'req' => false],
        'marketing' => ['label' => 'Marketing', 'desc' => 'Used for targeted advertising.', 'req' => false],
        'preferences' => ['label' => 'Preferences', 'desc' => 'Remember your settings.', 'req' => false],
    ];
    $cats_html = '';
    foreach ($categories as $c) {
        $c = sanitize_key($c);
        $info = $cat_labels[$c] ?? ['label' => ucfirst($c), 'desc' => '', 'req' => false];
        $chk = $info['req'] ? 'checked disabled' : '';
        $cats_html .= '<label class="wpi-cb-cat"><input type="checkbox" data-cat="'.esc_attr($c).'" '.
            $chk.'> <strong>'.esc_html($info['label']).'</strong>'.
            '<span class="wpi-cb-desc">'.esc_html($info['desc']).'</span></label>';
    }
    $priv_link = $privacy_url ? '<a href="'.$privacy_url.'" target="_blank" class="wpi-cb-privacy">Privacy Policy</a>' : '';
    ?>
    <div id="wpi-cookie-banner" style="display:none;<?php echo $position_css; ?>">
        <div class="wpi-cb-inner">
            <div class="wpi-cb-text">
                <strong class="wpi-cb-title"><?php echo $title; ?></strong>
                <p><?php echo $message; ?> <?php echo $priv_link; ?></p>
            </div>
            <div class="wpi-cb-categories" style="display:none;">
                <?php echo $cats_html; ?>
            </div>
            <div class="wpi-cb-buttons">
                <button id="wpi-cb-accept" class="wpi-cb-btn wpi-cb-btn-accept"><?php echo $accept; ?></button>
                <button id="wpi-cb-reject" class="wpi-cb-btn wpi-cb-btn-reject"><?php echo $reject; ?></button>
                <button id="wpi-cb-settings" class="wpi-cb-btn wpi-cb-btn-settings"><?php echo $settings_text; ?></button>
            </div>
        </div>
    </div>
    <style>
    #wpi-cookie-banner{position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:900px;z-index:999999;padding:20px;box-sizing:border-box;font-family:var(--wpi-font-body,-apple-system,BlinkMacSystemFont,sans-serif);
        background:var(--wpi-cb-bg,#1e293b);color:var(--wpi-cb-text,#e2e8f0);border-top:2px solid var(--wpi-cb-border,#334155);box-shadow:0 -4px 20px rgba(0,0,0,.3)}
    #wpi-cookie-banner.wpi-cb-top{top:0;bottom:auto;border-top:none;border-bottom:2px solid var(--wpi-cb-border,#334155)}
    .wpi-cb-inner{max-width:860px;margin:0 auto}
    .wpi-cb-title{font-size:18px;display:block;margin-bottom:6px;color:var(--wpi-cb-heading,#f8fafc)}
    .wpi-cb-text p{margin:4px 0 12px;font-size:14px;line-height:1.5;color:var(--wpi-cb-text,#cbd5e1)}
    .wpi-cb-privacy{color:var(--wpi-cb-link,#93c5fd)!important;text-decoration:underline}
    .wpi-cb-buttons{display:flex;gap:10px;flex-wrap:wrap}
    .wpi-cb-btn{padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px;font-weight:600;transition:opacity .2s}
    .wpi-cb-btn:hover{opacity:.85}
    .wpi-cb-btn-accept{background:var(--wpi-cb-accept-bg,#22c55e);color:var(--wpi-cb-accept-text,#fff)}
    .wpi-cb-btn-reject{background:var(--wpi-cb-reject-bg,#475569);color:var(--wpi-cb-reject-text,#e2e8f0)}
    .wpi-cb-btn-settings{background:transparent;color:var(--wpi-cb-link,#93c5fd);border:1px solid var(--wpi-cb-border,#475569);font-size:13px}
    .wpi-cb-categories{margin:12px 0;padding:12px;background:var(--wpi-cb-cat-bg,#0f172a);border-radius:8px}
    .wpi-cb-cat{display:flex;align-items:flex-start;gap:8px;margin:8px 0;font-size:14px;cursor:pointer}
    .wpi-cb-cat input{margin-top:3px}
    .wpi-cb-desc{display:block;font-size:12px;color:var(--wpi-cb-text,#94a3b8);margin-top:2px}
    @media(max-width:600px){#wpi-cookie-banner{padding:14px;max-width:100%;border-radius:0!important;transform:none;left:0}
        .wpi-cb-buttons{flex-direction:column}.wpi-cb-btn{width:100%;text-align:center}}
    </style>
    <script>
    (function(){
        var B=document.getElementById('wpi-cookie-banner');
        if(!B)return;
        var consent=getCookie('wpi_cookie_consent');
        if(consent){applyConsent(JSON.parse(consent));return;}
        B.style.display='block';
        var cats=B.querySelector('.wpi-cb-categories');
        document.getElementById('wpi-cb-accept').onclick=function(){saveConsent(true);};
        document.getElementById('wpi-cb-reject').onclick=function(){saveConsent(false);};
        document.getElementById('wpi-cb-settings').onclick=function(){
            cats.style.display=cats.style.display==='none'?'block':'none';
        };
        function saveConsent(all){
            var c={necessary:true,timestamp:Date.now()};
            var inputs=B.querySelectorAll('input[data-cat]');
            for(var i=0;i<inputs.length;i++){
                var cat=inputs[i].getAttribute('data-cat');
                c[cat]=all?true:(inputs[i].checked||inputs[i].disabled);
            }
            setCookie('wpi_cookie_consent',JSON.stringify(c),<?php echo $expire; ?>);
            B.style.display='none';
            applyConsent(c);
            // GPC/CCPA: set opt-out signal
            if(!all&&navigator.globalPrivacyControl){
                setCookie('wpi_ccpa_optout','1',<?php echo $expire; ?>);
            }
        }
        function applyConsent(c){
            document.querySelectorAll('script[data-cookie-category]').forEach(function(s){
                var cat=s.getAttribute('data-cookie-category');
                if(c[cat]){
                    var n=document.createElement('script');
                    if(s.src)n.src=s.src;else n.textContent=s.textContent;
                    n.type='text/javascript';
                    s.parentNode.replaceChild(n,s);
                }
            });
            // Fire event for other scripts to listen
            document.dispatchEvent(new CustomEvent('wpi_consent_updated',{detail:c}));
        }
        function setCookie(n,v,d){var e=new Date();e.setDate(e.getDate()+d);document.cookie=n+'='+encodeURIComponent(v)+';expires='+e.toUTCString()+';path=/;SameSite=Lax;Secure';}
        function getCookie(n){var m=document.cookie.match('(^|;)\\s*'+n+'=([^;]*)');return m?decodeURIComponent(m[2]):null;}
    })();
    </script>
    <?php
}, 999);

// Block scripts with data-cookie-category from loading until consent
add_action('wp_head', function() {
    ?>
    <script>
    // WPilot: change script types to prevent execution until consent
    document.addEventListener('DOMContentLoaded', function(){
        var consent = (function(){try{var c=document.cookie.match('(^|;)\\s*wpi_cookie_consent=([^;]*)');return c?JSON.parse(decodeURIComponent(c[2])):null;}catch(e){return null;}})();
        if(!consent) return;
        document.querySelectorAll('script[data-cookie-category]').forEach(function(s){
            var cat = s.getAttribute('data-cookie-category');
            if(consent[cat]){
                var n = document.createElement('script');
                if(s.src) n.src = s.src; else n.textContent = s.textContent;
                n.type = 'text/javascript';
                s.parentNode.replaceChild(n, s);
            }
        });
    });
    </script>
    <?php
}, 1);
MUPHP;

            if (function_exists('wpilot_mu_register')) {
                $result = wpilot_mu_register('cookie-banner', $mu_code);
                if ($result === false) {
                    return wpilot_err('Failed to write cookie banner mu-plugin. Check file permissions on wp-content/mu-plugins/.');
                }
            } else {
                return wpilot_err('MU consolidator not loaded. Cannot install cookie banner.');
            }

            return wpilot_ok('Cookie consent banner installed.', [
                'position'   => $config['position'],
                'categories' => $config['categories'],
                'privacy'    => $config['privacy_url'],
                'usage'      => 'Add data-cookie-category="analytics" or data-cookie-category="marketing" to script tags to block them until consent.',
            ]);

        // ═════════════════════════════════════════════════════
        //  5. gdpr_configure — Set retention & auto-cleanup
        // ═════════════════════════════════════════════════════
        case 'gdpr_configure':
            $retention_days        = intval($params['retention_days'] ?? 0);
            $anonymize_orders_days = intval($params['anonymize_orders_after'] ?? 0);
            $enable_consent_log    = ($params['consent_logging'] ?? 'yes') === 'yes';

            $config = get_option('wpilot_gdpr_config', []);

            if ($retention_days > 0) {
                $config['retention_days'] = $retention_days;
                update_option('wpilot_gdpr_retention_days', $retention_days);
            }
            if ($anonymize_orders_days > 0) {
                $config['anonymize_orders_after'] = $anonymize_orders_days;
            }
            $config['consent_logging'] = $enable_consent_log;
            $config['updated_at'] = current_time('mysql');

            update_option('wpilot_gdpr_config', $config, false);

            // Schedule or update WP-Cron for data cleanup
            $hook = 'wpilot_gdpr_cleanup';
            wp_clear_scheduled_hook($hook);

            if ($retention_days > 0 || $anonymize_orders_days > 0) {
                if (!wp_next_scheduled($hook)) {
                    wp_schedule_event(time(), 'daily', $hook);
                }
            }

            $summary = [];
            if ($retention_days > 0) $summary[] = "Form entries auto-delete after {$retention_days} days";
            if ($anonymize_orders_days > 0) $summary[] = "Orders anonymized after {$anonymize_orders_days} days";
            if ($enable_consent_log) $summary[] = 'Consent logging enabled';

            return wpilot_ok('GDPR configuration saved. ' . implode('. ', $summary) . '.', $config);

        // ═════════════════════════════════════════════════════
        //  6. gdpr_status — Current compliance status
        // ═════════════════════════════════════════════════════
        case 'gdpr_status':
            $config     = get_option('wpilot_gdpr_config', []);
            $retention  = intval(get_option('wpilot_gdpr_retention_days', 0));
            $privacy_id = (int) get_option('wp_page_for_privacy_policy', 0);
            $mu_modules = get_option('wpilot_mu_modules', []);

            $status = [
                'privacy_policy'     => $privacy_id ? get_permalink($privacy_id) : false,
                'cookie_banner'      => isset($mu_modules['cookie-banner']) ? 'installed' : 'not installed',
                'retention_days'     => $retention ?: 'not set',
                'anonymize_orders'   => $config['anonymize_orders_after'] ?? 'not set',
                'consent_logging'    => $config['consent_logging'] ?? false,
                'ssl_enabled'        => is_ssl(),
                'comment_consent'    => (bool) get_option('show_comments_cookies_opt_in', 0),
                'cron_active'        => (bool) wp_next_scheduled('wpilot_gdpr_cleanup'),
                'last_configured'    => $config['updated_at'] ?? 'never',
            ];

            // Count pending data requests
            $requests = get_posts([
                'post_type'   => ['user_request'],
                'post_status' => 'request-pending',
                'numberposts' => -1,
            ]);
            $status['pending_requests'] = count($requests);

            $checks_passed = 0;
            $total_checks  = 6;
            if ($status['privacy_policy']) $checks_passed++;
            if ($status['cookie_banner'] === 'installed') $checks_passed++;
            if ($status['ssl_enabled']) $checks_passed++;
            if ($status['comment_consent']) $checks_passed++;
            if ($status['retention_days'] !== 'not set') $checks_passed++;
            if ($status['cron_active']) $checks_passed++;

            $status['compliance_score'] = round(($checks_passed / $total_checks) * 100);

            return wpilot_ok("GDPR status: {$checks_passed}/{$total_checks} checks passed ({$status['compliance_score']}%).", $status);

        default:
            return null;
    }
}

// ── GDPR Cron: auto-clean old data ──────────────────────────
add_action('wpilot_gdpr_cleanup', 'wpilot_gdpr_run_cleanup');
function wpilot_gdpr_run_cleanup() {
    global $wpdb;
    $config = get_option('wpilot_gdpr_config', []);

    // Delete old form entries
    $retention = intval($config['retention_days'] ?? 0);
    if ($retention > 0) {
        $entries_table = $wpdb->prefix . 'wpi_form_entries';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$entries_table}'") === $entries_table) {
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention} days"));
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$entries_table} WHERE created_at < %s",
                $cutoff
            ));
            if ($deleted > 0) {
                error_log("[WPilot GDPR] Auto-deleted {$deleted} form entries older than {$retention} days.");
            }
        }
    }

    // Anonymize old WooCommerce orders
    $anon_days = intval($config['anonymize_orders_after'] ?? 0);
    if ($anon_days > 0 && class_exists('WooCommerce')) {
        $cutoff_date = date('Y-m-d', strtotime("-{$anon_days} days"));
        $orders = wc_get_orders([
            'date_created' => '<' . $cutoff_date,
            'limit'        => 50,
            'meta_query'   => [
                [
                    'key'     => '_wpi_gdpr_anonymized',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ]);

        $anonymized = 0;
        foreach ($orders as $order) {
            $order->set_billing_first_name('Anonymized');
            $order->set_billing_last_name('');
            $order->set_billing_email('anonymized-' . $order->get_id() . '@removed.invalid');
            $order->set_billing_phone('');
            $order->set_billing_address_1('');
            $order->set_billing_address_2('');
            $order->set_shipping_first_name('');
            $order->set_shipping_last_name('');
            $order->set_shipping_address_1('');
            $order->set_shipping_address_2('');
            $order->update_meta_data('_wpi_gdpr_anonymized', current_time('mysql'));
            $order->save();
            $anonymized++;
        }

        if ($anonymized > 0) {
            error_log("[WPilot GDPR] Auto-anonymized {$anonymized} orders older than {$anon_days} days.");
        }
    }
}

// ── Cleanup exported GDPR files ─────────────────────────────
add_action('wpilot_cleanup_gdpr_export', function($file_path) {
    if (file_exists($file_path)) {
        @unlink($file_path);
    }
});
