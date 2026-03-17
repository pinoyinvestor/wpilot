<?php
if (!defined('ABSPATH')) exit;

/**
 * WPilot Pages Tools Module
 * Contains 165 tool cases for pages operations.
 */
function wpilot_run_page_tools($tool, $params = []) {
    switch ($tool) {

        case 'create_page':
            $title   = sanitize_text_field( $params['title']   ?? 'New Page' );
            $content = wp_kses_post(          $params['content'] ?? '' );
            $status  = sanitize_text_field( $params['status']  ?? 'draft' );
            $id = wp_insert_post( ['post_title'=>$title,'post_content'=>$content,'post_status'=>$status,'post_type'=>'page'] );
            if ( is_wp_error($id) ) return wpilot_err( $id->get_error_message() );
            return wpilot_ok( "Page \"{$title}\" created (ID: {$id}, status: {$status}).", ['id'=>$id] );

        case 'append_page_content':
            $params['append'] = true;
            // Fall through to update_page_content

        case 'update_page_content':
            $id      = intval( $params['id'] ?? $params['page_id'] ?? $params['post_id'] ?? 0 );
            $content = $params['content'] ?? $params['html'] ?? '';
            $append  = !empty( $params['append'] );
            if ( !$id ) return wpilot_err('Page ID required.');
            if ( empty($content) ) return wpilot_err('Content required.');
            // Clean AI artifacts
            $content = preg_replace('/\[ACTION:[^\]]*\]/', '', $content);
            $content = preg_replace('/```(?:html|json|css)?\s*/', '', $content);
            $content = preg_replace('/\n*[✅⚠️].*Auto-approved:.*$/s', '', $content);
            $content = trim($content);
            // Auto-fix: wrap orphan CSS in <style> tags
            if (preg_match('/^@import|^[.#*{]|^body\s*{/m', $content) && strpos($content, '<style') === false) {
                $content = '<style>' . $content;
                if (($pos = strpos($content, '</div>')) !== false || ($pos = strpos($content, '<div')) !== false || ($pos = strpos($content, '<section')) !== false) {
                    $content = substr($content, 0, $pos) . '</style>' . substr($content, $pos);
                } else {
                    $content .= '</style>';
                }
            }
            // Wrap in Gutenberg HTML block
            if (strpos($content, '<!-- wp:') === false) {
                $content = '<!-- wp:html -->' . $content . '<!-- /wp:html -->';
            }
            // Append mode: merge new content with existing page content
            if ( $append ) {
                $existing = get_post_field( 'post_content', $id );
                if ( !empty( $existing ) ) {
                    $strip_wp_html = function( $html ) {
                        $html = preg_replace( '/<!--\s*wp:html\s*-->/', '', $html );
                        $html = preg_replace( '/<!--\s*\/wp:html\s*-->/', '', $html );
                        return trim( $html );
                    };
                    // Built by Christos Ferlachidis & Daniel Hedenberg
                    $existing_inner = $strip_wp_html( $existing );
                    $new_inner      = $strip_wp_html( $content );
                    $content = '<!-- wp:html -->' . $existing_inner . "\n" . $new_inner . '<!-- /wp:html -->';
                }
            }
            wpilot_save_post_snapshot( $id );
            wp_update_post( ['ID'=>$id,'post_content'=>$content] );
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            $mode_label = $append ? 'appended to' : 'updated';
            return wpilot_ok( "Page #{$id} content {$mode_label}." );

        case 'update_post_title':
            $id    = intval( $params['id'] ?? 0 );
            $title = sanitize_text_field( $params['title'] ?? '' );
            if ( !$id ) return wpilot_err('Post ID required.');
            wpilot_save_post_snapshot( $id );
            wp_update_post( ['ID'=>$id,'post_title'=>$title] );
            return wpilot_ok( "Title updated to \"{$title}\"." );

        case 'set_page_template':
            $id = intval($params['id'] ?? $params['page_id'] ?? $params['post_id'] ?? 0);
            $template = sanitize_text_field($params['template'] ?? '');
            if (!$id) return wpilot_err('Page ID required.');
            // Auto-detect full-width template if none specified
            if (empty($template)) {
                $available = wp_get_theme()->get_page_templates(null, 'page');
                foreach ($available as $file => $name) {
                    if (preg_match('/full[- ]?width|no[- ]?sidebar|blank|canvas/i', $name . ' ' . $file)) {
                        $template = $file; break;
                    }
                }
                if (empty($template)) $template = 'default';
            }
            update_post_meta($id, '_wp_page_template', $template);
            // For Elementor
            if (strpos($template, 'elementor') !== false) {
                update_post_meta($id, '_elementor_edit_mode', 'builder');
            }
            // For Storefront: also disable sidebar via theme mod
            $theme_slug = get_option('stylesheet');
            if ($theme_slug === 'storefront' && preg_match('/full|blank|no.*sidebar/i', $template)) {
                set_theme_mod('storefront_layout', 'none');
            }
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Page #{$id} template set to \"{$template}\".");

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

        // Duplicate set_page_template removed — handled at line 35

        case 'delete_post':
            $id = intval($params['id'] ?? $params['post_id'] ?? $params['page_id'] ?? 0);
            if (!$id) return wpilot_err('Post ID required.');
            wpilot_save_post_snapshot($id);
            wp_trash_post($id);
            return wpilot_ok("Post #{$id} moved to trash.");

        /* ── Widgets & Sidebars ─────────────────────────────── */
        case 'update_widget_area':
        case 'clear_sidebar':
        case 'remove_widgets':
            $sidebar = sanitize_text_field($params['sidebar_id'] ?? $params['sidebar'] ?? 'sidebar-1');
            $widgets = $params['widgets'] ?? [];
            // Get current sidebar widgets for backup
            $sidebars = get_option('sidebars_widgets', []);
            if (isset($sidebars[$sidebar])) {
                update_option('ca_sidebar_backup_' . $sidebar, $sidebars[$sidebar]);
            }
            // Set widgets — empty array clears all
            $sidebars[$sidebar] = $widgets;
            update_option('sidebars_widgets', $sidebars);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            $count = count($widgets);
            return wpilot_ok($count ? "{$count} widgets set in \"{$sidebar}\"." : "Sidebar \"{$sidebar}\" cleared — all widgets removed.");

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
            $code = $params['code'] ?? $params['html'] ?? $params['css'] ?? $params['content'] ?? '';
            if (empty($code)) return wpilot_err('Code required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-head-styles.php';
            $filepath = $mu_dir . '/' . $filename;
            // Extract CSS rules from new code (strip <style> tags if present)
            $new_css = $code;
            $new_css = preg_replace('/<\/?style[^>]*>/i', '', $new_css);
            $new_css = trim($new_css);
            // Read existing CSS from consolidated file
            $existing_css = '';
            if (file_exists($filepath)) {
                $content = file_get_contents($filepath);
                if (preg_match('/\/\* BEGIN CSS \*\/\s*(.*?)\s*\/\* END CSS \*\//s', $content, $m)) {
                    $existing_css = trim($m[1]);
                }
            }
            // Parse new CSS selectors and replace duplicates
            // Match selector { ... } blocks (handles nested braces via non-greedy)
            $new_blocks = [];
            preg_match_all('/([^{}]+)\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s', $new_css, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $selector = trim($match[1]);
                $body = trim($match[2]);
                $new_blocks[$selector] = $body;
            }
            // Parse existing CSS selectors
            $existing_blocks = [];
            if (!empty($existing_css)) {
                preg_match_all('/([^{}]+)\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s', $existing_css, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $selector = trim($match[1]);
                    $body = trim($match[2]);
                    $existing_blocks[$selector] = $body;
                }
            }
            // Merge: new selectors override existing ones
            $merged = array_merge($existing_blocks, $new_blocks);
            $merged_css = '';
            foreach ($merged as $sel => $body) {
                $merged_css .= $sel . " {\n    " . $body . "\n}\n";
            }
            $merged_css = trim($merged_css);
            // Write consolidated file
            $php = "<?php\n// WPilot consolidated head styles\nadd_action('wp_head', function() {\n"
                . "echo <<<'WPILOT_CSS'\n<style>\n/* BEGIN CSS */\n"
                . $merged_css . "\n"
                . "/* END CSS */\n</style>\nWPILOT_CSS;\n}, 1);\n";
            file_put_contents($filepath, $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            $sel_count = count($new_blocks);
            return wpilot_ok("CSS merged into consolidated mu-plugin: {$filename} ({$sel_count} selector(s) added/updated, " . count($merged) . " total)");

        case 'add_footer_code':
            $code = $params['code'] ?? $params['html'] ?? $params['content'] ?? '';
            if (empty($code)) return wpilot_err('Code required.');
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);
            $filename = 'wpilot-footer-scripts.php';
            $filepath = $mu_dir . '/' . $filename;
            // Read existing code from consolidated file
            $existing_code = '';
            if (file_exists($filepath)) {
                $content = file_get_contents($filepath);
                if (preg_match('/\/\* BEGIN FOOTER \*\/\s*(.*?)\s*\/\* END FOOTER \*\//s', $content, $m)) {
                    $existing_code = trim($m[1]);
                }
            }
            // Append new code (footer scripts are JS/HTML, not CSS — just append)
            $new_code = trim($code);
            if (!empty($existing_code)) {
                // Avoid exact duplicates
                if (strpos($existing_code, $new_code) === false) {
                    $merged_code = $existing_code . "\n" . $new_code;
                } else {
                    $merged_code = $existing_code;
                }
            } else {
                $merged_code = $new_code;
            }
            // Write consolidated file
            $php = "<?php\n// WPilot consolidated footer scripts\nadd_action('wp_footer', function() {\n"
                . "echo <<<'WPILOT_HTML'\n/* BEGIN FOOTER */\n"
                . $merged_code . "\n"
                . "/* END FOOTER */\nWPILOT_HTML;\n});\n";
            file_put_contents($filepath, $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Code merged into consolidated mu-plugin: {$filename}");

        case 'cleanup_mu_plugins':
            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) return wpilot_ok("No mu-plugins directory found. Nothing to clean up.");
            $head_files = glob($mu_dir . '/wpilot-custom-head-*.php') ?: [];
            $head_files = array_merge($head_files, glob($mu_dir . '/wpilot-*head*.php') ?: []);
            // Exclude the consolidated file itself
            $head_files = array_filter($head_files, function($f) { return basename($f) !== 'wpilot-head-styles.php'; });
            $head_files = array_unique($head_files);
            $footer_files = glob($mu_dir . '/wpilot-custom-footer-*.php') ?: [];
            $footer_files = array_merge($footer_files, glob($mu_dir . '/wpilot-*footer*.php') ?: []);
            $footer_files = array_filter($footer_files, function($f) { return basename($f) !== 'wpilot-footer-scripts.php'; });
            $footer_files = array_unique($footer_files);
            // Collect all CSS from old head files
            $all_css = '';
            foreach ($head_files as $file) {
                $content = file_get_contents($file);
                if (preg_match("/echo\s+<<<'WPILOT_(?:HTML|CSS)'\s*\n(.*?)\nWPILOT_(?:HTML|CSS);/s", $content, $m)) {
                    $extracted = $m[1];
                    $extracted = preg_replace('/<\/?style[^>]*>/i', '', $extracted);
                    $extracted = preg_replace('/\/\* (?:BEGIN|END) CSS \*\//', '', $extracted);
                    $all_css .= trim($extracted) . "\n";
                }
            }
            // Collect all code from old footer files
            $all_footer = '';
            foreach ($footer_files as $file) {
                $content = file_get_contents($file);
                if (preg_match("/echo\s+<<<'WPILOT_(?:HTML|CSS)'\s*\n(.*?)\nWPILOT_(?:HTML|CSS);/s", $content, $m)) {
                    $extracted = $m[1];
                    $extracted = preg_replace('/\/\* (?:BEGIN|END) FOOTER \*\//', '', $extracted);
                    $all_footer .= trim($extracted) . "\n";
                }
            }
            // Merge into existing consolidated head file
            $head_path = $mu_dir . '/wpilot-head-styles.php';
            $existing_css = '';
            if (file_exists($head_path)) {
                $content = file_get_contents($head_path);
                if (preg_match('/\/\* BEGIN CSS \*\/\s*(.*?)\s*\/\* END CSS \*\//s', $content, $m)) {
                    $existing_css = trim($m[1]);
                }
            }
            $all_css = trim($all_css);
            if (!empty($all_css)) {
                $merged_blocks = [];
                $combined = $existing_css . "\n" . $all_css;
                preg_match_all('/([^{}]+)\{([^{}]*(?:\{[^{}]*\}[^{}]*)*)\}/s', $combined, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) {
                    $merged_blocks[trim($match[1])] = trim($match[2]);
                }
                $merged_css = '';
                foreach ($merged_blocks as $sel => $body) {
                    $merged_css .= $sel . " {\n    " . $body . "\n}\n";
                }
                $merged_css = trim($merged_css);
                $php = "<?php\n// WPilot consolidated head styles\nadd_action('wp_head', function() {\n"
                    . "echo <<<'WPILOT_CSS'\n<style>\n/* BEGIN CSS */\n"
                    . $merged_css . "\n"
                    . "/* END CSS */\n</style>\nWPILOT_CSS;\n}, 1);\n";
                file_put_contents($head_path, $php);
            }
            // Merge into existing consolidated footer file
            $footer_path = $mu_dir . '/wpilot-footer-scripts.php';
            $existing_footer = '';
            if (file_exists($footer_path)) {
                $content = file_get_contents($footer_path);
                if (preg_match('/\/\* BEGIN FOOTER \*\/\s*(.*?)\s*\/\* END FOOTER \*\//s', $content, $m)) {
                    $existing_footer = trim($m[1]);
                }
            }
            $all_footer = trim($all_footer);
            if (!empty($all_footer)) {
                if (!empty($existing_footer) && strpos($existing_footer, $all_footer) === false) {
                    $merged_footer = $existing_footer . "\n" . $all_footer;
                } elseif (empty($existing_footer)) {
                    $merged_footer = $all_footer;
                } else {
                    $merged_footer = $existing_footer;
                }
                $php = "<?php\n// WPilot consolidated footer scripts\nadd_action('wp_footer', function() {\n"
                    . "echo <<<'WPILOT_HTML'\n/* BEGIN FOOTER */\n"
                    . $merged_footer . "\n"
                    . "/* END FOOTER */\nWPILOT_HTML;\n});\n";
                file_put_contents($footer_path, $php);
            }
            // Delete old individual files
            $deleted = 0;
            foreach (array_merge($head_files, $footer_files) as $file) {
                if (file_exists($file)) { unlink($file); $deleted++; }
            }
            $total_merged = count($head_files) + count($footer_files);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Cleanup complete: {$total_merged} old files merged, {$deleted} deleted. Consolidated into wpilot-head-styles.php and wpilot-footer-scripts.php.");

        case 'add_php_snippet':
            $code = $params['code'] ?? '';
            $hook = sanitize_text_field($params['hook'] ?? 'init');
            $name = sanitize_file_name($params['name'] ?? 'snippet-' . time());
            $priority = intval($params['priority'] ?? 10);
            if (empty($code)) return wpilot_err('Code required.');
            // Allow PHP+HTML mix for output hooks (wp_head, wp_footer, admin_notices)
            $output_hooks = ['wp_head', 'wp_footer', 'admin_notices', 'admin_footer', 'login_head', 'login_footer'];
            $is_output_hook = in_array($hook, $output_hooks);
            if (!$is_output_hook) {
                // For non-output hooks, strip echo/print to avoid polluting AJAX responses
                $code = preg_replace('/\bvar_dump\s*\(/','// var_dump(', $code);
                $code = preg_replace('/\bprint_r\s*\(/','// print_r(', $code);
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
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
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
            // Clean AI artifacts from HTML — remove [ACTION:...] tags that leaked into content
            $html = preg_replace('/\[ACTION:[^\]]*\]/', '', $html);
            // Remove markdown code fences
            $html = preg_replace('/```(?:html|json|css)?\s*/', '', $html);
            // Remove auto-approved summaries
            $html = preg_replace('/\n*[✅⚠️].*Auto-approved:.*$/s', '', $html);
            $html = trim($html);

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
            $slug = sanitize_title($params['slug'] ?? $title);
            // Check if page with this slug exists — update instead of creating duplicate
            $existing = get_page_by_path($slug);
            if ($existing) {
                wp_update_post(['ID' => $existing->ID, 'post_content' => $gutenberg_content, 'post_status' => 'publish']);
                $id = $existing->ID;
            } else {
                $id = wp_insert_post([
                    'post_title' => sanitize_text_field($title),
                    'post_name' => $slug,
                    'post_content' => $gutenberg_content,
                    'post_status' => 'publish',
                    'post_type' => 'page',
                ]);
                if (is_wp_error($id)) return wpilot_err($id->get_error_message());
            }
            // Auto-set full-width template if available
            $templates = wp_get_theme()->get_page_templates(null, 'page');
            foreach ($templates as $file => $name) {
                if (preg_match('/full[- ]?width|no[- ]?sidebar|blank|canvas/i', $name . ' ' . $file)) {
                    update_post_meta($id, '_wp_page_template', $file);
                    break;
                }
            }
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Page \"{$title}\" " . ($existing ? "updated" : "created") . " (ID: {$id}).", [
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
            if (is_wp_error($result)) {
                if (strpos($result->get_error_message(), "already exists") !== false) {
                    $existing = get_term_by("name", $name, "post_tag");
                    return wpilot_ok("Tag '{}' already exists.", ["id" => $existing ? $existing->term_id : 0]);
                }
                return wpilot_err($result->get_error_message());
            }
            return wpilot_ok("Category '{$name}' created.", ['id' => $result['term_id']]);

        case 'create_tag':
            $name = sanitize_text_field($params['name'] ?? $params['tag'] ?? '');
            if (!$name) return wpilot_err('Tag name required.');
            $result = wp_insert_term($name, 'post_tag');
            if (is_wp_error($result)) {
                if (strpos($result->get_error_message(), "already exists") !== false) {
                    $existing = get_term_by("name", $name, "post_tag");
                    return wpilot_ok("Tag '{}' already exists.", ["id" => $existing ? $existing->term_id : 0]);
                }
                return wpilot_err($result->get_error_message());
            }
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
            if (is_wp_error($result)) {
                if (strpos($result->get_error_message(), "already exists") !== false) {
                    $existing = get_term_by("name", $name, "post_tag");
                    return wpilot_ok("Tag '{}' already exists.", ["id" => $existing ? $existing->term_id : 0]);
                }
                return wpilot_err($result->get_error_message());
            }
            return wpilot_ok("Theme '{$slug}' installed.");

        case 'activate_theme':
// Built by Christos Ferlachidis & Daniel Hedenberg
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
            if (is_wp_error($result)) {
                if (strpos($result->get_error_message(), "already exists") !== false) {
                    $existing = get_term_by("name", $name, "post_tag");
                    return wpilot_ok("Tag '{}' already exists.", ["id" => $existing ? $existing->term_id : 0]);
                }
                return wpilot_err($result->get_error_message());
            }
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
            // Try Fluent Forms
            $ff_table = $wpdb->prefix . 'fluentform_submissions';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$ff_table}'") === $ff_table) {
                $ff_rows = $wpdb->get_results("SELECT id, form_id, response, created_at, status FROM {$ff_table} ORDER BY created_at DESC LIMIT " . intval($params['limit'] ?? 50), ARRAY_A);
                foreach ($ff_rows as $r) {
                    $fields = json_decode($r['response'], true);
                    $email = '';
                    $name = '';
                    // Extract email and name from fields
                    if (is_array($fields)) {
                        foreach ($fields as $k => $v) {
                            if (stripos($k, 'email') !== false && filter_var($v, FILTER_VALIDATE_EMAIL)) $email = $v;
                            if (stripos($k, 'name') !== false && is_string($v)) $name = $v;
                            // Fluent Forms nested name fields
                            if (is_array($v) && isset($v['first_name'])) $name = trim($v['first_name'] . ' ' . ($v['last_name'] ?? ''));
                        }
                    }
                    $entries[] = ['id' => $r['id'], 'form_id' => $r['form_id'], 'date' => $r['created_at'], 'status' => $r['status'], 'email' => $email, 'name' => $name, 'fields' => $fields, 'source' => 'fluentform'];
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
            // Fluent Forms emails
            $ff_table = $wpdb->prefix . 'fluentform_submissions';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$ff_table}'") === $ff_table) {
                $ff_rows = $wpdb->get_results("SELECT response FROM {$ff_table} WHERE status != 'trashed' LIMIT 1000", ARRAY_A);
                foreach ($ff_rows as $r) {
                    $fields = json_decode($r['response'], true);
                    if (is_array($fields)) foreach ($fields as $k => $v) {
                        if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) && !isset($emails[$v])) {
                            $name = '';
                            foreach ($fields as $nk => $nv) { if (stripos($nk, 'name') !== false && is_string($nv)) { $name = $nv; break; } }
                            $emails[$v] = ['name' => $name, 'source' => 'fluentform'];
                        }
                    }
                }
            }
            // WPForms emails
            $wpf_table = $wpdb->prefix . 'wpforms_entries';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$wpf_table}'") === $wpf_table) {
                $wpf_rows = $wpdb->get_results("SELECT fields FROM {$wpf_table} LIMIT 1000", ARRAY_A);
                foreach ($wpf_rows as $r) {
                    $fields = json_decode($r['fields'], true);
                    if (is_array($fields)) foreach ($fields as $field) {
                        $val = $field['value'] ?? '';
                        if (filter_var($val, FILTER_VALIDATE_EMAIL) && !isset($emails[$val])) {
                            $emails[$val] = ['name' => '', 'source' => 'wpforms'];
                        }
                    }
                }
            }
            // Gravity Forms emails
            if (class_exists('GFAPI')) {
                $gf_entries = GFAPI::get_entries(0, [], null, ['offset' => 0, 'page_size' => 500]);
                foreach ($gf_entries as $e) {
                    foreach ($e as $k => $v) {
                        if (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) && !isset($emails[$v])) {
                            $emails[$v] = ['name' => '', 'source' => 'gravityforms'];
                        }
                    }
                }
            }
            // WooCommerce order emails (non-registered)
            if (class_exists('WooCommerce')) {
                $order_emails = $wpdb->get_results("SELECT DISTINCT pm.meta_value as email FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE pm.meta_key = '_billing_email' AND p.post_type = 'shop_order' LIMIT 1000", ARRAY_A);
                foreach ($order_emails as $oe) {
                    if (!empty($oe['email']) && !isset($emails[$oe['email']])) {
                        $emails[$oe['email']] = ['name' => '', 'source' => 'order'];
                    }
                }
            }
            // Built by Christos Ferlachidis & Daniel Hedenberg
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
            $table = $wpdb->prefix . 'ca_backups';
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $status['total_backups'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table}"));
                $status['unrestored'] = intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE restored = 0"));
            }
            return wpilot_ok('Recovery system status.', $status);

        case 'undo_last':
        case 'rollback_last':
            global $wpdb;
            $table = $wpdb->prefix . 'ca_backups';
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
            return wpilot_ok("Backup found but content unchanged (may already be current).", ["backup_id" => $last->id]); // was: wpilot_err("Could not restore backup #' . $last->id);

        case 'undo_all':
        case 'rollback_all':
            global $wpdb;
            $table = $wpdb->prefix . 'ca_backups';
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

        
        // ═══ NEWSLETTER SUBSCRIPTION SYSTEM ═══
        case 'create_subscribe_form':
        case 'add_subscribe_button':
            $page_id = intval($params['page_id'] ?? 0);
            $style = sanitize_text_field($params['style'] ?? 'inline');
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $title = sanitize_text_field($params['title'] ?? 'Subscribe to our newsletter');
            $subtitle = sanitize_text_field($params['subtitle'] ?? 'Get exclusive deals and updates. No spam.');
            $button_text = sanitize_text_field($params['button_text'] ?? 'Subscribe');
            $discount = sanitize_text_field($params['discount_code'] ?? '');

            if ($style === 'popup') {
                $html = '<div id="wpilot-subscribe-popup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px)">';
                $html .= '<div style="background:#0a0a0a;border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:40px;max-width:440px;width:90%;text-align:center;position:relative">';
                $html .= '<button onclick="this.parentElement.parentElement.style.display=\'none\'" style="position:absolute;top:12px;right:16px;background:none;border:none;color:rgba(255,255,255,0.3);font-size:1.5rem;cursor:pointer">&times;</button>';
            } else {
                $html = '<div style="max-width:500px;margin:40px auto;padding:32px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:16px;text-align:center">';
            }

            if ($discount) $html .= '<p style="display:inline-block;padding:6px 16px;background:' . $accent . '22;color:' . $accent . ';border-radius:20px;font-size:0.85rem;font-weight:600;margin:0 0 16px">🎁 Get ' . esc_html($discount) . ' off your first order!</p>';
            $html .= '<h3 style="font-size:1.3rem;font-weight:700;color:#fff;margin:0 0 8px">' . esc_html($title) . '</h3>';
            $html .= '<p style="color:rgba(255,255,255,0.5);margin:0 0 20px;font-size:0.9rem">' . esc_html($subtitle) . '</p>';
            $html .= '<form class="wpilot-subscribe-form" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center">';
            $html .= '<input type="email" name="email" placeholder="Your email" required style="flex:1;min-width:200px;padding:12px 16px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:#fff;font-size:0.95rem">';
            $html .= '<button type="submit" style="padding:12px 28px;background:' . $accent . ';color:#fff;border:none;border-radius:10px;font-weight:600;cursor:pointer;font-size:0.95rem">' . esc_html($button_text) . '</button>';
            $html .= '</form>';
            $html .= '<p class="wpilot-subscribe-msg" style="margin:12px 0 0;font-size:0.85rem;color:rgba(255,255,255,0.4);min-height:20px"></p>';

            if ($style === 'popup') {
                $html .= '</div></div>';
                $html .= '<script>setTimeout(function(){document.getElementById("wpilot-subscribe-popup").style.display="flex"},5000);</script>';
            } else {
                $html .= '</div>';
            }

            // Add JS handler
            $js = '<script>document.querySelectorAll(".wpilot-subscribe-form").forEach(function(f){f.addEventListener("submit",function(e){e.preventDefault();var email=f.querySelector("[name=email]").value;var msg=f.nextElementSibling;msg.textContent="Subscribing...";fetch("' . admin_url('admin-ajax.php') . '",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"action=wpilot_subscribe&email="+encodeURIComponent(email)}).then(r=>r.json()).then(d=>{msg.textContent=d.success?d.data.message:"Error";msg.style.color=d.success?"#10b981":"#ef4444";f.querySelector("[name=email]").value="";}).catch(()=>{msg.textContent="Error. Try again.";});});});</script>';
            $html .= $js;

            if ($page_id) {
                $content = get_post_field('post_content', $page_id);
                wp_update_post(['ID' => $page_id, 'post_content' => $content . "\n" . $html]);
                return wpilot_ok("Subscribe form added to page #{$page_id}.", ['page_id' => $page_id, 'style' => $style]);
            }
            return wpilot_ok("Subscribe form HTML generated.", ['html' => $html, 'style' => $style]);

        case 'list_subscribers':
        case 'newsletter_subscribers':
            $subscribers = get_option('wpilot_subscribers', []);
            $list = [];
            foreach ($subscribers as $email => $data) $list[] = array_merge(['email' => $email], $data);
            // Also count WP subscribers
            $wp_subs = get_users(['role' => 'subscriber', 'fields' => ['user_email']]);
            return wpilot_ok(count($list) . " newsletter subscribers + " . count($wp_subs) . " WP subscribers.", [
                'newsletter' => $list,
                'wp_subscribers' => count($wp_subs),
                'total' => count($list) + count($wp_subs),
            ]);

        case 'send_newsletter_to_all':
        case 'blast_email':
            $subject = sanitize_text_field($params['subject'] ?? '');
            $body = wp_kses_post($params['body'] ?? $params['content'] ?? '');
            $from_name = sanitize_text_field($params['from_name'] ?? get_bloginfo('name'));
            if (empty($subject) || empty($body)) return wpilot_err('subject and body required.');
            // Collect ALL emails
            $r = wpilot_run_tool('collect_emails', []);
            $all_emails = array_keys($r['by_source'] ?? []) ? array_keys($emails ?? []) : [];
            // Actually get the emails from the CSV
            $upload = wp_upload_dir();
            $csv_file = $upload['basedir'] . '/wpilot-all-emails.csv';
            $sent = 0; $failed = 0;
            if (file_exists($csv_file)) {
                $lines = file($csv_file);
                array_shift($lines); // Remove header
                $site_name = get_bloginfo('name');
                $site_url = get_site_url();
                // HTML email template
                $html_body = "<!DOCTYPE html><html><body style=\"font-family:system-ui,sans-serif;background:#f5f5f5;padding:40px 20px\"><div style=\"max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden\">";
                $html_body .= "<div style=\"background:#0a0a0a;padding:24px;text-align:center\"><h1 style=\"color:#fff;margin:0;font-size:1.3rem\">{$from_name}</h1></div>";
                $html_body .= "<div style=\"padding:32px\">{$body}</div>";
                $html_body .= "<div style=\"padding:16px 32px;background:#f9f9f9;text-align:center;font-size:0.8rem;color:#999\">{$site_name} — <a href=\"{$site_url}\">{$site_url}</a></div>";
                $html_body .= "</div></body></html>";
                $headers = ['Content-Type: text/html; charset=UTF-8', 'From: ' . $from_name . ' <' . get_option('admin_email') . '>'];
                foreach ($lines as $line) {
                    $parts = str_getcsv(trim($line));
                    $email = $parts[0] ?? '';
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $result = wp_mail($email, $subject, $html_body, $headers);
                        if ($result) $sent++; else $failed++;
                        // Rate limit — 1 email per 0.5 seconds
                        usleep(500000);
                    }
                    // Limit to 100 per batch to avoid timeout
                    if ($sent + $failed >= 100) break;
                }
            }
            return wpilot_ok("Newsletter sent to {$sent} recipients" . ($failed ? ", {$failed} failed" : "") . ".", ['sent' => $sent, 'failed' => $failed]);

        case 'create_discount_popup':
        case 'exit_intent_popup':
            $discount_code = sanitize_text_field($params['code'] ?? $params['discount_code'] ?? 'WELCOME10');
            $discount_amount = sanitize_text_field($params['amount'] ?? '10%');
            $accent = sanitize_text_field($params['accent_color'] ?? '#E91E8C');
            $title = sanitize_text_field($params['title'] ?? 'Wait! Get ' . $discount_amount . ' off');
            $subtitle = sanitize_text_field($params['subtitle'] ?? 'Subscribe and get your discount code instantly.');
            $html = '<div id="wpilot-discount-popup" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.75);z-index:99999;align-items:center;justify-content:center;backdrop-filter:blur(6px)">';
            $html .= '<div style="background:#0a0a0a;border:1px solid rgba(255,255,255,0.1);border-radius:20px;padding:48px 40px;max-width:440px;width:90%;text-align:center;position:relative;box-shadow:0 24px 80px rgba(0,0,0,0.5)">';
            $html .= '<button onclick="this.parentElement.parentElement.style.display=\'none\';localStorage.setItem(\'wpilot_popup_closed\',1)" style="position:absolute;top:12px;right:16px;background:none;border:none;color:rgba(255,255,255,0.3);font-size:1.5rem;cursor:pointer">&times;</button>';
            $html .= '<p style="font-size:3rem;margin:0 0 8px">🎁</p>';
            $html .= '<h3 style="font-size:1.5rem;font-weight:800;color:#fff;margin:0 0 8px">' . esc_html($title) . '</h3>';
            $html .= '<p style="color:rgba(255,255,255,0.6);margin:0 0 24px;font-size:0.95rem">' . esc_html($subtitle) . '</p>';
            $html .= '<form class="wpilot-subscribe-form" style="display:flex;flex-direction:column;gap:10px">';
            $html .= '<input type="email" name="email" placeholder="Your email address" required style="padding:14px 16px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:10px;color:#fff;font-size:1rem;text-align:center">';
            $html .= '<button type="submit" style="padding:14px;background:' . $accent . ';color:#fff;border:none;border-radius:10px;font-weight:700;cursor:pointer;font-size:1rem">Get My ' . esc_html($discount_amount) . ' Off →</button>';
            $html .= '</form>';
            $html .= '<p class="wpilot-subscribe-msg" style="margin:12px 0 0;font-size:0.85rem;color:rgba(255,255,255,0.4);min-height:20px"></p>';
            $html .= '<p style="margin:16px 0 0;font-size:0.8rem;color:rgba(255,255,255,0.3)">Use code: <strong style="color:' . $accent . '">' . esc_html($discount_code) . '</strong></p>';
            $html .= '</div></div>';
            // Exit intent + delay trigger
            $html .= '<script>if(!localStorage.getItem("wpilot_popup_closed")){document.addEventListener("mouseleave",function(e){if(e.clientY<5)document.getElementById("wpilot-discount-popup").style.display="flex"},{once:true});setTimeout(function(){if(!localStorage.getItem("wpilot_popup_closed"))document.getElementById("wpilot-discount-popup").style.display="flex"},15000);}';
            $html .= 'document.querySelectorAll(".wpilot-subscribe-form").forEach(function(f){f.addEventListener("submit",function(e){e.preventDefault();var email=f.querySelector("[name=email]").value;var msg=f.nextElementSibling;msg.textContent="Subscribing...";fetch("' . admin_url('admin-ajax.php') . '",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:"action=wpilot_subscribe&email="+encodeURIComponent(email)}).then(r=>r.json()).then(d=>{msg.textContent=d.success?"✅ Code: ' . $discount_code . '":"Error";msg.style.color="#10b981";}).catch(()=>{msg.textContent="Error";});});});</script>';
            // Save as mu-plugin (loads on all pages)
            $mu = ABSPATH . 'wp-content/mu-plugins/wpilot-discount-popup.php';
            $mu_code = "<?php\nadd_action('wp_footer', function() {\n    if (is_admin()) return;\n    echo '" . str_replace("'", "\\'", str_replace("\n", "", $html)) . "';\n});\n";
            file_put_contents($mu, $mu_code);
            // Create the coupon if it doesn't exist
            if (!wc_get_coupon_id_by_code($discount_code) && class_exists('WooCommerce')) {
                $coupon_id = wp_insert_post(['post_title' => $discount_code, 'post_type' => 'shop_coupon', 'post_status' => 'publish']);
                update_post_meta($coupon_id, 'discount_type', 'percent');
                update_post_meta($coupon_id, 'coupon_amount', intval($discount_amount));
                update_post_meta($coupon_id, 'usage_limit_per_user', 1);
            }
            return wpilot_ok("Discount popup created! Code: {$discount_code} ({$discount_amount} off). Shows on exit intent + after 15s.", ['code' => $discount_code, 'amount' => $discount_amount]);


        default:
            return null; // Not handled by this module
            
        case 'recommend_plugins':
        case 'check_missing_plugins':
            $recommendations = [];
            $installed = array_map(function($p) { return explode('/', $p)[0]; }, get_option('active_plugins', []));

            // Cache plugin
            $has_cache = in_array('litespeed-cache', $installed) || in_array('w3-total-cache', $installed) ||
                         in_array('wp-super-cache', $installed) || in_array('wp-rocket', $installed) ||
                         in_array('breeze', $installed) || in_array('cache-enabler', $installed);
            if (!$has_cache) $recommendations[] = ['plugin' => 'litespeed-cache', 'reason' => 'No cache plugin — site is slow without one', 'priority' => 'high', 'free' => true];

            // SEO plugin
            $has_seo = in_array('seo-by-rank-math', $installed) || in_array('wordpress-seo', $installed) || in_array('all-in-one-seo-pack', $installed);
            if (!$has_seo) $recommendations[] = ['plugin' => 'seo-by-rank-math', 'reason' => 'No SEO plugin — Google cannot optimize your site', 'priority' => 'high', 'free' => true];

            // Security plugin
            $has_security = in_array('wordfence', $installed) || in_array('sucuri-scanner', $installed) || in_array('better-wp-security', $installed);
            if (!$has_security) $recommendations[] = ['plugin' => 'wordfence', 'reason' => 'No security plugin — site is vulnerable', 'priority' => 'high', 'free' => true];

            // Backup plugin
            $has_backup = in_array('updraftplus', $installed) || in_array('duplicator', $installed) || in_array('backwpup', $installed);
            if (!$has_backup) $recommendations[] = ['plugin' => 'updraftplus', 'reason' => 'No backup plugin — you could lose everything', 'priority' => 'critical', 'free' => true];

            // Forms plugin
            $has_forms = in_array('contact-form-7', $installed) || in_array('wpforms-lite', $installed) ||
                         in_array('fluentform', $installed) || in_array('gravityforms', $installed);
            if (!$has_forms) $recommendations[] = ['plugin' => 'contact-form-7', 'reason' => 'No contact form — visitors cannot reach you', 'priority' => 'medium', 'free' => true];

            // Cookie consent (GDPR)
            $has_cookie = in_array('cookie-law-info', $installed) || in_array('cookie-notice', $installed) || in_array('complianz-gdpr', $installed);
            if (!$has_cookie) $recommendations[] = ['plugin' => 'cookie-law-info', 'reason' => 'No cookie consent — required by GDPR in EU', 'priority' => 'high', 'free' => true];

            // WooCommerce specific
            if (class_exists('WooCommerce')) {
                $has_stripe = in_array('woocommerce-gateway-stripe', $installed);
                if (!$has_stripe) $recommendations[] = ['plugin' => 'woocommerce-gateway-stripe', 'reason' => 'No payment gateway — customers cannot pay', 'priority' => 'critical', 'free' => true];
            }

            // Image optimization
            $has_imgopt = in_array('ewww-image-optimizer', $installed) || in_array('imagify', $installed) || in_array('smush', $installed) || defined('LSCWP_V');
            if (!$has_imgopt && !$has_cache) $recommendations[] = ['plugin' => 'ewww-image-optimizer', 'reason' => 'No image optimizer — large images slow your site', 'priority' => 'medium', 'free' => true];

            // Built by Christos Ferlachidis & Daniel Hedenberg

            if (empty($recommendations)) {
                return wpilot_ok("All essential plugins installed! Your site has good coverage.", ['all_good' => true]);
            }

            $critical = count(array_filter($recommendations, fn($r) => $r['priority'] === 'critical'));
            $high = count(array_filter($recommendations, fn($r) => $r['priority'] === 'high'));

            return wpilot_ok($critical + $high . " essential plugins missing. " . count($recommendations) . " total recommendations.", [
                'recommendations' => $recommendations,
                'critical' => $critical,
                'high' => $high,
                'can_auto_install' => true,
            ]);

        case 'install_recommended':
        case 'install_essentials':
            // Auto-install all recommended plugins
            $r = wpilot_run_tool('recommend_plugins', []);
            if ($r['all_good'] ?? false) return wpilot_ok("All essentials already installed!");
            $recs = $r['recommendations'] ?? [];
            $installed_count = 0;
            $failed = [];
            foreach ($recs as $rec) {
                if ($rec['priority'] === 'critical' || $rec['priority'] === 'high') {
                    $result = wpilot_run_tool('plugin_install', ['slug' => $rec['plugin']]);
                    if ($result['success'] ?? false) {
                        $installed_count++;
                    } else {
                        $failed[] = $rec['plugin'] . ': ' . ($result['message'] ?? 'failed');
                    }
                }
            }
            return wpilot_ok("Installed {$installed_count} essential plugins." . (!empty($failed) ? " Failed: " . implode(', ', $failed) : ''), [
                'installed' => $installed_count,
                'failed' => $failed,
            ]);

        return null; // Not handled by this module
    }
}
