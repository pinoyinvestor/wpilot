<?php
/**
 * WPilot - AI Website Builder for WordPress
 * Copyright (c) 2026 Weblease. All rights reserved.
 *
 * This software is licensed, not sold. Unauthorized copying,
 * modification, or distribution is strictly prohibited.
 * License: https://weblease.se/terms
 *
 * Each copy is bound to a specific domain via license key.
 * Tampered or unlicensed copies will be disabled remotely.
 */
if (!defined('ABSPATH')) exit;

/**
 * WPilot Marketing Tools Module
 * Contains 15 tool cases for marketing operations:
 *   Popup/Banner Builder (6), AI Content Writer (5),
 *   QR Code Generator (2), Scheduling (2)
 */
function wpilot_run_marketing_tools($tool, $params = []) {
    switch ($tool) {

        // ═════════════════════════════════════════════════════════
        //  1. POPUP / BANNER BUILDER
        // ═════════════════════════════════════════════════════════

        // ── create_popup ─────────────────────────────────────────
        case 'create_popup':
            $title        = sanitize_text_field($params['title'] ?? '');
            $content      = wp_kses_post($params['content'] ?? '');
            $type         = sanitize_key($params['type'] ?? 'modal');
            $trigger      = sanitize_text_field($params['trigger'] ?? 'load');
            $style        = sanitize_key($params['style'] ?? 'minimal');
            $cta_text     = sanitize_text_field($params['cta_text'] ?? '');
            $cta_url      = esc_url_raw($params['cta_url'] ?? '');
            $dismiss_days = max(0, intval($params['dismiss_days'] ?? 7));
            $show_on      = sanitize_text_field($params['show_on'] ?? 'all');
            $bg_image     = esc_url_raw($params['background_image'] ?? '');

            if (empty($title) && empty($content)) {
                return wpilot_err('Popup requires a title or content.');
            }

            $allowed_types    = ['modal', 'slide-in', 'top-bar', 'bottom-bar', 'fullscreen'];
            $allowed_triggers = ['load', 'exit-intent', 'scroll-50', 'delay-5s', 'click'];
            $allowed_styles   = ['minimal', 'bold', 'branded'];
            if (!in_array($type, $allowed_types))       $type    = 'modal';
            if (!in_array($trigger, $allowed_triggers)) $trigger = 'load';
            if (!in_array($style, $allowed_styles))     $style   = 'minimal';

            $popup_id  = 'popup-' . wp_generate_password(8, false);
            $popups    = get_option('wpilot_popups', []);

            $popups[$popup_id] = [
                'id'               => $popup_id,
                'title'            => $title,
                'content'          => $content,
                'type'             => $type,
                'trigger'          => $trigger,
                'style'            => $style,
                'cta_text'         => $cta_text,
                'cta_url'          => $cta_url,
                'dismiss_days'     => $dismiss_days,
                'show_on'          => $show_on,
                'background_image' => $bg_image,
                'active'           => true,
                'impressions'      => 0,
                'clicks'           => 0,
                'dismissals'       => 0,
                'created_at'       => current_time('mysql'),
                'schedule_start'   => '',
                'schedule_end'     => '',
            ];
            update_option('wpilot_popups', $popups, false);

            $mu_code = wpilot_build_popup_mu_code($popups);
            wpilot_mu_register('popups', $mu_code);

            function_exists("wpilot_log_activity") && wpilot_log_activity('create_popup', "Created {$type} popup: {$title}", $popup_id);
            return wpilot_ok("Popup \"{$title}\" created ({$type}, trigger: {$trigger}).", [
                'popup_id' => $popup_id,
                'type'     => $type,
                'trigger'  => $trigger,
            ]);

        // ── create_banner ────────────────────────────────────────
        case 'create_banner':
            $text         = sanitize_text_field($params['text'] ?? '');
            $cta_text     = sanitize_text_field($params['cta_text'] ?? '');
            $cta_url      = esc_url_raw($params['cta_url'] ?? '');
            $position     = sanitize_key($params['position'] ?? 'top');
            $bg_color     = sanitize_hex_color($params['bg_color'] ?? '') ?: '';
            $dismissible  = ($params['dismissible'] ?? true) !== false;
            $countdown_to = sanitize_text_field($params['countdown_to'] ?? '');

            if (empty($text)) return wpilot_err('Banner text is required.');
            if (!in_array($position, ['top', 'bottom'])) $position = 'top';

            $banner_id = 'banner-' . wp_generate_password(8, false);
            $popups    = get_option('wpilot_popups', []);

            // Store banner data
            $popups[$banner_id] = [
                'id' => $banner_id, 'text' => $text, 'cta_text' => $cta_text,
                'cta_url' => $cta_url, 'position' => $position, 'bg_color' => $bg_color,
                'dismissible' => $dismissible, 'countdown_to' => $countdown_to,
                'active' => true, 'created_at' => current_time('mysql'),
            ];
            update_option('wpilot_popups', $popups, false);

            // Build simple, direct HTML injection — no complex render system
            $bg = $bg_color ?: 'var(--wp-primary, #333)';
            $is_top = ($position === 'top');
            $pos_css = $is_top ? 'top:0;' : 'bottom:0;';
            $dismiss_btn = $dismissible ? '<button onclick="this.parentElement.remove();document.body.style.paddingTop=document.getElementById(\'wpilot-header\')?\'70px\':\'0\'" style="background:none;border:none;color:inherit;font-size:1.3rem;cursor:pointer;margin-left:8px;opacity:0.7;">&times;</button>' : '';
            $cta_html = ($cta_text && $cta_url) ? '<a href="' . esc_url($cta_url) . '" style="background:var(--wp-bg,#fff);color:' . $bg . ';padding:6px 18px;border-radius:var(--wp-radius,6px);font-weight:600;text-decoration:none;font-size:0.85rem;white-space:nowrap;">' . esc_html($cta_text) . '</a>' : '';
            $countdown_js = '';
            if ($countdown_to) {
                $countdown_js = '<script>(function(){var d=new Date("' . esc_js($countdown_to) . '").getTime(),el=document.getElementById("wpilot-banner-countdown");if(!el)return;setInterval(function(){var n=d-Date.now();if(n<0){el.textContent="Erbjudandet har gått ut";return;}var h=Math.floor(n/36e5),m=Math.floor(n%36e5/6e4),s=Math.floor(n%6e4/1e3);el.textContent=h+"h "+m+"m "+s+"s";},1000);})()</script>';
            }
            $countdown_span = $countdown_to ? ' <span id="wpilot-banner-countdown" style="font-weight:700;"></span>' : '';

            $banner_el = '<div id="wpilot-top-banner" style="position:fixed;' . $pos_css . 'left:0;width:100%;z-index:9999999;background:' . $bg . ';color:var(--wp-bg,#fff);text-align:center;padding:10px 40px;font-family:var(--wp-body-font,system-ui);font-size:0.9rem;display:flex;align-items:center;justify-content:center;gap:16px;"><span>' . esc_html($text) . $countdown_span . '</span>' . $cta_html . $dismiss_btn . '</div>' . $countdown_js;

            // Mu-plugin: inject banner + adjust header position
            $mu_code  = "<?php\n";
            $mu_code .= "add_action('wp_body_open', function() {\n";
            $mu_code .= "    if (is_admin()) return;\n";
            $mu_code .= "    echo '" . addslashes($banner_el) . "';\n";
            $mu_code .= "}, 0);\n";
            if ($is_top) {
                $mu_code .= "add_action('wp_head', function() {\n";
                $mu_code .= "    echo '<style>#wpilot-header{top:42px !important;}body.has-wpilot-header{padding-top:112px !important;}</style>';\n";
                $mu_code .= "}, 999999);\n";
            }

            if (function_exists('wpilot_mu_register')) {
                wpilot_mu_register('top-banner', $mu_code);
            }

            function_exists("wpilot_log_activity") && wpilot_log_activity('create_banner', "Created {$position} banner", $banner_id);
            return wpilot_ok("Banner created at {$position} of page. Header pushed down automatically.", [
                'banner_id' => $banner_id, 'position' => $position,
            ]);

        // ── list_popups ──────────────────────────────────────────
        case 'list_popups':
            $popups = get_option('wpilot_popups', []);
            if (empty($popups)) return wpilot_ok('No active popups or banners.', ['popups' => []]);

            $list = [];
            foreach ($popups as $id => $p) {
                $list[] = [
                    'id'         => $id,
                    'title'      => $p['title'] ?: $p['content'],
                    'type'       => $p['type'],
                    'trigger'    => $p['trigger'],
                    'active'     => $p['active'],
                    'show_on'    => $p['show_on'] ?? 'all',
                    'created_at' => $p['created_at'],
                    'schedule'   => ($p['schedule_start'] ?? '') ? ($p['schedule_start'] . ' to ' . ($p['schedule_end'] ?? 'forever')) : 'always',
                ];
            }
            return wpilot_ok(count($list) . ' popup(s)/banner(s) found.', ['popups' => $list]);

        // ── remove_banner ──────────────────────────────────────────
        case 'remove_banner':
        case 'delete_banner':
        case 'hide_banner':
            if (function_exists('wpilot_mu_remove')) wpilot_mu_remove('top-banner');
            // Also clear popups that are banners
            $popups = get_option('wpilot_popups', []);
            foreach ($popups as $id => $p) {
                if (strpos($id, 'banner-') === 0) unset($popups[$id]);
            }
            update_option('wpilot_popups', $popups, false);
            if (function_exists('wpilot_regenerate_mu')) wpilot_regenerate_mu();
            return wpilot_ok('Banner removed.');

        // ── delete_popup ─────────────────────────────────────────
        case 'delete_popup':
            $id     = sanitize_key($params['id'] ?? $params['popup_id'] ?? $params['banner_id'] ?? '');
            $popups = get_option('wpilot_popups', []);
            if (empty($id) || !isset($popups[$id])) {
                return wpilot_err('Popup/banner not found. Use list_popups to see IDs.');
            }

            $label = $popups[$id]['title'] ?: $popups[$id]['content'];
            unset($popups[$id]);
            update_option('wpilot_popups', $popups, false);

            if (empty($popups)) {
                wpilot_mu_remove('popups');
            } else {
                wpilot_mu_register('popups', wpilot_build_popup_mu_code($popups));
            }

            function_exists("wpilot_log_activity") && wpilot_log_activity('delete_popup', "Deleted popup/banner: {$label}", $id);
            return wpilot_ok("Popup/banner \"{$label}\" deleted.");

        // ── schedule_popup ───────────────────────────────────────
        case 'schedule_popup':
            $id         = sanitize_key($params['id'] ?? $params['popup_id'] ?? '');
            $start_date = sanitize_text_field($params['start_date'] ?? '');
            $end_date   = sanitize_text_field($params['end_date'] ?? '');
            $popups     = get_option('wpilot_popups', []);

            if (empty($id) || !isset($popups[$id])) {
                return wpilot_err('Popup/banner not found.');
            }
            if (empty($start_date)) return wpilot_err('start_date is required (YYYY-MM-DD HH:MM).');

            $popups[$id]['schedule_start'] = $start_date;
            $popups[$id]['schedule_end']   = $end_date;
            update_option('wpilot_popups', $popups, false);

            wpilot_mu_register('popups', wpilot_build_popup_mu_code($popups));

            $msg = "Popup \"{$popups[$id]['title']}\" scheduled from {$start_date}";
            if ($end_date) $msg .= " to {$end_date}";
            return wpilot_ok($msg . '.');

        // ── popup_stats ──────────────────────────────────────────
        case 'popup_stats':
            $id     = sanitize_key($params['id'] ?? $params['popup_id'] ?? '');
            $popups = get_option('wpilot_popups', []);

            if ($id && isset($popups[$id])) {
                $p = $popups[$id];
                $impressions = intval($p['impressions'] ?? 0);
                $clicks      = intval($p['clicks'] ?? 0);
                $dismissals  = intval($p['dismissals'] ?? 0);
                $ctr         = $impressions > 0 ? round(($clicks / $impressions) * 100, 1) : 0;
                $dismiss_rate = $impressions > 0 ? round(($dismissals / $impressions) * 100, 1) : 0;
                return wpilot_ok("Stats for \"{$p['title']}\"", [
                    'impressions'  => $impressions,
                    'clicks'       => $clicks,
                    'dismissals'   => $dismissals,
                    'ctr'          => $ctr . '%',
                    'dismiss_rate' => $dismiss_rate . '%',
                ]);
            }

            $all_stats = [];
            foreach ($popups as $pid => $p) {
                $imp = intval($p['impressions'] ?? 0);
                $clk = intval($p['clicks'] ?? 0);
                $dis = intval($p['dismissals'] ?? 0);
                $all_stats[] = [
                    'id'           => $pid,
                    'title'        => $p['title'] ?: mb_substr($p['content'], 0, 40),
                    'impressions'  => $imp,
                    'clicks'       => $clk,
                    'ctr'          => $imp > 0 ? round(($clk / $imp) * 100, 1) . '%' : '0%',
                    'dismiss_rate' => $imp > 0 ? round(($dis / $imp) * 100, 1) . '%' : '0%',
                ];
            }
            return wpilot_ok(count($all_stats) . ' popup(s) stats.', ['stats' => $all_stats]);


        // ═════════════════════════════════════════════════════════
        //  2. AI CONTENT WRITER
        // ═════════════════════════════════════════════════════════

        // ── ai_write_content ─────────────────────────────────────
        case 'ai_write_content':
            $topic    = sanitize_text_field($params['topic'] ?? '');
            $type     = sanitize_key($params['type'] ?? 'blog-post');
            $tone     = sanitize_text_field($params['tone'] ?? '');
            $length   = sanitize_key($params['length'] ?? 'medium');
            $keywords = sanitize_text_field($params['keywords'] ?? '');
            $language = sanitize_text_field($params['language'] ?? 'English');

            if (empty($topic)) return wpilot_err('Topic is required.');

            $allowed_types = ['blog-post', 'product-description', 'landing-page', 'email', 'social-post', 'faq', 'testimonial'];
            if (!in_array($type, $allowed_types)) $type = 'blog-post';
            if (!in_array($length, ['short', 'medium', 'long'])) $length = 'medium';

            $profile = function_exists('wpilot_get_business_profile') ? wpilot_get_business_profile() : [];
            if (empty($tone) && !empty($profile['tone'])) $tone = $profile['tone'];
            if (empty($tone)) $tone = 'professional';

            $length_guide = ['short' => '150-300 words', 'medium' => '400-800 words', 'long' => '1000-2000 words'];

            $prompt = "Write a {$type} about: {$topic}\n\n";
            $prompt .= "Requirements:\n";
            $prompt .= "- Tone: {$tone}\n";
            $prompt .= "- Length: {$length_guide[$length]}\n";
            $prompt .= "- Language: {$language}\n";
            if ($keywords) $prompt .= "- SEO keywords to include naturally: {$keywords}\n";
            if (!empty($profile['name']))            $prompt .= "- Business: {$profile['name']}\n";
            if (!empty($profile['description']))     $prompt .= "- About: {$profile['description']}\n";
            if (!empty($profile['target_audience'])) $prompt .= "- Audience: {$profile['target_audience']}\n";
            if (!empty($profile['unique_selling']))  $prompt .= "- USP: {$profile['unique_selling']}\n";

            $prompt .= "\nReturn ONLY the content. No explanations, no meta-text. ";
            if ($type === 'blog-post') {
                $prompt .= "Use HTML formatting with h2/h3 headings, paragraphs, and lists where appropriate. Include a compelling introduction and conclusion.";
            } elseif ($type === 'email') {
                $prompt .= "Include subject line at top as 'Subject: ...', then the email body.";
            } elseif ($type === 'faq') {
                $prompt .= "Format as Q&A pairs using h3 for questions and p for answers.";
            }

            // Built by Weblease

            $result = wpilot_call_claude($prompt, 'chat', [], []);
            if (is_wp_error($result)) return wpilot_err('AI generation failed: ' . $result->get_error_message());

            function_exists("wpilot_log_activity") && wpilot_log_activity('ai_write_content', "Generated {$type}: {$topic}");
            return wpilot_ok("Content generated ({$type}, {$length}).", [
                'content'  => $result,
                'type'     => $type,
                'topic'    => $topic,
                'length'   => $length,
                'language' => $language,
            ]);

        // ── ai_rewrite ───────────────────────────────────────────
        case 'ai_rewrite':
            $content = $params['content'] ?? '';
            $goal    = sanitize_text_field($params['goal'] ?? 'more-professional');

            if (empty($content)) return wpilot_err('Content to rewrite is required.');

            $allowed_goals = ['shorter', 'longer', 'more-professional', 'more-casual', 'seo-optimized', 'simpler', 'more-persuasive'];
            $is_translate  = false;
            $target_lang   = '';

            if (strpos($goal, 'translate-to-') === 0) {
                $is_translate = true;
                $target_lang  = sanitize_text_field(str_replace('translate-to-', '', $goal));
            } elseif (!in_array($goal, $allowed_goals)) {
                $goal = 'more-professional';
            }

            if ($is_translate) {
                $prompt = "Translate the following content to {$target_lang}. Preserve all HTML formatting, tone, and meaning.\n\nContent:\n{$content}";
            } else {
                $goal_desc = [
                    'shorter'           => 'Make it shorter and more concise while keeping key points',
                    'longer'            => 'Expand it with more detail, examples, and depth',
                    'more-professional' => 'Rewrite in a more professional, polished tone',
                    'more-casual'       => 'Rewrite in a more casual, conversational tone',
                    'seo-optimized'     => 'Rewrite for better SEO: use natural keywords, improve readability, add semantic variations',
                    'simpler'           => 'Simplify the language for a wider audience (aim for 8th grade reading level)',
                    'more-persuasive'   => 'Rewrite to be more persuasive and compelling with strong CTAs',
                ];
                $prompt = "{$goal_desc[$goal]}.\n\nPreserve HTML formatting. Return ONLY the rewritten content.\n\nOriginal:\n{$content}";
            }

            $result = wpilot_call_claude($prompt, 'chat', [], []);
            if (is_wp_error($result)) return wpilot_err('Rewrite failed: ' . $result->get_error_message());

            function_exists("wpilot_log_activity") && wpilot_log_activity('ai_rewrite', "Rewrote content ({$goal})");
            return wpilot_ok("Content rewritten ({$goal}).", [
                'content'  => $result,
                'goal'     => $goal,
                'original_length' => mb_strlen(strip_tags($content)),
                'new_length'      => mb_strlen(strip_tags($result)),
            ]);

        // ── ai_generate_blog_series ──────────────────────────────
        case 'ai_generate_blog_series':
            $topic    = sanitize_text_field($params['topic'] ?? '');
            $count    = max(3, min(10, intval($params['count'] ?? 5)));
            $keywords = sanitize_text_field($params['keywords'] ?? '');

            if (empty($topic)) return wpilot_err('Topic is required for blog series.');

            $profile = function_exists('wpilot_get_business_profile') ? wpilot_get_business_profile() : [];
            $prompt  = "Create a blog series plan of exactly {$count} posts about: {$topic}\n\n";
            if ($keywords) $prompt .= "Target SEO keywords: {$keywords}\n";
            if (!empty($profile['name']))            $prompt .= "Business: {$profile['name']}\n";
            if (!empty($profile['target_audience'])) $prompt .= "Target audience: {$profile['target_audience']}\n";

            $prompt .= "\nFor each post return ONLY a valid JSON array of objects, each with:\n";
            $prompt .= '- "title": compelling blog post title' . "\n";
            $prompt .= '- "outline": array of 4-6 section headings' . "\n";
            $prompt .= '- "meta_description": SEO meta description (120-155 chars)' . "\n";
            $prompt .= "\nReturn ONLY the JSON array. No markdown, no code blocks, no explanation.";

            $result = wpilot_call_claude($prompt, 'chat', [], []);
            if (is_wp_error($result)) return wpilot_err('Blog series generation failed: ' . $result->get_error_message());

            $cleaned = trim($result);
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
            $series  = json_decode($cleaned, true);

            if (!is_array($series)) {
                return wpilot_ok('Blog series plan generated (raw text, could not parse JSON).', [
                    'raw'   => $result,
                    'count' => $count,
                    'topic' => $topic,
                ]);
            }

            function_exists("wpilot_log_activity") && wpilot_log_activity('ai_generate_blog_series', "Generated {$count}-post series on: {$topic}");
            return wpilot_ok("{$count} blog post outlines generated. Use ai_write_content to write each one.", [
                'series' => $series,
                'count'  => count($series),
                'topic'  => $topic,
            ]);

        // ── ai_product_descriptions ──────────────────────────────
        case 'ai_product_descriptions':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce is not active.');

            $product_id  = intval($params['product_id'] ?? 0);
            $product_ids = $params['product_ids'] ?? [];
            $auto_save   = ($params['auto_save'] ?? false) === true;

            if ($product_id && empty($product_ids)) $product_ids = [$product_id];
            if (is_string($product_ids)) $product_ids = array_map('intval', explode(',', $product_ids));
            $product_ids = array_filter(array_map('intval', (array) $product_ids));

            if (empty($product_ids)) return wpilot_err('Provide product_id or product_ids.');
            if (count($product_ids) > 20) return wpilot_err('Maximum 20 products at once.');

            $profile     = function_exists('wpilot_get_business_profile') ? wpilot_get_business_profile() : [];
            $tone        = $profile['tone'] ?? 'professional';
            $results     = [];

            foreach ($product_ids as $pid) {
                $product = wc_get_product($pid);
                if (!$product) {
                    $results[] = ['id' => $pid, 'error' => 'Product not found'];
                    continue;
                }

                $name       = $product->get_name();
                $price      = $product->get_price();
                $cats       = wp_get_post_terms($pid, 'product_cat', ['fields' => 'names']);
                $attributes = $product->get_attributes();
                $attr_text  = '';
                foreach ($attributes as $attr) {
                    if (is_a($attr, 'WC_Product_Attribute')) {
                        $attr_text .= $attr->get_name() . ': ' . implode(', ', $attr->get_options()) . '; ';
                    }
                }

                $prompt  = "Write a compelling product description for an e-commerce store.\n\n";
                $prompt .= "Product: {$name}\n";
                if ($price) $prompt .= "Price: {$price}\n";
                if (!empty($cats)) $prompt .= "Categories: " . implode(', ', $cats) . "\n";
                if ($attr_text) $prompt .= "Attributes: {$attr_text}\n";
                $prompt .= "Tone: {$tone}\n";
                if (!empty($profile['target_audience'])) $prompt .= "Target audience: {$profile['target_audience']}\n";
                $prompt .= "\nWrite 2-4 paragraphs. Use HTML (p, ul/li, strong). Focus on benefits, not just features. Include a subtle call-to-action. Return ONLY the description.";

                $desc = wpilot_call_claude($prompt, 'chat', [], []);
                if (is_wp_error($desc)) {
                    $results[] = ['id' => $pid, 'name' => $name, 'error' => $desc->get_error_message()];
                    continue;
                }

                if ($auto_save) {
                    $product->set_description($desc);
                    $product->save();
                }

                $results[] = [
                    'id'          => $pid,
                    'name'        => $name,
                    'description' => $desc,
                    'saved'       => $auto_save,
                ];
            }

            $generated = count(array_filter($results, function($r) { return !isset($r['error']); }));
            function_exists("wpilot_log_activity") && wpilot_log_activity('ai_product_descriptions', "Generated descriptions for {$generated} product(s)");
            return wpilot_ok("{$generated} product description(s) generated.", [
                'results'    => $results,
                'auto_saved' => $auto_save,
            ]);

        // ── ai_social_posts ──────────────────────────────────────
        case 'ai_social_posts':
            $content_url = esc_url_raw($params['content_url'] ?? '');
            $topic       = sanitize_text_field($params['topic'] ?? '');
            $platforms   = $params['platforms'] ?? ['instagram', 'facebook', 'twitter'];
            $count       = max(1, min(5, intval($params['count'] ?? 3)));

            if (empty($content_url) && empty($topic)) {
                return wpilot_err('Provide content_url or topic.');
            }

            if (is_string($platforms)) $platforms = array_map('trim', explode(',', $platforms));
            $allowed_platforms = ['instagram', 'facebook', 'twitter', 'linkedin', 'tiktok'];
            $platforms = array_intersect(array_map('sanitize_key', $platforms), $allowed_platforms);
            if (empty($platforms)) $platforms = ['instagram', 'facebook', 'twitter'];

            $profile = function_exists('wpilot_get_business_profile') ? wpilot_get_business_profile() : [];
            $tone    = $profile['tone'] ?? 'professional';
            $source  = $content_url ? "this URL: {$content_url}" : "this topic: {$topic}";

            $platform_rules = [
                'instagram' => 'Max 2200 chars, use emojis, 15-30 hashtags at the end, visual storytelling tone',
                'facebook'  => 'Max 500 chars ideal, conversational, 2-3 hashtags, include a question to boost engagement',
                'twitter'   => 'Max 280 chars, punchy and direct, 1-3 hashtags, use a hook',
                'linkedin'  => 'Professional tone, 150-300 words, thought leadership angle, 3-5 hashtags',
                'tiktok'    => 'Max 150 chars, trendy/playful tone, use trending hashtags, hook in first line',
            ];

            $prompt  = "Create {$count} social media post(s) for each platform based on {$source}.\n\n";
            if (!empty($profile['name'])) $prompt .= "Brand: {$profile['name']}\n";
            $prompt .= "Overall tone: {$tone}\n\n";
            $prompt .= "Platforms and rules:\n";
            foreach ($platforms as $p) {
                $prompt .= "- {$p}: {$platform_rules[$p]}\n";
            }
            $prompt .= "\nReturn ONLY a valid JSON object where keys are platform names and values are arrays of post strings.\n";
            $prompt .= 'Example: {"instagram":["post 1","post 2"],"twitter":["post 1"]}' . "\n";
            $prompt .= "No markdown, no code blocks, no explanation.";

            $result = wpilot_call_claude($prompt, 'chat', [], []);
            if (is_wp_error($result)) return wpilot_err('Social post generation failed: ' . $result->get_error_message());

            $cleaned = trim($result);
            $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
            $cleaned = preg_replace('/\s*```$/', '', $cleaned);
            $posts   = json_decode($cleaned, true);

            if (!is_array($posts)) {
                return wpilot_ok('Social posts generated (raw text).', ['raw' => $result, 'platforms' => $platforms]);
            }

            $total = 0;
            foreach ($posts as $plat => $items) $total += count($items);

            function_exists("wpilot_log_activity") && wpilot_log_activity('ai_social_posts', "Generated {$total} social post(s) across " . count($platforms) . " platform(s)");
            return wpilot_ok("{$total} social media post(s) generated.", [
                'posts'     => $posts,
                'platforms' => $platforms,
                'count'     => $total,
            ]);


        // ═════════════════════════════════════════════════════════
        //  3. QR CODE GENERATOR
        // ═════════════════════════════════════════════════════════

        // ── create_qr_code ───────────────────────────────────────
        case 'create_qr_code':
            $data      = $params['data'] ?? '';
            $size      = max(100, min(1000, intval($params['size'] ?? 300)));
            $format    = sanitize_key($params['format'] ?? 'png');
            $fg_color  = sanitize_hex_color($params['fg_color'] ?? '') ?: '000000';
            $bg_color  = sanitize_hex_color($params['bg_color'] ?? '') ?: 'FFFFFF';
            $save      = ($params['save_to_library'] ?? false) === true;

            if (empty($data)) return wpilot_err('QR code data is required (URL, text, vCard, or WiFi config).');
            if (!in_array($format, ['png', 'svg'])) $format = 'png';

            $fg_color = ltrim($fg_color, '#');
            $bg_color = ltrim($bg_color, '#');

            $encoded  = urlencode($data);
            $qr_url   = "https://chart.googleapis.com/chart?cht=qr&chs={$size}x{$size}&chl={$encoded}&chco={$fg_color}&chf=bg,s,{$bg_color}";

            $extra = ['qr_url' => $qr_url, 'data' => $data, 'size' => $size];

            if ($save) {
                $tmp = download_url($qr_url, 15);
                if (is_wp_error($tmp)) {
                    return wpilot_ok("QR code URL generated but could not save to media library.", $extra);
                }

                $file_array = [
                    'name'     => 'qr-code-' . wp_generate_password(6, false) . '.png',
                    'tmp_name' => $tmp,
                ];
                $attachment_id = media_handle_sideload($file_array, 0, 'QR Code: ' . mb_substr($data, 0, 80));
                if (is_wp_error($attachment_id)) {
                    @unlink($tmp);
                    return wpilot_ok("QR code URL generated but media upload failed.", $extra);
                }

                $extra['attachment_id']  = $attachment_id;
                $extra['attachment_url'] = wp_get_attachment_url($attachment_id);
            }

            function_exists("wpilot_log_activity") && wpilot_log_activity('create_qr_code', 'QR code generated', mb_substr($data, 0, 100));
            return wpilot_ok('QR code generated.', $extra);

        // ── create_qr_page ───────────────────────────────────────
        case 'create_qr_page':
            $url         = esc_url_raw($params['url'] ?? '');
            $page_title  = sanitize_text_field($params['title'] ?? 'QR Code');
            $description = sanitize_text_field($params['description'] ?? '');

            if (empty($url)) return wpilot_err('URL for the QR code is required.');

            $size     = 400;
            $encoded  = urlencode($url);
            $qr_url   = "https://chart.googleapis.com/chart?cht=qr&chs={$size}x{$size}&chl={$encoded}";

            $page_content  = '<div style="text-align:center;padding:40px 20px;max-width:600px;margin:0 auto;">';
            $page_content .= '<h2 style="font-size:1.8em;margin-bottom:16px;color:var(--wp-text,#222);">' . esc_html($page_title) . '</h2>';
            if ($description) {
                $page_content .= '<p style="font-size:1.1em;color:var(--wp-text,#555);margin-bottom:24px;">' . esc_html($description) . '</p>';
            }
            $page_content .= '<img src="' . esc_url($qr_url) . '" alt="QR Code" style="max-width:100%;height:auto;border-radius:var(--wp-radius,8px);box-shadow:0 4px 20px rgba(0,0,0,0.1);" />';
            $page_content .= '<p style="margin-top:16px;font-size:0.9em;color:var(--wp-text,#888);">Scan the code or visit: <a href="' . esc_url($url) . '">' . esc_html($url) . '</a></p>';
            $page_content .= '</div>';

            $post_id = wp_insert_post([
                'post_title'   => $page_title,
                'post_content' => $page_content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ], true);

            if (is_wp_error($post_id)) return wpilot_err('Failed to create QR page: ' . $post_id->get_error_message());

            function_exists("wpilot_log_activity") && wpilot_log_activity('create_qr_page', "QR page created: {$page_title}", get_permalink($post_id));
            return wpilot_ok("QR code page \"{$page_title}\" created.", [
                'page_id'  => $post_id,
                'page_url' => get_permalink($post_id),
                'qr_url'   => $qr_url,
            ]);


        // ═════════════════════════════════════════════════════════
        //  4. SCHEDULING
        // ═════════════════════════════════════════════════════════

        // ── schedule_content ─────────────────────────────────────
        case 'schedule_content':
            $action      = sanitize_key($params['action'] ?? '');
            $schedule_at = sanitize_text_field($params['schedule_at'] ?? '');
            $end_at      = sanitize_text_field($params['end_at'] ?? '');

            if (empty($action)) return wpilot_err('Action is required (show_popup, hide_popup, publish_post, start_sale, end_sale, enable_maintenance, disable_maintenance).');
            if (empty($schedule_at)) return wpilot_err('schedule_at datetime is required (YYYY-MM-DD HH:MM).');

            $allowed_actions = ['show_popup', 'hide_popup', 'publish_post', 'start_sale', 'end_sale', 'enable_maintenance', 'disable_maintenance'];
            if (!in_array($action, $allowed_actions)) {
                return wpilot_err("Invalid action. Allowed: " . implode(', ', $allowed_actions));
            }

            $timestamp = strtotime($schedule_at);
            if (!$timestamp || $timestamp < time()) {
                return wpilot_err('schedule_at must be a valid future datetime.');
            }

            $schedule_id = 'sched-' . wp_generate_password(8, false);
            $scheduled   = get_option('wpilot_scheduled_actions', []);

            $scheduled[$schedule_id] = [
                'id'          => $schedule_id,
                'action'      => $action,
                'schedule_at' => $schedule_at,
                'end_at'      => $end_at,
                'params'      => array_diff_key($params, array_flip(['action', 'schedule_at', 'end_at'])),
                'status'      => 'pending',
                'created_at'  => current_time('mysql'),
            ];
            update_option('wpilot_scheduled_actions', $scheduled, false);

            $hook = 'wpilot_scheduled_' . $schedule_id;
            wp_schedule_single_event($timestamp, $hook);
            add_action($hook, function() use ($schedule_id) {
                wpilot_execute_scheduled_action($schedule_id);
            });

            if ($end_at) {
                $end_timestamp = strtotime($end_at);
                if ($end_timestamp && $end_timestamp > $timestamp) {
                    $end_hook = 'wpilot_scheduled_end_' . $schedule_id;
                    wp_schedule_single_event($end_timestamp, $end_hook);
                    add_action($end_hook, function() use ($schedule_id) {
                        wpilot_execute_scheduled_end($schedule_id);
                    });
                }
            }

            function_exists("wpilot_log_activity") && wpilot_log_activity('schedule_content', "Scheduled {$action} at {$schedule_at}", $schedule_id);
            $msg = "Action \"{$action}\" scheduled for {$schedule_at}";
            if ($end_at) $msg .= ", ends at {$end_at}";
            return wpilot_ok($msg . '.', ['schedule_id' => $schedule_id]);

        // ── list_scheduled ───────────────────────────────────────
        case 'list_scheduled':
            $scheduled = get_option('wpilot_scheduled_actions', []);
            if (empty($scheduled)) return wpilot_ok('No scheduled actions.', ['actions' => []]);

            $list = [];
            foreach ($scheduled as $s) {
                $list[] = [
                    'id'          => $s['id'],
                    'action'      => $s['action'],
                    'schedule_at' => $s['schedule_at'],
                    'end_at'      => $s['end_at'] ?? '',
                    'status'      => $s['status'],
                    'created_at'  => $s['created_at'],
                ];
            }
            return wpilot_ok(count($list) . ' scheduled action(s).', ['actions' => $list]);

        default:
            return null;
    }
}


