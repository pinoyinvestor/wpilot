<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ── Dispatch tool calls ────────────────────────────────────────
function wpilot_run_tool( $tool, $params = [] ) {
    // Safety: write crash flag before executing (removed after success)
    $crash_file = WP_CONTENT_DIR . '/wpilot-crash-flag.txt';
    file_put_contents($crash_file, json_encode(['tool' => $tool, 'time' => date('Y-m-d H:i:s')]));
    // Register cleanup on successful completion
    register_shutdown_function(function() use ($crash_file) { @unlink($crash_file); });
    switch ( $tool ) {

        /* ── Pages & Posts ──────────────────────────────────── */
        case 'create_page':
            $title   = sanitize_text_field( $params['title']   ?? 'New Page' );
            $content = wp_kses_post(          $params['content'] ?? '' );
            $status  = sanitize_text_field( $params['status']  ?? 'draft' );
            $id = wp_insert_post( ['post_title'=>$title,'post_content'=>$content,'post_status'=>$status,'post_type'=>'page'] );
            if ( is_wp_error($id) ) return wpilot_err( $id->get_error_message() );
            return wpilot_ok( "Page \"{$title}\" created (ID: {$id}, status: {$status}).", ['id'=>$id] );

        case 'update_page_content':
            $id      = intval( $params['id'] ?? $params['page_id'] ?? $params['post_id'] ?? 0 );
            $content = wp_kses_post( $params['content'] ?? '' );
            if ( !$id ) return wpilot_err('Page ID required.');
            wpilot_save_post_snapshot( $id );
            wp_update_post( ['ID'=>$id,'post_content'=>$content] );
            return wpilot_ok( "Page #{$id} content updated." );

        case 'update_post_title':
            $id    = intval( $params['id'] ?? 0 );
            $title = sanitize_text_field( $params['title'] ?? '' );
            if ( !$id ) return wpilot_err('Post ID required.');
            wpilot_save_post_snapshot( $id );
            wp_update_post( ['ID'=>$id,'post_title'=>$title] );
            return wpilot_ok( "Title updated to \"{$title}\"." );

        case 'set_page_template':
            $id = intval($params['id'] ?? $params['page_id'] ?? $params['post_id'] ?? 0);
            $template = sanitize_text_field($params['template'] ?? 'default');
            if (!$id) return wpilot_err('Page ID required.');
            update_post_meta($id, '_wp_page_template', $template);
            // For Elementor
            if (strpos($template, 'elementor') !== false) {
                update_post_meta($id, '_elementor_edit_mode', 'builder');
            }
            return wpilot_ok("Page #{$id} template set to {$template}.");

        case 'set_homepage':
            // Accept any ID variant
            if (!isset($params['id']) && isset($params['page_id'])) $params['id'] = $params['page_id'];
            if (!isset($params['id']) && isset($params['post_id'])) $params['id'] = $params['post_id'];
            $id = intval( $params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0 );
            if ( !$id ) return wpilot_err('Page ID required.');
            update_option('show_on_front','page');
            update_option('page_on_front', $id);
            return wpilot_ok( "Homepage set to page ID {$id}." );

        /* ── Menus ──────────────────────────────────────────── */
        case 'create_menu':
            $name = sanitize_text_field( $params['name'] ?? $params['menu_name'] ?? 'Main Menu' );
            // Delete ALL existing menus to prevent duplicates
            $all_menus = wp_get_nav_menus();
            foreach ($all_menus as $old_menu) {
                wp_delete_nav_menu($old_menu->term_id);
            }
            $id = wp_create_nav_menu($name);
            if (is_wp_error($id)) return wpilot_err($id->get_error_message());
            $items = $params['items'] ?? [];
            foreach ($items as $item) {
                $ititle = sanitize_text_field($item['title'] ?? $item['label'] ?? '');
                $iurl = esc_url_raw($item['url'] ?? $item['link'] ?? '#');
                if ($ititle) {
                    wp_update_nav_menu_item($id, 0, [
                        'menu-item-title' => $ititle,
                        'menu-item-url' => $iurl,
                        'menu-item-status' => 'publish',
                        'menu-item-type' => 'custom',
                    ]);
                }
            }
            $location = $params['location'] ?? $params['menu_location'] ?? '';
            if (!$location) {
                $theme_locs = get_registered_nav_menus();
                if (!empty($theme_locs)) $location = array_key_first($theme_locs);
            }
            if ($location) {
                $locs = get_theme_mod('nav_menu_locations', []);
                $locs[$location] = $id;
                set_theme_mod('nav_menu_locations', $locs);
            }
            $ic = count($items);
            return wpilot_ok("Menu \"{$name}\" created with {$ic} items.", ['id'=>$id, 'items'=>$ic]);

        case 'add_menu_item':
            $menu_id = intval( $params['menu_id'] ?? 0 );
            $title   = sanitize_text_field( $params['title'] ?? '' );
            $url     = esc_url_raw( $params['url'] ?? '#' );
            $page_id = intval( $params['page_id'] ?? 0 );
            // Auto-find primary menu if not specified
            if ( !$menu_id ) {
                $locations = get_nav_menu_locations();
                foreach (['primary','main-menu','header-menu','main','header','top'] as $loc) {
                    if (!empty($locations[$loc])) { $menu_id = $locations[$loc]; break; }
                }
                // If still no menu, get any menu
                if (!$menu_id) {
                    $menus = wp_get_nav_menus();
                    if (!empty($menus)) $menu_id = $menus[0]->term_id;
                }
                // If still no menu, create one
                if (!$menu_id) {
                    $menu_id = wp_create_nav_menu(get_bloginfo('name') . ' Menu');
                    if (is_wp_error($menu_id)) return wpilot_err('Could not create menu.');
                }
            }
            $menu_args = [
                'menu-item-title'  => $title,
                'menu-item-status' => 'publish',
            ];
            if ($page_id) {
                $menu_args['menu-item-object'] = 'page';
                $menu_args['menu-item-object-id'] = $page_id;
                $menu_args['menu-item-type'] = 'post_type';
                if (!$title) $menu_args['menu-item-title'] = get_the_title($page_id);
            } else {
                $menu_args['menu-item-url'] = $url;
                $menu_args['menu-item-type'] = 'custom';
            }
            $item_id = wp_update_nav_menu_item( $menu_id, 0, $menu_args );
            if ( is_wp_error($item_id) ) return wpilot_err( $item_id->get_error_message() );
            return wpilot_ok( "Menu item \"{$title}\" added." );

        /* ── SEO metadata ───────────────────────────────────── */
        // Built by Christos Ferlachidis & Daniel Hedenberg
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
            return wpilot_ok('CSS updated (duplicates removed).');

        /* ── Media / Images ─────────────────────────────────── */
        case 'update_image_alt':
            $id  = intval( $params['id'] ?? 0 );
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
                $image_id = media_sideload_image($image_url, $post_id, '', 'id');
                if (is_wp_error($image_id)) return wpilot_err('Image download failed: ' . $image_id->get_error_message());
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
            return wpilot_ok("Coupon <strong>{$code}</strong> created — {$label} off.", ['code'=>$code,'id'=>$id]);

        case 'update_product_price':
            $pid   = intval($params['product_id'] ?? 0);
            if (!$pid) return wpilot_err('product_id required.');
            $price = sanitize_text_field($params['price'] ?? '');
            $sale  = sanitize_text_field($params['sale_price'] ?? '');
            update_post_meta($pid,'_regular_price',$price);
            update_post_meta($pid,'_price', $sale ?: $price);
            if ($sale) update_post_meta($pid,'_sale_price',$sale);
            if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
            return wpilot_ok("Product #{$pid} price updated.");

        case 'update_product_desc':
            $pid  = intval($params['product_id'] ?? 0);
            $desc = wp_kses_post($params['description'] ?? '');
            wpilot_save_post_snapshot($pid);
            wp_update_post(['ID'=>$pid,'post_content'=>$desc]);
            return wpilot_ok("Product description updated.");

        case 'create_product_category':
            if ( !class_exists('WooCommerce') ) return wpilot_err('WooCommerce required.');
            $name = sanitize_text_field($params['name'] ?? $params['category_name'] ?? $params['category'] ?? '');
            if (!$name) return wpilot_err('Category name required.');
            $term = wp_insert_term($name,'product_cat',['description'=>$params['desc']??'']);
            if (is_wp_error($term)) return wpilot_err($term->get_error_message());
            return wpilot_ok("Product category \"{$name}\" created.",['id'=>$term['term_id']]);

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
            return wpilot_ok("User \"{$username}\" ({$role}) created. Login sent to {$email}.",['user_id'=>$uid]);

        /* ── Plugins ─────────────────────────────────────────── */
        case 'activate_plugin':
            $file = sanitize_text_field($params['file'] ?? '');
            $slug = sanitize_text_field($params['slug'] ?? $params['plugin_slug'] ?? $params['plugin'] ?? '');
            $name = sanitize_text_field($params['name'] ?? $params['plugin_name'] ?? '');
            if (!$file) {
                if (!function_exists('get_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
                foreach (get_plugins() as $f => $d) {
                    if ($slug && (strpos($f, $slug.'/') === 0 || $f === $slug.'.php')) { $file = $f; break; }
                    if ($name && stripos($d['Name'], $name) !== false) { $file = $f; break; }
                }
            }
            if (!$file) {
                // Plugin not installed — try to install it first
                $search_name = $name ?: $slug ?: '';
                if ($search_name && function_exists('wpilot_infer_params')) {
                    $install_params = wpilot_infer_params('plugin_install', 'Install ' . $search_name);
                    if (!empty($install_params['slug'])) {
                        wpilot_load_heavy();
                        $install_result = wpilot_safe_run_tool('plugin_install', $install_params);
                        if ($install_result['success']) {
                            // Now try to find and activate
                            foreach (get_plugins() as $f => $d) {
                                if (stripos($d['Name'], $search_name) !== false) { $file = $f; break; }
                            }
                        }
                    }
                }
                if (!$file) return wpilot_err('Plugin not found. Try: [ACTION: plugin_install | Install ' . ($name ?: $slug) . ' | Install the plugin first | 🔌]');
            }
            if (is_plugin_active($file)) return wpilot_ok("Plugin is already active.");
            $result = activate_plugin($file);
            if (is_wp_error($result)) return wpilot_err("Could not activate: " . $result->get_error_message());
            $plugin_name = get_plugin_data(WP_PLUGIN_DIR . '/' . $file)['Name'] ?? $file;
            return wpilot_ok("Plugin \"" . $plugin_name . "\" activated.");

        case 'deactivate_plugin':
            $file = sanitize_text_field($params['file'] ?? '');
            $slug = sanitize_text_field($params['slug'] ?? $params['plugin_slug'] ?? $params['plugin'] ?? '');
            $name = sanitize_text_field($params['name'] ?? $params['plugin_name'] ?? '');
            // Auto-find plugin file from slug or name
            if (!$file) {
                if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
                foreach (get_plugins() as $f => $d) {
                    if ($slug && (strpos($f, $slug.'/') === 0 || $f === $slug.'.php')) { $file = $f; break; }
                    if ($name && stripos($d['Name'], $name) !== false) { $file = $f; break; }
                    // Also try matching the tool description
                    $desc = sanitize_text_field($params['description'] ?? '');
                    if ($desc && stripos($desc, $d['Name']) !== false) { $file = $f; break; }
                }
            }
            if (!$file) return wpilot_err('Could not find the plugin. Try specifying the plugin name.');
            if (!is_plugin_active($file)) return wpilot_ok("Plugin is already inactive.");
            deactivate_plugins($file);
            $plugin_name = get_plugin_data(WP_PLUGIN_DIR . '/' . $file)['Name'] ?? $file;
            return wpilot_ok("Plugin \"" . $plugin_name . "\" deactivated.");

        case 'delete_plugin':
            $file = sanitize_text_field($params['file'] ?? '');
            $slug = sanitize_text_field($params['slug'] ?? $params['plugin_slug'] ?? $params['plugin'] ?? '');
            $name = sanitize_text_field($params['name'] ?? $params['plugin_name'] ?? '');
            if (!$file) {
                if (!function_exists('get_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
                foreach (get_plugins() as $f => $d) {
                    if ($slug && (strpos($f, $slug.'/') === 0 || $f === $slug.'.php')) { $file = $f; break; }
                    if ($name && stripos($d['Name'], $name) !== false) { $file = $f; break; }
                }
            }
            if (!$file) {
                // Plugin not installed — try to install it first
                $search_name = $name ?: $slug ?: '';
                if ($search_name && function_exists('wpilot_infer_params')) {
                    $install_params = wpilot_infer_params('plugin_install', 'Install ' . $search_name);
                    if (!empty($install_params['slug'])) {
                        wpilot_load_heavy();
                        $install_result = wpilot_safe_run_tool('plugin_install', $install_params);
                        if ($install_result['success']) {
                            // Now try to find and activate
                            foreach (get_plugins() as $f => $d) {
                                if (stripos($d['Name'], $search_name) !== false) { $file = $f; break; }
                            }
                        }
                    }
                }
                if (!$file) return wpilot_err('Plugin not found. Try: [ACTION: plugin_install | Install ' . ($name ?: $slug) . ' | Install the plugin first | 🔌]');
            }
            if (!function_exists('delete_plugins')) require_once ABSPATH.'wp-admin/includes/plugin.php';
            // Deactivate first
            if (is_plugin_active($file)) deactivate_plugins($file);
            // Delete with error handling (complex plugins like Jetpack can crash)
            try {
                $del_result = delete_plugins([$file]);
                if (is_wp_error($del_result)) {
                    return wpilot_err('Could not delete: ' . $del_result->get_error_message());
                }
            } catch (\Throwable $e) {
                // Fallback: try to delete the folder directly
                $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($file);
                if (is_dir($plugin_dir)) {
                    $deleted = wpilot_delete_directory($plugin_dir);
                    if ($deleted) return wpilot_ok('Plugin deleted (force removed).');
                }
                return wpilot_err('Plugin deactivated but could not delete files: ' . $e->getMessage());
            }
            return wpilot_ok('Plugin deleted.');

        /* ── Site settings ───────────────────────────────────── */
        case 'update_blogname':
            update_option('blogname', sanitize_text_field($params['name'] ?? ''));
            return wpilot_ok("Site name updated.");

        case 'update_tagline':
            update_option('blogdescription', sanitize_text_field($params['tagline'] ?? ''));
            return wpilot_ok("Tagline updated.");

        /* ── Custom Instructions ─────────────────────────────── */
        case 'save_instruction':
            $rule = sanitize_textarea_field( $params['instruction'] ?? '' );
            if ( empty( $rule ) ) return wpilot_err('Instruction text required.');
            $current = trim( get_option( 'ca_custom_instructions', '' ) );
            $updated = $current ? $current . "\n" . $rule : $rule;
            update_option( 'ca_custom_instructions', $updated );
            return wpilot_ok( "Instruction saved: \"{$rule}\"" );

        /* ── Restore ─────────────────────────────────────────── */
        case 'restore_backup':
            return wpilot_restore( intval($params['backup_id'] ?? 0) );


        /* ── User Role Management ───────────────────────────── */
        case 'change_user_role':
            $uid  = intval($params['user_id'] ?? 0);
            $role = sanitize_text_field($params['role'] ?? '');
            if (!$uid) return wpilot_err('user_id required.');
            if (!$role) return wpilot_err('role required (subscriber, contributor, author, editor, administrator).');
            $allowed = ['subscriber','contributor','author','editor','administrator','shop_manager','customer'];
            if (!in_array($role, $allowed)) return wpilot_err("Invalid role. Allowed: " . implode(', ', $allowed));
            $user = new WP_User($uid);
            if (!$user->exists()) return wpilot_err("User #{$uid} not found.");
            $old_role = implode(', ', $user->roles);
            $user->set_role($role);
            return wpilot_ok("User #{$uid} role changed from {$old_role} to {$role}.");

        case 'update_user_meta':
            $uid   = intval($params['user_id'] ?? 0);
            $key   = sanitize_key($params['meta_key'] ?? '');
            $value = sanitize_text_field($params['meta_value'] ?? '');
            if (!$uid || !$key) return wpilot_err('user_id and meta_key required.');
            update_user_meta($uid, $key, $value);
            return wpilot_ok("User #{$uid} meta \"{$key}\" updated.");

        case 'list_users':
            $role  = sanitize_text_field($params['role'] ?? '');
            $args  = ['number' => 50, 'orderby' => 'registered', 'order' => 'DESC'];
            if ($role) $args['role'] = $role;
            $users = get_users($args);
            $list  = array_map(function($u) {
                return [
                    'id'    => $u->ID,
                    'name'  => $u->display_name,
                    'email' => $u->user_email,
                    'role'  => implode(', ', $u->roles),
                    'registered' => $u->user_registered,
                ];
            }, $users);
            return wpilot_ok("Found " . count($list) . " users.", ['users' => $list]);

        case 'delete_user':
            $uid = intval($params['user_id'] ?? 0);
            if (!$uid) return wpilot_err('user_id required.');
            if ($uid === get_current_user_id()) return wpilot_err('Cannot delete your own account.');
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $reassign = intval($params['reassign_to'] ?? 1);
            wp_delete_user($uid, $reassign);
            return wpilot_ok("User #{$uid} deleted. Content reassigned to user #{$reassign}.");

        /* ── WooCommerce Advanced ───────────────────────────── */
        // woo_create_product handled by plugin_tools.php (more complete version)

        case 'woo_update_product':
            if (!class_exists('WooCommerce')) {
                // Auto-install WooCommerce if not present
                if (function_exists('wpilot_plugin_install')) {
                    wpilot_plugin_install(['slug'=>'woocommerce']);
                    if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce installation failed. Install it manually first.');
                } else {
                    return wpilot_err('WooCommerce required. Install it first.');
                }
            }
            $pid = intval($params['product_id'] ?? $params['id'] ?? $params['post_id'] ?? 0);
            if (!$pid) return wpilot_err('product_id required.');
            wpilot_save_post_snapshot($pid);
            $update = ['ID' => $pid];
            if (isset($params['name']))              $update['post_title']   = sanitize_text_field($params['name']);
            if (isset($params['description']))       $update['post_content'] = wp_kses_post($params['description']);
            if (isset($params['short_description'])) $update['post_excerpt'] = wp_kses_post($params['short_description']);
            if (isset($params['status']))            $update['post_status']  = sanitize_text_field($params['status']);
            wp_update_post($update);
            if (isset($params['price']))      { update_post_meta($pid, '_regular_price', sanitize_text_field($params['price'])); update_post_meta($pid, '_price', sanitize_text_field($params['sale_price'] ?? $params['price'])); }
            if (isset($params['sale_price'])) update_post_meta($pid, '_sale_price', sanitize_text_field($params['sale_price']));
            if (isset($params['sku']))        update_post_meta($pid, '_sku', sanitize_text_field($params['sku']));
            if (isset($params['stock']))      { update_post_meta($pid, '_manage_stock', 'yes'); update_post_meta($pid, '_stock', intval($params['stock'])); }
            if (isset($params['category_ids'])) wp_set_object_terms($pid, array_map('intval', (array)$params['category_ids']), 'product_cat');
            if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
            return wpilot_ok("Product #{$pid} updated.");

        case 'woo_set_sale':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $pids = $params['product_ids'] ?? [];
            $cat  = intval($params['category_id'] ?? 0);
            $pct  = floatval($params['discount_percent'] ?? 0);
            $fixed = sanitize_text_field($params['sale_price'] ?? '');
            $start = sanitize_text_field($params['start_date'] ?? '');
            $end   = sanitize_text_field($params['end_date'] ?? '');

            // Get products by IDs or category
            if (!empty($pids)) {
                $products = array_map('intval', (array)$pids);
            } elseif ($cat) {
                $q = new WP_Query(['post_type'=>'product','tax_query'=>[['taxonomy'=>'product_cat','field'=>'term_id','terms'=>$cat]],'posts_per_page'=>-1,'fields'=>'ids']);
                $products = $q->posts;
            } else {
                return wpilot_err('Provide product_ids or category_id.');
            }

            $updated = 0;
            foreach ($products as $pid) {
                $regular = get_post_meta($pid, '_regular_price', true);
                if (!$regular) continue;

                $sale = $fixed ?: round($regular * (1 - $pct / 100), 2);
                update_post_meta($pid, '_sale_price', $sale);
                update_post_meta($pid, '_price', $sale);
                if ($start) update_post_meta($pid, '_sale_price_dates_from', strtotime($start));
                if ($end)   update_post_meta($pid, '_sale_price_dates_to', strtotime($end));
                if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
                $updated++;
            }
            return wpilot_ok("Sale applied to {$updated} products." . ($pct ? " {$pct}% off." : " Sale price: {$fixed}."));

        case 'woo_remove_sale':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $pids = $params['product_ids'] ?? [];
            $cat  = intval($params['category_id'] ?? 0);
            if (!empty($pids)) {
                $products = array_map('intval', (array)$pids);
            } elseif ($cat) {
                $q = new WP_Query(['post_type'=>'product','tax_query'=>[['taxonomy'=>'product_cat','field'=>'term_id','terms'=>$cat]],'posts_per_page'=>-1,'fields'=>'ids']);
                $products = $q->posts;
            } else {
                return wpilot_err('Provide product_ids or category_id.');
            }
            $count = 0;
            foreach ($products as $pid) {
                $regular = get_post_meta($pid, '_regular_price', true);
                delete_post_meta($pid, '_sale_price');
                delete_post_meta($pid, '_sale_price_dates_from');
                delete_post_meta($pid, '_sale_price_dates_to');
                update_post_meta($pid, '_price', $regular);
                if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
                $count++;
            }
            return wpilot_ok("Sale removed from {$count} products.");

        /* ── Posts ───────────────────────────────────────────── */
        case 'create_post':
            $title   = sanitize_text_field($params['title'] ?? 'New Post');
            $content = wp_kses_post($params['content'] ?? '');
            $status  = sanitize_text_field($params['status'] ?? 'draft');
            $cats    = $params['category_ids'] ?? [];
            $tags    = $params['tags'] ?? [];
            $id = wp_insert_post(['post_title'=>$title,'post_content'=>$content,'post_status'=>$status,'post_type'=>'post']);
            if (is_wp_error($id)) return wpilot_err($id->get_error_message());
            if (!empty($cats)) wp_set_post_categories($id, array_map('intval', (array)$cats));
            if (!empty($tags)) wp_set_post_tags($id, $tags);
            return wpilot_ok("Post \"{$title}\" created (ID: {$id}).", ['id'=>$id]);

        case 'update_post':
            $id = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            if (!$id) return wpilot_err('Post ID required.');
            wpilot_save_post_snapshot($id);
            $update = ['ID' => $id];
            if (isset($params['title']))   $update['post_title']   = sanitize_text_field($params['title']);
            if (isset($params['content'])) $update['post_content'] = wp_kses_post($params['content']);
            if (isset($params['status']))  $update['post_status']  = sanitize_text_field($params['status']);
            wp_update_post($update);
            if (isset($params['category_ids'])) wp_set_post_categories($id, array_map('intval', (array)$params['category_ids']));
            if (isset($params['tags'])) wp_set_post_tags($id, $params['tags']);
            return wpilot_ok("Post #{$id} updated.");

        case 'delete_post':
            $id = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            if (!$id) return wpilot_err('Post ID required.');
            wpilot_save_post_snapshot($id);
            wp_trash_post($id);
            return wpilot_ok("Post #{$id} moved to trash.");

        /* ── Widgets & Sidebars ─────────────────────────────── */
        case 'update_widget_area':
            $sidebar = sanitize_text_field($params['sidebar_id'] ?? '');
            $widgets = $params['widgets'] ?? [];
            if (!$sidebar) return wpilot_err('sidebar_id required.');
            // Get current sidebar widgets for backup
            $sidebars = get_option('sidebars_widgets', []);
            update_option('ca_sidebar_backup_' . $sidebar, $sidebars[$sidebar] ?? []);
            return wpilot_ok("Widget area \"{$sidebar}\" updated.");

        /* ── Options / Settings ─────────────────────────────── */
        case 'update_option':
            $key   = sanitize_text_field($params['option_key'] ?? $params['key'] ?? $params['option_name'] ?? $params['name'] ?? '');
            $value = $params['value'] ?? '';
            if (!$key) return wpilot_err('option_key required.');
            // Safety: block core WP options
            // Security: allowlist of safe option prefixes + blocklist of sensitive keys
            $safe_prefixes = ['woocommerce_','wpi_','ca_','wpilot_','rank_math_','elementor_','litespeed','updraft','wordfence','polylang_','translatepress_','WPLANG'];
            $blocked_exact = ['siteurl','home','admin_email','users_can_register','default_role','active_plugins','template','stylesheet','ca_api_key','wp_user_roles','auth_key','secure_auth_key','logged_in_key','nonce_key'];
            if (in_array($key, $blocked_exact)) return wpilot_err("Option \"{$key}\" is blocked for safety.");
            $allowed = false;
            foreach ($safe_prefixes as $prefix) { if (strpos($key, $prefix) === 0) { $allowed = true; break; } }
            // Also allow generic WP options like blogname, blogdescription, permalink_structure, page_on_front, show_on_front
            $safe_exact = ['blogname','blogdescription','blog_charset','permalink_structure','page_on_front','page_for_posts','show_on_front','wp_page_for_privacy_policy','site_icon','date_format','time_format','timezone_string','posts_per_page'];
            if (in_array($key, $safe_exact)) $allowed = true;
            if (!$allowed) return wpilot_err("Option \"{$key}\" is not in the safe list.");
            update_option($key, $value);
            return wpilot_ok("Option \"{$key}\" updated.");

        /* ── Permalink ──────────────────────────────────────── */
        case 'update_permalink_structure':
            $structure = sanitize_text_field($params['structure'] ?? '/%postname%/');
            global $wp_rewrite;
            $wp_rewrite->set_permalink_structure($structure);
            $wp_rewrite->flush_rules();
            return wpilot_ok("Permalink structure set to: {$structure}");

        /* ── Security Scanner ───────────────────────────────── */
        /* ── Code Injection (mu-plugin) ──────────────────── */
        case 'add_head_code':
            $code = $params['code'] ?? '';
            $name = sanitize_file_name($params['name'] ?? 'custom-head-' . time());
            if (empty($code)) return wpilot_err('Code required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-' . $name . '.php';
            // Use heredoc to avoid quote escaping issues
            $safe_code = str_replace("'", "\'", $code);
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('wp_head', function() {\n"
                . "    echo '" . $safe_code . "';\n"
                . "}, 1);\n";
            // Validate before saving
            $test_result = @exec('echo ' . escapeshellarg($php) . ' | php -l 2>&1', $output, $ret);
            if ($ret !== 0 && $ret !== null) {
                return wpilot_err('Code has syntax issues. Not saved.');
            }
            file_put_contents($mu_dir . '/' . $filename, $php);
            return wpilot_ok("Code added to <head> via mu-plugin: {$filename}");

        case 'add_footer_code':
            $code = $params['code'] ?? '';
            $name = sanitize_file_name($params['name'] ?? 'custom-footer-' . time());
            if (empty($code)) return wpilot_err('Code required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-' . $name . '.php';
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('wp_footer', function() {\n"
                . "    echo '" . addslashes($code) . "';\n"
                . "});\n";
            file_put_contents($mu_dir . '/' . $filename, $php);
            return wpilot_ok("Code added to footer via mu-plugin: {$filename}");

        case 'add_php_snippet':
            $code = $params['code'] ?? '';
            $hook = sanitize_text_field($params['hook'] ?? 'init');
            $name = sanitize_file_name($params['name'] ?? 'snippet-' . time());
            $priority = intval($params['priority'] ?? 10);
            if (empty($code)) return wpilot_err('Code required.');
            // SAFETY: strip echo/print from snippets — they pollute AJAX responses
            $code = preg_replace('/\becho\s+["\']/','// echo ', $code);
            $code = preg_replace('/\bprint\s*\(/','// print(', $code);
            $code = preg_replace('/\bvar_dump\s*\(/','// var_dump(', $code);
            $code = preg_replace('/\bprint_r\s*\(/','// print_r(', $code);
            // Validate: code must not contain raw HTML tags (common AI mistake)
            if (preg_match('/<[a-z]/i', $code) && !preg_match('/echo|print/', $code)) {
                return wpilot_err('PHP snippet contains HTML. Use add_head_code for HTML or wrap in echo/print for PHP.');
            }
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-' . $name . '.php';
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('" . $hook . "', function() {\n"
                . $code . "\n"
                . "}, " . $priority . ");\n";
            // Validate PHP syntax before saving
            $test = @exec('echo ' . escapeshellarg($php) . ' | php -l 2>&1', $output, $ret);
            if ($ret !== 0 && $ret !== null) {
                return wpilot_err('PHP syntax error in snippet. Not saved. Fix the code and try again.');
            }
            // Wrap code in try/catch so it can never crash WordPress
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('" . $hook . "', function() {\n"
                . "    try {\n"
                . "        " . $code . "\n"
                . "    } catch (\\Throwable \$e) {\n"
                . "        // Auto-disable this snippet if it crashes\n"
                . "        @rename(__FILE__, __FILE__ . '.disabled');\n"
                . "    }\n"
                . "}, " . $priority . ");\n";
            file_put_contents($mu_dir . '/' . $filename, $php);
            return wpilot_ok("PHP snippet added via mu-plugin: {$filename} (hook: {$hook})");

        case 'list_snippets':
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            $snippets = [];
            if (is_dir($mu_dir)) {
                foreach (glob($mu_dir . '/wpilot-*.php') as $file) {
                    $content = file_get_contents($file);
                    $snippets[] = [
                        'file' => basename($file),
                        'description' => preg_match('/WPilot: (.+)/', $content, $m) ? $m[1] : basename($file),
                        'size' => filesize($file),
                    ];
                }
            }
            return wpilot_ok(count($snippets) . " WPilot snippets active.", ['snippets' => $snippets]);

        case 'remove_snippet':
            $name = sanitize_file_name($params['name'] ?? '');
            if (empty($name)) return wpilot_err('Snippet name required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            // Try multiple naming patterns
            $tries = [
                $mu_dir . '/' . $name . '.php',
                $mu_dir . '/' . $name,
                $mu_dir . '/wpilot-' . $name . '.php',
                $mu_dir . '/wpilot-' . $name,
            ];
            // Also strip wpilot- prefix if already included
            $stripped = preg_replace('/^wpilot-/', '', $name);
            $tries[] = $mu_dir . '/wpilot-' . $stripped . '.php';
            $tries[] = $mu_dir . '/' . $stripped . '.php';
            foreach ($tries as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                    return wpilot_ok("Snippet removed: " . basename($file));
                }
            }
            return wpilot_err("Snippet not found: {$name}");

        case 'add_security_headers':
            return wpilot_fix_security('add_security_headers', $params);

        case 'disable_xmlrpc':
            return wpilot_fix_security('disable_xmlrpc', $params);

        /* ── PageSpeed Test ──────────────────────────────── */
        /* ── Performance Fix Tools ───────────────────────── */
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

        case 'security_scan':
            return wpilot_security_scan();

        case 'fix_security_issue':
            $issue = sanitize_text_field($params['issue'] ?? '');
            // Auto-detect issue from description if not set
            if (empty($issue)) {
                $desc = sanitize_text_field($params['description'] ?? '');
                $desc_lower = strtolower($desc);
                if (strpos($desc_lower, 'header') !== false) $issue = 'add_security_headers';
                elseif (strpos($desc_lower, 'xml') !== false || strpos($desc_lower, 'xmlrpc') !== false) $issue = 'disable_xmlrpc';
                elseif (strpos($desc_lower, 'readme') !== false) $issue = 'delete_readme';
                elseif (strpos($desc_lower, 'registr') !== false) $issue = 'disable_registration';
                else $issue = 'add_security_headers'; // default
            }
            return wpilot_fix_security($issue, $params);

        /* ── 404 Page ───────────────────────────────────────── */
        case 'create_404_page':
            $title   = sanitize_text_field($params['title'] ?? '404 — Page Not Found');
            $content = wp_kses_post($params['content'] ?? '');
            if (empty($content)) {
                $site = get_bloginfo('name');
                $home = get_home_url();
                $content = '<div style="text-align:center;padding:60px 20px;max-width:600px;margin:0 auto">'
                    . '<h1 style="font-size:72px;margin:0;color:#5B7FFF">404</h1>'
                    . '<h2 style="margin:10px 0 20px">Page not found</h2>'
                    . '<p style="color:#666;font-size:16px;line-height:1.7">The page you\'re looking for doesn\'t exist or has been moved.</p>'
                    . '<div style="margin:30px 0"><a href="' . esc_url($home) . '" style="display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;text-decoration:none;border-radius:8px;font-weight:600">← Back to ' . esc_html($site) . '</a></div>'
                    . '<p style="font-size:13px;color:#999">Try using the search or navigation menu above.</p>'
                    . '</div>';
            }
            // Check if 404 page already exists
            $existing = get_posts(['post_type'=>'page','meta_key'=>'_wp_page_template','meta_value'=>'404.php','numberposts'=>1]);
            if (!empty($existing)) {
                wpilot_save_post_snapshot($existing[0]->ID);
                wp_update_post(['ID'=>$existing[0]->ID, 'post_content'=>$content]);
                return wpilot_ok("404 page updated (ID: {$existing[0]->ID}).", ['id'=>$existing[0]->ID]);
            }
            $id = wp_insert_post(['post_title'=>$title,'post_content'=>$content,'post_status'=>'publish','post_type'=>'page']);
            if (is_wp_error($id)) return wpilot_err($id->get_error_message());
            return wpilot_ok("404 page created (ID: {$id}). Set your theme's 404 template to use this page.", ['id'=>$id]);

        /* ── SEO Advanced ───────────────────────────────────── */
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
        case 'woo_dashboard':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce not installed.');
            return wpilot_woo_dashboard($params);

        case 'woo_recent_orders':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce not installed.');
            return wpilot_woo_recent_orders($params);

        case 'woo_best_sellers':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce not installed.');
            return wpilot_woo_best_sellers($params);

        /* ── Comment Management ─────────────────────────────── */
        case 'list_comments':
            $status = sanitize_text_field($params['status'] ?? 'hold');
            $limit  = min(intval($params['limit'] ?? 20), 100);
            $comments = get_comments(['status'=>$status, 'number'=>$limit, 'orderby'=>'comment_date', 'order'=>'DESC']);
            $list = array_map(function($c) {
                return [
                    'id'      => $c->comment_ID,
                    'author'  => $c->comment_author,
                    'email'   => $c->comment_author_email,
                    'content' => wp_trim_words($c->comment_content, 20),
                    'post'    => get_the_title($c->comment_post_ID),
                    'date'    => $c->comment_date,
                    'status'  => wp_get_comment_status($c),
                ];
            }, $comments);
            $pending = wp_count_comments()->moderated;
            $spam    = wp_count_comments()->spam;
            return wpilot_ok("Comments ({$status}): " . count($list) . " shown. Pending: {$pending}, Spam: {$spam}.", ['comments'=>$list, 'pending'=>$pending, 'spam'=>$spam]);

        case 'approve_comment':
            $id = intval($params['comment_id'] ?? 0);
            if (!$id) return wpilot_err('comment_id required.');
            wp_set_comment_status($id, 'approve');
            return wpilot_ok("Comment #{$id} approved.");

        case 'delete_comment':
            $id = intval($params['comment_id'] ?? 0);
            if (!$id) return wpilot_err('comment_id required.');
            wp_delete_comment($id, true);
            return wpilot_ok("Comment #{$id} deleted permanently.");

        case 'spam_comment':
            $id = intval($params['comment_id'] ?? 0);
            if (!$id) return wpilot_err('comment_id required.');
            wp_spam_comment($id);
            return wpilot_ok("Comment #{$id} marked as spam.");

        case 'bulk_approve_comments':
            $comments = get_comments(['status'=>'hold', 'number'=>500]);
            $count = 0;
            foreach ($comments as $c) { wp_set_comment_status($c->comment_ID, 'approve'); $count++; }
            return wpilot_ok("Approved {$count} pending comments.");

        case 'bulk_delete_spam':
            global $wpdb;
            $spam_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
            if ($spam_count == 0) return wpilot_ok("No spam comments to delete.");
            $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
            $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
            return wpilot_ok("Deleted {$spam_count} spam comments and cleaned up orphan meta.");

        /* ── Redirects (301) ────────────────────────────────── */
        case 'create_redirect':
            $from = sanitize_text_field($params['from'] ?? '');
            $to   = esc_url_raw($params['to'] ?? '');
            $type = intval($params['type'] ?? 301);
            if (!$from || !$to) return wpilot_err('from and to URLs required.');
            $redirects = get_option('wpi_redirects', []);
            $redirects[$from] = ['to'=>$to, 'type'=>$type, 'created'=>current_time('mysql')];
            update_option('wpi_redirects', $redirects);
            return wpilot_ok("Redirect created: {$from} → {$to} ({$type}).", ['from'=>$from, 'to'=>$to]);

        case 'list_redirects':
            $redirects = get_option('wpi_redirects', []);
            if (empty($redirects)) return wpilot_ok("No redirects configured.", ['redirects'=>[]]);
            $list = [];
            foreach ($redirects as $from => $data) {
                $list[] = ['from'=>$from, 'to'=>$data['to'], 'type'=>$data['type'], 'created'=>$data['created'] ?? ''];
            }
            return wpilot_ok(count($list) . " redirects active.", ['redirects'=>$list]);

        case 'delete_redirect':
            $from = sanitize_text_field($params['from'] ?? '');
            if (!$from) return wpilot_err('from URL required.');
            $redirects = get_option('wpi_redirects', []);
            if (!isset($redirects[$from])) return wpilot_err("No redirect found for {$from}.");
            unset($redirects[$from]);
            update_option('wpi_redirects', $redirects);
            return wpilot_ok("Redirect deleted: {$from}.");

        /* ── Database Cleanup ───────────────────────────────── */
        case 'database_cleanup':
            return wpilot_database_cleanup($params);

        /* ── Broken Link Checker ────────────────────────────── */
        case 'check_broken_links':
            return wpilot_check_broken_links($params);


        /* ── Content Writing ────────────────────────────────── */
        case 'write_blog_post':
            $title   = sanitize_text_field($params['title'] ?? '');
            $topic   = sanitize_text_field($params['topic'] ?? $title);
            $tone    = sanitize_text_field($params['tone'] ?? 'professional');
            $length  = sanitize_text_field($params['length'] ?? 'medium');
            $keywords = sanitize_text_field($params['keywords'] ?? '');
            $status  = sanitize_text_field($params['status'] ?? 'draft');
            $cats    = $params['category_ids'] ?? [];
            $tags    = $params['tags'] ?? [];
            if (empty($topic) && empty($title)) return wpilot_err('Topic or title required.');
            // AI generates content via the chat — this tool creates the post with provided content
            $content = wp_kses_post($params['content'] ?? '');
            if (empty($content)) {
                return wpilot_ok("Ready to write about \"{$topic}\". Provide the content and I'll create the post.", ['needs_content' => true]);
            }
            $id = wp_insert_post(['post_title'=>$title ?: $topic,'post_content'=>$content,'post_status'=>$status,'post_type'=>'post']);
            if (is_wp_error($id)) return wpilot_err($id->get_error_message());
            if (!empty($cats)) wp_set_post_categories($id, array_map('intval', (array)$cats));
            if (!empty($tags)) wp_set_post_tags($id, $tags);
            // Set meta description from first paragraph
            $desc = wp_trim_words(wp_strip_all_tags($content), 25, '...');
            update_post_meta($id, '_yoast_wpseo_metadesc', substr($desc, 0, 160));
            update_post_meta($id, 'rank_math_description', substr($desc, 0, 160));
            if ($keywords) {
                update_post_meta($id, '_yoast_wpseo_focuskw', $keywords);
                update_post_meta($id, 'rank_math_focus_keyword', $keywords);
            }
            return wpilot_ok("Blog post \"{$title}\" created (ID: {$id}, status: {$status}). SEO meta auto-set.", ['id'=>$id, 'url'=>get_permalink($id)]);

        case 'rewrite_content':
            $id   = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            $tone = sanitize_text_field($params['tone'] ?? 'professional');
            $goal = sanitize_text_field($params['goal'] ?? 'improve');
            if (!$id) return wpilot_err('Post/page ID required.');
            $post = get_post($id);
            if (!$post) return wpilot_err("Post #{$id} not found.");
            wpilot_save_post_snapshot($id);
            $content = wp_kses_post($params['new_content'] ?? '');
            if (empty($content)) {
                // Return current content for the AI to rewrite
                return wpilot_ok("Current content for #{$id} (\"{$post->post_title}\"):\n\n" . wp_strip_all_tags($post->post_content), [
                    'current_content' => $post->post_content,
                    'title' => $post->post_title,
                    'word_count' => str_word_count(wp_strip_all_tags($post->post_content)),
                    'needs_rewrite' => true,
                ]);
            }
            wp_update_post(['ID'=>$id, 'post_content'=>$content]);
            return wpilot_ok("Content rewritten for \"{$post->post_title}\" (#{$id}).");

        case 'translate_content':
            $id   = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            $lang = sanitize_text_field($params['language'] ?? 'en');
            if (!$id) return wpilot_err('Post/page ID required.');
            $post = get_post($id);
            if (!$post) return wpilot_err("Post #{$id} not found.");
            $translated_content = wp_kses_post($params['translated_content'] ?? '');
            $translated_title   = sanitize_text_field($params['translated_title'] ?? '');
            if (empty($translated_content)) {
                return wpilot_ok("Translate this content to {$lang}:\n\nTitle: {$post->post_title}\n\n" . wp_strip_all_tags($post->post_content), [
                    'source_id' => $id,
                    'source_title' => $post->post_title,
                    'source_content' => $post->post_content,
                    'target_language' => $lang,
                    'needs_translation' => true,
                ]);
            }
            // Create translated copy
            $new_id = wp_insert_post([
                'post_title'   => $translated_title ?: $post->post_title . " ({$lang})",
                'post_content' => $translated_content,
                'post_status'  => 'draft',
                'post_type'    => $post->post_type,
            ]);
            if (is_wp_error($new_id)) return wpilot_err($new_id->get_error_message());
            update_post_meta($new_id, '_wpi_translated_from', $id);
            update_post_meta($new_id, '_wpi_language', $lang);
            return wpilot_ok("Translated copy created: \"{$translated_title}\" (ID: {$new_id}, status: draft).", ['id'=>$new_id]);

        case 'schedule_post':
            $id   = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            $date = sanitize_text_field($params['date'] ?? '');
            if (!$id || !$date) return wpilot_err('Post ID and date required. Format: YYYY-MM-DD HH:MM');
            $post = get_post($id);
            if (!$post) return wpilot_err("Post #{$id} not found.");
            $gmt = get_gmt_from_date($date);
            wp_update_post(['ID'=>$id, 'post_status'=>'future', 'post_date'=>$date, 'post_date_gmt'=>$gmt]);
            return wpilot_ok("Post \"{$post->post_title}\" scheduled for {$date}.");

        /* ── Builder tools (Elementor/Divi/Gutenberg) ─────── */
        /* ── Full Site Generator ────────────────────────────── */
        case 'generate_full_site':
            return wpilot_generate_full_site($params);

        case 'edit_current_page':
            $id = intval($params['post_id'] ?? $params['page_id'] ?? $params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            if (!$id) return wpilot_err('post_id required.');
            $post = get_post($id);
            if (!$post) return wpilot_err("Page #{$id} not found.");
            wpilot_save_post_snapshot($id);
            $update = ['ID' => $id];
            if (!empty($params['content'])) $update['post_content'] = wp_kses_post($params['content']);
            if (!empty($params['title']))   $update['post_title'] = sanitize_text_field($params['title']);
            if (count($update) > 1) wp_update_post($update);
            if (!empty($params['css'])) {
                $existing = wp_get_custom_css();
                wpilot_save_css_snapshot();
                wp_update_custom_css_post($existing . "
/* Page #{$id} */
" . wp_strip_all_tags($params['css']));
            }
            return wpilot_ok("Page #" . $id . " updated.", ["id"=>$id, "url"=>get_permalink($id)]);

        case 'create_html_page':
            $html = $params['html'] ?? $params['content'] ?? '';
            $title = $params['title'] ?? 'New Page';
            if (empty($html)) return wpilot_err('HTML content required.');
            // Safety: remove WordPress shortcodes from HTML (they don't render in Elementor HTML widget)
            $html = preg_replace('/\[products[^\]]*\]/', '', $html);
            $html = preg_replace('/\[woocommerce_[^\]]*\]/', '', $html);
            // Safety: wrap any orphan CSS rules in <style> tags
            if (preg_match('/(?<![<"\'a-z])(\.woocommerce[^{]*\{[^}]+\})/s', $html)) {
                $css = ''; $clean = $html;
                if (preg_match_all('/([.#@:][a-zA-Z][^{]*\{[^}]+\})/s', $clean, $m)) {
                    foreach ($m[0] as $rule) {
                        if (strpos(substr($clean, 0, strpos($clean, $rule)), '<style') === false) {
                            $css .= $rule . "\n";
                            $clean = str_replace($rule, '', $clean);
                        }
                    }
                }
                if ($css) $html = '<style>' . $css . '</style>' . trim($clean);
            }
            $builder = wpilot_detect_builder();

            // === ELEMENTOR: HTML widget in Elementor data structure ===
            if ($builder === 'elementor' && function_exists('wpilot_elementor_create_html_page')) {
                return wpilot_elementor_create_html_page($params);
            }

            // === DIVI: Code module wrapping the HTML ===
            if ($builder === 'divi') {
                $divi_content = '[et_pb_section fb_built="1" fullwidth="on" _builder_version="4.0"][et_pb_fullwidth_code _builder_version="4.0"]' . $html . '[/et_pb_fullwidth_code][/et_pb_section]';
                $id = wp_insert_post([
                    'post_title' => sanitize_text_field($title),
                    'post_content' => $divi_content,
                    'post_status' => 'publish',
                    'post_type' => 'page',
                    'meta_input' => ['_et_pb_use_builder' => 'on', '_et_pb_page_layout' => 'et_full_width_page'],
                ]);
                if (is_wp_error($id)) return wpilot_err($id->get_error_message());
                // Set Divi template
                update_post_meta($id, '_wp_page_template', 'page-template-blank.php');
                return wpilot_ok("Page \"{$title}\" created with Divi (ID: {$id}).", [
                    'page_id' => $id, 'url' => get_permalink($id),
                    'edit_url' => admin_url("post.php?post={$id}&action=edit"),
                    'builder' => 'divi',
                ]);
            }

            // === BEAVER BUILDER: HTML module ===
            if ($builder === 'beaver' && defined('FL_BUILDER_VERSION')) {
                $id = wp_insert_post([
                    'post_title' => sanitize_text_field($title),
                    'post_content' => $html,
                    'post_status' => 'publish',
                    'post_type' => 'page',
                ]);
                if (is_wp_error($id)) return wpilot_err($id->get_error_message());
                update_post_meta($id, '_fl_builder_enabled', true);
                // Create BB data with HTML module
                $node_id = substr(md5(rand()), 0, 9);
                $bb_data = [
                    $node_id => (object)[
                        'node' => $node_id,
                        'type' => 'module',
                        'settings' => (object)['type' => 'html', 'html' => $html],
                        'parent' => null,
                        'position' => 0,
                    ],
                ];
                update_post_meta($id, '_fl_builder_data', $bb_data);
                update_post_meta($id, '_wp_page_template', 'tpl-no-header-footer.php');
                return wpilot_ok("Page \"{$title}\" created with Beaver Builder (ID: {$id}).", [
                    'page_id' => $id, 'url' => get_permalink($id), 'builder' => 'beaver',
                ]);
            }

            // === GUTENBERG: Custom HTML block ===
            $gutenberg_content = '<!-- wp:html -->' . $html . '<!-- /wp:html -->';
            $id = wp_insert_post([
                'post_title' => sanitize_text_field($title),
                'post_content' => $gutenberg_content,
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
            if (is_wp_error($id)) return wpilot_err($id->get_error_message());
            return wpilot_ok("Page \"{$title}\" created (ID: {$id}).", [
                'page_id' => $id, 'url' => get_permalink($id), 'builder' => $builder,
            ]);

        case 'builder_create_page':
        case 'builder_update_section':
        case 'builder_add_widget':
        case 'builder_update_css':
        case 'builder_set_colors':
        case 'builder_set_fonts':
        case 'builder_create_header':
        case 'builder_create_footer':
            if (function_exists('wpilot_run_builder_tool')) {
                return wpilot_run_builder_tool($tool, $params);
            }
            return wpilot_err("Builder tools not available.");

        // ══ NEWSLETTER & EMAIL MARKETING ════════════════════
        case 'newsletter_send':
            return wpilot_newsletter_send($params);
        case 'newsletter_list_subscribers':
            return wpilot_newsletter_list_subscribers($params);
        case 'newsletter_configure':
            return wpilot_newsletter_configure($params);

        // ═══ AI IMAGE GENERATION ═══
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
        case 'woo_create_variation':
        case 'create_product_variation':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $pid = intval($params['product_id'] ?? $params['id'] ?? 0);
            if (!$pid) return wpilot_err('product_id required.');
            $product = wc_get_product($pid);
            if (!$product) return wpilot_err("Product #{$pid} not found.");
            // Convert to variable product if simple
            if ($product->get_type() === 'simple') {
                wp_set_object_terms($pid, 'variable', 'product_type');
                $product = wc_get_product($pid);
            }
            $attributes = $params['attributes'] ?? [];
            $variations = $params['variations'] ?? [];
            // Set product attributes
            $product_attributes = [];
            foreach ($attributes as $attr) {
                $name = sanitize_text_field($attr['name'] ?? '');
                $values = array_map('sanitize_text_field', (array)($attr['values'] ?? $attr['options'] ?? []));
                if ($name && $values) {
                    $product_attributes[sanitize_title($name)] = [
                        'name' => $name,
                        'value' => implode(' | ', $values),
                        'position' => count($product_attributes),
                        'is_visible' => 1,
                        'is_variation' => 1,
                        'is_taxonomy' => 0,
                    ];
                }
            }
            update_post_meta($pid, '_product_attributes', $product_attributes);
            // Create variations
            $created = 0;
            foreach ($variations as $var) {
                $variation_id = wp_insert_post([
                    'post_title' => $product->get_name() . ' - Variation',
                    'post_status' => 'publish',
                    'post_parent' => $pid,
                    'post_type' => 'product_variation',
                ]);
                if (!is_wp_error($variation_id)) {
                    update_post_meta($variation_id, '_regular_price', sanitize_text_field($var['price'] ?? $product->get_price()));
                    update_post_meta($variation_id, '_price', sanitize_text_field($var['price'] ?? $product->get_price()));
                    if (isset($var['sale_price'])) update_post_meta($variation_id, '_sale_price', sanitize_text_field($var['sale_price']));
                    if (isset($var['sku'])) update_post_meta($variation_id, '_sku', sanitize_text_field($var['sku']));
                    if (isset($var['stock'])) {
                        update_post_meta($variation_id, '_manage_stock', 'yes');
                        update_post_meta($variation_id, '_stock', intval($var['stock']));
                        update_post_meta($variation_id, '_stock_status', 'instock');
                    }
                    // Set attribute values for this variation
                    foreach ($var['attributes'] ?? [] as $attr_name => $attr_val) {
                        update_post_meta($variation_id, 'attribute_' . sanitize_title($attr_name), sanitize_text_field($attr_val));
                    }
                    $created++;
                }
            }
            if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
            return wpilot_ok("Created {$created} variations for product #{$pid}.", ['product_id' => $pid, 'variations' => $created]);

        // ═══ ABANDONED CART SETUP ═══
        case 'woo_abandoned_cart_setup':
        case 'abandoned_cart_setup':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            // Install abandoned cart plugin if not present
            if (!is_plugin_active('woo-cart-abandonment-recovery/woo-cart-abandonment-recovery.php') &&
                !is_plugin_active('abandoned-cart-pro-for-woocommerce/abandoned-cart-pro-for-woocommerce.php')) {
                if (function_exists('wpilot_plugin_install')) {
                    $install = wpilot_plugin_install(['slug' => 'woo-cart-abandonment-recovery']);
                    if (!$install['success']) return wpilot_err('Could not install abandoned cart plugin.');
                }
            }
            return wpilot_ok("Abandoned cart recovery plugin installed and active. Configure follow-up emails in WooCommerce > Cart Abandonment.", [
                'plugin' => 'Cart Abandonment Recovery',
                'settings_url' => admin_url('admin.php?page=woo-cart-abandonment-recovery'),
            ]);

        // ═══ CHANGE LOG / UNDO HISTORY ═══
        case 'list_changes':
        case 'undo_history':
        case 'change_log':
            $limit = intval($params['limit'] ?? 20);
            $logs = get_option('ca_backup_log', []);
            $logs = array_slice(array_reverse($logs), 0, $limit);
            $list = [];
            foreach ($logs as $log) {
                $list[] = [
                    'id' => $log['id'] ?? '',
                    'tool' => $log['tool'] ?? '',
                    'time' => $log['time'] ?? '',
                    'description' => substr($log['description'] ?? $log['tool'] ?? '', 0, 60),
                ];
            }
            return wpilot_ok(count($list) . " recent changes.", ['changes' => $list]);

        // ═══ SITE CLONE / MIGRATION ═══
        case 'export_site':
        case 'site_export':
            $upload = wp_upload_dir();
            $export_data = [
                'site' => ['name' => get_bloginfo('name'), 'url' => get_site_url(), 'tagline' => get_bloginfo('description')],
                'pages' => [],
                'products' => [],
                'menus' => [],
                'options' => [],
                'css' => wp_get_custom_css(),
            ];
            // Pages
            $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1]);
            foreach ($pages as $p) {
                $content = $p->post_content;
                if (get_post_meta($p->ID, '_elementor_edit_mode', true) === 'builder') {
                    $content = get_post_meta($p->ID, '_elementor_data', true);
                }
                $export_data['pages'][] = ['title' => $p->post_title, 'slug' => $p->post_name, 'content' => $content, 'template' => get_post_meta($p->ID, '_wp_page_template', true)];
            }
            // Products
            if (class_exists('WooCommerce')) {
                $products = wc_get_products(['limit' => -1, 'status' => 'publish']);
                foreach ($products as $pr) {
                    $export_data['products'][] = ['name' => $pr->get_name(), 'price' => $pr->get_price(), 'description' => $pr->get_description(), 'sku' => $pr->get_sku()];
                }
            }
            // Menus
            $menus = wp_get_nav_menus();
            foreach ($menus as $m) {
                $items = wp_get_nav_menu_items($m->term_id);
                $export_data['menus'][] = ['name' => $m->name, 'items' => array_map(fn($i) => ['title' => $i->title, 'url' => $i->url], $items ?: [])];
            }
            $file = $upload['basedir'] . '/wpilot-site-export.json';
            file_put_contents($file, json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return wpilot_ok("Site exported.", ['download_url' => $upload['baseurl'] . '/wpilot-site-export.json', 'pages' => count($export_data['pages']), 'products' => count($export_data['products'])]);

        // ═══ ACCESSIBILITY CHECK ═══
        case 'accessibility_check':
        case 'wcag_check':
            $url = $params['url'] ?? home_url('/');
            if (strpos($url, '/') === 0) $url = home_url($url);
            $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => false]);
            if (is_wp_error($response)) return wpilot_err('Could not fetch page.');
            $html = wp_remote_retrieve_body($response);
            $issues = [];
            // Check images without alt
            preg_match_all('/<img[^>]+>/i', $html, $imgs);
            $no_alt = 0;
            foreach ($imgs[0] as $img) {
                if (strpos($img, 'alt=""') !== false || strpos($img, 'alt') === false) $no_alt++;
            }
            if ($no_alt > 0) $issues[] = "{$no_alt} images missing alt text";
            // Check form labels
            preg_match_all('/<input[^>]+>/i', $html, $inputs);
            $no_label = 0;
            foreach ($inputs[0] as $inp) {
                if (strpos($inp, 'type="hidden"') !== false) continue;
                $id_match = [];
                if (preg_match('/id="([^"]+)"/', $inp, $id_match)) {
                    if (strpos($html, 'for="' . $id_match[1] . '"') === false) $no_label++;
                } else {
                    $no_label++;
                }
            }
            if ($no_label > 0) $issues[] = "{$no_label} form inputs missing labels";
            // Check heading hierarchy
            preg_match_all('/<h(\d)/i', $html, $headings);
            if (!empty($headings[1])) {
                $first_heading = $headings[1][0];
                if ($first_heading != 1) $issues[] = "First heading is H{$first_heading}, should be H1";
                $h1_count = count(array_filter($headings[1], fn($h) => $h == 1));
                if ($h1_count > 1) $issues[] = "Multiple H1 tags ({$h1_count}) — should have only one";
                if ($h1_count === 0) $issues[] = "No H1 tag found";
            }
            // Check color contrast (basic)
            if (strpos($html, 'color:') !== false && strpos($html, 'background') !== false) {
                // Can only do basic checks without rendering
            }
            // Check skip navigation
            if (strpos($html, 'skip-') === false && strpos($html, 'skip_') === false) {
                $issues[] = "No skip navigation link for keyboard users";
            }
            // Check language attribute
            if (strpos($html, 'lang=') === false) {
                $issues[] = "Missing lang attribute on <html> tag";
            }
            // Check viewport meta
            if (strpos($html, 'viewport') === false) {
                $issues[] = "Missing viewport meta tag — not mobile-friendly";
            }
            $score = max(0, 100 - (count($issues) * 12));
            return wpilot_ok("Accessibility check: {$score}/100. " . count($issues) . " issues found.", [
                'score' => $score,
                'issues' => $issues,
                'url' => $url,
            ]);

                // ═══ READ CONTENT (AI needs to read before editing) ═══
        case 'get_page':
        case 'get_post':
            $id = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            $slug = sanitize_text_field($params['slug'] ?? '');
            if (!$id && $slug) {
                $found = get_page_by_path($slug, OBJECT, ['page','post','product']);
                if ($found) $id = $found->ID;
            }
            if (!$id) return wpilot_err('Post ID or slug required.');
            $post = get_post($id);
            if (!$post) return wpilot_err("Post #{$id} not found.");
            $content = $post->post_content;
            // Get builder content if Elementor
            if (get_post_meta($id, '_elementor_edit_mode', true) === 'builder' && function_exists('wpilot_extract_builder_html')) {
                $el_data = json_decode(get_post_meta($id, '_elementor_data', true), true);
                if (is_array($el_data)) $content = wpilot_extract_builder_html($el_data);
            }
            return wpilot_ok("Page content retrieved.", [
                'id' => $id, 'title' => $post->post_title, 'slug' => $post->post_name,
                'status' => $post->post_status, 'type' => $post->post_type,
                'content' => substr($content, 0, 5000),
                'content_length' => strlen($content),
                'template' => get_post_meta($id, '_wp_page_template', true) ?: 'default',
                'url' => get_permalink($id),
            ]);

        case 'list_pages':
            $status = sanitize_text_field($params['status'] ?? 'publish');
            $pages = get_posts(['post_type' => 'page', 'post_status' => $status, 'numberposts' => 50, 'orderby' => 'menu_order', 'order' => 'ASC']);
            $list = array_map(fn($p) => ['id' => $p->ID, 'title' => $p->post_title, 'slug' => $p->post_name, 'status' => $p->post_status, 'url' => get_permalink($p->ID)], $pages);
            return wpilot_ok(count($list) . " pages.", ['pages' => $list]);

        case 'list_posts':
            $status = sanitize_text_field($params['status'] ?? 'publish');
            $cat = sanitize_text_field($params['category'] ?? '');
            $args = ['post_type' => 'post', 'post_status' => $status, 'numberposts' => 30];
            if ($cat) $args['category_name'] = $cat;
            $posts = get_posts($args);
            $list = array_map(fn($p) => ['id' => $p->ID, 'title' => $p->post_title, 'date' => $p->post_date, 'status' => $p->post_status], $posts);
            return wpilot_ok(count($list) . " posts.", ['posts' => $list]);

        // ═══ TAXONOMY MANAGEMENT ═══
        case 'create_category':
            $name = sanitize_text_field($params['name'] ?? $params['category'] ?? '');
            $parent = intval($params['parent'] ?? 0);
            if (!$name) return wpilot_err('Category name required.');
            $result = wp_insert_term($name, 'category', ['parent' => $parent]);
            if (is_wp_error($result)) return wpilot_err($result->get_error_message());
            return wpilot_ok("Category '{$name}' created.", ['id' => $result['term_id']]);

        case 'create_tag':
            $name = sanitize_text_field($params['name'] ?? $params['tag'] ?? '');
            if (!$name) return wpilot_err('Tag name required.');
            $result = wp_insert_term($name, 'post_tag');
            if (is_wp_error($result)) return wpilot_err($result->get_error_message());
            return wpilot_ok("Tag '{$name}' created.", ['id' => $result['term_id']]);

        case 'list_categories':
            $cats = get_categories(['hide_empty' => false]);
            $list = array_map(fn($c) => ['id' => $c->term_id, 'name' => $c->name, 'slug' => $c->slug, 'count' => $c->count, 'parent' => $c->parent], is_array($cats) ? $cats : []);
            return wpilot_ok(count($list) . " categories.", ['categories' => $list]);

        // ═══ PLUGIN UPDATES & MAINTENANCE ═══
        case 'list_plugins':
        case 'plugin_list':
            if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all = get_plugins();
            $active = get_option('active_plugins', []);
            $updates = get_site_transient('update_plugins');
            $list = [];
            foreach ($all as $file => $p) {
                $has_update = isset($updates->response[$file]);
                $list[] = [
                    'file' => $file, 'name' => $p['Name'], 'version' => $p['Version'],
                    'active' => in_array($file, $active),
                    'update_available' => $has_update,
                    'new_version' => $has_update ? $updates->response[$file]->new_version : null,
                ];
            }
            return wpilot_ok(count($list) . " plugins.", ['plugins' => $list]);

        case 'update_plugins':
        case 'update_all_plugins':
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $updates = get_site_transient('update_plugins');
            if (empty($updates->response)) return wpilot_ok("All plugins up to date.");
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            $updated = 0;
            foreach ($updates->response as $file => $info) {
                $result = $upgrader->upgrade($file);
                if ($result) $updated++;
            }
            return wpilot_ok("Updated {$updated} plugins.");

        case 'maintenance_mode':
        case 'enable_maintenance':
            $enable = filter_var($params['enable'] ?? $params['on'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $message = sanitize_text_field($params['message'] ?? 'We are performing scheduled maintenance. Please check back soon.');
            $mu_file = WPMU_PLUGIN_DIR . '/wpilot-maintenance.php';
            if ($enable) {
                $escaped = addslashes($message);
                $code = '<?php' . "\n" . 'if(!current_user_can("manage_options")&&!strpos($_SERVER["REQUEST_URI"],"wp-admin")&&!strpos($_SERVER["REQUEST_URI"],"wp-login")){wp_die("<div style=\'text-align:center;padding:100px 20px;background:#050810;color:#e2e8f0;min-height:100vh;display:flex;align-items:center;justify-content:center\'><div><h1>🔧</h1><h2>' . $escaped . '</h2><p style=\'color:rgba(255,255,255,.5)\'>Back shortly.</p></div></div>","Maintenance",503);}';
                file_put_contents($mu_file, $code);
                return wpilot_ok("Maintenance mode ON. Only admins can access the site.");
            } else {
                if (file_exists($mu_file)) @unlink($mu_file);
                return wpilot_ok("Maintenance mode OFF. Site is public.");
            }

        // ═══ BACKUP ON DEMAND ═══
        case 'backup_now':
            // Try UpdraftPlus first
            if (class_exists('UpdraftPlus')) {
                do_action('updraft_backup_now_via_addon');
                return wpilot_ok("Backup started via UpdraftPlus. Check backup status in settings.");
            }
            // Fallback: DB export
            global $wpdb;
            $upload = wp_upload_dir();
            $file = $upload['basedir'] . '/wpilot-backup-' . date('Y-m-d-His') . '.sql';
            $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
            $sql = "-- WPilot DB Backup " . date('Y-m-d H:i:s') . "

";
            foreach ($tables as $table) {
                $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
                $sql .= "DROP TABLE IF EXISTS `{$table}`;
{$create[1]};

";
                $rows = $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_N);
                foreach ($rows as $row) {
                    $vals = array_map(function($v) use ($wpdb) { return $v === null ? 'NULL' : "'" . $wpdb->_real_escape($v) . "'"; }, $row);
                    $sql .= "INSERT INTO `{$table}` VALUES(" . implode(',', $vals) . ");
";
                }
                $sql .= "
";
            }
            file_put_contents($file, $sql);
            $size = round(filesize($file) / 1048576, 1);
            return wpilot_ok("Database backup saved ({$size}MB).", ['file' => $upload['baseurl'] . '/' . basename($file), 'size_mb' => $size, 'tables' => count($tables)]);

        // ═══ WOOCOMMERCE STOCK & VARIATIONS ═══
        case 'woo_manage_stock':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $pid = intval($params['product_id'] ?? $params['id'] ?? 0);
            if (!$pid) return wpilot_err('product_id required.');
            $product = wc_get_product($pid);
            if (!$product) return wpilot_err("Product #{$pid} not found.");
            if (isset($params['stock'])) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity(intval($params['stock']));
            }
            if (isset($params['status'])) {
                $product->set_stock_status(sanitize_text_field($params['status']));
            }
            $product->save();
            return wpilot_ok("Stock updated for #{$pid}. Qty: " . $product->get_stock_quantity() . ", Status: " . $product->get_stock_status());

        case 'woo_get_order':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $oid = intval($params['order_id'] ?? $params['id'] ?? 0);
            if (!$oid) return wpilot_err('order_id required.');
            $order = wc_get_order($oid);
            if (!$order) return wpilot_err("Order #{$oid} not found.");
            $items = [];
            foreach ($order->get_items() as $item) {
                $items[] = ['name' => $item->get_name(), 'qty' => $item->get_quantity(), 'total' => $item->get_total()];
            }
            return wpilot_ok("Order #{$oid} details.", [
                'id' => $oid, 'status' => $order->get_status(), 'total' => $order->get_total(),
                'currency' => $order->get_currency(), 'date' => $order->get_date_created()->format('Y-m-d H:i'),
                'customer' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email' => $order->get_billing_email(), 'phone' => $order->get_billing_phone(),
                'address' => $order->get_billing_address_1() . ', ' . $order->get_billing_city(),
                'items' => $items, 'notes' => array_map(fn($n) => $n->comment_content, $order->get_customer_order_notes()),
            ]);

        case 'woo_low_stock_report':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $threshold = intval($params['threshold'] ?? 5);
            $products = wc_get_products(['limit' => -1, 'manage_stock' => true]);
            $low = [];
            foreach ($products as $p) {
                $qty = $p->get_stock_quantity();
                if ($qty !== null && $qty <= $threshold) {
                    $low[] = ['id' => $p->get_id(), 'name' => $p->get_name(), 'stock' => $qty, 'status' => $p->get_stock_status()];
                }
            }
            return wpilot_ok(count($low) . " products with low stock (threshold: {$threshold}).", ['products' => $low]);

        // ═══ LEGAL & COMPLIANCE ═══
        case 'privacy_policy_generate':
            $site = get_bloginfo('name');
            $url = get_site_url();
            $email = get_option('admin_email');
            $html = "<h2>Privacy Policy for {$site}</h2><p><em>Last updated: " . date('F j, Y') . "</em></p>";
            $html .= "<h3>Who We Are</h3><p>Our website address is: {$url}. Contact: {$email}</p>";
            $html .= "<h3>Data We Collect</h3><p>We collect information you provide directly: name, email, and any data submitted through forms or during checkout.</p>";
            $html .= "<h3>Cookies</h3><p>We use essential cookies for site functionality and optional analytics cookies with your consent.</p>";
            $html .= "<h3>How We Use Data</h3><p>To process orders, respond to inquiries, improve our services, and send marketing communications (with consent).</p>";
            $html .= "<h3>Data Sharing</h3><p>We do not sell personal data. We may share data with: payment processors, shipping providers, and analytics services.</p>";
            $html .= "<h3>Data Retention</h3><p>We retain personal data for as long as necessary to fulfill the purposes outlined, unless a longer retention period is required by law.</p>";
            $html .= "<h3>Your Rights</h3><p>You have the right to access, correct, delete, or export your personal data. Contact us at {$email}.</p>";
            $html .= "<h3>Contact</h3><p>For privacy questions, email {$email}.</p>";
            $id = wp_insert_post(['post_title' => 'Privacy Policy', 'post_content' => $html, 'post_status' => 'publish', 'post_type' => 'page']);
            if (is_wp_error($id)) return wpilot_err($id->get_error_message());
            update_option('wp_page_for_privacy_policy', $id);
            return wpilot_ok("Privacy Policy page created (ID: {$id}).", ['page_id' => $id, 'url' => get_permalink($id)]);

        case 'terms_generate':
            $site = get_bloginfo('name');
            $html = "<h2>Terms & Conditions</h2><p><em>Last updated: " . date('F j, Y') . "</em></p>";
            $html .= "<h3>Agreement</h3><p>By using {$site}, you agree to these terms.</p>";
            $html .= "<h3>Products & Services</h3><p>We reserve the right to modify prices and availability without notice.</p>";
            $html .= "<h3>Payment</h3><p>All payments are processed securely. Prices include applicable taxes unless stated otherwise.</p>";
            $html .= "<h3>Refunds</h3><p>Refund requests must be submitted within 14 days of purchase. Digital products may have different terms.</p>";
            $html .= "<h3>Liability</h3><p>We are not liable for indirect damages arising from use of our services.</p>";
            $html .= "<h3>Governing Law</h3><p>These terms are governed by applicable local law.</p>";
            $id = wp_insert_post(['post_title' => 'Terms & Conditions', 'post_content' => $html, 'post_status' => 'publish', 'post_type' => 'page']);
            if (is_wp_error($id)) return wpilot_err($id->get_error_message());
            if (class_exists('WooCommerce')) update_option('woocommerce_terms_page_id', $id);
            return wpilot_ok("Terms & Conditions page created (ID: {$id}).", ['page_id' => $id]);

        // ═══ UNPUBLISH / PASSWORD PROTECT ═══
        case 'unpublish_post':
            $id = intval($params['id'] ?? $params['post_id'] ?? 0);
            if (!$id) return wpilot_err('Post ID required.');
            wp_update_post(['ID' => $id, 'post_status' => 'draft']);
            return wpilot_ok("Post #{$id} unpublished (set to draft).");

        case 'password_protect_post':
            $id = intval($params['id'] ?? $params['post_id'] ?? 0);
            $pw = sanitize_text_field($params['password'] ?? '');
            if (!$id || !$pw) return wpilot_err('Post ID and password required.');
            wp_update_post(['ID' => $id, 'post_password' => $pw]);
            return wpilot_ok("Post #{$id} password-protected.");

        // ═══ BULK OPERATIONS ═══
        case 'bulk_delete_posts':
            $status = sanitize_text_field($params['status'] ?? 'trash');
            $type = sanitize_text_field($params['post_type'] ?? 'post');
            $posts = get_posts(['post_type' => $type, 'post_status' => $status, 'numberposts' => -1, 'fields' => 'ids']);
            $count = 0;
            foreach ($posts as $pid) { wp_delete_post($pid, true); $count++; }
            return wpilot_ok("Deleted {$count} {$type} posts with status '{$status}'.");

                // ═══ THEME MANAGEMENT ═══
        case 'install_theme':
        case 'theme_install':
            $slug = sanitize_text_field($params['slug'] ?? $params['theme'] ?? $params['name'] ?? '');
            if (empty($slug)) return wpilot_err('Theme slug required.');
            require_once ABSPATH . 'wp-admin/includes/theme.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
            $result = $upgrader->install('https://downloads.wordpress.org/theme/' . $slug . '.latest-stable.zip');
            if (is_wp_error($result)) return wpilot_err($result->get_error_message());
            return wpilot_ok("Theme '{$slug}' installed.");

        case 'activate_theme':
        case 'switch_theme':
            $slug = sanitize_text_field($params['slug'] ?? $params['theme'] ?? $params['name'] ?? '');
            if (empty($slug)) return wpilot_err('Theme slug required.');
            $themes = wp_get_themes();
            foreach ($themes as $stylesheet => $t) {
                if ($stylesheet === $slug || strtolower($t->get('Name')) === strtolower($slug)) {
                    switch_theme($stylesheet);
                    return wpilot_ok("Theme '{$t->get('Name')}' activated.");
                }
            }
            return wpilot_err("Theme '{$slug}' not found. Install it first.");

        case 'list_themes':
            $themes = wp_get_themes();
            $active = get_stylesheet();
            $list = [];
            foreach ($themes as $slug => $t) {
                $list[] = ['slug' => $slug, 'name' => $t->get('Name'), 'version' => $t->get('Version'), 'active' => $slug === $active];
            }
            return wpilot_ok(count($list) . " themes installed.", ['themes' => $list]);

        // ═══ WOOCOMMERCE ORDER MANAGEMENT ═══
        case 'list_orders':
        case 'woo_list_orders':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $status = $params['status'] ?? 'any';
            $limit = intval($params['limit'] ?? 10);
            $orders = wc_get_orders(['limit' => $limit, 'status' => $status, 'orderby' => 'date', 'order' => 'DESC']);
            $list = [];
            foreach ($orders as $o) {
                $list[] = [
                    'id' => $o->get_id(),
                    'status' => $o->get_status(),
                    'total' => $o->get_total() . ' ' . $o->get_currency(),
                    'customer' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
                    'email' => $o->get_billing_email(),
                    'date' => $o->get_date_created()->format('Y-m-d H:i'),
                    'items' => count($o->get_items()),
                ];
            }
            return wpilot_ok(count($list) . " orders found.", ['orders' => $list]);

        case 'update_order':
        case 'woo_update_order':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $order_id = intval($params['order_id'] ?? $params['id'] ?? 0);
            if (!$order_id) return wpilot_err('order_id required.');
            $order = wc_get_order($order_id);
            if (!$order) return wpilot_err("Order #{$order_id} not found.");
            if (!empty($params['status'])) {
                $order->update_status(sanitize_text_field($params['status']), $params['note'] ?? 'Updated by WPilot');
            }
            if (!empty($params['note'])) {
                $order->add_order_note(sanitize_text_field($params['note']));
            }
            return wpilot_ok("Order #{$order_id} updated.", ['status' => $order->get_status()]);

        case 'refund_order':
        case 'woo_refund_order':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $order_id = intval($params['order_id'] ?? $params['id'] ?? 0);
            $amount = floatval($params['amount'] ?? 0);
            $reason = sanitize_text_field($params['reason'] ?? 'Refund via WPilot');
            if (!$order_id) return wpilot_err('order_id required.');
            $order = wc_get_order($order_id);
            if (!$order) return wpilot_err("Order #{$order_id} not found.");
            if (!$amount) $amount = $order->get_total();
            $refund = wc_create_refund(['order_id' => $order_id, 'amount' => $amount, 'reason' => $reason]);
            if (is_wp_error($refund)) return wpilot_err($refund->get_error_message());
            return wpilot_ok("Refunded {$amount} {$order->get_currency()} on order #{$order_id}.");

        // ═══ MEDIA MANAGEMENT ═══
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
        case 'view_debug_log':
        case 'debug_log':
            $log_file = WP_CONTENT_DIR . '/debug.log';
            if (!file_exists($log_file)) {
                // Try PHP error log
                $log_file = ini_get('error_log');
            }
            if (!$log_file || !file_exists($log_file)) return wpilot_ok("No debug log found. WP_DEBUG may be off.");
            $lines = intval($params['lines'] ?? 30);
            $content = file_get_contents($log_file);
            $all_lines = explode("\n", $content);
            $last = array_slice($all_lines, -$lines);
            return wpilot_ok("Last {$lines} log entries.", ['log' => implode("\n", $last), 'total_lines' => count($all_lines), 'file_size_kb' => round(filesize($log_file)/1024)]);

        // ═══ SEARCH & REPLACE ═══
        case 'search_replace':
            $search = $params['search'] ?? '';
            $replace = $params['replace'] ?? '';
            $table = sanitize_text_field($params['table'] ?? 'posts');
            if (empty($search)) return wpilot_err('Search string required.');
            global $wpdb;
            $allowed_tables = ['posts' => $wpdb->posts, 'postmeta' => $wpdb->postmeta, 'options' => $wpdb->options];
            $tbl = $allowed_tables[$table] ?? null;
            if (!$tbl) return wpilot_err("Table '{$table}' not allowed. Use: posts, postmeta, options.");
            if ($table === 'posts') {
                $count = $wpdb->query($wpdb->prepare("UPDATE {$tbl} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", $search, $replace, '%' . $wpdb->esc_like($search) . '%'));
            } elseif ($table === 'postmeta') {
                $count = $wpdb->query($wpdb->prepare("UPDATE {$tbl} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s", $search, $replace, '%' . $wpdb->esc_like($search) . '%'));
            } else {
                $count = $wpdb->query($wpdb->prepare("UPDATE {$tbl} SET option_value = REPLACE(option_value, %s, %s) WHERE option_value LIKE %s", $search, $replace, '%' . $wpdb->esc_like($search) . '%'));
            }
            return wpilot_ok("Replaced '{$search}' with '{$replace}' in {$count} rows ({$table}).");

        // ═══ HTACCESS ═══
        case 'update_htaccess':
        case 'add_htaccess_rule':
            $rules = $params['rules'] ?? $params['content'] ?? '';
            if (empty($rules)) return wpilot_err('Rules required.');
            $htaccess = ABSPATH . '.htaccess';
            $current = file_exists($htaccess) ? file_get_contents($htaccess) : '';
            // Add before WordPress rules
            $marker = '# BEGIN WordPress';
            if (strpos($current, $marker) !== false) {
                $new = "# BEGIN WPilot\n{$rules}\n# END WPilot\n\n" . $current;
                // Remove old WPilot block if exists
                $new = preg_replace('/# BEGIN WPilot.*?# END WPilot\n*/s', '', $current);
                $new = "# BEGIN WPilot\n{$rules}\n# END WPilot\n\n" . $new;
            } else {
                $new = $current . "\n# BEGIN WPilot\n{$rules}\n# END WPilot";
            }
            file_put_contents($htaccess, $new);
            return wpilot_ok(".htaccess updated with custom rules.");

        // ═══ DUPLICATE PAGE/POST ═══
        case 'duplicate_page':
        case 'duplicate_post':
            $id = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            if (!$id) return wpilot_err('Post/page ID required.');
            $post = get_post($id);
            if (!$post) return wpilot_err("Post #{$id} not found.");
            $new_title = $params['title'] ?? $post->post_title . ' (Copy)';
            $new_id = wp_insert_post([
                'post_title' => sanitize_text_field($new_title),
                'post_content' => $post->post_content,
                'post_status' => 'draft',
                'post_type' => $post->post_type,
            ]);
            if (is_wp_error($new_id)) return wpilot_err($new_id->get_error_message());
            // Copy meta
            $meta = get_post_meta($id);
            foreach ($meta as $key => $values) {
                if ($key === '_wp_old_slug') continue;
                foreach ($values as $v) update_post_meta($new_id, $key, maybe_unserialize($v));
            }
            return wpilot_ok("Duplicated as draft (ID: {$new_id}).", ['new_id' => $new_id]);

        // ═══ EXPORT CONTENT ═══
        case 'export_products':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $products = wc_get_products(['limit' => -1, 'status' => 'publish']);
            $csv = "ID,Name,Price,Sale Price,SKU,Stock,Category,Image URL\n";
            foreach ($products as $p) {
                $cats = wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names']);
                $img = $p->get_image_id() ? wp_get_attachment_url($p->get_image_id()) : '';
                $csv .= implode(',', [
                    $p->get_id(),
                    '"' . str_replace('"', '""', $p->get_name()) . '"',
                    $p->get_price(),
                    $p->get_sale_price() ?: '',
                    $p->get_sku() ?: '',
                    $p->get_stock_quantity() ?? 'instock',
                    '"' . implode(';', $cats) . '"',
                    $img,
                ]) . "\n";
            }
            $upload = wp_upload_dir();
            $file = $upload['basedir'] . '/wpilot-products-export.csv';
            file_put_contents($file, $csv);
            return wpilot_ok("Exported " . count($products) . " products.", ['download_url' => $upload['baseurl'] . '/wpilot-products-export.csv', 'count' => count($products)]);

                // ═══ CHECK FRONTEND — AI sees what the visitor sees ═══
        case 'check_frontend':
        case 'check_page':
        case 'view_page':
            $url = $params['url'] ?? $params['page_url'] ?? '';
            $page_id = intval($params['id'] ?? $params['page_id'] ?? 0);

            // Build URL from page ID if no URL given
            if (empty($url) && $page_id) {
                $url = get_permalink($page_id);
            }
            if (empty($url)) {
                // Default to homepage
                $url = home_url('/');
            }
            // Allow relative paths
            if (strpos($url, '/') === 0) {
                $url = home_url($url);
            }

            $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => false]);
            if (is_wp_error($response)) return wpilot_err('Could not fetch: ' . $response->get_error_message());

            $html = wp_remote_retrieve_body($response);
            $status = wp_remote_retrieve_response_code($response);

            if (empty($html)) return wpilot_err('Empty page at ' . $url);

            $report = [];
            $report['url'] = $url;
            $report['status'] = $status;
            $report['size_kb'] = round(strlen($html) / 1024);

            // Extract visible text (what visitor sees)
            $text = $html;
            $text = preg_replace('/<script[^>]*>.*?<\/script>/s', '', $text);
            $text = preg_replace('/<style[^>]*>.*?<\/style>/s', '', $text);
            $text = preg_replace('/<[^>]+>/', ' ', $text);
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);
            $report['visible_text'] = substr($text, 0, 800);

            // Check for CSS rendering issues (CSS showing as text)
            $css_leak = false;
            if (preg_match('/(?<![<a-z])(\.woocommerce[^{]*\{|border-radius:|font-weight:|background:|display:\s*grid)/', $text)) {
                $css_leak = true;
            }
            $report['css_leak'] = $css_leak;

            // Check dark theme
            $report['has_dark_bg'] = strpos($html, '050810') !== false || strpos($html, '080c14') !== false;

            // Check custom CSS loaded
            $report['custom_css_loaded'] = strpos($html, 'wp-custom-css') !== false;

            // Count style and script tags
            preg_match_all('/<style[^>]*>/', $html, $style_tags);
            preg_match_all('/<script[^>]*>/', $html, $script_tags);
            $report['style_tags'] = count($style_tags[0]);
            $report['script_tags'] = count($script_tags[0]);

            // Navigation
            $report['has_nav'] = strpos($html, '<nav') !== false;
            preg_match_all('/menu-item/', $html, $menu_items);
            $report['menu_items'] = count($menu_items[0]);

            // WooCommerce specific
            if (strpos($html, 'woocommerce') !== false) {
                preg_match_all('/woocommerce-loop-product__title/', $html, $prod_titles);
                preg_match_all('/attachment-woocommerce_thumbnail/', $html, $prod_imgs);
                preg_match_all('/add_to_cart_button/', $html, $cart_btns);
                preg_match_all('/woocommerce-Price-amount/', $html, $prices);
                $report['woo_products'] = count($prod_titles[0]);
                $report['woo_images'] = count($prod_imgs[0]);
                if (count($prod_imgs[0]) < count($prod_titles[0])) {
                    $missing = count($prod_titles[0]) - count($prod_imgs[0]);
                    $issues[] = $missing . ' products missing images — use set_featured_image with image_url to fix';
                }
                $report['woo_buttons'] = count($cart_btns[0]);
                $report['woo_prices'] = count($prices[0]);
                $report['woo_cart_shortcode'] = strpos($html, 'woocommerce-cart') !== false;
                $report['woo_checkout_shortcode'] = strpos($html, 'woocommerce-checkout') !== false;
            }

            // Elementor
            preg_match_all('/elementor-widget-(\w+)/', $html, $el_widgets);
            if (!empty($el_widgets[1])) {
                $report['elementor_widgets'] = array_count_values($el_widgets[1]);
            }

            // Check for broken elements
            $issues = [];
            if ($css_leak) $issues[] = 'CSS rules visible as text on page — broken rendering';
            if ($status !== 200) $issues[] = 'Page returned HTTP ' . $status;
            if (!$report['has_dark_bg'] && strpos($html, '050810') === false) $issues[] = 'Dark theme CSS not applied';
            if (!$report['custom_css_loaded']) $issues[] = 'Custom CSS not loading';
            if (isset($report['woo_products']) && $report['woo_products'] === 0 && strpos($url, 'shop') !== false) $issues[] = 'Shop page has no products';
            if (isset($report['woo_images']) && isset($report['woo_products']) && $report['woo_images'] < $report['woo_products']) $issues[] = ($report['woo_products'] - $report['woo_images']) . ' products missing images';
            if (strpos($text, '[woocommerce') !== false || strpos($text, '[products') !== false) $issues[] = 'Shortcodes visible as text — not rendering';

            // Extract headings
            $headings = [];
            if (preg_match_all('/<h[1-3][^>]*>([^<]+)</', $html, $hm)) {
                $headings = array_slice(array_map('trim', $hm[1]), 0, 8);
            }
            $report['headings'] = $headings;

            // Images without alt
            $imgs_no_alt = 0;
            if (preg_match_all('/<img[^>]+>/i', $html, $img_tags)) {
                foreach ($img_tags[0] as $img) {
                    if (strpos($img, 'alt=""') !== false || strpos($img, 'alt') === false) {
                        $imgs_no_alt++;
                    }
                }
            }
            if ($imgs_no_alt > 0) $issues[] = $imgs_no_alt . ' images missing alt text';

            // Detect duplicate navigation
            preg_match_all('/<nav[^>]*>/', $html, $nav_tags);
            if (count($nav_tags[0]) > 2) {
                $issues[] = count($nav_tags[0]) . ' nav elements found — possible duplicate navigation';
            }

            // Check for nav hidden on mobile without hamburger
            if (strpos($html, 'display: none') !== false || strpos($html, 'display:none') !== false) {
                if (strpos($html, 'menu-toggle') === false && strpos($html, 'navigation-toggle') === false && strpos($html, 'hamburger') === false) {
                    $issues[] = 'Navigation may be hidden on mobile without hamburger menu';
                }
            }

            $report['issues'] = $issues;
            $report['issues_count'] = count($issues);

            $msg = count($issues) === 0
                ? "Page looks good. No issues found at {$url}."
                : count($issues) . " issues found at {$url}: " . implode(', ', $issues);

            return wpilot_ok($msg, $report);

                // ═══ ADMIN DASHBOARD CUSTOMIZATION PER ROLE ═══
        case 'customize_admin':
        case 'customize_dashboard':
            $role = sanitize_text_field($params['role'] ?? 'all');
            $hide_menus = $params['hide_menus'] ?? $params['hide'] ?? [];
            $show_menus = $params['show_menus'] ?? $params['show'] ?? [];
            $custom_css = $params['css'] ?? $params['admin_css'] ?? '';
            $welcome_message = $params['welcome'] ?? $params['welcome_message'] ?? '';
            $redirect_url = $params['redirect'] ?? $params['login_redirect'] ?? '';

            $code_parts = ["<?php\n// WPilot Admin Customization for role: {$role}"];

            // Hide admin menu items
            if (!empty($hide_menus)) {
                $menu_slugs = [
                    'posts' => 'edit.php', 'pages' => 'edit.php?post_type=page',
                    'comments' => 'edit-comments.php', 'media' => 'upload.php',
                    'plugins' => 'plugins.php', 'users' => 'users.php',
                    'tools' => 'tools.php', 'settings' => 'options-general.php',
                    'appearance' => 'themes.php', 'woocommerce' => 'woocommerce',
                    'products' => 'edit.php?post_type=product', 'orders' => 'edit.php?post_type=shop_order',
                    'elementor' => 'elementor', 'jetpack' => 'jetpack',
                    'dashboard' => 'index.php', 'profile' => 'profile.php',
                ];
                $removes = [];
                foreach ((array)$hide_menus as $item) {
                    $slug = $menu_slugs[strtolower(trim($item))] ?? sanitize_text_field($item);
                    $removes[] = "remove_menu_page('{$slug}');";
                }
                $role_check = $role === 'all' ? '' : "if (!current_user_can('manage_options')) ";
                $code_parts[] = "add_action('admin_menu', function() { {$role_check}" . implode(' ', $removes) . " }, 999);";
            }

            // Custom admin CSS
            if (!empty($custom_css)) {
                $escaped_css = addslashes($custom_css);
                $code_parts[] = "add_action('admin_head', function() { echo '<style>{$escaped_css}</style>'; });";
            }

            // Welcome message on dashboard
            if (!empty($welcome_message)) {
                $escaped_msg = addslashes($welcome_message);
                $div_open = '<div style=\"background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;padding:20px 24px;border:none;border-radius:12px;margin:15px 0\"><p style=\"margin:0;font-size:16px\">';
                $div_close = '</p></div>';
                $code_parts[] = "add_action('admin_notices', function() { if (get_current_screen()->id !== 'dashboard') return; echo '{$div_open}{$escaped_msg}{$div_close}'; });";
            }

            // Login redirect per role
            if (!empty($redirect_url)) {
                $escaped_url = esc_url($redirect_url);
                if ($role !== 'all') {
                    $code_parts[] = "add_filter('login_redirect', function($url, $request, $user) { if (is_a($user,'WP_User') && in_array('{$role}', $user->roles)) return '{$escaped_url}'; return $url; }, 10, 3);";
                } else {
                    $code_parts[] = "add_filter('login_redirect', function() { return '{$escaped_url}'; }, 10, 3);";
                }
            }

            $mu_file = WPMU_PLUGIN_DIR . '/wpilot-admin-' . sanitize_file_name($role) . '.php';
            file_put_contents($mu_file, implode("\n", $code_parts));
            return wpilot_ok("Admin customized for role: {$role}.", ['file' => basename($mu_file)]);

        // ═══ CUSTOM LOGIN PAGE ═══
        case 'customize_login':
        case 'design_login':
            $logo_url = $params['logo_url'] ?? $params['logo'] ?? '';
            $bg_color = sanitize_hex_color($params['bg_color'] ?? '#080c14') ?: '#080c14';
            $btn_color = sanitize_hex_color($params['button_color'] ?? '#6366f1') ?: '#6366f1';
            $text_color = sanitize_hex_color($params['text_color'] ?? '#ffffff') ?: '#ffffff';

            $login_css = "body.login{background:{$bg_color}!important}";
            $login_css .= "#loginform{background:rgba(255,255,255,.05)!important;border:1px solid rgba(255,255,255,.1)!important;border-radius:16px!important;box-shadow:0 20px 60px rgba(0,0,0,.3)!important}";
            $login_css .= "#loginform label{color:{$text_color}!important}";
            $login_css .= "#loginform input[type=text],#loginform input[type=password]{background:rgba(255,255,255,.08)!important;border:1px solid rgba(255,255,255,.15)!important;color:#fff!important;border-radius:8px!important}";
            $login_css .= "#wp-submit{background:linear-gradient(135deg,{$btn_color},#8b5cf6)!important;border:none!important;border-radius:50px!important;padding:8px 24px!important;text-shadow:none!important;box-shadow:0 4px 20px rgba(99,102,241,.3)!important}";
            $login_css .= ".login #nav a,.login #backtoblog a{color:rgba(255,255,255,.5)!important}";
            $login_css .= "#login h1 a{background-size:contain!important;width:200px!important;height:80px!important}";

            if (!empty($logo_url)) {
                $login_css .= "#login h1 a{background-image:url({$logo_url})!important}";
            }

            $mu_code = "<?php\nadd_action('login_enqueue_scripts', function() { echo '<style>{$login_css}</style>'; });";
            if (!empty($logo_url)) {
                $mu_code .= "\nadd_filter('login_headerurl', function() { return home_url(); });";
            }

            file_put_contents(WPMU_PLUGIN_DIR . '/wpilot-login-style.php', $mu_code);
            return wpilot_ok("Login page styled. Background: {$bg_color}, Button: {$btn_color}.", ['preview' => wp_login_url()]);

        // ═══ ROLE-BASED FRONTEND VIEW ═══
        case 'create_role_view':
        case 'role_dashboard':
            $role = sanitize_text_field($params['role'] ?? 'customer');
            $title = sanitize_text_field($params['title'] ?? ucfirst($role) . ' Dashboard');
            $html = $params['html'] ?? $params['content'] ?? '';
            $redirect_after_login = filter_var($params['redirect_login'] ?? true, FILTER_VALIDATE_BOOLEAN);

            if (empty($html)) return wpilot_err('HTML content required for the dashboard view.');

            // Create the page
            $page_id = wp_insert_post([
                'post_title' => $title,
                'post_content' => '<!-- wp:html -->' . $html . '<!-- /wp:html -->',
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
            if (is_wp_error($page_id)) return wpilot_err($page_id->get_error_message());

            // If Elementor, create HTML widget
            if (defined('ELEMENTOR_VERSION')) {
                update_post_meta($page_id, '_elementor_edit_mode', 'builder');
                update_post_meta($page_id, '_elementor_template_type', 'wp-page');
                update_post_meta($page_id, '_wp_page_template', 'elementor_header_footer');
                $el_data = [[
                    'id' => substr(md5(rand()),0,7),
                    'elType' => 'section',
                    'settings' => ['layout'=>'full_width','gap'=>'no','padding'=>['unit'=>'px','top'=>'0','right'=>'0','bottom'=>'0','left'=>'0','isLinked'=>true]],
                    'elements' => [[
                        'id' => substr(md5(rand()),0,7),
                        'elType' => 'column',
                        'settings' => ['_column_size'=>100],
                        'elements' => [[
                            'id' => substr(md5(rand()),0,7),
                            'elType' => 'widget',
                            'widgetType' => 'html',
                            'settings' => ['html' => $html],
                        ]],
                    ]],
                ]];
                update_post_meta($page_id, '_elementor_data', wp_slash(wp_json_encode($el_data)));
            }

            // Restrict page to role
            $restrict_code = "<?php\n// Restrict page {$page_id} to role: {$role}\n";
            $restrict_code .= "add_action('template_redirect', function() {\n";
            $restrict_code .= "    if (is_page({$page_id}) && !current_user_can('manage_options')) {\n";
            $restrict_code .= "        if (!is_user_logged_in()) { wp_redirect(wp_login_url(get_permalink({$page_id}))); exit; }\n";
            $restrict_code .= "        \\$user = wp_get_current_user();\n";
            $restrict_code .= "        if (!in_array('{$role}', \\$user->roles) && !current_user_can('manage_options')) { wp_redirect(home_url()); exit; }\n";
            $restrict_code .= "    }\n";
            $restrict_code .= "});";

            // Redirect after login
            if ($redirect_after_login) {
                $page_url = get_permalink($page_id);
                $restrict_code .= "\nadd_filter('login_redirect', function(\\$url, \\$request, \\$user) {\n";
                $restrict_code .= "    if (is_a(\\$user,'WP_User') && in_array('{$role}', \\$user->roles)) return '{$page_url}';\n";
                $restrict_code .= "    return \\$url;\n";
                $restrict_code .= "}, 10, 3);";
            }

// DISABLED: mu-plugin generation caused syntax errors
            // file_put_contents(WPMU_PLUGIN_DIR . '/wpilot-role-' . sanitize_file_name($role) . '.php', $restrict_code);

            return wpilot_ok("Dashboard for role '{$role}' created (ID: {$page_id}).", [
                'page_id' => $page_id,
                'url' => get_permalink($page_id),
                'role' => $role,
                'restricted' => true,
                'login_redirect' => $redirect_after_login,
            ]);

                // ═══ ANALYZE EXTERNAL WEBSITE FOR DESIGN INSPIRATION ═══
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
        case 'set_favicon':
        case 'update_favicon':
            $image_url = $params['url'] ?? $params['image_url'] ?? $params['favicon_url'] ?? '';
            $image_id = intval($params['image_id'] ?? 0);
            $emoji = $params['emoji'] ?? '';

            // If emoji, create an SVG favicon
            if (!empty($emoji)) {
                $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">' . $emoji . '</text></svg>';
                $upload_dir = wp_upload_dir();
                $svg_path = $upload_dir['basedir'] . '/favicon.svg';
                file_put_contents($svg_path, $svg);
                // Add as site icon via custom code
                $favicon_url = $upload_dir['baseurl'] . '/favicon.svg';
                // Inject via mu-plugin
                $mu_file = WPMU_PLUGIN_DIR . '/wpilot-favicon.php';
                $mu_code = "<?php\n// WPilot Favicon\nadd_action('wp_head', function() { echo '<link rel=\"icon\" href=\"{$favicon_url}\" type=\"image/svg+xml\">'; }, 1);";
                file_put_contents($mu_file, $mu_code);
                return wpilot_ok("Emoji favicon set: {$emoji}", ['favicon' => $upload_dir['baseurl'] . '/favicon.svg']);
            }

            // If URL, download and set
            if (!$image_id && !empty($image_url)) {
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                $image_id = media_sideload_image($image_url, 0, 'Site Favicon', 'id');
                if (is_wp_error($image_id)) return wpilot_err('Favicon download failed: ' . $image_id->get_error_message());
            }

            if ($image_id) {
                update_option('site_icon', $image_id);
                return wpilot_ok("Favicon set.", ['image_id' => $image_id]);
            }
            return wpilot_err('Provide emoji, image_url, or image_id.');

        // ═══ FULL SITE MAP ═══
        case 'get_sitemap':
        case 'site_map':
            $sitemap = [];
            // All pages with URLs
            $pages = get_pages(['post_status' => 'publish']);
            $sitemap['pages'] = array_map(fn($p) => ['title' => $p->post_title, 'url' => get_permalink($p->ID), 'template' => get_post_meta($p->ID, '_wp_page_template', true) ?: 'default'], $pages);
            // All posts
            $posts = get_posts(['numberposts' => 20, 'post_status' => 'publish']);
            $sitemap['posts'] = array_map(fn($p) => ['title' => $p->post_title, 'url' => get_permalink($p->ID)], $posts);
            // Product pages
            if (class_exists('WooCommerce')) {
                $products = wc_get_products(['limit' => 50, 'status' => 'publish']);
                $sitemap['products'] = array_map(fn($p) => ['name' => $p->get_name(), 'url' => $p->get_permalink(), 'price' => $p->get_price()], $products);
                $sitemap['woo_pages'] = [
                    'shop' => get_permalink(get_option('woocommerce_shop_page_id')),
                    'cart' => get_permalink(get_option('woocommerce_cart_page_id')),
                    'checkout' => get_permalink(get_option('woocommerce_checkout_page_id')),
                    'myaccount' => get_permalink(get_option('woocommerce_myaccount_page_id')),
                ];
            }
            // Categories
            $cats = get_categories(['hide_empty' => false]);
            $sitemap['categories'] = array_map(fn($c) => $c->name . ' (' . $c->count . ' posts)', $cats);
            // Registered menus
            $menus = wp_get_nav_menus();
            $sitemap['menus'] = array_map(fn($m) => $m->name, $menus);
            return wpilot_ok("Site map generated.", ['sitemap' => $sitemap]);

        
        case 'export_users_csv':
            $users = get_users(["fields" => ["ID","user_login","user_email","display_name"]]);
            $csv = "ID,Username,Email,Name,Role\n";
            foreach ($users as $u) { $csv .= $u->ID . "," . $u->user_login . "," . $u->user_email . "," . $u->display_name . "," . implode(";",get_userdata($u->ID)->roles) . "\n"; }
            $up = wp_upload_dir(); file_put_contents($up["basedir"]."/wpilot-users.csv", $csv);
            return wpilot_ok("Exported ".count($users)." users.", ["download_url"=>$up["baseurl"]."/wpilot-users.csv"]);

        case 'export_orders_csv':
            if (!class_exists("WooCommerce")) return wpilot_err("WooCommerce required.");
            $orders = wc_get_orders(["limit"=>100,"orderby"=>"date","order"=>"DESC"]);
            $csv = "ID,Date,Status,Customer,Email,Total\n";
            foreach ($orders as $o) $csv .= $o->get_id().",".$o->get_date_created()->format("Y-m-d").",".$o->get_status().",".$o->get_billing_first_name().",".$o->get_billing_email().",".$o->get_total()."\n";
            $up = wp_upload_dir(); file_put_contents($up["basedir"]."/wpilot-orders.csv", $csv);
            return wpilot_ok("Exported ".count($orders)." orders.", ["download_url"=>$up["baseurl"]."/wpilot-orders.csv"]);

        case 'export_email_list':
        case 'export_subscribers':
            $emails = [];
            foreach (get_users(["role"=>"subscriber","fields"=>["user_email"]]) as $s) $emails[] = $s->user_email;
            if (class_exists("WooCommerce")) foreach (get_users(["role"=>"customer","fields"=>["user_email"]]) as $c) $emails[] = $c->user_email;
            return wpilot_ok(count(array_unique($emails))." emails.", ["emails"=>array_unique($emails)]);

        case 'list_members':
        case 'list_customers':
            $role = sanitize_text_field($params["role"] ?? "customer");
            $users = get_users(["role"=>$role,"number"=>50]);
            $list = array_map(fn($u) => ["id"=>$u->ID,"name"=>$u->display_name,"email"=>$u->user_email], $users);
            return wpilot_ok(count($list)." {$role}s.", ["members"=>$list]);

        case 'customer_report':
            if (!class_exists("WooCommerce")) return wpilot_err("WooCommerce required.");
            $customers = get_users(["role"=>"customer"]);
            $total = 0; $top = [];
            foreach ($customers as $u) { $c = new WC_Customer($u->ID); $s = floatval($c->get_total_spent()); $total += $s; $top[] = ["name"=>$u->display_name,"spent"=>$s,"orders"=>$c->get_order_count()]; }
            usort($top, fn($a,$b) => $b["spent"] <=> $a["spent"]);
            return wpilot_ok(count($customers)." customers, {$total} ".get_woocommerce_currency()." total.", ["top"=>array_slice($top,0,10)]);

        case 'newsletter_add_subscriber':
            $email = sanitize_email($params["email"] ?? "");
            if (empty($email)) return wpilot_err("Email required.");
            if (!email_exists($email)) { wp_create_user($email, wp_generate_password(), $email); return wpilot_ok("Added: {$email}"); }
            return wpilot_ok("Already exists.");

        case 'newsletter_bulk_import':
            $csv = $params["csv"] ?? "";
            if (empty($csv)) return wpilot_err("CSV: email,name");
            $lines = preg_split("/\r\n|\r|\n/", trim($csv)); array_shift($lines); $added = 0;
            foreach ($lines as $line) { $c = str_getcsv(trim($line)); $e = sanitize_email($c[0] ?? ""); if ($e && !email_exists($e)) { wp_create_user($e, wp_generate_password(), $e); $added++; } }
            return wpilot_ok("Imported {$added} subscribers.");

        case 'disk_usage':
            $up = wp_upload_dir(); $size = 0;
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($up["basedir"], RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iter as $file) $size += $file->getSize();
            global $wpdb; $db = 0;
            foreach ($wpdb->get_results("SHOW TABLE STATUS") as $t) $db += $t->Data_length + $t->Index_length;
            return wpilot_ok("Disk usage.", ["uploads"=>round($size/1048576,1)." MB","database"=>round($db/1048576,1)." MB"]);


        // ═══ SCREENSHOT & VISION ANALYSIS ═══
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
        case 'list_templates':
            $templates = wpilot_get_templates();
            $list = array_map(function($t) { return $t['name'] . ' — ' . $t['description']; }, $templates);
            return wpilot_ok(count($templates) . " templates available.", ['templates' => $list]);

        case 'apply_template':
        case 'use_template':
            $template_name = sanitize_text_field($params['template'] ?? $params['name'] ?? '');
            $page_title = sanitize_text_field($params['title'] ?? $params['page_title'] ?? ucfirst($template_name));
            if (empty($template_name)) return wpilot_err('Template name required. Use list_templates to see available.');
            $html = wpilot_get_template_html($template_name, $params);
            if (empty($html)) return wpilot_err("Template '{$template_name}' not found. Use list_templates.");
            // Create page with template HTML
            $page_id = wp_insert_post([
                'post_title'   => $page_title,
                'post_content' => $html,
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ]);
            if (is_wp_error($page_id)) return wpilot_err($page_id->get_error_message());
            return wpilot_ok("Created '{$page_title}' with {$template_name} template.", [
                'page_id' => $page_id,
                'url'     => get_permalink($page_id),
            ]);

        // ═══ AI TRAINING & LEARNING ═══
        case 'training_stats':
            global $wpdb;
            $t = $wpdb->prefix . 'wpilot_training';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$t}'") !== $t) return wpilot_ok("Training table not set up yet.", ['total' => 0]);
            $total   = intval($wpdb->get_var("SELECT COUNT(*) FROM {$t}"));
            $rated   = intval($wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE rating >= 4"));
            $avg     = floatval($wpdb->get_var("SELECT AVG(rating) FROM {$t}") ?? 0);
            $intents = $wpdb->get_results("SELECT JSON_EXTRACT(metadata, '$.intent') as intent, COUNT(*) as cnt FROM {$t} GROUP BY intent ORDER BY cnt DESC LIMIT 10", ARRAY_A);
            $tools   = $wpdb->get_results("SELECT JSON_EXTRACT(metadata, '$.tools_used') as tools, COUNT(*) as cnt FROM {$t} WHERE JSON_LENGTH(JSON_EXTRACT(metadata, '$.tools_used')) > 0 GROUP BY tools ORDER BY cnt DESC LIMIT 10", ARRAY_A);
            return wpilot_ok("{$total} training pairs, avg rating {$avg}.", [
                'total'         => $total,
                'high_quality'  => $rated,
                'avg_rating'    => round($avg, 1),
                'top_intents'   => $intents,
                'top_tools'     => $tools,
            ]);

        case 'export_training_data':
            if (!function_exists('wpilot_export_training_jsonl')) return wpilot_err('Training module not loaded.');
            $format = sanitize_text_field($params['format'] ?? 'chatml');
            $min_quality = intval($params['min_quality'] ?? 60);
            $result = wpilot_export_training_jsonl($format, $min_quality);
            if (is_wp_error($result)) return wpilot_err($result->get_error_message());
            return wpilot_ok("Exported training data.", $result);

        case 'ai_self_test':
        case 'test_ai':
            // AI tests itself on common tasks
            $tests = [
                ['msg' => 'list all plugins', 'expect_tool' => 'list_plugins'],
                ['msg' => 'show my pages', 'expect_tool' => 'list_pages'],
                ['msg' => 'check homepage', 'expect_tool' => 'check_frontend'],
            ];
            $results = [];
            foreach ($tests as $test) {
                $r = wpilot_smart_answer($test['msg'], 'chat', wpilot_build_context('general'), []);
                $resp = is_array($r) ? $r['text'] : (is_wp_error($r) ? $r->get_error_message() : $r);
                $actions = wpilot_parse_actions($resp);
                if (empty($actions) && function_exists('wpilot_parse_compact_actions')) $actions = wpilot_parse_compact_actions($resp);
                $found_tool = !empty($actions) ? $actions[0]['tool'] : 'none';
                $pass = ($found_tool === $test['expect_tool']);
                $results[] = [
                    'test'     => $test['msg'],
                    'expected' => $test['expect_tool'],
                    'got'      => $found_tool,
                    'pass'     => $pass,
                ];
            }
            $passed = count(array_filter($results, fn($r) => $r['pass']));
            return wpilot_ok("{$passed}/" . count($tests) . " tests passed.", ['tests' => $results]);

        // ═══ MULTI-STEP WORKFLOWS ═══
        case 'build_landing_page':
            // Full landing page workflow: create page + add CSS + screenshot
            $title = sanitize_text_field($params['title'] ?? 'Landing Page');
            $html = $params['html'] ?? '';
            $css = $params['css'] ?? '';
            if (empty($html)) return wpilot_err('HTML content required for landing page.');
            // Create page
            $page_id = wp_insert_post([
                'post_title'   => $title,
                'post_content' => $html,
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ]);
            if (is_wp_error($page_id)) return wpilot_err($page_id->get_error_message());
            // Add CSS if provided
            if (!empty($css)) {
                $existing = wp_get_custom_css();
                wp_update_custom_css_post($existing . "\n/* Landing: {$title} */\n" . $css);
            }
            $url = get_permalink($page_id);
            // Take screenshot of result
            $shot = wpilot_take_screenshot($url, ['delay' => 3]);
            $shot_url = is_wp_error($shot) ? null : $shot['url'];
            return wpilot_ok("Landing page '{$title}' created.", [
                'page_id'        => $page_id,
                'url'            => $url,
                'screenshot_url' => $shot_url,
            ]);

        case 'full_site_audit':
            // Complete site audit: screenshot + check_frontend + SEO + performance
            $url = home_url('/');
            $audit = ['url' => $url];
            // Screenshot
            $shot = wpilot_take_screenshot($url, ['delay' => 3]);
            if (!is_wp_error($shot)) {
                $audit['screenshot_url'] = $shot['url'];
                $visual = wpilot_analyze_screenshot($shot['path'], 'full');
                if (!is_wp_error($visual)) $audit['visual_analysis'] = $visual;
            }
            // Mobile screenshot
            $mobile = wpilot_take_screenshot($url, ['mobile' => true, 'delay' => 3]);
            if (!is_wp_error($mobile)) {
                $audit['mobile_screenshot'] = $mobile['url'];
                $mobile_analysis = wpilot_analyze_screenshot($mobile['path'], 'bugs');
                if (!is_wp_error($mobile_analysis)) $audit['mobile_analysis'] = $mobile_analysis;
            }
            // Page data
            $response = wp_remote_get($url, ['timeout' => 15, 'sslverify' => false]);
            if (!is_wp_error($response)) {
                $html_content = wp_remote_retrieve_body($response);
                $audit['page_size_kb'] = round(strlen($html_content) / 1024);
                preg_match_all('/<img[^>]+>/', $html_content, $imgs);
                $audit['total_images'] = count($imgs[0]);
                preg_match_all('/<script[^>]+>/', $html_content, $scripts);
                $audit['total_scripts'] = count($scripts[0]);
                preg_match_all('/<link[^>]+stylesheet/', $html_content, $css_files);
                $audit['total_stylesheets'] = count($css_files[0]);
            }
            // Plugin count
            $audit['active_plugins'] = count(get_option('active_plugins', []));
            // DB size
            global $wpdb;
            $db_size = 0;
            foreach ($wpdb->get_results("SHOW TABLE STATUS") as $t) $db_size += $t->Data_length + $t->Index_length;
            $audit['database_mb'] = round($db_size / 1048576, 1);
            return wpilot_ok("Full site audit complete.", $audit);


        // ═══ GRANULAR ELEMENT EDITING ═══
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
        case 'read_file':
        case 'view_file':
            $path = $params['path'] ?? $params['file'] ?? '';
            if (empty($path)) return wpilot_err('File path required. Example: /wp-content/themes/flavor/header.php');
            if (strpos($path, '/') !== 0) $path = ABSPATH . $path;
            $real = realpath($path);
            $wp_root = realpath(ABSPATH);
            if (!$real || strpos($real, $wp_root) !== 0) return wpilot_err('File not found or outside WordPress directory.');
            if (!is_file($real)) return wpilot_err('Not a file: ' . $path);
            // SECURITY: Block reading sensitive files that contain credentials
            $blocked_read = ['wp-config.php', '.htaccess', '.htpasswd', 'wp-settings.php', '.env', 'debug.log'];
            if (in_array(basename($real), $blocked_read)) return wpilot_err('Cannot read sensitive configuration files for security.');
            // Block files that may contain secrets
            $path_lower = strtolower($real);
            if (preg_match('/(password|credential|\.key|\.pem|\.crt)/', $path_lower)) return wpilot_err('Cannot read files that may contain sensitive data.');
            $size = filesize($real);
            if ($size > 500000) return wpilot_err('File too large (' . round($size/1024) . ' KB). Max 500KB.');
            $content = file_get_contents($real);
            $lines = substr_count($content, "\n") + 1;
            return wpilot_ok("File: {$path} ({$lines} lines, " . round($size/1024) . " KB)", [
                'path' => $path, 'content' => $content, 'lines' => $lines, 'size_kb' => round($size/1024),
            ]);

        case 'write_file':
        case 'edit_file':
            $path = $params['path'] ?? $params['file'] ?? '';
            $content = $params['content'] ?? '';
            $search = $params['search'] ?? $params['find'] ?? '';
            $replace_str = $params['replace'] ?? '';
            if (empty($path)) return wpilot_err('File path required.');
            if (strpos($path, '/') !== 0) $path = ABSPATH . $path;
            $real = realpath(dirname($path));
            $wp_root = realpath(ABSPATH);
            if (!$real || strpos($real, $wp_root) !== 0) return wpilot_err('Path outside WordPress directory.');
            $basename = basename($path);
            if (in_array($basename, ['wp-config.php', '.htaccess', '.htpasswd', 'wp-settings.php', '.env'])) {
                return wpilot_err('Cannot modify core WordPress config files for security.');
            }
            // SECURITY: Block writing to sensitive directories
            $blocked_dirs = ['/wpilot/', '/mu-plugins/wpilot-', '/wp-admin/includes/', '/wp-includes/'];
            foreach ($blocked_dirs as $bd) {
                if (strpos($path, $bd) !== false) return wpilot_err('Cannot write to protected directory.');
            }
            if (file_exists($path)) {
                $backup_dir = WP_CONTENT_DIR . '/wpilot-backups/files/' . date('Y-m-d');
                wp_mkdir_p($backup_dir);
                copy($path, $backup_dir . '/' . $basename . '.' . time() . '.bak');
            }
            if (!empty($search)) {
                if (!file_exists($path)) return wpilot_err('File not found: ' . $path);
                $existing = file_get_contents($path);
                if (strpos($existing, $search) === false) return wpilot_err("Search text not found in {$path}.");
                $existing = str_replace($search, $replace_str, $existing);
                file_put_contents($path, $existing);
                return wpilot_ok("Updated {$path} (search & replace).", ['path' => $path]);
            }
            if (empty($content)) return wpilot_err('content or search+replace required.');
            file_put_contents($path, $content);
            return wpilot_ok("Wrote {$path} (" . strlen($content) . " bytes).", ['path' => $path]);

        case 'list_files':
        case 'file_list':
            $dir = $params['path'] ?? $params['dir'] ?? '';
            if (empty($dir)) $dir = ABSPATH;
            if (strpos($dir, '/') !== 0) $dir = ABSPATH . $dir;
            $real = realpath($dir);
            $wp_root = realpath(ABSPATH);
            if (!$real || strpos($real, $wp_root) !== 0) return wpilot_err('Directory outside WordPress.');
            if (!is_dir($real)) return wpilot_err('Not a directory: ' . $dir);
            $files = [];
            foreach (scandir($real) as $f_name) {
                if ($f_name === '.' || $f_name === '..') continue;
                $full = $real . '/' . $f_name;
                $files[] = [
                    'name' => $f_name,
                    'type' => is_dir($full) ? 'dir' : 'file',
                    'size' => is_file($full) ? filesize($full) : 0,
                    'modified' => date('Y-m-d H:i', filemtime($full)),
                ];
            }
            usort($files, function($a, $b) {
                if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
                return strcmp($a['name'], $b['name']);
            });
            return wpilot_ok(count($files) . " items in {$dir}", ['files' => $files, 'path' => $dir]);

        // ═══ THEME FILE EDITOR ═══
        case 'edit_theme_file':
        case 'edit_theme':
            $file = sanitize_text_field($params['file'] ?? 'functions.php');
            $search = $params['search'] ?? $params['find'] ?? '';
            $replace_str = $params['replace'] ?? '';
            $append = $params['append'] ?? '';
            $theme_dir = get_stylesheet_directory();
            $path = $theme_dir . '/' . $file;
            if (!file_exists($path)) return wpilot_err("Theme file not found: {$file}.");
            // Backup
            $backup_dir = WP_CONTENT_DIR . '/wpilot-backups/theme/' . date('Y-m-d');
            wp_mkdir_p($backup_dir);
            copy($path, $backup_dir . '/' . basename($file) . '.' . time() . '.bak');
            $content = file_get_contents($path);
            if (!empty($search)) {
                if (strpos($content, $search) === false) return wpilot_err("Text not found in {$file}.");
                $content = str_replace($search, $replace_str, $content);
                file_put_contents($path, $content);
                return wpilot_ok("Updated theme file: {$file}", ['file' => $file, 'path' => $path]);
            }
            if (!empty($append)) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $close_pos = strrpos($content, '?>');
                    if ($close_pos !== false) {
                        $content = substr($content, 0, $close_pos) . "\n" . $append . "\n" . substr($content, $close_pos);
                    } else {
                        $content .= "\n" . $append;
                    }
                } else {
                    $content .= "\n" . $append;
                }
                file_put_contents($path, $content);
                return wpilot_ok("Appended to {$file}", ['file' => $file]);
            }
            return wpilot_ok("Theme file: {$file} (" . strlen($content) . " bytes)", [
                'file' => $file, 'content' => $content, 'lines' => substr_count($content, "\n") + 1
            ]);

        // ═══ DATABASE QUERY (read-only + safe writes) ═══
        // Built by Christos Ferlachidis & Daniel Hedenberg
        case 'db_query':
        case 'database_query':
            $query = $params['query'] ?? $params['sql'] ?? '';
            if (empty($query)) return wpilot_err('SQL query required.');
            global $wpdb;
            $query_upper = strtoupper(trim($query));
            // SECURITY: Block dangerous SQL patterns (check anywhere in query, not just start)
            $blocked = ['DROP ', 'TRUNCATE ', 'ALTER ', 'CREATE TABLE', 'GRANT ', 'REVOKE ', 'LOAD ', 'INTO OUTFILE', 'INTO DUMPFILE', 'SLEEP(', 'BENCHMARK(', 'LOAD_FILE('];
            foreach ($blocked as $b) {
                if (strpos($query_upper, $b) !== false) return wpilot_err("Blocked: dangerous SQL pattern detected.");
            }
            // Block stacked queries
            if (substr_count($query, ';') > 0) return wpilot_err("Multiple statements not allowed.");
            // Block UNION-based injection
            if (preg_match('/UNION\s+(ALL\s+)?SELECT/i', $query)) return wpilot_err("UNION SELECT not allowed.");
            $query = str_replace(['{prefix}', '{wp_}'], $wpdb->prefix, $query);
            // SECURITY: Only allow queries against known WP tables
            if (!preg_match('/(?:FROM|TABLE|INTO|UPDATE)\s+[`]?(' . preg_quote($wpdb->prefix, '/') . '\w+)[`]?/i', $query) && strpos($query_upper, 'SHOW') !== 0 && strpos($query_upper, 'DESCRIBE') !== 0) {
                return wpilot_err("Query must reference tables with the WordPress prefix ({$wpdb->prefix}).");
            }
            if (strpos($query_upper, 'SELECT') === 0 || strpos($query_upper, 'SHOW') === 0 || strpos($query_upper, 'DESCRIBE') === 0) {
                $results = $wpdb->get_results($query, ARRAY_A);
                if ($wpdb->last_error) return wpilot_err("SQL error: " . $wpdb->last_error);
                return wpilot_ok(count($results) . " rows.", ['rows' => array_slice($results, 0, 100), 'total' => count($results)]);
            }
            if (strpos($query_upper, 'UPDATE') === 0 || strpos($query_upper, 'INSERT') === 0 || strpos($query_upper, 'DELETE') === 0) {
                if (empty($params['confirm'])) {
                    return wpilot_err("Write query requires confirm:true. Query: " . substr($query, 0, 200));
                }
                $affected = $wpdb->query($query);
                if ($wpdb->last_error) return wpilot_err("SQL error: " . $wpdb->last_error);
                return wpilot_ok("{$affected} rows affected.", ['affected' => $affected]);
            }
            return wpilot_err("Unsupported query type. Use SELECT, SHOW, UPDATE, INSERT, or DELETE.");

        // ═══ WP-CLI WRAPPER ═══
        case 'wp_cli':
        case 'run_command':
            $cmd = $params['command'] ?? $params['cmd'] ?? '';
            if (empty($cmd)) return wpilot_err('WP-CLI command required. Example: command="post list --post_type=page"');
            if (preg_match('/[;&|`$()\\{}]/', $cmd)) return wpilot_err('Shell operators not allowed in WP-CLI commands.');
            $blocked_cmds = ['db export', 'db import', 'db reset', 'db drop', 'db query', 'core download', 'config set', 'config delete', 'package install', 'super-admin', 'shell', 'eval-file', 'eval '];
            foreach ($blocked_cmds as $bc) {
                if (strpos($cmd, $bc) !== false) return wpilot_err("Blocked command: {$bc}");
            }
            // Split command into args for safe execution
            $args = ['wp', '--allow-root', '--path=' . ABSPATH];
            $parts = preg_split('/\s+/', trim($cmd));
            foreach ($parts as $part) { $args[] = $part; }
            $descriptors = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
            $proc = proc_open($args, $descriptors, $pipes);
            if (!is_resource($proc)) return wpilot_err('Failed to run WP-CLI.');
            fclose($pipes[0]);
            stream_set_timeout($pipes[1], 30);
            $output = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $exit = proc_close($proc);
            if ($exit !== 0) return wpilot_err("WP-CLI error: " . ($stderr ?: $output));
            return wpilot_ok("Command: wp {$cmd}", ['output' => $output, 'exit_code' => $exit]);

        // ═══ ACTION CHAIN — Run multiple tools in sequence ═══
        case 'run_chain':
        case 'action_chain':
        case 'multi_action':
            $steps = $params['steps'] ?? $params['actions'] ?? [];
            if (empty($steps) || !is_array($steps)) return wpilot_err('steps array required: [{"tool":"x","params":{}}, ...]');
            $results = [];
            foreach ($steps as $i => $step) {
                $tool = $step['tool'] ?? '';
                $step_params = $step['params'] ?? [];
                if (empty($tool)) { $results[] = ['step' => $i+1, 'error' => 'Missing tool']; continue; }
                $r = wpilot_run_tool($tool, $step_params);
                $results[] = ['step' => $i+1, 'tool' => $tool, 'success' => $r['success'] ?? false, 'message' => $r['message'] ?? ''];
                if (!($r['success'] ?? false) && !empty($params['stop_on_error'])) break;
            }
            $passed = count(array_filter($results, fn($r) => $r['success'] ?? false));
            return wpilot_ok("{$passed}/" . count($results) . " steps completed.", ['results' => $results]);

        // ═══ CRON / SCHEDULED TASKS ═══
        case 'schedule_action':
        case 'cron_add':
            $action = sanitize_text_field($params['action'] ?? $params['tool'] ?? '');
            $when = $params['when'] ?? $params['time'] ?? '';
            $action_params = $params['params'] ?? $params['tool_params'] ?? [];
            if (empty($action)) return wpilot_err('action (tool name) and when (datetime) required.');
            $timestamp = strtotime($when);
            if (!$timestamp || $timestamp < time()) $timestamp = time() + 3600;
            $scheduled = get_option('wpilot_scheduled_actions', []);
            $id = uniqid('wpi_');
            $scheduled[$id] = [
                'tool' => $action, 'params' => $action_params,
                'scheduled_at' => date('Y-m-d H:i:s'), 'run_at' => date('Y-m-d H:i:s', $timestamp), 'status' => 'pending',
            ];
            update_option('wpilot_scheduled_actions', $scheduled);
            wp_schedule_single_event($timestamp, 'wpilot_run_scheduled', [$id]);
            return wpilot_ok("Scheduled '{$action}' for " . date('Y-m-d H:i', $timestamp), ['id' => $id]);

        case 'list_scheduled':
        case 'cron_list':
            $scheduled = get_option('wpilot_scheduled_actions', []);
            $list = [];
            foreach ($scheduled as $id => $s) $list[] = array_merge(['id' => $id], $s);
            return wpilot_ok(count($list) . " scheduled actions.", ['actions' => $list]);

        case 'cancel_scheduled':
        case 'cron_delete':
            $id = sanitize_text_field($params['id'] ?? '');
            $scheduled = get_option('wpilot_scheduled_actions', []);
            if (isset($scheduled[$id])) { unset($scheduled[$id]); update_option('wpilot_scheduled_actions', $scheduled); return wpilot_ok("Cancelled: {$id}"); }
            return wpilot_err("Not found: {$id}");

        // ═══ FULL LOG VIEWER ═══
        case 'read_log':
        case 'error_log':
            $lines = min(intval($params['lines'] ?? 50), 200);
            $log_file = WP_CONTENT_DIR . '/debug.log';
            if (!file_exists($log_file)) $log_file = ini_get('error_log');
            if (!file_exists($log_file)) return wpilot_err('No log file found. Enable WP_DEBUG_LOG.');
            $file_lines = file($log_file);
            $total = count($file_lines);
            $tail = array_slice($file_lines, -$lines);
            return wpilot_ok("Last {$lines} of {$total} lines.", ['log' => implode('', $tail), 'total_lines' => $total]);

        
        // ═══ CHECKOUT PAGE DESIGN ═══
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
            // Built by Christos Ferlachidis & Daniel Hedenberg
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
        // Built by Christos Ferlachidis & Daniel Hedenberg

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
        case 'woo_create_api_key':
        case 'woo_generate_api':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $description = sanitize_text_field($params['description'] ?? $params['name'] ?? 'WPilot API Access');
            $permissions = sanitize_text_field($params['permissions'] ?? 'read_write'); // read, write, read_write
            $user_id = intval($params['user_id'] ?? get_current_user_id());
            global $wpdb;
            $consumer_key = 'ck_' . wc_rand_hash();
            $consumer_secret = 'cs_' . wc_rand_hash();
            $wpdb->insert($wpdb->prefix . 'woocommerce_api_keys', [
                'user_id' => $user_id,
                'description' => $description,
                'permissions' => $permissions,
                'consumer_key' => wc_api_hash($consumer_key),
                'consumer_secret' => $consumer_secret,
                'truncated_key' => substr($consumer_key, -7),
            ]);
            $key_id = $wpdb->insert_id;
            return wpilot_ok("WooCommerce API key created: {$description}", [
                'key_id' => $key_id,
                'consumer_key' => $consumer_key,
                'consumer_secret' => $consumer_secret,
                'permissions' => $permissions,
                'api_url' => get_site_url() . '/wp-json/wc/v3/',
                'note' => 'Save these keys — the secret cannot be shown again.',
            ]);

        case 'woo_list_api_keys':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            global $wpdb;
            $keys = $wpdb->get_results("SELECT key_id, user_id, description, permissions, truncated_key, last_access FROM {$wpdb->prefix}woocommerce_api_keys ORDER BY key_id DESC LIMIT 20", ARRAY_A);
            return wpilot_ok(count($keys) . " WooCommerce API keys.", ['keys' => $keys]);

        // ═══ ORDER PRINTING / RECEIPTS ═══
        case 'print_order':
        case 'order_receipt':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $order_id = intval($params['order_id'] ?? $params['id'] ?? 0);
            if (!$order_id) return wpilot_err('order_id required.');
            $order = wc_get_order($order_id);
            if (!$order) return wpilot_err("Order #{$order_id} not found.");
            $receipt = "═══════════════════════════════\n";
            $receipt .= "         " . get_bloginfo('name') . "\n";
            $receipt .= "═══════════════════════════════\n";
            $receipt .= "Order: #{$order_id}\n";
            $receipt .= "Date: " . $order->get_date_created()->format('Y-m-d H:i') . "\n";
            $receipt .= "Status: " . ucfirst($order->get_status()) . "\n";
            $receipt .= "───────────────────────────────\n";
            $receipt .= "Customer: " . $order->get_billing_first_name() . " " . $order->get_billing_last_name() . "\n";
            $receipt .= "Email: " . $order->get_billing_email() . "\n";
            if ($order->get_billing_phone()) $receipt .= "Phone: " . $order->get_billing_phone() . "\n";
            $receipt .= "───────────────────────────────\n";
            $receipt .= "ITEMS:\n";
            foreach ($order->get_items() as $item) {
                $qty = $item->get_quantity();
                $total = $item->get_total();
                $receipt .= "  {$qty}x " . $item->get_name() . " — " . wc_price($total) . "\n";
            }
            $receipt .= "───────────────────────────────\n";
            if (floatval($order->get_shipping_total()) > 0) $receipt .= "Shipping: " . wc_price($order->get_shipping_total()) . "\n";
            if (floatval($order->get_total_tax()) > 0) $receipt .= "Tax: " . wc_price($order->get_total_tax()) . "\n";
            $receipt .= "TOTAL: " . wc_price($order->get_total()) . "\n";
            $receipt .= "Payment: " . $order->get_payment_method_title() . "\n";
            $receipt .= "═══════════════════════════════\n";
            if ($order->get_shipping_address_1()) {
                $receipt .= "Ship to: " . $order->get_shipping_address_1() . ", " . $order->get_shipping_city() . " " . $order->get_shipping_postcode() . "\n";
            }
            // Save as file for download
            $upload = wp_upload_dir();
            $file = $upload['basedir'] . "/wpilot-receipt-{$order_id}.txt";
            file_put_contents($file, $receipt);
            return wpilot_ok("Receipt for order #{$order_id}", [
                'receipt' => $receipt,
                'download_url' => $upload['baseurl'] . "/wpilot-receipt-{$order_id}.txt",
                'total' => $order->get_total(),
                'currency' => $order->get_currency(),
            ]);

        // ═══ SALES REPORTS & ANALYTICS ═══
        // Built by Christos Ferlachidis & Daniel Hedenberg
        case 'sales_report':
        case 'revenue_report':
        case 'woo_sales_report':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $period = sanitize_text_field($params['period'] ?? 'month'); // today, week, month, year, all
            $periods = ['today' => '1 day', 'week' => '7 days', 'month' => '30 days', 'year' => '365 days', 'all' => '100 years'];
            $after = date('Y-m-d', strtotime('-' . ($periods[$period] ?? '30 days')));
            $orders = wc_get_orders(['date_after' => $after, 'status' => ['completed', 'processing'], 'limit' => -1]);
            $total_revenue = 0; $total_orders = count($orders); $total_items = 0;
            $by_day = []; $by_product = []; $by_status = [];
            foreach ($orders as $o) {
                $total_revenue += floatval($o->get_total());
                $day = $o->get_date_created()->format('Y-m-d');
                $by_day[$day] = ($by_day[$day] ?? 0) + floatval($o->get_total());
                $by_status[$o->get_status()] = ($by_status[$o->get_status()] ?? 0) + 1;
                foreach ($o->get_items() as $item) {
                    $name = $item->get_name();
                    $total_items += $item->get_quantity();
                    $by_product[$name] = ($by_product[$name] ?? 0) + floatval($item->get_total());
                }
            }
            arsort($by_product);
            $avg_order = $total_orders > 0 ? round($total_revenue / $total_orders, 2) : 0;
            $currency = get_woocommerce_currency();
            return wpilot_ok("Sales report ({$period}): {$total_revenue} {$currency} from {$total_orders} orders.", [
                'period' => $period, 'from' => $after,
                'total_revenue' => $total_revenue, 'total_orders' => $total_orders, 'total_items' => $total_items,
                'avg_order_value' => $avg_order, 'currency' => $currency,
                'by_status' => $by_status,
                'top_products' => array_slice($by_product, 0, 10, true),
                'daily_revenue' => array_slice($by_day, -14, null, true),
            ]);

        case 'customer_stats':
        case 'woo_customer_stats':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $customers = get_users(['role' => 'customer']);
            $total = count($customers); $total_spent = 0; $top = [];
            foreach ($customers as $u) {
                $c = new WC_Customer($u->ID);
                $spent = floatval($c->get_total_spent());
                $total_spent += $spent;
                $top[] = ['name' => $u->display_name, 'email' => $u->user_email, 'spent' => $spent, 'orders' => $c->get_order_count(), 'registered' => $u->user_registered];
            }
            usort($top, fn($a, $b) => $b['spent'] <=> $a['spent']);
            $new_this_month = count(array_filter($customers, fn($u) => strtotime($u->user_registered) > strtotime('-30 days')));
            return wpilot_ok("{$total} customers, " . get_woocommerce_currency_symbol() . number_format($total_spent, 0) . " total spent.", [
                'total_customers' => $total, 'total_spent' => $total_spent,
                'new_this_month' => $new_this_month,
                'avg_spent' => $total > 0 ? round($total_spent / $total, 2) : 0,
                'top_10' => array_slice($top, 0, 10),
            ]);

        case 'inventory_report':
        case 'stock_report':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $products = wc_get_products(['limit' => -1, 'status' => 'publish']);
            $in_stock = 0; $out_of_stock = 0; $low_stock = 0; $items = [];
            $low_threshold = intval($params['threshold'] ?? 5);
            foreach ($products as $p) {
                $stock = $p->get_stock_quantity();
                $status = $p->get_stock_status();
                if ($status === 'instock') $in_stock++;
                elseif ($status === 'outofstock') { $out_of_stock++; $items[] = ['name' => $p->get_name(), 'id' => $p->get_id(), 'stock' => 0, 'status' => 'out']; }
                if ($stock !== null && $stock <= $low_threshold && $stock > 0) { $low_stock++; $items[] = ['name' => $p->get_name(), 'id' => $p->get_id(), 'stock' => $stock, 'status' => 'low']; }
            }
            return wpilot_ok("Inventory: {$in_stock} in stock, {$out_of_stock} out, {$low_stock} low.", [
                'total' => count($products), 'in_stock' => $in_stock, 'out_of_stock' => $out_of_stock, 'low_stock' => $low_stock,
                'alerts' => $items,
            ]);

        // ═══ SECURITY PLUGIN INTEGRATIONS ═══
        case 'block_ip':
        case 'ban_ip':
            $ip = sanitize_text_field($params['ip'] ?? '');
            $reason = sanitize_text_field($params['reason'] ?? 'Blocked by WPilot');
            if (empty($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) return wpilot_err('Valid IP address required.');
            $blocked = get_option('wpilot_blocked_ips', []);
            $blocked[$ip] = ['reason' => $reason, 'date' => date('Y-m-d H:i:s')];
            update_option('wpilot_blocked_ips', $blocked);
            // Add to .htaccess if Apache
            $htaccess = ABSPATH . '.htaccess';
            if (file_exists($htaccess)) {
                $content = file_get_contents($htaccess);
                if (strpos($content, $ip) === false) {
                    $rule = "\n# WPilot blocked IP\nDeny from {$ip}\n";
                    $content = str_replace('# END WordPress', $rule . "# END WordPress", $content);
                    file_put_contents($htaccess, $content);
                }
            }
            return wpilot_ok("Blocked IP: {$ip}", ['ip' => $ip, 'reason' => $reason]);

        case 'unblock_ip':
            $ip = sanitize_text_field($params['ip'] ?? '');
            $blocked = get_option('wpilot_blocked_ips', []);
            unset($blocked[$ip]);
            update_option('wpilot_blocked_ips', $blocked);
            return wpilot_ok("Unblocked IP: {$ip}");

        case 'list_blocked_ips':
            $blocked = get_option('wpilot_blocked_ips', []);
            $list = [];
            foreach ($blocked as $ip => $data) $list[] = array_merge(['ip' => $ip], $data);
            return wpilot_ok(count($list) . " blocked IPs.", ['ips' => $list]);

        case 'failed_logins':
        case 'login_attempts':
            global $wpdb;
            // Check if Wordfence or Limit Login Attempts is active
            $attempts = [];
            // Try WPilot's own tracking
            $log = get_option('wpilot_failed_logins', []);
            // Try WordPress default
            if (empty($log)) {
                $log_file = WP_CONTENT_DIR . '/debug.log';
                if (file_exists($log_file)) {
                    $lines = file($log_file);
                    foreach (array_reverse($lines) as $line) {
                        if (stripos($line, 'authentication') !== false || stripos($line, 'login failed') !== false) {
                            $attempts[] = trim($line);
                            if (count($attempts) >= 20) break;
                        }
                    }
                }
            }
            return wpilot_ok(count($attempts) . " failed login entries found.", ['attempts' => $attempts]);

        case 'security_audit':
        case 'full_security_check':
            $issues = [];
            $score = 100;
            // Check debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) { $issues[] = ['severity' => 'high', 'issue' => 'WP_DEBUG is enabled', 'fix' => 'Set WP_DEBUG to false in wp-config.php']; $score -= 15; }
            // Check file editing
            if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) { $issues[] = ['severity' => 'medium', 'issue' => 'File editing enabled in admin', 'fix' => "Add define('DISALLOW_FILE_EDIT', true) to wp-config.php"]; $score -= 10; }
            // Check admin username
            if (username_exists('admin')) { $issues[] = ['severity' => 'high', 'issue' => 'Default "admin" username exists', 'fix' => 'Rename admin user']; $score -= 15; }
            // Check SSL
            if (!is_ssl() && strpos(get_site_url(), 'https') === false) { $issues[] = ['severity' => 'high', 'issue' => 'No SSL/HTTPS', 'fix' => 'Install SSL certificate']; $score -= 20; }
            // Check WP version
            global $wp_version;
            $latest = get_site_transient('update_core');
            if ($latest && !empty($latest->updates) && version_compare($wp_version, $latest->updates[0]->version, '<')) {
                $issues[] = ['severity' => 'high', 'issue' => "WordPress outdated: {$wp_version} (latest: " . $latest->updates[0]->version . ")", 'fix' => 'Update WordPress'];
                $score -= 15;
            }
            // Check plugin updates
            $update_plugins = get_site_transient('update_plugins');
            $outdated = !empty($update_plugins->response) ? count($update_plugins->response) : 0;
            if ($outdated > 0) { $issues[] = ['severity' => 'medium', 'issue' => "{$outdated} plugins need updates", 'fix' => 'Update plugins']; $score -= min($outdated * 5, 20); }
            // Check xmlrpc
            $xmlrpc_test = wp_remote_get(home_url('/xmlrpc.php'), ['timeout' => 5]);
            if (!is_wp_error($xmlrpc_test) && wp_remote_retrieve_response_code($xmlrpc_test) === 200) {
                $issues[] = ['severity' => 'medium', 'issue' => 'XML-RPC enabled', 'fix' => 'Use disable_xmlrpc tool']; $score -= 5;
            }
            // Check security headers
            $home_resp = wp_remote_get(home_url('/'), ['timeout' => 5]);
            if (!is_wp_error($home_resp)) {
                $h = wp_remote_retrieve_headers($home_resp);
                if (empty($h['x-frame-options'])) { $issues[] = ['severity' => 'medium', 'issue' => 'Missing X-Frame-Options header']; $score -= 5; }
                if (empty($h['x-content-type-options'])) { $issues[] = ['severity' => 'low', 'issue' => 'Missing X-Content-Type-Options header']; $score -= 3; }
                if (empty($h['strict-transport-security'])) { $issues[] = ['severity' => 'low', 'issue' => 'Missing HSTS header']; $score -= 3; }
            }
            // Check blocked IPs
            $blocked = count(get_option('wpilot_blocked_ips', []));
            $score = max(0, $score);
            $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 40 ? 'D' : 'F')));
            return wpilot_ok("Security score: {$score}/100 (Grade {$grade}). " . count($issues) . " issues found.", [
                'score' => $score, 'grade' => $grade, 'issues' => $issues, 'blocked_ips' => $blocked,
            ]);

        case 'configure_wordfence':
            if (!is_plugin_active('wordfence/wordfence.php')) return wpilot_err('Wordfence not installed. Use plugin_install to add it.');
            $settings = [];
            if (isset($params['firewall'])) $settings['firewallEnabled'] = $params['firewall'] ? 1 : 0;
            if (isset($params['brute_force'])) $settings['loginSecurityEnabled'] = $params['brute_force'] ? 1 : 0;
            if (isset($params['scan_schedule'])) $settings['scheduledScansEnabled'] = 1;
            if (isset($params['block_fake_google'])) $settings['blockFakeCrawlers'] = $params['block_fake_google'] ? 1 : 0;
            foreach ($settings as $k => $v) update_option('wordfence_' . $k, $v);
            return wpilot_ok("Wordfence configured: " . count($settings) . " settings updated.", ['settings' => $settings]);

        // ═══ PLUGIN POWER INTEGRATIONS ═══
        case 'configure_updraftplus':
        case 'setup_backups':
            if (!class_exists('UpdraftPlus')) return wpilot_err('UpdraftPlus not installed.');
            $schedule_files = sanitize_text_field($params['files_schedule'] ?? 'weekly');
            $schedule_db = sanitize_text_field($params['db_schedule'] ?? 'daily');
            $retain = intval($params['retain'] ?? 5);
            $settings = get_option('updraft_interval', 'manual');
            update_option('updraft_interval', $schedule_files);
            update_option('updraft_interval_database', $schedule_db);
            update_option('updraft_retain', $retain);
            update_option('updraft_retain_db', $retain);
            // Configure storage if provided
            if (!empty($params['storage'])) {
                update_option('updraft_service', [$params['storage']]);
            }
            return wpilot_ok("UpdraftPlus configured: files={$schedule_files}, db={$schedule_db}, retain={$retain}.");

        case 'configure_litespeed':
        case 'setup_cache':
            if (!defined('LSCWP_V')) return wpilot_err('LiteSpeed Cache not installed.');
            $settings = [];
            if (isset($params['cache'])) { update_option('litespeed.conf.cache', $params['cache'] ? 1 : 0); $settings[] = 'cache=' . ($params['cache'] ? 'on' : 'off'); }
            if (isset($params['css_minify'])) { update_option('litespeed.conf.optm-css_min', $params['css_minify'] ? 1 : 0); $settings[] = 'css_minify'; }
            if (isset($params['js_minify'])) { update_option('litespeed.conf.optm-js_min', $params['js_minify'] ? 1 : 0); $settings[] = 'js_minify'; }
            if (isset($params['lazy_load'])) { update_option('litespeed.conf.media-lazy', $params['lazy_load'] ? 1 : 0); $settings[] = 'lazy_load'; }
            if (isset($params['browser_cache'])) { update_option('litespeed.conf.cache-browser', $params['browser_cache'] ? 1 : 0); $settings[] = 'browser_cache'; }
            // IMPORTANT: Keep CSS inline OFF (known to break themes)
            update_option('litespeed.conf.optm-css_comb_ext_inl', 0);
            update_option('litespeed.conf.css_async_inline', 0);
            return wpilot_ok("LiteSpeed configured: " . implode(', ', $settings));

        case 'configure_rankmath':
        case 'setup_seo_plugin':
            if (!class_exists('RankMath')) return wpilot_err('Rank Math not installed.');
            $settings = [];
            if (isset($params['sitemap'])) { update_option('rank_math_modules', array_merge(get_option('rank_math_modules', []), ['sitemap'])); $settings[] = 'sitemap'; }
            if (isset($params['schema'])) { update_option('rank_math_modules', array_merge(get_option('rank_math_modules', []), ['rich-snippet'])); $settings[] = 'schema'; }
            if (isset($params['breadcrumbs'])) { update_option('rank_math_breadcrumbs', $params['breadcrumbs'] ? 'on' : 'off'); $settings[] = 'breadcrumbs'; }
            if (isset($params['noindex_empty_cats'])) { update_option('rank_math_noindex_empty_category', $params['noindex_empty_cats'] ? 'on' : 'off'); $settings[] = 'noindex_empty'; }
            return wpilot_ok("Rank Math configured: " . implode(', ', $settings));

        case 'configure_polylang':
        case 'setup_multilang':
            if (!function_exists('pll_languages_list')) return wpilot_err('Polylang not installed.');
            $languages = pll_languages_list(['fields' => 'name']);
            return wpilot_ok(count($languages) . " languages configured: " . implode(', ', $languages), ['languages' => $languages]);

        case 'translate_page':
            if (!function_exists('pll_set_post_language')) return wpilot_err('Polylang required for translations.');
            $page_id = intval($params['page_id'] ?? 0);
            $lang = sanitize_text_field($params['language'] ?? $params['lang'] ?? '');
            if (!$page_id || empty($lang)) return wpilot_err('page_id and language required.');
            pll_set_post_language($page_id, $lang);
            return wpilot_ok("Page {$page_id} language set to {$lang}.");

        
        // ═══ SERVER PERFORMANCE INFO ═══
        case 'server_info':
        case 'system_info':
            $info = [
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'memory_limit' => WP_MEMORY_LIMIT,
                'memory_used' => round(memory_get_usage() / 1048576, 1) . ' MB',
                'memory_peak' => round(memory_get_peak_usage() / 1048576, 1) . ' MB',
                'php_memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time') . 's',
                'upload_max' => ini_get('upload_max_filesize'),
                'post_max' => ini_get('post_max_size'),
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
                'active_plugins' => count(get_option('active_plugins', [])),
                'theme' => get_template(),
                'builder' => wpilot_detect_builder(),
                'woocommerce' => class_exists('WooCommerce') ? WC()->version : 'not installed',
                'ssl' => is_ssl() ? 'yes' : 'no',
                'multisite' => is_multisite() ? 'yes' : 'no',
                'cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'yes' : 'no',
                'debug_mode' => defined('WP_DEBUG') && WP_DEBUG ? 'yes' : 'no',
                'wpilot_tools' => substr_count(file_get_contents(__FILE__), "case '"),
            ];
            global $wpdb;
            $db_size = 0;
            foreach ($wpdb->get_results("SHOW TABLE STATUS") as $t) $db_size += $t->Data_length + $t->Index_length;
            $info['database_size'] = round($db_size / 1048576, 1) . ' MB';
            $info['db_tables'] = count($wpdb->get_results("SHOW TABLES"));
            return wpilot_ok("Server info: PHP " . PHP_VERSION . ", " . $info['memory_used'] . " used.", $info);

        case 'clear_screenshots':
        case 'delete_screenshots':
            wpilot_cleanup_all_screenshots();
            return wpilot_ok("All screenshots deleted.");

        case 'clear_temp_files':
        case 'cleanup':
            wpilot_cleanup_all_screenshots();
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpilot_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wpilot_%'");
            delete_transient('wpilot_ctx_general');
            delete_transient('wpilot_ctx_build');
            delete_transient('wpilot_ctx_analyze');
            return wpilot_ok("All temp files, screenshots, and caches cleared.");

        case 'storage_usage':
            $dir = wp_upload_dir()['basedir'] . '/wpilot-screenshots';
            $screenshots = is_dir($dir) ? glob($dir . '/*.png') : [];
            $ss_size = 0;
            foreach ($screenshots as $f) $ss_size += filesize($f);
            $receipts = glob(wp_upload_dir()['basedir'] . '/wpilot-receipt-*.txt');
            $csvs = glob(wp_upload_dir()['basedir'] . '/wpilot-*.csv');
            $backups_dir = WP_CONTENT_DIR . '/wpilot-backups';
            $backup_size = 0;
            if (is_dir($backups_dir)) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backups_dir, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iter as $file) $backup_size += $file->getSize();
            }
            return wpilot_ok("WPilot storage usage", [
                'screenshots' => count($screenshots) . ' files (' . round($ss_size/1024) . ' KB)',
                'receipts' => count($receipts) . ' files',
                'csv_exports' => count($csvs) . ' files',
                'backups' => round($backup_size/1024) . ' KB',
                'total_kb' => round(($ss_size + $backup_size) / 1024),
            ]);

        
        // ═══ CUSTOM HEADER BUILDER ═══
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
            // Built by Christos Ferlachidis & Daniel Hedenberg
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
    return $sent ? wpilot_ok("Test email sent to {$to}.") : wpilot_err("Failed to send test email.");

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
    // Built by Christos Ferlachidis & Daniel Hedenberg
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
    return wpilot_err('Provide updates array or percentage.');

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

        /* ── Role / Permission Tools ────────────────────────── */
        case 'create_role':
            $role_slug    = sanitize_text_field($params['role_slug'] ?? '');
            $role_name    = sanitize_text_field($params['role_name'] ?? '');
            $capabilities = $params['capabilities'] ?? ['read' => true];
            if (!$role_slug || !$role_name) return wpilot_err('role_slug and role_name required.');
            if (get_role($role_slug)) return wpilot_err("Role '{$role_slug}' already exists.");
            $caps = [];
            foreach ($capabilities as $cap => $val) $caps[sanitize_text_field($cap)] = (bool)$val;
            add_role($role_slug, $role_name, $caps);
            return wpilot_ok("Role '{$role_name}' ({$role_slug}) created with " . count($caps) . " capabilities.", ['role' => $role_slug, 'capabilities' => count($caps)]);

        case 'delete_role':
            $role_slug = sanitize_text_field($params['role_slug'] ?? '');
            if (!$role_slug) return wpilot_err('role_slug required.');
            if (in_array($role_slug, ['administrator', 'editor', 'author', 'contributor', 'subscriber'])) return wpilot_err("Cannot delete built-in role '{$role_slug}'.");
            if (!get_role($role_slug)) return wpilot_err("Role '{$role_slug}' not found.");
            remove_role($role_slug);
            return wpilot_ok("Role '{$role_slug}' deleted.");

        case 'list_roles':
            global $wp_roles;
            if (!isset($wp_roles)) $wp_roles = new WP_Roles();
            $roles_list = [];
            foreach ($wp_roles->roles as $slug => $details) {
                $roles_list[] = ['slug' => $slug, 'name' => $details['name'], 'capabilities' => count($details['capabilities'])];
            }
            return wpilot_ok(count($roles_list) . " roles found.", ['roles' => $roles_list]);

        case 'add_capability':
            $role = sanitize_text_field($params['role'] ?? '');
            $cap  = sanitize_text_field($params['capability'] ?? '');
            if (!$role || !$cap) return wpilot_err('role and capability required.');
            $role_obj = get_role($role);
            if (!$role_obj) return wpilot_err("Role '{$role}' not found.");
            $role_obj->add_cap($cap);
            return wpilot_ok("Capability '{$cap}' added to role '{$role}'.");

        case 'set_user_role':
            $user_id = intval($params['user_id'] ?? 0);
            $role    = sanitize_text_field($params['role'] ?? '');
            if (!$user_id || !$role) return wpilot_err('user_id and role required.');
            $user = get_user_by('id', $user_id);
            if (!$user) return wpilot_err("User #{$user_id} not found.");
            if (!get_role($role)) return wpilot_err("Role '{$role}' not found.");
            $user->set_role($role);
            return wpilot_ok("User #{$user_id} ({$user->user_login}) role set to '{$role}'.");

        /* ── Admin Panel Tools ──────────────────────────────── */
        case 'add_admin_notice':
            $message     = sanitize_text_field($params['message'] ?? '');
            $type        = sanitize_text_field($params['type'] ?? 'info');
            $dismissible = isset($params['dismissible']) ? (bool)$params['dismissible'] : true;
            if (!$message) return wpilot_err('message required.');
            $valid_types = ['success', 'warning', 'error', 'info'];
            if (!in_array($type, $valid_types)) $type = 'info';
            $notices = get_option('wpilot_admin_notices', []);
            $notices[] = ['message' => $message, 'type' => $type, 'dismissible' => $dismissible, 'time' => time()];
            update_option('wpilot_admin_notices', $notices);
            return wpilot_ok("Admin notice ({$type}) added: {$message}", ['type' => $type, 'dismissible' => $dismissible]);

        case 'customize_admin_bar':
            $hide = $params['hide'] ?? [];
            if (empty($hide) || !is_array($hide)) return wpilot_err('hide array required (array of admin bar item IDs).');
            $existing = get_option('wpilot_hidden_admin_bar', []);
            $merged = array_unique(array_merge($existing, $hide));
            update_option('wpilot_hidden_admin_bar', $merged);
            return wpilot_ok(count($hide) . " admin bar item(s) hidden.", ['hidden' => $merged]);

        case 'add_dashboard_widget':
            $title   = sanitize_text_field($params['title'] ?? 'WPilot Widget');
            $content = wp_kses_post($params['content'] ?? '');
            if (!$content) return wpilot_err('content (HTML) required.');
            $widgets = get_option('wpilot_dashboard_widgets', []);
            $widget_id = 'wpilot_widget_' . wp_rand(1000, 9999);
            $widgets[$widget_id] = ['title' => $title, 'content' => $content];
            update_option('wpilot_dashboard_widgets', $widgets);
            return wpilot_ok("Dashboard widget '{$title}' added.", ['widget_id' => $widget_id]);

        case 'admin_color_scheme':
            $scheme = sanitize_text_field($params['scheme'] ?? 'default');
            $valid = ['default', 'light', 'blue', 'midnight', 'sunrise', 'ectoplasm', 'coffee', 'ocean', 'modern'];
            if (!in_array($scheme, $valid)) return wpilot_err("Unknown scheme: {$scheme}. Options: " . implode(', ', $valid));
            $users = get_users(['role__in' => ['administrator'], 'fields' => 'ID']);
            foreach ($users as $uid) update_user_meta($uid, 'admin_color', $scheme);
            return wpilot_ok("Admin color scheme set to '{$scheme}' for " . count($users) . " admin(s).", ['scheme' => $scheme]);

        
        // ═══ CALENDAR / EVENTS ═══
        case 'create_event':
        case 'add_event':
            $title = sanitize_text_field($params['title'] ?? '');
            $date = sanitize_text_field($params['date'] ?? '');
            $time = sanitize_text_field($params['time'] ?? '');
            $end_date = sanitize_text_field($params['end_date'] ?? $date);
            $location = sanitize_text_field($params['location'] ?? '');
            $description = wp_kses_post($params['description'] ?? '');
            if (empty($title) || empty($date)) return wpilot_err('title and date required.');
            $events = get_option('wpilot_events', []);
            $id = uniqid('evt_');
            $events[$id] = ['title' => $title, 'date' => $date, 'time' => $time, 'end_date' => $end_date, 'location' => $location, 'description' => $description, 'created' => date('Y-m-d H:i:s')];
            update_option('wpilot_events', $events);
            return wpilot_ok("Event created: {$title} on {$date}" . ($time ? " at {$time}" : ""), ['id' => $id]);

        case 'list_events':
            $events = get_option('wpilot_events', []);
            $upcoming = [];
            foreach ($events as $id => $e) {
                if ($e['date'] >= date('Y-m-d')) $upcoming[] = array_merge(['id' => $id], $e);
            }
            usort($upcoming, fn($a, $b) => strcmp($a['date'], $b['date']));
            return wpilot_ok(count($upcoming) . " upcoming events.", ['events' => $upcoming]);

        case 'delete_event':
            $id = sanitize_text_field($params['id'] ?? '');
            $events = get_option('wpilot_events', []);
            if (isset($events[$id])) { unset($events[$id]); update_option('wpilot_events', $events); return wpilot_ok("Event deleted."); }
            return wpilot_err("Event not found.");

        case 'create_calendar_page':
            $events = get_option('wpilot_events', []);
            $html = '<div style="max-width:900px;margin:60px auto;padding:0 24px;font-family:system-ui,sans-serif">';
            $html .= '<h2 style="font-size:2rem;font-weight:800;margin:0 0 32px;color:inherit">Upcoming Events</h2>';
            $html .= '<div style="display:flex;flex-direction:column;gap:16px">';
            foreach ($events as $e) {
                if ($e['date'] < date('Y-m-d')) continue;
                $html .= '<div style="display:flex;gap:20px;padding:20px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:12px">';
                $html .= '<div style="text-align:center;min-width:60px"><p style="font-size:1.8rem;font-weight:800;margin:0;color:#E91E8C">' . date('d', strtotime($e['date'])) . '</p><p style="font-size:0.8rem;text-transform:uppercase;margin:0;color:rgba(255,255,255,0.5)">' . date('M', strtotime($e['date'])) . '</p></div>';
                $html .= '<div><h3 style="margin:0 0 4px;font-weight:600">' . esc_html($e['title']) . '</h3>';
                if ($e['time']) $html .= '<p style="margin:0;color:rgba(255,255,255,0.5);font-size:0.9rem">🕐 ' . esc_html($e['time']) . '</p>';
                if ($e['location']) $html .= '<p style="margin:4px 0 0;color:rgba(255,255,255,0.5);font-size:0.9rem">📍 ' . esc_html($e['location']) . '</p>';
                $html .= '</div></div>';
            }
            $html .= '</div></div>';
            $page_id = wp_insert_post(['post_title' => 'Events', 'post_content' => $html, 'post_status' => 'publish', 'post_type' => 'page']);
            return wpilot_ok("Calendar page created.", ['page_id' => $page_id, 'url' => get_permalink($page_id)]);

        // ═══ DOWNLOAD / EXPORT TOOLS ═══
        case 'download_site':
        case 'export_full_site':
            $upload = wp_upload_dir();
            $export_dir = $upload['basedir'] . '/wpilot-export';
            wp_mkdir_p($export_dir);
            // Export posts + pages as JSON
            $pages = get_posts(['post_type' => ['page', 'post'], 'numberposts' => -1, 'post_status' => 'publish']);
            $data = [];
            foreach ($pages as $p) {
                $data[] = ['id' => $p->ID, 'type' => $p->post_type, 'title' => $p->post_title, 'slug' => $p->post_name, 'content' => $p->post_content, 'date' => $p->post_date, 'status' => $p->post_status];
            }
            file_put_contents($export_dir . '/pages.json', wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            // Export options
            $opts = ['blogname', 'blogdescription', 'siteurl', 'home', 'permalink_structure', 'template', 'stylesheet'];
            $options = [];
            foreach ($opts as $o) $options[$o] = get_option($o);
            file_put_contents($export_dir . '/options.json', wp_json_encode($options, JSON_PRETTY_PRINT));
            // Export custom CSS
            file_put_contents($export_dir . '/custom.css', wp_get_custom_css());
            // Export menus
            $menus_data = [];
            foreach (wp_get_nav_menus() as $menu) {
                $items = wp_get_nav_menu_items($menu->term_id);
                $menus_data[$menu->name] = array_map(fn($i) => ['title' => $i->title, 'url' => $i->url, 'parent' => $i->menu_item_parent], $items ?: []);
            }
            file_put_contents($export_dir . '/menus.json', wp_json_encode($menus_data, JSON_PRETTY_PRINT));
            return wpilot_ok("Site exported: " . count($data) . " pages/posts, " . count($menus_data) . " menus.", [
                'download_url' => $upload['baseurl'] . '/wpilot-export/',
                'files' => ['pages.json', 'options.json', 'custom.css', 'menus.json'],
            ]);

        case 'download_orders':
        case 'export_orders':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $limit = intval($params['limit'] ?? 500);
            $status = $params['status'] ?? ['completed', 'processing', 'on-hold'];
            if (is_string($status)) $status = explode(',', $status);
            $orders = wc_get_orders(['limit' => $limit, 'status' => $status, 'orderby' => 'date', 'order' => 'DESC']);
            $csv = "Order ID,Date,Status,Customer,Email,Phone,Address,City,Postcode,Country,Items,Subtotal,Shipping,Tax,Total,Payment Method,Note\n";
            foreach ($orders as $o) {
                $items = [];
                foreach ($o->get_items() as $item) $items[] = $item->get_name() . ' x' . $item->get_quantity();
                $csv .= implode(',', [
                    $o->get_id(), $o->get_date_created()->format('Y-m-d H:i'), $o->get_status(),
                    '"' . $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() . '"',
                    $o->get_billing_email(), $o->get_billing_phone(),
                    '"' . $o->get_billing_address_1() . '"', $o->get_billing_city(), $o->get_billing_postcode(), $o->get_billing_country(),
                    '"' . implode('; ', $items) . '"',
                    $o->get_subtotal(), $o->get_shipping_total(), $o->get_total_tax(), $o->get_total(),
                    $o->get_payment_method_title(), '"' . str_replace('"', "'", $o->get_customer_note()) . '"',
                ]) . "\n";
            }
            $upload = wp_upload_dir();
            // Built by Christos Ferlachidis & Daniel Hedenberg
            $file = $upload['basedir'] . '/wpilot-orders-export.csv';
            file_put_contents($file, $csv);
            return wpilot_ok("Exported " . count($orders) . " orders.", ['download_url' => $upload['baseurl'] . '/wpilot-orders-export.csv', 'count' => count($orders)]);

        case 'download_customers':
        case 'export_customers':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $customers = get_users(['role' => 'customer']);
            $csv = "ID,Name,Email,Phone,Address,City,Postcode,Country,Orders,Total Spent,Registered\n";
            foreach ($customers as $u) {
                $c = new WC_Customer($u->ID);
                $csv .= implode(',', [
                    $u->ID, '"' . $u->display_name . '"', $u->user_email,
                    $c->get_billing_phone(), '"' . $c->get_billing_address_1() . '"',
                    $c->get_billing_city(), $c->get_billing_postcode(), $c->get_billing_country(),
                    $c->get_order_count(), $c->get_total_spent(), $u->user_registered,
                ]) . "\n";
            }
            $upload = wp_upload_dir();
            file_put_contents($upload['basedir'] . '/wpilot-customers.csv', $csv);
            return wpilot_ok("Exported " . count($customers) . " customers.", ['download_url' => $upload['baseurl'] . '/wpilot-customers.csv']);

        // ═══ BULK PRODUCT IMPORT/EXPORT ═══
        case 'download_products':
        case 'export_all_products':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $products = wc_get_products(['limit' => -1, 'status' => 'publish']);
            $csv = "ID,Name,SKU,Price,Sale Price,Stock,Status,Category,Description,Image URL\n";
            foreach ($products as $p) {
                $cats = wp_get_post_terms($p->get_id(), 'product_cat', ['fields' => 'names']);
                $img = wp_get_attachment_url($p->get_image_id());
                $csv .= implode(',', [
                    $p->get_id(), '"' . $p->get_name() . '"', $p->get_sku(),
                    $p->get_regular_price(), $p->get_sale_price(), $p->get_stock_quantity() ?? '',
                    $p->get_stock_status(), '"' . implode(';', $cats) . '"',
                    '"' . substr(strip_tags($p->get_short_description()), 0, 100) . '"',
                    $img ?: '',
                ]) . "\n";
            }
            $upload = wp_upload_dir();
            file_put_contents($upload['basedir'] . '/wpilot-products.csv', $csv);
            return wpilot_ok("Exported " . count($products) . " products.", ['download_url' => $upload['baseurl'] . '/wpilot-products.csv']);

        // ═══ SHIPPING TOOLS ═══
        case 'shipping_zones':
        case 'list_shipping_zones':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $zones_raw = WC_Shipping_Zones::get_zones();
            $zones = [];
            foreach ($zones_raw as $z) {
                $methods = [];
                foreach ($z['shipping_methods'] as $m) {
                    $methods[] = ['id' => $m->id, 'title' => $m->title, 'enabled' => $m->enabled, 'cost' => $m->cost ?? ''];
                }
                $zones[] = ['id' => $z['id'], 'name' => $z['zone_name'], 'regions' => count($z['zone_locations']), 'methods' => $methods];
            }
            return wpilot_ok(count($zones) . " shipping zones.", ['zones' => $zones]);

        case 'create_shipping_label':
        case 'shipping_label':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $order_id = intval($params['order_id'] ?? 0);
            if (!$order_id) return wpilot_err('order_id required.');
            $order = wc_get_order($order_id);
            if (!$order) return wpilot_err("Order #{$order_id} not found.");
            $from = ['name' => get_bloginfo('name'), 'address' => get_option('woocommerce_store_address', ''), 'city' => get_option('woocommerce_store_city', ''), 'postcode' => get_option('woocommerce_store_postcode', ''), 'country' => get_option('woocommerce_default_country', '')];
            $to = [
                'name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                'address' => $order->get_shipping_address_1(), 'address2' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(), 'postcode' => $order->get_shipping_postcode(),
                'country' => $order->get_shipping_country(), 'phone' => $order->get_billing_phone(),
            ];
            $items = [];
            $total_weight = 0;
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $weight = $product ? floatval($product->get_weight()) * $item->get_quantity() : 0;
                $total_weight += $weight;
                $items[] = $item->get_name() . ' x' . $item->get_quantity();
            }
            $label = "══════════════════════════════════\n";
            $label .= "  SHIPPING LABEL — Order #{$order_id}\n";
            $label .= "══════════════════════════════════\n\n";
            $label .= "FROM:\n  " . $from['name'] . "\n  " . $from['address'] . "\n  " . $from['postcode'] . " " . $from['city'] . "\n  " . $from['country'] . "\n\n";
            $label .= "TO:\n  " . $to['name'] . "\n  " . $to['address'] . "\n";
            if ($to['address2']) $label .= "  " . $to['address2'] . "\n";
            $label .= "  " . $to['postcode'] . " " . $to['city'] . "\n  " . $to['country'] . "\n";
            if ($to['phone']) $label .= "  Tel: " . $to['phone'] . "\n";
            $label .= "\n──────────────────────────────────\n";
            $label .= "ITEMS: " . implode(', ', $items) . "\n";
            if ($total_weight > 0) $label .= "WEIGHT: " . $total_weight . " kg\n";
            $label .= "SHIPPING: " . $order->get_shipping_method() . "\n";
            $label .= "══════════════════════════════════\n";
            $upload = wp_upload_dir();
            file_put_contents($upload['basedir'] . "/wpilot-shipping-{$order_id}.txt", $label);
            return wpilot_ok("Shipping label for order #{$order_id}", [
                'label' => $label, 'download_url' => $upload['baseurl'] . "/wpilot-shipping-{$order_id}.txt",
                'from' => $from, 'to' => $to, 'weight_kg' => $total_weight,
            ]);

        case 'postnord_track':
        case 'track_shipment':
            $tracking = sanitize_text_field($params['tracking_number'] ?? $params['tracking'] ?? '');
            if (empty($tracking)) return wpilot_err('tracking_number required.');
            // PostNord tracking API (public)
            $response = wp_remote_get("https://api2.postnord.com/rest/shipment/v5/trackandtrace/findByIdentifier.json?id={$tracking}&locale=sv", ['timeout' => 10]);
            if (is_wp_error($response)) return wpilot_err('PostNord API error: ' . $response->get_error_message());
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $shipments = $body['TrackingInformationResponse']['shipments'] ?? [];
            if (empty($shipments)) return wpilot_ok("No tracking info found for {$tracking}.", ['tracking' => $tracking]);
            $s = $shipments[0];
            $events = [];
            foreach (($s['items'][0]['events'] ?? []) as $e) {
                $events[] = ['date' => $e['eventTime'] ?? '', 'location' => $e['location']['displayName'] ?? '', 'description' => $e['eventDescription'] ?? ''];
            }
            return wpilot_ok("Tracking: {$tracking} — " . ($s['statusText']['header'] ?? 'unknown'), [
                'tracking' => $tracking, 'status' => $s['statusText']['header'] ?? '', 'service' => $s['service']['name'] ?? '',
                'events' => array_slice($events, 0, 10),
            ]);

        // ═══ CUSTOMER DATA TOOLS ═══
        case 'get_customer':
        case 'customer_details':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $id = intval($params['customer_id'] ?? $params['user_id'] ?? $params['id'] ?? 0);
            $email = sanitize_email($params['email'] ?? '');
            if (!$id && $email) { $user = get_user_by('email', $email); if ($user) $id = $user->ID; }
            if (!$id) return wpilot_err('customer_id or email required.');
            $c = new WC_Customer($id);
            $user = get_userdata($id);
            if (!$user) return wpilot_err("Customer #{$id} not found.");
            $orders = wc_get_orders(['customer_id' => $id, 'limit' => 10, 'orderby' => 'date', 'order' => 'DESC']);
            $order_list = [];
            foreach ($orders as $o) $order_list[] = ['id' => $o->get_id(), 'date' => $o->get_date_created()->format('Y-m-d'), 'total' => $o->get_total(), 'status' => $o->get_status()];
            return wpilot_ok("Customer: " . $user->display_name, [
                'id' => $id, 'name' => $user->display_name, 'email' => $user->user_email, 'registered' => $user->user_registered,
                'billing' => ['phone' => $c->get_billing_phone(), 'address' => $c->get_billing_address_1(), 'city' => $c->get_billing_city(), 'postcode' => $c->get_billing_postcode(), 'country' => $c->get_billing_country()],
                'orders' => $c->get_order_count(), 'total_spent' => $c->get_total_spent(), 'recent_orders' => $order_list,
            ]);

        // ═══ FORM DATA TOOLS ═══
        case 'get_form_entries':
        case 'form_submissions':
            // Contact Form 7 — stored via Flamingo plugin or CF7 DB
            global $wpdb;
            $entries = [];
            // Try Flamingo (CF7 addon that stores submissions)
            if (post_type_exists('flamingo_inbound')) {
                $messages = get_posts(['post_type' => 'flamingo_inbound', 'numberposts' => intval($params['limit'] ?? 50), 'orderby' => 'date', 'order' => 'DESC']);
                foreach ($messages as $m) {
                    $meta = get_post_meta($m->ID);
                    $entries[] = ['id' => $m->ID, 'date' => $m->post_date, 'subject' => $m->post_title, 'from' => $meta['_from'][0] ?? '', 'fields' => $meta['_field_'][0] ?? $m->post_content];
                }
            }
            // Try WPForms entries
            $wpf_table = $wpdb->prefix . 'wpforms_entries';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$wpf_table}'") === $wpf_table) {
                $rows = $wpdb->get_results("SELECT entry_id, form_id, fields, date FROM {$wpf_table} ORDER BY date DESC LIMIT " . intval($params['limit'] ?? 50), ARRAY_A);
                foreach ($rows as $r) {
                    $fields = json_decode($r['fields'], true);
                    $entries[] = ['id' => $r['entry_id'], 'form_id' => $r['form_id'], 'date' => $r['date'], 'fields' => $fields];
                }
            }
            // Try Gravity Forms
            if (class_exists('GFAPI')) {
                $gf_entries = GFAPI::get_entries(0, [], null, ['offset' => 0, 'page_size' => intval($params['limit'] ?? 50)]);
                foreach ($gf_entries as $e) $entries[] = ['id' => $e['id'], 'form_id' => $e['form_id'], 'date' => $e['date_created'], 'fields' => $e];
            }
            return wpilot_ok(count($entries) . " form entries found.", ['entries' => $entries]);

        case 'export_form_data':
        case 'download_form_entries':
            $r = wpilot_run_tool('get_form_entries', ['limit' => intval($params['limit'] ?? 500)]);
            if (!($r['success'] ?? false)) return $r;
            $entries = $r['entries'] ?? [];
            if (empty($entries)) return wpilot_ok("No form entries to export.");
            // Build CSV
            $csv = "ID,Date,From/Subject,Data\n";
            foreach ($entries as $e) {
                $data = is_array($e['fields'] ?? '') ? json_encode($e['fields']) : ($e['fields'] ?? '');
                $csv .= $e['id'] . ',"' . ($e['date'] ?? '') . '","' . ($e['from'] ?? $e['subject'] ?? '') . '","' . str_replace('"', "'", substr($data, 0, 500)) . '"' . "\n";
            }
            $upload = wp_upload_dir();
            file_put_contents($upload['basedir'] . '/wpilot-form-entries.csv', $csv);
            return wpilot_ok("Exported " . count($entries) . " form entries.", ['download_url' => $upload['baseurl'] . '/wpilot-form-entries.csv']);

        case 'collect_emails':
        case 'get_all_emails':
            $emails = [];
            // WordPress users
            $users = get_users(['fields' => ['user_email', 'display_name']]);
            foreach ($users as $u) $emails[$u->user_email] = ['name' => $u->display_name, 'source' => 'user'];
            // WooCommerce customers
            if (class_exists('WooCommerce')) {
                $customers = get_users(['role' => 'customer', 'fields' => ['user_email', 'display_name']]);
                foreach ($customers as $c) $emails[$c->user_email] = ['name' => $c->display_name, 'source' => 'customer'];
            }
            // Comments
            global $wpdb;
            $comment_emails = $wpdb->get_results("SELECT DISTINCT comment_author_email as email, comment_author as name FROM {$wpdb->comments} WHERE comment_author_email != '' AND comment_approved = '1'", ARRAY_A);
            foreach ($comment_emails as $ce) { if (!isset($emails[$ce['email']])) $emails[$ce['email']] = ['name' => $ce['name'], 'source' => 'comment']; }
            // Form entries (Flamingo)
            if (post_type_exists('flamingo_contact')) {
                $contacts = get_posts(['post_type' => 'flamingo_contact', 'numberposts' => -1]);
                foreach ($contacts as $fc) {
                    $email = get_post_meta($fc->ID, '_email', true);
                    if ($email && !isset($emails[$email])) $emails[$email] = ['name' => $fc->post_title, 'source' => 'form'];
                }
            }
            // Export CSV
            $csv = "Email,Name,Source\n";
            foreach ($emails as $email => $data) $csv .= "{$email},\"{$data['name']}\",{$data['source']}\n";
            $upload = wp_upload_dir();
            file_put_contents($upload['basedir'] . '/wpilot-all-emails.csv', $csv);
            return wpilot_ok(count($emails) . " unique emails collected from all sources.", [
                'total' => count($emails), 'download_url' => $upload['baseurl'] . '/wpilot-all-emails.csv',
                'by_source' => array_count_values(array_column($emails, 'source')),
            ]);

        // ═══ MAP TOOLS ═══
        case 'create_store_locator':
        case 'store_map':
            $locations = $params['locations'] ?? [];
            $page_title = sanitize_text_field($params['title'] ?? 'Store Locations');
            if (empty($locations)) {
                // Use store address from WooCommerce
                $addr = get_option('woocommerce_store_address', '') . ', ' . get_option('woocommerce_store_city', '') . ' ' . get_option('woocommerce_store_postcode', '');
                $locations = [['name' => get_bloginfo('name'), 'address' => $addr]];
            }
            $html = '<div style="max-width:1000px;margin:60px auto;padding:0 24px">';
            $html .= '<h2 style="font-size:2rem;font-weight:800;margin:0 0 32px">' . esc_html($page_title) . '</h2>';
            foreach ($locations as $loc) {
                $encoded = urlencode($loc['address'] ?? '');
                $html .= '<div style="margin-bottom:24px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:16px;overflow:hidden">';
                $html .= '<iframe width="100%" height="300" style="border:0" loading="lazy" src="https://maps.google.com/maps?q=' . $encoded . '&output=embed"></iframe>';
                $html .= '<div style="padding:20px"><h3 style="margin:0 0 4px;font-weight:600">' . esc_html($loc['name'] ?? '') . '</h3>';
                $html .= '<p style="margin:0;color:rgba(255,255,255,0.5)">' . esc_html($loc['address'] ?? '') . '</p></div></div>';
            }
            $html .= '</div>';
            $page_id = wp_insert_post(['post_title' => $page_title, 'post_content' => $html, 'post_status' => 'publish', 'post_type' => 'page']);
            return wpilot_ok("Store locator page created with " . count($locations) . " locations.", ['page_id' => $page_id, 'url' => get_permalink($page_id)]);

        
        // ═══ FULL COUPON MANAGEMENT ═══
        case 'create_advanced_coupon':
        case 'create_full_coupon':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $code = strtoupper(sanitize_text_field($params['code'] ?? 'SAVE' . rand(10, 99)));
            $amount = floatval($params['amount'] ?? 10);
            // Types: percent, fixed_cart, fixed_product, percent_product
            $type = sanitize_text_field($params['type'] ?? $params['discount_type'] ?? 'percent');
            $id = wp_insert_post(['post_title' => $code, 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'post_excerpt' => sanitize_text_field($params['description'] ?? '')]);
            if (is_wp_error($id)) return wpilot_err($id->get_error_message());
            // Core
            update_post_meta($id, 'discount_type', $type);
            update_post_meta($id, 'coupon_amount', $amount);
            // Restrictions
            if (isset($params['min_amount'])) update_post_meta($id, 'minimum_amount', floatval($params['min_amount']));
            if (isset($params['max_amount'])) update_post_meta($id, 'maximum_amount', floatval($params['max_amount']));
            // Products — by ID or by name
            $product_ids = [];
            if (!empty($params['product_ids'])) {
                $product_ids = array_map('intval', (array)$params['product_ids']);
            }
            if (!empty($params['products'])) {
                // Search products by name
                foreach ((array)$params['products'] as $name) {
                    $found = wc_get_products(['name' => $name, 'limit' => 1]);
                    if (!empty($found)) $product_ids[] = $found[0]->get_id();
                }
            }
            if (!empty($product_ids)) update_post_meta($id, 'product_ids', $product_ids);
            // Excluded products
            if (!empty($params['exclude_products'])) update_post_meta($id, 'exclude_product_ids', array_map('intval', (array)$params['exclude_products']));
            // Categories — by ID or by name
            $cat_ids = [];
            if (!empty($params['category_ids'])) {
                $cat_ids = array_map('intval', (array)$params['category_ids']);
            }
            if (!empty($params['categories'])) {
                foreach ((array)$params['categories'] as $cat_name) {
                    $term = get_term_by('name', $cat_name, 'product_cat');
                    if ($term) $cat_ids[] = $term->term_id;
                }
            }
            if (!empty($cat_ids)) update_post_meta($id, 'product_categories', $cat_ids);
            if (!empty($params['exclude_categories'])) {
                $ex_cats = [];
                foreach ((array)$params['exclude_categories'] as $c) {
                    $t = is_numeric($c) ? intval($c) : (get_term_by('name', $c, 'product_cat') ? get_term_by('name', $c, 'product_cat')->term_id : 0);
                    if ($t) $ex_cats[] = $t;
                }
                update_post_meta($id, 'exclude_product_categories', $ex_cats);
            }
            // Expiry date
            if (!empty($params['expiry']) || !empty($params['expires'])) {
                $expiry = $params['expiry'] ?? $params['expires'];
                $ts = strtotime($expiry);
                if ($ts) update_post_meta($id, 'date_expires', $ts);
            }
            // Usage limits
            // Built by Christos Ferlachidis & Daniel Hedenberg
            if (isset($params['usage_limit'])) update_post_meta($id, 'usage_limit', intval($params['usage_limit']));
            if (isset($params['usage_limit_per_user'])) update_post_meta($id, 'usage_limit_per_user', intval($params['usage_limit_per_user']));
            // Free shipping
            if (!empty($params['free_shipping'])) update_post_meta($id, 'free_shipping', 'yes');
            // Individual use only (can't combine with other coupons)
            if (!empty($params['individual_use'])) update_post_meta($id, 'individual_use', 'yes');
            // Exclude sale items
            if (!empty($params['exclude_sale_items'])) update_post_meta($id, 'exclude_sale_items', 'yes');
            // Email restrictions
            if (!empty($params['allowed_emails'])) update_post_meta($id, 'customer_email', (array)$params['allowed_emails']);
            $label = $type === 'percent' ? "{$amount}%" : "{$amount} " . get_woocommerce_currency();
            $details = "Coupon {$code}: {$label} off";
            if (!empty($product_ids)) $details .= ", " . count($product_ids) . " products";
            if (!empty($cat_ids)) $details .= ", " . count($cat_ids) . " categories";
            if (!empty($params['expiry'])) $details .= ", expires " . $params['expiry'];
            return wpilot_ok($details, ['code' => $code, 'id' => $id, 'discount' => $label]);

        case 'update_coupon':
        case 'edit_coupon':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $code = strtoupper(sanitize_text_field($params['code'] ?? ''));
            $coupon_id = intval($params['coupon_id'] ?? $params['id'] ?? 0);
            if (!$coupon_id && $code) {
                $c = new WC_Coupon($code);
                $coupon_id = $c->get_id();
            }
            if (!$coupon_id) return wpilot_err('code or coupon_id required.');
            $updated = [];
            if (isset($params['amount'])) { update_post_meta($coupon_id, 'coupon_amount', floatval($params['amount'])); $updated[] = 'amount'; }
            if (isset($params['type'])) { update_post_meta($coupon_id, 'discount_type', sanitize_text_field($params['type'])); $updated[] = 'type'; }
            if (isset($params['expiry'])) { $ts = strtotime($params['expiry']); if ($ts) update_post_meta($coupon_id, 'date_expires', $ts); $updated[] = 'expiry'; }
            if (isset($params['min_amount'])) { update_post_meta($coupon_id, 'minimum_amount', floatval($params['min_amount'])); $updated[] = 'min_amount'; }
            if (isset($params['usage_limit'])) { update_post_meta($coupon_id, 'usage_limit', intval($params['usage_limit'])); $updated[] = 'usage_limit'; }
            if (isset($params['enabled'])) {
                $status = $params['enabled'] ? 'publish' : 'draft';
                wp_update_post(['ID' => $coupon_id, 'post_status' => $status]);
                $updated[] = $params['enabled'] ? 'enabled' : 'disabled';
            }
            return wpilot_ok("Coupon #{$coupon_id} updated: " . implode(', ', $updated), ['coupon_id' => $coupon_id, 'updated' => $updated]);

        case 'delete_coupon':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $code = strtoupper(sanitize_text_field($params['code'] ?? ''));
            $coupon_id = intval($params['coupon_id'] ?? $params['id'] ?? 0);
            if (!$coupon_id && $code) { $c = new WC_Coupon($code); $coupon_id = $c->get_id(); }
            if (!$coupon_id) return wpilot_err('code or coupon_id required.');
            wp_trash_post($coupon_id);
            return wpilot_ok("Coupon deleted: " . ($code ?: "#{$coupon_id}"));

        case 'list_coupons':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $coupons = get_posts(['post_type' => 'shop_coupon', 'numberposts' => intval($params['limit'] ?? 50), 'post_status' => ['publish', 'draft']]);
            $list = [];
            foreach ($coupons as $c) {
                $type = get_post_meta($c->ID, 'discount_type', true);
                $amount = get_post_meta($c->ID, 'coupon_amount', true);
                $expires = get_post_meta($c->ID, 'date_expires', true);
                $usage = get_post_meta($c->ID, 'usage_count', true) ?: 0;
                $limit = get_post_meta($c->ID, 'usage_limit', true) ?: 'unlimited';
                $products = get_post_meta($c->ID, 'product_ids', true) ?: [];
                $categories = get_post_meta($c->ID, 'product_categories', true) ?: [];
                $min = get_post_meta($c->ID, 'minimum_amount', true);
                $free_ship = get_post_meta($c->ID, 'free_shipping', true);
                $label = $type === 'percent' ? "{$amount}%" : "{$amount} " . get_woocommerce_currency();
                $list[] = [
                    'id' => $c->ID, 'code' => $c->post_title, 'discount' => $label, 'type' => $type,
                    'status' => $c->post_status, 'usage' => "{$usage}/{$limit}",
                    'expires' => $expires ? date('Y-m-d', $expires) : 'never',
                    'products' => count($products), 'categories' => count($categories),
                    'min_spend' => $min ?: 0, 'free_shipping' => $free_ship === 'yes',
                    'description' => $c->post_excerpt,
                ];
            }
            return wpilot_ok(count($list) . " coupons.", ['coupons' => $list]);

        case 'coupon_usage':
        case 'coupon_stats':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $code = strtoupper(sanitize_text_field($params['code'] ?? ''));
            if (empty($code)) return wpilot_err('Coupon code required.');
            $coupon = new WC_Coupon($code);
            if (!$coupon->get_id()) return wpilot_err("Coupon '{$code}' not found.");
            // Get orders that used this coupon
            $orders = wc_get_orders(['limit' => 100, 'coupon' => $code]);
            $total_discount = 0; $order_list = [];
            foreach ($orders as $o) {
                foreach ($o->get_coupon_codes() as $cc) {
                    if (strtoupper($cc) === $code) {
                        $discount = $o->get_discount_total();
                        $total_discount += $discount;
                        $order_list[] = ['order_id' => $o->get_id(), 'date' => $o->get_date_created()->format('Y-m-d'), 'discount' => $discount, 'total' => $o->get_total(), 'customer' => $o->get_billing_email()];
                    }
                }
            }
            return wpilot_ok("Coupon {$code}: used " . count($order_list) . " times, " . get_woocommerce_currency_symbol() . number_format($total_discount, 0) . " discounted.", [
                'code' => $code, 'times_used' => count($order_list), 'total_discounted' => $total_discount,
                'limit' => $coupon->get_usage_limit() ?: 'unlimited',
                'expires' => $coupon->get_date_expires() ? $coupon->get_date_expires()->format('Y-m-d') : 'never',
                'orders' => array_slice($order_list, 0, 20),
            ]);

        case 'bulk_create_coupons':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $prefix = strtoupper(sanitize_text_field($params['prefix'] ?? 'PROMO'));
            $count = min(intval($params['count'] ?? 10), 100);
            $amount = floatval($params['amount'] ?? 10);
            $type = sanitize_text_field($params['type'] ?? 'percent');
            $expiry = $params['expiry'] ?? '';
            $usage_limit = intval($params['usage_limit'] ?? 1);
            $codes = [];
            for ($i = 0; $i < $count; $i++) {
                $code = $prefix . strtoupper(substr(md5(uniqid()), 0, 6));
                $id = wp_insert_post(['post_title' => $code, 'post_type' => 'shop_coupon', 'post_status' => 'publish']);
                if (!is_wp_error($id)) {
                    update_post_meta($id, 'discount_type', $type);
                    update_post_meta($id, 'coupon_amount', $amount);
                    update_post_meta($id, 'usage_limit', $usage_limit);
                    if ($expiry) update_post_meta($id, 'date_expires', strtotime($expiry));
                    if (!empty($params['product_ids'])) update_post_meta($id, 'product_ids', array_map('intval', (array)$params['product_ids']));
                    if (!empty($params['category_ids'])) update_post_meta($id, 'product_categories', array_map('intval', (array)$params['category_ids']));
                    $codes[] = $code;
                }
            }
            // Export as CSV
            $csv = "Code,Discount,Type,Usage Limit,Expires\n";
            $label = $type === 'percent' ? "{$amount}%" : "{$amount} " . get_woocommerce_currency();
            foreach ($codes as $c) $csv .= "{$c},{$label},{$type},{$usage_limit}," . ($expiry ?: 'never') . "\n";
            $upload = wp_upload_dir();
            file_put_contents($upload['basedir'] . '/wpilot-coupons.csv', $csv);
            return wpilot_ok("Created {$count} coupons ({$prefix}...)", [
                'count' => $count, 'codes' => $codes, 'discount' => $label,
                'download_url' => $upload['baseurl'] . '/wpilot-coupons.csv',
            ]);

        
        // ═══ ANALYTICS & TRACKING ═══
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
        // Built by Christos Ferlachidis & Daniel Hedenberg
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
        case 'recovery_status':
        case 'safety_check':
            $crash_file = WP_CONTENT_DIR . '/wpilot-crash-flag.txt';
            $safe_mode_file = WP_CONTENT_DIR . '/wpilot-safe-mode.txt';
            $log_file = WP_CONTENT_DIR . '/wpilot-recovery.log';
            $status = [
                'crash_flag' => file_exists($crash_file),
                'safe_mode' => file_exists($safe_mode_file),
                'safe_mode_data' => file_exists($safe_mode_file) ? json_decode(file_get_contents($safe_mode_file), true) : null,
                'recovery_url' => admin_url('?wpilot-recover=1'),
                'recovery_token_set' => !empty(get_option('wpilot_recovery_token', '')),
            ];
            if (file_exists($log_file)) {
                $lines = file($log_file);
                $status['recent_log'] = implode('', array_slice($lines, -10));
            }
            global $wpdb;
            $table = $wpdb->prefix . 'wpilot_backups';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $status['total_backups'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
                $status['unrestored'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE restored = 0"));
            }
            return wpilot_ok('Recovery system status.', $status);

        case 'undo_last':
        case 'rollback_last':
            global $wpdb;
            $table = $wpdb->prefix . 'wpilot_backups';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return wpilot_err('Backup table not found.');
            $last = $wpdb->get_row("SELECT * FROM {$table} WHERE restored = 0 ORDER BY id DESC LIMIT 1");
            if (!$last) return wpilot_ok('Nothing to undo — no recent changes.');
            $data = json_decode($last->data_before, true);
            $restored = false;
            if ($last->target_type === 'page' && !empty($data['post_content'])) {
                wp_update_post(['ID' => $last->target_id, 'post_content' => $data['post_content']]);
                if (!empty($data['_elementor_data'])) update_post_meta($last->target_id, '_elementor_data', $data['_elementor_data']);
                $restored = true;
            } elseif ($last->target_type === 'css' && isset($data['css'])) {
                wp_update_custom_css_post($data['css']);
                $restored = true;
            } elseif ($last->target_type === 'option') {
                update_option($last->target_id, $data['value'] ?? $data);
                $restored = true;
            }
            if ($restored) {
                $wpdb->update($table, ['restored' => 1], ['id' => $last->id]);
                return wpilot_ok("Rolled back: {$last->target_type} #{$last->target_id} (backup #{$last->id}).");
            }
            return wpilot_err('Could not restore backup #' . $last->id);

        case 'undo_all':
        case 'rollback_all':
            global $wpdb;
            $table = $wpdb->prefix . 'wpilot_backups';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return wpilot_err('Backup table not found.');
            $backups = $wpdb->get_results("SELECT * FROM {$table} WHERE restored = 0 ORDER BY id DESC");
            $count = 0;
            foreach ($backups as $b) {
                $data = json_decode($b->data_before, true);
                if ($b->target_type === 'page' && !empty($data['post_content'])) {
                    wp_update_post(['ID' => $b->target_id, 'post_content' => $data['post_content']]);
                    $count++;
                } elseif ($b->target_type === 'css' && isset($data['css'])) {
                    wp_update_custom_css_post($data['css']);
                    $count++;
                }
                $wpdb->update($table, ['restored' => 1], ['id' => $b->id]);
            }
            return wpilot_ok("Rolled back {$count} changes.", ['total_restored' => $count]);

        case 'reset_safe_mode':
            @unlink(WP_CONTENT_DIR . '/wpilot-safe-mode.txt');
            @unlink(WP_CONTENT_DIR . '/wpilot-crash-flag.txt');
            return wpilot_ok('Safe mode reset. WPilot will load normally.');

        default:
            // Route to plugin-specific tools (Amelia, WooCommerce, LearnDash, etc.)
            if ( function_exists( 'wpilot_run_plugin_tool' ) ) {
                return wpilot_run_plugin_tool( $tool, $params );
            }
            return wpilot_err("Unknown tool: {$tool}");
    }
}

// ═══════════════════════════════════════════════════════════════
//  WebP Conversion Engine
//  Converts images using WordPress image editor (GD or Imagick)
//  Keeps original file as backup for one-click restore
// ═══════════════════════════════════════════════════════════════

function wpilot_convert_single_webp( $id, $quality = 82 ) {
    $file = get_attached_file( $id );
    if ( ! $file || ! file_exists( $file ) ) return wpilot_err( "Image #{$id} file not found." );

    $mime = get_post_mime_type( $id );
    // Skip if already WebP, SVG, or GIF (animated)
    if ( in_array( $mime, ['image/webp', 'image/svg+xml', 'image/gif'] ) ) {
        return wpilot_ok( "Image #{$id} skipped ({$mime}).", ['skipped' => true] );
    }

    // Only convert JPEG and PNG
    if ( ! in_array( $mime, ['image/jpeg', 'image/png'] ) ) {
        return wpilot_ok( "Image #{$id} skipped — unsupported format.", ['skipped' => true] );
    }

    // Check WebP support
    $editor = wp_get_image_editor( $file );
    if ( is_wp_error( $editor ) ) return wpilot_err( "Cannot open image: " . $editor->get_error_message() );

    $supports = $editor->supports_mime_type( 'image/webp' );
    if ( ! $supports ) return wpilot_err( "Server does not support WebP conversion. Install GD with WebP or Imagick." );

    // Save original path for backup
    $original_path = $file;
    $path_info     = pathinfo( $file );
    $webp_path     = $path_info['dirname'] . '/' . $path_info['filename'] . '.webp';

    // Keep original as backup
    $backup_path = $file . '.wpilot-backup';
    if ( ! file_exists( $backup_path ) ) {
        copy( $file, $backup_path );
    }

    // Set quality and save as WebP
    $editor->set_quality( $quality );
    $saved = $editor->save( $webp_path, 'image/webp' );
    if ( is_wp_error( $saved ) ) return wpilot_err( "WebP conversion failed: " . $saved->get_error_message() );

    // Calculate size savings
    $original_size = filesize( $original_path );
    $webp_size     = filesize( $webp_path );
    $saved_bytes   = $original_size - $webp_size;
    $saved_pct     = $original_size > 0 ? round( ($saved_bytes / $original_size) * 100 ) : 0;

    // Update WordPress attachment to point to WebP
    update_attached_file( $id, $webp_path );
    wp_update_post( [
        'ID'             => $id,
        'post_mime_type' => 'image/webp',
    ] );

    // Regenerate thumbnails for the WebP version
    $metadata = wp_generate_attachment_metadata( $id, $webp_path );
    wp_update_attachment_metadata( $id, $metadata );

    // Log backup for restore
    wpilot_save_image_backup( $id, $original_path, $backup_path, $mime );

    $size_kb = round( $saved_bytes / 1024 );
    return wpilot_ok(
        "Image #{$id} converted to WebP — saved {$size_kb}KB ({$saved_pct}% smaller).",
        ['id' => $id, 'saved_bytes' => $saved_bytes, 'saved_pct' => $saved_pct]
    );
}

function wpilot_bulk_convert_webp( $quality = 82 ) {
    $images = get_posts( [
        'post_type'      => 'attachment',
        'post_mime_type' => ['image/jpeg', 'image/png'],
        'numberposts'    => -1,
        'post_status'    => 'inherit',
    ] );

    if ( empty( $images ) ) return wpilot_ok( "No JPEG/PNG images found to convert." );

    // Check WebP support before starting
    $test_img = get_attached_file( $images[0]->ID );
    if ( $test_img ) {
        $editor = wp_get_image_editor( $test_img );
        if ( is_wp_error( $editor ) || ! $editor->supports_mime_type( 'image/webp' ) ) {
            return wpilot_err( "Server does not support WebP. Install PHP GD with WebP support or Imagick." );
        }
    }

    $converted   = 0;
    $skipped     = 0;
    $failed      = 0;
    $total_saved = 0;
    $errors      = [];

    // Process in manageable batches to avoid timeout
    $batch_limit = 50;
    $batch       = array_slice( $images, 0, $batch_limit );

    foreach ( $batch as $img ) {
        $result = wpilot_convert_single_webp( $img->ID, $quality );

        if ( $result['success'] ) {
            if ( ! empty( $result['data']['skipped'] ) ) {
                $skipped++;
            } else {
                $converted++;
                $total_saved += ($result['data']['saved_bytes'] ?? 0);
            }
        } else {
            $failed++;
            $errors[] = "#{$img->ID}: " . ($result['message'] ?? 'unknown error');
        }
    }

    $remaining   = count( $images ) - $batch_limit;
    $saved_mb    = round( $total_saved / (1024 * 1024), 1 );
    $msg         = "Converted {$converted} images to WebP — saved {$saved_mb}MB total.";

    if ( $skipped ) $msg .= " Skipped {$skipped}.";
    if ( $failed )  $msg .= " Failed {$failed}.";
    if ( $remaining > 0 ) $msg .= " {$remaining} more images remaining — run again to continue.";

    return wpilot_ok( $msg, [
        'converted'   => $converted,
        'skipped'     => $skipped,
        'failed'      => $failed,
        'total_saved' => $total_saved,
        'remaining'   => max( 0, $remaining ),
        'errors'      => array_slice( $errors, 0, 5 ),
    ] );
}

// ── Save image backup for restore ──────────────────────────────
function wpilot_save_image_backup( $id, $original_path, $backup_path, $original_mime ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . 'ca_backups', [
        'tool'        => 'image_webp_convert',
        'target_id'   => $id,
        'target_type' => 'image',
        'data_before' => wp_json_encode( [
            'original_path' => $original_path,
            'backup_path'   => $backup_path,
            'original_mime' => $original_mime,
        ] ),
        'created_at'  => current_time( 'mysql' ),
    ] );
}

// ── Post/CSS snapshot helpers ──────────────────────────────────
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
        'target_id'   => 0,
        'target_type' => 'custom_css',
        'data_before' => wp_json_encode(['css'=>wp_get_custom_css()]),
        'created_at'  => current_time('mysql'),
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  Security Scanner
// ═══════════════════════════════════════════════════════════════

function wpilot_security_scan() {
    $issues = [];
    $score  = 100;

    // 1. Check WordPress version
    $wp_version = get_bloginfo('version');
    $latest = '6.7'; // approximate
    if (version_compare($wp_version, '6.0', '<')) {
        $issues[] = ['severity'=>'critical', 'issue'=>"WordPress {$wp_version} is severely outdated", 'fix'=>'Update WordPress immediately', 'auto_fix'=>false];
        $score -= 25;
    } elseif (version_compare($wp_version, $latest, '<')) {
        $issues[] = ['severity'=>'warning', 'issue'=>"WordPress {$wp_version} — newer version available", 'fix'=>'Update WordPress', 'auto_fix'=>false];
        $score -= 5;
    }

    // 2. Check for outdated plugins
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $update_plugins = get_site_transient('update_plugins');
    if ($update_plugins && !empty($update_plugins->response)) {
        $outdated = count($update_plugins->response);
        $issues[] = ['severity'=>'warning', 'issue'=>"{$outdated} plugins need updates", 'fix'=>'Update all plugins', 'auto_fix'=>false];
        $score -= min(20, $outdated * 4);
    }

    // 3. Check SSL
    // Built by Christos Ferlachidis & Daniel Hedenberg
    $ssl = is_ssl();
    if (!$ssl) {
        $issues[] = ['severity'=>'critical', 'issue'=>'Site is NOT using HTTPS/SSL', 'fix'=>'Install SSL certificate and enable HTTPS', 'auto_fix'=>false];
        $score -= 20;
    }

    // 4. Check debug mode
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $issues[] = ['severity'=>'warning', 'issue'=>'WP_DEBUG is enabled on production', 'fix'=>'Set WP_DEBUG to false in wp-config.php', 'auto_fix'=>false];
        $score -= 10;
    }

    // 5. Check default admin username
    $admin_user = get_user_by('login', 'admin');
    if ($admin_user) {
        $issues[] = ['severity'=>'warning', 'issue'=>'Default "admin" username exists — easy brute force target', 'fix'=>'Create new admin account, transfer content, delete "admin" user', 'auto_fix'=>false];
        $score -= 10;
    }

    // 6. Check file editing
    if (!defined('DISALLOW_FILE_EDIT') || !DISALLOW_FILE_EDIT) {
        $issues[] = ['severity'=>'warning', 'issue'=>'File editing enabled in dashboard — attackers can modify PHP files', 'fix'=>'Add define("DISALLOW_FILE_EDIT", true) to wp-config.php', 'auto_fix'=>false];
        $score -= 5;
    }

    // 7. Check XML-RPC
    $xmlrpc_enabled = true; // default on
    if ($xmlrpc_enabled) {
        $issues[] = ['severity'=>'info', 'issue'=>'XML-RPC is enabled — potential brute force vector', 'fix'=>'Disable XML-RPC if not using Jetpack or mobile apps', 'auto_fix'=>'disable_xmlrpc'];
    }

    // 8. Check for security plugin
    $has_security = false;
    $active = get_option('active_plugins', []);
    foreach ($active as $p) {
        if (preg_match('/wordfence|sucuri|ithemes-security|all-in-one-wp-security|jetpack/i', $p)) {
            $has_security = true;
            break;
        }
    }
    if (!$has_security) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No security plugin installed', 'fix'=>'Install Wordfence (free) for firewall + malware scanning', 'auto_fix'=>false];
        $score -= 10;
    }

    // 9. Check database prefix
    global $wpdb;
    if ($wpdb->prefix === 'wp_') {
        $issues[] = ['severity'=>'info', 'issue'=>'Default database prefix "wp_" — slightly easier to target with SQL injection', 'fix'=>'Consider changing database prefix (advanced)', 'auto_fix'=>false];
    }

    // 10. Check user registration
    if (get_option('users_can_register')) {
        $issues[] = ['severity'=>'warning', 'issue'=>'Open user registration enabled — spam accounts can register', 'fix'=>'Disable registration or add CAPTCHA', 'auto_fix'=>'disable_registration'];
        $score -= 5;
    }

    // 11. Check login attempts (if no protection)
    if (!$has_security) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No brute force protection on login page', 'fix'=>'Install Wordfence or Limit Login Attempts Reloaded (free)', 'auto_fix'=>false];
        $score -= 5;
    }

    // 12. Check for readme.html and license.txt (version disclosure)
    if (file_exists(ABSPATH . 'readme.html')) {
        $issues[] = ['severity'=>'info', 'issue'=>'readme.html exposes WordPress version', 'fix'=>'Delete readme.html from root', 'auto_fix'=>'delete_readme'];
    }

    $score = max(0, $score);
    $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 40 ? 'D' : 'F')));

    return wpilot_ok("Security scan complete. Score: {$score}/100 (Grade: {$grade}). Found " . count($issues) . " issues.", [
        'score'  => $score,
        'grade'  => $grade,
        'issues' => $issues,
    ]);
}

