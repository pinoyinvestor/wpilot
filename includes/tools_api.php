<?php
if (!defined('ABSPATH')) exit;

/**
 * WPilot Api Tools Module
 * Contains 41 tool cases for api operations.
 */
function wpilot_run_api_tools($tool, $params = []) {
    switch ($tool) {

        case 'api_call':
        case 'http_request':
        case 'connect_api':
            $url = $params['url'] ?? '';
            $method = strtoupper($params['method'] ?? 'GET');
            $headers = $params['headers'] ?? [];
            $body = $params['body'] ?? $params['data'] ?? '';
            $api_key_name = $params['api_key'] ?? '';
            if (empty($url)) return wpilot_err('URL required. Example: url="https://api.stripe.com/v1/charges"');
            // Security: block internal/private IPs
            $host = parse_url($url, PHP_URL_HOST);
            if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0']) || preg_match('/^(10\.|172\.(1[6-9]|2|3[01])\.|192\.168\.)/', gethostbyname($host))) {
                return wpilot_err('Cannot call internal/private IP addresses.');
            }
            // Load stored API key if referenced
            if ($api_key_name) {
                $keys = get_option('wpilot_api_keys', []);
                if (isset($keys[$api_key_name])) {
                    $key_data = $keys[$api_key_name];
                    // Auto-add auth header
                    if ($key_data['type'] === 'bearer') $headers['Authorization'] = 'Bearer ' . $key_data['key'];
                    elseif ($key_data['type'] === 'basic') $headers['Authorization'] = 'Basic ' . base64_encode($key_data['key']);
                    elseif ($key_data['type'] === 'header') $headers[$key_data['header_name']] = $key_data['key'];
                }
            }
            $wp_args = ['method' => $method, 'timeout' => 30, 'headers' => $headers, 'sslverify' => true];
            if ($body && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $wp_args['body'] = is_array($body) ? wp_json_encode($body) : $body;
                if (!isset($headers['Content-Type'])) $wp_args['headers']['Content-Type'] = 'application/json';
            }
            $response = wp_remote_request($url, $wp_args);
            if (is_wp_error($response)) return wpilot_err('API call failed: ' . $response->get_error_message());
            $code = wp_remote_retrieve_response_code($response);
            $resp_body = wp_remote_retrieve_body($response);
            $json = json_decode($resp_body, true);
            return wpilot_ok("API {$method} {$url} — HTTP {$code}", [
                'status' => $code,
                'response' => $json ?: substr($resp_body, 0, 5000),
                'headers' => wp_remote_retrieve_headers($response)->getAll(),
            ]);

        // ═══ STORE API KEYS SECURELY ═══
        case 'save_api_key':
        case 'store_api_key':
            $name = sanitize_key($params['name'] ?? '');
            $key = $params['key'] ?? $params['value'] ?? '';
            $type = sanitize_text_field($params['type'] ?? 'bearer'); // bearer, basic, header, query
            $header_name = sanitize_text_field($params['header_name'] ?? 'X-API-Key');
            if (empty($name) || empty($key)) return wpilot_err('name and key required. Example: name="stripe", key="sk_live_..."');
            $keys = get_option('wpilot_api_keys', []);
            $keys[$name] = ['key' => $key, 'type' => $type, 'header_name' => $header_name, 'added' => date('Y-m-d H:i:s')];
            update_option('wpilot_api_keys', $keys);
            return wpilot_ok("API key '{$name}' saved ({$type} auth).", ['name' => $name, 'type' => $type]);

        case 'list_api_keys':
            $keys = get_option('wpilot_api_keys', []);
            $list = [];
            foreach ($keys as $name => $data) {
                $list[] = ['name' => $name, 'type' => $data['type'], 'added' => $data['added'] ?? '', 'key_preview' => substr($data['key'], 0, 8) . '...'];
            }
            return wpilot_ok(count($list) . " API keys stored.", ['keys' => $list]);

        case 'delete_api_key':
            $name = sanitize_key($params['name'] ?? '');
            $keys = get_option('wpilot_api_keys', []);
            if (isset($keys[$name])) { unset($keys[$name]); update_option('wpilot_api_keys', $keys); return wpilot_ok("Deleted key: {$name}"); }
            return wpilot_err("Key not found: {$name}");

        // ═══ WEBHOOK RECEIVER — Create endpoints that external services can call ═══
        case 'create_webhook':
        case 'add_webhook':
            $slug = sanitize_title($params['slug'] ?? $params['name'] ?? '');
            $action = sanitize_text_field($params['action'] ?? $params['tool'] ?? '');
            $secret = $params['secret'] ?? wp_generate_password(32, false);
            if (empty($slug)) return wpilot_err('slug required. Creates endpoint: /wp-json/wpilot/v1/webhook/{slug}');
            $webhooks = get_option('wpilot_webhooks', []);
            $webhooks[$slug] = ['action' => $action, 'secret' => $secret, 'created' => date('Y-m-d H:i:s'), 'calls' => 0];
            update_option('wpilot_webhooks', $webhooks);
            $endpoint = get_rest_url(null, "wpilot/v1/webhook/{$slug}");
            return wpilot_ok("Webhook created: {$endpoint}", ['url' => $endpoint, 'secret' => $secret, 'slug' => $slug]);

        case 'list_webhooks':
            $webhooks = get_option('wpilot_webhooks', []);
            $list = [];
            foreach ($webhooks as $slug => $wh) {
                $list[] = ['slug' => $slug, 'url' => get_rest_url(null, "wpilot/v1/webhook/{$slug}"), 'action' => $wh['action'], 'calls' => $wh['calls']];
            }
            return wpilot_ok(count($list) . " webhooks.", ['webhooks' => $list]);

        case 'delete_webhook':
            $slug = sanitize_title($params['slug'] ?? '');
            $webhooks = get_option('wpilot_webhooks', []);
            if (isset($webhooks[$slug])) { unset($webhooks[$slug]); update_option('wpilot_webhooks', $webhooks); return wpilot_ok("Deleted webhook: {$slug}"); }
            return wpilot_err("Webhook not found: {$slug}");

        // ═══ PRE-BUILT INTEGRATIONS ═══
        // Built by Christos Ferlachidis & Daniel Hedenberg
        case 'connect_stripe':
            $key = $params['key'] ?? $params['secret_key'] ?? '';
            $pub = $params['publishable_key'] ?? $params['public_key'] ?? '';
            if (empty($key)) return wpilot_err('Stripe secret key required (sk_live_... or sk_test_...)');
            // Store keys
            $keys = get_option('wpilot_api_keys', []);
            $keys['stripe'] = ['key' => $key, 'type' => 'bearer', 'header_name' => '', 'added' => date('Y-m-d H:i:s')];
            update_option('wpilot_api_keys', $keys);
            // If WooCommerce Stripe is active, configure it
            if (class_exists('WooCommerce')) {
                update_option('woocommerce_stripe_settings', array_merge(
                    get_option('woocommerce_stripe_settings', []),
                    ['enabled' => 'yes', 'testmode' => (strpos($key, 'sk_test_') === 0) ? 'yes' : 'no',
                     'secret_key' => $key, 'publishable_key' => $pub,
                     'test_secret_key' => (strpos($key, 'sk_test_') === 0) ? $key : '',
                     'test_publishable_key' => (strpos($pub, 'pk_test_') === 0) ? $pub : '']
                ));
            }
            // Test connection
            $test = wp_remote_get('https://api.stripe.com/v1/balance', ['headers' => ['Authorization' => 'Bearer ' . $key], 'timeout' => 10]);
            $ok = !is_wp_error($test) && wp_remote_retrieve_response_code($test) === 200;
            $balance = $ok ? json_decode(wp_remote_retrieve_body($test), true) : null;
            return wpilot_ok("Stripe " . ($ok ? "connected! Balance available." : "key saved but connection test failed."), [
                'connected' => $ok,
                'test_mode' => strpos($key, 'sk_test_') === 0,
                'balance' => $balance ? ($balance['available'][0]['amount']/100 . ' ' . strtoupper($balance['available'][0]['currency'])) : null,
            ]);

        case 'connect_google_analytics':
        case 'add_analytics':
            $id = sanitize_text_field($params['measurement_id'] ?? $params['ga_id'] ?? $params['id'] ?? '');
            if (empty($id) || !preg_match('/^G-[A-Z0-9]+$/', $id)) return wpilot_err('Google Analytics Measurement ID required (G-XXXXXXXXXX).');
            $script = "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$id}\"></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$id}');</script>";
            $existing = get_option('wpilot_head_code', '');
            if (strpos($existing, $id) !== false) return wpilot_ok("Analytics already installed: {$id}");
            update_option('wpilot_head_code', $existing . "\n" . $script);
            return wpilot_ok("Google Analytics connected: {$id}", ['measurement_id' => $id]);

        case 'connect_facebook_pixel':
        case 'add_pixel':
            $pixel_id = sanitize_text_field($params['pixel_id'] ?? $params['id'] ?? '');
            if (empty($pixel_id) || !preg_match('/^\d+$/', $pixel_id)) return wpilot_err('Facebook Pixel ID required (numeric).');
            $script = "<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$pixel_id}');fbq('track','PageView');</script>";
            $existing = get_option('wpilot_head_code', '');
            if (strpos($existing, $pixel_id) !== false) return wpilot_ok("Pixel already installed.");
            update_option('wpilot_head_code', $existing . "\n" . $script);
            return wpilot_ok("Facebook Pixel connected: {$pixel_id}", ['pixel_id' => $pixel_id]);

        case 'connect_mailchimp':
            $api_key = $params['key'] ?? $params['api_key'] ?? '';
            if (empty($api_key) || strpos($api_key, '-') === false) return wpilot_err('Mailchimp API key required (ends with -usXX).');
            $dc = substr($api_key, strpos($api_key, '-') + 1);
            // Store key
            $keys = get_option('wpilot_api_keys', []);
            $keys['mailchimp'] = ['key' => $api_key, 'type' => 'basic', 'dc' => $dc, 'added' => date('Y-m-d H:i:s')];
            update_option('wpilot_api_keys', $keys);
            // Test — get lists
            $test = wp_remote_get("https://{$dc}.api.mailchimp.com/3.0/lists?count=5", [
                'headers' => ['Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key)], 'timeout' => 10
            ]);
            $ok = !is_wp_error($test) && wp_remote_retrieve_response_code($test) === 200;
            $lists = [];
            if ($ok) {
                $data = json_decode(wp_remote_retrieve_body($test), true);
                foreach (($data['lists'] ?? []) as $l) $lists[] = ['id' => $l['id'], 'name' => $l['name'], 'members' => $l['stats']['member_count']];
            }
            return wpilot_ok("Mailchimp " . ($ok ? "connected! " . count($lists) . " lists found." : "key saved but test failed."), ['connected' => $ok, 'lists' => $lists]);

        case 'mailchimp_add_subscriber':
            $email = sanitize_email($params['email'] ?? '');
            $list_id = $params['list_id'] ?? '';
            $name = sanitize_text_field($params['name'] ?? '');
            if (empty($email)) return wpilot_err('email required.');
            $keys = get_option('wpilot_api_keys', []);
            if (!isset($keys['mailchimp'])) return wpilot_err('Mailchimp not connected. Use connect_mailchimp first.');
            $api_key = $keys['mailchimp']['key'];
            $dc = $keys['mailchimp']['dc'];
            // If no list_id, get the first list
            if (empty($list_id)) {
                $lists = wp_remote_get("https://{$dc}.api.mailchimp.com/3.0/lists?count=1", [
                    'headers' => ['Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key)], 'timeout' => 10
                ]);
                if (!is_wp_error($lists)) {
                    $data = json_decode(wp_remote_retrieve_body($lists), true);
                    $list_id = $data['lists'][0]['id'] ?? '';
                }
            }
            if (empty($list_id)) return wpilot_err('No Mailchimp list found. Provide list_id.');
            $body = ['email_address' => $email, 'status' => 'subscribed'];
            if ($name) {
                $parts = explode(' ', $name, 2);
                $body['merge_fields'] = ['FNAME' => $parts[0], 'LNAME' => $parts[1] ?? ''];
            }
            $result = wp_remote_post("https://{$dc}.api.mailchimp.com/3.0/lists/{$list_id}/members", [
                'headers' => ['Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key), 'Content-Type' => 'application/json'],
                'body' => wp_json_encode($body), 'timeout' => 10
            ]);
            $code = wp_remote_retrieve_response_code($result);
            return ($code === 200 || $code === 201) ? wpilot_ok("Added {$email} to Mailchimp.") : wpilot_err("Failed: HTTP {$code}");

        case 'connect_google_maps':
        case 'add_google_maps':
            $api_key = $params['key'] ?? $params['api_key'] ?? '';
            if (empty($api_key)) return wpilot_err('Google Maps API key required.');
            $keys = get_option('wpilot_api_keys', []);
            $keys['google_maps'] = ['key' => $api_key, 'type' => 'query', 'added' => date('Y-m-d H:i:s')];
            update_option('wpilot_api_keys', $keys);
            $script = "<script src=\"https://maps.googleapis.com/maps/api/js?key={$api_key}\" async defer></script>";
            $existing = get_option('wpilot_head_code', '');
            if (strpos($existing, 'maps.googleapis.com') === false) update_option('wpilot_head_code', $existing . "\n" . $script);
            return wpilot_ok("Google Maps connected.", ['api_key' => substr($api_key, 0, 8) . '...']);

        case 'add_map':
        case 'embed_map':
            $address = $params['address'] ?? $params['location'] ?? '';
            $page_id = intval($params['page_id'] ?? 0);
            $width = $params['width'] ?? '100%';
            $height = $params['height'] ?? '400px';
            if (empty($address)) return wpilot_err('address or location required.');
            $encoded = urlencode($address);
// Built by Christos Ferlachidis & Daniel Hedenberg
            $keys = get_option('wpilot_api_keys', []);
            if (isset($keys['google_maps'])) {
                $map_html = "<div style=\"border-radius:16px;overflow:hidden;margin:24px 0\"><iframe width=\"{$width}\" height=\"{$height}\" style=\"border:0;width:100%\" loading=\"lazy\" referrerpolicy=\"no-referrer-when-downgrade\" src=\"https://www.google.com/maps/embed/v1/place?key=" . $keys['google_maps']['key'] . "&q={$encoded}\"></iframe></div>";
            } else {
                $map_html = "<div style=\"border-radius:16px;overflow:hidden;margin:24px 0\"><iframe width=\"{$width}\" height=\"{$height}\" style=\"border:0;width:100%\" loading=\"lazy\" src=\"https://maps.google.com/maps?q={$encoded}&output=embed\"></iframe></div>";
            }
            if ($page_id) {
                $content = get_post_field('post_content', $page_id);
                $content .= "\n" . $map_html;
                wp_update_post(['ID' => $page_id, 'post_content' => $content]);
                // Also Elementor
                $el = get_post_meta($page_id, '_elementor_data', true);
                if ($el && strpos($el, 'google.com/maps') === false) {
                    $el = str_replace('</div>\n</div>', '</div>' . addslashes($map_html) . '</div>', $el);
                }
                return wpilot_ok("Map added to page {$page_id}.", ['address' => $address, 'page_id' => $page_id]);
            }
            return wpilot_ok("Map HTML generated.", ['html' => $map_html, 'address' => $address]);

        case 'connect_recaptcha':
            $site_key = $params['site_key'] ?? '';
            $secret_key = $params['secret_key'] ?? '';
            if (empty($site_key) || empty($secret_key)) return wpilot_err('site_key and secret_key required from Google reCAPTCHA.');
            update_option('wpilot_recaptcha_site_key', $site_key);
            update_option('wpilot_recaptcha_secret_key', $secret_key);
            $keys = get_option('wpilot_api_keys', []);
            $keys['recaptcha'] = ['key' => $site_key, 'secret' => $secret_key, 'type' => 'custom', 'added' => date('Y-m-d H:i:s')];
            update_option('wpilot_api_keys', $keys);
            return wpilot_ok("reCAPTCHA connected.", ['site_key' => substr($site_key, 0, 10) . '...']);

        case 'connect_custom_api':
        case 'setup_integration':
            $name = sanitize_key($params['name'] ?? '');
            $base_url = esc_url_raw($params['base_url'] ?? $params['url'] ?? '');
            $auth_type = sanitize_text_field($params['auth_type'] ?? 'bearer');
            $api_key = $params['api_key'] ?? $params['key'] ?? '';
            $header_name = sanitize_text_field($params['header_name'] ?? 'Authorization');
            if (empty($name) || empty($base_url)) return wpilot_err('name and base_url required.');
            $integrations = get_option('wpilot_integrations', []);
            $integrations[$name] = [
                'base_url' => $base_url, 'auth_type' => $auth_type, 'header_name' => $header_name, 'added' => date('Y-m-d H:i:s'),
            ];
            update_option('wpilot_integrations', $integrations);
            if ($api_key) {
                $keys = get_option('wpilot_api_keys', []);
                $keys[$name] = ['key' => $api_key, 'type' => $auth_type, 'header_name' => $header_name, 'added' => date('Y-m-d H:i:s')];
                update_option('wpilot_api_keys', $keys);
            }
            return wpilot_ok("Integration '{$name}' configured: {$base_url}", ['name' => $name, 'base_url' => $base_url]);

        
        // ═══ WOOCOMMERCE REST API KEYS ═══
        case 'setup_analytics':
        case 'add_tracking':
            $ga_id = sanitize_text_field($params['ga_id'] ?? $params['measurement_id'] ?? '');
            $gtm_id = sanitize_text_field($params['gtm_id'] ?? '');
            $fb_pixel = sanitize_text_field($params['pixel_id'] ?? '');
            $added = [];
            $head = get_option('wpilot_head_code', '');
            // Google Analytics 4
            if ($ga_id && preg_match('/^G-/', $ga_id) && strpos($head, $ga_id) === false) {
                $head .= "\n<!-- GA4 -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id={$ga_id}\"></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$ga_id}');</script>";
                $added[] = "GA4: {$ga_id}";
            }
            // Google Tag Manager
            if ($gtm_id && preg_match('/^GTM-/', $gtm_id) && strpos($head, $gtm_id) === false) {
                $head .= "\n<!-- GTM -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','{$gtm_id}');</script>";
                $added[] = "GTM: {$gtm_id}";
            }
            // Facebook Pixel
            if ($fb_pixel && preg_match('/^\d+$/', $fb_pixel) && strpos($head, $fb_pixel) === false) {
                $head .= "\n<!-- FB Pixel -->\n<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','{$fb_pixel}');fbq('track','PageView');</script>";
                $added[] = "FB Pixel: {$fb_pixel}";
            }
            if (empty($added)) return wpilot_err('Provide ga_id (G-...), gtm_id (GTM-...), or pixel_id.');
            update_option('wpilot_head_code', $head);
            return wpilot_ok("Tracking added: " . implode(', ', $added), ['added' => $added]);

        case 'remove_tracking':
            $type = sanitize_text_field($params['type'] ?? ''); // ga4, gtm, pixel, all
            $head = get_option('wpilot_head_code', '');
            $removed = [];
            if ($type === 'ga4' || $type === 'all') { $head = preg_replace('/<!-- GA4 -->.*?<\/script>\s*<\/script>/s', '', $head); $removed[] = 'GA4'; }
            if ($type === 'gtm' || $type === 'all') { $head = preg_replace('/<!-- GTM -->.*?<\/script>/s', '', $head); $removed[] = 'GTM'; }
            if ($type === 'pixel' || $type === 'all') { $head = preg_replace('/<!-- FB Pixel -->.*?<\/script>/s', '', $head); $removed[] = 'FB Pixel'; }
            update_option('wpilot_head_code', trim($head));
            return wpilot_ok("Removed: " . implode(', ', $removed));

        case 'list_tracking':
            $head = get_option('wpilot_head_code', '');
            $tracking = [];
            if (preg_match('/G-[A-Z0-9]+/', $head, $m)) $tracking[] = ['type' => 'GA4', 'id' => $m[0]];
            if (preg_match('/GTM-[A-Z0-9]+/', $head, $m)) $tracking[] = ['type' => 'GTM', 'id' => $m[0]];
            if (preg_match('/fbq\(\'init\',\'(\d+)\'\)/', $head, $m)) $tracking[] = ['type' => 'FB Pixel', 'id' => $m[1]];
            if (preg_match('/tiktok.*?\'([A-Z0-9]+)\'/', $head, $m)) $tracking[] = ['type' => 'TikTok', 'id' => $m[1]];
            return wpilot_ok(count($tracking) . " tracking codes installed.", ['tracking' => $tracking]);

        // ═══ GOOGLE ADS ═══
        case 'connect_google_ads':
        case 'setup_google_ads':
            $conversion_id = sanitize_text_field($params['conversion_id'] ?? $params['id'] ?? '');
            $conversion_label = sanitize_text_field($params['conversion_label'] ?? $params['label'] ?? '');
            if (empty($conversion_id)) return wpilot_err('Google Ads conversion_id required (AW-XXXXXXXXX).');
            $head = get_option('wpilot_head_code', '');
            if (strpos($head, $conversion_id) !== false) return wpilot_ok("Google Ads already installed.");
            // Built by Christos Ferlachidis & Daniel Hedenberg
            $head .= "\n<!-- Google Ads -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id={$conversion_id}\"></script>\n<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{$conversion_id}');</script>";
            update_option('wpilot_head_code', $head);
            // If WooCommerce, add purchase conversion tracking
            if (class_exists('WooCommerce') && $conversion_label) {
                $woo_tracking = "\nadd_action('woocommerce_thankyou', function(\$order_id) {\n    \$order = wc_get_order(\$order_id);\n    if (!\$order) return;\n    echo '<script>gtag(\"event\",\"conversion\",{\"send_to\":\"{$conversion_id}/{$conversion_label}\",\"value\":' . \$order->get_total() . ',\"currency\":\"' . \$order->get_currency() . '\",\"transaction_id\":\"' . \$order_id . '\"});</script>';\n});\n";
                $mu = ABSPATH . 'wp-content/mu-plugins/wpilot-google-ads.php';
                file_put_contents($mu, "<?php\n// WPilot Google Ads Conversion Tracking\n" . $woo_tracking);
            }
            return wpilot_ok("Google Ads connected: {$conversion_id}" . ($conversion_label ? " with purchase tracking" : ""), [
                'conversion_id' => $conversion_id, 'woo_tracking' => !empty($conversion_label),
            ]);

        // ═══ SOCIAL MEDIA TOOLS ═══
        case 'add_social_links':
        case 'setup_social':
            $links = $params['links'] ?? $params;
            $socials = [];
            foreach (['facebook', 'instagram', 'twitter', 'x', 'linkedin', 'youtube', 'tiktok', 'pinterest', 'snapchat', 'threads'] as $platform) {
                if (!empty($links[$platform])) $socials[$platform] = esc_url($links[$platform]);
            }
            if (empty($socials)) return wpilot_err('Provide social links: facebook, instagram, twitter, linkedin, youtube, tiktok, etc.');
            update_option('wpilot_social_links', $socials);
            return wpilot_ok(count($socials) . " social links saved.", ['links' => $socials]);

        case 'get_social_links':
            $links = get_option('wpilot_social_links', []);
            return wpilot_ok(count($links) . " social links.", ['links' => $links]);

        case 'add_social_share_buttons':
            $page_id = intval($params['page_id'] ?? 0);
            $style = sanitize_text_field($params['style'] ?? 'pill');
            $platforms = $params['platforms'] ?? ['facebook', 'twitter', 'linkedin', 'whatsapp'];
            $url = $page_id ? get_permalink($page_id) : get_site_url();
            $title = $page_id ? get_the_title($page_id) : get_bloginfo('name');
            $encoded_url = urlencode($url);
            $encoded_title = urlencode($title);
            $share_urls = [
                'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$encoded_url}",
                'twitter' => "https://twitter.com/intent/tweet?url={$encoded_url}&text={$encoded_title}",
                'linkedin' => "https://www.linkedin.com/shareArticle?mini=true&url={$encoded_url}&title={$encoded_title}",
                'whatsapp' => "https://wa.me/?text={$encoded_title}%20{$encoded_url}",
                'pinterest' => "https://pinterest.com/pin/create/button/?url={$encoded_url}&description={$encoded_title}",
                'email' => "mailto:?subject={$encoded_title}&body={$encoded_url}",
            ];
            $icons = ['facebook' => 'f', 'twitter' => '𝕏', 'linkedin' => 'in', 'whatsapp' => 'wa', 'pinterest' => 'p', 'email' => '✉'];
            $colors = ['facebook' => '#1877F2', 'twitter' => '#000', 'linkedin' => '#0A66C2', 'whatsapp' => '#25D366', 'pinterest' => '#E60023', 'email' => '#666'];
            $html = '<div style="display:flex;gap:8px;flex-wrap:wrap;margin:20px 0">';
            foreach ($platforms as $p) {
                if (!isset($share_urls[$p])) continue;
                $bg = $colors[$p] ?? '#666';
                $icon = $icons[$p] ?? substr($p, 0, 2);
                $html .= '<a href="' . $share_urls[$p] . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;background:' . $bg . ';color:#fff;border-radius:50%;text-decoration:none;font-weight:700;font-size:0.8rem;transition:transform 0.2s" title="Share on ' . ucfirst($p) . '">' . $icon . '</a>';
            }
            $html .= '</div>';
            if ($page_id) {
                $content = get_post_field('post_content', $page_id);
                $content .= "\n" . $html;
                wp_update_post(['ID' => $page_id, 'post_content' => $content]);
                return wpilot_ok("Share buttons added to page #{$page_id}.", ['page_id' => $page_id, 'platforms' => $platforms]);
            }
            return wpilot_ok("Share buttons HTML generated.", ['html' => $html, 'platforms' => $platforms]);

        case 'add_social_feed':
        case 'embed_social':
            $platform = sanitize_text_field($params['platform'] ?? '');
            $url = esc_url($params['url'] ?? $params['embed_url'] ?? '');
            $page_id = intval($params['page_id'] ?? 0);
            if (empty($platform) && empty($url)) return wpilot_err('platform or url required.');
            $embeds = [
                'instagram' => $url ? '<blockquote class="instagram-media" data-instgrm-permalink="' . $url . '" style="max-width:540px;margin:20px auto"></blockquote><script async src="https://www.instagram.com/embed.js"></script>' : '',
                'twitter' => $url ? '<blockquote class="twitter-tweet"><a href="' . $url . '"></a></blockquote><script async src="https://platform.twitter.com/widgets.js"></script>' : '',
                'youtube' => $url ? '<div style="position:relative;padding-bottom:56.25%;height:0;overflow:hidden;border-radius:12px;margin:20px 0"><iframe style="position:absolute;top:0;left:0;width:100%;height:100%;border:0" src="' . str_replace('watch?v=', 'embed/', $url) . '" allowfullscreen></iframe></div>' : '',
                'tiktok' => $url ? '<blockquote class="tiktok-embed" cite="' . $url . '" data-video-id="' . basename($url) . '"><a href="' . $url . '"></a></blockquote><script async src="https://www.tiktok.com/embed.js"></script>' : '',
            ];
            $html = $embeds[$platform] ?? '';
            if (empty($html)) return wpilot_err("Unsupported platform or missing URL. Supported: instagram, twitter, youtube, tiktok.");
            if ($page_id) {
                $content = get_post_field('post_content', $page_id);
                wp_update_post(['ID' => $page_id, 'post_content' => $content . "\n" . $html]);
                return wpilot_ok("{$platform} embed added to page #{$page_id}.", ['page_id' => $page_id]);
            }
            return wpilot_ok("{$platform} embed HTML generated.", ['html' => $html]);

        case 'connect_tiktok_pixel':
        case 'add_tiktok_pixel':
            $pixel_id = sanitize_text_field($params['pixel_id'] ?? $params['id'] ?? '');
            if (empty($pixel_id)) return wpilot_err('TikTok pixel_id required.');
            $head = get_option('wpilot_head_code', '');
            if (strpos($head, $pixel_id) !== false) return wpilot_ok("TikTok Pixel already installed.");
            $head .= "\n<!-- TikTok Pixel -->\n<script>!function(w,d,t){w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=['page','track','identify','instances','debug','on','off','once','ready','alias','group','enableCookie','disableCookie'];ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e};ttq.load=function(e,n){var i='https://analytics.tiktok.com/i18n/pixel/events.js';ttq._i=ttq._i||{};ttq._i[e]=[];ttq._i[e]._u=i;ttq._t=ttq._t||{};ttq._t[e]=+new Date;ttq._o=ttq._o||{};ttq._o[e]=n||{};var o=document.createElement('script');o.type='text/javascript';o.async=!0;o.src=i+'?sdkid='+e+'&lib='+t;var a=document.getElementsByTagName('script')[0];a.parentNode.insertBefore(o,a)};ttq.load('{$pixel_id}');ttq.page();}(window,document,'ttq');</script>";
            update_option('wpilot_head_code', $head);
            return wpilot_ok("TikTok Pixel connected: {$pixel_id}");

        // ═══ SNAPCHAT PIXEL ═══
        case 'connect_snapchat_pixel':
            $pixel_id = sanitize_text_field($params['pixel_id'] ?? $params['id'] ?? '');
            if (empty($pixel_id)) return wpilot_err('Snapchat pixel_id required.');
            $head = get_option('wpilot_head_code', '');
            if (strpos($head, 'snaptr') !== false) return wpilot_ok("Snapchat Pixel already installed.");
            $head .= "\n<!-- Snapchat Pixel -->\n<script type='text/javascript'>(function(e,t,n){if(e.snaptr)return;var a=e.snaptr=function(){a.handleRequest?a.handleRequest.apply(a,arguments):a.queue.push(arguments)};a.queue=[];var s='script';r=t.createElement(s);r.async=!0;r.src=n;var u=t.getElementsByTagName(s)[0];u.parentNode.insertBefore(r,u);})(window,document,'https://sc-static.net/scevent.min.js');snaptr('init','{$pixel_id}',{});snaptr('track','PAGE_VIEW');</script>";
            update_option('wpilot_head_code', $head);
            return wpilot_ok("Snapchat Pixel connected: {$pixel_id}");

        // ═══ PINTEREST TAG ═══
        case 'connect_pinterest_tag':
            $tag_id = sanitize_text_field($params['tag_id'] ?? $params['id'] ?? '');
            if (empty($tag_id)) return wpilot_err('Pinterest tag_id required.');
            $head = get_option('wpilot_head_code', '');
            if (strpos($head, 'pintrk') !== false) return wpilot_ok("Pinterest Tag already installed.");
            $head .= "\n<!-- Pinterest Tag -->\n<script>!function(e){if(!window.pintrk){window.pintrk=function(){window.pintrk.queue.push(Array.prototype.slice.call(arguments))};var n=window.pintrk;n.queue=[],n.version='3.0';var t=document.createElement('script');t.async=!0,t.src=e;var r=document.getElementsByTagName('script')[0];r.parentNode.insertBefore(t,r)}}('https://s.pinimg.com/ct/core.js');pintrk('load','{$tag_id}');pintrk('page');</script>";
            update_option('wpilot_head_code', $head);
            return wpilot_ok("Pinterest Tag connected: {$tag_id}");

        
        // ═══ LOGO TOOLS ═══

        default:
            return null; // Not handled by this module
    }
}
