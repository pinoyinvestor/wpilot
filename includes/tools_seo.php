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
 * WPilot Seo Tools Module
 * Contains 28 tool cases for seo operations.
 */
function wpilot_run_seo_tools($tool, $params = []) {
    switch ($tool) {

        case 'update_meta_desc':
            $id   = intval( $params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0 );
            $desc = sanitize_text_field( $params['desc'] ?? '' );
            if ( !$id ) return wpilot_err('Post/page ID required.');
            update_post_meta($id,'_yoast_wpseo_metadesc', $desc);
            update_post_meta($id,'_aioseo_description',   $desc);
            update_post_meta($id,'rank_math_description',  $desc);
            return wpilot_ok( "Meta description updated for #{$id}." );

        case 'update_seo_title':
            $id    = intval( $params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0 );
            if (!$id) return wpilot_err('Post/page ID required.');
            $title = sanitize_text_field( $params['title'] ?? '' );
            update_post_meta($id,'_yoast_wpseo_title', $title);
            update_post_meta($id,'rank_math_title',    $title);
            return wpilot_ok( "SEO title updated." );

        case 'update_focus_keyword':
            $id = intval( $params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0 );
            if (!$id) return wpilot_err('Post/page ID required.');
            $kw = sanitize_text_field( $params['keyword'] ?? '' );
            update_post_meta($id,'_yoast_wpseo_focuskw',    $kw);
            update_post_meta($id,'rank_math_focus_keyword', $kw);
            return wpilot_ok( "Focus keyword set to \"{$kw}\"." );

        /* ── CSS ────────────────────────────────────────────── */
        case 'fix_performance':
            return wpilot_fix_performance($params);

        case 'fix_render_blocking':
            return wpilot_fix_render_blocking($params);

        case 'enable_lazy_load':
            return wpilot_enable_lazy_load($params);

        case 'optimize_database':
            $params['dry_run'] = false;
            return wpilot_database_cleanup($params);

        case 'add_image_dimensions':
            return wpilot_add_image_dimensions($params);

        case 'minify_assets':
            return wpilot_minify_assets($params);

        case 'pagespeed_test':
            return wpilot_pagespeed_test($params);

        case 'seo_audit':
            return wpilot_seo_audit();

        case 'create_robots_txt':
            $custom = sanitize_textarea_field($params['content'] ?? '');
            if (empty($custom)) {
                $site_url = get_site_url();
                $custom = "User-agent: *\nAllow: /\n\nDisallow: /wp-admin/\nDisallow: /wp-includes/\nDisallow: /wp-login.php\nDisallow: /xmlrpc.php\nDisallow: /?s=\nDisallow: /search/\nDisallow: /cart/\nDisallow: /checkout/\nDisallow: /my-account/\n\nSitemap: {$site_url}/sitemap.xml\nSitemap: {$site_url}/sitemap_index.xml";
            }
            // WordPress filters robots.txt dynamically, but we can save a custom version
            update_option('wpi_custom_robots_txt', $custom);
            return wpilot_ok("robots.txt rules saved. These will be applied via WordPress filter.");

        case 'fix_heading_structure':
            $id = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            if (!$id) return wpilot_err('Page/post ID required.');
            $post = get_post($id);
            if (!$post) return wpilot_err("Post #{$id} not found.");
            wpilot_save_post_snapshot($id);
            $content = $post->post_content;
            // Fix common heading issues: multiple H1s → H2, empty headings
            $h1_count = preg_match_all('/<h1[^>]*>/i', $content);
            if ($h1_count > 1) {
                // Keep first H1, convert rest to H2
                $first = true;
                $content = preg_replace_callback('/<h1([^>]*)>(.*?)<\/h1>/is', function($m) use (&$first) {
                    if ($first) { $first = false; return $m[0]; }
                    return "<h2{$m[1]}>{$m[2]}</h2>";
                }, $content);
            }
            // Remove empty headings
            $content = preg_replace('/<h[1-6][^>]*>\s*<\/h[1-6]>/i', '', $content);
            wp_update_post(['ID'=>$id, 'post_content'=>$content]);
            $fixes = [];
            if ($h1_count > 1) $fixes[] = "Fixed {$h1_count} H1 tags → kept 1, converted rest to H2";
            return wpilot_ok("Heading structure fixed for #{$id}. " . implode('. ', $fixes));

        case 'add_schema_markup':
            $id     = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            $type   = sanitize_text_field($params['schema_type'] ?? 'Article');
            $data   = $params['schema_data'] ?? [];
            if (!$id) return wpilot_err('Page/post ID required.');
            $schema = [
                '@context' => 'https://schema.org',
                '@type'    => $type,
            ];
            $schema = array_merge($schema, array_map('sanitize_text_field', (array)$data));
            update_post_meta($id, '_wpi_schema_markup', wp_json_encode($schema));
            return wpilot_ok("Schema markup ({$type}) added to #{$id}.");

        case 'bulk_fix_seo':
            return wpilot_bulk_fix_seo();

// Built by Weblease
        case 'set_open_graph':
            $id    = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            $title = sanitize_text_field($params['og_title'] ?? '');
            $desc  = sanitize_text_field($params['og_description'] ?? '');
            $image = esc_url_raw($params['og_image'] ?? '');
            if (!$id) return wpilot_err('Page/post ID required.');
            if ($title) update_post_meta($id, '_wpi_og_title', $title);
            if ($desc)  update_post_meta($id, '_wpi_og_description', $desc);
            if ($image) update_post_meta($id, '_wpi_og_image', $image);
            return wpilot_ok("Open Graph tags set for #{$id}.");

        /* ── Site Health / Speed ─────────────────────────────── */
        case 'site_health_check':
            return wpilot_site_health_check();

        /* ── WooCommerce Dashboard ──────────────────────────── */
        case 'add_open_graph':
        case 'setup_social_sharing':
            $title = sanitize_text_field($params['title'] ?? get_bloginfo('name'));
            $description = sanitize_text_field($params['description'] ?? get_bloginfo('description'));
            $image = esc_url($params['image'] ?? '');
            $type = sanitize_text_field($params['type'] ?? 'website');
            $og_tags = "<meta property=\"og:title\" content=\"{$title}\">\n<meta property=\"og:description\" content=\"{$description}\">\n<meta property=\"og:type\" content=\"{$type}\">\n<meta property=\"og:url\" content=\"" . get_site_url() . "\">\n";
            if ($image) $og_tags .= "<meta property=\"og:image\" content=\"{$image}\">\n";
            $og_tags .= "<meta name=\"twitter:card\" content=\"summary_large_image\">\n<meta name=\"twitter:title\" content=\"{$title}\">\n<meta name=\"twitter:description\" content=\"{$description}\">\n";
            if ($image) $og_tags .= "<meta name=\"twitter:image\" content=\"{$image}\">\n";
            $head = get_option('wpilot_head_code', '');
            $head = preg_replace('/<!-- OG Tags -->.*?<!-- \/OG -->/s', '', $head);
            $head .= "\n<!-- OG Tags -->\n" . $og_tags . "<!-- /OG -->";
            update_option('wpilot_head_code', $head);
            return wpilot_ok("Open Graph + Twitter Cards configured.", ['title' => $title, 'description' => $description]);

        // ═══ TIKTOK PIXEL ═══
        case 'research_url':
        case 'scrape_url':
        case 'fetch_url':
            $url = $params['url'] ?? '';
            if (empty($url)) return wpilot_err('URL required.');
            $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => false, 'user-agent' => 'Mozilla/5.0 (compatible; WPilot/2.0)']);
            if (is_wp_error($response)) return wpilot_err('Fetch failed: ' . $response->get_error_message());
            $html = wp_remote_retrieve_body($response);
            $code = wp_remote_retrieve_response_code($response);
            $data = ['url' => $url, 'status' => $code, 'size_kb' => round(strlen($html) / 1024)];
            if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) $data['title'] = trim($m[1]);
            if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)/i', $html, $m)) $data['meta_description'] = $m[1];
            $headings = [];
            if (preg_match_all('/<h([1-3])[^>]*>([^<]+)<\/h\1>/i', $html, $hm)) {
                foreach ($hm[2] as $i => $t) $headings[] = 'h' . $hm[1][$i] . ': ' . trim($t);
            }
            $data['headings'] = array_slice($headings, 0, 15);
            preg_match_all('/<img[^>]+>/i', $html, $imgs);
            $data['images'] = count($imgs[0]);
            $techs = [];
            $tech_map = ['wp-content' => 'WordPress', 'shopify' => 'Shopify', 'woocommerce' => 'WooCommerce', 'elementor' => 'Elementor', 'divi' => 'Divi', 'squarespace' => 'Squarespace', 'wix' => 'Wix', 'tailwind' => 'Tailwind', 'bootstrap' => 'Bootstrap', 'react' => 'React', 'vue' => 'Vue.js', 'angular' => 'Angular', 'next' => 'Next.js', 'jquery' => 'jQuery'];
            foreach ($tech_map as $needle => $name) { if (stripos($html, $needle) !== false) $techs[] = $name; }
            $data['technologies'] = $techs;
            $text = strip_tags(preg_replace('/<(script|style)[^>]*>.*?<\/\1>/s', '', $html));
            $data['text_preview'] = substr(preg_replace('/\s+/', ' ', trim($text)), 0, 500);
            $colors = [];
            if (preg_match_all('/#[0-9a-fA-F]{3,8}/', $html, $cm)) $colors = array_values(array_unique(array_slice($cm[0], 0, 10)));
            $data['colors'] = $colors;
            return wpilot_ok('Researched: ' . ($data['title'] ?? $url), $data);

        case 'compare_website':
        case 'competitor_analysis':
            $url = $params['url'] ?? $params['competitor_url'] ?? '';
            if (empty($url)) return wpilot_err('Competitor URL required.');
            $comp = wpilot_run_tool('research_url', ['url' => $url]);
            $ours = wpilot_run_tool('research_url', ['url' => home_url('/')]);
            return wpilot_ok('Competitor analysis: ' . ($comp['title'] ?? $url), [
                'our_site' => ['title' => $ours['title'] ?? '', 'techs' => $ours['technologies'] ?? [], 'images' => $ours['images'] ?? 0],
                'competitor' => ['title' => $comp['title'] ?? '', 'techs' => $comp['technologies'] ?? [], 'images' => $comp['images'] ?? 0, 'url' => $url, 'colors' => $comp['colors'] ?? []],
            ]);

        case 'research_keywords':
        case 'keyword_research':
            $keyword = sanitize_text_field($params['keyword'] ?? $params['query'] ?? '');
            if (empty($keyword)) return wpilot_err('Keyword required.');
            $suggest_url = 'https://suggestqueries.google.com/complete/search?client=firefox&q=' . urlencode($keyword);
            $response = wp_remote_get($suggest_url, ['timeout' => 10]);
            $suggestions = [];
            if (!is_wp_error($response)) {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $suggestions = $body[1] ?? [];
            }
            global $wpdb;
            $found = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_type FROM {$wpdb->posts} WHERE post_status='publish' AND (post_title LIKE %s OR post_content LIKE %s) LIMIT 10",
                '%' . $wpdb->esc_like($keyword) . '%', '%' . $wpdb->esc_like($keyword) . '%'
            ), ARRAY_A);
            return wpilot_ok("Keyword: {$keyword}", ['keyword' => $keyword, 'google_suggestions' => array_slice($suggestions, 0, 10), 'found_on_site' => $found]);

        case 'copy_design_from':
        case 'design_inspiration':
            $url = $params['url'] ?? '';
            if (empty($url)) return wpilot_err('URL to get inspiration from required.');
            $comp = wpilot_run_tool('research_url', ['url' => $url]);
            $colors = $comp['colors'] ?? [];
            $title = $comp['title'] ?? '';
            return wpilot_ok("Design research from {$url}: found " . count($colors) . " colors, " . count($comp['headings'] ?? []) . " headings, " . count($comp['technologies'] ?? []) . " technologies.", [
                'colors' => $colors, 'technologies' => $comp['technologies'] ?? [],
                'headings' => $comp['headings'] ?? [], 'text_preview' => $comp['text_preview'] ?? '',
            ]);

        
        // ═══ RECOVERY & SAFETY ═══

        default:
            return null; // Not handled by this module
    }
}