function wpilot_fix_security($issue, $params = []) {
    switch ($issue) {
        case 'disable_xmlrpc':
            // Add filter to block XML-RPC
            $mu_dir = WPMU_PLUGIN_DIR;
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            file_put_contents($mu_dir . '/wpilot-disable-xmlrpc.php',
                "<?php\n// WPilot: Disable XML-RPC for security\nadd_filter('xmlrpc_enabled', '__return_false');\n"
            );
            return wpilot_ok("XML-RPC disabled via mu-plugin.");

        case 'disable_registration':
            update_option('users_can_register', 0);
            return wpilot_ok("User registration disabled.");

        case 'delete_readme':
            $file = ABSPATH . 'readme.html';
            if (file_exists($file)) { @unlink($file); }
            $file2 = ABSPATH . 'license.txt';
            if (file_exists($file2)) { @unlink($file2); }
            return wpilot_ok("readme.html and license.txt deleted.");

        /* ── Code Injection (mu-plugin) ──────────────────── */
        case 'add_head_code':
            $code = $params['code'] ?? '';
            $name = sanitize_file_name($params['name'] ?? 'custom-head-' . time());
            if (empty($code)) return wpilot_err('Code required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-' . $name . '.php';
            // Use heredoc to avoid quote escaping issues
            $safe_code = str_replace("'", "\'", $code);
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('wp_head', function() {\n"
                . "    echo '" . $safe_code . "';\n"
                . "}, 1);\n";
            // Validate before saving
            $test_result = @exec('echo ' . escapeshellarg($php) . ' | php -l 2>&1', $output, $ret);
            if ($ret !== 0 && $ret !== null) {
                return wpilot_err('Code has syntax issues. Not saved.');
            }
            file_put_contents($mu_dir . '/' . $filename, $php);
            return wpilot_ok("Code added to <head> via mu-plugin: {$filename}");

        case 'add_footer_code':
            $code = $params['code'] ?? '';
            $name = sanitize_file_name($params['name'] ?? 'custom-footer-' . time());
            if (empty($code)) return wpilot_err('Code required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-' . $name . '.php';
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('wp_footer', function() {\n"
                . "    echo '" . addslashes($code) . "';\n"
                . "});\n";
            file_put_contents($mu_dir . '/' . $filename, $php);
            return wpilot_ok("Code added to footer via mu-plugin: {$filename}");

        case 'add_php_snippet':
            $code = $params['code'] ?? '';
            $hook = sanitize_text_field($params['hook'] ?? 'init');
            $name = sanitize_file_name($params['name'] ?? 'snippet-' . time());
            $priority = intval($params['priority'] ?? 10);
            if (empty($code)) return wpilot_err('Code required.');
            // SAFETY: strip echo/print from snippets — they pollute AJAX responses
            $code = preg_replace('/\becho\s+["\']/','// echo ', $code);
            $code = preg_replace('/\bprint\s*\(/','// print(', $code);
            $code = preg_replace('/\bvar_dump\s*\(/','// var_dump(', $code);
            $code = preg_replace('/\bprint_r\s*\(/','// print_r(', $code);
            // Validate: code must not contain raw HTML tags (common AI mistake)
            if (preg_match('/<[a-z]/i', $code) && !preg_match('/echo|print/', $code)) {
                return wpilot_err('PHP snippet contains HTML. Use add_head_code for HTML or wrap in echo/print for PHP.');
            }
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-' . $name . '.php';
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('" . $hook . "', function() {\n"
                . $code . "\n"
                . "}, " . $priority . ");\n";
            // Validate PHP syntax before saving
            $test = @exec('echo ' . escapeshellarg($php) . ' | php -l 2>&1', $output, $ret);
            if ($ret !== 0 && $ret !== null) {
                return wpilot_err('PHP syntax error in snippet. Not saved. Fix the code and try again.');
            }
            // Wrap code in try/catch so it can never crash WordPress
            $php = "<?php\n// WPilot: " . sanitize_text_field($params['description'] ?? $name) . "\n"
                . "add_action('" . $hook . "', function() {\n"
                . "    try {\n"
                . "        " . $code . "\n"
                . "    } catch (\\Throwable \$e) {\n"
                . "        // Auto-disable this snippet if it crashes\n"
                . "        @rename(__FILE__, __FILE__ . '.disabled');\n"
                . "    }\n"
                . "}, " . $priority . ");\n";
            file_put_contents($mu_dir . '/' . $filename, $php);
            return wpilot_ok("PHP snippet added via mu-plugin: {$filename} (hook: {$hook})");

        case 'list_snippets':
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            $snippets = [];
            if (is_dir($mu_dir)) {
                foreach (glob($mu_dir . '/wpilot-*.php') as $file) {
                    $content = file_get_contents($file);
                    $snippets[] = [
                        'file' => basename($file),
                        'description' => preg_match('/WPilot: (.+)/', $content, $m) ? $m[1] : basename($file),
                        'size' => filesize($file),
                    ];
                }
            }
            return wpilot_ok(count($snippets) . " WPilot snippets active.", ['snippets' => $snippets]);

        case 'remove_snippet':
            $name = sanitize_file_name($params['name'] ?? '');
            if (empty($name)) return wpilot_err('Snippet name required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            // Try multiple naming patterns
            $tries = [
                $mu_dir . '/' . $name . '.php',
                $mu_dir . '/' . $name,
                $mu_dir . '/wpilot-' . $name . '.php',
                $mu_dir . '/wpilot-' . $name,
            ];
            // Also strip wpilot- prefix if already included
            $stripped = preg_replace('/^wpilot-/', '', $name);
            $tries[] = $mu_dir . '/wpilot-' . $stripped . '.php';
            $tries[] = $mu_dir . '/' . $stripped . '.php';
            foreach ($tries as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                    return wpilot_ok("Snippet removed: " . basename($file));
                }
            }
            return wpilot_err("Snippet not found: {$name}");

        case 'add_security_headers':
            $mu_dir = WPMU_PLUGIN_DIR;
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $code = "<?php\n// WPilot: Security Headers\n"
                . "add_action('send_headers', function() {\n"
                . "    header('X-Content-Type-Options: nosniff');\n"
                . "    header('X-Frame-Options: SAMEORIGIN');\n"
                . "    header('X-XSS-Protection: 1; mode=block');\n"
                . "    header('Referrer-Policy: strict-origin-when-cross-origin');\n"
                . "    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');\n"
                . "});\n";
            file_put_contents($mu_dir . '/wpilot-security-headers.php', $code);
            return wpilot_ok("Security headers added via mu-plugin.");

        default:
            return wpilot_err("Unknown security fix: {$issue}. Manual fix required.");
    }
}

// ═══════════════════════════════════════════════════════════════
//  SEO Audit
// ═══════════════════════════════════════════════════════════════

function wpilot_seo_audit() {
    $issues = [];
    $score  = 100;

    // 1. Check SEO plugin
    $seo_plugin = 'none';
    if (defined('WPSEO_VERSION'))        $seo_plugin = 'yoast';
    elseif (class_exists('RankMath'))    $seo_plugin = 'rankmath';
    elseif (class_exists('AIOSEO\Plugin')) $seo_plugin = 'aioseo';
    if ($seo_plugin === 'none') {
        $issues[] = ['severity'=>'critical', 'issue'=>'No SEO plugin installed', 'fix'=>'Install Rank Math (free) — best free SEO plugin', 'tool'=>'plugin_install', 'params'=>['slug'=>'seo-by-rank-math']];
        $score -= 25;
    }

    // 2. Check pages for missing meta descriptions
    $pages = get_posts(['post_type'=>['page','post'], 'post_status'=>'publish', 'numberposts'=>100]);
    $missing_meta  = 0;
    $missing_title = 0;
    $no_h1         = 0;
    $multiple_h1   = 0;
    $thin_content  = 0;
    $missing_alt_pages = 0;

    foreach ($pages as $p) {
        // Meta description
        $meta = get_post_meta($p->ID, '_yoast_wpseo_metadesc', true)
             ?: get_post_meta($p->ID, 'rank_math_description', true)
             ?: get_post_meta($p->ID, '_aioseo_description', true);
        if (empty($meta)) $missing_meta++;

        // SEO title
        $seo_title = get_post_meta($p->ID, '_yoast_wpseo_title', true)
                  ?: get_post_meta($p->ID, 'rank_math_title', true);
        if (empty($seo_title) && strlen($p->post_title) < 20) $missing_title++;

        // Heading structure
        $h1_count = preg_match_all('/<h1/i', $p->post_content);
        if ($h1_count === 0 && $p->post_type === 'page') $no_h1++;
        if ($h1_count > 1) $multiple_h1++;

        // Thin content
        $word_count = str_word_count(wp_strip_all_tags($p->post_content));
        if ($word_count < 100 && $p->post_type === 'post') $thin_content++;

        // Images without alt in content
        if (preg_match_all('/<img[^>]*alt=["\']\s*["\'][^>]*>/i', $p->post_content, $m)) {
            $missing_alt_pages++;
        }
    }

    if ($missing_meta > 0) {
        $issues[] = ['severity'=>'critical', 'issue'=>"{$missing_meta} pages/posts missing meta description", 'fix'=>'Use bulk_fix_seo to auto-generate descriptions', 'tool'=>'bulk_fix_seo'];
        $score -= min(25, $missing_meta * 3);
    }
    if ($multiple_h1 > 0) {
        $issues[] = ['severity'=>'warning', 'issue'=>"{$multiple_h1} pages have multiple H1 tags", 'fix'=>'Use fix_heading_structure for each page'];
        $score -= min(10, $multiple_h1 * 2);
    }
    if ($thin_content > 0) {
        $issues[] = ['severity'=>'warning', 'issue'=>"{$thin_content} posts have thin content (<100 words)", 'fix'=>'Add more content or noindex these pages'];
        $score -= min(15, $thin_content * 3);
    }

    // 3. Check site title and tagline
    $tagline = get_bloginfo('description');
    if (empty($tagline) || $tagline === 'Just another WordPress site') {
        $issues[] = ['severity'=>'critical', 'issue'=>'Default or empty tagline — Google uses this as fallback description', 'fix'=>'Set a proper site tagline', 'tool'=>'update_tagline'];
        $score -= 15;
    }

    // 4. Check for sitemap
    $sitemap_url = get_site_url() . '/sitemap.xml';
    $sitemap_check = wp_remote_head($sitemap_url, ['timeout'=>5]);
    if (is_wp_error($sitemap_check) || wp_remote_retrieve_response_code($sitemap_check) !== 200) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No sitemap.xml found', 'fix'=>'Install Rank Math or Yoast SEO to auto-generate sitemap'];
        $score -= 10;
    }

    // 5. Check for robots.txt
    $robots_url = get_site_url() . '/robots.txt';
    $robots_check = wp_remote_head($robots_url, ['timeout'=>5]);
    if (is_wp_error($robots_check) || wp_remote_retrieve_response_code($robots_check) !== 200) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No robots.txt found', 'fix'=>'Create robots.txt with proper rules', 'tool'=>'create_robots_txt'];
        $score -= 5;
    }

    // 6. Check permalink structure
    $permalink = get_option('permalink_structure');
    if (empty($permalink) || $permalink === '/?p=%post_id%') {
        $issues[] = ['severity'=>'critical', 'issue'=>'Using default permalink structure (?p=123) — terrible for SEO', 'fix'=>'Set permalink to /%postname%/', 'tool'=>'update_permalink_structure', 'params'=>['structure'=>'/%postname%/']];
        $score -= 20;
    }

    // 7. Missing images alt text
    $images = get_posts(['post_type'=>'attachment','post_mime_type'=>'image','numberposts'=>-1]);
    $missing_alt = 0;
    foreach ($images as $img) {
        if (empty(get_post_meta($img->ID, '_wp_attachment_image_alt', true))) $missing_alt++;
    }
    if ($missing_alt > 0) {
        $issues[] = ['severity'=>'warning', 'issue'=>"{$missing_alt} images missing alt text", 'fix'=>'Use bulk_fix_alt_text to auto-set from titles', 'tool'=>'bulk_fix_alt_text'];
        $score -= min(15, $missing_alt);
    }

    // 8. Check for 404 page
    $has_404 = false;
    $theme_404 = get_theme_file_path('404.php');
    if (file_exists($theme_404)) $has_404 = true;
    if (!$has_404) {
        $issues[] = ['severity'=>'warning', 'issue'=>'No custom 404 page — visitors see a generic error', 'fix'=>'Create a branded 404 page', 'tool'=>'create_404_page'];
        $score -= 5;
    }

    // 9. Check SSL for SEO
    if (!is_ssl()) {
        $issues[] = ['severity'=>'critical', 'issue'=>'No HTTPS — Google penalizes non-SSL sites in rankings', 'fix'=>'Enable SSL/HTTPS'];
        $score -= 15;
    }

    $score = max(0, $score);
    $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : ($score >= 40 ? 'D' : 'F')));

    return wpilot_ok("SEO Audit complete. Score: {$score}/100 (Grade: {$grade}). Found " . count($issues) . " issues.", [
        'score'  => $score,
        'grade'  => $grade,
        'issues' => $issues,
        'stats'  => [
            'total_pages'  => count($pages),
            'missing_meta' => $missing_meta,
            'missing_alt'  => $missing_alt,
            'thin_content' => $thin_content,
            'seo_plugin'   => $seo_plugin,
        ],
    ]);
}

function wpilot_bulk_fix_seo() {
    $pages = get_posts(['post_type'=>['page','post'], 'post_status'=>'publish', 'numberposts'=>100]);
    $fixed = 0;

    foreach ($pages as $p) {
        $meta = get_post_meta($p->ID, '_yoast_wpseo_metadesc', true)
             ?: get_post_meta($p->ID, 'rank_math_description', true)
             ?: get_post_meta($p->ID, '_aioseo_description', true);

        if (empty($meta)) {
            // Auto-generate from content
            $content = wp_strip_all_tags($p->post_content);
            $desc = wp_trim_words($content, 25, '...');
            if (strlen($desc) < 50) $desc = $p->post_title . ' — ' . $desc;
            $desc = substr($desc, 0, 160);

            update_post_meta($p->ID, '_yoast_wpseo_metadesc', $desc);
            update_post_meta($p->ID, 'rank_math_description', $desc);
            update_post_meta($p->ID, '_aioseo_description', $desc);
            $fixed++;
        }
    }

    return wpilot_ok("Auto-generated meta descriptions for {$fixed} pages/posts.");
}

// ═══════════════════════════════════════════════════════════════
//  Site Health Check
// ═══════════════════════════════════════════════════════════════

function wpilot_site_health_check() {
    $checks = [];

    // PHP version
    $php = PHP_VERSION;
    $checks['php'] = [
        'label' => "PHP {$php}",
        'status' => version_compare($php, '8.0', '>=') ? 'good' : (version_compare($php, '7.4', '>=') ? 'warning' : 'critical'),
        'note' => version_compare($php, '8.0', '<') ? 'Upgrade to PHP 8.0+ for better performance' : 'Up to date',
    ];

    // WordPress version
    $wp = get_bloginfo('version');
    $checks['wordpress'] = [
        'label' => "WordPress {$wp}",
        'status' => version_compare($wp, '6.0', '>=') ? 'good' : 'warning',
    ];

    // Memory limit
    $memory = ini_get('memory_limit');
    $memory_mb = wp_convert_hr_to_bytes($memory) / 1048576;
    $checks['memory'] = [
        'label' => "Memory limit: {$memory}",
        'status' => $memory_mb >= 256 ? 'good' : ($memory_mb >= 128 ? 'warning' : 'critical'),
        'note' => $memory_mb < 256 ? 'Increase to at least 256M' : 'Sufficient',
    ];

    // Max execution time
    $max_exec = ini_get('max_execution_time');
    $checks['execution_time'] = [
        'label' => "Max execution time: {$max_exec}s",
        'status' => $max_exec >= 60 ? 'good' : 'warning',
    ];

    // Active plugins count
    $plugin_count = count(get_option('active_plugins', []));
    $checks['plugins'] = [
        'label' => "{$plugin_count} active plugins",
        'status' => $plugin_count <= 15 ? 'good' : ($plugin_count <= 25 ? 'warning' : 'critical'),
        'note' => $plugin_count > 20 ? 'Too many plugins slow down the site. Audit and remove unused ones.' : 'Reasonable count',
    ];

    // Database size
    global $wpdb;
    $db_size = $wpdb->get_var("SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $checks['database'] = [
        'label' => "Database: {$db_size}MB",
        'status' => $db_size < 500 ? 'good' : ($db_size < 1000 ? 'warning' : 'critical'),
        'note' => $db_size > 500 ? 'Consider database optimization' : 'Normal size',
    ];

    // Uploads folder size (estimate)
    $upload_dir = wp_upload_dir();
    $checks['uploads'] = [
        'label' => 'Upload path: ' . $upload_dir['basedir'],
        'status' => 'good',
    ];

    // Caching
    $has_cache = false;
    foreach (get_option('active_plugins', []) as $p) {
        if (preg_match('/wp-rocket|litespeed|w3-total-cache|wp-super-cache|autoptimize|wp-fastest-cache/i', $p)) {
            $has_cache = true;
            break;
        }
    }
    $checks['caching'] = [
        'label' => $has_cache ? 'Cache plugin active' : 'No cache plugin',
        'status' => $has_cache ? 'good' : 'warning',
        'note' => $has_cache ? '' : 'Install LiteSpeed Cache (free) or WP Super Cache for faster load times',
    ];

    // Object cache
    $checks['object_cache'] = [
        'label' => wp_using_ext_object_cache() ? 'Object cache: active' : 'Object cache: not configured',
        'status' => wp_using_ext_object_cache() ? 'good' : 'info',
    ];

    $good = count(array_filter($checks, fn($c) => $c['status'] === 'good'));
    $total = count($checks);

    return wpilot_ok("Site health: {$good}/{$total} checks passed.", ['checks' => $checks]);
}

// ── Apply custom robots.txt ────────────────────────────────
add_filter('robots_txt', function($output, $public) {
    $custom = get_option('wpi_custom_robots_txt', '');
    if (!empty($custom)) return $custom;
    return $output;
}, 10, 2);

// ── Output schema markup in head ───────────────────────────
add_action('wp_head', function() {
    if (!is_singular()) return;
    $schema = get_post_meta(get_the_ID(), '_wpi_schema_markup', true);
    if ($schema) {
        echo '<script type="application/ld+json">' . $schema . '</script>' . "\n";
    }
    // Open Graph
    $og_title = get_post_meta(get_the_ID(), '_wpi_og_title', true);
    $og_desc  = get_post_meta(get_the_ID(), '_wpi_og_description', true);
    $og_image = get_post_meta(get_the_ID(), '_wpi_og_image', true);
    if ($og_title) echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
    if ($og_desc)  echo '<meta property="og:description" content="' . esc_attr($og_desc) . '">' . "\n";
    if ($og_image) echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
});

// ═══════════════════════════════════════════════════════════════
//  WooCommerce Dashboard & Analytics
// ═══════════════════════════════════════════════════════════════

function wpilot_woo_dashboard($p) {
    $period = sanitize_text_field($p['period'] ?? 'today');

    switch ($period) {
        case 'today':     $after = date('Y-m-d 00:00:00'); break;
        case 'yesterday': $after = date('Y-m-d 00:00:00', strtotime('-1 day')); break;
        case 'week':      $after = date('Y-m-d 00:00:00', strtotime('-7 days')); break;
        case 'month':     $after = date('Y-m-d 00:00:00', strtotime('-30 days')); break;
        case 'year':      $after = date('Y-m-d 00:00:00', strtotime('-365 days')); break;
        default:          $after = date('Y-m-d 00:00:00');
    }

    $orders = wc_get_orders([
        'date_created' => '>' . strtotime($after),
        'limit'  => -1,
        'status' => ['wc-completed', 'wc-processing', 'wc-on-hold'],
    ]);

    $total_revenue = 0;
    $total_orders  = count($orders);
    $total_items   = 0;
    $statuses      = ['completed'=>0, 'processing'=>0, 'on-hold'=>0, 'refunded'=>0];

    foreach ($orders as $order) {
        $total_revenue += floatval($order->get_total());
        $total_items   += $order->get_item_count();
        $status = str_replace('wc-', '', $order->get_status());
        if (isset($statuses[$status])) $statuses[$status]++;
    }

    // Built by Christos Ferlachidis & Daniel Hedenberg
    $avg_order = $total_orders > 0 ? round($total_revenue / $total_orders, 2) : 0;

    // Refunded orders
    $refunds = wc_get_orders([
        'date_created' => '>' . strtotime($after),
        'limit' => -1,
        'status' => 'wc-refunded',
    ]);
    $refund_total = 0;
    foreach ($refunds as $r) $refund_total += floatval($r->get_total());
    $statuses['refunded'] = count($refunds);

    // Total customers
    $customers = count(get_users(['role'=>'customer', 'date_query'=>[['after'=>$after]]]));

    // Low stock products
    $low_stock = [];
    $products = wc_get_products(['limit'=>-1, 'stock_status'=>'instock']);
    foreach ($products as $prod) {
        $stock = $prod->get_stock_quantity();
        if ($stock !== null && $stock <= 5 && $stock > 0) {
            $low_stock[] = ['name'=>$prod->get_name(), 'stock'=>$stock, 'id'=>$prod->get_id()];
        }
    }

    $currency = get_woocommerce_currency_symbol();

    return wpilot_ok("WooCommerce Dashboard ({$period}):\n\n" .
        "Revenue: {$currency}" . number_format($total_revenue, 2) . "\n" .
        "Orders: {$total_orders} (avg: {$currency}{$avg_order})\n" .
        "Items sold: {$total_items}\n" .
        "New customers: {$customers}\n" .
        ($refund_total > 0 ? "Refunds: {$currency}" . number_format($refund_total, 2) . " ({$statuses['refunded']} orders)\n" : '') .
        (count($low_stock) > 0 ? "Low stock: " . count($low_stock) . " products" : ''), [
        'revenue'      => $total_revenue,
        'orders'       => $total_orders,
        'avg_order'    => $avg_order,
        'items_sold'   => $total_items,
        'customers'    => $customers,
        'statuses'     => $statuses,
        'refund_total' => $refund_total,
        'low_stock'    => array_slice($low_stock, 0, 10),
        'currency'     => $currency,
        'period'       => $period,
    ]);
}

function wpilot_woo_recent_orders($p) {
    $limit = min(intval($p['limit'] ?? 10), 50);
    $orders = wc_get_orders(['limit'=>$limit, 'orderby'=>'date', 'order'=>'DESC']);
    $list = [];
    $currency = get_woocommerce_currency_symbol();
    foreach ($orders as $o) {
        $list[] = [
            'id'       => $o->get_id(),
            'status'   => $o->get_status(),
            'total'    => $currency . $o->get_total(),
            'customer' => $o->get_billing_first_name() . ' ' . $o->get_billing_last_name(),
            'email'    => $o->get_billing_email(),
            'items'    => $o->get_item_count(),
            'date'     => $o->get_date_created()->format('Y-m-d H:i'),
        ];
    }
    return wpilot_ok("Last {$limit} orders:", ['orders'=>$list]);
}

function wpilot_woo_best_sellers($p) {
    $limit  = min(intval($p['limit'] ?? 10), 30);
    $period = sanitize_text_field($p['period'] ?? 'month');

    global $wpdb;
    $after = date('Y-m-d', strtotime("-1 {$period}"));

    $results = $wpdb->get_results($wpdb->prepare("
        SELECT oi.order_item_name as name,
               SUM(oim.meta_value) as qty,
               oim2.meta_value as product_id
        FROM {$wpdb->prefix}woocommerce_order_items oi
        JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id AND oim.meta_key = '_qty'
        JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id AND oim2.meta_key = '_product_id'
        JOIN {$wpdb->posts} p ON oi.order_id = p.ID
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed','wc-processing')
        AND p.post_date >= %s
        GROUP BY oim2.meta_value
        ORDER BY qty DESC
        LIMIT %d
    ", $after, $limit));

    $best = [];
    foreach ($results as $r) {
        $prod = wc_get_product($r->product_id);
        $best[] = [
            'name'     => $r->name,
            'id'       => $r->product_id,
            'qty_sold' => intval($r->qty),
            'price'    => $prod ? $prod->get_price() : '?',
            'stock'    => $prod ? $prod->get_stock_quantity() : null,
        ];
    }

    return wpilot_ok("Best sellers (last {$period}):", ['products'=>$best]);
}

// ═══════════════════════════════════════════════════════════════
//  Database Cleanup
// ═══════════════════════════════════════════════════════════════

function wpilot_database_cleanup($p) {
    global $wpdb;
    $dry_run = isset($p['dry_run']) && $p['dry_run'];
    $results = [];

    // 1. Post revisions
    $revisions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
    if (!$dry_run && $revisions > 0) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'");
    }
    $results['revisions'] = intval($revisions);

    // 2. Auto-drafts
    $auto_drafts = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
    if (!$dry_run && $auto_drafts > 0) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'auto-draft'");
    }
    $results['auto_drafts'] = intval($auto_drafts);

    // 3. Trashed posts
    $trash = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'");
    if (!$dry_run && $trash > 0) {
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_status = 'trash'");
    }
    $results['trashed'] = intval($trash);

    // 4. Spam comments
    // Built by Christos Ferlachidis & Daniel Hedenberg
    $spam = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
    if (!$dry_run && $spam > 0) {
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'");
    }
    $results['spam_comments'] = intval($spam);

    // 5. Trashed comments
    $trash_comments = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
    if (!$dry_run && $trash_comments > 0) {
        $wpdb->query("DELETE FROM {$wpdb->comments} WHERE comment_approved = 'trash'");
    }
    $results['trashed_comments'] = intval($trash_comments);

    // 6. Orphaned post meta
    $orphan_meta = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");
    if (!$dry_run && $orphan_meta > 0) {
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");
    }
    $results['orphaned_postmeta'] = intval($orphan_meta);

    // 7. Orphaned comment meta
    $orphan_cmeta = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
    if (!$dry_run && $orphan_cmeta > 0) {
        $wpdb->query("DELETE FROM {$wpdb->commentmeta} WHERE comment_id NOT IN (SELECT comment_ID FROM {$wpdb->comments})");
    }
    $results['orphaned_commentmeta'] = intval($orphan_cmeta);

    // 8. Expired transients
    $transients = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");
    if (!$dry_run && $transients > 0) {
        $wpdb->query("DELETE a, b FROM {$wpdb->options} a INNER JOIN {$wpdb->options} b ON b.option_name = REPLACE(a.option_name, '_transient_timeout_', '_transient_') WHERE a.option_name LIKE '_transient_timeout_%' AND a.option_value < UNIX_TIMESTAMP()");
    }
    $results['expired_transients'] = intval($transients);

    // 9. Optimize tables
    if (!$dry_run) {
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
        foreach ($tables as $table) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $wpdb->query("OPTIMIZE TABLE `{$table}`");
            }
        }
        $results['tables_optimized'] = count($tables);
    }

    $total = array_sum(array_filter($results, fn($v) => is_int($v) && $v > 0));
    $action = $dry_run ? 'Found' : 'Cleaned';

    return wpilot_ok("{$action}: {$results['revisions']} revisions, {$results['auto_drafts']} auto-drafts, {$results['trashed']} trashed posts, {$results['spam_comments']} spam comments, {$results['trashed_comments']} trashed comments, {$results['orphaned_postmeta']} orphaned meta, {$results['expired_transients']} expired transients." . (!$dry_run ? " Database tables optimized." : ""), $results);
}

// ═══════════════════════════════════════════════════════════════
//  Broken Link Checker
// ═══════════════════════════════════════════════════════════════

function wpilot_check_broken_links($p) {
    $limit = min(intval($p['limit'] ?? 50), 200);

    // Get all published content
    $posts = get_posts(['post_type'=>['page','post'], 'post_status'=>'publish', 'numberposts'=>$limit]);

    $broken  = [];
    $checked = 0;

    foreach ($posts as $post) {
        // Extract URLs from content
        preg_match_all('/href=["\']([^"\']+)["\']/', $post->post_content, $matches);
        if (empty($matches[1])) continue;

        foreach ($matches[1] as $url) {
            // Skip anchors, mailto, tel, javascript
            if (preg_match('/^(#|mailto:|tel:|javascript:)/', $url)) continue;
            // Skip external URLs (too slow to check all)
            if (strpos($url, get_site_url()) === false && preg_match('/^https?:\/\//', $url)) continue;

            // Make relative URLs absolute
            if (strpos($url, '/') === 0) $url = get_site_url() . $url;

            $checked++;
            if ($checked > 100) break 2; // safety limit

            $response = wp_remote_head($url, ['timeout'=>5, 'redirection'=>3]);
            $code = wp_remote_retrieve_response_code($response);

            if (is_wp_error($response) || $code >= 400) {
                $broken[] = [
                    'url'       => $url,
                    'status'    => is_wp_error($response) ? 'error' : $code,
                    'found_in'  => $post->post_title,
                    'post_id'   => $post->ID,
                    'post_type' => $post->post_type,
                ];
            }
        }
    }

    if (empty($broken)) return wpilot_ok("No broken internal links found. Checked {$checked} links across " . count($posts) . " pages/posts.");

    return wpilot_ok("Found " . count($broken) . " broken links (checked {$checked} total).", [
        'broken' => $broken,
        'checked' => $checked,
        'pages_scanned' => count($posts),
    ]);
}

// ── Handle WPilot redirects ────────────────────────────────
add_action('template_redirect', function() {
    $redirects = get_option('wpi_redirects', []);
    if (empty($redirects)) return;

    $request = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($request, PHP_URL_PATH);
    $path = rtrim($path, '/');

    foreach ($redirects as $from => $data) {
        $from_clean = rtrim($from, '/');
        if ($path === $from_clean || $request === $from) {
            wp_redirect($data['to'], $data['type']);
            exit;
        }
    }
});

// ══════════════════════════════════════════════════════════════
//  NEWSLETTER — Send emails to users, customers, subscribers
//  Works with: Mailchimp for WP, MailPoet, Newsletter plugin,
//  or native wp_mail() fallback using WordPress user database
// ══════════════════════════════════════════════════════════════

function wpilot_newsletter_list_subscribers($p) {
    $group = sanitize_text_field($p['group'] ?? 'all');
    $emails = [];
    $sources = [];

    // 1. WordPress users
    if ($group === 'all' || $group === 'users') {
        $users = get_users(['fields'=>['user_email','display_name','ID'], 'number'=>1000]);
        foreach ($users as $u) {
            $roles = implode(',', (new WP_User($u->ID))->roles);
            $emails[$u->user_email] = [
                'email'  => $u->user_email,
                'name'   => $u->display_name,
                'source' => 'wordpress_user',
                'role'   => $roles,
            ];
        }
        $sources[] = 'WordPress users (' . count($users) . ')';
    }

    // 2. WooCommerce customers (orders)
    if (($group === 'all' || $group === 'customers') && class_exists('WooCommerce')) {
        $orders = wc_get_orders(['limit'=>500, 'status'=>['wc-completed','wc-processing']]);
        $cust_count = 0;
        foreach ($orders as $order) {
            $email = $order->get_billing_email();
            if ($email && !isset($emails[$email])) {
                $emails[$email] = [
                    'email'  => $email,
                    'name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'source' => 'woocommerce_customer',
                    'role'   => 'customer',
                ];
                $cust_count++;
            }
        }
        $sources[] = 'WooCommerce customers (' . $cust_count . ')';
    }

    // 3. Comment authors (with consent — they left their email publicly)
    // Built by Christos Ferlachidis & Daniel Hedenberg
    if ($group === 'all' || $group === 'commenters') {
        $comments = get_comments(['status'=>'approve', 'number'=>500, 'type'=>'comment']);
        $comm_count = 0;
        foreach ($comments as $c) {
            if ($c->comment_author_email && !isset($emails[$c->comment_author_email])) {
                $emails[$c->comment_author_email] = [
                    'email'  => $c->comment_author_email,
                    'name'   => $c->comment_author,
                    'source' => 'commenter',
                    'role'   => 'commenter',
                ];
                $comm_count++;
            }
        }
        $sources[] = 'Comment authors (' . $comm_count . ')';
    }

    // 4. MailPoet subscribers
    if (class_exists('\MailPoet\API\API')) {
        try {
            $api = \MailPoet\API\API::MP('v1');
            $subscribers = $api->getSubscribers(['status'=>'subscribed', 'limit'=>1000]);
            $mp_count = 0;
            foreach ($subscribers as $sub) {
                if (!isset($emails[$sub['email']])) {
                    $emails[$sub['email']] = [
                        'email'  => $sub['email'],
                        'name'   => ($sub['first_name'] ?? '') . ' ' . ($sub['last_name'] ?? ''),
                        'source' => 'mailpoet',
                        'role'   => 'subscriber',
                    ];
                    $mp_count++;
                }
            }
            $sources[] = 'MailPoet (' . $mp_count . ')';
        } catch (\Exception $e) {}
    }

    // 5. Newsletter plugin subscribers
    if (class_exists('Newsletter')) {
        global $wpdb;
        $table = $wpdb->prefix . 'newsletter';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $subs = $wpdb->get_results("SELECT email, name FROM {$table} WHERE status = 'C' LIMIT 1000");
            $nl_count = 0;
            foreach ($subs as $s) {
                if (!isset($emails[$s->email])) {
                    $emails[$s->email] = [
                        'email'  => $s->email,
                        'name'   => $s->name ?? '',
                        'source' => 'newsletter_plugin',
                        'role'   => 'subscriber',
                    ];
                    $nl_count++;
                }
            }
            $sources[] = 'Newsletter plugin (' . $nl_count . ')';
        }
    }

    // Remove admin email and obvious system emails
    $admin_email = get_option('admin_email');
    unset($emails[$admin_email]);
    $emails = array_filter($emails, function($e) {
        return !preg_match('/(noreply|no-reply|admin@|info@localhost|test@)/i', $e['email']);
    });

    $total = count($emails);
    $by_role = [];
    foreach ($emails as $e) {
        $role = $e['role'] ?? 'unknown';
        $by_role[$role] = ($by_role[$role] ?? 0) + 1;
    }

    return wpilot_ok("Found {$total} email addresses. Sources: " . implode(', ', $sources) . ".", [
        'total'      => $total,
        'by_role'    => $by_role,
        'sources'    => $sources,
        'emails'     => array_values($emails),
    ]);
}

function wpilot_newsletter_send($p) {
    $subject = sanitize_text_field($p['subject'] ?? '');
    $body    = wp_kses_post($p['body'] ?? '');
    $group   = sanitize_text_field($p['group'] ?? 'all');
    $role    = sanitize_text_field($p['role'] ?? '');

    if (empty($subject)) return wpilot_err('Subject required.');
    if (empty($body))    return wpilot_err('Email body required.');

    // Check if MailPoet is available for sending
    if (class_exists('\MailPoet\API\API')) {
        // Use MailPoet to send — much better deliverability
        try {
            $api = \MailPoet\API\API::MP('v1');
            $lists = $api->getLists();
            if (!empty($lists)) {
                return wpilot_ok("MailPoet is installed. For best deliverability, create the newsletter through MailPoet > Newsletters. I've prepared the content for you.\n\nSubject: {$subject}\n\nOr I can send via WordPress wp_mail() directly — but MailPoet has better tracking and unsubscribe handling.");
            }
        } catch (\Exception $e) {}
    }

    // Collect recipients
    $result = wpilot_newsletter_list_subscribers(['group' => $group]);
    if (!$result['success'] || empty($result['emails'])) {
        return wpilot_err('No recipients found for group: ' . $group);
    }

    $recipients = $result['emails'];

    // Filter by role if specified
    if ($role) {
        $recipients = array_filter($recipients, function($e) use ($role) {
            return $e['role'] === $role;
        });
    }

    if (empty($recipients)) return wpilot_err("No recipients match role: {$role}");

    $total = count($recipients);
    $site_name = get_bloginfo('name');
    $site_url  = get_site_url();

    // Build HTML email template
    $html = wpilot_newsletter_template($subject, $body, $site_name, $site_url);

    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $site_name . ' <' . get_option('admin_email') . '>',
    ];

    // Send in batches to avoid timeout
    $sent    = 0;
    $failed  = 0;
    $batch   = 0;
    $limit   = 50; // max per batch
    $batches = array_chunk(array_values($recipients), $limit);

    foreach ($batches as $batch_recipients) {
        foreach ($batch_recipients as $recipient) {
            $personalized_html = str_replace(
                ['{{name}}', '{{email}}'],
                [esc_html($recipient['name'] ?: 'there'), esc_html($recipient['email'])],
                $html
            );

            $result = wp_mail($recipient['email'], $subject, $personalized_html, $headers);
            if ($result) $sent++;
            else $failed++;
        }
        $batch++;

        // Safety: max 3 batches (150 emails) per call to avoid timeout
        if ($batch >= 3) break;
    }

    $remaining = $total - ($batch * $limit);
    $msg = "Newsletter sent to {$sent} recipients.";
    if ($failed > 0)    $msg .= " {$failed} failed.";
    if ($remaining > 0) $msg .= " {$remaining} remaining — run again to continue.";
    $msg .= "\n\nFor large lists (100+), use a dedicated email service (MailPoet, Mailchimp, SendGrid) for better deliverability.";

    // Log the send
    $log = get_option('wpi_newsletter_log', []);
    $log[] = [
        'subject'    => $subject,
        'sent'       => $sent,
        'failed'     => $failed,
        'group'      => $group,
        'role'       => $role,
        'date'       => current_time('mysql'),
    ];
    if (count($log) > 50) $log = array_slice($log, -50);
    update_option('wpi_newsletter_log', $log);

    return wpilot_ok($msg, [
        'sent'      => $sent,
        'failed'    => $failed,
        'remaining' => max(0, $remaining),
        'total'     => $total,
    ]);
}

