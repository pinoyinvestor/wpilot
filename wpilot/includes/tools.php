<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Dispatch tool calls ────────────────────────────────────────
function wpilot_run_tool( $tool, $params = [] ) {
    switch ( $tool ) {

        /* ── Pages & Posts ──────────────────────────────────── */
        case 'create_page':
            $title   = sanitize_text_field( $params['title']   ?? 'New Page' );
            $content = wp_kses_post(          $params['content'] ?? '' );
            $status  = sanitize_text_field( $params['status']  ?? 'draft' );
            $id = wp_insert_post( ['post_title'=>$title,'post_content'=>$content,'post_status'=>$status,'post_type'=>'page'] );
            if ( is_wp_error($id) ) return wpilot_err( $id->get_error_message() );
            return wpilot_ok( "✅ Page \"{$title}\" created (ID: {$id}, status: {$status}).", ['id'=>$id] );

        case 'update_page_content':
            $id      = intval( $params['id'] ?? 0 );
            $content = wp_kses_post( $params['content'] ?? '' );
            if ( !$id ) return wpilot_err('Page ID required.');
            wpilot_save_post_snapshot( $id );
            wp_update_post( ['ID'=>$id,'post_content'=>$content] );
            return wpilot_ok( "✅ Page #{$id} content updated." );

        case 'update_post_title':
            $id    = intval( $params['id'] ?? 0 );
            $title = sanitize_text_field( $params['title'] ?? '' );
            if ( !$id ) return wpilot_err('Post ID required.');
            wpilot_save_post_snapshot( $id );
            wp_update_post( ['ID'=>$id,'post_title'=>$title] );
            return wpilot_ok( "✅ Title updated to \"{$title}\"." );

        case 'set_homepage':
            $id = intval( $params['id'] ?? 0 );
            if ( !$id ) return wpilot_err('Page ID required.');
            update_option('show_on_front','page');
            update_option('page_on_front', $id);
            return wpilot_ok( "✅ Homepage set to page ID {$id}." );

        /* ── Menus ──────────────────────────────────────────── */
        case 'create_menu':
            $name = sanitize_text_field( $params['name'] ?? 'New Menu' );
            $id   = wp_create_nav_menu( $name );
            if ( is_wp_error($id) ) return wpilot_err( $id->get_error_message() );
            if ( !empty($params['location']) ) {
                $locs = get_theme_mod('nav_menu_locations', []);
                $locs[$params['location']] = $id;
                set_theme_mod('nav_menu_locations', $locs);
            }
            return wpilot_ok( "✅ Menu \"{$name}\" created.", ['id'=>$id] );

        case 'add_menu_item':
            $menu_id = intval( $params['menu_id'] ?? 0 );
            $title   = sanitize_text_field( $params['title'] ?? '' );
            $url     = esc_url_raw( $params['url'] ?? '#' );
            if ( !$menu_id ) return wpilot_err('menu_id required.');
            $item_id = wp_update_nav_menu_item( $menu_id, 0, [
                'menu-item-title'  => $title,
                'menu-item-url'    => $url,
                'menu-item-status' => 'publish',
                'menu-item-type'   => 'custom',
            ]);
            if ( is_wp_error($item_id) ) return wpilot_err( $item_id->get_error_message() );
            return wpilot_ok( "✅ Menu item \"{$title}\" added." );

        /* ── SEO metadata ───────────────────────────────────── */
        case 'update_meta_desc':
            $id   = intval( $params['id'] ?? 0 );
            $desc = sanitize_text_field( $params['desc'] ?? '' );
            if ( !$id ) return wpilot_err('Post/page ID required.');
            update_post_meta($id,'_yoast_wpseo_metadesc', $desc);
            update_post_meta($id,'_aioseo_description',   $desc);
            update_post_meta($id,'rank_math_description',  $desc);
            return wpilot_ok( "✅ Meta description updated for #{$id}." );

        case 'update_seo_title':
            $id    = intval( $params['id'] ?? 0 );
            $title = sanitize_text_field( $params['title'] ?? '' );
            update_post_meta($id,'_yoast_wpseo_title', $title);
            update_post_meta($id,'rank_math_title',    $title);
            return wpilot_ok( "✅ SEO title updated." );

        case 'update_focus_keyword':
            $id = intval( $params['id'] ?? 0 );
            $kw = sanitize_text_field( $params['keyword'] ?? '' );
            update_post_meta($id,'_yoast_wpseo_focuskw',    $kw);
            update_post_meta($id,'rank_math_focus_keyword', $kw);
            return wpilot_ok( "✅ Focus keyword set to \"{$kw}\"." );

        /* ── CSS ────────────────────────────────────────────── */
        case 'update_custom_css':
            $css = wp_strip_all_tags( $params['css'] ?? '' );
            wpilot_save_css_snapshot();
            wp_update_custom_css_post( $css );
            return wpilot_ok('✅ Custom CSS replaced.');

        case 'append_custom_css':
            $new  = wp_strip_all_tags( $params['css'] ?? '' );
            $curr = wp_get_custom_css();
            wpilot_save_css_snapshot();
            wp_update_custom_css_post( trim($curr) . "\n\n/* Claude AI — " . date('Y-m-d H:i') . " */\n" . $new );
            return wpilot_ok('✅ CSS appended to Customizer.');

        /* ── Media / Images ─────────────────────────────────── */
        case 'update_image_alt':
            $id  = intval( $params['id'] ?? 0 );
            $alt = sanitize_text_field( $params['alt'] ?? '' );
            if ( !$id ) return wpilot_err('Image ID required.');
            update_post_meta($id,'_wp_attachment_image_alt', $alt);
            return wpilot_ok( "✅ Alt text updated for image #{$id}." );

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
            return wpilot_ok("✅ Fixed alt text for {$fixed} images.");

        case 'set_featured_image':
            $post_id  = intval( $params['post_id']  ?? 0 );
            $image_id = intval( $params['image_id'] ?? 0 );
            if (!$post_id||!$image_id) return wpilot_err('post_id and image_id required.');
            set_post_thumbnail($post_id,$image_id);
            return wpilot_ok("✅ Featured image set.");

        /* ── WooCommerce ─────────────────────────────────────── */
        case 'create_coupon':
            if ( !class_exists('WooCommerce') ) return wpilot_err('WooCommerce required.');
            $code   = strtoupper(sanitize_text_field($params['code'] ?? 'SAVE'.rand(10,99)));
            $amount = floatval($params['amount'] ?? 10);
            $type   = sanitize_text_field($params['type'] ?? 'percent');
            $id = wp_insert_post(['post_title'=>$code,'post_type'=>'shop_coupon','post_status'=>'publish']);
            if ( is_wp_error($id) ) return wpilot_err($id->get_error_message());
            update_post_meta($id,'discount_type',  $type);
            update_post_meta($id,'coupon_amount',  $amount);
            if (!empty($params['min_amount']))  update_post_meta($id,'minimum_amount',floatval($params['min_amount']));
            if (!empty($params['expiry']))       update_post_meta($id,'date_expires',strtotime($params['expiry']));
            if (!empty($params['product_ids']))  update_post_meta($id,'product_ids',array_map('intval',(array)$params['product_ids']));
            if (!empty($params['category_ids'])) update_post_meta($id,'product_categories',array_map('intval',(array)$params['category_ids']));
            $label = $type==='percent' ? "{$amount}%" : "{$amount} kr";
            return wpilot_ok("✅ Coupon <strong>{$code}</strong> created — {$label} off.", ['code'=>$code,'id'=>$id]);

        case 'update_product_price':
            $pid   = intval($params['product_id'] ?? 0);
            $price = sanitize_text_field($params['price'] ?? '');
            $sale  = sanitize_text_field($params['sale_price'] ?? '');
            update_post_meta($pid,'_regular_price',$price);
            update_post_meta($pid,'_price', $sale ?: $price);
            if ($sale) update_post_meta($pid,'_sale_price',$sale);
            if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
            return wpilot_ok("✅ Product #{$pid} price updated.");

        case 'update_product_desc':
            $pid  = intval($params['product_id'] ?? 0);
            $desc = wp_kses_post($params['description'] ?? '');
            wpilot_save_post_snapshot($pid);
            wp_update_post(['ID'=>$pid,'post_content'=>$desc]);
            return wpilot_ok("✅ Product description updated.");

        case 'create_product_category':
            if ( !class_exists('WooCommerce') ) return wpilot_err('WooCommerce required.');
            $name = sanitize_text_field($params['name'] ?? '');
            if (!$name) return wpilot_err('Category name required.');
            $term = wp_insert_term($name,'product_cat',['description'=>$params['desc']??'']);
            if (is_wp_error($term)) return wpilot_err($term->get_error_message());
            return wpilot_ok("✅ Product category \"{$name}\" created.",['id'=>$term['term_id']]);

        /* ── Users ───────────────────────────────────────────── */
        case 'create_user':
            $username = sanitize_user($params['username'] ?? 'user'.rand(100,9999));
            $email    = sanitize_email($params['email'] ?? '');
            $role     = sanitize_text_field($params['role'] ?? 'subscriber');
            if (!$email) return wpilot_err('Email required.');
            if (email_exists($email)) return wpilot_err("Email {$email} already in use.");
            $pass = wp_generate_password(14,true);
            $uid  = wp_create_user($username,$pass,$email);
            if (is_wp_error($uid)) return wpilot_err($uid->get_error_message());
            (new WP_User($uid))->set_role($role);
            wp_new_user_notification($uid,null,'user');
            return wpilot_ok("✅ User \"{$username}\" ({$role}) created. Login sent to {$email}.",['user_id'=>$uid]);

        /* ── Plugins ─────────────────────────────────────────── */
        case 'deactivate_plugin':
            $file = sanitize_text_field($params['file'] ?? '');
            if (!$file) return wpilot_err('Plugin file required.');
            deactivate_plugins($file);
            return wpilot_ok("✅ Plugin deactivated.");

        case 'delete_plugin':
            $file = sanitize_text_field($params['file'] ?? '');
            if (!$file) return wpilot_err('Plugin file required.');
            if (!function_exists('delete_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
            deactivate_plugins($file);
            delete_plugins([$file]);
            return wpilot_ok("✅ Plugin deleted.");

        /* ── Site settings ───────────────────────────────────── */
        case 'update_blogname':
            update_option('blogname', sanitize_text_field($params['name'] ?? ''));
            return wpilot_ok("✅ Site name updated.");

        case 'update_tagline':
            update_option('blogdescription', sanitize_text_field($params['tagline'] ?? ''));
            return wpilot_ok("✅ Tagline updated.");

        /* ── Restore ─────────────────────────────────────────── */
        case 'restore_backup':
            return wpilot_restore( intval($params['backup_id'] ?? 0) );

        default:
            return wpilot_err("Unknown tool: {$tool}");
    }
}

function wpilot_save_post_snapshot( $post_id ) {
    global $wpdb;
    $post = get_post($post_id);
    if (!$post) return;
    $wpdb->insert($wpdb->prefix.'ca_backups', [
        'tool'        => 'post_snapshot',
        'target_id'   => $post_id,
        'target_type' => $post->post_type,
        'data_before' => wp_json_encode(['post'=>$post->to_array(),'meta'=>get_post_meta($post_id)]),
        'created_at'  => current_time('mysql'),
    ]);
}

function wpilot_save_css_snapshot() {
    global $wpdb;
    $wpdb->insert($wpdb->prefix.'ca_backups', [
        'tool'        => 'css_snapshot',
        'target_type' => 'custom_css',
        'data_before' => wp_json_encode(['css'=>wp_get_custom_css()]),
        'created_at'  => current_time('mysql'),
    ]);
}