// ═══════════════════════════════════════════════════════════════
//  POPUP / BANNER — MU-PLUGIN CODE BUILDER
//  Generates the frontend JS+CSS injected via wpilot-site.php
//  Content is sanitized with wp_kses_post on save and escaped
//  with JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT on output
// ═══════════════════════════════════════════════════════════════

function wpilot_build_popup_mu_code($popups) {
    $active = array_filter($popups, function($p) { return !empty($p['active']); });
    if (empty($active)) return '/* no active popups */';

    // The mu-plugin code reads from wp_options at runtime so popups
    // are always current without regenerating the mu file for data changes.
    $code = <<<'PHPMU'
add_action('wp_footer', function() {
    if (is_admin()) return;
    $popups_data = get_option('wpilot_popups', []);
    if (empty($popups_data)) return;

    $now = current_time('timestamp');
    $active = [];
    foreach ($popups_data as $id => $p) {
        if (empty($p['active'])) continue;
        if (!empty($p['schedule_start']) && strtotime($p['schedule_start']) > $now) continue;
        if (!empty($p['schedule_end']) && strtotime($p['schedule_end']) < $now) continue;
        $active[$id] = $p;
    }
    if (empty($active)) return;

    $page_id = get_the_ID();
    $is_home = is_front_page() || is_home();
    $is_shop = function_exists('is_shop') && is_shop();

    $show = [];
    foreach ($active as $id => $p) {
        $rule = $p['show_on'] ?? 'all';
        if ($rule === 'all') { $show[$id] = $p; continue; }
        if ($rule === 'homepage' && $is_home) { $show[$id] = $p; continue; }
        if ($rule === 'shop' && $is_shop) { $show[$id] = $p; continue; }
        if (is_numeric($rule) && intval($rule) === intval($page_id)) { $show[$id] = $p; continue; }
    }
    if (empty($show)) return;

    $json = wp_json_encode($show, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $stats_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('wpilot_popup_stat');
    ?>
    <style>
    .wpi-popup-overlay{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);opacity:0;transition:opacity .3s ease;pointer-events:none}
    .wpi-popup-overlay.wpi-active{opacity:1;pointer-events:auto}
    .wpi-popup-box{position:relative;background:var(--wp-bg,#fff);color:var(--wp-text,#222);border-radius:var(--wp-radius,12px);padding:32px;max-width:520px;width:90%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);transform:translateY(20px) scale(.95);transition:transform .3s ease}
    .wpi-popup-overlay.wpi-active .wpi-popup-box{transform:translateY(0) scale(1)}
    .wpi-popup-close{position:absolute;top:12px;right:14px;background:none;border:none;font-size:22px;cursor:pointer;color:var(--wp-text,#666);line-height:1;padding:4px 8px;z-index:2}
    .wpi-popup-close:hover{color:var(--wp-primary,#333)}
    .wpi-popup-title{font-size:1.5em;font-weight:700;margin:0 0 12px;color:var(--wp-text,#111)}
    .wpi-popup-content{font-size:1em;line-height:1.6;margin-bottom:20px}
    .wpi-popup-cta{display:inline-block;background:var(--wp-primary,#2563eb);color:#fff;padding:12px 28px;border-radius:var(--wp-radius,8px);text-decoration:none;font-weight:600;font-size:1em;transition:opacity .2s}
    .wpi-popup-cta:hover{opacity:.85;color:#fff}
    .wpi-popup-overlay.wpi-fullscreen .wpi-popup-box{max-width:100%;width:100%;height:100vh;border-radius:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
    .wpi-popup-overlay.wpi-slide-in{align-items:flex-end;justify-content:flex-end;background:transparent;backdrop-filter:none;pointer-events:none}
    .wpi-popup-overlay.wpi-slide-in.wpi-active{pointer-events:auto}
    .wpi-popup-overlay.wpi-slide-in .wpi-popup-box{max-width:380px;margin:20px;transform:translateX(120%);box-shadow:0 8px 30px rgba(0,0,0,.15)}
    .wpi-popup-overlay.wpi-slide-in.wpi-active .wpi-popup-box{transform:translateX(0)}
    .wpi-bar{position:fixed;left:0;right:0;z-index:999998;display:flex;align-items:center;justify-content:center;gap:16px;padding:14px 24px;font-size:.95em;background:var(--wp-primary,#2563eb);color:#fff;transform:translateY(-100%);transition:transform .4s ease;flex-wrap:wrap}
    .wpi-bar.wpi-bottom{top:auto;bottom:0;transform:translateY(100%)}
    .wpi-bar.wpi-active{transform:translateY(0)}
    .wpi-bar .wpi-popup-close{position:static;color:#fff;font-size:18px;margin-left:8px}
    .wpi-bar .wpi-popup-cta{background:#fff;color:var(--wp-primary,#2563eb);padding:8px 20px;font-size:.9em}
    .wpi-bar .wpi-countdown{font-weight:700;font-variant-numeric:tabular-nums}
    .wpi-style-bold .wpi-popup-title{font-size:2em}
    .wpi-style-bold .wpi-popup-cta{padding:16px 36px;font-size:1.1em;text-transform:uppercase;letter-spacing:1px}
    .wpi-style-branded .wpi-popup-box{background-size:cover;background-position:center;color:#fff}
    .wpi-style-branded .wpi-popup-title,.wpi-style-branded .wpi-popup-content{text-shadow:0 2px 8px rgba(0,0,0,.5)}
    @media(max-width:600px){.wpi-popup-box{padding:24px 18px;max-width:95%}.wpi-popup-overlay.wpi-slide-in .wpi-popup-box{max-width:90%;margin:10px}.wpi-bar{flex-direction:column;gap:8px;padding:12px 16px;text-align:center}}
    </style>
    <script>
    (function(){
        var popups=<?php echo $json; ?>;
        var ajaxUrl=<?php echo wp_json_encode($stats_url); ?>;
        var nonce=<?php echo wp_json_encode($nonce); ?>;

        function trackStat(id,type){
            var fd=new FormData();
            fd.append('action','wpilot_popup_stat');
            fd.append('nonce',nonce);
            fd.append('popup_id',id);
            fd.append('stat_type',type);
            if(navigator.sendBeacon){navigator.sendBeacon(ajaxUrl,fd);}
            else{fetch(ajaxUrl,{method:'POST',body:fd,keepalive:true});}
        }

        function isDismissed(id,days){
            if(!days)return false;
            var k='wpi_popup_'+id;
            try{var ts=localStorage.getItem(k);if(!ts)return false;return(Date.now()-parseInt(ts,10))<days*864e5;}
            catch(e){return false;}
        }

        function dismiss(id,days){
            try{if(days)localStorage.setItem('wpi_popup_'+id,Date.now());}catch(e){}
            trackStat(id,'dismiss');
        }

        function buildCountdown(endDate){
            var el=document.createElement('span');
            el.className='wpi-countdown';
            function tick(){
                var diff=new Date(endDate)-new Date();
                if(diff<=0){el.textContent='Expired';return;}
                var d=Math.floor(diff/864e5),h=Math.floor((diff%864e5)/36e5),m=Math.floor((diff%36e5)/6e4),s=Math.floor((diff%6e4)/1e3);
                var parts=[];
                if(d>0)parts.push(d+'d');
                parts.push(h+'h',m+'m',s+'s');
                el.textContent=parts.join(' ');
                setTimeout(tick,1000);
            }
            tick();
            return el;
        }

        function renderContent(container, html) {
            var temp = document.createElement('template');
            temp.innerHTML = html;
            var frag = temp.content;
            container.appendChild(frag);
        }

        Object.keys(popups).forEach(function(id){
            var p=popups[id];
            var days=parseInt(p.dismiss_days||7,10);
            if(isDismissed(id,days))return;

            var type=p.type||'modal';
            var isBar=type==='top-bar'||type==='bottom-bar';

            if(isBar){
                var bar=document.createElement('div');
                bar.className='wpi-bar'+(type==='bottom-bar'?' wpi-bottom':'');
                if(p.bg_color)bar.style.background=p.bg_color;

                var txt=document.createElement('span');
                txt.textContent=p.content||p.title||'';
                bar.appendChild(txt);

                if(p.countdown_to){
                    bar.appendChild(document.createTextNode(' '));
                    bar.appendChild(buildCountdown(p.countdown_to));
                }

                if(p.cta_text&&p.cta_url){
                    var cta=document.createElement('a');
                    cta.className='wpi-popup-cta';
                    cta.href=p.cta_url;
                    cta.textContent=p.cta_text;
                    cta.addEventListener('click',function(){trackStat(id,'click');});
                    bar.appendChild(cta);
                }

                if(p.dismissible!==false){
                    var cls=document.createElement('button');
                    cls.className='wpi-popup-close';
                    cls.textContent='\u00D7';
                    cls.setAttribute('aria-label','Close');
                    cls.addEventListener('click',function(){
                        bar.classList.remove('wpi-active');
                        dismiss(id,days);
                        setTimeout(function(){bar.remove();},400);
                    });
                    bar.appendChild(cls);
                }

                document.body.appendChild(bar);
                requestAnimationFrame(function(){bar.classList.add('wpi-active');});
                trackStat(id,'impression');
                return;
            }

            var overlay=document.createElement('div');
            overlay.className='wpi-popup-overlay';
            if(type==='fullscreen')overlay.classList.add('wpi-fullscreen');
            if(type==='slide-in')overlay.classList.add('wpi-slide-in');

            var box=document.createElement('div');
            box.className='wpi-popup-box';
            if(p.style==='bold')overlay.classList.add('wpi-style-bold');
            if(p.style==='branded'&&p.background_image){
                overlay.classList.add('wpi-style-branded');
                box.style.backgroundImage='url('+p.background_image+')';
            }

            var close=document.createElement('button');
            close.className='wpi-popup-close';
            close.textContent='\u00D7';
            close.setAttribute('aria-label','Close popup');
            box.appendChild(close);

            if(p.title){
                var ttl=document.createElement('div');
                ttl.className='wpi-popup-title';
                ttl.textContent=p.title;
                box.appendChild(ttl);
            }
            if(p.content){
                var cnt=document.createElement('div');
                cnt.className='wpi-popup-content';
                renderContent(cnt, p.content);
                box.appendChild(cnt);
            }
            if(p.cta_text&&p.cta_url){
                var cta=document.createElement('a');
                cta.className='wpi-popup-cta';
                cta.href=p.cta_url;
                cta.textContent=p.cta_text;
                cta.addEventListener('click',function(){trackStat(id,'click');});
                box.appendChild(cta);
            }

            overlay.appendChild(box);

            function showPopup(){
                document.body.appendChild(overlay);
                requestAnimationFrame(function(){overlay.classList.add('wpi-active');});
                trackStat(id,'impression');
            }
            function hidePopup(){
                overlay.classList.remove('wpi-active');
                dismiss(id,days);
                setTimeout(function(){overlay.remove();},400);
            }

            close.addEventListener('click',hidePopup);
            overlay.addEventListener('click',function(e){
                if(e.target===overlay&&type!=='slide-in')hidePopup();
            });

            var trigger=p.trigger||'load';
            if(trigger==='load'){
                showPopup();
            }else if(trigger==='exit-intent'){
                var fired=false;
                document.addEventListener('mouseleave',function(e){
                    if(e.clientY<0&&!fired){fired=true;showPopup();}
                });
            }else if(trigger==='scroll-50'){
                var scrollFired=false;
                var observer=new IntersectionObserver(function(entries){
                    entries.forEach(function(entry){
                        if(entry.isIntersecting&&!scrollFired){
                            scrollFired=true;showPopup();observer.disconnect();
                        }
                    });
                },{threshold:0});
                var sentinel=document.createElement('div');
                sentinel.style.cssText='height:1px;width:1px;position:absolute;top:50%;left:0;pointer-events:none';
                document.body.appendChild(sentinel);
                observer.observe(sentinel);
            }else if(trigger==='delay-5s'){
                setTimeout(showPopup,5000);
            }else if(trigger==='click'){
                document.addEventListener('click',function(e){
                    var el=e.target.closest('[data-popup="'+id+'"]');
                    if(el){e.preventDefault();showPopup();}
                });
            }
        });
    })();
    </script>
    <?php
}, 999);
PHPMU;

    return $code;
}


// ═══════════════════════════════════════════════════════════════
//  POPUP STATS — AJAX HANDLER
// ═══════════════════════════════════════════════════════════════

add_action('wp_ajax_wpilot_popup_stat', 'wpilot_handle_popup_stat');
add_action('wp_ajax_nopriv_wpilot_popup_stat', 'wpilot_handle_popup_stat');

function wpilot_handle_popup_stat() {
    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wpilot_popup_stat')) {
        wp_die('Invalid nonce', 403);
    }

    $popup_id  = sanitize_key($_POST['popup_id'] ?? '');
    $stat_type = sanitize_key($_POST['stat_type'] ?? '');
    if (empty($popup_id) || !in_array($stat_type, ['impression', 'click', 'dismiss'])) {
        wp_die('Bad request', 400);
    }

    $popups = get_option('wpilot_popups', []);
    if (!isset($popups[$popup_id])) wp_die('Not found', 404);

    $key_map = ['impression' => 'impressions', 'click' => 'clicks', 'dismiss' => 'dismissals'];
    $field   = $key_map[$stat_type];

    $popups[$popup_id][$field] = intval($popups[$popup_id][$field] ?? 0) + 1;
    update_option('wpilot_popups', $popups, false);

    wp_die('ok');
}

// Built by Weblease

// ═══════════════════════════════════════════════════════════════
//  SCHEDULED ACTIONS — EXECUTION ENGINE
// ═══════════════════════════════════════════════════════════════

function wpilot_execute_scheduled_action($schedule_id) {
    $scheduled = get_option('wpilot_scheduled_actions', []);
    if (!isset($scheduled[$schedule_id])) return;

    $item   = $scheduled[$schedule_id];
    $action = $item['action'];
    $params = $item['params'] ?? [];

    switch ($action) {
        case 'show_popup':
            $pid    = sanitize_key($params['popup_id'] ?? $params['id'] ?? '');
            $popups = get_option('wpilot_popups', []);
            if (isset($popups[$pid])) {
                $popups[$pid]['active'] = true;
                update_option('wpilot_popups', $popups, false);
                wpilot_mu_register('popups', wpilot_build_popup_mu_code($popups));
            }
            break;

        case 'hide_popup':
            $pid    = sanitize_key($params['popup_id'] ?? $params['id'] ?? '');
            $popups = get_option('wpilot_popups', []);
            if (isset($popups[$pid])) {
                $popups[$pid]['active'] = false;
                update_option('wpilot_popups', $popups, false);
                wpilot_mu_register('popups', wpilot_build_popup_mu_code($popups));
            }
            break;

        case 'publish_post':
            $post_id = intval($params['post_id'] ?? $params['id'] ?? 0);
            if ($post_id) {
                wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
            }
            break;

        case 'start_sale':
            if (class_exists('WooCommerce')) {
                $product_ids = $params['product_ids'] ?? [];
                $discount    = $params['discount'] ?? '';
                if (is_string($product_ids)) $product_ids = array_map('intval', explode(',', $product_ids));
                foreach ($product_ids as $pid) {
                    $product = wc_get_product($pid);
                    if (!$product) continue;
                    $regular = $product->get_regular_price();
                    if (!$regular) continue;
                    if (strpos($discount, '%') !== false) {
                        $pct  = floatval($discount) / 100;
                        $sale = round($regular * (1 - $pct), 2);
                    } else {
                        $sale = floatval($discount);
                    }
                    $product->set_sale_price($sale);
                    $product->save();
                }
            }
            break;

        case 'end_sale':
            if (class_exists('WooCommerce')) {
                $product_ids = $params['product_ids'] ?? [];
                if (is_string($product_ids)) $product_ids = array_map('intval', explode(',', $product_ids));
                foreach ($product_ids as $pid) {
                    $product = wc_get_product($pid);
                    if (!$product) continue;
                    $product->set_sale_price('');
                    $product->set_date_on_sale_from('');
                    $product->set_date_on_sale_to('');
                    $product->save();
                }
            }
            break;

        case 'enable_maintenance':
            update_option('wpilot_maintenance_mode', '1');
            break;

        case 'disable_maintenance':
            delete_option('wpilot_maintenance_mode');
            break;
    }

    $scheduled[$schedule_id]['status'] = 'completed';
    $scheduled[$schedule_id]['executed_at'] = current_time('mysql');
    update_option('wpilot_scheduled_actions', $scheduled, false);

    function_exists("wpilot_log_activity") && wpilot_log_activity('scheduled_action', "Executed: {$action}", $schedule_id);
}

function wpilot_execute_scheduled_end($schedule_id) {
    $scheduled = get_option('wpilot_scheduled_actions', []);
    if (!isset($scheduled[$schedule_id])) return;

    $item   = $scheduled[$schedule_id];
    $action = $item['action'];

    $reverse = [
        'show_popup'          => 'hide_popup',
        'start_sale'          => 'end_sale',
        'enable_maintenance'  => 'disable_maintenance',
    ];

    if (isset($reverse[$action])) {
        $end_item = $item;
        $end_item['action'] = $reverse[$action];
        $scheduled['end-' . $schedule_id] = $end_item;
        update_option('wpilot_scheduled_actions', $scheduled, false);
        wpilot_execute_scheduled_action('end-' . $schedule_id);
    }
}

// Register scheduled action hooks on init
add_action('init', function() {
    $scheduled = get_option('wpilot_scheduled_actions', []);
    foreach ($scheduled as $id => $item) {
        if (($item['status'] ?? '') !== 'pending') continue;

        $hook = 'wpilot_scheduled_' . $id;
        add_action($hook, function() use ($id) {
            wpilot_execute_scheduled_action($id);
        });

        $end_hook = 'wpilot_scheduled_end_' . $id;
        add_action($end_hook, function() use ($id) {
            wpilot_execute_scheduled_end($id);
        });
    }
});