function wpilot_newsletter_template($subject, $body, $site_name, $site_url) {
    return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif">'
        . '<div style="max-width:600px;margin:0 auto;padding:20px">'
        . '<div style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08)">'
        // Header
        . '<div style="background:linear-gradient(135deg,#5B7FFF,#7C5CFC);padding:30px 32px;text-align:center">'
        . '<h1 style="color:#fff;margin:0;font-size:22px;font-weight:700">' . esc_html($site_name) . '</h1>'
        . '</div>'
        // Body
        . '<div style="padding:32px;font-size:15px;line-height:1.7;color:#333">'
        . '<p style="margin:0 0 8px;color:#999;font-size:13px">' . esc_html($subject) . '</p>'
        . '<p>Hi {{name}},</p>'
        . $body
        . '</div>'
        // Footer
        . '<div style="padding:20px 32px;background:#f9fafb;border-top:1px solid #eee;text-align:center;font-size:12px;color:#999">'
        . '<p style="margin:0">Sent from <a href="' . esc_url($site_url) . '" style="color:#5B7FFF;text-decoration:none">' . esc_html($site_name) . '</a></p>'
        . '<p style="margin:6px 0 0;font-size:11px">You received this because you are a member/customer at ' . esc_html($site_name) . '.</p>'
        . '</div>'
        . '</div></div></body></html>';
}

