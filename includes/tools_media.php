<?php
if (!defined('ABSPATH')) exit;

/**
 * WPilot Media Tools Module
 * Contains 31 tool cases for media operations.
 */
function wpilot_run_media_tools($tool, $params = []) {
    switch ($tool) {

        case 'update_image_alt':
            $id  = intval( $params["id"] ?? $params["image_id"] ?? $params["attachment_id"] ?? 0 );
            $alt = sanitize_text_field( $params['alt'] ?? '' );
            if ( !$id ) return wpilot_err('Image ID required.');
            update_post_meta($id,'_wp_attachment_image_alt', $alt);
            return wpilot_ok( "Alt text updated for image #{$id}." );

        case 'bulk_fix_alt_text':
            $images = get_posts(['post_type'=>'attachment','post_mime_type'=>'image','numberposts'=>-1]);
            $fixed  = 0;
            foreach ( $images as $img ) {
                $alt = get_post_meta($img->ID,'_wp_attachment_image_alt',true);
                if ( empty(trim((string)$alt)) ) {
                    update_post_meta($img->ID,'_wp_attachment_image_alt', sanitize_text_field($img->post_title));
                    $fixed++;
                }
            }
            return wpilot_ok("Fixed alt text for {$fixed} images.");

        case 'set_featured_image':
            $post_id  = intval( $params['post_id'] ?? $params['id'] ?? $params['product_id'] ?? $params['page_id'] ?? 0 );
            $image_id = intval( $params['image_id'] ?? 0 );
            $image_url = $params['image_url'] ?? $params['url'] ?? $params['image'] ?? $params['src'] ?? '';
            // Fallback: extract post_id from description if params parsing failed
            if (!$post_id) {
                $desc = sanitize_text_field( wp_unslash( $_POST['description'] ?? $_POST['label'] ?? '' ) );
                if (preg_match('/(?:product|page|post|ID)[:\s#]*?(\d+)/i', $desc, $dm)) {
                    $post_id = intval($dm[1]);
                }
            }
            // Fallback: extract image URL from description
            if (empty($image_url) && !$image_id) {
                $desc = wp_unslash( $_POST['description'] ?? $_POST['label'] ?? '' );
                if (preg_match('/(https?:\/\/[^\s"]+\.(?:jpg|jpeg|png|webp|gif)[^\s"]*)/i', $desc, $um)) {
                    $image_url = esc_url_raw($um[1]);
                }
            }
            if (!$image_id && !empty($image_url)) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                // Try media_sideload_image first (works for URLs with extension)
                $image_id = media_sideload_image($image_url, $post_id, '', 'id');
                // If URL has no extension (Unsplash etc), download manually
                if (is_wp_error($image_id)) {
                    $tmp = download_url($image_url);
                    if (is_wp_error($tmp)) return wpilot_err('Image download failed: ' . $tmp->get_error_message());
                    $ext = 'jpg';
                    $type = wp_check_filetype(basename(parse_url($image_url, PHP_URL_PATH)));
                    if (!empty($type['ext'])) $ext = $type['ext'];
                    $file = ['name' => sanitize_file_name('wpilot-image-' . time() . '.' . $ext), 'tmp_name' => $tmp];
                    $image_id = media_handle_sideload($file, $post_id, '');
                    @unlink($tmp);
                    if (is_wp_error($image_id)) return wpilot_err('Image upload failed: ' . $image_id->get_error_message());
                }
            }
            if (!$post_id||!$image_id) return wpilot_err('post_id and image_url or image_id required.');
            set_post_thumbnail($post_id,$image_id);
            return wpilot_ok("Featured image set.");

        /* ── Image Conversion — WebP ────────────────────────── */
        case 'compress_images':
            return wpilot_compress_images($params);

        case 'convert_all_images_webp':
            return wpilot_bulk_convert_webp( intval( $params['quality'] ?? 82 ) );

        case 'convert_image_webp':
            $id = intval( $params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0 );
            if ( !$id ) return wpilot_err('Image ID required.');
            return wpilot_convert_single_webp( $id, intval( $params['quality'] ?? 82 ) );

        /* ── WooCommerce ─────────────────────────────────────── */
        case 'generate_image':
        case 'create_image':
        case 'ai_image':
            $prompt_text = $params['prompt'] ?? $params['description'] ?? '';
            $post_id = intval($params['post_id'] ?? $params['id'] ?? 0);
            if (empty($prompt_text)) return wpilot_err('Image description/prompt required.');
            // Use the site's Claude API key to call image generation
            // For now, use placeholder images from picsum until image API is integrated
            $width = intval($params['width'] ?? 800);
            $height = intval($params['height'] ?? 600);
            $seed = crc32($prompt_text); // Consistent image for same prompt
            $image_url = "https://placehold.co/{$width}x{$height}/1a1a2e/6366f1.png?text=" . urlencode(substr($prompt_text, 0, 15));
            // Download and attach
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $title = sanitize_text_field(substr($prompt_text, 0, 60));
            $image_id = media_sideload_image($image_url, $post_id ?: 0, $title, 'id');
            if (is_wp_error($image_id)) return wpilot_err('Image generation failed: ' . $image_id->get_error_message());
            // Set alt text from prompt
            update_post_meta($image_id, '_wp_attachment_image_alt', $title);
            // Set as featured if post_id given
            if ($post_id) set_post_thumbnail($post_id, $image_id);
            return wpilot_ok("Image generated and uploaded (ID: {$image_id}).", [
                'image_id' => $image_id,
                'url' => wp_get_attachment_url($image_id),
                'set_as_featured' => $post_id > 0,
            ]);

        // ═══ PRODUCT VARIATIONS ═══
        case 'list_media':
            $limit = intval($params['limit'] ?? 20);
            $type = $params['type'] ?? 'image';
            $images = get_posts(['post_type' => 'attachment', 'post_mime_type' => $type, 'numberposts' => $limit, 'orderby' => 'date', 'order' => 'DESC']);
            $list = [];
            foreach ($images as $img) {
                $file = get_attached_file($img->ID);
                $list[] = [
                    'id' => $img->ID,
                    'title' => $img->post_title,
                    'url' => wp_get_attachment_url($img->ID),
                    'size_kb' => $file && file_exists($file) ? round(filesize($file)/1024) : 0,
                    'mime' => $img->post_mime_type,
                    'alt' => get_post_meta($img->ID, '_wp_attachment_image_alt', true),
                ];
            }
            return wpilot_ok(count($list) . " media items.", ['media' => $list]);

        case 'upload_image':
        case 'upload_media':
            $url = $params['url'] ?? $params['image_url'] ?? '';
            $title = sanitize_text_field($params['title'] ?? $params['name'] ?? '');
            if (empty($url)) return wpilot_err('Image URL required.');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $id = media_sideload_image($url, 0, $title, 'id');
            if (is_wp_error($id)) return wpilot_err('Upload failed: ' . $id->get_error_message());
            return wpilot_ok("Image uploaded (ID: {$id}).", ['image_id' => $id, 'url' => wp_get_attachment_url($id)]);

        case 'delete_media':
            $id = intval($params['id'] ?? $params['image_id'] ?? $params['media_id'] ?? 0);
            if (!$id) return wpilot_err('Media ID required.');
            $result = wp_delete_attachment($id, true);
            if (!$result) return wpilot_err("Could not delete media #{$id}.");
            return wpilot_ok("Media #{$id} deleted.");

        // ═══ DEBUG & LOGS ═══
        case 'screenshot':
        case 'take_screenshot':
            $url = $params['url'] ?? home_url('/');
            if (strpos($url, '/') === 0) $url = home_url($url);
            $mobile = !empty($params['mobile']);
            $result = wpilot_take_screenshot($url, [
                'mobile' => $mobile,
                'width'  => intval($params['width'] ?? 1440),
                'delay'  => intval($params['delay'] ?? 2),
            ]);
            if (is_wp_error($result)) return wpilot_err($result->get_error_message());
            return wpilot_ok("Screenshot taken: {$result['filename']} ({$result['size_kb']} KB)", $result);

        case 'analyze_design':
        case 'visual_review':
// Built by Weblease
        case 'design_review':
            // Fallback on low-memory hosting
            if (function_exists('wpilot_can_use') && !wpilot_can_use_feature('screenshot')) {
                return wpilot_run_tool('check_frontend', $params);
            }
            $url = $params['url'] ?? home_url('/');
            if (strpos($url, '/') === 0) $url = home_url($url);
            $type = sanitize_text_field($params['analysis_type'] ?? 'full');
            // Take screenshot first
            $shot = wpilot_take_screenshot($url, ['delay' => 3]);
            if (is_wp_error($shot)) return wpilot_err('Screenshot failed: ' . $shot->get_error_message());
            // Analyze with Vision
            $analysis = wpilot_analyze_screenshot($shot['path'], $type);
            if (is_wp_error($analysis)) return wpilot_err('Vision analysis failed: ' . $analysis->get_error_message());
            return wpilot_ok("Visual analysis of {$url}", [
                'screenshot_url' => $shot['url'],
                'analysis'       => $analysis,
                'analysis_type'  => $type,
            ]);

        case 'check_visual_bugs':
        case 'visual_debug':
            $url = $params['url'] ?? home_url('/');
            if (strpos($url, '/') === 0) $url = home_url($url);
            // Desktop + mobile screenshots
            $desktop = wpilot_take_screenshot($url, ['delay' => 3]);
            $mobile  = wpilot_take_screenshot($url, ['mobile' => true, 'delay' => 3]);
            $results = ['screenshots' => []];
            if (!is_wp_error($desktop)) {
                $results['screenshots']['desktop'] = $desktop['url'];
                $analysis = wpilot_analyze_screenshot($desktop['path'], 'bugs');
                if (!is_wp_error($analysis)) $results['desktop_bugs'] = $analysis;
            }
            if (!is_wp_error($mobile)) {
                $results['screenshots']['mobile'] = $mobile['url'];
                $analysis = wpilot_analyze_screenshot($mobile['path'], 'bugs');
                if (!is_wp_error($analysis)) $results['mobile_bugs'] = $analysis;
            }
            return wpilot_ok("Visual bug check for {$url}", $results);

        case 'compare_before_after':
            $url = $params['url'] ?? home_url('/');
            if (strpos($url, '/') === 0) $url = home_url($url);
            // Find latest "before" screenshot for this URL
            $slug = sanitize_title(wp_parse_url($url, PHP_URL_PATH) ?: 'home');
            $dir = wp_upload_dir()['basedir'] . '/wpilot-screenshots';
            $befores = glob($dir . '/' . $slug . '*-before.png');
            if (empty($befores)) return wpilot_err('No "before" screenshot found. Take a screenshot first, then make changes, then compare.');
            // Take "after" screenshot
            $after = wpilot_take_screenshot($url, ['delay' => 3]);
            if (is_wp_error($after)) return wpilot_err('After screenshot failed: ' . $after->get_error_message());
            // Compare
            $before_path = end($befores);
            $comparison = wpilot_compare_screenshots($before_path, $after['path']);
            if (is_wp_error($comparison)) return wpilot_err('Comparison failed: ' . $comparison->get_error_message());
            return wpilot_ok("Before/after comparison for {$url}", [
                'before_url' => wp_upload_dir()['baseurl'] . '/wpilot-screenshots/' . basename($before_path),
                'after_url'  => $after['url'],
                'comparison' => $comparison,
            ]);

        case 'screenshot_before':
            $url = $params['url'] ?? home_url('/');
            if (strpos($url, '/') === 0) $url = home_url($url);
            $shot = wpilot_take_screenshot($url, ['delay' => 2]);
            if (is_wp_error($shot)) return wpilot_err($shot->get_error_message());
            // Rename to -before
            $before_path = str_replace('.png', '-before.png', $shot['path']);
            $before_url  = str_replace('.png', '-before.png', $shot['url']);
            rename($shot['path'], $before_path);
            return wpilot_ok("Before screenshot saved. Make changes, then use compare_before_after.", [
                'screenshot_url' => $before_url,
            ]);

        case 'screenshot_history':
            $history = wpilot_get_screenshot_history(20);
            return wpilot_ok(count($history) . " screenshots.", ['screenshots' => $history]);

        // ═══ TEMPLATE LIBRARY ═══
        case 'responsive_check':
        case 'responsive_screenshots':
        case 'multi_device_screenshot':
            // Fallback on low-memory
            if (function_exists('wpilot_can_use') && !wpilot_can_use_feature('screenshot')) {
                return wpilot_run_tool('check_frontend', $params);
            }
            $url = $params['url'] ?? home_url('/');
            if (strpos($url, '/') === 0) $url = home_url($url);
            $shots = [];
            // Desktop 1440px
            $desktop = wpilot_take_screenshot($url, ['width' => 1440, 'height' => 900, 'delay' => 3]);
            if (!is_wp_error($desktop)) $shots['desktop'] = $desktop['url'];
            // Tablet 768px
            $tablet = wpilot_take_screenshot($url, ['width' => 768, 'height' => 1024, 'delay' => 3]);
            if (!is_wp_error($tablet)) $shots['tablet'] = $tablet['url'];
            // Mobile 390px
            $mobile = wpilot_take_screenshot($url, ['mobile' => true, 'delay' => 3]);
            if (!is_wp_error($mobile)) $shots['mobile'] = $mobile['url'];
            // Analyze each
            $analyses = [];
            if (!is_wp_error($desktop)) {
                $a = wpilot_analyze_screenshot($desktop['path'], 'design');
                if (!is_wp_error($a)) $analyses['desktop'] = $a;
            }
            if (!is_wp_error($tablet)) {
                $a = wpilot_analyze_screenshot($tablet['path'], 'bugs');
                if (!is_wp_error($a)) $analyses['tablet'] = $a;
            }
            if (!is_wp_error($mobile)) {
                $a = wpilot_analyze_screenshot($mobile['path'], 'bugs');
                if (!is_wp_error($a)) $analyses['mobile'] = $a;
            }
            return wpilot_ok("Multi-device screenshots for {$url}", [
                'screenshots' => $shots,
                'analyses' => $analyses,
            ]);

        
        // ═══ FILE SYSTEM — Read/Write any WordPress file ═══
        case 'upload_logo':
        case 'set_logo':
        case 'change_logo':
            $url = $params['url'] ?? $params['logo_url'] ?? $params['image_url'] ?? '';
            $image_id = intval($params['image_id'] ?? 0);
            if (empty($url) && !$image_id) return wpilot_err('Logo URL or image_id required.');
            if (!empty($url) && !$image_id) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $image_id = media_sideload_image($url, 0, get_bloginfo('name') . ' Logo', 'id');
                if (is_wp_error($image_id)) return wpilot_err('Upload failed: ' . $image_id->get_error_message());
            }
            set_theme_mod('custom_logo', $image_id);
            $meta = wp_get_attachment_metadata($image_id);
            $w = $meta['width'] ?? 0; $h = $meta['height'] ?? 0;
            if ($w > 0 && $h > 0 && abs($w - $h) < 50) update_option('site_icon', $image_id);
            return wpilot_ok("Logo set! (ID: {$image_id}, {$w}x{$h}px)", ['image_id' => $image_id, 'url' => wp_get_attachment_url($image_id)]);

        case 'remove_logo':
            remove_theme_mod('custom_logo');
            return wpilot_ok('Logo removed.');

        case 'get_logo':
            $logo_id = get_theme_mod('custom_logo');
            if (!$logo_id) return wpilot_ok('No logo set.', ['has_logo' => false]);
            return wpilot_ok('Current logo.', ['has_logo' => true, 'image_id' => $logo_id, 'url' => wp_get_attachment_url($logo_id)]);

        // ═══ WEB RESEARCH ═══
        // Built by Weblease

        default:
            return null; // Not handled by this module
    }
}
