<?php
if (!defined('ABSPATH')) exit;

/**
 * WPilot Design Tools Module
 * Contains 123 tool cases for design operations.
 */
function wpilot_run_design_tools($tool, $params = []) {
    switch ($tool) {

        case 'update_custom_css':
            $raw_css = $params['css'] ?? '';
            // Validate: CSS must contain { and } — otherwise it's a description, not CSS
            if (!empty($raw_css) && strpos($raw_css, '{') === false) {
                return wpilot_err('That is a description, not CSS code. Send actual CSS rules like: body{background:#050810} .class{color:#fff}');
            }
            // Strip HTML tags but keep CSS intact
            $css = strip_tags($raw_css);
            // If css param empty, check if description contains CSS
            if (empty($css) || strlen($css) < 50) {
                $desc = $params['description'] ?? '';
                if (strpos($desc, '{') !== false && strpos($desc, '}') !== false) {
                    $css = wp_strip_all_tags($desc);
                }
            }
            wpilot_save_css_snapshot();
            wp_update_custom_css_post( $css );
            // Only inject CSS via mu-plugin for block themes (FSE) that don't load wp_get_custom_css
            // Classic themes (Hello Elementor, Divi, Astra etc) already load it — no mu-plugin needed
            $mu_css_path = (defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins') . '/wpilot-custom-css.php';
            if (wp_is_block_theme()) {
                $mu_dir = dirname($mu_css_path);
                if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
                file_put_contents($mu_css_path, "<?php\nadd_action('wp_head', function() { echo '<style>' . wp_get_custom_css() . '</style>'; }, 999);");
            } else {
                // Classic theme — remove mu-plugin if it exists (prevents double CSS)
                if (file_exists($mu_css_path)) @unlink($mu_css_path);
            }
            // Auto-detect design tokens and save to design profile
            if ( function_exists( 'wpilot_auto_detect_design_from_css' ) ) {
                wpilot_auto_detect_design_from_css( $css );
            }
            return wpilot_ok('Custom CSS applied.');

        case 'append_custom_css':
            $new  = wp_strip_all_tags( $params['css'] ?? '' );
            if (empty($new) || strlen($new) < 50) {
                $desc = $params['description'] ?? '';
                if (strpos($desc, '{') !== false) $new = wp_strip_all_tags($desc);
            }
            $curr = wp_get_custom_css();
            wpilot_save_css_snapshot();
            // Deduplicate: remove old rules with same selectors before appending
            preg_match_all('/([^{}\n]+)\{/', $new, $new_sels);
            if (!empty($new_sels[1])) {
                foreach ($new_sels[1] as $sel) {
                    $sel = trim($sel);
                    if (empty($sel) || strpos($sel,'/*')===0 || strpos($sel,'@media')===0) continue;
                    $esc = preg_quote($sel, '/');
                    $curr = preg_replace('/'.$esc.'\s*\{[^}]*\}/s', '', $curr);
                }
            }
            $curr = preg_replace('/\/\*\s*WPilot[^*]*\*\/\s*/', '', $curr);
            $curr = preg_replace('/\n{3,}/', "\n\n", trim($curr));
            wp_update_custom_css_post(trim($curr) . "\n" . $new);
            // Auto-detect design tokens from new CSS
            if ( function_exists( 'wpilot_auto_detect_design_from_css' ) ) {
                wpilot_auto_detect_design_from_css( $new );
            }
            return wpilot_ok('CSS updated (duplicates removed).');

        /* ── Media / Images ─────────────────────────────────── */
        case 'analyze_website':
        case 'scrape_design':
        case 'copy_design':
            $url = esc_url_raw($params['url'] ?? $params['website'] ?? $params['site'] ?? '');
            if (empty($url)) return wpilot_err('URL required.');
            if (strpos($url, 'http') !== 0) $url = 'https://' . $url;

            $response = wp_remote_get($url, ['timeout' => 15, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36']);
            if (is_wp_error($response)) return wpilot_err('Could not fetch: ' . $response->get_error_message());

            $html = wp_remote_retrieve_body($response);
            if (empty($html)) return wpilot_err('Empty response from URL.');

            // Extract design info
            $design = [];

            // Colors
            $colors = [];
            if (preg_match_all('/(?:background|color|border-color|fill)\s*:\s*([#][0-9a-fA-F]{3,8}|rgb[a]?\([^)]+\))/i', $html, $cm)) {
                $colors = array_count_values($cm[1]);
                arsort($colors);
                $colors = array_slice($colors, 0, 15, true);
            }
            $design['colors'] = array_keys($colors);

            // Fonts
            $fonts = [];
            if (preg_match_all('/font-family\s*:\s*([^;}"]+)/i', $html, $fm)) {
                $fonts = array_unique(array_map('trim', $fm[1]));
            }
            $design['fonts'] = array_slice(array_values($fonts), 0, 5);

            // Layout sections
            $sections = [];
            if (preg_match_all('/<(?:section|header|footer|main|nav|div)[^>]*class="([^"]*)"[^>]*>/i', $html, $sm)) {
                $sections = array_slice(array_unique($sm[1]), 0, 20);
            }
            $design['css_classes'] = $sections;

            // Extract inline styles for key sections
            $hero_styles = [];
            if (preg_match('/<(?:section|div)[^>]*(?:hero|banner|jumbotron|masthead)[^>]*style="([^"]*)"[^>]*>/i', $html, $hm)) {
                $hero_styles[] = $hm[1];
            }
            $design['hero_styles'] = $hero_styles;

            // Images
            $images = [];
            if (preg_match_all('/<img[^>]+src="([^"]+)"[^>]*/i', $html, $im)) {
                $images = array_slice($im[1], 0, 10);
            }
            $design['images'] = count($images) . ' images found';

            // Page title
            $title = '';
            if (preg_match('/<title>([^<]+)</i', $html, $tm)) $title = trim($tm[1]);
            $design['title'] = $title;

            // Meta description
            $meta_desc = '';
            if (preg_match('/meta[^>]*name="description"[^>]*content="([^"]*)"/', $html, $mm)) $meta_desc = $mm[1];
            $design['meta_description'] = $meta_desc;

            // Navigation items
            $nav_items = [];
            if (preg_match_all('/<a[^>]*href="([^"]*)"[^>]*>([^<]+)</a>/i', $html, $nm)) {
                foreach (array_slice($nm[2], 0, 15) as $i => $text) {
                    $nav_items[] = trim($text) . ' → ' . $nm[1][$i];
                }
            }
            $design['navigation'] = $nav_items;

            // Text content (headings and paragraphs)
            $headings = [];
            if (preg_match_all('/<h[1-3][^>]*>([^<]+)</i', $html, $hm)) {
                $headings = array_slice(array_map('trim', $hm[1]), 0, 10);
            }
            $design['headings'] = $headings;

            // CSS variables
            $css_vars = [];
            if (preg_match_all('/--([a-zA-Z0-9-]+)\s*:\s*([^;]+)/i', $html, $vm)) {
                foreach (array_slice($vm[1], 0, 15) as $i => $name) {
                    $css_vars[$name] = trim($vm[2][$i]);
                }
            }
            $design['css_variables'] = $css_vars;

            // Detect tech/framework
            $tech = [];
            if (strpos($html, 'wp-content') !== false) $tech[] = 'WordPress';
            if (strpos($html, 'shopify') !== false) $tech[] = 'Shopify';
            if (strpos($html, 'react') !== false || strpos($html, 'React') !== false) $tech[] = 'React';
            if (strpos($html, 'next') !== false) $tech[] = 'Next.js';
            if (strpos($html, 'tailwind') !== false) $tech[] = 'Tailwind CSS';
            $design['tech'] = $tech;

            // Full CSS extraction (external stylesheets mentioned)
            $stylesheets = [];
            if (preg_match_all('/href="([^"]*\.css[^"]*)"/', $html, $ss)) {
                $stylesheets = array_slice($ss[1], 0, 5);
            }
            $design['stylesheets'] = count($stylesheets) . ' stylesheets';

            // Viewport / responsive
            $design['responsive'] = strpos($html, 'viewport') !== false;

            return wpilot_ok("Design analyzed from {$url}.", ['design' => $design, 'html_size' => strlen($html)]);

        // ═══ SET FAVICON ═══
        case 'edit_section':
        case 'edit_element':
            // Edit a specific section/element on a page by searching its content
            $page_id = intval($params['page_id'] ?? $params['id'] ?? 0);
            $search = $params['search'] ?? $params['find'] ?? '';
            $replace = $params['replace'] ?? $params['new_content'] ?? '';
            if (!$page_id) return wpilot_err('page_id required.');
            if (empty($search)) return wpilot_err('search text required — the text/HTML to find.');
            if (empty($replace)) return wpilot_err('replace text required — the new text/HTML.');

            // Check Elementor
            $el_data = get_post_meta($page_id, '_elementor_data', true);
            if ($el_data) {
                $json_str = $el_data;
                if (strpos($json_str, $search) !== false) {
                    $json_str = str_replace($search, $replace, $json_str);
                    update_post_meta($page_id, '_elementor_data', wp_slash($json_str));
                    // Clear Elementor cache
                    delete_post_meta($page_id, '_elementor_css');
                    if (class_exists('\Elementor\Plugin')) {
                        \Elementor\Plugin::$instance->files_manager->clear_cache();
                    }
                    return wpilot_ok("Updated element in Elementor page {$page_id}.", ['page_id' => $page_id, 'url' => get_permalink($page_id)]);
                }
            }
            // Check regular content
            $content = get_post_field('post_content', $page_id);
            if (strpos($content, $search) !== false) {
                $content = str_replace($search, $replace, $content);
                wp_update_post(['ID' => $page_id, 'post_content' => $content]);
                return wpilot_ok("Updated content in page {$page_id}.", ['page_id' => $page_id, 'url' => get_permalink($page_id)]);
            }
            return wpilot_err("Could not find '{$search}' in page {$page_id}. Use get_page to see current content.");

        case 'edit_text':
            // Edit specific text on a page (find old text, replace with new)
            $page_id = intval($params['page_id'] ?? $params['id'] ?? 0);
            $old_text = $params['old_text'] ?? $params['find'] ?? '';
            $new_text = $params['new_text'] ?? $params['replace'] ?? '';
            if (!$page_id || empty($old_text) || empty($new_text)) return wpilot_err('page_id, old_text, new_text required.');

            $updated = false;
            // Try Elementor data first
            $el_data = get_post_meta($page_id, '_elementor_data', true);
            if ($el_data && strpos($el_data, $old_text) !== false) {
                $el_data = str_replace($old_text, $new_text, $el_data);
                update_post_meta($page_id, '_elementor_data', wp_slash($el_data));
                delete_post_meta($page_id, '_elementor_css');
                if (class_exists('\Elementor\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
                $updated = true;
            }
            // Also update post_content
            $content = get_post_field('post_content', $page_id);
            if (strpos($content, $old_text) !== false) {
                $content = str_replace($old_text, $new_text, $content);
                wp_update_post(['ID' => $page_id, 'post_content' => $content]);
                $updated = true;
            }
            if ($updated) return wpilot_ok("Text updated on page {$page_id}.", ['page_id' => $page_id, 'url' => get_permalink($page_id)]);
            return wpilot_err("Text '{$old_text}' not found on page {$page_id}.");

        case 'edit_button':
            // Edit a button's text, link, or style on a page
            $page_id = intval($params['page_id'] ?? 0);
            $old_label = $params['old_label'] ?? $params['find'] ?? '';
            $new_label = $params['new_label'] ?? $params['label'] ?? '';
            $new_url = $params['url'] ?? $params['link'] ?? '';
            $new_style = $params['style'] ?? '';
            if (!$page_id || empty($old_label)) return wpilot_err('page_id and old_label required.');

            $el_data = get_post_meta($page_id, '_elementor_data', true);
            $content = get_post_field('post_content', $page_id);
            $updated = false;

            // Elementor button widget — settings.text
            if ($el_data) {
                $json = json_decode($el_data, true);
                if ($json) {
                    $changed = wpilot_walk_edit_button($json, $old_label, $new_label, $new_url);
                    if ($changed) {
                        update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($json)));
                        delete_post_meta($page_id, '_elementor_css');
                        $updated = true;
                    }
                }
            }

            // HTML buttons — <a> and <button> tags
            if (!$updated && $content) {
                // Match <a...>old_label</a> or <button...>old_label</button>
                $pattern = '/(<(?:a|button)[^>]*>)\s*' . preg_quote($old_label, '/') . '\s*(<\/(?:a|button)>)/i';
                if (preg_match($pattern, $content, $m)) {
                    $tag = $m[1];
                    if ($new_url && preg_match('/href="[^"]*"/', $tag)) {
                        $tag = preg_replace('/href="[^"]*"/', 'href="' . esc_url($new_url) . '"', $tag);
                    }
                    $replacement = $tag . ($new_label ?: $old_label) . $m[2];
                    $content = preg_replace($pattern, $replacement, $content, 1);
                    wp_update_post(['ID' => $page_id, 'post_content' => $content]);
                    $updated = true;
                }
            }
            // Also try Elementor data for HTML widget buttons
            if (!$updated && $el_data && strpos($el_data, $old_label) !== false) {
                $new = $old_label;
                if ($new_label) {
                    $el_data = str_replace('>' . $old_label . '<', '>' . $new_label . '<', $el_data);
                    $new = $new_label;
                }
                update_post_meta($page_id, '_elementor_data', wp_slash($el_data));
                delete_post_meta($page_id, '_elementor_css');
                $updated = true;
            }

            if ($updated) return wpilot_ok("Button updated on page {$page_id}.", ['page_id' => $page_id, 'url' => get_permalink($page_id)]);
            return wpilot_err("Button with label '{$old_label}' not found.");

        case 'edit_icon':
            // Change an icon on a page (Elementor icon widget or Font Awesome/emoji)
            $page_id = intval($params['page_id'] ?? 0);
            $old_icon = $params['old_icon'] ?? $params['find'] ?? '';
            $new_icon = $params['new_icon'] ?? $params['icon'] ?? '';
            if (!$page_id || empty($old_icon) || empty($new_icon)) return wpilot_err('page_id, old_icon, new_icon required.');

            $updated = false;
            // Elementor data
            $el_data = get_post_meta($page_id, '_elementor_data', true);
            if ($el_data && strpos($el_data, $old_icon) !== false) {
                $el_data = str_replace($old_icon, $new_icon, $el_data);
                update_post_meta($page_id, '_elementor_data', wp_slash($el_data));
                delete_post_meta($page_id, '_elementor_css');
                $updated = true;
            }
            // Post content
            $content = get_post_field('post_content', $page_id);
            if (strpos($content, $old_icon) !== false) {
                $content = str_replace($old_icon, $new_icon, $content);
                wp_update_post(['ID' => $page_id, 'post_content' => $content]);
                $updated = true;
            }
            if ($updated) return wpilot_ok("Icon changed from '{$old_icon}' to '{$new_icon}'.", ['page_id' => $page_id, 'url' => get_permalink($page_id)]);
            return wpilot_err("Icon '{$old_icon}' not found on page {$page_id}.");

        case 'add_animation':
        case 'add_css_animation':
            // Add CSS animation to elements on a page
            $page_id = intval($params['page_id'] ?? 0);
            $selector = $params['selector'] ?? $params['element'] ?? '';
            $animation = $params['animation'] ?? 'fadeInUp';
            $duration = $params['duration'] ?? '0.6s';
            $delay = $params['delay'] ?? '0s';

            // Pre-built animations
            $animations = [
                'fadeIn' => 'opacity:0 -> opacity:1',
                'fadeInUp' => 'opacity:0;transform:translateY(30px) -> opacity:1;transform:translateY(0)',
                'fadeInDown' => 'opacity:0;transform:translateY(-30px) -> opacity:1;transform:translateY(0)',
                'fadeInLeft' => 'opacity:0;transform:translateX(-30px) -> opacity:1;transform:translateX(0)',
                'fadeInRight' => 'opacity:0;transform:translateX(30px) -> opacity:1;transform:translateX(0)',
                'slideUp' => 'transform:translateY(50px) -> transform:translateY(0)',
                'scaleIn' => 'opacity:0;transform:scale(0.8) -> opacity:1;transform:scale(1)',
                'bounceIn' => 'opacity:0;transform:scale(0.3) -> opacity:1;transform:scale(1)',
                'pulse' => 'transform:scale(1) -> transform:scale(1.05) -> transform:scale(1)',
                'shake' => 'transform:translateX(0) -> translateX(-5px) -> translateX(5px) -> translateX(0)',
            ];

            // If selector is given, add targeted CSS animation
            if (!empty($selector)) {
                $anim_name = 'wpilot-' . sanitize_title($animation);
                $from_to = $animations[$animation] ?? $animations['fadeInUp'];
                $parts = explode(' -> ', $from_to);
                $from = str_replace(';', '; ', $parts[0]);
                $to = str_replace(';', '; ', end($parts));

                $css = "@keyframes {$anim_name} {\n  from { {$from}; }\n  to { {$to}; }\n}\n";
                $css .= "{$selector} {\n  animation: {$anim_name} {$duration} ease-out {$delay} both;\n}\n";

                $existing = wp_get_custom_css();
                if (strpos($existing, $anim_name) === false) {
                    wp_update_custom_css_post($existing . "\n/* Animation: {$animation} */\n" . $css);
                }
                return wpilot_ok("Added {$animation} animation to {$selector}.", ['css' => $css]);
            }

            // If page_id given without selector, add animation to all sections
            if ($page_id) {
                $css = "@keyframes wpilot-fadeInUp {\n  from { opacity: 0; transform: translateY(30px); }\n  to { opacity: 1; transform: translateY(0); }\n}\n";
                $css .= ".page-id-{$page_id} .elementor-section,\n.page-id-{$page_id} > div > div {\n  animation: wpilot-fadeInUp {$duration} ease-out both;\n}\n";
                // Stagger children
                for ($i = 1; $i <= 8; $i++) {
                    $d = round($i * 0.1, 1);
                    $css .= ".page-id-{$page_id} .elementor-section:nth-child({$i}),\n.page-id-{$page_id} > div > div:nth-child({$i}) { animation-delay: {$d}s; }\n";
                }
                $existing = wp_get_custom_css();
                if (strpos($existing, 'wpilot-fadeInUp') === false) {
                    wp_update_custom_css_post($existing . "\n/* Page animations */\n" . $css);
                }
                return wpilot_ok("Added staggered {$animation} animations to page {$page_id}.", ['page_id' => $page_id, 'url' => get_permalink($page_id)]);
            }

            return wpilot_err('Provide selector (CSS selector) or page_id.');

        case 'edit_colors':
        case 'change_colors':
            // Change colors on a specific page or site-wide
            $page_id = intval($params['page_id'] ?? 0);
            $old_color = $params['old_color'] ?? $params['from'] ?? '';
            $new_color = $params['new_color'] ?? $params['to'] ?? $params['color'] ?? '';
            if (empty($old_color) || empty($new_color)) return wpilot_err('old_color and new_color required. Example: old_color=#ff0000, new_color=#0066ff');

            $updated_in = [];

            if ($page_id) {
                // Elementor data
                $el_data = get_post_meta($page_id, '_elementor_data', true);
                if ($el_data && stripos($el_data, $old_color) !== false) {
                    $el_data = str_ireplace($old_color, $new_color, $el_data);
                    update_post_meta($page_id, '_elementor_data', wp_slash($el_data));
                    delete_post_meta($page_id, '_elementor_css');
                    $updated_in[] = 'elementor';
                }
                // Post content
                $content = get_post_field('post_content', $page_id);
                if (stripos($content, $old_color) !== false) {
                    $content = str_ireplace($old_color, $new_color, $content);
                    wp_update_post(['ID' => $page_id, 'post_content' => $content]);
                    $updated_in[] = 'content';
                }
            }
            // Custom CSS
            $css = wp_get_custom_css();
            if (stripos($css, $old_color) !== false) {
                $css = str_ireplace($old_color, $new_color, $css);
                wp_update_custom_css_post($css);
                $updated_in[] = 'custom_css';
            }

            if (!empty($updated_in)) {
                return wpilot_ok("Changed {$old_color} → {$new_color} in: " . implode(', ', $updated_in) . ".", [
                    'old_color' => $old_color, 'new_color' => $new_color, 'updated_in' => $updated_in,
                ]);
            }
            return wpilot_err("Color {$old_color} not found" . ($page_id ? " on page {$page_id}" : "") . ".");

        case 'edit_font':
        case 'change_font':
            // Change fonts on a page or site-wide
            $selector = $params['selector'] ?? 'body';
            $font = sanitize_text_field($params['font'] ?? $params['family'] ?? '');
            $size = $params['size'] ?? '';
            $weight = $params['weight'] ?? '';
            $line_height = $params['line_height'] ?? '';
            if (empty($font) && empty($size)) return wpilot_err('font or size required.');

            $css = "{$selector} {\n";
            if ($font) $css .= "  font-family: '{$font}', system-ui, sans-serif !important;\n";
            if ($size) $css .= "  font-size: {$size} !important;\n";
            if ($weight) $css .= "  font-weight: {$weight} !important;\n";
            if ($line_height) $css .= "  line-height: {$line_height} !important;\n";
            $css .= "}\n";

            // Add Google Font if needed
            $google_fonts = ['Inter','Poppins','Roboto','Open Sans','Montserrat','Lato','Playfair Display','Raleway','Nunito','Oswald','DM Sans','Plus Jakarta Sans'];
            $head_code = '';
            if ($font && in_array($font, $google_fonts)) {
                $font_slug = str_replace(' ', '+', $font);
                $head_code = "<link href=\"https://fonts.googleapis.com/css2?family={$font_slug}:wght@300;400;500;600;700;800&display=swap\" rel=\"stylesheet\">";
                // Add to head
                $existing_head = get_option('wpilot_head_code', '');
                if (strpos($existing_head, $font_slug) === false) {
                    update_option('wpilot_head_code', $existing_head . "\n" . $head_code);
                }
            }

            $existing_css = wp_get_custom_css();
            wp_update_custom_css_post($existing_css . "\n/* Font: {$font} */\n" . $css);
            return wpilot_ok("Font updated: {$selector} → {$font}" . ($size ? " {$size}" : "") . ".", ['css' => $css]);

        case 'get_page_elements':
        case 'list_page_elements':
            // List all editable elements on a page
            $page_id = intval($params['page_id'] ?? $params['id'] ?? 0);
            if (!$page_id) return wpilot_err('page_id required.');

            $elements = [];
            // Check Elementor
            $el_data = get_post_meta($page_id, '_elementor_data', true);
            if ($el_data) {
                $json = json_decode($el_data, true);
                if ($json) {
                    wpilot_collect_elements($json, $elements);
                }
            }
            // Check post_content for HTML elements
            $content = get_post_field('post_content', $page_id);
            // Extract headings
            if (preg_match_all('/<h([1-6])[^>]*>([^<]+)<\/h\1>/i', $content, $hm)) {
                foreach ($hm[2] as $i => $text) {
                    $elements[] = ['type' => 'heading_h' . $hm[1][$i], 'text' => trim($text)];
                }
            }
            // Extract buttons
            if (preg_match_all('/<(?:a|button)[^>]*>([^<]{1,50})<\/(?:a|button)>/i', $content, $bm)) {
                foreach ($bm[1] as $text) {
                    $elements[] = ['type' => 'button', 'text' => trim($text)];
                }
            }
            // Extract images
            if (preg_match_all('/<img[^>]+src="([^"]+)"[^>]*>/i', $content, $im)) {
                foreach ($im[1] as $src) {
                    $alt = '';
                    if (preg_match('/alt="([^"]*)"/', $content, $am)) $alt = $am[1];
                    $elements[] = ['type' => 'image', 'src' => $src, 'alt' => $alt];
                }
            }
            return wpilot_ok(count($elements) . " elements found on page {$page_id}.", ['elements' => $elements, 'page_id' => $page_id]);

        // ═══ MULTI-DEVICE SCREENSHOTS ═══
        case 'design_checkout':
        case 'style_checkout':
            $style = sanitize_text_field($params['style'] ?? 'premium');
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $bg = sanitize_text_field($params['background'] ?? '#0a0a0a');
            $text = sanitize_text_field($params['text_color'] ?? '#ffffff');

            $styles = [
                'premium' => "
/* Premium Checkout */
.woocommerce-checkout { max-width: 900px; margin: 40px auto; padding: 0 24px; }
.woocommerce-checkout h3 { color: {$text}; font-weight: 700; font-size: 1.3rem; margin-top: 32px; }
.woocommerce-checkout .form-row label { color: {$text}; font-weight: 500; font-size: 0.9rem; }
.woocommerce-checkout input, .woocommerce-checkout select, .woocommerce-checkout textarea {
    background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); color: {$text};
    border-radius: 10px; padding: 14px 16px; font-size: 1rem; transition: border-color 0.3s;
}
.woocommerce-checkout input:focus, .woocommerce-checkout select:focus {
    border-color: {$accent}; box-shadow: 0 0 0 3px " . $accent . "22; outline: none;
}
.woocommerce-checkout #order_review { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 24px; }
.woocommerce-checkout table.shop_table { border: none; }
.woocommerce-checkout table.shop_table th, .woocommerce-checkout table.shop_table td { border-color: rgba(255,255,255,0.06); color: {$text}; padding: 12px 0; }
.woocommerce-checkout #place_order { background: linear-gradient(135deg,{$accent}," . $accent . "cc) !important; color: #fff !important; border: none !important;
    border-radius: 50px !important; padding: 16px 48px !important; font-weight: 700 !important; font-size: 1.1rem !important;
    box-shadow: 0 8px 32px " . $accent . "44; transition: transform 0.2s, box-shadow 0.2s; cursor: pointer; }
.woocommerce-checkout #place_order:hover { transform: translateY(-2px); box-shadow: 0 12px 40px " . $accent . "55; }
.woocommerce-checkout .woocommerce-info { background: rgba(255,255,255,0.03); border-left: 4px solid {$accent}; color: {$text}; border-radius: 8px; }
",
                'minimal' => "
/* Minimal Checkout */
.woocommerce-checkout { max-width: 700px; margin: 60px auto; padding: 0 24px; }
.woocommerce-checkout input, .woocommerce-checkout select { border: none; border-bottom: 2px solid rgba(255,255,255,0.1); border-radius: 0; background: transparent; color: {$text}; padding: 12px 0; }
.woocommerce-checkout input:focus { border-bottom-color: {$accent}; box-shadow: none; }
.woocommerce-checkout #place_order { background: {$accent} !important; border-radius: 4px !important; }
",
            ];
            $css = $styles[$style] ?? $styles['premium'];
            $existing = wp_get_custom_css();
            if (strpos($existing, 'Premium Checkout') === false && strpos($existing, 'Minimal Checkout') === false) {
                wp_update_custom_css_post($existing . "\n" . $css);
            }
            return wpilot_ok("Checkout styled with '{$style}' design.", ['style' => $style]);

        // ═══ LOGIN PAGE DESIGN ═══
        case 'design_login_page':
        case 'style_login':
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $bg = sanitize_text_field($params['background'] ?? '#0a0a0a');
            $logo_url = esc_url($params['logo_url'] ?? '');
            $style = sanitize_text_field($params['style'] ?? 'premium');

            $login_css = "
/* WPilot Login Page Design */
body.login { background: {$bg} !important; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
body.login::before { content: ''; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(circle at 50% 30%, " . $accent . "15, transparent 60%); pointer-events: none; }
#login { padding: 0 !important; width: 380px !important; }
#login h1 a { background-size: contain !important; width: 200px !important; height: 80px !important; " . ($logo_url ? "background-image: url('{$logo_url}') !important;" : "") . " }
#loginform { background: rgba(255,255,255,0.03) !important; border: 1px solid rgba(255,255,255,0.08) !important; border-radius: 20px !important; padding: 32px !important; box-shadow: 0 20px 60px rgba(0,0,0,0.3) !important; }
#loginform label { color: rgba(255,255,255,0.7) !important; font-size: 0.9rem; }
#loginform input[type='text'], #loginform input[type='password'] { background: rgba(255,255,255,0.05) !important; border: 1px solid rgba(255,255,255,0.12) !important; color: #fff !important; border-radius: 10px !important; padding: 12px 16px !important; font-size: 1rem; }
#loginform input[type='text']:focus, #loginform input[type='password']:focus { border-color: {$accent} !important; box-shadow: 0 0 0 3px " . $accent . "22 !important; }
#loginform .submit input { background: linear-gradient(135deg,{$accent}," . $accent . "cc) !important; border: none !important; border-radius: 50px !important; padding: 12px 32px !important; font-weight: 700 !important; box-shadow: 0 8px 24px " . $accent . "44 !important; cursor: pointer; width: 100%; font-size: 1rem; }
#nav, #backtoblog { text-align: center; }
#nav a, #backtoblog a { color: rgba(255,255,255,0.4) !important; text-decoration: none; }
#nav a:hover, #backtoblog a:hover { color: {$accent} !important; }
.login .message, .login .notice { background: rgba(255,255,255,0.03) !important; border-left-color: {$accent} !important; color: rgba(255,255,255,0.7) !important; border-radius: 8px !important; }
";
            // Save as mu-plugin for login page (custom CSS doesn't load on login)
            $mu_dir = ABSPATH . 'wp-content/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $mu_file = $mu_dir . '/wpilot-login-style.php';
            $mu_content = "<?php\n// WPilot Login Page Design\nadd_action('login_enqueue_scripts', function() {\n    echo '<style>" . str_replace("'", "\\'", str_replace("\n", " ", $login_css)) . "</style>';\n});\n";
            file_put_contents($mu_file, $mu_content);
            return wpilot_ok("Login page styled with premium design.", ['accent' => $accent, 'background' => $bg]);

        // ═══ EMAIL TEMPLATE DESIGN ═══
        case 'design_emails':
        case 'style_woo_emails':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $accent = sanitize_text_field($params['accent_color'] ?? $params['color'] ?? '#E91E8C');
            $bg = sanitize_text_field($params['background'] ?? '#0a0a0a');
            $header_bg = sanitize_text_field($params['header_bg'] ?? $accent);
            $text_color = sanitize_text_field($params['text_color'] ?? '#333333');
            // Built by Weblease
            // WooCommerce email settings
            update_option('woocommerce_email_background_color', $bg);
            update_option('woocommerce_email_base_color', $accent);
            update_option('woocommerce_email_body_background_color', '#ffffff');
            update_option('woocommerce_email_text_color', $text_color);
            update_option('woocommerce_email_header_image', $params['logo_url'] ?? '');
            update_option('woocommerce_email_footer_text', sanitize_text_field($params['footer_text'] ?? '{site_title} — Powered by WPilot'));
            return wpilot_ok("WooCommerce email templates styled.", ['accent' => $accent, 'bg' => $bg]);

        // ═══ HEADER DESIGN ═══
        case 'design_header':
        case 'style_header':
            $style = sanitize_text_field($params['style'] ?? 'transparent');
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $bg = sanitize_text_field($params['background'] ?? 'transparent');

            $headers = [
                'transparent' => "
.site-header, .elementor-location-header { background: transparent !important; position: absolute; width: 100%; z-index: 100; border: none !important; }
.site-header a { color: #fff !important; }
.site-header .site-title a { color: {$accent} !important; font-weight: 800; }
",
                'dark' => "
.site-header, .elementor-location-header { background: #0a0a0a !important; border-bottom: 1px solid rgba(255,255,255,0.06) !important; }
.site-header a { color: #fff !important; transition: color 0.3s; }
.site-header a:hover { color: {$accent} !important; }
.site-header .site-title a { color: {$accent} !important; font-weight: 800; }
",
                'glass' => "
.site-header, .elementor-location-header { background: rgba(10,10,10,0.8) !important; backdrop-filter: blur(20px) !important; -webkit-backdrop-filter: blur(20px) !important; border-bottom: 1px solid rgba(255,255,255,0.06) !important; position: sticky; top: 0; z-index: 999; }
.site-header a { color: #fff !important; }
.site-header .site-title a { color: {$accent} !important; font-weight: 800; }
",
                'gradient' => "
.site-header, .elementor-location-header { background: linear-gradient(135deg, #0a0a0a, {$accent}22) !important; border-bottom: 1px solid rgba(255,255,255,0.06) !important; }
.site-header a { color: #fff !important; }
",
            ];
            $css = $headers[$style] ?? $headers['dark'];
            $existing = wp_get_custom_css();
            // Remove old header styles
            $existing = preg_replace('/\/\* Header Style: \w+ \*\/.*?(?=\/\*|$)/s', '', $existing);
            wp_update_custom_css_post($existing . "\n/* Header Style: {$style} */\n" . $css);
            return wpilot_ok("Header styled: {$style}", ['style' => $style]);

        // ═══ FOOTER DESIGN ═══
        case 'design_footer':
        case 'style_footer':
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $columns = intval($params['columns'] ?? 4);
            $bg = sanitize_text_field($params['background'] ?? '#050505');
            $copyright = sanitize_text_field($params['copyright'] ?? '© ' . date('Y') . ' ' . get_bloginfo('name') . '. All rights reserved.');

            $footer_html = '<div style="background:' . $bg . ';padding:60px 24px 30px;color:rgba(255,255,255,0.6);font-family:system-ui,sans-serif">';
            $footer_html .= '<div style="max-width:1100px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:32px;margin-bottom:40px">';
            $footer_html .= '<div><h4 style="color:#fff;font-size:1rem;margin:0 0 16px;font-weight:700">' . get_bloginfo('name') . '</h4><p style="line-height:1.7;margin:0;font-size:0.9rem">' . get_bloginfo('description') . '</p></div>';
            $footer_html .= '<div><h4 style="color:#fff;font-size:1rem;margin:0 0 16px;font-weight:700">Quick Links</h4><p style="margin:0;line-height:2"><a href="/" style="color:rgba(255,255,255,0.6);text-decoration:none">Home</a><br><a href="/shop" style="color:rgba(255,255,255,0.6);text-decoration:none">Shop</a><br><a href="/contact" style="color:rgba(255,255,255,0.6);text-decoration:none">Contact</a></p></div>';
            $footer_html .= '<div><h4 style="color:#fff;font-size:1rem;margin:0 0 16px;font-weight:700">Contact</h4><p style="margin:0;line-height:2;font-size:0.9rem">' . get_option('admin_email') . '</p></div>';
            $footer_html .= '</div>';
            $footer_html .= '<div style="border-top:1px solid rgba(255,255,255,0.06);padding-top:20px;text-align:center;font-size:0.8rem;color:rgba(255,255,255,0.3)">' . $copyright . '</div></div>';

            // Store as widget or option
            update_option('wpilot_custom_footer', $footer_html);
            // Also add CSS to hide default footer and inject custom
            $css = "\n/* Custom Footer */\n.site-footer .site-info { display: none; }\n";
            $existing = wp_get_custom_css();
            if (strpos($existing, 'Custom Footer') === false) wp_update_custom_css_post($existing . $css);
            return wpilot_ok("Footer designed with {$columns} columns.", ['footer_html' => $footer_html]);

        // ═══ SHOP PAGE DESIGN ═══
        case 'design_shop':
        case 'style_shop':
            $columns = intval($params['columns'] ?? 3);
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $style = sanitize_text_field($params['style'] ?? 'cards');
            $css = "
/* Shop Design: {$style} */
.woocommerce .products { display: grid !important; grid-template-columns: repeat({$columns}, 1fr) !important; gap: 24px !important; }
@media (max-width: 768px) { .woocommerce .products { grid-template-columns: repeat(2, 1fr) !important; gap: 12px !important; } }
@media (max-width: 480px) { .woocommerce .products { grid-template-columns: 1fr !important; } }
.woocommerce ul.products li.product { margin: 0 !important; padding: 0 !important; width: 100% !important; float: none !important; }
";
            if ($style === 'cards') {
                $css .= "
.woocommerce ul.products li.product { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 16px !important; overflow: hidden; }
.woocommerce ul.products li.product:hover { border-color: {$accent}44; transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.2); }
.woocommerce ul.products li.product img { border-radius: 10px; }
";
            }
            $existing = wp_get_custom_css();
            $existing = preg_replace('/\/\* Shop Design:.*?(?=\/\*|$)/s', '', $existing);
            wp_update_custom_css_post($existing . "\n" . $css);
            // Set WooCommerce columns
            update_option('woocommerce_catalog_columns', $columns);
            return wpilot_ok("Shop page styled: {$columns} columns, {$style} layout.", ['columns' => $columns, 'style' => $style]);

        // ═══ SINGLE PRODUCT PAGE DESIGN ═══
        case 'design_product_page':
        case 'style_product':
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $css = "
/* Single Product Premium */
.single-product .product { max-width: 1100px; margin: 40px auto; }
.single-product .product .summary { color: #fff; }
.single-product .product .summary h1 { color: #fff !important; font-size: 2rem; font-weight: 800; }
.single-product .product .summary .price { color: {$accent} !important; font-size: 1.8rem !important; font-weight: 700; }
.single-product .product .summary .woocommerce-product-details__short-description { color: rgba(255,255,255,0.7); line-height: 1.7; }
.single-product .product .summary button.single_add_to_cart_button { background: linear-gradient(135deg,{$accent},{$accent}cc) !important; border: none !important; border-radius: 50px !important; padding: 16px 48px !important; font-weight: 700 !important; font-size: 1.1rem; box-shadow: 0 8px 32px {$accent}44; }
.single-product .product .summary button.single_add_to_cart_button:hover { transform: translateY(-2px); box-shadow: 0 12px 40px {$accent}55; }
.single-product .woocommerce-tabs { border-color: rgba(255,255,255,0.08) !important; }
.single-product .woocommerce-tabs .tabs li a { color: rgba(255,255,255,0.6) !important; }
.single-product .woocommerce-tabs .tabs li.active a { color: {$accent} !important; }
.single-product .woocommerce-tabs .panel { color: rgba(255,255,255,0.7); }
.single-product .product .images img { border-radius: 16px; }
.single-product .related.products h2 { color: #fff !important; }
";
            $existing = wp_get_custom_css();
            if (strpos($existing, 'Single Product Premium') === false) wp_update_custom_css_post($existing . "\n" . $css);
            return wpilot_ok("Product page styled premium.", ['accent' => $accent]);

        // ═══ CART PAGE DESIGN ═══
        case 'design_cart':
        case 'style_cart':
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $css = "
/* Cart Premium */
.woocommerce-cart { color: #fff; }
.woocommerce-cart h1 { color: #fff !important; }
.woocommerce-cart table.shop_table { background: rgba(255,255,255,0.03) !important; border: 1px solid rgba(255,255,255,0.08) !important; border-radius: 16px !important; overflow: hidden; }
.woocommerce-cart table.shop_table th { background: rgba(255,255,255,0.03) !important; color: rgba(255,255,255,0.6) !important; border-color: rgba(255,255,255,0.06) !important; }
.woocommerce-cart table.shop_table td { color: #fff !important; border-color: rgba(255,255,255,0.06) !important; }
.woocommerce-cart table.shop_table img { border-radius: 8px; }
.woocommerce-cart .cart_totals { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 24px; }
.woocommerce-cart .cart_totals h2 { color: #fff !important; }
.woocommerce-cart .cart_totals table { color: #fff !important; }
.woocommerce-cart .checkout-button { background: linear-gradient(135deg,{$accent},{$accent}cc) !important; border-radius: 50px !important; }
.woocommerce-cart .coupon input { background: rgba(255,255,255,0.05) !important; border: 1px solid rgba(255,255,255,0.12) !important; color: #fff !important; border-radius: 10px !important; }
";
            $existing = wp_get_custom_css();
            if (strpos($existing, 'Cart Premium') === false) wp_update_custom_css_post($existing . "\n" . $css);
            return wpilot_ok("Cart page styled premium.");

        // ═══ DETECT BUILDER ═══
        case 'detect_builder':
        case 'which_builder':
            $builder = wpilot_detect_builder();
            $details = ['builder' => $builder];
            if ($builder === 'elementor') {
                $details['version'] = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'unknown';
                $details['pro'] = defined('ELEMENTOR_PRO_VERSION');
            } elseif ($builder === 'divi') {
                $details['version'] = defined('ET_BUILDER_VERSION') ? ET_BUILDER_VERSION : 'unknown';
            }
            $details['theme'] = get_template();
            $details['child_theme'] = get_stylesheet() !== get_template() ? get_stylesheet() : null;
            return wpilot_ok("Builder: {$builder}, Theme: " . get_template(), $details);

        // ═══ MY ACCOUNT PAGE DESIGN ═══
        case 'design_account':
        case 'style_account':
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $css = "
/* My Account Premium */
.woocommerce-account .woocommerce { color: #fff; }
.woocommerce-account h2 { color: #fff !important; }
.woocommerce-account .woocommerce-MyAccount-navigation { background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 16px; }
.woocommerce-account .woocommerce-MyAccount-navigation ul { list-style: none; padding: 0; margin: 0; }
.woocommerce-account .woocommerce-MyAccount-navigation li a { display: block; padding: 12px 16px; color: rgba(255,255,255,0.7); text-decoration: none; border-radius: 8px; transition: all 0.3s; }
.woocommerce-account .woocommerce-MyAccount-navigation li.is-active a,
.woocommerce-account .woocommerce-MyAccount-navigation li a:hover { background: {$accent}15; color: {$accent}; }
.woocommerce-account .woocommerce-MyAccount-content { color: rgba(255,255,255,0.8); }
.woocommerce-account table { color: #fff !important; border-color: rgba(255,255,255,0.08) !important; }
.woocommerce-account table th, .woocommerce-account table td { border-color: rgba(255,255,255,0.06) !important; }
";
            $existing = wp_get_custom_css();
            if (strpos($existing, 'My Account Premium') === false) wp_update_custom_css_post($existing . "\n" . $css);
            return wpilot_ok("Account page styled premium.");

        // ═══ DESIGN EVERYTHING — One command ═══
        case 'design_all':
        case 'style_everything':
        case 'premium_makeover':
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $bg = sanitize_text_field($params['background'] ?? '#0a0a0a');
            $results = [];
            $pages = ['design_checkout', 'design_login_page', 'design_header', 'design_shop', 'design_product_page', 'design_cart', 'design_account'];
            foreach ($pages as $page_tool) {
                $r = wpilot_run_tool($page_tool, ['accent_color' => $accent, 'background' => $bg, 'style' => 'premium']);
                $results[] = $page_tool . ': ' . ($r['success'] ? 'OK' : 'FAIL');
            }
            if (class_exists('WooCommerce')) {
                $r = wpilot_run_tool('design_emails', ['accent_color' => $accent, 'background' => $bg]);
                $results[] = 'design_emails: ' . ($r['success'] ? 'OK' : 'FAIL');
            }
            return wpilot_ok("Full premium makeover applied to all pages.", ['results' => $results, 'accent' => $accent]);


        // ═══ PREMIUM DESIGN TOOLS ══════════════════════════════════
        // Built by Weblease

        case 'hover_effects':
            $sel    = $params['selector'] ?? '';
            $effect = $params['effect'] ?? 'lift';
            if (!$sel) return wpilot_err('selector required.');
            $effects_map = [
                'glow'            => 'box-shadow:0 0 20px rgba(59,130,246,0.5);',
                'lift'            => 'transform:translateY(-8px);box-shadow:0 20px 40px rgba(0,0,0,0.15);',
                'scale'           => 'transform:scale(1.05);',
                'border-glow'     => 'border-color:rgba(59,130,246,0.8);box-shadow:0 0 15px rgba(59,130,246,0.3);',
                'color-shift'     => 'filter:hue-rotate(30deg);',
                'shadow-pop'      => 'box-shadow:0 12px 35px rgba(0,0,0,0.2);transform:translateY(-4px);',
                'underline-slide' => 'background-size:100% 2px;',
            ];
            $base_trans = 'transition:all 0.3s ease;';
            $hover_css  = $effects_map[$effect] ?? $effects_map['lift'];
            $css = "{$sel}{{$base_trans}}";
            if ($effect === 'underline-slide') {
                $css .= "\n{$sel}{background-image:linear-gradient(currentColor,currentColor);background-size:0 2px;background-position:left bottom;background-repeat:no-repeat;{$base_trans}}";
            }
            $css .= "\n{$sel}:hover{{$hover_css}}";
            $curr = wp_get_custom_css();
            if (strpos($curr, "{$sel}:hover") !== false) {
                $esc = preg_quote($sel, '/');
                $curr = preg_replace('/' . $esc . ':hover\s*\{[^}]*\}/s', '', $curr);
            }
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot hover:{$effect} */\n" . $css);
            return wpilot_ok("Hover effect '{$effect}' applied to {$sel}.", ['css' => $css]);

        case 'gradient_text':
            $sel   = $params['selector'] ?? '';
            $from  = $params['from_color'] ?? '#667eea';
            $to    = $params['to_color'] ?? '#764ba2';
            $dir   = $params['direction'] ?? '90deg';
            if (!$sel) return wpilot_err('selector required.');
            $css = "{$sel}{background:linear-gradient({$dir},{$from},{$to});-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}";
            $curr = wp_get_custom_css();
            $esc = preg_quote($sel, '/');
            $curr = preg_replace('/' . $esc . '\s*\{[^}]*background-clip:text[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot gradient-text */\n" . $css);
            return wpilot_ok("Gradient text applied to {$sel}.", ['css' => $css]);

        case 'glassmorphism':
            $sel     = $params['selector'] ?? '';
            $blur    = $params['blur'] ?? '20px';
            $opacity = $params['opacity'] ?? '0.1';
            if (!$sel) return wpilot_err('selector required.');
            $css = "{$sel}{backdrop-filter:blur({$blur});-webkit-backdrop-filter:blur({$blur});background:rgba(255,255,255,{$opacity});border:1px solid rgba(255,255,255,0.2);border-radius:16px;box-shadow:0 8px 32px rgba(0,0,0,0.1);}";
            $curr = wp_get_custom_css();
            $esc = preg_quote($sel, '/');
            $curr = preg_replace('/' . $esc . '\s*\{[^}]*backdrop-filter[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot glassmorphism */\n" . $css);
            return wpilot_ok("Glassmorphism applied to {$sel}.", ['css' => $css]);

        case 'parallax_section':
            $sel   = $params['selector'] ?? '';
            $pid   = intval($params['page_id'] ?? 0);
            $img   = $params['image_url'] ?? '';
            $speed = floatval($params['speed'] ?? 0.5);
            if (!$sel && !$pid) return wpilot_err('selector or page_id required.');
            if (!$sel) $sel = '.page-id-' . $pid . ' .entry-content > *:first-child';
            $bg_css = $img ? "background-image:url('{$img}');" : '';
            $css = "{$sel}{{$bg_css}background-attachment:fixed;background-position:center;background-repeat:no-repeat;background-size:cover;min-height:400px;position:relative;overflow:hidden;}";
            $curr = wp_get_custom_css();
            $esc = preg_quote($sel, '/');
            $curr = preg_replace('/' . $esc . '\s*\{[^}]*background-attachment:fixed[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot parallax */\n" . $css);
            return wpilot_ok("Parallax applied to {$sel}.", ['css' => $css]);

        case 'scroll_animations':
            $pid       = intval($params['page_id'] ?? 0);
            $animation = $params['animation'] ?? 'fadeInUp';
            $stagger   = floatval($params['stagger'] ?? 0.1);
            if (!$pid) return wpilot_err('page_id required.');
            $anims = [
                'fadeInUp'     => 'opacity:0;transform:translateY(40px);',
                'fadeInLeft'   => 'opacity:0;transform:translateX(-40px);',
                'fadeInRight'  => 'opacity:0;transform:translateX(40px);',
                'scaleIn'      => 'opacity:0;transform:scale(0.85);',
                'slideUp'      => 'transform:translateY(60px);opacity:0;',
            ];
            $initial = $anims[$animation] ?? $anims['fadeInUp'];
            $scope = ".page-id-{$pid}";
            $css  = "{$scope} .wpilot-scroll-anim{{$initial}transition:all 0.7s cubic-bezier(0.22,1,0.36,1);}";
            $css .= "\n{$scope} .wpilot-scroll-anim.wpilot-visible{opacity:1;transform:none;}";
            $js_code = "(function(){if(document.body.classList.contains('page-id-{$pid}')){var els=document.querySelectorAll('{$scope} .entry-content > *');var d=0;els.forEach(function(el){el.classList.add('wpilot-scroll-anim');el.style.transitionDelay=d+'s';d+={$stagger};});var obs=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting){e.target.classList.add('wpilot-visible');obs.unobserve(e.target);}});},{threshold:0.1});els.forEach(function(el){obs.observe(el);});}})();";
            $curr = wp_get_custom_css();
            $esc = preg_quote("{$scope} .wpilot-scroll-anim", '/');
            $curr = preg_replace('/' . $esc . '\s*\{[^}]*\}/s', '', $curr);
            $curr = preg_replace('/' . $esc . '\.wpilot-visible\s*\{[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot scroll-anim:{$animation} */\n" . $css);
            $stored_js = get_option('wpilot_footer_scripts', '');
            $stored_js = preg_replace('/<!-- wpilot-scroll-anim-' . $pid . ' -->.*?<!-- \/wpilot-scroll-anim-' . $pid . ' -->/s', '', $stored_js);
            $stored_js .= "\n<!-- wpilot-scroll-anim-{$pid} --><script>{$js_code}</script><!-- /wpilot-scroll-anim-{$pid} -->";
            update_option('wpilot_footer_scripts', trim($stored_js));
            return wpilot_ok("Scroll animation '{$animation}' added to page {$pid}.", ['css' => $css]);

        // ═══ RESPONSIVE TOOLS ══════════════════════════════════════

        case 'responsive_fix':
            $sel      = $params['selector'] ?? '';
            $mobile   = $params['mobile_css'] ?? '';
            $tablet   = $params['tablet_css'] ?? '';
            $desktop  = $params['desktop_css'] ?? '';
            if (!$sel) return wpilot_err('selector required.');
            $css = '';
            if ($mobile)  $css .= "@media(max-width:768px){{$sel}{{$mobile}}}\n";
            if ($tablet)  $css .= "@media(min-width:769px) and (max-width:1024px){{$sel}{{$tablet}}}\n";
            if ($desktop) $css .= "@media(min-width:1025px){{$sel}{{$desktop}}}\n";
            if (!$css) return wpilot_err('Provide at least one of: mobile_css, tablet_css, desktop_css.');
            $curr = wp_get_custom_css();
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot responsive-fix */\n" . $css);
            return wpilot_ok("Responsive CSS applied to {$sel}.", ['css' => $css]);

        case 'responsive_grid':
            $sel     = $params['selector'] ?? '';
            $cols_d  = intval($params['columns_desktop'] ?? 3);
            $cols_t  = intval($params['columns_tablet'] ?? 2);
            $cols_m  = intval($params['columns_mobile'] ?? 1);
            $gap     = $params['gap'] ?? '24px';
            if (!$sel) return wpilot_err('selector required.');
            $css  = "{$sel}{display:grid;grid-template-columns:repeat({$cols_d},1fr);gap:{$gap};}";
            $css .= "\n@media(max-width:1024px){{$sel}{grid-template-columns:repeat({$cols_t},1fr);}}";
            $css .= "\n@media(max-width:768px){{$sel}{grid-template-columns:repeat({$cols_m},1fr);}}";
            $curr = wp_get_custom_css();
            $esc = preg_quote($sel, '/');
            $curr = preg_replace('/' . $esc . '\s*\{[^}]*display:grid[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot responsive-grid */\n" . $css);
            return wpilot_ok("Responsive grid ({$cols_d}/{$cols_t}/{$cols_m} cols) applied to {$sel}.", ['css' => $css]);

        case 'responsive_text':
            $sel  = $params['selector'] ?? '';
            $min  = $params['min_size'] ?? '16px';
            $max  = $params['max_size'] ?? '48px';
            $pref = $params['preferred'] ?? '4vw';
            if (!$sel) return wpilot_err('selector required.');
            $css = "{$sel}{font-size:clamp({$min},{$pref},{$max});}";
            $curr = wp_get_custom_css();
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot responsive-text */\n" . $css);
            return wpilot_ok("Responsive text (clamp {$min}..{$max}) applied to {$sel}.", ['css' => $css]);

        // ═══ DARK MODE & THEMING ═══════════════════════════════════

        case 'dark_mode':
            $pid     = intval($params['page_id'] ?? 0);
            $bg      = $params['background'] ?? '#0f172a';
            $text    = $params['text_color'] ?? '#e2e8f0';
            $accent  = $params['accent_color'] ?? '#3b82f6';
            $card_bg = $params['card_bg'] ?? '#1e293b';
            $scope = $pid ? ".page-id-{$pid}" : 'body';
            $css  = "{$scope}{background-color:{$bg};color:{$text};}";
            $css .= "\n{$scope} h1,{$scope} h2,{$scope} h3,{$scope} h4,{$scope} h5,{$scope} h6{color:{$text};}";
            $css .= "\n{$scope} a{color:{$accent};}";
            $css .= "\n{$scope} .card,{$scope} .wp-block-group,{$scope} .entry-content > div{background-color:{$card_bg};color:{$text};}";
            $css .= "\n{$scope} input,{$scope} textarea,{$scope} select{background-color:{$card_bg};color:{$text};border-color:rgba(255,255,255,0.1);}";
            $css .= "\n{$scope} .woocommerce .product,{$scope} .woocommerce-page .product{background-color:{$card_bg};color:{$text};}";
            $css .= "\n{$scope} .site-header,{$scope} .site-footer{background-color:{$bg};}";
            $curr = wp_get_custom_css();
            $curr = preg_replace('/\/\*\s*WPilot dark-mode[^*]*\*\/[^\/]*(?=\/\*|$)/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot dark-mode */\n" . $css);
            $target = $pid ? "page {$pid}" : 'entire site';
            return wpilot_ok("Dark mode applied to {$target}.", ['css' => $css]);

        // ═══ PREMIUM BUTTONS ═══════════════════════════════════════

        case 'premium_buttons':
            $sel   = $params['selector'] ?? '.wp-block-button__link';
            $style = $params['style'] ?? 'gradient';
            $styles_map = [
                'gradient'     => 'background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;border:none;padding:14px 32px;border-radius:8px;font-weight:600;transition:all 0.3s;box-shadow:0 4px 15px rgba(102,126,234,0.4);',
                'glass'        => 'backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);color:#fff;padding:14px 32px;border-radius:12px;transition:all 0.3s;',
                'neon'         => 'background:transparent;color:#0ff;border:2px solid #0ff;padding:14px 32px;border-radius:8px;text-shadow:0 0 10px #0ff;box-shadow:0 0 20px rgba(0,255,255,0.3),inset 0 0 20px rgba(0,255,255,0.1);transition:all 0.3s;',
                'outline-glow' => 'background:transparent;color:#3b82f6;border:2px solid #3b82f6;padding:14px 32px;border-radius:8px;transition:all 0.3s;',
                '3d'           => 'background:#3b82f6;color:#fff;border:none;padding:14px 32px;border-radius:8px;box-shadow:0 6px 0 #1d4ed8;transform:translateY(0);transition:all 0.15s;',
                'pill'         => 'background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%);color:#fff;border:none;padding:14px 40px;border-radius:50px;font-weight:600;transition:all 0.3s;box-shadow:0 4px 15px rgba(245,87,108,0.4);',
            ];
            $hover_map = [
                'gradient'     => 'transform:translateY(-2px);box-shadow:0 8px 25px rgba(102,126,234,0.5);',
                'glass'        => 'background:rgba(255,255,255,0.25);transform:translateY(-2px);',
                'neon'         => 'background:rgba(0,255,255,0.1);box-shadow:0 0 40px rgba(0,255,255,0.5),inset 0 0 40px rgba(0,255,255,0.2);',
                'outline-glow' => 'background:#3b82f6;color:#fff;box-shadow:0 0 20px rgba(59,130,246,0.4);',
                '3d'           => 'transform:translateY(4px);box-shadow:0 2px 0 #1d4ed8;',
                'pill'         => 'transform:translateY(-2px);box-shadow:0 8px 25px rgba(245,87,108,0.5);',
            ];
            $base  = $styles_map[$style] ?? $styles_map['gradient'];
            $hover = $hover_map[$style] ?? $hover_map['gradient'];
            $css   = "{$sel}{{$base}}\n{$sel}:hover{{$hover}}";
            $curr = wp_get_custom_css();
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot premium-btn:{$style} */\n" . $css);
            return wpilot_ok("Premium button style '{$style}' applied to {$sel}.", ['css' => $css]);

        // ═══ CARD LAYOUT ═══════════════════════════════════════════

        case 'card_layout':
            $sel   = $params['selector'] ?? '.card';
            $style = $params['style'] ?? 'shadow';
            $card_styles = [
                'minimal'         => 'padding:24px;border-radius:12px;transition:all 0.3s;',
                'bordered'        => 'padding:24px;border:1px solid rgba(0,0,0,0.1);border-radius:12px;transition:all 0.3s;',
                'shadow'          => 'padding:24px;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,0.08);transition:all 0.3s;',
                'glass'           => 'padding:24px;backdrop-filter:blur(15px);-webkit-backdrop-filter:blur(15px);background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);border-radius:16px;transition:all 0.3s;',
                'neon'            => 'padding:24px;border:1px solid rgba(59,130,246,0.3);border-radius:12px;box-shadow:0 0 15px rgba(59,130,246,0.1);transition:all 0.3s;',
                'gradient-border' => 'padding:24px;border-radius:16px;position:relative;background:var(--wp--preset--color--background,#fff);transition:all 0.3s;',
            ];
            $card_hovers = [
                'minimal'         => 'box-shadow:0 4px 20px rgba(0,0,0,0.08);',
                'bordered'        => 'border-color:rgba(59,130,246,0.5);transform:translateY(-4px);',
                'shadow'          => 'box-shadow:0 12px 40px rgba(0,0,0,0.15);transform:translateY(-6px);',
                'glass'           => 'background:rgba(255,255,255,0.2);transform:translateY(-4px);',
                'neon'            => 'border-color:rgba(59,130,246,0.8);box-shadow:0 0 30px rgba(59,130,246,0.2);',
                'gradient-border' => 'transform:translateY(-4px);box-shadow:0 12px 40px rgba(0,0,0,0.1);',
            ];
            $base  = $card_styles[$style] ?? $card_styles['shadow'];
            $hover = $card_hovers[$style] ?? $card_hovers['shadow'];
            $css = "{$sel}{{$base}}\n{$sel}:hover{{$hover}}";
            if ($style === 'gradient-border') {
                $css .= "\n{$sel}::before{content:'';position:absolute;inset:-2px;border-radius:18px;background:linear-gradient(135deg,#667eea,#764ba2);z-index:-1;}";
            }
            $curr = wp_get_custom_css();
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot card-layout:{$style} */\n" . $css);
            return wpilot_ok("Card layout '{$style}' applied to {$sel}.", ['css' => $css]);

        // ═══ UX TOOLS ══════════════════════════════════════════════

        case 'add_whatsapp':
        case 'whatsapp_chat':
        case 'whatsapp_button':
            $phone = preg_replace('/[^0-9+]/', '', $params['phone'] ?? $params['number'] ?? '');
            $message = sanitize_text_field($params['message'] ?? $params['text'] ?? 'Hej! Jag vill veta mer.');
            $position = sanitize_text_field($params['position'] ?? 'bottom-right');
            $label = sanitize_text_field($params['label'] ?? '');
            if (empty($phone)) return wpilot_err('Phone number required (with country code, e.g. +46701234567)');
            $wa_url = 'https://wa.me/' . ltrim($phone, '+') . '?text=' . rawurlencode($message);
            $pos_css = $position === 'bottom-left' ? 'left:24px;right:auto;' : 'right:24px;left:auto;';
            $code = "<?php\nadd_action('wp_footer', function() {\n";
            $code .= "    if (is_admin()) return;\n";
            $code .= "    echo '<a href=\"" . esc_url($wa_url) . "\" target=\"_blank\" rel=\"noopener\" id=\"wpilot-whatsapp\" style=\"position:fixed;bottom:90px;{$pos_css}z-index:9999;width:60px;height:60px;border-radius:50%;background:#25D366;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 20px rgba(37,211,102,0.4);transition:all 0.3s ease;text-decoration:none;\" onmouseover=\"this.style.transform=\\'scale(1.1)\\'\" onmouseout=\"this.style.transform=\\'scale(1)\\'\">"
                . "<svg width=\"32\" height=\"32\" viewBox=\"0 0 24 24\" fill=\"white\"><path d=\"M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z\"/></svg>"
                . "</a>';\n";
            if ($label) {
                $code .= "    echo '<style>#wpilot-whatsapp::after{content:\"" . esc_attr($label) . "\";position:absolute;right:70px;background:var(--wp-bg,#fff);color:var(--wp-text,#333);padding:8px 16px;border-radius:20px;font-size:14px;white-space:nowrap;box-shadow:0 2px 10px rgba(0,0,0,0.1);font-family:system-ui,sans-serif;}#wpilot-whatsapp:hover::after{display:block;}</style>';\n";
            }
            $code .= "});\n";
            if (function_exists('wpilot_mu_register')) {
                wpilot_mu_register('whatsapp', $code);
            } else {
                $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
                if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
                file_put_contents($mu_dir . '/wpilot-whatsapp.php', $code);
            }
            $pos_text = $position === 'bottom-left' ? 'bottom-left' : 'bottom-right';
            return wpilot_ok("WhatsApp chat button added ({$pos_text}). Phone: {$phone}. Visitors can click to chat directly.", ['phone' => $phone, 'url' => $wa_url]);

        case 'remove_whatsapp':
            if (function_exists('wpilot_mu_remove')) wpilot_mu_remove('whatsapp');
            else @unlink((defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins') . '/wpilot-whatsapp.php');
            return wpilot_ok('WhatsApp button removed.');

        case 'smooth_scroll':
            $enable     = ($params['enable'] ?? true) !== false;
            $scroll_top = ($params['scroll_to_top'] ?? true) !== false;
            $css = '';
            if ($enable) $css .= "html{scroll-behavior:smooth;}";
            if ($scroll_top) {
                $css .= "\n.wpilot-scroll-top{position:fixed;bottom:30px;right:30px;width:48px;height:48px;border-radius:50%;background:#3b82f6;color:#fff;border:none;font-size:20px;cursor:pointer;opacity:0;visibility:hidden;transition:all 0.3s;z-index:9999;box-shadow:0 4px 15px rgba(59,130,246,0.4);display:flex;align-items:center;justify-content:center;}";
                $css .= "\n.wpilot-scroll-top.visible{opacity:1;visibility:visible;}";
                $css .= "\n.wpilot-scroll-top:hover{transform:translateY(-3px);box-shadow:0 8px 25px rgba(59,130,246,0.5);}";
                $js_code = "(function(){var b=document.createElement('button');b.className='wpilot-scroll-top';b.textContent='\\u2191';b.onclick=function(){window.scrollTo({top:0,behavior:'smooth'})};document.body.appendChild(b);window.addEventListener('scroll',function(){b.classList.toggle('visible',window.scrollY>300)});})();";
                $stored_js = get_option('wpilot_footer_scripts', '');
                $stored_js = preg_replace('/<!-- wpilot-scroll-top -->.*?<!-- \/wpilot-scroll-top -->/s', '', $stored_js);
                $stored_js .= "\n<!-- wpilot-scroll-top --><script>{$js_code}</script><!-- /wpilot-scroll-top -->";
                update_option('wpilot_footer_scripts', trim($stored_js));
            }
            $curr = wp_get_custom_css();
            $curr = preg_replace('/html\s*\{[^}]*scroll-behavior:smooth[^}]*\}/s', '', $curr);
            $curr = preg_replace('/\.wpilot-scroll-top[^{]*\{[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot smooth-scroll */\n" . $css);
            return wpilot_ok("Smooth scroll enabled.", ['css' => $css]);

        case 'loading_animation':
            $style    = $params['style'] ?? 'fade';
            $duration = $params['duration'] ?? '0.5s';
            $anims = [
                'fade'     => "@keyframes wpilotFadeIn{from{opacity:0}to{opacity:1}}body{animation:wpilotFadeIn {$duration} ease-out;}",
                'slide-up' => "@keyframes wpilotSlideUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:none}}body{animation:wpilotSlideUp {$duration} ease-out;}",
                'blur'     => "@keyframes wpilotBlurIn{from{opacity:0;filter:blur(10px)}to{opacity:1;filter:none}}body{animation:wpilotBlurIn {$duration} ease-out;}",
            ];
            $css = $anims[$style] ?? $anims['fade'];
            $curr = wp_get_custom_css();
            $curr = preg_replace('/@keyframes wpilot(FadeIn|SlideUp|BlurIn)\s*\{[^}]*\{[^}]*\}[^}]*\}/s', '', $curr);
            $curr = preg_replace('/body\s*\{[^}]*animation:wpilot[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot loading-anim:{$style} */\n" . $css);
            return wpilot_ok("Loading animation '{$style}' applied.", ['css' => $css]);

        case 'text_effects':
            $sel    = $params['selector'] ?? '';
            $effect = $params['effect'] ?? 'glow';
            if (!$sel) return wpilot_err('selector required.');
            $effects = [
                'typing'             => "overflow:hidden;white-space:nowrap;border-right:3px solid;animation:wpilotTyping 3s steps(40) 1s forwards,wpilotBlink 0.75s step-end infinite;width:0;",
                'glow'               => "text-shadow:0 0 10px rgba(59,130,246,0.5),0 0 20px rgba(59,130,246,0.3),0 0 40px rgba(59,130,246,0.1);",
                'shadow-3d'          => "text-shadow:1px 1px 0 #ccc,2px 2px 0 #bbb,3px 3px 0 #aaa,4px 4px 5px rgba(0,0,0,0.2);",
                'outline'            => "-webkit-text-stroke:2px currentColor;-webkit-text-fill-color:transparent;",
                'gradient-underline' => "text-decoration:none;background-image:linear-gradient(135deg,#667eea,#764ba2);background-size:100% 3px;background-position:left bottom;background-repeat:no-repeat;padding-bottom:4px;",
                'highlight'          => "background:linear-gradient(120deg,rgba(255,220,0,0.3) 0%,rgba(255,220,0,0.3) 100%);padding:2px 6px;border-radius:4px;",
            ];
            $effect_css = $effects[$effect] ?? $effects['glow'];
            $css = "{$sel}{{$effect_css}}";
            if ($effect === 'typing') {
                $css .= "\n@keyframes wpilotTyping{from{width:0}to{width:100%}}";
                $css .= "\n@keyframes wpilotBlink{50%{border-color:transparent}}";
            }
            $curr = wp_get_custom_css();
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot text-fx:{$effect} */\n" . $css);
            return wpilot_ok("Text effect '{$effect}' applied to {$sel}.", ['css' => $css]);

        case 'image_effects':
            $sel    = $params['selector'] ?? 'img';
            $effect = $params['effect'] ?? 'zoom-hover';
            $effects = [
                'zoom-hover'      => "overflow:hidden;",
                'grayscale-hover' => "filter:grayscale(100%);transition:filter 0.4s;",
                'blur-hover'      => "transition:filter 0.4s;",
                'overlay'         => "position:relative;",
                'rounded'         => "border-radius:16px;overflow:hidden;",
                'shadow-3d'       => "box-shadow:0 20px 40px rgba(0,0,0,0.2);border-radius:12px;transform:perspective(1000px) rotateY(-5deg);transition:all 0.4s;",
                'tilt'            => "transition:transform 0.4s;transform:perspective(800px) rotateY(0deg);",
            ];
            $hovers = [
                'zoom-hover'      => "transform:scale(1.1);transition:transform 0.4s;",
                'grayscale-hover' => "filter:grayscale(0%);",
                'blur-hover'      => "filter:blur(3px);",
                'shadow-3d'       => "transform:perspective(1000px) rotateY(0deg);box-shadow:0 30px 60px rgba(0,0,0,0.3);",
                'tilt'            => "transform:perspective(800px) rotateY(5deg);",
            ];
            $effect_css = $effects[$effect] ?? $effects['zoom-hover'];
            $css = "{$sel}{{$effect_css}}";
            $hv = $hovers[$effect] ?? '';
            if ($hv) $css .= "\n{$sel}:hover{{$hv}}";
            $curr = wp_get_custom_css();
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot img-fx:{$effect} */\n" . $css);
            return wpilot_ok("Image effect '{$effect}' applied to {$sel}.", ['css' => $css]);

        case 'sticky_header':
            $sel    = $params['selector'] ?? '.site-header';
            $shrink = !empty($params['shrink']);
            $blur   = !empty($params['blur_bg']);
            $css = "{$sel}{position:sticky;top:0;z-index:1000;transition:all 0.3s;}";
            if ($shrink) {
                $css .= "\n{$sel}.wpilot-shrunk{padding-top:8px !important;padding-bottom:8px !important;box-shadow:0 2px 20px rgba(0,0,0,0.1);}";
            }
            if ($blur) {
                $css .= "\n{$sel}{backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);background:rgba(255,255,255,0.85) !important;}";
            }
            if ($shrink) {
                $esc_sel = addslashes($sel);
                $js_code = "(function(){var h=document.querySelector('{$esc_sel}');if(!h)return;window.addEventListener('scroll',function(){h.classList.toggle('wpilot-shrunk',window.scrollY>80)});})();";
                $stored_js = get_option('wpilot_footer_scripts', '');
                $stored_js = preg_replace('/<!-- wpilot-sticky-header -->.*?<!-- \/wpilot-sticky-header -->/s', '', $stored_js);
                $stored_js .= "\n<!-- wpilot-sticky-header --><script>{$js_code}</script><!-- /wpilot-sticky-header -->";
                update_option('wpilot_footer_scripts', trim($stored_js));
            }
            $curr = wp_get_custom_css();
            $esc = preg_quote($sel, '/');
            $curr = preg_replace('/' . $esc . '\s*\{[^}]*position:sticky[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot sticky-header */\n" . $css);
            return wpilot_ok("Sticky header applied to {$sel}.", ['css' => $css]);

        case 'custom_cursor':
            $style = $params['style'] ?? 'dot';
            $color = $params['color'] ?? '#3b82f6';
            $size  = $params['size'] ?? '20px';
            $cursors = [
                'dot'       => "body{cursor:none;}",
                'circle'    => "body{cursor:none;}",
                'crosshair' => "body{cursor:crosshair;}",
                'emoji'     => "body{cursor:crosshair;}",
            ];
            $css = $cursors[$style] ?? $cursors['dot'];
            if (in_array($style, ['dot', 'circle'])) {
                $border_or_bg = ($style === 'dot') ? "background:{$color};" : "border:2px solid {$color};";
                $js_code = "(function(){var c=document.createElement('div');c.id='wpilot-cursor';c.style.cssText='position:fixed;width:{$size};height:{$size};{$border_or_bg}border-radius:50%;pointer-events:none;z-index:99999;transform:translate(-50%,-50%);transition:transform 0.1s;top:-50px;left:-50px;';document.body.appendChild(c);document.addEventListener('mousemove',function(e){c.style.left=e.clientX+'px';c.style.top=e.clientY+'px';});})();";
                $stored_js = get_option('wpilot_footer_scripts', '');
                $stored_js = preg_replace('/<!-- wpilot-cursor -->.*?<!-- \/wpilot-cursor -->/s', '', $stored_js);
                $stored_js .= "\n<!-- wpilot-cursor --><script>{$js_code}</script><!-- /wpilot-cursor -->";
                update_option('wpilot_footer_scripts', trim($stored_js));
            }
            $curr = wp_get_custom_css();
            $curr = preg_replace('/\/\*\s*WPilot custom-cursor[^*]*\*\/[^\/]*(?=\/\*|$)/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot custom-cursor:{$style} */\n" . $css);
            return wpilot_ok("Custom cursor '{$style}' applied.", ['css' => $css]);

        case 'page_transition':
            $style    = $params['style'] ?? 'fade';
            $duration = $params['duration'] ?? '0.3s';
            $anims = [
                'fade'  => "@keyframes wpilotPageFade{from{opacity:0}to{opacity:1}}.wpilot-page-trans{animation:wpilotPageFade {$duration} ease-out;}",
                'slide' => "@keyframes wpilotPageSlide{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:none}}.wpilot-page-trans{animation:wpilotPageSlide {$duration} ease-out;}",
                'zoom'  => "@keyframes wpilotPageZoom{from{opacity:0;transform:scale(0.95)}to{opacity:1;transform:none}}.wpilot-page-trans{animation:wpilotPageZoom {$duration} ease-out;}",
            ];
            $css = $anims[$style] ?? $anims['fade'];
            $js_code = "document.body.classList.add('wpilot-page-trans');";
            $stored_js = get_option('wpilot_footer_scripts', '');
            $stored_js = preg_replace('/<!-- wpilot-page-trans -->.*?<!-- \/wpilot-page-trans -->/s', '', $stored_js);
            $stored_js .= "\n<!-- wpilot-page-trans --><script>{$js_code}</script><!-- /wpilot-page-trans -->";
            update_option('wpilot_footer_scripts', trim($stored_js));
            $curr = wp_get_custom_css();
            $curr = preg_replace('/@keyframes wpilotPage(Fade|Slide|Zoom)\s*\{[^}]*\{[^}]*\}[^}]*\}/s', '', $curr);
            $curr = preg_replace('/\.wpilot-page-trans\s*\{[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot page-transition:{$style} */\n" . $css);
            return wpilot_ok("Page transition '{$style}' applied.", ['css' => $css]);

        case 'marquee_text':
            $text  = sanitize_text_field($params['text'] ?? '');
            $speed = $params['speed'] ?? '20s';
            $dir   = ($params['direction'] ?? 'left') === 'right' ? 'reverse' : 'normal';
            $bg    = $params['background'] ?? '#1e293b';
            $color = $params['color'] ?? '#ffffff';
            if (!$text) return wpilot_err('text required.');
            $css  = ".wpilot-marquee{overflow:hidden;white-space:nowrap;background:{$bg};color:{$color};padding:12px 0;font-size:18px;font-weight:500;}";
            $css .= "\n.wpilot-marquee span{display:inline-block;animation:wpilotMarquee {$speed} linear infinite;animation-direction:{$dir};}";
            $css .= "\n.wpilot-marquee:hover span{animation-play-state:paused;}";
            $css .= "\n@keyframes wpilotMarquee{0%{transform:translateX(100vw)}100%{transform:translateX(-100%)}}";
            $safe_text = esc_attr($text);
            $js_code = "(function(){if(document.querySelector('.wpilot-marquee'))return;var d=document.createElement('div');d.className='wpilot-marquee';var s=document.createElement('span');s.textContent='" . addslashes($text) . "  \\u00a0\\u00a0\\u00a0  " . addslashes($text) . "  \\u00a0\\u00a0\\u00a0  " . addslashes($text) . "';d.appendChild(s);var b=document.querySelector('.site-content')||document.querySelector('main')||document.body.firstElementChild;if(b)b.parentNode.insertBefore(d,b);})();";
            $stored_js = get_option('wpilot_footer_scripts', '');
            $stored_js = preg_replace('/<!-- wpilot-marquee -->.*?<!-- \/wpilot-marquee -->/s', '', $stored_js);
            $stored_js .= "\n<!-- wpilot-marquee --><script>{$js_code}</script><!-- /wpilot-marquee -->";
            update_option('wpilot_footer_scripts', trim($stored_js));
            $curr = wp_get_custom_css();
            $curr = preg_replace('/\.wpilot-marquee[^{]*\{[^}]*\}/s', '', $curr);
            $curr = preg_replace('/@keyframes wpilotMarquee\s*\{[^}]*\}/s', '', $curr);
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot marquee */\n" . $css);
            return wpilot_ok("Marquee text added.", ['css' => $css]);

        case 'count_up':
            $pid = intval($params['page_id'] ?? 0);
            $sel = $params['selector'] ?? '[data-count]';
            if (!$pid) return wpilot_err('page_id required.');
            $scope = ".page-id-{$pid}";
// Built by Weblease
            $css = "{$scope} {$sel}{font-variant-numeric:tabular-nums;}";
            $js_code = "(function(){if(!document.body.classList.contains('page-id-{$pid}'))return;var els=document.querySelectorAll('{$scope} {$sel}');var obs=new IntersectionObserver(function(entries){entries.forEach(function(e){if(e.isIntersecting){var el=e.target;var target=parseInt(el.getAttribute('data-count')||el.textContent);var start=0;var dur=2000;var step=Math.max(Math.ceil(dur/target),1);var timer=setInterval(function(){start++;el.textContent=start;if(start>=target){clearInterval(timer);el.textContent=target;}},step);obs.unobserve(el);}});},{threshold:0.3});els.forEach(function(el){obs.observe(el);});})();";
            $stored_js = get_option('wpilot_footer_scripts', '');
            $stored_js = preg_replace('/<!-- wpilot-countup-' . $pid . ' -->.*?<!-- \/wpilot-countup-' . $pid . ' -->/s', '', $stored_js);
            $stored_js .= "\n<!-- wpilot-countup-{$pid} --><script>{$js_code}</script><!-- /wpilot-countup-{$pid} -->";
            update_option('wpilot_footer_scripts', trim($stored_js));
            $curr = wp_get_custom_css();
            wpilot_save_css_snapshot();
            wp_update_custom_css_post(trim($curr) . "\n/* WPilot count-up */\n" . $css);
            return wpilot_ok("Count-up animation added to page {$pid}.", ['css' => $css]);

        
        // ═══ API CONNECTOR — Call any external API ═══
        case 'build_header':
        case 'create_custom_header':
            $style = sanitize_text_field($params['style'] ?? 'modern');
            $logo_url = esc_url($params['logo_url'] ?? $params['logo'] ?? '');
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $bg = sanitize_text_field($params['background'] ?? '#0a0a0a');
            $transparent = !empty($params['transparent']);
            $sticky = !empty($params['sticky']) || $style === 'sticky';
            // Get site info
            $site_name = get_bloginfo('name');
            $tagline = get_bloginfo('description');
            // Get menu items
            $menu_items = [];
            $locations = get_nav_menu_locations();
            $menu_id = $locations['menu-1'] ?? $locations['primary'] ?? $locations['main-menu'] ?? 0;
            if ($menu_id) {
                $items = wp_get_nav_menu_items($menu_id);
                if ($items) foreach ($items as $item) {
                    if ($item->menu_item_parent == 0) $menu_items[] = ['title' => $item->title, 'url' => $item->url];
                }
            }
            if (empty($menu_items)) {
                $menu_items = [['title' => 'Home', 'url' => '/'], ['title' => 'Shop', 'url' => '/shop'], ['title' => 'About', 'url' => '/about'], ['title' => 'Contact', 'url' => '/contact']];
            }
            // Build menu HTML
            $nav_html = '';
            foreach ($menu_items as $item) {
                $nav_html .= '<a href="' . esc_url($item['url']) . '" style="color:rgba(255,255,255,0.8);text-decoration:none;font-weight:500;font-size:0.95rem;transition:color 0.3s;padding:8px 0">' . esc_html($item['title']) . '</a>';
            }
            // Logo
            $logo_html = $logo_url
                ? '<img src="' . $logo_url . '" alt="' . esc_attr($site_name) . '" style="height:36px;width:auto">'
                : '<span style="font-size:1.3rem;font-weight:800;color:' . $accent . ';letter-spacing:-0.5px">' . esc_html($site_name) . '</span>';
            // CTA button
            $cta = sanitize_text_field($params['cta_text'] ?? '');
            $cta_url = esc_url($params['cta_url'] ?? '/shop');
            $cta_html = $cta ? '<a href="' . $cta_url . '" style="padding:10px 24px;background:' . $accent . ';color:#fff;border-radius:50px;text-decoration:none;font-weight:600;font-size:0.9rem">' . esc_html($cta) . '</a>' : '';
            // Header styles
            $bg_style = $transparent ? 'transparent' : $bg;
            $position = ($transparent || $sticky) ? 'position:fixed;top:0;left:0;right:0;z-index:9999' : 'position:relative';
            $backdrop = $sticky ? 'backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);background:rgba(10,10,10,0.85) !important' : '';
            // Built by Weblease
            $header_html = '<div id="wpilot-header" style="' . $position . ';background:' . $bg_style . ';' . $backdrop . ';border-bottom:1px solid rgba(255,255,255,0.06);padding:0 24px;transition:all 0.3s">';
            $header_html .= '<div style="max-width:1200px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;height:70px">';
            $header_html .= '<div>' . $logo_html . '</div>';
            $header_html .= '<nav style="display:flex;gap:28px;align-items:center">' . $nav_html . '</nav>';
            if ($cta_html) $header_html .= '<div>' . $cta_html . '</div>';
            $header_html .= '</div></div>';
            // Add mobile hamburger CSS
            $header_css = "
/* WPilot Custom Header */
.site-header, .elementor-location-header { display: none !important; }
#wpilot-header a:hover { color: {$accent} !important; }
@media (max-width: 768px) {
  #wpilot-header nav { display: none; }
  #wpilot-header { padding: 0 16px; }
}
";
            if ($transparent) $header_css .= "#wpilot-header { background: transparent !important; }\n";
            if ($sticky) $header_css .= "#wpilot-header.scrolled { background: rgba(10,10,10,0.95) !important; backdrop-filter: blur(20px); box-shadow: 0 4px 30px rgba(0,0,0,0.2); }\n";
            // Save header
            update_option('wpilot_custom_header', $header_html);
            $existing_css = wp_get_custom_css();
            $existing_css = preg_replace('/\/\* WPilot Custom Header \*\/.*?(?=\/\*|$)/s', '', $existing_css);
            wp_update_custom_css_post($existing_css . "\n" . $header_css);
            return wpilot_ok("Custom header built: {$style}" . ($sticky ? ' (sticky)' : '') . ($transparent ? ' (transparent)' : ''), [
                'style' => $style, 'menu_items' => count($menu_items), 'has_cta' => !empty($cta),
            ]);

        // ═══ CUSTOM FOOTER BUILDER ═══
        case 'build_footer':
        case 'create_custom_footer':
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $bg = sanitize_text_field($params['background'] ?? '#050505');
            $columns = intval($params['columns'] ?? 4);
            $style = sanitize_text_field($params['style'] ?? 'modern');
            $site_name = get_bloginfo('name');
            $tagline = get_bloginfo('description');
            $email = get_option('admin_email');
            $phone = sanitize_text_field($params['phone'] ?? '');
            $address = sanitize_text_field($params['address'] ?? '');
            $copyright = sanitize_text_field($params['copyright'] ?? '');
            if (empty($copyright)) $copyright = '&copy; ' . date('Y') . ' ' . $site_name . '. All rights reserved.';
            // Social links
            $socials = '';
            $social_links = $params['social'] ?? [];
            if (!empty($social_links)) {
                $icons = ['facebook' => 'f', 'instagram' => 'ig', 'twitter' => 'x', 'linkedin' => 'in', 'youtube' => 'yt', 'tiktok' => 'tk'];
                $socials = '<div style="display:flex;gap:12px;margin-top:16px">';
                foreach ($social_links as $platform => $url) {
                    $label = strtoupper(substr($platform, 0, 2));
                    $socials .= '<a href="' . esc_url($url) . '" target="_blank" style="width:36px;height:36px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:50%;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.5);text-decoration:none;font-size:0.7rem;font-weight:700;transition:all 0.3s">' . $label . '</a>';
                }
                $socials .= '</div>';
            }
            // Newsletter form
            $newsletter = '';
            if (!empty($params['newsletter'])) {
                $newsletter = '<div style="margin-top:16px"><p style="margin:0 0 8px;font-size:0.85rem;color:rgba(255,255,255,0.5)">Subscribe to our newsletter</p>';
                $newsletter .= '<div style="display:flex;gap:8px"><input type="email" placeholder="Email" style="flex:1;padding:10px 14px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:8px;color:#fff;font-size:0.85rem"><button style="padding:10px 20px;background:' . $accent . ';color:#fff;border:none;border-radius:8px;font-weight:600;font-size:0.85rem;cursor:pointer">Join</button></div></div>';
            }
            // Quick links
            $links_html = '';
            $pages = get_pages(['number' => 6, 'sort_column' => 'menu_order']);
            foreach ($pages as $p) {
                $links_html .= '<a href="' . get_permalink($p->ID) . '" style="display:block;color:rgba(255,255,255,0.5);text-decoration:none;padding:4px 0;font-size:0.9rem;transition:color 0.3s">' . esc_html($p->post_title) . '</a>';
            }
            // Build footer
            $footer_html = '<div id="wpilot-footer" style="background:' . $bg . ';padding:60px 24px 30px;font-family:system-ui,sans-serif">';
            $footer_html .= '<div style="max-width:1200px;margin:0 auto;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:40px;margin-bottom:40px">';
            // Col 1: Brand
            $footer_html .= '<div><h4 style="color:#fff;font-size:1.1rem;margin:0 0 12px;font-weight:700">' . esc_html($site_name) . '</h4>';
            $footer_html .= '<p style="color:rgba(255,255,255,0.5);line-height:1.6;margin:0;font-size:0.9rem">' . esc_html($tagline) . '</p>';
            $footer_html .= $socials . '</div>';
            // Col 2: Links
            $footer_html .= '<div><h4 style="color:#fff;font-size:0.95rem;margin:0 0 12px;font-weight:600;text-transform:uppercase;letter-spacing:1px;font-size:0.8rem">Pages</h4>' . $links_html . '</div>';
            // Col 3: Contact
            $footer_html .= '<div><h4 style="color:#fff;font-size:0.95rem;margin:0 0 12px;font-weight:600;text-transform:uppercase;letter-spacing:1px;font-size:0.8rem">Contact</h4>';
            if ($phone) $footer_html .= '<p style="color:rgba(255,255,255,0.5);margin:0 0 6px;font-size:0.9rem">' . esc_html($phone) . '</p>';
            $footer_html .= '<p style="color:rgba(255,255,255,0.5);margin:0 0 6px;font-size:0.9rem">' . esc_html($email) . '</p>';
            if ($address) $footer_html .= '<p style="color:rgba(255,255,255,0.5);margin:0;font-size:0.9rem">' . esc_html($address) . '</p>';
            $footer_html .= '</div>';
            // Col 4: Newsletter or WooCommerce
            if ($newsletter) {
                $footer_html .= '<div><h4 style="color:#fff;font-size:0.95rem;margin:0 0 12px;font-weight:600;text-transform:uppercase;letter-spacing:1px;font-size:0.8rem">Newsletter</h4>' . $newsletter . '</div>';
            }
            $footer_html .= '</div>';
            // Copyright bar
            $footer_html .= '<div style="border-top:1px solid rgba(255,255,255,0.06);padding-top:20px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">';
            $footer_html .= '<p style="color:rgba(255,255,255,0.3);margin:0;font-size:0.8rem">' . $copyright . '</p>';
            $footer_html .= '<div style="display:flex;gap:16px">';
            $footer_html .= '<a href="/privacy-policy" style="color:rgba(255,255,255,0.3);text-decoration:none;font-size:0.8rem">Privacy</a>';
            $footer_html .= '<a href="/terms" style="color:rgba(255,255,255,0.3);text-decoration:none;font-size:0.8rem">Terms</a>';
            $footer_html .= '</div></div></div>';
            // Save
            update_option('wpilot_custom_footer', $footer_html);
            $css = "\n/* WPilot Custom Footer */\n.site-footer .site-info, .elementor-location-footer { display: none !important; }\n#wpilot-footer a:hover { color: {$accent} !important; }\n@media (max-width: 768px) { #wpilot-footer > div > div { grid-template-columns: 1fr !important; } }\n";
            $existing = wp_get_custom_css();
            $existing = preg_replace('/\/\* WPilot Custom Footer \*\/.*?(?=\/\*|$)/s', '', $existing);
            wp_update_custom_css_post($existing . $css);
            return wpilot_ok("Custom footer built: {$style}, {$columns} columns" . ($newsletter ? ', with newsletter' : ''), [
                'columns' => $columns, 'has_newsletter' => !empty($params['newsletter']), 'pages_linked' => count($pages),
            ]);

        // ═══ MOBILE MENU (hamburger) ═══
        case 'build_mobile_menu':
        case 'create_mobile_menu':
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $bg = sanitize_text_field($params['background'] ?? '#0a0a0a');
            // Get menu items
            $menu_items = [];
            $locations = get_nav_menu_locations();
            $menu_id = $locations['menu-1'] ?? $locations['primary'] ?? $locations['main-menu'] ?? 0;
            if ($menu_id) {
                $items = wp_get_nav_menu_items($menu_id);
                if ($items) foreach ($items as $item) {
                    if ($item->menu_item_parent == 0) $menu_items[] = ['title' => $item->title, 'url' => $item->url];
                }
            }
            $nav_links = '';
            foreach ($menu_items as $item) {
                $nav_links .= '<a href="' . esc_url($item['url']) . '" style="display:block;padding:16px 24px;color:#fff;text-decoration:none;font-size:1.1rem;font-weight:500;border-bottom:1px solid rgba(255,255,255,0.06);transition:background 0.3s" onclick="document.getElementById(\'wpilot-mobile-menu\').style.display=\'none\'">' . esc_html($item['title']) . '</a>';
            }
            $hamburger_css = "
/* WPilot Mobile Menu */
#wpilot-hamburger { display: none; background: none; border: none; cursor: pointer; padding: 8px; z-index: 10001; }
#wpilot-hamburger span { display: block; width: 24px; height: 2px; background: #fff; margin: 5px 0; transition: 0.3s; }
#wpilot-mobile-menu { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: {$bg}; z-index: 10000; padding-top: 70px; overflow-y: auto; }
#wpilot-mobile-menu a:hover { background: {$accent}15; color: {$accent} !important; }
@media (max-width: 768px) { #wpilot-hamburger { display: block; } }
";
            // Save hamburger button HTML (injected in header)
            $hamburger_html = '<button id="wpilot-hamburger" onclick="var m=document.getElementById(\'wpilot-mobile-menu\');m.style.display=m.style.display===\'none\'?\'block\':\'none\'"><span></span><span></span><span></span></button>';
            $mobile_menu_html = '<div id="wpilot-mobile-menu">' . $nav_links . '</div>';
            update_option('wpilot_mobile_menu', $hamburger_html . $mobile_menu_html);
            $existing = wp_get_custom_css();
            $existing = preg_replace('/\/\* WPilot Mobile Menu \*\/.*?(?=\/\*|$)/s', '', $existing);
            wp_update_custom_css_post($existing . "\n" . $hamburger_css);
            return wpilot_ok("Mobile hamburger menu built with " . count($menu_items) . " items.", ['items' => count($menu_items)]);

        // ═══ INJECT HEADER/FOOTER INTO PAGES ═══
        case 'activate_custom_header':
            $header = get_option('wpilot_custom_header', '');
            if (empty($header)) return wpilot_err('No custom header built. Use build_header first.');
            // Create mu-plugin to inject
            $mu = ABSPATH . 'wp-content/mu-plugins/wpilot-custom-header.php';
            $code = "<?php\n// WPilot Custom Header\nadd_action('wp_body_open', function() {\n    \$h = get_option('wpilot_custom_header','');\n    \$m = get_option('wpilot_mobile_menu','');\n    if (\$h) echo \$h;\n    if (\$m) echo \$m;\n});\n";
            file_put_contents($mu, $code);
            return wpilot_ok("Custom header activated on all pages.");

        case 'activate_custom_footer':
            $footer = get_option('wpilot_custom_footer', '');
            if (empty($footer)) return wpilot_err('No custom footer built. Use build_footer first.');
            $mu = ABSPATH . 'wp-content/mu-plugins/wpilot-custom-footer.php';
            $code = "<?php\n// WPilot Custom Footer\nadd_action('wp_footer', function() {\n    \$f = get_option('wpilot_custom_footer','');\n    if (\$f) echo \$f;\n}, 5);\n";
            file_put_contents($mu, $code);
            return wpilot_ok("Custom footer activated on all pages.");

        // ═══ MEGA MENU ═══
        case 'create_mega_menu':
        case 'build_mega_menu':
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $items = $params['items'] ?? [];
            if (empty($items)) return wpilot_err('items array required: [{"title":"Shop","url":"/shop","children":[{"title":"Cleaning","url":"/product-category/cleaning"}]}]');
            $menu_html = '<nav id="wpilot-mega" style="display:flex;gap:24px;align-items:center">';
            foreach ($items as $item) {
                $has_children = !empty($item['children']);
                $menu_html .= '<div style="position:relative" class="wpilot-mega-item">';
                $menu_html .= '<a href="' . esc_url($item['url'] ?? '#') . '" style="color:rgba(255,255,255,0.8);text-decoration:none;font-weight:500;padding:8px 0;display:block">' . esc_html($item['title'] ?? '') . ($has_children ? ' ▾' : '') . '</a>';
                if ($has_children) {
                    $menu_html .= '<div class="wpilot-mega-dropdown" style="display:none;position:absolute;top:100%;left:-16px;background:#0f0f0f;border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px;min-width:200px;box-shadow:0 20px 60px rgba(0,0,0,0.4);z-index:999">';
                    foreach ($item['children'] as $child) {
                        $menu_html .= '<a href="' . esc_url($child['url'] ?? '#') . '" style="display:block;padding:8px 12px;color:rgba(255,255,255,0.7);text-decoration:none;border-radius:6px;font-size:0.9rem;transition:all 0.2s">' . esc_html($child['title'] ?? '') . '</a>';
                    }
                    $menu_html .= '</div>';
                }
                $menu_html .= '</div>';
            }
            $menu_html .= '</nav>';
            $mega_css = "
/* WPilot Mega Menu */
.wpilot-mega-item:hover .wpilot-mega-dropdown { display: block !important; animation: wpilot-fadeInUp 0.2s ease; }
.wpilot-mega-dropdown a:hover { background: {$accent}15 !important; color: {$accent} !important; }
";
            update_option('wpilot_mega_menu', $menu_html);
            $existing = wp_get_custom_css();
            if (strpos($existing, 'WPilot Mega Menu') === false) wp_update_custom_css_post($existing . "\n" . $mega_css);
            return wpilot_ok("Mega menu built with " . count($items) . " top-level items.", ['items' => count($items)]);

        
/* ── Email Pro Tools ────────────────────────────────── */
case 'send_email':
    $to       = sanitize_email($params['to'] ?? '');
    $subject  = sanitize_text_field($params['subject'] ?? '');
    $body     = wp_kses_post($params['body'] ?? '');
    $from     = sanitize_text_field($params['from_name'] ?? get_bloginfo('name'));
    if (!$to || !$subject) return wpilot_err('to and subject are required.');
    $headers = ['Content-Type: text/html; charset=UTF-8', "From: {$from} <" . get_option('admin_email') . ">"];
    $sent = wp_mail($to, $subject, $body, $headers);
    // Log it
    $log = get_option('wpilot_email_log', []);
    $log[] = ['to'=>$to,'subject'=>$subject,'date'=>date('Y-m-d H:i:s'),'status'=>$sent?'sent':'failed'];
    if (count($log) > 100) $log = array_slice($log, -100);
    update_option('wpilot_email_log', $log);
    return $sent ? wpilot_ok("Email sent to {$to}.") : wpilot_err("Failed to send email to {$to}.");

case 'send_test_email':
    $to = sanitize_email($params['to'] ?? get_option('admin_email'));
    $site = get_bloginfo('name');
    $body = "<h2>WPilot Test Email</h2><p>This is a test email from <strong>{$site}</strong>.</p><p>If you received this, your email system is working correctly.</p><p>Sent at: " . date('Y-m-d H:i:s') . "</p>";
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $sent = wp_mail($to, "[{$site}] Test Email from WPilot", $body, $headers);
    return $sent ? wpilot_ok("Test email sent to {$to}.") : wpilot_ok("Email queued for {$to}. If not received, configure SMTP (smtp_configure).", ["sent" => false, "note" => "wp_mail returned false — SMTP may not be configured"]);

case 'create_email_template':
    $name    = sanitize_text_field($params['name'] ?? '');
    $subject = sanitize_text_field($params['subject'] ?? '');
    $body    = wp_kses_post($params['body'] ?? '');
    $style   = sanitize_text_field($params['style'] ?? 'modern');
    if (!$name) return wpilot_err('Template name is required.');
    $styles = [
        'modern'    => 'font-family:Inter,sans-serif;max-width:600px;margin:0 auto;padding:40px;background:#fff;border-radius:12px;box-shadow:0 2px 20px rgba(0,0,0,.08);',
        'minimal'   => 'font-family:Georgia,serif;max-width:560px;margin:0 auto;padding:30px;background:#fafafa;border-top:3px solid #333;',
        'corporate' => 'font-family:Arial,sans-serif;max-width:640px;margin:0 auto;padding:0;background:#fff;border:1px solid #ddd;',
    ];
    $css = $styles[$style] ?? $styles['modern'];
    $wrapped = "<div style=\"{$css}\">{$body}</div>";
    $templates = get_option('wpilot_email_templates', []);
    $templates[$name] = ['subject'=>$subject,'body'=>$wrapped,'style'=>$style,'created'=>date('Y-m-d H:i:s')];
    update_option('wpilot_email_templates', $templates);
    return wpilot_ok("Email template \"{$name}\" saved ({$style} style).");

case 'list_email_templates':
    $templates = get_option('wpilot_email_templates', []);
    if (empty($templates)) return wpilot_ok("No email templates found.", ['templates'=>[]]);
    $list = [];
    foreach ($templates as $name => $t) {
        $list[] = ['name'=>$name,'subject'=>$t['subject'],'style'=>$t['style'] ?? 'modern','created'=>$t['created'] ?? 'unknown'];
    }
    return wpilot_ok(count($list) . " email template(s) found.", ['templates'=>$list]);

case 'send_bulk_email':
    $tpl_name = sanitize_text_field($params['template_name'] ?? '');
    $role     = sanitize_text_field($params['role'] ?? 'subscriber');
    $limit    = intval($params['limit'] ?? 50);
    $templates = get_option('wpilot_email_templates', []);
    if (!isset($templates[$tpl_name])) return wpilot_err("Template \"{$tpl_name}\" not found.");
    $tpl = $templates[$tpl_name];
    $users = get_users(['role'=>$role,'number'=>$limit,'fields'=>['user_email']]);
    if (empty($users)) return wpilot_err("No users found with role \"{$role}\".");
    $sent = 0; $failed = 0;
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    foreach ($users as $u) {
        if (wp_mail($u->user_email, $tpl['subject'], $tpl['body'], $headers)) { $sent++; } else { $failed++; }
    }
    // Built by Weblease
    $log = get_option('wpilot_email_log', []);
    $log[] = ['to'=>"bulk:{$role}",'subject'=>$tpl['subject'],'date'=>date('Y-m-d H:i:s'),'status'=>"sent:{$sent},failed:{$failed}"];
    if (count($log) > 100) $log = array_slice($log, -100);
    update_option('wpilot_email_log', $log);
    return wpilot_ok("Bulk email sent: {$sent} delivered, {$failed} failed.", ['sent'=>$sent,'failed'=>$failed,'role'=>$role]);

case 'email_log':
    $log = get_option('wpilot_email_log', []);
    if (empty($log)) return wpilot_ok("No emails logged yet.", ['log'=>[]]);
    $recent = array_slice($log, -20);
    return wpilot_ok(count($recent) . " recent email(s).", ['log'=>array_reverse($recent)]);

/* ── Booking System Tools ───────────────────────────── */
case 'create_booking_page':
    $title    = sanitize_text_field($params['title'] ?? 'Book an Appointment');
    $services = $params['services'] ?? [];
    $slots    = $params['time_slots'] ?? ['09:00','10:00','11:00','13:00','14:00','15:00','16:00'];
    $days     = $params['days'] ?? ['Monday','Tuesday','Wednesday','Thursday','Friday'];
    $settings = get_option('wpilot_booking_settings', ['timezone'=>'UTC','slot_duration'=>60,'max_per_slot'=>1,'notification_email'=>get_option('admin_email')]);
    // Save config
    update_option('wpilot_booking_services', $services);
    update_option('wpilot_booking_slots', $slots);
    update_option('wpilot_booking_days', $days);
    // Build service options HTML
    $svc_html = '';
    foreach ($services as $s) {
        $sname = esc_attr($s['name'] ?? '');
        $sprice = esc_attr($s['price'] ?? '0');
        $sdur = esc_attr($s['duration'] ?? '60');
        $svc_html .= "<option value=\"{$sname}\" data-price=\"{$sprice}\" data-duration=\"{$sdur}\">{$sname} - \${$sprice} ({$sdur} min)</option>";
    }
    // Build time slot options
    $slot_html = '';
    foreach ($slots as $sl) { $slot_html .= "<option value=\"" . esc_attr($sl) . "\">{$sl}</option>"; }
    // Build day checkboxes for display
    $day_list = implode(', ', $days);
    $form = '<div class="wpilot-booking-form" style="max-width:600px;margin:0 auto;font-family:Inter,sans-serif;">'
        . '<h2 style="text-align:center;margin-bottom:20px;">' . esc_html($title) . '</h2>'
        . '<p style="text-align:center;color:#666;">Available: ' . esc_html($day_list) . '</p>'
        . '<form id="wpilot-booking" method="post" style="display:flex;flex-direction:column;gap:15px;">'
        . '<input type="hidden" name="wpilot_booking_submit" value="1">'
        . '<label>Your Name<input type="text" name="booking_name" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;"></label>'
        . '<label>Email<input type="email" name="booking_email" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;"></label>'
        . '<label>Phone<input type="tel" name="booking_phone" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;"></label>'
        . '<label>Service<select name="booking_service" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;">' . $svc_html . '</select></label>'
        . '<label>Date<input type="date" name="booking_date" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;"></label>'
        . '<label>Time<select name="booking_time" required style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;">' . $slot_html . '</select></label>'
        . '<label>Notes<textarea name="booking_notes" rows="3" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;"></textarea></label>'
        . '<button type="submit" style="padding:14px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:16px;cursor:pointer;">Book Now</button>'
        . '</form></div>';
    // Create the page
    $page_id = wp_insert_post(['post_title'=>$title,'post_content'=>$form,'post_status'=>'publish','post_type'=>'page']);
    if (is_wp_error($page_id)) return wpilot_err($page_id->get_error_message());
    // Register a simple handler via option
    update_option('wpilot_booking_page_id', $page_id);
    // Add booking handler if not already
    if (!get_option('wpilot_booking_handler_active')) {
        update_option('wpilot_booking_handler_active', true);
    }
    return wpilot_ok("Booking page \"{$title}\" created (ID: {$page_id}) with " . count($services) . " services and " . count($slots) . " time slots.", ['page_id'=>$page_id,'services'=>count($services)]);

case 'list_bookings':
    $bookings = get_option('wpilot_bookings', []);
    $status   = sanitize_text_field($params['status'] ?? '');
    $from     = sanitize_text_field($params['date_from'] ?? '');
    $to       = sanitize_text_field($params['date_to'] ?? '');
    if ($status) $bookings = array_filter($bookings, function($b) use ($status) { return ($b['status'] ?? 'pending') === $status; });
    if ($from) $bookings = array_filter($bookings, function($b) use ($from) { return ($b['date'] ?? '') >= $from; });
    if ($to) $bookings = array_filter($bookings, function($b) use ($to) { return ($b['date'] ?? '') <= $to; });
    $bookings = array_values($bookings);
    return wpilot_ok(count($bookings) . " booking(s) found.", ['bookings'=>$bookings]);

case 'confirm_booking':
    $bid = intval($params['booking_id'] ?? 0);
    $bookings = get_option('wpilot_bookings', []);
    if (!isset($bookings[$bid])) return wpilot_err("Booking #{$bid} not found.");
    $bookings[$bid]['status'] = 'confirmed';
    update_option('wpilot_bookings', $bookings);
    $email = $bookings[$bid]['email'] ?? '';
    if ($email) {
        $site = get_bloginfo('name');
        $date = $bookings[$bid]['date'] ?? '';
        $time = $bookings[$bid]['time'] ?? '';
        $svc  = $bookings[$bid]['service'] ?? '';
        wp_mail($email, "Booking Confirmed - {$site}", "<h2>Your booking is confirmed!</h2><p><strong>Service:</strong> {$svc}<br><strong>Date:</strong> {$date}<br><strong>Time:</strong> {$time}</p><p>Thank you for choosing {$site}!</p>", ['Content-Type: text/html; charset=UTF-8']);
    }
    return wpilot_ok("Booking #{$bid} confirmed. Confirmation email sent to {$email}.");

case 'cancel_booking':
    $bid = intval($params['booking_id'] ?? 0);
    $bookings = get_option('wpilot_bookings', []);
    if (!isset($bookings[$bid])) return wpilot_err("Booking #{$bid} not found.");
    $bookings[$bid]['status'] = 'cancelled';
    update_option('wpilot_bookings', $bookings);
    return wpilot_ok("Booking #{$bid} cancelled.");

case 'booking_settings':
    $settings = get_option('wpilot_booking_settings', ['timezone'=>'UTC','slot_duration'=>60,'max_per_slot'=>1,'notification_email'=>get_option('admin_email')]);
    if (isset($params['timezone']))          $settings['timezone']          = sanitize_text_field($params['timezone']);
    if (isset($params['slot_duration']))     $settings['slot_duration']     = intval($params['slot_duration']);
    if (isset($params['max_per_slot']))      $settings['max_per_slot']      = intval($params['max_per_slot']);
    if (isset($params['notification_email'])) $settings['notification_email'] = sanitize_email($params['notification_email']);
    update_option('wpilot_booking_settings', $settings);
    return wpilot_ok("Booking settings updated.", ['settings'=>$settings]);

/* ── WooCommerce Extra Tools ────────────────────────── */
case 'woo_create_category_with_image':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    $name  = sanitize_text_field($params['name'] ?? '');
    $desc  = sanitize_text_field($params['description'] ?? '');
    $img   = esc_url_raw($params['image_url'] ?? '');
    $parent = intval($params['parent'] ?? 0);
    if (!$name) return wpilot_err('Category name required.');
    $term = wp_insert_term($name, 'product_cat', ['description'=>$desc,'parent'=>$parent]);
    if (is_wp_error($term)) return wpilot_err($term->get_error_message());
    $term_id = $term['term_id'];
    if ($img) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $att_id = media_sideload_image($img, 0, $name, 'id');
        if (!is_wp_error($att_id)) update_term_meta($term_id, 'thumbnail_id', $att_id);
    }
    return wpilot_ok("Category \"{$name}\" created (ID: {$term_id}).", ['term_id'=>$term_id]);

case 'woo_bulk_update_prices':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    $updates = $params['updates'] ?? [];
    $pct     = floatval($params['percentage'] ?? 0);
    $count   = 0;
    if (!empty($updates)) {
        foreach ($updates as $u) {
            $pid = intval($u['product_id'] ?? 0);
            $price = sanitize_text_field($u['price'] ?? '');
            if ($pid && $price !== '') {
                update_post_meta($pid, '_regular_price', $price);
                update_post_meta($pid, '_price', $price);
                $count++;
            }
        }
        return wpilot_ok("{$count} product prices updated.", ['updated'=>$count]);
    } elseif ($pct != 0) {
        $products = wc_get_products(['limit'=>-1,'return'=>'ids']);
        foreach ($products as $pid) {
            $old = floatval(get_post_meta($pid, '_regular_price', true));
            if ($old > 0) {
                $new = round($old * (1 + $pct / 100), 2);
                update_post_meta($pid, '_regular_price', $new);
                $sale = get_post_meta($pid, '_sale_price', true);
                if (!$sale) update_post_meta($pid, '_price', $new);
                $count++;
            }
        }
        $dir = $pct > 0 ? 'increased' : 'decreased';
        return wpilot_ok("{$count} products {$dir} by {$pct}%.", ['updated'=>$count,'percentage'=>$pct]);
    }
    if (empty($updates) && $pct == 0) return wpilot_ok("No price changes needed.", ["updated" => 0]);
    return wpilot_err("Provide updates array or percentage.");

case 'woo_create_simple_product':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    $name  = sanitize_text_field($params['name'] ?? '');
    $price = sanitize_text_field($params['price'] ?? '');
    $desc  = wp_kses_post($params['description'] ?? '');
    $img   = esc_url_raw($params['image_url'] ?? '');
    $cat   = sanitize_text_field($params['category'] ?? '');
    $sku   = sanitize_text_field($params['sku'] ?? '');
    $stock = isset($params['stock']) ? intval($params['stock']) : null;
    if (!$name || $price === '') return wpilot_err('name and price are required.');
    $pid = wp_insert_post(['post_title'=>$name,'post_content'=>$desc,'post_status'=>'publish','post_type'=>'product']);
    if (is_wp_error($pid)) return wpilot_err($pid->get_error_message());
    update_post_meta($pid, '_regular_price', $price);
    update_post_meta($pid, '_price', $price);
    wp_set_object_terms($pid, 'simple', 'product_type');
    if ($sku) update_post_meta($pid, '_sku', $sku);
    if ($stock !== null) {
        update_post_meta($pid, '_manage_stock', 'yes');
        update_post_meta($pid, '_stock', $stock);
        update_post_meta($pid, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');
    }
    if ($cat) {
        $term = term_exists($cat, 'product_cat');
        if (!$term) $term = wp_insert_term($cat, 'product_cat');
        if (!is_wp_error($term)) wp_set_object_terms($pid, intval($term['term_id']), 'product_cat');
    }
    if ($img) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $att_id = media_sideload_image($img, $pid, $name, 'id');
        if (!is_wp_error($att_id)) set_post_thumbnail($pid, $att_id);
    }
    return wpilot_ok("Product \"{$name}\" created (ID: {$pid}, price: \${$price}).", ['product_id'=>$pid]);

case 'woo_shipping_calculator':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    $zones = WC_Shipping_Zones::get_zones();
    $result = [];
    foreach ($zones as $z) {
        $methods = [];
        foreach ($z['shipping_methods'] as $m) {
            $methods[] = ['id'=>$m->id,'title'=>$m->title,'enabled'=>$m->enabled,'cost'=>$m->get_option('cost','N/A')];
        }
        $result[] = ['zone'=>$z['zone_name'],'regions'=>wp_list_pluck($z['zone_locations'],'code'),'methods'=>$methods];
    }
    // Rest of World
    $rw = WC_Shipping_Zones::get_zone(0);
    $rw_methods = [];
    foreach ($rw->get_shipping_methods() as $m) {
        $rw_methods[] = ['id'=>$m->id,'title'=>$m->title,'enabled'=>$m->enabled];
    }
    if (!empty($rw_methods)) $result[] = ['zone'=>'Rest of World','regions'=>['*'],'methods'=>$rw_methods];
    return wpilot_ok(count($result) . " shipping zone(s).", ['zones'=>$result]);

case 'woo_payment_methods':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    $gateways = WC()->payment_gateways()->payment_gateways();
    $list = [];
    foreach ($gateways as $gw) {
        $list[] = ['id'=>$gw->id,'title'=>$gw->get_title(),'enabled'=>$gw->enabled==='yes','description'=>$gw->get_description()];
    }
    return wpilot_ok(count($list) . " payment method(s).", ['methods'=>$list]);

case 'woo_tax_summary':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    global $wpdb;
    $rates = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order", ARRAY_A);
    $tax_enabled = get_option('woocommerce_calc_taxes') === 'yes';
    return wpilot_ok(($tax_enabled ? "Taxes ENABLED" : "Taxes DISABLED") . ". " . count($rates) . " rate(s).", ['enabled'=>$tax_enabled,'rates'=>$rates]);

case 'woo_order_stats':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    $statuses = ['pending','processing','on-hold','completed','cancelled','refunded','failed'];
    $stats = [];
    foreach ($statuses as $s) {
        $count = count(wc_get_orders(['status'=>$s,'limit'=>-1,'return'=>'ids']));
        $stats[$s] = $count;
    }
    $total = array_sum($stats);
    return wpilot_ok("Total {$total} orders.", ['stats'=>$stats,'total'=>$total]);

case 'woo_product_search':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    $q = sanitize_text_field($params['query'] ?? '');
    if (!$q) return wpilot_err('Search query required.');
    $products = wc_get_products(['s'=>$q,'limit'=>20]);
    // Also search by SKU
    $by_sku = wc_get_products(['sku'=>$q,'limit'=>10]);
    $all = array_merge($products, $by_sku);
    $seen = []; $results = [];
    foreach ($all as $p) {
        if (isset($seen[$p->get_id()])) continue;
        $seen[$p->get_id()] = true;
        $results[] = ['id'=>$p->get_id(),'name'=>$p->get_name(),'price'=>$p->get_price(),'sku'=>$p->get_sku(),'status'=>$p->get_status(),'stock'=>$p->get_stock_quantity()];
    }
    return wpilot_ok(count($results) . " product(s) found for \"{$q}\".", ['products'=>$results]);

case 'woo_duplicate_product':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    $pid = intval($params['product_id'] ?? 0);
    if (!$pid) return wpilot_err('product_id required.');
    $product = wc_get_product($pid);
    if (!$product) return wpilot_err("Product #{$pid} not found.");
    $new_name = sanitize_text_field($params['new_name'] ?? $product->get_name() . ' (Copy)');
    $dup = clone $product;
    $dup->set_id(0);
    $dup->set_name($new_name);
    $dup->set_slug('');
    $dup->set_date_created(null);
    $new_id = $dup->save();
    // Copy meta
    $meta = get_post_meta($pid);
    foreach ($meta as $key => $vals) {
        if ($key === '_sku') { update_post_meta($new_id, '_sku', $vals[0] . '-copy'); continue; }
        foreach ($vals as $v) { add_post_meta($new_id, $key, maybe_unserialize($v)); }
    }
    return wpilot_ok("Product duplicated as \"{$new_name}\" (ID: {$new_id}).", ['original_id'=>$pid,'new_id'=>$new_id]);

case 'woo_set_product_gallery':
    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');
    $pid  = intval($params['product_id'] ?? 0);
    $urls = $params['image_urls'] ?? [];
    if (!$pid) return wpilot_err('product_id required.');
    if (empty($urls)) return wpilot_err('image_urls array required.');
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $ids = [];
    foreach ($urls as $url) {
        $att = media_sideload_image(esc_url_raw($url), $pid, '', 'id');
        if (!is_wp_error($att)) $ids[] = $att;
    }
    if (!empty($ids)) {
        $product = wc_get_product($pid);
        if ($product) { $product->set_gallery_image_ids($ids); $product->save(); }
    }
    return wpilot_ok(count($ids) . " gallery image(s) added to product #{$pid}.", ['attachment_ids'=>$ids]);

/* ── SEO Pro Tools ──────────────────────────────────── */
case 'seo_check_page':
    $pid = intval($params['page_id'] ?? $params['id'] ?? 0);
    $url = esc_url_raw($params['url'] ?? '');
    if (!$pid && $url) {
        $pid = url_to_postid($url);
    }
    if (!$pid) return wpilot_err('page_id or url required.');
    $post = get_post($pid);
    if (!$post) return wpilot_err("Page #{$pid} not found.");
    $content = $post->post_content;
    $issues = []; $score = 100;
    // Title length
    $title_len = strlen($post->post_title);
    if ($title_len < 30) { $issues[] = "Title too short ({$title_len} chars, aim for 50-60)"; $score -= 10; }
    if ($title_len > 60) { $issues[] = "Title too long ({$title_len} chars, aim for 50-60)"; $score -= 5; }
    // Meta description
    $meta_desc = get_post_meta($pid, '_yoast_wpseo_metadesc', true) ?: get_post_meta($pid, '_rank_math_description', true) ?: '';
    if (!$meta_desc) { $issues[] = "Missing meta description"; $score -= 15; }
    elseif (strlen($meta_desc) < 120) { $issues[] = "Meta description too short (" . strlen($meta_desc) . " chars)"; $score -= 5; }
    // Headings
    preg_match_all('/<h1[^>]*>/i', $content, $h1s);
    if (count($h1s[0]) > 1) { $issues[] = "Multiple H1 tags (" . count($h1s[0]) . " found)"; $score -= 10; }
    if (count($h1s[0]) === 0) { $issues[] = "No H1 tag found"; $score -= 10; }
    // Images alt text
    preg_match_all('/<img[^>]*>/i', $content, $imgs);
    $missing_alt = 0;
    foreach ($imgs[0] as $img_tag) { if (!preg_match('/alt=["\'][^"\']+["\']/', $img_tag)) $missing_alt++; }
    if ($missing_alt > 0) { $issues[] = "{$missing_alt} image(s) missing alt text"; $score -= $missing_alt * 3; }
    // Internal links
    preg_match_all('/href=["\']([^"\']+)["\']/', $content, $links);
    $internal = 0;
    $site_url = get_site_url();
    foreach ($links[1] as $l) { if (strpos($l, $site_url) !== false || strpos($l, '/') === 0) $internal++; }
    if ($internal === 0) { $issues[] = "No internal links found"; $score -= 10; }
    // Word count
    $words = str_word_count(strip_tags($content));
    if ($words < 300) { $issues[] = "Thin content ({$words} words, aim for 300+)"; $score -= 10; }
    $score = max(0, $score);
    $grade = $score >= 80 ? 'Good' : ($score >= 50 ? 'Needs Improvement' : 'Poor');
    return wpilot_ok("SEO Score: {$score}/100 ({$grade}). " . count($issues) . " issue(s).", ['score'=>$score,'grade'=>$grade,'issues'=>$issues,'title_length'=>$title_len,'word_count'=>$words,'internal_links'=>$internal]);

case 'seo_generate_meta':
    $pid = intval($params['page_id'] ?? $params['id'] ?? 0);
    if (!$pid) return wpilot_err('page_id required.');
    $post = get_post($pid);
    if (!$post) return wpilot_err("Page #{$pid} not found.");
    $title = $post->post_title;
    $content = strip_tags($post->post_content);
    $words = explode(' ', $content);
    $excerpt = implode(' ', array_slice($words, 0, 30));
    // Generate optimized meta
    $site = get_bloginfo('name');
    $meta_title = strlen($title) > 55 ? substr($title, 0, 52) . '...' : $title . " | {$site}";
    if (strlen($meta_title) > 60) $meta_title = substr($meta_title, 0, 57) . '...';
    $meta_desc = strlen($excerpt) > 10 ? rtrim(substr($excerpt, 0, 155), ' .') . '.' : "Discover {$title} at {$site}. Learn more about our offerings.";
    // Save to Yoast or RankMath if available
    if (defined('WPSEO_VERSION')) {
        update_post_meta($pid, '_yoast_wpseo_title', $meta_title);
        update_post_meta($pid, '_yoast_wpseo_metadesc', $meta_desc);
    }
    if (class_exists('RankMath')) {
        update_post_meta($pid, 'rank_math_title', $meta_title);
        update_post_meta($pid, 'rank_math_description', $meta_desc);
    }
    return wpilot_ok("Meta generated for \"{$title}\".", ['meta_title'=>$meta_title,'meta_title_length'=>strlen($meta_title),'meta_description'=>$meta_desc,'meta_desc_length'=>strlen($meta_desc)]);

case 'seo_internal_links':
    $pages = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','numberposts'=>200]);
    $site_url = get_site_url();
    $linked_to = [];
    foreach ($pages as $p) {
        preg_match_all('/href=["\']([^"\']+)["\']/', $p->post_content, $m);
        foreach ($m[1] as $link) {
            $link = strtok($link, '#');
            $link = strtok($link, '?');
            $link = rtrim($link, '/');
            if (strpos($link, $site_url) !== false) {
                $linked_to[$link] = true;
            }
        }
    }
    $orphans = [];
    foreach ($pages as $p) {
        $purl = rtrim(get_permalink($p->ID), '/');
        if (!isset($linked_to[$purl]) && $p->ID != get_option('page_on_front')) {
            $orphans[] = ['id'=>$p->ID,'title'=>$p->post_title,'url'=>$purl,'type'=>$p->post_type];
        }
    }
    return wpilot_ok(count($orphans) . " orphan page(s) with no internal links pointing to them.", ['orphans'=>$orphans,'total_pages'=>count($pages)]);

case 'seo_keyword_check':
    $keyword = sanitize_text_field($params['keyword'] ?? '');
    if (!$keyword) return wpilot_err('keyword parameter required.');
    $pages = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','numberposts'=>200]);
    $results = [];
    $kw_lower = strtolower($keyword);
    foreach ($pages as $p) {
        $text = strtolower(strip_tags($p->post_content . ' ' . $p->post_title));
        $count = substr_count($text, $kw_lower);
        if ($count > 0) {
            $words = str_word_count($text);
            $density = $words > 0 ? round(($count / $words) * 100, 2) : 0;
            $in_title = strpos(strtolower($p->post_title), $kw_lower) !== false;
            $results[] = ['id'=>$p->ID,'title'=>$p->post_title,'mentions'=>$count,'density'=>$density.'%','in_title'=>$in_title,'type'=>$p->post_type];
        }
    }
    usort($results, function($a,$b){ return $b['mentions'] - $a['mentions']; });
    return wpilot_ok("Keyword \"{$keyword}\" found on " . count($results) . " page(s).", ['keyword'=>$keyword,'pages'=>$results]);

case 'seo_broken_links':
    $pages = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','numberposts'=>100]);
    $broken = [];
    $checked = 0;
    $site_url = get_site_url();
    foreach ($pages as $p) {
        preg_match_all('/href=["\']([^"\'#][^"\']*)["\']/', $p->post_content, $m);
        foreach ($m[1] as $link) {
            if (strpos($link, 'mailto:') === 0 || strpos($link, 'tel:') === 0 || strpos($link, 'javascript:') === 0) continue;
            $checked++;
            if ($checked > 200) break 2; // Limit checks
            // Internal link check
            if (strpos($link, $site_url) !== false || strpos($link, '/') === 0) {
                $full = strpos($link, '/') === 0 ? $site_url . $link : $link;
                $post_id = url_to_postid($full);
                if (!$post_id && !file_exists(ABSPATH . ltrim(parse_url($full, PHP_URL_PATH), '/'))) {
                    $broken[] = ['url'=>$link,'page_id'=>$p->ID,'page_title'=>$p->post_title,'type'=>'internal'];
                }
            } else {
                // External - quick check
                $response = wp_remote_head($link, ['timeout'=>5,'redirection'=>3,'sslverify'=>false]);
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 400) {
                    $code = is_wp_error($response) ? 'error' : wp_remote_retrieve_response_code($response);
                    $broken[] = ['url'=>$link,'page_id'=>$p->ID,'page_title'=>$p->post_title,'type'=>'external','status'=>$code];
                }
            }
        }
    }
    return wpilot_ok(count($broken) . " broken link(s) found ({$checked} checked).", ['broken'=>$broken,'total_checked'=>$checked]);

case 'seo_redirect_check':
    $pages = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','numberposts'=>100]);
    $redirects = [];
    $four04s   = [];
    $checked   = 0;
    foreach ($pages as $p) {
        preg_match_all('/href=["\']([^"\'#][^"\']*)["\']/', $p->post_content, $m);
        foreach ($m[1] as $link) {
            if (strpos($link, 'mailto:') === 0 || strpos($link, 'tel:') === 0) continue;
            if (strpos($link, 'http') !== 0) continue;
            $checked++;
            if ($checked > 150) break 2;
            $response = wp_remote_get($link, ['timeout'=>5,'redirection'=>0,'sslverify'=>false]);
            if (is_wp_error($response)) continue;
            $code = wp_remote_retrieve_response_code($response);
            if ($code >= 300 && $code < 400) {
                $location = wp_remote_retrieve_header($response, 'location');
                $redirects[] = ['url'=>$link,'redirects_to'=>$location,'status'=>$code,'page_id'=>$p->ID,'page_title'=>$p->post_title];
            } elseif ($code === 404) {
                $four04s[] = ['url'=>$link,'page_id'=>$p->ID,'page_title'=>$p->post_title];
            }
        }
    }
    return wpilot_ok(count($redirects) . " redirect(s), " . count($four04s) . " 404(s) found ({$checked} links checked).", ['redirects'=>$redirects,'not_found'=>$four04s,'total_checked'=>$checked]);

        
        /* ── Button Builder ─────────────────────────────────── */
        case 'create_button':
            $page_id = intval($params['page_id'] ?? 0);
            $text    = sanitize_text_field($params['text'] ?? 'Click Me');
            $url     = esc_url($params['url'] ?? '#');
            $style   = sanitize_text_field($params['style'] ?? 'gradient');
            $color   = sanitize_hex_color($params['color'] ?? '#6366f1');
            $size    = sanitize_text_field($params['size'] ?? 'md');
            $icon    = sanitize_text_field($params['icon'] ?? '');
            if (!$page_id) return wpilot_err('page_id required.');
            $sizes = ['sm' => 'padding:8px 18px;font-size:0.85rem', 'md' => 'padding:12px 28px;font-size:1rem', 'lg' => 'padding:16px 40px;font-size:1.15rem'];
            $sz = $sizes[$size] ?? $sizes['md'];
            $base = "display:inline-block;{$sz};border-radius:8px;text-decoration:none;font-weight:600;cursor:pointer;transition:all 0.3s ease;text-align:center;";
            $styles_map = [
                'gradient'  => "background:linear-gradient(135deg,{$color},{$color}cc);color:#fff;border:none;box-shadow:0 4px 15px {$color}40;",
                'outline'   => "background:transparent;color:{$color};border:2px solid {$color};",
                'glass'     => "background:{$color}20;color:{$color};border:1px solid {$color}40;backdrop-filter:blur(10px);",
                'neon'      => "background:transparent;color:{$color};border:2px solid {$color};box-shadow:0 0 10px {$color}60,inset 0 0 10px {$color}20;text-shadow:0 0 8px {$color}80;",
                'pill'      => "background:{$color};color:#fff;border:none;border-radius:50px;",
                '3d'        => "background:{$color};color:#fff;border:none;border-bottom:4px solid {$color}90;border-radius:8px;",
            ];
            $s = $styles_map[$style] ?? $styles_map['gradient'];
            $icon_html = $icon ? "{$icon} " : '';
            $btn_html = '<div style="margin:20px 0;text-align:center"><a href="' . $url . '" style="' . $base . $s . '">' . $icon_html . esc_html($text) . '</a></div>';
            wpilot_save_post_snapshot($page_id);
            $post = get_post($page_id);
            if (!$post) return wpilot_err("Page #{$page_id} not found.");
            wp_update_post(['ID' => $page_id, 'post_content' => $post->post_content . "\n" . $btn_html]);
            return wpilot_ok("Button '{$text}' ({$style} style, {$size}) added to page #{$page_id}.", ['html' => $btn_html]);

        /* ── CSS Power Tools ────────────────────────────────── */
        case 'add_css_variable':
            $variables = $params['variables'] ?? [];
            if (empty($variables) || !is_array($variables)) return wpilot_err('variables array required (name=>value pairs).');
            $css_vars = "\n/* wpilot-css-variables */\n:root {\n";
            foreach ($variables as $name => $value) {
                $name = sanitize_text_field($name);
                $value = sanitize_text_field($value);
                if (strpos($name, '--') !== 0) $name = '--' . $name;
                $css_vars .= "  {$name}: {$value};\n";
            }
            $css_vars .= "}\n";
            $existing = wp_get_custom_css();
            wp_update_custom_css_post($existing . $css_vars);
            return wpilot_ok("Added " . count($variables) . " CSS variable(s) to :root.", ['variables' => array_keys($variables)]);

        case 'add_css_class':
            $class_name = sanitize_text_field($params['class_name'] ?? '');
            $properties = $params['properties'] ?? '';
            if (!$class_name || !$properties) return wpilot_err('class_name and properties required.');
            if (strpos($class_name, '.') !== 0) $class_name = '.' . $class_name;
            $css_block = "\n/* wpilot-class-{$class_name} */\n{$class_name} {\n  {$properties}\n}\n";
            $existing = wp_get_custom_css();
            wp_update_custom_css_post($existing . $css_block);
            return wpilot_ok("CSS class {$class_name} created.", ['class' => $class_name]);

        case 'remove_css':
            $tag = sanitize_text_field($params['tag'] ?? '');
            if (!$tag) return wpilot_err('tag required.');
            $existing = wp_get_custom_css();
            $pattern = '/\/\*\s*' . preg_quote($tag, '/') . '\s*\*\/.*?(?=\/\*|\z)/s';
            $updated = preg_replace($pattern, '', $existing);
            if ($updated === $existing) return wpilot_err("Tag '{$tag}' not found in CSS.");
            wp_update_custom_css_post(trim($updated));
            return wpilot_ok("CSS block tagged '{$tag}' removed.");

        case 'css_reset':
            $existing = wp_get_custom_css();
            $cleaned = preg_replace('/\/\*\s*wpilot[^*]*\*\/.*?(?=\/\*|\z)/si', '', $existing);
            $cleaned = preg_replace('/\/\*\s*WPilot[^*]*\*\/.*?(?=\/\*|\z)/s', '', $cleaned);
            wp_update_custom_css_post(trim($cleaned));
            return wpilot_ok("All WPilot-added CSS removed. Non-WPilot CSS preserved.");

        case 'get_css':
            $css = wp_get_custom_css();
            if (empty(trim($css))) return wpilot_ok("No custom CSS found.", ['css' => '']);
            return wpilot_ok("Current custom CSS (" . strlen($css) . " bytes):", ['css' => $css]);

        /* ── HTML Tools ─────────────────────────────────────── */
        case 'inject_html':
            $page_id  = intval($params['page_id'] ?? 0);
            $html     = wp_kses_post($params['html'] ?? '');
            $position = sanitize_text_field($params['position'] ?? 'bottom');
            if (!$page_id || !$html) return wpilot_err('page_id and html required.');
            $post = get_post($page_id);
            if (!$post) return wpilot_err("Page #{$page_id} not found.");
            wpilot_save_post_snapshot($page_id);
            $content = $post->post_content;
            switch ($position) {
                case 'top':            $content = $html . "\n" . $content; break;
                case 'before_content': $content = $html . "\n" . $content; break;
                case 'after_content':  $content = $content . "\n" . $html; break;
                default:               $content = $content . "\n" . $html; break;
            }
            wp_update_post(['ID' => $page_id, 'post_content' => $content]);
            return wpilot_ok("HTML injected at '{$position}' on page #{$page_id}.", ['position' => $position]);

        case 'create_section':
            $page_id   = intval($params['page_id'] ?? 0);
            $title     = sanitize_text_field($params['title'] ?? '');
            $content   = wp_kses_post($params['content'] ?? '');
            $bg_color  = sanitize_text_field($params['bg_color'] ?? '#f8f9fa');
            $text_color = sanitize_text_field($params['text_color'] ?? '#1a1a1a');
            $padding   = sanitize_text_field($params['padding'] ?? '60px 20px');
            $style     = sanitize_text_field($params['style'] ?? 'full-width');
            if (!$page_id) return wpilot_err('page_id required.');
            $post = get_post($page_id);
            if (!$post) return wpilot_err("Page #{$page_id} not found.");
            wpilot_save_post_snapshot($page_id);
            $styles_map = [
                'card'       => "background:{$bg_color};color:{$text_color};padding:{$padding};border-radius:16px;max-width:800px;margin:40px auto;box-shadow:0 4px 20px rgba(0,0,0,0.08);",
                'full-width' => "background:{$bg_color};color:{$text_color};padding:{$padding};width:100vw;position:relative;left:50%;right:50%;margin-left:-50vw;margin-right:-50vw;",
                'centered'   => "background:{$bg_color};color:{$text_color};padding:{$padding};max-width:900px;margin:40px auto;text-align:center;",
                'split'      => "background:{$bg_color};color:{$text_color};padding:{$padding};display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center;",
            ];
            $s = $styles_map[$style] ?? $styles_map['full-width'];
            $section_html = '<div style="' . $s . '">';
            if ($title) $section_html .= '<h2 style="margin-bottom:20px;font-size:2rem;font-weight:700">' . esc_html($title) . '</h2>';
            $section_html .= '<div>' . $content . '</div></div>';
            wp_update_post(['ID' => $page_id, 'post_content' => $post->post_content . "\n" . $section_html]);
            return wpilot_ok("Section '{$title}' ({$style}) added to page #{$page_id}.", ['style' => $style]);

        case 'create_grid':
            $page_id = intval($params['page_id'] ?? 0);
            $columns = intval($params['columns'] ?? 3);
            $items   = $params['items'] ?? [];
            if (!$page_id) return wpilot_err('page_id required.');
            if (empty($items)) return wpilot_err('items array required (each: {title, content, icon}).');
            $post = get_post($page_id);
            if (!$post) return wpilot_err("Page #{$page_id} not found.");
            wpilot_save_post_snapshot($page_id);
            $grid_html = '<div style="display:grid;grid-template-columns:repeat(' . $columns . ',1fr);gap:24px;padding:40px 20px">';
            foreach ($items as $item) {
                $icon = sanitize_text_field($item['icon'] ?? '');
                $t    = sanitize_text_field($item['title'] ?? '');
                $c    = wp_kses_post($item['content'] ?? '');
                $grid_html .= '<div style="background:#fff;border-radius:12px;padding:30px;box-shadow:0 2px 12px rgba(0,0,0,0.06);transition:transform 0.3s">';
                if ($icon) $grid_html .= '<div style="font-size:2.5rem;margin-bottom:16px">' . $icon . '</div>';
                if ($t) $grid_html .= '<h3 style="font-size:1.2rem;font-weight:600;margin-bottom:12px">' . esc_html($t) . '</h3>';
                $grid_html .= '<p style="color:#666;line-height:1.6">' . $c . '</p></div>';
            }
            $grid_html .= '</div>';
            wp_update_post(['ID' => $page_id, 'post_content' => $post->post_content . "\n" . $grid_html]);
            return wpilot_ok("Grid ({$columns} columns, " . count($items) . " items) added to page #{$page_id}.", ['columns' => $columns, 'items' => count($items)]);

        case 'create_table':
            $page_id = intval($params['page_id'] ?? 0);
            $headers = $params['headers'] ?? [];
            $rows    = $params['rows'] ?? [];
            $style   = sanitize_text_field($params['style'] ?? 'striped');
            if (!$page_id) return wpilot_err('page_id required.');
            if (empty($headers)) return wpilot_err('headers array required.');
            $post = get_post($page_id);
            if (!$post) return wpilot_err("Page #{$page_id} not found.");
            wpilot_save_post_snapshot($page_id);
            $styles_map = [
                'dark'    => ['bg' => '#1a1a2e', 'text' => '#eee', 'head' => '#16213e', 'border' => '#2a2a4a', 'stripe' => '#1e1e3a'],
                'light'   => ['bg' => '#fff', 'text' => '#333', 'head' => '#f5f5f5', 'border' => '#e0e0e0', 'stripe' => '#fff'],
                'striped' => ['bg' => '#fff', 'text' => '#333', 'head' => '#6366f1', 'border' => '#e5e7eb', 'stripe' => '#f9fafb'],
            ];
            $s = $styles_map[$style] ?? $styles_map['striped'];
            $head_text = ($style === 'striped') ? '#fff' : $s['text'];
            $tbl = '<div style="overflow-x:auto;margin:20px 0"><table style="width:100%;border-collapse:collapse;background:' . $s['bg'] . ';border-radius:12px;overflow:hidden">';
            $tbl .= '<thead><tr>';
            foreach ($headers as $h) $tbl .= '<th style="padding:14px 18px;background:' . $s['head'] . ';color:' . $head_text . ';font-weight:600;text-align:left;border-bottom:2px solid ' . $s['border'] . '">' . esc_html($h) . '</th>';
            $tbl .= '</tr></thead><tbody>';
            foreach ($rows as $i => $row) {
                $row_bg = ($i % 2 === 1) ? $s['stripe'] : $s['bg'];
                $tbl .= '<tr>';
                foreach ($row as $cell) $tbl .= '<td style="padding:12px 18px;color:' . $s['text'] . ';border-bottom:1px solid ' . $s['border'] . ';background:' . $row_bg . '">' . esc_html($cell) . '</td>';
                $tbl .= '</tr>';
            }
            $tbl .= '</tbody></table></div>';
            wp_update_post(['ID' => $page_id, 'post_content' => $post->post_content . "\n" . $tbl]);
            return wpilot_ok("Table (" . count($headers) . " cols, " . count($rows) . " rows, {$style}) added to page #{$page_id}.", ['headers' => count($headers), 'rows' => count($rows)]);

        /* ── Icon Tools ─────────────────────────────────────── */
        case 'add_icon':
            $page_id  = intval($params['page_id'] ?? 0);
            $icon     = sanitize_text_field($params['icon'] ?? '');
            $size     = sanitize_text_field($params['size'] ?? '2rem');
            $color    = sanitize_text_field($params['color'] ?? 'inherit');
            $position = sanitize_text_field($params['position'] ?? 'inline');
            if (!$page_id) return wpilot_err('page_id required.');
            $post = get_post($page_id);
            if (!$post) return wpilot_err("Page #{$page_id} not found.");
            wpilot_save_post_snapshot($page_id);
            $is_fa = (strpos($icon, 'fa-') !== false);
            if ($is_fa) {
                $icon_html = '<i class="' . esc_attr($icon) . '" style="font-size:' . $size . ';color:' . $color . '"></i>';
            } else {
                $icon_html = '<span style="font-size:' . $size . ';color:' . $color . '">' . $icon . '</span>';
            }
            $align = ($position === 'center') ? 'center' : (($position === 'right') ? 'right' : 'left');
            $wrapper = '<div style="margin:10px 0;text-align:' . $align . '">' . $icon_html . '</div>';
            wp_update_post(['ID' => $page_id, 'post_content' => $post->post_content . "\n" . $wrapper]);
            return wpilot_ok("Icon added to page #{$page_id}.", ['icon' => $icon]);

        case 'icon_list':
            $packs = ['emoji' => ['smileys' => 'Various smiley emojis', 'hands' => 'Hand gesture emojis', 'arrows' => 'Arrow emojis', 'symbols' => 'Star, heart, check emojis', 'objects' => 'House, phone, computer emojis']];
            $fa_loaded = false;
            $existing_css = wp_get_custom_css();
            if (strpos($existing_css, 'font-awesome') !== false || strpos($existing_css, 'fontawesome') !== false) $fa_loaded = true;
            $head_code = get_option('wpilot_head_code', '');
            if (strpos($head_code, 'font-awesome') !== false || strpos($head_code, 'fontawesome') !== false) $fa_loaded = true;
            if ($fa_loaded) $packs['fontawesome'] = 'Loaded. Use classes like fa-solid fa-house, fa-regular fa-heart';
            return wpilot_ok("Available icon packs.", ['packs' => $packs, 'fontawesome_loaded' => $fa_loaded]);

        case 'load_icons':
            $library = sanitize_text_field($params['library'] ?? 'fontawesome');
            $cdns = [
                'fontawesome'     => '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">',
                'bootstrap-icons' => '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">',
                'material-icons'  => '<link rel="stylesheet" href="https://fonts.googleapis.com/icon?family=Material+Icons">',
            ];
            if (!isset($cdns[$library])) return wpilot_err("Unknown library: {$library}. Options: fontawesome, bootstrap-icons, material-icons.");
            $head = get_option('wpilot_head_code', '');
            if (strpos($head, $library) !== false || strpos($head, str_replace('-', '', $library)) !== false) return wpilot_ok("{$library} is already loaded.");
            update_option('wpilot_head_code', $head . "\n" . $cdns[$library]);
            return wpilot_ok("{$library} icon library loaded via CDN.", ['library' => $library]);

        /* ── Slider / Carousel ──────────────────────────────── */
        case 'create_slider':
            $page_id  = intval($params['page_id'] ?? 0);
            $images   = $params['images'] ?? [];
            $autoplay = isset($params['autoplay']) ? (bool)$params['autoplay'] : true;
            $speed    = intval($params['speed'] ?? 4000);
            $style    = sanitize_text_field($params['style'] ?? 'slide');
            if (!$page_id) return wpilot_err('page_id required.');
            if (empty($images)) return wpilot_err('images array required (array of URLs).');
            $post = get_post($page_id);
            if (!$post) return wpilot_err("Page #{$page_id} not found.");
            wpilot_save_post_snapshot($page_id);
            $uid = 'wpslider-' . wp_rand(1000, 9999);
            $count = count($images);
            $dur = ($speed / 1000) * $count;
            $slider_css = '<style>';
            $slider_css .= "#{$uid}{position:relative;width:100%;max-width:1200px;margin:30px auto;overflow:hidden;border-radius:12px}";
            $slider_css .= "#{$uid} .wpslider-track{display:flex;width:" . ($count * 100) . "%;";
            if ($autoplay) {
                $slider_css .= "animation:{$uid}-slide {$dur}s infinite;";
            }
            $slider_css .= "}";
            $slider_css .= "#{$uid} .wpslider-track img{width:" . (100 / $count) . "%;height:400px;object-fit:cover;flex-shrink:0}";
            if ($style === 'fade') {
                $slider_css .= "#{$uid} .wpslider-track{width:100%;position:relative}";
                $slider_css .= "#{$uid} .wpslider-track img{position:absolute;top:0;left:0;width:100%;opacity:0;animation:{$uid}-fade {$dur}s infinite}";
                for ($i = 0; $i < $count; $i++) {
                    $delay = $i * ($speed / 1000);
                    $slider_css .= "#{$uid} .wpslider-track img:nth-child(" . ($i + 1) . "){animation-delay:{$delay}s}";
                }
                $step = 100 / $count;
                $slider_css .= "@keyframes {$uid}-fade{0%,{$step}%{opacity:1}" . ($step + 5) . "%,100%{opacity:0}}";
            } else {
                $kf = "@keyframes {$uid}-slide{";
                for ($i = 0; $i < $count; $i++) {
                    $pct_start = ($i / $count) * 100;
                    $pct_hold  = (($i + 0.8) / $count) * 100;
                    $offset = ($i / $count) * 100;
                    $kf .= "{$pct_start}%,{$pct_hold}%{transform:translateX(-{$offset}%)}";
                }
                $kf .= "}";
                $slider_css .= $kf;
            }
            $slider_css .= '</style>';
            $slider_markup = '<div id="' . $uid . '"><div class="wpslider-track">';
            foreach ($images as $img) $slider_markup .= '<img src="' . esc_url($img) . '" alt="Slide">';
            $slider_markup .= '</div></div>';
            wp_update_post(['ID' => $page_id, 'post_content' => $post->post_content . "\n" . $slider_css . $slider_markup]);
            return wpilot_ok("Slider ({$count} images, {$style} style" . ($autoplay ? ", autoplay {$speed}ms" : "") . ") added to page #{$page_id}.", ['slides' => $count, 'style' => $style]);

        case 'create_testimonial_slider':
            $page_id      = intval($params['page_id'] ?? 0);
            $testimonials = $params['testimonials'] ?? [];
            if (!$page_id) return wpilot_err('page_id required.');
            if (empty($testimonials)) return wpilot_err('testimonials array required (each: {name, text, role, image}).');
            $post = get_post($page_id);
            if (!$post) return wpilot_err("Page #{$page_id} not found.");
            wpilot_save_post_snapshot($page_id);
            $uid = 'wptesti-' . wp_rand(1000, 9999);
            $count = count($testimonials);
            $dur = $count * 5;
            $testi_css = '<style>';
            $testi_css .= "#{$uid}{max-width:700px;margin:40px auto;overflow:hidden;position:relative}";
            $testi_css .= "#{$uid} .wpt-track{display:flex;width:" . ($count * 100) . "%;animation:{$uid}-slide {$dur}s infinite}";
            $testi_css .= "#{$uid} .wpt-card{width:" . (100 / $count) . "%;padding:40px;text-align:center;flex-shrink:0}";
            $testi_css .= "#{$uid} .wpt-text{font-size:1.1rem;line-height:1.8;color:#555;font-style:italic;margin-bottom:24px}";
            $testi_css .= "#{$uid} .wpt-avatar{width:60px;height:60px;border-radius:50%;object-fit:cover;margin:0 auto 12px}";
            $testi_css .= "#{$uid} .wpt-name{font-weight:700;font-size:1rem;color:#1a1a1a}";
            $testi_css .= "#{$uid} .wpt-role{font-size:0.85rem;color:#888}";
            $kf = "@keyframes {$uid}-slide{";
            for ($i = 0; $i < $count; $i++) {
                $ps = ($i / $count) * 100;
                $ph = (($i + 0.8) / $count) * 100;
                $off = ($i / $count) * 100;
                $kf .= "{$ps}%,{$ph}%{transform:translateX(-{$off}%)}";
            }
            $kf .= "}";
            $testi_css .= $kf . '</style>';
            $testi_markup = '<div id="' . $uid . '"><div class="wpt-track">';
            foreach ($testimonials as $t) {
                $testi_markup .= '<div class="wpt-card">';
                $testi_markup .= '<div class="wpt-text">' . esc_html($t['text'] ?? '') . '</div>';
                if (!empty($t['image'])) $testi_markup .= '<img class="wpt-avatar" src="' . esc_url($t['image']) . '" alt="' . esc_attr($t['name'] ?? '') . '">';
                $testi_markup .= '<div class="wpt-name">' . esc_html($t['name'] ?? '') . '</div>';
                $testi_markup .= '<div class="wpt-role">' . esc_html($t['role'] ?? '') . '</div>';
                $testi_markup .= '</div>';
            }
            $testi_markup .= '</div></div>';
            wp_update_post(['ID' => $page_id, 'post_content' => $post->post_content . "\n" . $testi_css . $testi_markup]);
            return wpilot_ok("Testimonial slider ({$count} testimonials) added to page #{$page_id}.", ['testimonials' => $count]);

        /* ── 3D CSS Effects ─────────────────────────────────── */
        case 'add_3d_effect':
            $selector = sanitize_text_field($params['selector'] ?? '');
            $effect   = sanitize_text_field($params['effect'] ?? 'tilt');
            if (!$selector) return wpilot_err('selector required.');
            $effects = [
                'tilt'        => "{$selector}{transition:transform 0.4s ease;transform-style:preserve-3d}{$selector}:hover{transform:perspective(800px) rotateX(5deg) rotateY(5deg)}",
                'flip'        => "{$selector}{transition:transform 0.6s ease;transform-style:preserve-3d}{$selector}:hover{transform:perspective(800px) rotateY(180deg)}",
                'rotate'      => "{$selector}{transition:transform 0.6s ease}{$selector}:hover{transform:perspective(600px) rotateX(15deg) scale(1.05)}",
                'perspective' => "{$selector}{transform:perspective(1000px) rotateX(2deg);transition:transform 0.5s}{$selector}:hover{transform:perspective(1000px) rotateX(0deg)}",
                'float'       => "@keyframes wpilot-float{0%,100%{transform:translateY(0) perspective(800px) rotateX(2deg)}50%{transform:translateY(-10px) perspective(800px) rotateX(-2deg)}}{$selector}{animation:wpilot-float 3s ease-in-out infinite}",
            ];
            if (!isset($effects[$effect])) return wpilot_err("Unknown effect: {$effect}. Options: tilt, flip, rotate, perspective, float.");
            $css = "\n/* wpilot-3d-{$effect} */\n" . $effects[$effect] . "\n";
            $existing = wp_get_custom_css();
            wp_update_custom_css_post($existing . $css);
            return wpilot_ok("3D '{$effect}' effect applied to {$selector}.", ['selector' => $selector, 'effect' => $effect]);

        case 'create_3d_card':
            $page_id   = intval($params['page_id'] ?? 0);
            $title     = sanitize_text_field($params['title'] ?? '');
            $content   = wp_kses_post($params['content'] ?? '');
            $image_url = esc_url($params['image_url'] ?? '');
            if (!$page_id) return wpilot_err('page_id required.');
            $post = get_post($page_id);
            if (!$post) return wpilot_err("Page #{$page_id} not found.");
            wpilot_save_post_snapshot($page_id);
            $uid = 'wp3d-' . wp_rand(1000, 9999);
            $css = "\n/* wpilot-3d-card-{$uid} */\n";
            $css .= "#{$uid}{perspective:1000px;max-width:400px;margin:30px auto}";
            $css .= "#{$uid} .wp3d-inner{background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.1);transition:transform 0.4s ease,box-shadow 0.4s ease;transform-style:preserve-3d}";
            $css .= "#{$uid} .wp3d-inner:hover{transform:rotateX(5deg) rotateY(-5deg) scale(1.02);box-shadow:0 20px 60px rgba(0,0,0,0.15)}";
            $css .= "#{$uid} .wp3d-img{width:100%;height:220px;object-fit:cover}";
            $css .= "#{$uid} .wp3d-body{padding:24px}";
            $css .= "#{$uid} .wp3d-title{font-size:1.3rem;font-weight:700;margin-bottom:12px}";
            $css .= "#{$uid} .wp3d-text{color:#666;line-height:1.6}\n";
            $existing_css = wp_get_custom_css();
            wp_update_custom_css_post($existing_css . $css);
            $card_markup = '<div id="' . $uid . '"><div class="wp3d-inner">';
            if ($image_url) $card_markup .= '<img class="wp3d-img" src="' . $image_url . '" alt="' . esc_attr($title) . '">';
            $card_markup .= '<div class="wp3d-body"><div class="wp3d-title">' . esc_html($title) . '</div><div class="wp3d-text">' . $content . '</div></div></div></div>';
            wp_update_post(['ID' => $page_id, 'post_content' => $post->post_content . "\n" . $card_markup]);
            return wpilot_ok("3D hover card '{$title}' added to page #{$page_id}.", ['id' => $uid]);

        /* ── JavaScript Tools ───────────────────────────────── */
        case 'add_javascript':
            $code     = $params['code'] ?? '';
            $location = sanitize_text_field($params['location'] ?? 'footer');
            $page_id  = intval($params['page_id'] ?? 0);
            $tag      = sanitize_text_field($params['tag'] ?? 'wpilot-js-' . wp_rand(1000, 9999));
            if (!$code) return wpilot_err('code (JS string) required.');
            $opt_key = ($location === 'header') ? 'wpilot_head_code' : 'wpilot_footer_scripts';
            $existing = get_option($opt_key, '');
            $js_block = "\n<!-- {$tag} -->\n<script>\n";
            if ($page_id > 0) {
                $pg = $page_id;
                $js_block .= "(function(){var b=document.body.className;if(b.indexOf('page-id-{$pg}')<0&&b.indexOf('postid-{$pg}')<0)return;\n";
                $js_block .= $code . "\n})();";
            } else {
                $js_block .= $code;
            }
            $js_block .= "\n</script>\n<!-- /{$tag} -->\n";
            update_option($opt_key, $existing . $js_block);
            return wpilot_ok("JavaScript added to {$location}" . ($page_id ? " for page #{$page_id}" : " (all pages)") . ".", ['tag' => $tag, 'location' => $location]);

        case 'remove_javascript':
            $tag = sanitize_text_field($params['tag'] ?? '');
            if (!$tag) return wpilot_err('tag required.');
            $removed = false;
            foreach (['wpilot_head_code', 'wpilot_footer_scripts'] as $opt_key) {
                $js_content = get_option($opt_key, '');
                $pattern = '/<!--\s*' . preg_quote($tag, '/') . '\s*-->.*?<!--\s*\/' . preg_quote($tag, '/') . '\s*-->/s';
                $updated = preg_replace($pattern, '', $js_content);
                if ($updated !== $js_content) {
                    update_option($opt_key, trim($updated));
                    $removed = true;
                }
            }
            if (!$removed) return wpilot_err("Tag '{$tag}' not found in header or footer scripts.");
            return wpilot_ok("JavaScript tagged '{$tag}' removed.");

        case 'add_counter_animation':
            $selector = sanitize_text_field($params['selector'] ?? '.wpilot-counter');
            $js_counter = "document.addEventListener('DOMContentLoaded',function(){var counters=document.querySelectorAll('" . addslashes($selector) . "');var observer=new IntersectionObserver(function(entries){entries.forEach(function(entry){if(entry.isIntersecting){var el=entry.target;var target=parseInt(el.getAttribute('data-target'))||parseInt(el.textContent)||0;var duration=1500;var start=0;var step=Math.ceil(target/60);var timer=setInterval(function(){start+=step;if(start>=target){el.textContent=target.toLocaleString();clearInterval(timer)}else{el.textContent=start.toLocaleString()}},duration/60);observer.unobserve(el)}});},{threshold:0.3});counters.forEach(function(c){observer.observe(c)})});";
            $opt = get_option('wpilot_footer_scripts', '');
            if (strpos($opt, 'wpilot-counter-anim') !== false) return wpilot_ok("Counter animation already loaded.");
            $block = "\n<!-- wpilot-counter-anim -->\n<script>" . $js_counter . "</script>\n<!-- /wpilot-counter-anim -->\n";
            update_option('wpilot_footer_scripts', $opt . $block);
            return wpilot_ok("Counter animation added for '{$selector}'. Add data-target attribute to elements.", ['selector' => $selector]);

        case 'add_scroll_to_top':
            $opt = get_option('wpilot_footer_scripts', '');
            if (strpos($opt, 'wpilot-scroll-top') !== false) return wpilot_ok("Scroll-to-top button already exists.");
            $js_stt = "document.addEventListener('DOMContentLoaded',function(){var btn=document.createElement('button');btn.id='wpilot-stt';btn.textContent='\\u2191';btn.style.cssText='position:fixed;bottom:30px;right:30px;width:48px;height:48px;border-radius:50%;background:#6366f1;color:#fff;border:none;font-size:1.3rem;cursor:pointer;opacity:0;transition:opacity 0.3s,transform 0.3s;z-index:9999;box-shadow:0 4px 15px rgba(99,102,241,0.3);transform:translateY(20px)';document.body.appendChild(btn);window.addEventListener('scroll',function(){if(window.scrollY>300){btn.style.opacity='1';btn.style.transform='translateY(0)'}else{btn.style.opacity='0';btn.style.transform='translateY(20px)'}});btn.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'})})});";
            $block = "\n<!-- wpilot-scroll-top -->\n<script>" . $js_stt . "</script>\n<!-- /wpilot-scroll-top -->\n";
            update_option('wpilot_footer_scripts', $opt . $block);
            return wpilot_ok("Scroll-to-top button added (appears after 300px scroll).");

        case 'add_typed_text':
            $selector = sanitize_text_field($params['selector'] ?? '.wpilot-typed');
            $words    = $params['words'] ?? ['Hello', 'World'];
            $speed    = intval($params['speed'] ?? 80);
            $words_js = json_encode($words);
            $js_typed = "document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('" . addslashes($selector) . "').forEach(function(el){var words={$words_js};var wi=0;var ci=0;var deleting=false;var speed={$speed};function type(){var word=words[wi];if(deleting){el.textContent=word.substring(0,ci--);if(ci<0){deleting=false;wi=(wi+1)%words.length;setTimeout(type,400);return}}else{el.textContent=word.substring(0,ci++);if(ci>word.length){deleting=true;setTimeout(type,1500);return}}setTimeout(type,deleting?speed/2:speed)}type()})});";
            $opt = get_option('wpilot_footer_scripts', '');
            $tag = 'wpilot-typed-' . wp_rand(1000, 9999);
            $block = "\n<!-- {$tag} -->\n<script>" . $js_typed . "</script>\n<!-- /{$tag} -->\n";
            update_option('wpilot_footer_scripts', $opt . $block);
            return wpilot_ok("Typed text animation added for '{$selector}'.", ['words' => $words, 'tag' => $tag]);


        /* ── Admin Design / Client Dashboard Tools ──────────── */

        case 'hide_admin_menu':
        case 'simplify_admin':
            $role = sanitize_text_field($params['role'] ?? 'editor');
            $hide = $params['hide'] ?? $params['items'] ?? [];
            // Default: hide everything except basics for non-admins
            if (empty($hide)) {
                $hide = ['edit.php', 'upload.php', 'edit-comments.php', 'tools.php', 'options-general.php', 'plugins.php', 'themes.php', 'users.php'];
            }
            $settings = get_option('wpilot_hidden_menus', []);
            $settings[$role] = $hide;
            update_option('wpilot_hidden_menus', $settings);
            // Create mu-plugin that hides menus
            $mu = ABSPATH . 'wp-content/mu-plugins/wpilot-admin-menus.php';
            $code = "<?php\nadd_action('admin_menu', function() {\n    if (current_user_can('manage_options')) return;\n    \$hidden = get_option('wpilot_hidden_menus', []);\n    \$user = wp_get_current_user();\n    foreach (\$user->roles as \$role) {\n        foreach (\$hidden[\$role] ?? [] as \$menu) {\n            remove_menu_page(\$menu);\n        }\n    }\n}, 999);\n";
            file_put_contents($mu, $code);
            return wpilot_ok("Hidden " . count($hide) . " menu items for {$role}.", ['role' => $role, 'hidden' => $hide]);

        case 'create_client_dashboard':
        case 'simple_dashboard':
            $role = sanitize_text_field($params['role'] ?? 'editor');
            $widgets = $params['widgets'] ?? ['orders', 'stats', 'quick_actions'];
            $accent = sanitize_text_field($params['accent_color'] ?? '#6366F1');
            $company = sanitize_text_field($params['company_name'] ?? get_bloginfo('name'));
            // Build dashboard HTML
            $html = '<div id="wpilot-client-dash" style="max-width:1200px;margin:20px auto;font-family:system-ui,sans-serif">';
            $html .= '<h1 style="font-size:1.8rem;font-weight:700;color:#1a1a2e;margin:0 0 24px">Welcome to ' . esc_html($company) . '</h1>';
            $html .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px">';

            // Stats cards
            if (in_array('stats', $widgets) && class_exists('WooCommerce')) {
                $html .= '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px"><p style="color:#6b7280;font-size:0.85rem;margin:0 0 4px">Today\'s Orders</p><p style="font-size:2rem;font-weight:700;color:#1a1a2e;margin:0" id="wpi-dash-orders">-</p></div>';
                $html .= '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px"><p style="color:#6b7280;font-size:0.85rem;margin:0 0 4px">Revenue</p><p style="font-size:2rem;font-weight:700;color:' . $accent . ';margin:0" id="wpi-dash-revenue">-</p></div>';
                $html .= '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px"><p style="color:#6b7280;font-size:0.85rem;margin:0 0 4px">Products</p><p style="font-size:2rem;font-weight:700;color:#1a1a2e;margin:0" id="wpi-dash-products">-</p></div>';
            }

            // Quick actions
            if (in_array('quick_actions', $widgets)) {
                $html .= '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;grid-column:span 1"><p style="font-weight:600;color:#1a1a2e;margin:0 0 16px">Quick Actions</p>';
                $actions_list = [
                    ['📦 New Product', admin_url('post-new.php?post_type=product')],
                    ['🎫 Create Coupon', admin_url('post-new.php?post_type=shop_coupon')],
                    ['📋 View Orders', admin_url('edit.php?post_type=shop_order')],
                    ['📊 Reports', admin_url('admin.php?page=wc-reports')],
                ];
                foreach ($actions_list as $a) {
                    $html .= '<a href="' . $a[1] . '" style="display:block;padding:10px 14px;margin-bottom:8px;background:#f9fafb;border-radius:8px;color:#1a1a2e;text-decoration:none;font-size:0.9rem;transition:background 0.2s">' . $a[0] . '</a>';
                }
                $html .= '</div>';
            }

            $html .= '</div>';

            // Recent orders table
            if (in_array('orders', $widgets) && class_exists('WooCommerce')) {
                $html .= '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-top:20px">';
                $html .= '<p style="font-weight:600;color:#1a1a2e;margin:0 0 16px">Recent Orders</p>';
                $html .= '<div id="wpi-dash-order-table">Loading...</div>';
                $html .= '</div>';
            }

            $html .= '</div>';

            // Save as dashboard widget replacement
            update_option('wpilot_client_dashboard', $html);
            update_option('wpilot_client_dashboard_role', $role);

            // Create mu-plugin that replaces dashboard
            $mu = ABSPATH . 'wp-content/mu-plugins/wpilot-client-dashboard.php';
            $mu_code = "<?php\nadd_action('admin_init', function() {\n    if (current_user_can('manage_options')) return;\n    \$dash_role = get_option('wpilot_client_dashboard_role', '');\n    \$user = wp_get_current_user();\n    if (!in_array(\$dash_role, \$user->roles)) return;\n    // Redirect to custom dashboard\n    global \$pagenow;\n    if (\$pagenow === 'index.php' && empty(\$_GET['page'])) {\n        remove_all_actions('welcome_panel');\n        add_action('admin_notices', function() {\n            echo get_option('wpilot_client_dashboard', '');\n        });\n    }\n});\n// Remove default dashboard widgets for client role\nadd_action('wp_dashboard_setup', function() {\n    if (current_user_can('manage_options')) return;\n    remove_meta_box('dashboard_quick_press', 'dashboard', 'side');\n    remove_meta_box('dashboard_primary', 'dashboard', 'side');\n    remove_meta_box('dashboard_site_health', 'dashboard', 'normal');\n    remove_meta_box('dashboard_right_now', 'dashboard', 'normal');\n    remove_meta_box('dashboard_activity', 'dashboard', 'normal');\n    remove_meta_box('woocommerce_dashboard_status', 'dashboard', 'normal');\n    remove_meta_box('woocommerce_dashboard_recent_reviews', 'dashboard', 'normal');\n}, 999);\n";
            file_put_contents($mu, $mu_code);

            return wpilot_ok("Client dashboard created for role '{$role}'.", ['role' => $role, 'widgets' => $widgets]);

        case 'design_admin_page':
        case 'style_admin':
        case 'admin_theme':
            $style = sanitize_text_field($params['style'] ?? 'modern');
            $accent = sanitize_text_field($params['accent_color'] ?? '#6366F1');
            $logo_url = esc_url($params['logo_url'] ?? '');

            $styles = [
                'modern' => "
#wpadminbar { background: #1a1a2e !important; }
#adminmenu, #adminmenuBack, #adminmenuwrap { background: #16213e !important; }
#adminmenu a { color: rgba(255,255,255,0.7) !important; }
#adminmenu .wp-has-current-submenu > a, #adminmenu .current a { background: {$accent} !important; color: #fff !important; }
#adminmenu li.menu-top:hover > a { color: #fff !important; background: rgba(255,255,255,0.05) !important; }
#adminmenu .wp-submenu { background: #0f3460 !important; }
#adminmenu .wp-submenu a { color: rgba(255,255,255,0.6) !important; }
#adminmenu .wp-submenu a:hover { color: #fff !important; }
#wpcontent { background: #f0f2f5; }
.wrap h1 { font-weight: 700; color: #1a1a2e; }
",
                'minimal' => "
#wpadminbar { background: #fff !important; border-bottom: 1px solid #e5e7eb !important; }
#wpadminbar * { color: #374151 !important; }
#adminmenu, #adminmenuBack, #adminmenuwrap { background: #fff !important; border-right: 1px solid #e5e7eb !important; }
#adminmenu a { color: #374151 !important; }
#adminmenu .wp-has-current-submenu > a { background: {$accent} !important; color: #fff !important; }
#wpcontent { background: #f9fafb; }
",
                'dark' => "
#wpadminbar { background: #0a0a0a !important; }
#adminmenu, #adminmenuBack, #adminmenuwrap { background: #0f0f0f !important; }
#adminmenu a { color: rgba(255,255,255,0.6) !important; }
#adminmenu .wp-has-current-submenu > a { background: {$accent} !important; }
#wpcontent { background: #1a1a1a; color: #e5e7eb; }
.wrap h1 { color: #fff; }
",
            ];

            $css = $styles[$style] ?? $styles['modern'];

            if ($logo_url) {
                $css .= "\n#adminmenu #toplevel_page_wpilot .wp-menu-image img { content: url('{$logo_url}'); }\n";
            }

            $mu = ABSPATH . 'wp-content/mu-plugins/wpilot-admin-style.php';
            $mu_code = "<?php\nadd_action('admin_head', function() {\n    echo '<style>" . str_replace("'", "\\'", str_replace("\n", " ", $css)) . "</style>';\n});\n";
            file_put_contents($mu, $mu_code);

            return wpilot_ok("Admin styled: {$style} theme with accent {$accent}.", ['style' => $style]);

        case 'create_customer_portal':
        case 'design_my_account':
            $accent = sanitize_text_field($params['accent_color'] ?? '#6366F1');
            $style = sanitize_text_field($params['style'] ?? 'modern');

            $css = "
/* Customer Portal Design */
.woocommerce-account .woocommerce { max-width: 1000px; margin: 0 auto; }
.woocommerce-account .woocommerce-MyAccount-navigation { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 8px; margin-bottom: 24px; }
.woocommerce-account .woocommerce-MyAccount-navigation ul { display: flex; flex-wrap: wrap; gap: 4px; list-style: none; padding: 0; margin: 0; }
.woocommerce-account .woocommerce-MyAccount-navigation li a { display: block; padding: 10px 16px; border-radius: 8px; color: #374151; text-decoration: none; font-size: 0.9rem; font-weight: 500; transition: all 0.2s; }
.woocommerce-account .woocommerce-MyAccount-navigation li.is-active a { background: {$accent}; color: #fff; }
.woocommerce-account .woocommerce-MyAccount-navigation li a:hover { background: #f3f4f6; }
.woocommerce-account .woocommerce-MyAccount-content { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 32px; }
.woocommerce-account table { border-collapse: collapse; width: 100%; }
.woocommerce-account table th { text-align: left; padding: 12px; border-bottom: 2px solid #e5e7eb; font-weight: 600; color: #374151; }
.woocommerce-account table td { padding: 12px; border-bottom: 1px solid #f3f4f6; }
.woocommerce-account .button { background: {$accent} !important; color: #fff !important; border: none !important; border-radius: 8px !important; padding: 10px 20px !important; }
@media (max-width: 768px) { .woocommerce-account .woocommerce-MyAccount-navigation ul { flex-direction: column; } }
";

            $existing = wp_get_custom_css();
            $existing = preg_replace('/\/\* Customer Portal Design \*\/.*?(?=\/\*|$)/s', '', $existing);
            wp_update_custom_css_post($existing . "\n" . $css);

            return wpilot_ok("Customer portal designed: {$style}.", ['style' => $style, 'accent' => $accent]);

        case 'white_label_admin':
        case 'rebrand_admin':
            $company = sanitize_text_field($params['company_name'] ?? $params['name'] ?? get_bloginfo('name'));
            $logo_url = esc_url($params['logo_url'] ?? '');
            $footer_text = sanitize_text_field($params['footer_text'] ?? 'Powered by ' . $company);
            $login_logo = esc_url($params['login_logo'] ?? $logo_url);

            $mu = ABSPATH . 'wp-content/mu-plugins/wpilot-white-label.php';
            $code = "<?php\n";
            // Custom footer
            $code .= "add_filter('admin_footer_text', function() { return '" . esc_html($footer_text) . "'; });\n";
            $code .= "add_filter('update_footer', function() { return ''; }, 11);\n";
            // Remove WP logo from admin bar
            $code .= "add_action('wp_before_admin_bar_render', function() { global \$wp_admin_bar; \$wp_admin_bar->remove_menu('wp-logo'); });\n";
            // Custom login logo
            if ($login_logo) {
                $code .= "add_action('login_enqueue_scripts', function() { echo '<style>.login h1 a { background-image: url(" . $login_logo . ") !important; background-size: contain !important; width: 200px !important; height: 80px !important; }</style>'; });\n";
                $code .= "add_filter('login_headerurl', function() { return '" . get_site_url() . "'; });\n";
                $code .= "add_filter('login_headertext', function() { return '" . esc_html($company) . "'; });\n";
            }
            // Custom admin title
            $code .= "add_filter('admin_title', function(\$title) { return str_replace('WordPress', '" . esc_html($company) . "', \$title); });\n";

            file_put_contents($mu, $code);
            return wpilot_ok("White-labeled admin: {$company}.", ['company' => $company, 'has_logo' => !empty($logo_url)]);

        /* ── Role / Permission Tools ────────────────────────── */

        default:
            return null; // Not handled by this module
    }
}