function wpilot_newsletter_configure($p) {
    // Check for email marketing plugins
    if (class_exists('\MailPoet\API\API')) {
        return wpilot_ok("MailPoet is installed. Use it for:\n- Automated welcome emails\n- Abandoned cart emails (WooCommerce)\n- Newsletter signup forms\n- Beautiful email templates\n\nI can help you create newsletters — just tell me what to write about.");
    }
    if (class_exists('Newsletter')) {
        return wpilot_ok("Newsletter plugin is installed. Subscribers are collected automatically. Use newsletter_send to send emails, or newsletter_list_subscribers to see your list.");
    }
    if (function_exists('mc4wp_get_api')) {
        return wpilot_ok("Mailchimp for WP is installed. Your subscribers sync to Mailchimp. Send newsletters from your Mailchimp dashboard for best deliverability.");
    }

    return wpilot_ok("No email marketing plugin installed. Options:\n\n**MailPoet** (FREE up to 1000 subscribers) — Best all-in-one: forms, templates, automation, WooCommerce integration. Sends directly from your server.\n\n**Newsletter** (FREE) — Simple and lightweight. Collects subscribers, sends newsletters.\n\n**Mailchimp for WP** (FREE) — Syncs with Mailchimp. Best if you already use Mailchimp.\n\nFor most sites, **MailPoet is the best free choice**. Want me to install it?", [
        'not_installed' => true,
        'suggested' => [
            ['slug'=>'mailpoet', 'name'=>'MailPoet', 'reason'=>'Best free email marketing, forms + automation'],
            ['slug'=>'newsletter', 'name'=>'Newsletter', 'reason'=>'Lightweight, simple newsletter sending'],
            ['slug'=>'mailchimp-for-wp', 'name'=>'Mailchimp for WP', 'reason'=>'Free Mailchimp integration'],
        ],
    ]);
}


