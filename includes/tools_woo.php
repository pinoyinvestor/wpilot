<?php
if (!defined('ABSPATH')) exit;

/**
 * WPilot Woo Tools Module
 * Contains 63 tool cases for woo operations.
 */
function wpilot_run_woo_tools($tool, $params = []) {
    switch ($tool) {

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
// Built by Christos Ferlachidis & Daniel Hedenberg
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

        default:
            return null; // Not handled by this module
    }
}