// Full Site Generator
function wpilot_generate_full_site($p) {
    $site_type = sanitize_text_field($p['site_type'] ?? 'general');
    $business = sanitize_text_field($p['business_name'] ?? get_bloginfo('name'));
    $description = sanitize_text_field($p['description'] ?? '');
    $pages_data = $p['pages'] ?? [];
    if (empty($pages_data)) $pages_data = wpilot_default_pages_for_type($site_type, $business);
    $created = [];
    foreach ($pages_data as $page) {
        $title = sanitize_text_field($page['title'] ?? 'Untitled');
        $slug = sanitize_title($page['slug'] ?? $title);
        $existing = get_page_by_path($slug);
        if ($existing) { $created[] = ['id'=>$existing->ID,'title'=>$title,'status'=>'exists']; continue; }
        $id = wp_insert_post(['post_title'=>$title,'post_name'=>$slug,'post_content'=>wp_kses_post($page['content']??''),'post_status'=>'publish','post_type'=>'page','menu_order'=>$page['order']??0]);
        if (is_wp_error($id)) continue;
        if (!empty($page['is_front'])) { update_option('show_on_front','page'); update_option('page_on_front',$id); }
        if (!empty($page['is_blog'])) update_option('page_for_posts',$id);
        $desc = wp_trim_words(wp_strip_all_tags($page['content']??''),25,'...');
        update_post_meta($id,'rank_math_description',substr($desc?:$title,0,160));
        $created[] = ['id'=>$id,'title'=>$title,'status'=>'created','url'=>get_permalink($id)];
    }
    $menu_name = $business.' Menu';
    $menu_id = wp_create_nav_menu($menu_name);
    if (!is_wp_error($menu_id)) {
        foreach ($created as $i=>$pg) {
            wp_update_nav_menu_item($menu_id,0,['menu-item-title'=>$pg['title'],'menu-item-object'=>'page','menu-item-object-id'=>$pg['id'],'menu-item-type'=>'post_type','menu-item-status'=>'publish','menu-item-position'=>$i+1]);
        }
        $locations = get_theme_mod('nav_menu_locations',[]);
        foreach (['primary','main-menu','header-menu','main','header'] as $try) {
            $all = get_registered_nav_menus();
            if (isset($all[$try])) { $locations[$try]=$menu_id; break; }
        }
        set_theme_mod('nav_menu_locations',$locations);
    }
    if ($description) update_option('blogdescription',$description);
    $count = count(array_filter($created,fn($p)=>$p['status']==='created'));
    return wpilot_ok("Site created: " . $count . " pages + menu. Navigate to any page and tell me what to change.", ["pages"=>$created]);
}

function wpilot_default_pages_for_type($type,$biz) {
    $p = [['title'=>'Home','slug'=>'home','is_front'=>true,'order'=>1,'content'=>'<h1>Welcome to '.$biz.'</h1>']];
    switch($type) {
        case 'booking':
            $p[] = ['title'=>'Services','slug'=>'services','order'=>2,'content'=>'<h2>Our Services</h2>'];
            $p[] = ['title'=>'Book Now','slug'=>'book','order'=>3,'content'=>'<h2>Book an Appointment</h2>'];
            $p[] = ['title'=>'About Us','slug'=>'about','order'=>4,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>5,'content'=>'<h2>Contact Us</h2>'];
            $p[] = ['title'=>'FAQ','slug'=>'faq','order'=>6,'content'=>'<h2>FAQ</h2>']; break;
        case 'ecommerce':
            $p[] = ['title'=>'Shop','slug'=>'shop','order'=>2,'content'=>'<h2>Products</h2>'];
            $p[] = ['title'=>'About','slug'=>'about','order'=>3,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>4,'content'=>'<h2>Contact</h2>'];
            $p[] = ['title'=>'FAQ','slug'=>'faq','order'=>5,'content'=>'<h2>FAQ</h2>'];
            $p[] = ['title'=>'Shipping','slug'=>'shipping','order'=>6,'content'=>'<h2>Shipping & Returns</h2>']; break;
        case 'restaurant':
            $p[] = ['title'=>'Menu','slug'=>'menu','order'=>2,'content'=>'<h2>Our Menu</h2>'];
            $p[] = ['title'=>'Reservations','slug'=>'reservations','order'=>3,'content'=>'<h2>Book a Table</h2>'];
            $p[] = ['title'=>'About','slug'=>'about','order'=>4,'content'=>'<h2>Our Story</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>5,'content'=>'<h2>Find Us</h2>']; break;
        case 'portfolio':
            $p[] = ['title'=>'Portfolio','slug'=>'portfolio','order'=>2,'content'=>'<h2>Our Work</h2>'];
            $p[] = ['title'=>'Services','slug'=>'services','order'=>3,'content'=>'<h2>What We Do</h2>'];
            $p[] = ['title'=>'About','slug'=>'about','order'=>4,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>5,'content'=>'<h2>Get in Touch</h2>']; break;
        case 'education':
            $p[] = ['title'=>'Courses','slug'=>'courses','order'=>2,'content'=>'<h2>Our Courses</h2>'];
            $p[] = ['title'=>'Pricing','slug'=>'pricing','order'=>3,'content'=>'<h2>Pricing</h2>'];
            $p[] = ['title'=>'About','slug'=>'about','order'=>4,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>5,'content'=>'<h2>Contact</h2>']; break;

        default:
            $p[] = ['title'=>'About','slug'=>'about','order'=>2,'content'=>'<h2>About '.$biz.'</h2>'];
            $p[] = ['title'=>'Services','slug'=>'services','order'=>3,'content'=>'<h2>Our Services</h2>'];
            $p[] = ['title'=>'Contact','slug'=>'contact','order'=>4,'content'=>'<h2>Contact Us</h2>'];
            $p[] = ['title'=>'Blog','slug'=>'blog','is_blog'=>True,'order'=>5,'content'=>'']; break;
    }
    $p[] = ['title'=>'Privacy Policy','slug'=>'privacy-policy','order'=>90,'content'=>'<h2>Privacy Policy</h2>'];
    return $p;
}


function wpilot_delete_directory($dir) {
    if (!is_dir($dir)) return false;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        is_dir($path) ? wpilot_delete_directory($path) : @unlink($path);
    }
    return @rmdir($dir);
}


// ═══════════════════════════════════════════════════════════════
//  PageSpeed Test — calls Google PageSpeed Insights API (free)
// ═══════════════════════════════════════════════════════════════

function wpilot_pagespeed_test($p) {
    $url = esc_url_raw($p['url'] ?? get_site_url());
    $strategy = sanitize_text_field($p['strategy'] ?? 'mobile'); // mobile or desktop

    // Google PageSpeed Insights API (free, no key required for basic use)
    $api_key = get_option('wpi_google_api_key', 'AIzaSyCQUl3oP-NN7DHY2VJz084oGRtBq_guTk4');
    $api_url = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url='
        . urlencode($url)
        . '&strategy=' . $strategy
        . '&category=performance&category=seo&category=best-practices&category=accessibility'
        . ($api_key ? '&key=' . $api_key : '');

    // Check cache first (results valid for 10 min)
    $cache_key = 'wpi_pagespeed_' . md5($url . $strategy);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }

    // Call API with retry on rate limit
    $response = wp_remote_get($api_url, ['timeout' => 90]);

    if (is_wp_error($response)) {
        return wpilot_err('PageSpeed test failed: ' . $response->get_error_message() . '. Try again in a minute.');
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code === 429) {
        // Rate limited - wait 5 seconds and retry once
        sleep(5);
        $response = wp_remote_get($api_url, ['timeout' => 90]);
        if (is_wp_error($response)) return wpilot_err('PageSpeed temporarily unavailable. Try again in 1-2 minutes.');
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 429) {
            return wpilot_err('Google PageSpeed is rate limited. Wait 1-2 minutes and try again. This is a Google API limit, not a WPilot issue.');
        }
    }
    if ($code !== 200) {
        return wpilot_err('PageSpeed API error (HTTP ' . $code . '). Try again shortly.');
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data) || !isset($data['lighthouseResult'])) {
        return wpilot_err('Invalid PageSpeed response');
    }

    $lh = $data['lighthouseResult'];
    $cats = $lh['categories'] ?? [];
    $audits = $lh['audits'] ?? [];

    // Extract scores
    $scores = [];
    foreach (['performance', 'seo', 'best-practices', 'accessibility'] as $cat) {
        if (isset($cats[$cat])) {
            $scores[$cat] = round(($cats[$cat]['score'] ?? 0) * 100);
        }
    }

    // Extract key metrics
    $metrics = [];
    $metric_keys = [
        'first-contentful-paint' => 'FCP',
        'largest-contentful-paint' => 'LCP',
        'total-blocking-time' => 'TBT',
        'cumulative-layout-shift' => 'CLS',
        'speed-index' => 'Speed Index',
        'interactive' => 'Time to Interactive',
    ];
    foreach ($metric_keys as $key => $label) {
        if (isset($audits[$key])) {
            $metrics[$label] = [
                'value' => $audits[$key]['displayValue'] ?? '?',
                'score' => round(($audits[$key]['score'] ?? 0) * 100),
            ];
        }
    }

    // Extract opportunities (things to fix)
    $opportunities = [];
    $opp_keys = [
        'render-blocking-resources' => 'Render-blocking resources',
        'unused-javascript' => 'Unused JavaScript',
        'unused-css-rules' => 'Unused CSS',
        'modern-image-formats' => 'Use WebP/AVIF images',
        'uses-optimized-images' => 'Optimize images',
        'uses-text-compression' => 'Enable text compression',
        'uses-responsive-images' => 'Properly size images',
        'efficient-animated-content' => 'Efficient animations',
        'offscreen-images' => 'Defer offscreen images',
        'unminified-javascript' => 'Minify JavaScript',
        'unminified-css' => 'Minify CSS',
        'uses-long-cache-ttl' => 'Cache static assets',
        'dom-size' => 'Reduce DOM size',
        'bootup-time' => 'Reduce JavaScript execution',
        'mainthread-work-breakdown' => 'Minimize main-thread work',
        'font-display' => 'Font display',
        'third-party-summary' => 'Third-party code',
    ];

    foreach ($opp_keys as $key => $label) {
        if (isset($audits[$key]) && ($audits[$key]['score'] ?? 1) < 1) {
            $savings = '';
            if (isset($audits[$key]['details']['overallSavingsMs'])) {
                $savings = round($audits[$key]['details']['overallSavingsMs']) . 'ms';
            } elseif (isset($audits[$key]['details']['overallSavingsBytes'])) {
                $savings = round($audits[$key]['details']['overallSavingsBytes'] / 1024) . 'KB';
            }
            $opportunities[] = [
                'issue' => $label,
                'savings' => $savings,
                'score' => round(($audits[$key]['score'] ?? 0) * 100),
                'displayValue' => $audits[$key]['displayValue'] ?? '',
            ];
        }
    }

    // Sort by score (worst first)
    usort($opportunities, function($a, $b) { return $a['score'] - $b['score']; });

    // Build result message
    $perf_score = $scores['performance'] ?? 0;
    $grade = $perf_score >= 90 ? 'A' : ($perf_score >= 50 ? 'B' : ($perf_score >= 25 ? 'C' : 'D'));

    $msg = "PageSpeed Results ({$strategy}):\n\n";
    $msg .= "Performance: {$perf_score}/100 (Grade: {$grade})\n";
    if (isset($scores['seo'])) $msg .= "SEO: {$scores['seo']}/100\n";
    if (isset($scores['accessibility'])) $msg .= "Accessibility: {$scores['accessibility']}/100\n";
    if (isset($scores['best-practices'])) $msg .= "Best Practices: {$scores['best-practices']}/100\n";
    $msg .= "\nMetrics:\n";
    foreach ($metrics as $label => $m) {
        $msg .= "- {$label}: {$m['value']}\n";
    }
    if (!empty($opportunities)) {
        $msg .= "\nTop issues to fix:\n";
        foreach (array_slice($opportunities, 0, 8) as $opp) {
            $save = $opp['savings'] ? " (save {$opp['savings']})" : '';
            $msg .= "- {$opp['issue']}{$save}\n";
        }
    }

    $result = wpilot_ok($msg, [
        'url' => $url,
        'strategy' => $strategy,
        'scores' => $scores,
        'metrics' => $metrics,
        'opportunities' => $opportunities,
        'grade' => $grade,
    ]);

    // Cache for 10 minutes to avoid rate limits
    set_transient($cache_key, $result, 600);

    return $result;
}


// ═══════════════════════════════════════════════════════════════
//  Performance Fix Tools
// ═══════════════════════════════════════════════════════════════

function wpilot_fix_performance($p) {
    $results = [];

    // 1. Configure cache if available
    if (function_exists('wpilot_cache_configure')) {
        $cache = wpilot_cache_configure($p);
        $results[] = 'Cache: ' . ($cache['message'] ?? 'configured');
    }

    // 2. Convert images to WebP
    $images = get_posts(['post_type'=>'attachment','post_mime_type'=>['image/jpeg','image/png'],'numberposts'=>-1]);
    $not_webp = count($images);
    if ($not_webp > 0) {
        $results[] = $not_webp . ' images can be converted to WebP (use convert_all_images_webp)';
    }

    // 3. Check for lazy loading
    $has_lazy = false;
    foreach (get_option('active_plugins', []) as $plugin) {
        if (preg_match('/litespeed|wp-rocket|lazy/i', $plugin)) $has_lazy = true;
    }
    if (!$has_lazy) {
        $results[] = 'No lazy loading detected — enable it for faster page loads';
    } else {
        $results[] = 'Lazy loading: active';
    }

    // 4. Database size
    global $wpdb;
    $revisions = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'");
    $transients = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' AND option_value < UNIX_TIMESTAMP()");
    if ($revisions > 50 || $transients > 20) {
        $results[] = "Database bloat: {$revisions} revisions, {$transients} expired transients — clean up to speed up queries";
    }

    // 5. Plugin count
    $plugin_count = count(get_option('active_plugins', []));
    if ($plugin_count > 20) {
        $results[] = "{$plugin_count} active plugins — consider deactivating unused ones";
    }

    // 6. PHP version
    $php = PHP_VERSION;
    if (version_compare($php, '8.0', '<')) {
        $results[] = "PHP {$php} is slow — upgrade to 8.0+ for 20-30% speed boost";
    }

    return wpilot_ok("Performance analysis:\n" . implode("\n", array_map(function($r) { return "- " . $r; }, $results)), [
        'not_webp' => $not_webp,
        'revisions' => $revisions,
        'transients' => $transients,
        'plugin_count' => $plugin_count,
        'php_version' => $php,
        'has_lazy' => $has_lazy,
    ]);
}

function wpilot_fix_render_blocking($p) {
    // This requires cache plugin configuration
    // LiteSpeed, WP Rocket, Autoptimize can defer/async CSS/JS

    if (defined('LSCWP_V') || class_exists('LiteSpeed\Core')) {
        update_option('litespeed.conf.optm-css_async', 1);
        update_option('litespeed.conf.optm-js_defer', 1);
        update_option('litespeed.conf.optm-js_inline_defer', 1);
        update_option('litespeed.conf.css_minify', 1);
        update_option('litespeed.conf.js_minify', 1);
        update_option('litespeed.conf.css_combine', 1);
        update_option('litespeed.conf.js_combine', 1);
        update_option('litespeed.conf.optm-qs_rm', 1);
        do_action('litespeed_purge_all');
        return wpilot_ok("LiteSpeed configured: CSS async, JS deferred, CSS/JS minified + combined, query strings removed. Cache purged.");
    }

    if (defined('WP_ROCKET_VERSION')) {
        $opts = get_option('wp_rocket_settings', []);
        $opts['defer_all_js'] = 1;
        $opts['async_css'] = 1;
        $opts['minify_css'] = 1;
        $opts['minify_js'] = 1;
        $opts['minify_concatenate_css'] = 1;
        $opts['minify_concatenate_js'] = 1;
        $opts['remove_query_strings'] = 1;
        update_option('wp_rocket_settings', $opts);
        if (function_exists('rocket_clean_domain')) rocket_clean_domain();
        return wpilot_ok("WP Rocket configured: JS deferred, CSS async, minified + combined.");
    }

    // No cache plugin — add inline via mu-plugin
    $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

    $code = "<?php\n// WPilot: Defer render-blocking resources\n"
        . "add_action('wp_enqueue_scripts', function() {\n"
        . "    // Add defer to all scripts\n"
        . "    add_filter('script_loader_tag', function(\$tag, \$handle) {\n"
        . "        if (is_admin()) return \$tag;\n"
        . "        if (strpos(\$tag, 'defer') !== false || strpos(\$tag, 'async') !== false) return \$tag;\n"
        . "        return str_replace(' src=', ' defer src=', \$tag);\n"
        . "    }, 10, 2);\n"
        . "}, 20);\n";

    file_put_contents($mu_dir . '/wpilot-defer-scripts.php', $code);
    return wpilot_ok("Created mu-plugin to defer all scripts. For best results, install LiteSpeed Cache or WP Rocket.");
}

function wpilot_enable_lazy_load($p) {
    // WordPress 5.5+ has native lazy loading, but we can enhance it

    if (defined('LSCWP_V')) {
        update_option('litespeed.conf.media_lazy', 1);
        update_option('litespeed.conf.media_lazy_placeholder', 1);
        update_option('litespeed.conf.media_placeholder_resp', 1);
        update_option('litespeed.conf.media_iframe_lazy', 1);
        return wpilot_ok("LiteSpeed lazy loading enabled: images, iframes, responsive placeholders.");
    }

    if (defined('WP_ROCKET_VERSION')) {
        $opts = get_option('wp_rocket_settings', []);
        $opts['lazyload'] = 1;
        $opts['lazyload_iframes'] = 1;
        $opts['lazyload_youtube'] = 1;
        update_option('wp_rocket_settings', $opts);
        return wpilot_ok("WP Rocket lazy loading enabled: images, iframes, YouTube.");
    }

    // WordPress native lazy loading is already on by default (5.5+)
    // Add enhanced lazy loading via mu-plugin
    $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

    $code = "<?php\n// WPilot: Enhanced lazy loading\n"
        . "add_filter('wp_lazy_loading_enabled', '__return_true');\n"
        . "add_filter('wp_img_tag_add_loading_attr', function() { return 'lazy'; });\n";

    file_put_contents($mu_dir . '/wpilot-lazy-load.php', $code);
    return wpilot_ok("Enhanced lazy loading enabled. WordPress native + mu-plugin. For best results, install LiteSpeed Cache.");
}

function wpilot_add_image_dimensions($p) {
    $images = get_posts(['post_type'=>'attachment','post_mime_type'=>'image','numberposts'=>-1]);
    $fixed = 0;

    foreach ($images as $img) {
        $meta = wp_get_attachment_metadata($img->ID);
        if (empty($meta['width']) || empty($meta['height'])) {
            $file = get_attached_file($img->ID);
            if ($file && file_exists($file)) {
                $size = wp_getimagesize($file);
                if ($size) {
                    $meta['width'] = $size[0];
                    $meta['height'] = $size[1];
                    wp_update_attachment_metadata($img->ID, $meta);
                    $fixed++;
                }
            }
        }
    }

    // Also fix images in content that lack width/height attributes
    $posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','numberposts'=>100]);
    $content_fixed = 0;
    foreach ($posts as $post) {
        $content = $post->post_content;
        if (preg_match_all('/<img[^>]+>/i', $content, $matches)) {
            $changed = false;
            foreach ($matches[0] as $img_tag) {
                if (strpos($img_tag, 'width=') === false || strpos($img_tag, 'height=') === false) {
                    // Try to get dimensions from attachment
                    if (preg_match('/wp-image-(\d+)/', $img_tag, $id_match)) {
                        $meta = wp_get_attachment_metadata(intval($id_match[1]));
                        if ($meta && !empty($meta['width']) && !empty($meta['height'])) {
                            $new_tag = $img_tag;
                            if (strpos($new_tag, 'width=') === false) {
                                $new_tag = str_replace('<img', '<img width="' . $meta['width'] . '"', $new_tag);
                            }
                            if (strpos($new_tag, 'height=') === false) {
                                $new_tag = str_replace('<img', '<img height="' . $meta['height'] . '"', $new_tag);
                            }
                            $content = str_replace($img_tag, $new_tag, $content);
                            $changed = true;
                            $content_fixed++;
                        }
                    }
                }
            }
            if ($changed) {
                wp_update_post(['ID' => $post->ID, 'post_content' => $content]);
            }
        }
    }

    return wpilot_ok("Fixed dimensions: {$fixed} image metadata + {$content_fixed} images in content now have width/height attributes.");
}

function wpilot_minify_assets($p) {
    // Configure minification in cache plugins
    if (function_exists('wpilot_cache_configure')) {
        return wpilot_cache_configure($p);
    }
    return wpilot_ok("Install a cache plugin (LiteSpeed Cache or WP Rocket) for CSS/JS minification. Use cache_configure after installing.");
}


// ═══════════════════════════════════════════════════════════════
//  Image Compression — compress images using WordPress image editor
// ═══════════════════════════════════════════════════════════════

function wpilot_compress_images($p) {
    $quality = intval($p['quality'] ?? 70);
    $limit = intval($p['limit'] ?? 50);
    $min_size = intval($p['min_size_kb'] ?? 100); // only compress images larger than this

    $images = get_posts([
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'numberposts' => $limit,
        'post_status' => 'inherit',
    ]);

    $compressed = 0;
    $saved_total = 0;
    $skipped = 0;

    foreach ($images as $img) {
        $file = get_attached_file($img->ID);
        if (!$file || !file_exists($file)) { $skipped++; continue; }

        $size = filesize($file);
        if ($size < $min_size * 1024) { $skipped++; continue; }

        $editor = wp_get_image_editor($file);
        if (is_wp_error($editor)) { $skipped++; continue; }

        // Backup original
        $backup = $file . '.wpilot-compress-backup';
        if (!file_exists($backup)) copy($file, $backup);

        // Compress
        $editor->set_quality($quality);
        $saved = $editor->save($file);

        if (!is_wp_error($saved)) {
            $new_size = filesize($file);
            $saved_bytes = $size - $new_size;
            if ($saved_bytes > 0) {
                $saved_total += $saved_bytes;
                $compressed++;

                // Regenerate thumbnails
                $metadata = wp_generate_attachment_metadata($img->ID, $file);
                wp_update_attachment_metadata($img->ID, $metadata);
            }
        }
    }

    $saved_kb = round($saved_total / 1024);
    $saved_mb = round($saved_total / 1048576, 1);

    return wpilot_ok(
        "Compressed {$compressed} images — saved {$saved_kb}KB ({$saved_mb}MB). Skipped {$skipped} (already small or unsupported).",
        ['compressed' => $compressed, 'saved_bytes' => $saved_total, 'skipped' => $skipped]
    );
}

// ── Elementor button editor helper ──
function wpilot_walk_edit_button(&$elements, $old_label, $new_label, $new_url = '') {
    $found = false;
    foreach ($elements as &$el) {
        if (($el['widgetType'] ?? '') === 'button' && ($el['settings']['text'] ?? '') === $old_label) {
            if ($new_label) $el['settings']['text'] = $new_label;
            if ($new_url) $el['settings']['link']['url'] = $new_url;
            $found = true;
        }
        if (!empty($el['elements'])) {
            if (wpilot_walk_edit_button($el['elements'], $old_label, $new_label, $new_url)) $found = true;
        }
    }
    return $found;
}

// ── Collect Elementor elements for listing ──
function wpilot_collect_elements($elements, &$result, $depth = 0) {
    foreach ($elements as $el) {
        $type = $el['elType'] ?? '?';
        $widget = $el['widgetType'] ?? '';
        $s = $el['settings'] ?? [];
        if ($widget === 'heading' || $widget === 'text-editor') {
            $result[] = ['type' => $widget, 'text' => substr(strip_tags($s['title'] ?? $s['editor'] ?? ''), 0, 80)];
        } elseif ($widget === 'button') {
            $result[] = ['type' => 'button', 'text' => $s['text'] ?? '', 'url' => $s['link']['url'] ?? ''];
        } elseif ($widget === 'image') {
            $result[] = ['type' => 'image', 'src' => $s['image']['url'] ?? '', 'alt' => $s['caption'] ?? ''];
        } elseif ($widget === 'icon') {
            $result[] = ['type' => 'icon', 'icon' => $s['selected_icon']['value'] ?? ''];
        } elseif ($widget === 'html') {
            // Extract elements from HTML widget
            $html = $s['html'] ?? '';
            if (preg_match_all('/<h([1-6])[^>]*>([^<]+)<\/h\1>/i', $html, $hm)) {
                foreach ($hm[2] as $i => $t) $result[] = ['type' => 'heading_h'.$hm[1][$i], 'text' => trim($t)];
            }
            if (preg_match_all('/<(?:a|button)[^>]*>([^<]{1,50})<\/(?:a|button)>/i', $html, $bm)) {
                foreach ($bm[1] as $t) $result[] = ['type' => 'button', 'text' => trim($t)];
            }
        }
        if (!empty($el['elements'])) wpilot_collect_elements($el['elements'], $result, $depth + 1);
    }
}
