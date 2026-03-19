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
 * WPilot Advanced WooCommerce Tools Module
 * Contains 12 tool cases for advanced woo features:
 *   Product: filter, wishlist, quick_view, compare, size_guide
 *   Order:   tracking, invoice, review_request
 *   Bulk:    bulk_update, import_products
 *   Config:  conditional_shipping, tax_setup
 */
function wpilot_run_woo_advanced_tools($tool, $params = []) {
    switch ($tool) {

        // ═══════════════════════════════════════════════════════════
        //  1. PRODUCT FILTER — AJAX sidebar with price/color/size/category/brand
        // ═══════════════════════════════════════════════════════════
        case 'woo_add_product_filter':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $config = [
                'price_range'  => !empty($params['price_range'])  ? true : true,
                'colors'       => $params['colors']  ?? ['#000000','#FFFFFF','#FF0000','#0000FF','#00FF00','#FFA500','#800080','#FFC0CB'],
                'sizes'        => $params['sizes']   ?? ['XS','S','M','L','XL','XXL'],
                'categories'   => !isset($params['categories']) || $params['categories'] !== false,
                'brands'       => $params['brands']  ?? [],
                'color_labels' => $params['color_labels'] ?? ['Black','White','Red','Blue','Green','Orange','Purple','Pink'],
            ];

            update_option('wpilot_woo_filter_config', $config);

            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

            $colors_json   = wp_json_encode($config['colors']);
            $labels_json   = wp_json_encode($config['color_labels']);
            $sizes_json    = wp_json_encode($config['sizes']);
            $brands_json   = wp_json_encode($config['brands']);

            $php = <<<'FILTER_PHP'
<?php
/**
 * WPilot AJAX Product Filter
 * Adds filterable sidebar to WooCommerce shop pages
 */
add_action('wp_enqueue_scripts', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;

    $config = get_option('wpilot_woo_filter_config', []);
    $currency = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$';

    global $wpdb;
    $prices = $wpdb->get_row("SELECT MIN(CAST(meta_value AS DECIMAL(10,2))) as min_price, MAX(CAST(meta_value AS DECIMAL(10,2))) as max_price FROM {$wpdb->postmeta} WHERE meta_key = '_price' AND meta_value > 0");
    $min_price = $prices ? floor($prices->min_price) : 0;
    $max_price = $prices ? ceil($prices->max_price) : 10000;

    $cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
    $cat_data = [];
    if (!is_wp_error($cats)) {
        foreach ($cats as $c) {
            $cat_data[] = ['id' => $c->term_id, 'name' => $c->name, 'count' => $c->count];
        }
    }

    wp_enqueue_script('wpilot-woo-filter', '', [], false, true);
    wp_add_inline_script('wpilot-woo-filter', 'var wpilotFilter = ' . wp_json_encode([
        'ajax_url'     => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('wpilot_filter'),
        'colors'       => $config['colors'] ?? [],
        'color_labels' => $config['color_labels'] ?? [],
        'sizes'        => $config['sizes'] ?? [],
        'brands'       => $config['brands'] ?? [],
        'categories'   => $cat_data,
        'show_cats'    => !empty($config['categories']),
        'currency'     => $currency,
        'min_price'    => $min_price,
        'max_price'    => $max_price,
    ]) . ';');
});

add_action('woocommerce_before_shop_loop', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    echo '<div id="wpilot-filter-wrap"></div>';
}, 5);

add_action('wp_head', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    ?>
    <style>
    #wpilot-filter-wrap {
        background: var(--wp-bg, #fff);
        border: 1px solid var(--wp-border, #e0e0e0);
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 24px;
    }
    .wpf-section { margin-bottom: 18px; }
    .wpf-section h4 {
        font-size: 14px;
        font-weight: 600;
        color: var(--wp-text, #333);
        margin: 0 0 10px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .wpf-range-wrap { padding: 0 4px; }
    .wpf-range-track {
        position: relative;
        height: 6px;
        background: var(--wp-border, #ddd);
        border-radius: 3px;
        margin: 14px 0;
        cursor: pointer;
    }
    .wpf-range-fill {
        position: absolute;
        height: 100%;
        background: var(--wp-primary, #5B8DEF);
        border-radius: 3px;
    }
    .wpf-range-thumb {
        position: absolute;
        top: 50%;
        width: 18px;
        height: 18px;
        background: var(--wp-primary, #5B8DEF);
        border: 2px solid #fff;
        border-radius: 50%;
        transform: translate(-50%, -50%);
        cursor: grab;
        box-shadow: 0 1px 4px rgba(0,0,0,.2);
        z-index: 2;
    }
    .wpf-range-thumb:active { cursor: grabbing; }
    .wpf-range-values {
        display: flex;
        justify-content: space-between;
        font-size: 13px;
        color: var(--wp-text-muted, #777);
    }
    .wpf-colors { display: flex; flex-wrap: wrap; gap: 8px; }
    .wpf-color-btn {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        border: 2px solid transparent;
        cursor: pointer;
        transition: border-color .2s, transform .2s;
        position: relative;
    }
    .wpf-color-btn:hover { transform: scale(1.15); }
    .wpf-color-btn.active { border-color: var(--wp-primary, #5B8DEF); transform: scale(1.15); }
    .wpf-color-btn[title]::after {
        content: attr(title);
        position: absolute;
        bottom: -20px;
        left: 50%;
        transform: translateX(-50%);
        font-size: 10px;
        white-space: nowrap;
        opacity: 0;
        transition: opacity .2s;
        color: var(--wp-text-muted, #777);
    }
    .wpf-color-btn:hover::after { opacity: 1; }
    .wpf-sizes { display: flex; flex-wrap: wrap; gap: 6px; }
    .wpf-size-btn {
        padding: 5px 14px;
        border: 1px solid var(--wp-border, #ddd);
        border-radius: 4px;
        background: var(--wp-bg, #fff);
        color: var(--wp-text, #333);
        font-size: 13px;
        cursor: pointer;
        transition: all .2s;
    }
    .wpf-size-btn:hover, .wpf-size-btn.active {
        background: var(--wp-primary, #5B8DEF);
        color: #fff;
        border-color: var(--wp-primary, #5B8DEF);
    }
    .wpf-cats label {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 4px 0;
        font-size: 13px;
        color: var(--wp-text, #333);
        cursor: pointer;
    }
    .wpf-cats input[type="checkbox"] { accent-color: var(--wp-primary, #5B8DEF); }
    .wpf-cat-count {
        margin-left: auto;
        font-size: 11px;
        color: var(--wp-text-muted, #999);
        background: var(--wp-bg-alt, #f5f5f5);
        padding: 1px 6px;
        border-radius: 10px;
    }
    .wpf-brand-select {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid var(--wp-border, #ddd);
        border-radius: 4px;
        font-size: 13px;
        background: var(--wp-bg, #fff);
        color: var(--wp-text, #333);
    }
    .wpf-actions {
        display: flex;
        gap: 8px;
        margin-top: 16px;
    }
    .wpf-apply-btn {
        flex: 1;
        padding: 10px;
        background: var(--wp-primary, #5B8DEF);
        color: #fff;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity .2s;
    }
    .wpf-apply-btn:hover { opacity: .85; }
    .wpf-clear-btn {
        padding: 10px 16px;
        background: transparent;
        color: var(--wp-text-muted, #777);
        border: 1px solid var(--wp-border, #ddd);
        border-radius: 6px;
        font-size: 13px;
        cursor: pointer;
    }
    .wpf-loading { opacity: .5; pointer-events: none; }
    </style>
    <?php
});

add_action('wp_footer', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    ?>
    <script>
    (function(){
        var F = window.wpilotFilter;
        if (!F) return;
        var wrap = document.getElementById('wpilot-filter-wrap');
        if (!wrap) return;

        var state = { min: F.min_price, max: F.max_price, colors: [], sizes: [], cats: [], brand: '' };
        var dragging = null;

        function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

        function buildUI() {
            var frag = document.createDocumentFragment();

            // Price range section
            var priceSection = document.createElement('div');
            priceSection.className = 'wpf-section';
            priceSection.appendChild(Object.assign(document.createElement('h4'), {textContent: 'Price'}));
            var rangeWrap = document.createElement('div');
            rangeWrap.className = 'wpf-range-wrap';
            var track = document.createElement('div');
            track.className = 'wpf-range-track';
            track.id = 'wpf-track';
            var fill = document.createElement('div');
            fill.className = 'wpf-range-fill';
            fill.id = 'wpf-fill';
            track.appendChild(fill);
            var minThumb = document.createElement('div');
            minThumb.className = 'wpf-range-thumb';
            minThumb.id = 'wpf-min-thumb';
            minThumb.dataset.type = 'min';
            var maxThumb = document.createElement('div');
            maxThumb.className = 'wpf-range-thumb';
            maxThumb.id = 'wpf-max-thumb';
            maxThumb.dataset.type = 'max';
            track.appendChild(minThumb);
            track.appendChild(maxThumb);
            rangeWrap.appendChild(track);
            var vals = document.createElement('div');
            vals.className = 'wpf-range-values';
            var minVal = document.createElement('span');
            minVal.id = 'wpf-min-val';
            var maxVal = document.createElement('span');
            maxVal.id = 'wpf-max-val';
            vals.appendChild(minVal);
            vals.appendChild(maxVal);
            rangeWrap.appendChild(vals);
            priceSection.appendChild(rangeWrap);
            frag.appendChild(priceSection);

            // Colors section
            if (F.colors && F.colors.length) {
                var colorSection = document.createElement('div');
                colorSection.className = 'wpf-section';
                colorSection.appendChild(Object.assign(document.createElement('h4'), {textContent: 'Color'}));
                var colorWrap = document.createElement('div');
                colorWrap.className = 'wpf-colors';
                F.colors.forEach(function(c, i) {
                    var btn = document.createElement('div');
                    btn.className = 'wpf-color-btn';
                    btn.dataset.color = c;
                    btn.title = (F.color_labels && F.color_labels[i]) || '';
                    btn.style.background = c;
                    if (c === '#FFFFFF' || c === '#fff') btn.style.boxShadow = 'inset 0 0 0 1px #ddd';
                    colorWrap.appendChild(btn);
                });
                colorSection.appendChild(colorWrap);
                frag.appendChild(colorSection);
            }

            // Sizes section
            if (F.sizes && F.sizes.length) {
                var sizeSection = document.createElement('div');
                sizeSection.className = 'wpf-section';
                sizeSection.appendChild(Object.assign(document.createElement('h4'), {textContent: 'Size'}));
                var sizeWrap = document.createElement('div');
                sizeWrap.className = 'wpf-sizes';
                F.sizes.forEach(function(s) {
                    var btn = document.createElement('div');
                    btn.className = 'wpf-size-btn';
                    btn.dataset.size = s;
                    btn.textContent = s;
                    sizeWrap.appendChild(btn);
                });
                sizeSection.appendChild(sizeWrap);
                frag.appendChild(sizeSection);
            }

            // Categories section
            if (F.show_cats && F.categories && F.categories.length) {
                var catSection = document.createElement('div');
                catSection.className = 'wpf-section';
                catSection.appendChild(Object.assign(document.createElement('h4'), {textContent: 'Category'}));
                var catWrap = document.createElement('div');
                catWrap.className = 'wpf-cats';
                F.categories.forEach(function(c) {
                    var label = document.createElement('label');
                    var cb = document.createElement('input');
                    cb.type = 'checkbox';
                    cb.value = c.id;
                    label.appendChild(cb);
                    label.appendChild(document.createTextNode(c.name));
                    var cnt = document.createElement('span');
                    cnt.className = 'wpf-cat-count';
                    cnt.textContent = c.count;
                    label.appendChild(cnt);
                    catWrap.appendChild(label);
                });
                catSection.appendChild(catWrap);
                frag.appendChild(catSection);
            }

            // Brands section
            if (F.brands && F.brands.length) {
                var brandSection = document.createElement('div');
                brandSection.className = 'wpf-section';
                brandSection.appendChild(Object.assign(document.createElement('h4'), {textContent: 'Brand'}));
                var sel = document.createElement('select');
                sel.className = 'wpf-brand-select';
                sel.id = 'wpf-brand';
                var defOpt = document.createElement('option');
                defOpt.value = '';
                defOpt.textContent = 'All brands';
                sel.appendChild(defOpt);
                F.brands.forEach(function(b) {
                    var opt = document.createElement('option');
                    opt.value = b;
                    opt.textContent = b;
                    sel.appendChild(opt);
                });
                brandSection.appendChild(sel);
                frag.appendChild(brandSection);
            }

            // Actions
            var actions = document.createElement('div');
            actions.className = 'wpf-actions';
            var applyBtn = document.createElement('button');
            applyBtn.className = 'wpf-apply-btn';
            applyBtn.id = 'wpf-apply';
            applyBtn.textContent = 'Apply Filters';
            var clearBtn = document.createElement('button');
            clearBtn.className = 'wpf-clear-btn';
            clearBtn.id = 'wpf-clear';
            clearBtn.textContent = 'Clear';
            actions.appendChild(applyBtn);
            actions.appendChild(clearBtn);
            frag.appendChild(actions);

            wrap.appendChild(frag);
            updateSliderUI();
            bindEvents();
        }

        function updateSliderUI() {
            var track = document.getElementById('wpf-track');
            var fill = document.getElementById('wpf-fill');
            var minT = document.getElementById('wpf-min-thumb');
            var maxT = document.getElementById('wpf-max-thumb');
            if (!track) return;
            var range = F.max_price - F.min_price;
            var minPct = ((state.min - F.min_price) / range) * 100;
            var maxPct = ((state.max - F.min_price) / range) * 100;
            fill.style.left = minPct + '%';
            fill.style.width = (maxPct - minPct) + '%';
            minT.style.left = minPct + '%';
            maxT.style.left = maxPct + '%';
            document.getElementById('wpf-min-val').textContent = F.currency + state.min;
            document.getElementById('wpf-max-val').textContent = F.currency + state.max;
        }

        function bindEvents() {
            var thumbs = wrap.querySelectorAll('.wpf-range-thumb');
            thumbs.forEach(function(t) {
                t.addEventListener('mousedown', function(e) { dragging = t.dataset.type; e.preventDefault(); });
                t.addEventListener('touchstart', function(e) { dragging = t.dataset.type; e.preventDefault(); }, {passive:false});
            });
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('touchmove', onDrag, {passive:false});
            document.addEventListener('mouseup', function() { dragging = null; });
            document.addEventListener('touchend', function() { dragging = null; });

            wrap.querySelectorAll('.wpf-color-btn').forEach(function(b) {
                b.addEventListener('click', function() {
                    b.classList.toggle('active');
                    var c = b.dataset.color;
                    var i = state.colors.indexOf(c);
                    if (i > -1) state.colors.splice(i, 1); else state.colors.push(c);
                });
            });

            wrap.querySelectorAll('.wpf-size-btn').forEach(function(b) {
                b.addEventListener('click', function() {
                    b.classList.toggle('active');
                    var s = b.dataset.size;
                    var i = state.sizes.indexOf(s);
                    if (i > -1) state.sizes.splice(i, 1); else state.sizes.push(s);
                });
            });

            wrap.querySelectorAll('.wpf-cats input').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var v = parseInt(cb.value);
                    var i = state.cats.indexOf(v);
                    if (cb.checked && i === -1) state.cats.push(v);
                    if (!cb.checked && i > -1) state.cats.splice(i, 1);
                });
            });

            var brandSel = document.getElementById('wpf-brand');
            if (brandSel) brandSel.addEventListener('change', function() { state.brand = this.value; });

            document.getElementById('wpf-apply').addEventListener('click', applyFilters);
            document.getElementById('wpf-clear').addEventListener('click', clearFilters);
        }

        function onDrag(e) {
            if (!dragging) return;
            e.preventDefault();
            var track = document.getElementById('wpf-track');
            var rect = track.getBoundingClientRect();
            var clientX = e.touches ? e.touches[0].clientX : e.clientX;
            var pct = Math.max(0, Math.min(1, (clientX - rect.left) / rect.width));
            var val = Math.round(F.min_price + pct * (F.max_price - F.min_price));
            if (dragging === 'min') { state.min = Math.min(val, state.max - 1); }
            else { state.max = Math.max(val, state.min + 1); }
            updateSliderUI();
        }

        function applyFilters() {
            var grid = document.querySelector('.products');
            if (grid) grid.classList.add('wpf-loading');
            var fd = new FormData();
            fd.append('action', 'wpilot_product_filter');
            fd.append('nonce', F.nonce);
            fd.append('min_price', state.min);
            fd.append('max_price', state.max);
            fd.append('colors', JSON.stringify(state.colors));
            fd.append('sizes', JSON.stringify(state.sizes));
            fd.append('categories', JSON.stringify(state.cats));
            fd.append('brand', state.brand);
            fetch(F.ajax_url, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success && grid) {
                        while (grid.firstChild) grid.removeChild(grid.firstChild);
                        var temp = document.createElement('div');
                        temp.innerHTML = d.data.html;
                        while (temp.firstChild) grid.appendChild(temp.firstChild);
                    }
                    if (grid) grid.classList.remove('wpf-loading');
                })
                .catch(function() { if (grid) grid.classList.remove('wpf-loading'); });
        }

        function clearFilters() {
            state = { min: F.min_price, max: F.max_price, colors: [], sizes: [], cats: [], brand: '' };
            wrap.querySelectorAll('.active').forEach(function(el) { el.classList.remove('active'); });
            wrap.querySelectorAll('.wpf-cats input').forEach(function(cb) { cb.checked = false; });
            var brandSel = document.getElementById('wpf-brand');
            if (brandSel) brandSel.value = '';
            updateSliderUI();
            applyFilters();
        }

        buildUI();
    })();
    </script>
    <?php
});

add_action('wp_ajax_wpilot_product_filter', 'wpilot_ajax_product_filter');
add_action('wp_ajax_nopriv_wpilot_product_filter', 'wpilot_ajax_product_filter');
function wpilot_ajax_product_filter() {
    check_ajax_referer('wpilot_filter', 'nonce');
    $args = [
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => intval(get_option('posts_per_page', 12)),
    ];
    $meta = [];
    $min = floatval($_POST['min_price'] ?? 0);
    $max = floatval($_POST['max_price'] ?? 999999);
    if ($min > 0 || $max < 999999) {
        $meta[] = ['key' => '_price', 'value' => [$min, $max], 'type' => 'DECIMAL', 'compare' => 'BETWEEN'];
    }
    $colors = json_decode(stripslashes($_POST['colors'] ?? '[]'), true);
    if (!empty($colors) && is_array($colors)) {
        $meta[] = ['key' => '_product_color', 'value' => array_map('sanitize_hex_color', $colors), 'compare' => 'IN'];
    }
    $sizes = json_decode(stripslashes($_POST['sizes'] ?? '[]'), true);
    if (!empty($sizes) && is_array($sizes)) {
        $meta[] = ['key' => '_product_size', 'value' => array_map('sanitize_text_field', $sizes), 'compare' => 'IN'];
    }
    if (!empty($meta)) { $args['meta_query'] = $meta; }
    $cats = json_decode(stripslashes($_POST['categories'] ?? '[]'), true);
    if (!empty($cats) && is_array($cats)) {
        $args['tax_query'] = [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array_map('intval', $cats)]];
    }
    $brand = sanitize_text_field($_POST['brand'] ?? '');
    if ($brand) {
        $args['tax_query'] = $args['tax_query'] ?? [];
        $args['tax_query'][] = ['taxonomy' => 'product_brand', 'field' => 'name', 'terms' => $brand];
    }
    $q = new WP_Query($args);
    ob_start();
    if ($q->have_posts()) {
        while ($q->have_posts()) { $q->the_post(); wc_get_template_part('content', 'product'); }
    } else {
        echo '<li class="product"><p style="padding:40px;text-align:center;color:var(--wp-text-muted,#999)">No products match your filters.</p></li>';
    }
    wp_reset_postdata();
    $html = ob_get_clean();
    wp_send_json_success(['html' => $html, 'count' => $q->found_posts]);
}
FILTER_PHP;

            file_put_contents($mu_dir . '/wpilot-woo-filter.php', $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok('AJAX product filter installed with price slider, color swatches, size buttons, category checkboxes' . (!empty($config['brands']) ? ', brand dropdown' : '') . '.', [
                'mu_plugin' => 'wpilot-woo-filter.php',
                'config'    => $config,
            ]);

        // ═══════════════════════════════════════════════════════════
        //  2. WISHLIST — heart icon, user meta / cookie, AJAX
        // ═══════════════════════════════════════════════════════════
        case 'woo_create_wishlist':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $page_title = sanitize_text_field($params['page_title'] ?? 'Wishlist');
            $page_slug  = sanitize_title($params['page_slug'] ?? 'wishlist');

            $existing = get_page_by_path($page_slug);
            if (!$existing) {
                $page_id = wp_insert_post([
                    'post_title'   => $page_title,
                    'post_name'    => $page_slug,
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_content' => '<!-- WPilot Wishlist - rendered via shortcode -->[wpilot_wishlist]',
                ]);
            } else {
                $page_id = $existing->ID;
            }

            update_option('wpilot_wishlist_page', $page_id);

            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

            $php = <<<'WISHLIST_PHP'
<?php
/**
 * WPilot Wishlist — heart icon on products, AJAX add/remove
 */

add_shortcode('wpilot_wishlist', function() {
    $items = wpilot_get_wishlist();
    if (empty($items)) {
        return '<div class="wpwl-empty" style="text-align:center;padding:60px 20px;color:var(--wp-text-muted,#999)"><p style="font-size:48px;margin:0">&#9825;</p><p>Your wishlist is empty.</p><p><a href="' . esc_url(wc_get_page_permalink('shop')) . '" style="color:var(--wp-primary,#5B8DEF)">Continue Shopping</a></p></div>';
    }
    $html = '<div class="wpwl-grid">';
    foreach ($items as $pid) {
        $product = wc_get_product($pid);
        if (!$product) continue;
        $img   = $product->get_image('woocommerce_thumbnail');
        $name  = esc_html($product->get_name());
        $price = $product->get_price_html();
        $link  = esc_url($product->get_permalink());
        $html .= '<div class="wpwl-item" data-product-id="' . intval($pid) . '">';
        $html .= '<a href="' . $link . '" class="wpwl-img">' . $img . '</a>';
        $html .= '<div class="wpwl-info"><a href="' . $link . '" class="wpwl-name">' . $name . '</a>';
        $html .= '<div class="wpwl-price">' . $price . '</div>';
        $html .= '<div class="wpwl-actions">';
        $html .= '<a href="' . esc_url($product->add_to_cart_url()) . '" class="wpwl-cart-btn">Add to Cart</a>';
        $html .= '<button class="wpwl-remove-btn" data-product-id="' . intval($pid) . '">Remove</button>';
        $html .= '</div></div></div>';
    }
    $html .= '</div>';
    return $html;
});

function wpilot_get_wishlist() {
    if (is_user_logged_in()) {
        return get_user_meta(get_current_user_id(), '_wpilot_wishlist', true) ?: [];
    }
    $cookie = isset($_COOKIE['wpilot_wishlist']) ? json_decode(stripslashes($_COOKIE['wpilot_wishlist']), true) : [];
    return is_array($cookie) ? array_map('intval', $cookie) : [];
}

function wpilot_save_wishlist($items) {
    $items = array_unique(array_map('intval', array_filter($items)));
    if (is_user_logged_in()) {
        update_user_meta(get_current_user_id(), '_wpilot_wishlist', $items);
    }
    setcookie('wpilot_wishlist', wp_json_encode($items), time() + (86400 * 90), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false);
}

add_action('wp_login', function($user_login, $user) {
    $cookie = isset($_COOKIE['wpilot_wishlist']) ? json_decode(stripslashes($_COOKIE['wpilot_wishlist']), true) : [];
    if (!empty($cookie) && is_array($cookie)) {
        $existing = get_user_meta($user->ID, '_wpilot_wishlist', true) ?: [];
        $merged = array_unique(array_merge($existing, array_map('intval', $cookie)));
        update_user_meta($user->ID, '_wpilot_wishlist', $merged);
    }
}, 10, 2);

add_action('woocommerce_before_shop_loop_item', function() {
    global $product;
    $items = wpilot_get_wishlist();
    $active = in_array($product->get_id(), $items) ? ' active' : '';
    echo '<button class="wpwl-heart' . esc_attr($active) . '" data-product-id="' . intval($product->get_id()) . '" title="Wishlist">&#9829;</button>';
}, 5);

add_action('woocommerce_single_product_summary', function() {
    global $product;
    $items = wpilot_get_wishlist();
    $active = in_array($product->get_id(), $items) ? ' active' : '';
    echo '<button class="wpwl-heart wpwl-heart-single' . esc_attr($active) . '" data-product-id="' . intval($product->get_id()) . '">&#9829; <span>' . ($active ? 'In Wishlist' : 'Add to Wishlist') . '</span></button>';
}, 35);

add_action('wp_enqueue_scripts', function() {
    if (!is_woocommerce() && !is_page(get_option('wpilot_wishlist_page'))) return;
    wp_enqueue_script('wpilot-wishlist', '', [], false, true);
    wp_add_inline_script('wpilot-wishlist', 'var wpilotWL = ' . wp_json_encode([
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wpilot_wishlist'),
    ]) . ';');
});

add_action('wp_head', function() {
    if (!is_woocommerce() && !is_page(get_option('wpilot_wishlist_page'))) return;
    ?>
    <style>
    .wpwl-heart {
        position: absolute;
        top: 10px;
        right: 10px;
        z-index: 5;
        background: rgba(255,255,255,.9);
        border: none;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        font-size: 18px;
        color: #ccc;
        cursor: pointer;
        transition: all .3s;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
    }
    .wpwl-heart:hover { color: #e74c3c; transform: scale(1.15); }
    .wpwl-heart.active { color: #e74c3c; }
    .wpwl-heart-single {
        position: relative;
        top: auto;
        right: auto;
        width: auto;
        height: auto;
        border-radius: 6px;
        padding: 8px 16px;
        font-size: 14px;
        gap: 6px;
        background: transparent;
        border: 1px solid var(--wp-border, #ddd);
    }
    .wpwl-heart-single span { font-size: 13px; }
    .wpwl-heart-single.active { border-color: #e74c3c; }
    li.product { position: relative; }
    .wpwl-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 24px;
    }
    .wpwl-item {
        border: 1px solid var(--wp-border, #eee);
        border-radius: 8px;
        overflow: hidden;
        background: var(--wp-bg, #fff);
        transition: box-shadow .2s;
    }
    .wpwl-item:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
    .wpwl-img img { width: 100%; display: block; }
    .wpwl-info { padding: 16px; }
    .wpwl-name { font-weight: 600; color: var(--wp-text, #333); text-decoration: none; display: block; margin-bottom: 6px; }
    .wpwl-price { font-size: 15px; color: var(--wp-primary, #5B8DEF); margin-bottom: 12px; }
    .wpwl-actions { display: flex; gap: 8px; }
    .wpwl-cart-btn {
        flex: 1;
        text-align: center;
        padding: 8px;
        background: var(--wp-primary, #5B8DEF);
        color: #fff;
        border-radius: 4px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
    }
    .wpwl-remove-btn {
        padding: 8px 12px;
        background: transparent;
        border: 1px solid var(--wp-border, #ddd);
        border-radius: 4px;
        color: var(--wp-text-muted, #999);
        cursor: pointer;
        font-size: 13px;
    }
    .wpwl-remove-btn:hover { border-color: #e74c3c; color: #e74c3c; }
    </style>
    <?php
});

add_action('wp_footer', function() {
    if (!is_woocommerce() && !is_page(get_option('wpilot_wishlist_page'))) return;
    ?>
    <script>
    (function(){
        var W = window.wpilotWL;
        if (!W) return;
        function toggle(pid) {
            var fd = new FormData();
            fd.append('action', 'wpilot_wishlist_toggle');
            fd.append('nonce', W.nonce);
            fd.append('product_id', pid);
            fetch(W.ajax_url, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (!d.success) return;
                    document.querySelectorAll('.wpwl-heart[data-product-id="' + pid + '"]').forEach(function(h) {
                        if (d.data.in_wishlist) {
                            h.classList.add('active');
                            var s = h.querySelector('span');
                            if (s) s.textContent = 'In Wishlist';
                        } else {
                            h.classList.remove('active');
                            var s = h.querySelector('span');
                            if (s) s.textContent = 'Add to Wishlist';
                        }
                    });
                    if (!d.data.in_wishlist) {
                        var card = document.querySelector('.wpwl-item[data-product-id="' + pid + '"]');
                        if (card) { card.style.transition = 'opacity .3s'; card.style.opacity = '0'; setTimeout(function() { card.remove(); }, 300); }
                    }
                });
        }
        document.addEventListener('click', function(e) {
            var heart = e.target.closest('.wpwl-heart');
            if (heart) { e.preventDefault(); e.stopPropagation(); toggle(heart.dataset.productId); return; }
            var rem = e.target.closest('.wpwl-remove-btn');
            if (rem) { toggle(rem.dataset.productId); }
        });
    })();
    </script>
    <?php
});

add_action('wp_ajax_wpilot_wishlist_toggle', 'wpilot_wishlist_toggle');
add_action('wp_ajax_nopriv_wpilot_wishlist_toggle', 'wpilot_wishlist_toggle');
function wpilot_wishlist_toggle() {
    check_ajax_referer('wpilot_wishlist', 'nonce');
    $pid = intval($_POST['product_id'] ?? 0);
    if (!$pid) wp_send_json_error('Invalid product');
    $items = wpilot_get_wishlist();
    $idx = array_search($pid, $items);
    if ($idx !== false) {
        array_splice($items, $idx, 1);
        $in = false;
    } else {
        $items[] = $pid;
        $in = true;
    }
    wpilot_save_wishlist($items);
    wp_send_json_success(['in_wishlist' => $in, 'count' => count($items)]);
}
WISHLIST_PHP;

            file_put_contents($mu_dir . '/wpilot-woo-wishlist.php', $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Wishlist installed — heart icon on products, wishlist page at <strong>/{$page_slug}</strong>.", [
                'mu_plugin' => 'wpilot-woo-wishlist.php',
                'page_id'   => $page_id,
                'page_url'  => get_permalink($page_id),
            ]);

        // ═══════════════════════════════════════════════════════════
        //  3. QUICK VIEW — modal on shop page, AJAX loaded
        // ═══════════════════════════════════════════════════════════
        case 'woo_create_quick_view':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

            $php = <<<'QUICKVIEW_PHP'
<?php
/**
 * WPilot Quick View — modal product preview on shop page
 */

add_action('woocommerce_after_shop_loop_item', function() {
    global $product;
    echo '<button class="wpqv-btn" data-product-id="' . intval($product->get_id()) . '">Quick View</button>';
}, 8);

add_action('wp_footer', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    echo '<div id="wpqv-overlay" class="wpqv-overlay" style="display:none"><div class="wpqv-modal"><button class="wpqv-close">&times;</button><div id="wpqv-content" class="wpqv-content"></div></div></div>';
});

add_action('wp_enqueue_scripts', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    wp_enqueue_script('wpilot-quickview', '', [], false, true);
    wp_add_inline_script('wpilot-quickview', 'var wpilotQV = ' . wp_json_encode([
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wpilot_qv'),
    ]) . ';');
});

add_action('wp_head', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    ?>
    <style>
    .wpqv-btn {
        display: block;
        width: 100%;
        padding: 8px;
        background: var(--wp-bg, #fff);
        color: var(--wp-primary, #5B8DEF);
        border: 1px solid var(--wp-primary, #5B8DEF);
        border-radius: 4px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all .2s;
        margin-top: 6px;
    }
    .wpqv-btn:hover { background: var(--wp-primary, #5B8DEF); color: #fff; }
    .wpqv-overlay {
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: rgba(0,0,0,.55);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        animation: wpqvFadeIn .2s;
    }
    @keyframes wpqvFadeIn { from { opacity:0; } to { opacity:1; } }
    .wpqv-modal {
        background: var(--wp-bg, #fff);
        border-radius: 12px;
        max-width: 800px;
        width: 100%;
        max-height: 90vh;
        overflow-y: auto;
        position: relative;
        box-shadow: 0 20px 60px rgba(0,0,0,.3);
        animation: wpqvSlideUp .25s;
    }
    @keyframes wpqvSlideUp { from { transform: translateY(30px); opacity:0; } to { transform: translateY(0); opacity:1; } }
    .wpqv-close {
        position: absolute;
        top: 12px;
        right: 16px;
        background: none;
        border: none;
        font-size: 28px;
        color: var(--wp-text-muted, #999);
        cursor: pointer;
        z-index: 2;
        line-height: 1;
    }
    .wpqv-close:hover { color: var(--wp-text, #333); }
    .wpqv-content { padding: 0; }
    .wpqv-inner {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0;
    }
    @media (max-width: 600px) { .wpqv-inner { grid-template-columns: 1fr; } }
    .wpqv-img { background: var(--wp-bg-alt, #f7f7f7); }
    .wpqv-img img { width: 100%; display: block; border-radius: 12px 0 0 12px; object-fit: cover; min-height: 300px; }
    @media (max-width: 600px) { .wpqv-img img { border-radius: 12px 12px 0 0; min-height: auto; } }
    .wpqv-details { padding: 30px; display: flex; flex-direction: column; gap: 12px; }
    .wpqv-title { font-size: 22px; font-weight: 700; color: var(--wp-text, #333); margin: 0; }
    .wpqv-price { font-size: 20px; color: var(--wp-primary, #5B8DEF); font-weight: 600; }
    .wpqv-price del { color: var(--wp-text-muted, #999); font-weight: 400; font-size: 16px; }
    .wpqv-desc { font-size: 14px; color: var(--wp-text-muted, #666); line-height: 1.6; }
    .wpqv-meta { font-size: 12px; color: var(--wp-text-muted, #999); }
    .wpqv-add-cart {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 24px;
        background: var(--wp-primary, #5B8DEF);
        color: #fff;
        border: none;
        border-radius: 6px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: opacity .2s;
        margin-top: auto;
    }
    .wpqv-add-cart:hover { opacity: .85; color: #fff; }
    .wpqv-link {
        font-size: 13px;
        color: var(--wp-primary, #5B8DEF);
        text-decoration: none;
    }
    .wpqv-spinner {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 300px;
        color: var(--wp-text-muted, #999);
    }
    </style>
    <?php
});

add_action('wp_footer', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    ?>
    <script>
    (function(){
        var QV = window.wpilotQV;
        if (!QV) return;
        var overlay = document.getElementById('wpqv-overlay');
        var content = document.getElementById('wpqv-content');
        if (!overlay || !content) return;

        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.wpqv-btn');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                openQV(btn.dataset.productId);
                return;
            }
            if (e.target.classList.contains('wpqv-overlay') || e.target.classList.contains('wpqv-close')) {
                closeQV();
            }
        });

        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeQV(); });

        function openQV(pid) {
            overlay.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            content.textContent = 'Loading...';
            content.className = 'wpqv-content wpqv-spinner';
            var fd = new FormData();
            fd.append('action', 'wpilot_quick_view');
            fd.append('nonce', QV.nonce);
            fd.append('product_id', pid);
            fetch(QV.ajax_url, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    content.className = 'wpqv-content';
                    if (d.success) {
                        var temp = document.createElement('div');
                        temp.innerHTML = d.data.html;
                        while (content.firstChild) content.removeChild(content.firstChild);
                        while (temp.firstChild) content.appendChild(temp.firstChild);
                    } else {
                        content.textContent = 'Product not found.';
                        content.className = 'wpqv-content wpqv-spinner';
                    }
                });
        }

        function closeQV() {
            overlay.style.display = 'none';
            document.body.style.overflow = '';
        }
    })();
    </script>
    <?php
});

add_action('wp_ajax_wpilot_quick_view', 'wpilot_ajax_quick_view');
add_action('wp_ajax_nopriv_wpilot_quick_view', 'wpilot_ajax_quick_view');
function wpilot_ajax_quick_view() {
    check_ajax_referer('wpilot_qv', 'nonce');
    $pid = intval($_POST['product_id'] ?? 0);
    $product = wc_get_product($pid);
    if (!$product) wp_send_json_error('Product not found');
    $img = wp_get_attachment_url($product->get_image_id()) ?: wc_placeholder_img_src();
    $cats = wc_get_product_category_list($pid, ', ');
    $sku  = $product->get_sku();
    $html  = '<div class="wpqv-inner">';
    $html .= '<div class="wpqv-img"><img src="' . esc_url($img) . '" alt="' . esc_attr($product->get_name()) . '"></div>';
    $html .= '<div class="wpqv-details">';
    $html .= '<h2 class="wpqv-title">' . esc_html($product->get_name()) . '</h2>';
    $html .= '<div class="wpqv-price">' . $product->get_price_html() . '</div>';
    if ($product->get_short_description()) {
        $html .= '<div class="wpqv-desc">' . wp_kses_post($product->get_short_description()) . '</div>';
    }
    $html .= '<div class="wpqv-meta">';
    if ($sku) $html .= 'SKU: ' . esc_html($sku) . '<br>';
    if ($cats) $html .= 'Category: ' . $cats;
    $html .= '</div>';
    if ($product->is_purchasable() && $product->is_in_stock()) {
        $html .= '<a href="' . esc_url($product->add_to_cart_url()) . '" class="wpqv-add-cart">Add to Cart</a>';
    }
    $html .= '<a href="' . esc_url($product->get_permalink()) . '" class="wpqv-link">View Full Details &rarr;</a>';
    $html .= '</div></div>';
    wp_send_json_success(['html' => $html]);
}
QUICKVIEW_PHP;

            file_put_contents($mu_dir . '/wpilot-woo-quickview.php', $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok('Quick View modal installed — "Quick View" button on all product cards.', [
                'mu_plugin' => 'wpilot-woo-quickview.php',
            ]);

        // ═══════════════════════════════════════════════════════════
        //  4. PRODUCT COMPARE — side-by-side table, max 4
        // ═══════════════════════════════════════════════════════════
        case 'woo_product_compare':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $page_title = sanitize_text_field($params['page_title'] ?? 'Compare Products');
            $page_slug  = sanitize_title($params['page_slug'] ?? 'compare');
            $max        = min(intval($params['max_products'] ?? 4), 6);

            $existing = get_page_by_path($page_slug);
            if (!$existing) {
                $page_id = wp_insert_post([
                    'post_title'   => $page_title,
                    'post_name'    => $page_slug,
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_content' => '[wpilot_compare]',
                ]);
            } else {
                $page_id = $existing->ID;
            }

            update_option('wpilot_compare_page', $page_id);
            update_option('wpilot_compare_max', $max);

            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

            $php = <<<'COMPARE_PHP'
<?php
/**
 * WPilot Product Compare — checkbox on products, comparison table
 */

add_action('woocommerce_after_shop_loop_item', function() {
    global $product;
    echo '<label class="wpcmp-label"><input type="checkbox" class="wpcmp-cb" data-product-id="' . intval($product->get_id()) . '"> Compare</label>';
}, 9);

add_action('wp_footer', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag() && !is_page(get_option('wpilot_compare_page'))) return;
    $compare_url = get_permalink(get_option('wpilot_compare_page'));
    $max = intval(get_option('wpilot_compare_max', 4));
    echo '<div id="wpcmp-bar" class="wpcmp-bar" style="display:none">';
    echo '<div class="wpcmp-bar-inner"><span id="wpcmp-count">0</span> / ' . intval($max) . ' selected ';
    echo '<a href="' . esc_url($compare_url) . '" id="wpcmp-go" class="wpcmp-go-btn">Compare Now</a>';
    echo '<button class="wpcmp-clear-btn" id="wpcmp-clear-all">Clear</button></div></div>';
});

add_shortcode('wpilot_compare', function() {
    if (empty($_GET['ids'])) {
        return '<div style="text-align:center;padding:60px;color:var(--wp-text-muted,#999)"><p>No products selected for comparison.</p><p><a href="' . esc_url(wc_get_page_permalink('shop')) . '" style="color:var(--wp-primary,#5B8DEF)">Go to Shop</a></p></div>';
    }
    $ids = array_map('intval', explode(',', sanitize_text_field($_GET['ids'])));
    $ids = array_slice($ids, 0, intval(get_option('wpilot_compare_max', 4)));
    $products = [];
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if ($p) $products[] = $p;
    }
    if (empty($products)) return '<p>No valid products found.</p>';

    $html = '<div class="wpcmp-table-wrap"><table class="wpcmp-table"><thead><tr><th></th>';
    foreach ($products as $p) {
        $html .= '<th><a href="' . esc_url($p->get_permalink()) . '">' . esc_html($p->get_name()) . '</a></th>';
    }
    $html .= '</tr></thead><tbody>';

    $html .= '<tr><td class="wpcmp-row-label">Image</td>';
    foreach ($products as $p) { $html .= '<td>' . $p->get_image('woocommerce_thumbnail') . '</td>'; }
    $html .= '</tr>';

    $html .= '<tr><td class="wpcmp-row-label">Price</td>';
    foreach ($products as $p) { $html .= '<td>' . $p->get_price_html() . '</td>'; }
    $html .= '</tr>';

    $html .= '<tr><td class="wpcmp-row-label">Description</td>';
    foreach ($products as $p) { $html .= '<td>' . wp_kses_post($p->get_short_description() ?: '-') . '</td>'; }
    $html .= '</tr>';

    $html .= '<tr><td class="wpcmp-row-label">SKU</td>';
    foreach ($products as $p) { $html .= '<td>' . esc_html($p->get_sku() ?: '-') . '</td>'; }
    $html .= '</tr>';

    $html .= '<tr><td class="wpcmp-row-label">Stock</td>';
    foreach ($products as $p) {
        $stock = $p->is_in_stock() ? '<span style="color:#22c55e">In Stock</span>' : '<span style="color:#ef4444">Out of Stock</span>';
        if ($p->get_stock_quantity() !== null) $stock .= ' (' . intval($p->get_stock_quantity()) . ')';
        $html .= '<td>' . $stock . '</td>';
    }
    $html .= '</tr>';

    $html .= '<tr><td class="wpcmp-row-label">Weight</td>';
    foreach ($products as $p) { $html .= '<td>' . esc_html($p->get_weight() ? $p->get_weight() . ' ' . get_option('woocommerce_weight_unit') : '-') . '</td>'; }
    $html .= '</tr>';

    $html .= '<tr><td class="wpcmp-row-label">Dimensions</td>';
    foreach ($products as $p) { $html .= '<td>' . esc_html(wc_format_dimensions($p->get_dimensions(false)) ?: '-') . '</td>'; }
    $html .= '</tr>';

    $html .= '<tr><td class="wpcmp-row-label">Rating</td>';
    foreach ($products as $p) {
        $avg = floatval($p->get_average_rating());
        $cnt = intval($p->get_review_count());
        $stars = $avg > 0 ? str_repeat('&#9733;', round($avg)) . str_repeat('&#9734;', 5 - round($avg)) . " ({$cnt})" : '-';
        $html .= '<td>' . $stars . '</td>';
    }
    $html .= '</tr>';

    $html .= '<tr><td class="wpcmp-row-label">Categories</td>';
    foreach ($products as $p) { $html .= '<td>' . wc_get_product_category_list($p->get_id(), ', ') . '</td>'; }
    $html .= '</tr>';

    $html .= '<tr><td class="wpcmp-row-label"></td>';
    foreach ($products as $p) {
        if ($p->is_purchasable() && $p->is_in_stock()) {
            $html .= '<td><a href="' . esc_url($p->add_to_cart_url()) . '" class="wpcmp-cart-btn">Add to Cart</a></td>';
        } else {
            $html .= '<td>-</td>';
        }
    }
    $html .= '</tr>';

    // Dynamic product attributes
    $all_attrs = [];
    foreach ($products as $p) {
        foreach ($p->get_attributes() as $key => $attr) {
            $label = wc_attribute_label($key);
            if (!in_array($label, $all_attrs)) $all_attrs[] = $label;
        }
    }
    foreach ($all_attrs as $attr_label) {
        $html .= '<tr><td class="wpcmp-row-label">' . esc_html($attr_label) . '</td>';
        foreach ($products as $p) {
            $val = '-';
            foreach ($p->get_attributes() as $key => $attr) {
                if (wc_attribute_label($key) === $attr_label) {
                    if (is_object($attr) && method_exists($attr, 'get_options')) {
                        $opts = $attr->get_options();
                        $terms = [];
                        foreach ($opts as $o) {
                            $t = get_term($o);
                            $terms[] = is_wp_error($t) ? $o : $t->name;
                        }
                        $val = implode(', ', $terms);
                    } elseif (is_string($attr)) {
                        $val = $attr;
                    }
                }
            }
            $html .= '<td>' . esc_html($val) . '</td>';
        }
        $html .= '</tr>';
    }

    $html .= '</tbody></table></div>';
    return $html;
});

add_action('wp_head', function() {
    if (!is_woocommerce() && !is_page(get_option('wpilot_compare_page'))) return;
    ?>
    <style>
    .wpcmp-label { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--wp-text-muted, #777); cursor: pointer; margin-top: 4px; }
    .wpcmp-label input { accent-color: var(--wp-primary, #5B8DEF); }
    .wpcmp-bar {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        z-index: 9999;
        background: var(--wp-bg, #fff);
        border-top: 1px solid var(--wp-border, #ddd);
        box-shadow: 0 -4px 20px rgba(0,0,0,.1);
        padding: 14px 24px;
    }
    .wpcmp-bar-inner { display: flex; align-items: center; gap: 16px; max-width: 1200px; margin: 0 auto; font-size: 14px; color: var(--wp-text, #333); }
    .wpcmp-go-btn {
        padding: 8px 20px;
        background: var(--wp-primary, #5B8DEF);
        color: #fff;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
        font-size: 14px;
    }
    .wpcmp-clear-btn { background: transparent; border: 1px solid var(--wp-border, #ddd); padding: 8px 14px; border-radius: 6px; cursor: pointer; color: var(--wp-text-muted, #999); font-size: 13px; }
    .wpcmp-table-wrap { overflow-x: auto; }
    .wpcmp-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    .wpcmp-table th, .wpcmp-table td {
        padding: 14px 16px;
        border-bottom: 1px solid var(--wp-border, #eee);
        text-align: left;
        vertical-align: top;
    }
    .wpcmp-table th { font-weight: 600; color: var(--wp-primary, #5B8DEF); }
    .wpcmp-table th a { color: inherit; text-decoration: none; }
    td.wpcmp-row-label {
        font-weight: 600;
        color: var(--wp-text, #333);
        white-space: nowrap;
        font-size: 13px;
        width: 120px;
        min-width: 120px;
    }
    .wpcmp-table img { max-width: 150px; border-radius: 6px; }
    .wpcmp-cart-btn {
        display: inline-block;
        padding: 8px 18px;
        background: var(--wp-primary, #5B8DEF);
        color: #fff;
        border-radius: 4px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
    }
    </style>
    <?php
});

add_action('wp_footer', function() {
    if (!is_shop() && !is_product_category() && !is_product_tag()) return;
    $compare_url = get_permalink(get_option('wpilot_compare_page'));
    $max = intval(get_option('wpilot_compare_max', 4));
    ?>
    <script>
    (function(){
        var selected = JSON.parse(sessionStorage.getItem('wpcmp_ids') || '[]');
        var max = <?php echo intval($max); ?>;
        var bar = document.getElementById('wpcmp-bar');
        var countEl = document.getElementById('wpcmp-count');
        var goBtn = document.getElementById('wpcmp-go');
        var baseUrl = <?php echo wp_json_encode(esc_url($compare_url)); ?>;

        function updateUI() {
            if (bar) bar.style.display = selected.length > 0 ? '' : 'none';
            if (countEl) countEl.textContent = selected.length;
            if (goBtn) goBtn.href = baseUrl + (baseUrl.indexOf('?') > -1 ? '&' : '?') + 'ids=' + selected.join(',');
            document.querySelectorAll('.wpcmp-cb').forEach(function(cb) {
                cb.checked = selected.indexOf(parseInt(cb.dataset.productId)) > -1;
            });
            sessionStorage.setItem('wpcmp_ids', JSON.stringify(selected));
        }

        document.addEventListener('change', function(e) {
            if (!e.target.classList.contains('wpcmp-cb')) return;
            var pid = parseInt(e.target.dataset.productId);
            if (e.target.checked) {
                if (selected.length >= max) { e.target.checked = false; return; }
                if (selected.indexOf(pid) === -1) selected.push(pid);
            } else {
                selected = selected.filter(function(id) { return id !== pid; });
            }
            updateUI();
        });

        var clearBtn = document.getElementById('wpcmp-clear-all');
        if (clearBtn) clearBtn.addEventListener('click', function() { selected = []; updateUI(); });

        updateUI();
    })();
    </script>
    <?php
});
COMPARE_PHP;

            file_put_contents($mu_dir . '/wpilot-woo-compare.php', $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Product Compare installed — checkbox on products, compare page at <strong>/{$page_slug}</strong> (max {$max} products).", [
                'mu_plugin' => 'wpilot-woo-compare.php',
                'page_id'   => $page_id,
                'page_url'  => get_permalink($page_id),
            ]);

        // ═══════════════════════════════════════════════════════════
        //  5. SIZE GUIDE — modal popup with per-category size chart
        // ═══════════════════════════════════════════════════════════
        case 'woo_create_size_guide':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $charts = $params['charts'] ?? [];
            if (empty($charts)) {
                $charts = [
                    'default' => [
                        'title' => 'Size Guide',
                        'headers' => ['Size', 'Chest (cm)', 'Waist (cm)', 'Hip (cm)'],
                        'rows' => [
                            ['XS', '82-86', '62-66', '87-91'],
                            ['S', '86-90', '66-70', '91-95'],
                            ['M', '90-94', '70-74', '95-99'],
                            ['L', '94-98', '74-78', '99-103'],
                            ['XL', '98-102', '78-82', '103-107'],
                            ['XXL', '102-106', '82-86', '107-111'],
                        ],
                    ],
                ];
            }

            $clean_charts = [];
            foreach ($charts as $key => $chart) {
                $clean_key = sanitize_key($key);
                $clean_charts[$clean_key] = [
                    'title'   => sanitize_text_field($chart['title'] ?? 'Size Guide'),
                    'headers' => array_map('sanitize_text_field', $chart['headers'] ?? []),
                    'rows'    => array_map(function($row) { return array_map('sanitize_text_field', $row); }, $chart['rows'] ?? []),
                ];
            }

            $cat_map = [];
            if (!empty($params['category_map'])) {
                foreach ($params['category_map'] as $cat_id => $chart_key) {
                    $cat_map[intval($cat_id)] = sanitize_key($chart_key);
                }
            }

            update_option('wpilot_size_charts', $clean_charts);
            update_option('wpilot_size_chart_map', $cat_map);

            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

            $php = <<<'SIZEGUIDE_PHP'
<?php
/**
 * WPilot Size Guide — per-category size chart modal
 */

add_action('woocommerce_single_product_summary', function() {
    global $product;
    $chart = wpilot_get_size_chart_for_product($product);
    if (!$chart) return;
    echo '<button class="wpsg-trigger" id="wpsg-open">&#128207; Size Guide</button>';
}, 25);

function wpilot_get_size_chart_for_product($product) {
    $charts  = get_option('wpilot_size_charts', []);
    $cat_map = get_option('wpilot_size_chart_map', []);
    if (empty($charts)) return null;

    $cat_ids = $product->get_category_ids();
    foreach ($cat_ids as $cid) {
        if (isset($cat_map[$cid]) && isset($charts[$cat_map[$cid]])) {
            return $charts[$cat_map[$cid]];
        }
    }
    return $charts['default'] ?? reset($charts);
}

add_action('wp_footer', function() {
    if (!is_product()) return;
    global $product;
    $chart = wpilot_get_size_chart_for_product($product);
    if (!$chart) return;
    ?>
    <div id="wpsg-overlay" class="wpsg-overlay" style="display:none">
        <div class="wpsg-modal">
            <button class="wpsg-close" id="wpsg-close">&times;</button>
            <h3 class="wpsg-title"><?php echo esc_html($chart['title']); ?></h3>
            <div class="wpsg-table-wrap">
                <table class="wpsg-table">
                    <thead><tr>
                    <?php foreach ($chart['headers'] as $h): ?>
                        <th><?php echo esc_html($h); ?></th>
                    <?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($chart['rows'] as $row): ?>
                        <tr>
                        <?php foreach ($row as $cell): ?>
                            <td><?php echo esc_html($cell); ?></td>
                        <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script>
    (function(){
        var open = document.getElementById('wpsg-open');
        var overlay = document.getElementById('wpsg-overlay');
        var close = document.getElementById('wpsg-close');
        if (!open || !overlay) return;
        open.addEventListener('click', function() { overlay.style.display = 'flex'; document.body.style.overflow = 'hidden'; });
        close.addEventListener('click', function() { overlay.style.display = 'none'; document.body.style.overflow = ''; });
        overlay.addEventListener('click', function(e) { if (e.target === overlay) { overlay.style.display = 'none'; document.body.style.overflow = ''; } });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { overlay.style.display = 'none'; document.body.style.overflow = ''; } });
    })();
    </script>
    <?php
});

add_action('wp_head', function() {
    if (!is_product()) return;
    ?>
    <style>
    .wpsg-trigger {
        background: transparent;
        border: 1px solid var(--wp-border, #ddd);
        padding: 6px 14px;
        border-radius: 4px;
        font-size: 13px;
        color: var(--wp-text, #333);
        cursor: pointer;
        transition: all .2s;
    }
    .wpsg-trigger:hover { border-color: var(--wp-primary, #5B8DEF); color: var(--wp-primary, #5B8DEF); }
    .wpsg-overlay {
        position: fixed;
        inset: 0;
        z-index: 99999;
        background: rgba(0,0,0,.5);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        animation: wpsgFade .2s;
    }
    @keyframes wpsgFade { from { opacity:0; } to { opacity:1; } }
    .wpsg-modal {
        background: var(--wp-bg, #fff);
        border-radius: 12px;
        max-width: 600px;
        width: 100%;
        padding: 30px;
        position: relative;
        max-height: 80vh;
        overflow-y: auto;
        box-shadow: 0 20px 60px rgba(0,0,0,.25);
    }
    .wpsg-close {
        position: absolute;
        top: 12px;
        right: 16px;
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: var(--wp-text-muted, #999);
    }
    .wpsg-title { margin: 0 0 20px; font-size: 20px; color: var(--wp-text, #333); }
    .wpsg-table-wrap { overflow-x: auto; }
    .wpsg-table { width: 100%; border-collapse: collapse; font-size: 14px; }
    .wpsg-table th {
        background: var(--wp-primary, #5B8DEF);
        color: #fff;
        padding: 10px 14px;
        text-align: left;
        font-weight: 600;
    }
    .wpsg-table th:first-child { border-radius: 6px 0 0 0; }
    .wpsg-table th:last-child { border-radius: 0 6px 0 0; }
    .wpsg-table td {
        padding: 10px 14px;
        border-bottom: 1px solid var(--wp-border, #eee);
        color: var(--wp-text, #333);
    }
    .wpsg-table tr:hover td { background: var(--wp-bg-alt, #f9f9f9); }
    .wpsg-table td:first-child { font-weight: 600; }
    </style>
    <?php
});
SIZEGUIDE_PHP;

            file_put_contents($mu_dir . '/wpilot-woo-sizeguide.php', $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok('Size Guide installed — modal popup on product pages with ' . count($clean_charts) . ' chart(s).', [
                'mu_plugin' => 'wpilot-woo-sizeguide.php',
                'charts'    => array_keys($clean_charts),
            ]);

        // ═══════════════════════════════════════════════════════════
        //  6. ORDER TRACKING — customer-facing tracking page
        // ═══════════════════════════════════════════════════════════
        case 'woo_order_tracking':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $page_title = sanitize_text_field($params['page_title'] ?? 'Track Your Order');
            $page_slug  = sanitize_title($params['page_slug'] ?? 'order-tracking');

            // Built by Weblease

            $existing = get_page_by_path($page_slug);
            if (!$existing) {
                $page_id = wp_insert_post([
                    'post_title'   => $page_title,
                    'post_name'    => $page_slug,
                    'post_type'    => 'page',
                    'post_status'  => 'publish',
                    'post_content' => '[wpilot_order_tracking]',
                ]);
            } else {
                $page_id = $existing->ID;
            }

            update_option('wpilot_tracking_page', $page_id);

            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

            $php = <<<'TRACKING_PHP'
<?php
/**
 * WPilot Order Tracking — customer enters order ID + email to see status
 */

add_shortcode('wpilot_order_tracking', function() {
    $html = '<div class="wpt-wrap">';

    if (!empty($_POST['wpt_order_id']) && !empty($_POST['wpt_email'])) {
        if (!wp_verify_nonce($_POST['wpt_nonce'] ?? '', 'wpilot_track_order')) {
            $html .= '<p class="wpt-error">Security check failed. Please try again.</p>';
        } else {
            $order_id = intval($_POST['wpt_order_id']);
            $email    = sanitize_email($_POST['wpt_email']);
            $order    = wc_get_order($order_id);

            if (!$order || strtolower($order->get_billing_email()) !== strtolower($email)) {
                $html .= '<p class="wpt-error">Order not found. Please check your order number and email.</p>';
            } else {
                $status = $order->get_status();
                $tracking = get_post_meta($order_id, '_wpilot_tracking_number', true);
                $carrier  = get_post_meta($order_id, '_wpilot_tracking_carrier', true);
                $carrier_url = get_post_meta($order_id, '_wpilot_tracking_url', true);

                $steps = [
                    'pending'    => ['label' => 'Order Placed', 'icon' => '&#128230;'],
                    'processing' => ['label' => 'Processing', 'icon' => '&#9881;'],
                    'shipped'    => ['label' => 'Shipped', 'icon' => '&#128666;'],
                    'completed'  => ['label' => 'Delivered', 'icon' => '&#9989;'],
                ];

                $status_map = [
                    'pending'    => 0,
                    'on-hold'    => 0,
                    'processing' => 1,
                    'shipped'    => 2,
                    'completed'  => 3,
                    'refunded'   => -1,
                    'cancelled'  => -1,
                    'failed'     => -1,
                ];

                $current_step = $status_map[$status] ?? 0;

                $html .= '<div class="wpt-result">';
                $html .= '<h3 class="wpt-order-title">Order #' . intval($order_id) . '</h3>';
                $html .= '<p class="wpt-order-date">Placed on ' . esc_html($order->get_date_created()->format('F j, Y')) . '</p>';

                if ($current_step === -1) {
                    $html .= '<div class="wpt-status-badge wpt-status-' . esc_attr($status) . '">' . esc_html(ucfirst($status)) . '</div>';
                } else {
                    $html .= '<div class="wpt-timeline">';
                    $i = 0;
                    foreach ($steps as $key => $step) {
                        $state = '';
                        if ($i < $current_step) $state = 'done';
                        elseif ($i === $current_step) $state = 'active';
                        $html .= '<div class="wpt-step ' . $state . '">';
                        $html .= '<div class="wpt-step-icon">' . $step['icon'] . '</div>';
                        $html .= '<div class="wpt-step-label">' . esc_html($step['label']) . '</div>';
                        $html .= '</div>';
                        if ($i < count($steps) - 1) $html .= '<div class="wpt-step-line ' . ($i < $current_step ? 'done' : '') . '"></div>';
                        $i++;
                    }
                    $html .= '</div>';
                }

                if ($tracking) {
                    $html .= '<div class="wpt-tracking">';
                    $html .= '<strong>Tracking Number:</strong> ';
                    if ($carrier_url) {
                        $html .= '<a href="' . esc_url($carrier_url . $tracking) . '" target="_blank" rel="noopener">' . esc_html($tracking) . '</a>';
                    } else {
                        $html .= esc_html($tracking);
                    }
                    if ($carrier) $html .= ' (' . esc_html($carrier) . ')';
                    $html .= '</div>';
                }

                $html .= '<div class="wpt-items"><h4>Order Items</h4>';
                foreach ($order->get_items() as $item) {
                    $html .= '<div class="wpt-item">';
                    $html .= '<span class="wpt-item-name">' . esc_html($item->get_name()) . ' &times; ' . intval($item->get_quantity()) . '</span>';
                    $html .= '<span class="wpt-item-total">' . wc_price($item->get_total()) . '</span>';
                    $html .= '</div>';
                }
                $html .= '<div class="wpt-item wpt-total"><span>Total</span><span>' . wc_price($order->get_total()) . '</span></div>';
                $html .= '</div>';

                $html .= '</div>';
                $html .= '<style>
                .wpt-result { max-width: 600px; margin: 0 auto; }
                .wpt-order-title { font-size: 24px; color: var(--wp-text, #333); margin: 0 0 4px; }
                .wpt-order-date { color: var(--wp-text-muted, #999); font-size: 14px; margin: 0 0 30px; }
                .wpt-timeline { display: flex; align-items: center; justify-content: center; margin: 30px 0; gap: 0; }
                .wpt-step { display: flex; flex-direction: column; align-items: center; gap: 8px; min-width: 80px; }
                .wpt-step-icon {
                    width: 48px;
                    height: 48px;
                    border-radius: 50%;
                    background: var(--wp-bg-alt, #f0f0f0);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 20px;
                    transition: all .3s;
                }
                .wpt-step.active .wpt-step-icon { background: var(--wp-primary, #5B8DEF); box-shadow: 0 0 0 4px rgba(91,141,239,.2); }
                .wpt-step.done .wpt-step-icon { background: #22c55e; }
                .wpt-step-label { font-size: 12px; color: var(--wp-text-muted, #999); font-weight: 500; }
                .wpt-step.active .wpt-step-label { color: var(--wp-primary, #5B8DEF); font-weight: 700; }
                .wpt-step.done .wpt-step-label { color: #22c55e; }
                .wpt-step-line { flex: 1; height: 3px; background: var(--wp-border, #e0e0e0); min-width: 30px; }
                .wpt-step-line.done { background: #22c55e; }
                .wpt-status-badge {
                    display: inline-block;
                    padding: 6px 16px;
                    border-radius: 20px;
                    font-size: 14px;
                    font-weight: 600;
                    margin: 10px 0 20px;
                }
                .wpt-status-refunded { background: #fef3c7; color: #92400e; }
                .wpt-status-cancelled { background: #fee2e2; color: #991b1b; }
                .wpt-status-failed { background: #fee2e2; color: #991b1b; }
                .wpt-tracking {
                    background: var(--wp-bg-alt, #f7f7f7);
                    padding: 14px 18px;
                    border-radius: 8px;
                    margin: 20px 0;
                    font-size: 14px;
                }
                .wpt-tracking a { color: var(--wp-primary, #5B8DEF); }
                .wpt-items { margin-top: 24px; border-top: 1px solid var(--wp-border, #eee); padding-top: 16px; }
                .wpt-items h4 { font-size: 16px; color: var(--wp-text, #333); margin: 0 0 12px; }
                .wpt-item { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; color: var(--wp-text, #333); border-bottom: 1px solid var(--wp-border, #f0f0f0); }
                .wpt-total { font-weight: 700; border-top: 2px solid var(--wp-border, #ddd); border-bottom: none; padding-top: 12px; margin-top: 4px; }
                </style>';

                return $html . '</div>';
            }
        }
    }

    // Show form
    $html .= '<div class="wpt-form-wrap">';
    $html .= '<h3 style="text-align:center;color:var(--wp-text,#333);margin:0 0 8px">Track Your Order</h3>';
    $html .= '<p style="text-align:center;color:var(--wp-text-muted,#999);margin:0 0 24px;font-size:14px">Enter your order number and email address to check the status.</p>';
    $html .= '<form method="post" class="wpt-form">';
    $html .= wp_nonce_field('wpilot_track_order', 'wpt_nonce', true, false);
    $html .= '<label class="wpt-field"><span>Order Number</span><input type="text" name="wpt_order_id" placeholder="e.g. 1234" required></label>';
    $html .= '<label class="wpt-field"><span>Email Address</span><input type="email" name="wpt_email" placeholder="your@email.com" required></label>';
    $html .= '<button type="submit" class="wpt-submit">Track Order</button>';
    $html .= '</form></div>';
    $html .= '<style>
    .wpt-wrap { max-width: 480px; margin: 0 auto; }
    .wpt-form-wrap { background: var(--wp-bg, #fff); border: 1px solid var(--wp-border, #eee); border-radius: 12px; padding: 32px; }
    .wpt-field { display: block; margin-bottom: 16px; }
    .wpt-field span { display: block; font-size: 13px; font-weight: 600; color: var(--wp-text, #333); margin-bottom: 6px; }
    .wpt-field input { width: 100%; padding: 10px 14px; border: 1px solid var(--wp-border, #ddd); border-radius: 6px; font-size: 15px; box-sizing: border-box; }
    .wpt-field input:focus { outline: none; border-color: var(--wp-primary, #5B8DEF); box-shadow: 0 0 0 3px rgba(91,141,239,.15); }
    .wpt-submit { width: 100%; padding: 12px; background: var(--wp-primary, #5B8DEF); color: #fff; border: none; border-radius: 6px; font-size: 16px; font-weight: 600; cursor: pointer; }
    .wpt-submit:hover { opacity: .9; }
    .wpt-error { background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; font-size: 14px; margin-bottom: 16px; }
    </style>';

    $html .= '</div>';
    return $html;
});
TRACKING_PHP;

            file_put_contents($mu_dir . '/wpilot-woo-tracking.php', $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Order Tracking page installed at <strong>/{$page_slug}</strong> — visual timeline with order status.", [
                'mu_plugin' => 'wpilot-woo-tracking.php',
                'page_id'   => $page_id,
                'page_url'  => get_permalink($page_id),
            ]);

        // ═══════════════════════════════════════════════════════════
        //  7. INVOICE GENERATE — HTML invoice for WooCommerce order
        // ═══════════════════════════════════════════════════════════
        case 'woo_invoice_generate':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');
            $order_id = intval($params['order_id'] ?? 0);
            if (!$order_id) return wpilot_err('order_id required.');
            $order = wc_get_order($order_id);
            if (!$order) return wpilot_err("Order #{$order_id} not found.");

            $site_name = get_bloginfo('name');
            $site_url  = get_site_url();
            $logo_id   = get_theme_mod('custom_logo');
            $logo_url  = $logo_id ? wp_get_attachment_url($logo_id) : '';

            $store = [
                'name'     => $site_name,
                'address'  => get_option('woocommerce_store_address', ''),
                'address2' => get_option('woocommerce_store_address_2', ''),
                'city'     => get_option('woocommerce_store_city', ''),
                'postcode' => get_option('woocommerce_store_postcode', ''),
                'country'  => WC()->countries->get_base_country(),
            ];

            $billing = [
                'name'     => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'company'  => $order->get_billing_company(),
                'address'  => $order->get_billing_address_1(),
                'address2' => $order->get_billing_address_2(),
                'city'     => $order->get_billing_city(),
                'postcode' => $order->get_billing_postcode(),
                'country'  => $order->get_billing_country(),
                'email'    => $order->get_billing_email(),
                'phone'    => $order->get_billing_phone(),
            ];

            $shipping = [
                'name'     => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
                'address'  => $order->get_shipping_address_1(),
                'address2' => $order->get_shipping_address_2(),
                'city'     => $order->get_shipping_city(),
                'postcode' => $order->get_shipping_postcode(),
                'country'  => $order->get_shipping_country(),
            ];

            $items_data = [];
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                $items_data[] = [
                    'name'     => $item->get_name(),
                    'sku'      => $product ? $product->get_sku() : '',
                    'qty'      => $item->get_quantity(),
                    'price'    => wc_price($item->get_subtotal() / max(1, $item->get_quantity())),
                    'total'    => wc_price($item->get_total()),
                ];
            }

            $date_created = $order->get_date_created()->format('Y-m-d');
            $invoice_num  = sanitize_text_field($params['invoice_number'] ?? 'INV-' . $order_id);

            $logo_html = $logo_url ? '<img src="' . esc_url($logo_url) . '" style="max-height:60px;max-width:200px" alt="logo">' : '<strong style="font-size:24px">' . esc_html($site_name) . '</strong>';

            $invoice_html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice ' . esc_attr($invoice_num) . '</title>';
            $invoice_html .= '<style>';
            $invoice_html .= 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;margin:0;padding:40px;color:#333;font-size:14px}';
            $invoice_html .= '.inv-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:40px}';
            $invoice_html .= '.inv-title{font-size:32px;font-weight:700;color:#5B8DEF;margin:0}';
            $invoice_html .= '.inv-meta{text-align:right;font-size:13px;color:#666}';
            $invoice_html .= '.inv-addresses{display:flex;gap:40px;margin-bottom:30px}';
            $invoice_html .= '.inv-addr{flex:1}.inv-addr h4{font-size:12px;text-transform:uppercase;letter-spacing:1px;color:#999;margin:0 0 8px}';
            $invoice_html .= '.inv-addr p{margin:0;line-height:1.6}';
            $invoice_html .= 'table{width:100%;border-collapse:collapse;margin-bottom:30px}';
            $invoice_html .= 'th{background:#5B8DEF;color:#fff;padding:10px 14px;text-align:left;font-weight:600;font-size:13px}';
            $invoice_html .= 'td{padding:10px 14px;border-bottom:1px solid #eee}';
            $invoice_html .= '.inv-totals{text-align:right;margin-bottom:40px}';
            $invoice_html .= '.inv-totals div{padding:4px 0;font-size:14px}';
            $invoice_html .= '.inv-totals .inv-grand{font-size:20px;font-weight:700;color:#5B8DEF;border-top:2px solid #ddd;padding-top:10px;margin-top:6px}';
            $invoice_html .= '.inv-footer{text-align:center;font-size:12px;color:#999;border-top:1px solid #eee;padding-top:20px}';
            $invoice_html .= '@media print{body{padding:20px}}';
            $invoice_html .= '</style></head><body>';

            $invoice_html .= '<div class="inv-header"><div>' . $logo_html . '</div>';
            $invoice_html .= '<div class="inv-meta"><div class="inv-title">INVOICE</div>';
            $invoice_html .= '<div>' . esc_html($invoice_num) . '</div>';
            $invoice_html .= '<div>Date: ' . esc_html($date_created) . '</div>';
            $invoice_html .= '<div>Order: #' . intval($order_id) . '</div>';
            $invoice_html .= '<div>Status: ' . esc_html(ucfirst($order->get_status())) . '</div></div></div>';

            $invoice_html .= '<div class="inv-addresses">';
            $invoice_html .= '<div class="inv-addr"><h4>From</h4><p>' . esc_html($store['name']) . '<br>' . esc_html($store['address']);
            if ($store['address2']) $invoice_html .= '<br>' . esc_html($store['address2']);
            $invoice_html .= '<br>' . esc_html($store['postcode'] . ' ' . $store['city']) . '<br>' . esc_html($store['country']) . '</p></div>';
            $invoice_html .= '<div class="inv-addr"><h4>Bill To</h4><p>' . esc_html($billing['name']);
            if ($billing['company']) $invoice_html .= '<br>' . esc_html($billing['company']);
            $invoice_html .= '<br>' . esc_html($billing['address']);
            if ($billing['address2']) $invoice_html .= '<br>' . esc_html($billing['address2']);
            $invoice_html .= '<br>' . esc_html($billing['postcode'] . ' ' . $billing['city']) . '<br>' . esc_html($billing['country']);
            $invoice_html .= '<br>' . esc_html($billing['email']) . '</p></div>';

            if (trim($shipping['name']) && trim($shipping['address'])) {
                $invoice_html .= '<div class="inv-addr"><h4>Ship To</h4><p>' . esc_html($shipping['name']) . '<br>' . esc_html($shipping['address']);
                if ($shipping['address2']) $invoice_html .= '<br>' . esc_html($shipping['address2']);
                $invoice_html .= '<br>' . esc_html($shipping['postcode'] . ' ' . $shipping['city']) . '<br>' . esc_html($shipping['country']) . '</p></div>';
            }
            $invoice_html .= '</div>';

            $invoice_html .= '<table><thead><tr><th>Item</th><th>SKU</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead><tbody>';
            foreach ($items_data as $item) {
                $invoice_html .= '<tr><td>' . esc_html($item['name']) . '</td><td>' . esc_html($item['sku'] ?: '-') . '</td><td>' . intval($item['qty']) . '</td><td>' . $item['price'] . '</td><td>' . $item['total'] . '</td></tr>';
            }
            $invoice_html .= '</tbody></table>';

            $invoice_html .= '<div class="inv-totals">';
            $invoice_html .= '<div>Subtotal: ' . wc_price($order->get_subtotal()) . '</div>';
            if (floatval($order->get_shipping_total()) > 0) $invoice_html .= '<div>Shipping: ' . wc_price($order->get_shipping_total()) . '</div>';
            if (floatval($order->get_total_tax()) > 0) $invoice_html .= '<div>Tax: ' . wc_price($order->get_total_tax()) . '</div>';
            if (floatval($order->get_total_discount()) > 0) $invoice_html .= '<div>Discount: -' . wc_price($order->get_total_discount()) . '</div>';
            $invoice_html .= '<div class="inv-grand">Total: ' . wc_price($order->get_total()) . '</div>';
            $invoice_html .= '</div>';

            $payment_method = $order->get_payment_method_title();
            if ($payment_method) $invoice_html .= '<p style="font-size:13px;color:#666">Payment method: ' . esc_html($payment_method) . '</p>';

            $invoice_html .= '<div class="inv-footer">' . esc_html($site_name) . ' &mdash; ' . esc_url($site_url) . '</div>';
            $invoice_html .= '</body></html>';

            $upload = wp_upload_dir();
            $inv_dir = $upload['basedir'] . '/wpilot-invoices';
            if (!is_dir($inv_dir)) wp_mkdir_p($inv_dir);

            if (!file_exists($inv_dir . '/.htaccess')) {
                file_put_contents($inv_dir . '/.htaccess', "Options -Indexes\n");
            }

            $filename = sanitize_file_name("invoice-{$order_id}.html");
            file_put_contents($inv_dir . '/' . $filename, $invoice_html);
            $download_url = $upload['baseurl'] . '/wpilot-invoices/' . $filename;

            update_post_meta($order_id, '_wpilot_invoice_url', $download_url);

            return wpilot_ok("Invoice generated for order #{$order_id}.", [
                'download_url' => $download_url,
                'invoice_num'  => $invoice_num,
                'order_id'     => $order_id,
                'total'        => $order->get_total(),
            ]);

        // ═══════════════════════════════════════════════════════════
        //  8. REVIEW REQUEST — auto-email after delivery
        // ═══════════════════════════════════════════════════════════
        case 'woo_review_request':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $delay_days = max(1, intval($params['delay_days'] ?? 7));
            $subject    = sanitize_text_field($params['subject'] ?? 'How did you like your purchase from {site_name}?');
            $template   = wp_kses_post($params['template'] ?? '');

            if (empty($template)) {
                $template = '<div style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;max-width:600px;margin:0 auto;padding:30px">'
                    . '<h2 style="color:#333">Hi {customer_name}!</h2>'
                    . '<p style="color:#555;line-height:1.6">Thank you for your recent purchase from <strong>{site_name}</strong>. We hope you are enjoying your new items!</p>'
                    . '<p style="color:#555;line-height:1.6">We would love to hear your feedback. Your review helps other customers and helps us improve.</p>'
                    . '<div style="text-align:center;margin:30px 0">{product_review_links}</div>'
                    . '<p style="color:#999;font-size:13px">Thank you for being a valued customer!</p></div>';
            }

            update_option('wpilot_review_request', [
                'enabled'    => true,
                'delay_days' => $delay_days,
                'subject'    => $subject,
                'template'   => $template,
            ]);

            $mu_dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
            if (!is_dir($mu_dir)) wp_mkdir_p($mu_dir);

            $php = <<<'REVIEW_PHP'
<?php
/**
 * WPilot Review Request — auto-email after order completion
 */

add_action('woocommerce_order_status_completed', function($order_id) {
    $config = get_option('wpilot_review_request', []);
    if (empty($config['enabled'])) return;
    $delay = intval($config['delay_days'] ?? 7);
    wp_schedule_single_event(time() + ($delay * DAY_IN_SECONDS), 'wpilot_send_review_request', [$order_id]);
});

add_action('wpilot_send_review_request', function($order_id) {
    $config = get_option('wpilot_review_request', []);
    if (empty($config['enabled'])) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    if (get_post_meta($order_id, '_wpilot_review_sent', true)) return;

    $email   = $order->get_billing_email();
    $name    = $order->get_billing_first_name();
    $site    = get_bloginfo('name');

    $links = '';
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        $url = $product->get_permalink() . '#reviews';
        $links .= '<a href="' . esc_url($url) . '" style="display:inline-block;padding:10px 24px;background:#5B8DEF;color:#fff;text-decoration:none;border-radius:6px;margin:4px;font-weight:600">';
        $links .= 'Review: ' . esc_html($product->get_name()) . '</a><br>';
    }

    $subject  = str_replace('{site_name}', $site, $config['subject']);
    $body     = str_replace(
        ['{customer_name}', '{site_name}', '{order_id}', '{product_review_links}'],
        [esc_html($name), esc_html($site), intval($order_id), $links],
        $config['template']
    );

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    wp_mail($email, $subject, $body, $headers);
    update_post_meta($order_id, '_wpilot_review_sent', time());
});
REVIEW_PHP;

            file_put_contents($mu_dir . '/wpilot-woo-review-request.php', $php);
            if (function_exists('wpilot_bust_cache')) wpilot_bust_cache();
            return wpilot_ok("Review request emails enabled — sends {$delay_days} day(s) after order completion.", [
                'mu_plugin'  => 'wpilot-woo-review-request.php',
                'delay_days' => $delay_days,
            ]);

        // ═══════════════════════════════════════════════════════════
        //  9. BULK UPDATE — update products by filter
        // ═══════════════════════════════════════════════════════════
        case 'woo_bulk_update':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $action = sanitize_text_field($params['action'] ?? '');
            if (!$action) return wpilot_err('action required (set_price, set_sale, set_stock, set_status, set_category, remove_sale).');

            $allowed_actions = ['set_price', 'set_sale', 'set_stock', 'set_status', 'set_category', 'remove_sale'];
            if (!in_array($action, $allowed_actions)) return wpilot_err('Invalid action. Allowed: ' . implode(', ', $allowed_actions));

            $args = [
                'post_type'      => 'product',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ];

            $category_id = intval($params['category_id'] ?? 0);
            $category_slug = sanitize_text_field($params['category'] ?? '');
            if ($category_id || $category_slug) {
                $tax_query = ['taxonomy' => 'product_cat'];
                if ($category_id) { $tax_query['field'] = 'term_id'; $tax_query['terms'] = $category_id; }
                else { $tax_query['field'] = 'slug'; $tax_query['terms'] = $category_slug; }
                $args['tax_query'] = [$tax_query];
            }

            if (!empty($params['price_min']) || !empty($params['price_max'])) {
                $args['meta_query'] = [];
                $min = floatval($params['price_min'] ?? 0);
                $max = floatval($params['price_max'] ?? 999999);
                $args['meta_query'][] = ['key' => '_price', 'value' => [$min, $max], 'type' => 'DECIMAL', 'compare' => 'BETWEEN'];
            }

            if (!empty($params['filter_status'])) {
                $args['post_status'] = sanitize_text_field($params['filter_status']);
            }

            if (!empty($params['product_ids'])) {
                $args['post__in'] = array_map('intval', (array)$params['product_ids']);
            }

            $q = new WP_Query($args);
            $product_ids = $q->posts;

            if (empty($product_ids)) return wpilot_err('No products match the filter criteria.');

            $updated = 0;
            foreach ($product_ids as $pid) {
                switch ($action) {
                    case 'set_price':
                        $new_price = sanitize_text_field($params['value'] ?? $params['price'] ?? '');
                        if ($new_price === '') continue 2;
                        update_post_meta($pid, '_regular_price', $new_price);
                        $sale = get_post_meta($pid, '_sale_price', true);
                        update_post_meta($pid, '_price', $sale ?: $new_price);
                        break;

                    case 'set_sale':
                        $pct   = floatval($params['discount_percent'] ?? 0);
                        $fixed = sanitize_text_field($params['sale_price'] ?? '');
                        $regular = get_post_meta($pid, '_regular_price', true);
                        if ($pct > 0 && $regular) {
                            $sale_price = round(floatval($regular) * (1 - $pct / 100), 2);
                        } elseif ($fixed !== '') {
                            $sale_price = $fixed;
                        } else {
                            continue 2;
                        }
                        update_post_meta($pid, '_sale_price', $sale_price);
                        update_post_meta($pid, '_price', $sale_price);
                        if (!empty($params['start_date'])) update_post_meta($pid, '_sale_price_dates_from', strtotime(sanitize_text_field($params['start_date'])));
                        if (!empty($params['end_date'])) update_post_meta($pid, '_sale_price_dates_to', strtotime(sanitize_text_field($params['end_date'])));
                        break;

                    case 'remove_sale':
                        $regular = get_post_meta($pid, '_regular_price', true);
                        delete_post_meta($pid, '_sale_price');
                        delete_post_meta($pid, '_sale_price_dates_from');
                        delete_post_meta($pid, '_sale_price_dates_to');
                        if ($regular) update_post_meta($pid, '_price', $regular);
                        break;

                    case 'set_stock':
                        $stock = intval($params['value'] ?? $params['stock'] ?? 0);
                        update_post_meta($pid, '_manage_stock', 'yes');
                        update_post_meta($pid, '_stock', $stock);
                        update_post_meta($pid, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');
                        break;

                    case 'set_status':
                        $status = sanitize_text_field($params['value'] ?? $params['status'] ?? 'publish');
                        wp_update_post(['ID' => $pid, 'post_status' => $status]);
                        break;

                    case 'set_category':
                        $cat_ids = array_map('intval', (array)($params['value'] ?? $params['target_category_ids'] ?? []));
                        $append = !empty($params['append']);
                        wp_set_object_terms($pid, $cat_ids, 'product_cat', $append);
                        break;
                }
                if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
                $updated++;
            }

            return wpilot_ok("Bulk update complete: {$action} applied to {$updated} product(s).", [
                'action'  => $action,
                'updated' => $updated,
                'total'   => count($product_ids),
            ]);

        // ═══════════════════════════════════════════════════════════
        //  10. IMPORT PRODUCTS — from CSV data or URL
        // ═══════════════════════════════════════════════════════════
        case 'woo_import_products':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $csv_data = $params['csv_data'] ?? '';
            $csv_url  = sanitize_url($params['csv_url'] ?? '');

            if (empty($csv_data) && empty($csv_url)) return wpilot_err('csv_data or csv_url required.');

            if (empty($csv_data) && $csv_url) {
                $response = wp_remote_get($csv_url, ['timeout' => 30]);
                if (is_wp_error($response)) return wpilot_err('Failed to fetch CSV: ' . $response->get_error_message());
                $csv_data = wp_remote_retrieve_body($response);
            }

            if (empty($csv_data)) return wpilot_err('CSV data is empty.');

            $lines = array_filter(array_map('trim', explode("\n", $csv_data)));
            if (count($lines) < 2) return wpilot_err('CSV must have a header row and at least one data row.');

            $headers = str_getcsv(array_shift($lines));
            $headers = array_map(function($h) { return strtolower(trim($h)); }, $headers);

            $col_map = [
                'name'             => ['name', 'title', 'product_name', 'product_title'],
                'price'            => ['price', 'regular_price'],
                'sale_price'       => ['sale_price', 'sale'],
                'description'      => ['description', 'desc', 'content'],
                'short_description'=> ['short_description', 'short_desc', 'excerpt'],
                'category'         => ['category', 'categories', 'product_cat'],
                'image_url'        => ['image_url', 'image', 'img', 'thumbnail'],
                'sku'              => ['sku', 'product_sku'],
                'stock'            => ['stock', 'stock_quantity', 'qty'],
                'weight'           => ['weight'],
                'status'           => ['status'],
                'type'             => ['type', 'product_type'],
            ];

            $mapping = [];
            foreach ($col_map as $field => $aliases) {
                foreach ($aliases as $alias) {
                    $idx = array_search($alias, $headers);
                    if ($idx !== false) { $mapping[$field] = $idx; break; }
                }
            }

            if (!isset($mapping['name'])) return wpilot_err('CSV must have a "name" or "title" column.');

            $created = 0;
            $errors  = [];
            $max_import = min(intval($params['limit'] ?? 500), 500);

            foreach (array_slice($lines, 0, $max_import) as $line_num => $line) {
                $cols = str_getcsv($line);
                $name = sanitize_text_field($cols[$mapping['name']] ?? '');
                if (empty($name)) { $errors[] = "Row " . ($line_num + 2) . ": empty name, skipped."; continue; }

                $product_data = [
                    'post_title'   => $name,
                    'post_type'    => 'product',
                    'post_status'  => isset($mapping['status']) ? sanitize_text_field($cols[$mapping['status']] ?? 'publish') : 'publish',
                    'post_content' => isset($mapping['description']) ? wp_kses_post($cols[$mapping['description']] ?? '') : '',
                    'post_excerpt' => isset($mapping['short_description']) ? wp_kses_post($cols[$mapping['short_description']] ?? '') : '',
                ];

                $pid = wp_insert_post($product_data);
                if (is_wp_error($pid)) { $errors[] = "Row " . ($line_num + 2) . ": " . $pid->get_error_message(); continue; }

                $type = isset($mapping['type']) ? sanitize_text_field($cols[$mapping['type']] ?? 'simple') : 'simple';
                wp_set_object_terms($pid, $type, 'product_type');

                if (isset($mapping['price'])) {
                    $price = sanitize_text_field($cols[$mapping['price']] ?? '');
                    if ($price !== '') {
                        update_post_meta($pid, '_regular_price', $price);
                        update_post_meta($pid, '_price', $price);
                    }
                }

                if (isset($mapping['sale_price'])) {
                    $sale = sanitize_text_field($cols[$mapping['sale_price']] ?? '');
                    if ($sale !== '') {
                        update_post_meta($pid, '_sale_price', $sale);
                        update_post_meta($pid, '_price', $sale);
                    }
                }

                if (isset($mapping['sku'])) {
                    $sku = sanitize_text_field($cols[$mapping['sku']] ?? '');
                    if ($sku) update_post_meta($pid, '_sku', $sku);
                }

                if (isset($mapping['stock'])) {
                    $stock = intval($cols[$mapping['stock']] ?? 0);
                    update_post_meta($pid, '_manage_stock', 'yes');
                    update_post_meta($pid, '_stock', $stock);
                    update_post_meta($pid, '_stock_status', $stock > 0 ? 'instock' : 'outofstock');
                } else {
                    update_post_meta($pid, '_stock_status', 'instock');
                }

                if (isset($mapping['weight'])) {
                    $w = sanitize_text_field($cols[$mapping['weight']] ?? '');
                    if ($w) update_post_meta($pid, '_weight', $w);
                }

                if (isset($mapping['category'])) {
                    $cat_name = sanitize_text_field($cols[$mapping['category']] ?? '');
                    if ($cat_name) {
                        $cat_names = array_map('trim', explode(',', $cat_name));
                        $cat_ids = [];
                        foreach ($cat_names as $cn) {
                            $term = get_term_by('name', $cn, 'product_cat');
                            if (!$term) {
                                $new_term = wp_insert_term($cn, 'product_cat');
                                if (!is_wp_error($new_term)) $cat_ids[] = $new_term['term_id'];
                            } else {
                                $cat_ids[] = $term->term_id;
                            }
                        }
                        if (!empty($cat_ids)) wp_set_object_terms($pid, $cat_ids, 'product_cat');
                    }
                }

                if (isset($mapping['image_url'])) {
                    $img_url = esc_url_raw($cols[$mapping['image_url']] ?? '');
                    if ($img_url && function_exists('media_sideload_image')) {
                        require_once ABSPATH . 'wp-admin/includes/media.php';
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        $img_id = media_sideload_image($img_url, $pid, $name, 'id');
                        if (!is_wp_error($img_id)) {
                            set_post_thumbnail($pid, $img_id);
                        }
                    }
                }

                update_post_meta($pid, '_visibility', 'visible');
                if (function_exists('wc_delete_product_transients')) wc_delete_product_transients($pid);
                $created++;
            }

            return wpilot_ok("Imported {$created} product(s) from CSV.", [
                'created' => $created,
                'errors'  => array_slice($errors, 0, 10),
                'columns_mapped' => array_keys($mapping),
            ]);

        // ═══════════════════════════════════════════════════════════
        //  11. CONDITIONAL SHIPPING — free shipping above threshold
        // ═══════════════════════════════════════════════════════════
        case 'woo_conditional_shipping':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $free_threshold = floatval($params['free_threshold'] ?? $params['min_amount'] ?? 500);
            $flat_rate      = floatval($params['flat_rate'] ?? 49);
            $zone_name      = sanitize_text_field($params['zone_name'] ?? '');

            if ($zone_name) {
                $zones = WC_Shipping_Zones::get_zones();
                $target_zone = null;
                foreach ($zones as $z) {
                    if (strtolower($z['zone_name']) === strtolower($zone_name)) {
                        $target_zone = new WC_Shipping_Zone($z['id']);
                        break;
                    }
                }
                if (!$target_zone) return wpilot_err("Shipping zone \"{$zone_name}\" not found.");
            } else {
                $country = WC()->countries->get_base_country();
                $zones = WC_Shipping_Zones::get_zones();
                $target_zone = null;
                foreach ($zones as $z) {
                    foreach ($z['zone_locations'] as $loc) {
                        if ($loc->code === $country && $loc->type === 'country') {
                            $target_zone = new WC_Shipping_Zone($z['id']);
                            break 2;
                        }
                    }
                }
                if (!$target_zone) {
                    $target_zone = new WC_Shipping_Zone();
                    $country_name = WC()->countries->get_countries()[$country] ?? $country;
                    $target_zone->set_zone_name($country_name);
                    $target_zone->save();
                    $target_zone->add_location($country, 'country');
                }
            }

            $existing_methods = $target_zone->get_shipping_methods();
            foreach ($existing_methods as $m) {
                if (in_array($m->id, ['flat_rate', 'free_shipping'])) {
                    $target_zone->delete_shipping_method($m->instance_id);
                }
            }

            $free_id = $target_zone->add_shipping_method('free_shipping');
            $free_method = WC_Shipping_Zones::get_shipping_method($free_id);
            if ($free_method) {
                $free_method->init_instance_settings();
                $free_method->instance_settings['requires'] = 'min_amount';
                $free_method->instance_settings['min_amount'] = $free_threshold;
                $free_method->instance_settings['title'] = sanitize_text_field($params['free_label'] ?? 'Free Shipping');
                update_option($free_method->get_instance_option_key(), $free_method->instance_settings);
            }

            $flat_id = $target_zone->add_shipping_method('flat_rate');
            $flat_method = WC_Shipping_Zones::get_shipping_method($flat_id);
            if ($flat_method) {
                $flat_method->init_instance_settings();
                $flat_method->instance_settings['cost'] = $flat_rate;
                $flat_method->instance_settings['title'] = sanitize_text_field($params['flat_label'] ?? 'Standard Shipping');
                update_option($flat_method->get_instance_option_key(), $flat_method->instance_settings);
            }

            $currency = get_woocommerce_currency_symbol();
            return wpilot_ok("Conditional shipping configured: Free over {$currency}{$free_threshold}, flat {$currency}{$flat_rate} below.", [
                'zone'           => $target_zone->get_zone_name(),
                'zone_id'        => $target_zone->get_id(),
                'free_threshold' => $free_threshold,
                'flat_rate'      => $flat_rate,
            ]);

        // ═══════════════════════════════════════════════════════════
        //  12. TAX SETUP — auto-configure tax rates for country
        // ═══════════════════════════════════════════════════════════
        case 'woo_tax_setup':
            if (!class_exists('WooCommerce')) return wpilot_err('WooCommerce required.');

            $country = strtoupper(sanitize_text_field($params['country'] ?? WC()->countries->get_base_country()));

            $tax_configs = [
                'SE' => [
                    ['rate' => 25, 'name' => 'Moms 25%', 'class' => '', 'priority' => 1],
                    ['rate' => 12, 'name' => 'Moms 12%', 'class' => 'reduced-rate', 'priority' => 1],
                    ['rate' => 6, 'name' => 'Moms 6%', 'class' => 'zero-rate', 'priority' => 1],
                ],
                'NO' => [
                    ['rate' => 25, 'name' => 'MVA 25%', 'class' => '', 'priority' => 1],
                    ['rate' => 15, 'name' => 'MVA 15%', 'class' => 'reduced-rate', 'priority' => 1],
                    ['rate' => 12, 'name' => 'MVA 12%', 'class' => 'zero-rate', 'priority' => 1],
                ],
                'DK' => [
                    ['rate' => 25, 'name' => 'Moms 25%', 'class' => '', 'priority' => 1],
                ],
                'FI' => [
                    ['rate' => 24, 'name' => 'ALV 24%', 'class' => '', 'priority' => 1],
                    ['rate' => 14, 'name' => 'ALV 14%', 'class' => 'reduced-rate', 'priority' => 1],
                    ['rate' => 10, 'name' => 'ALV 10%', 'class' => 'zero-rate', 'priority' => 1],
                ],
                'DE' => [
                    ['rate' => 19, 'name' => 'MwSt 19%', 'class' => '', 'priority' => 1],
                    ['rate' => 7, 'name' => 'MwSt 7%', 'class' => 'reduced-rate', 'priority' => 1],
                ],
                'FR' => [
                    ['rate' => 20, 'name' => 'TVA 20%', 'class' => '', 'priority' => 1],
                    ['rate' => 10, 'name' => 'TVA 10%', 'class' => 'reduced-rate', 'priority' => 1],
                    ['rate' => 5.5, 'name' => 'TVA 5.5%', 'class' => 'zero-rate', 'priority' => 1],
                ],
                'NL' => [
                    ['rate' => 21, 'name' => 'BTW 21%', 'class' => '', 'priority' => 1],
                    ['rate' => 9, 'name' => 'BTW 9%', 'class' => 'reduced-rate', 'priority' => 1],
                ],
                'ES' => [
                    ['rate' => 21, 'name' => 'IVA 21%', 'class' => '', 'priority' => 1],
                    ['rate' => 10, 'name' => 'IVA 10%', 'class' => 'reduced-rate', 'priority' => 1],
                    ['rate' => 4, 'name' => 'IVA 4%', 'class' => 'zero-rate', 'priority' => 1],
                ],
                'IT' => [
                    ['rate' => 22, 'name' => 'IVA 22%', 'class' => '', 'priority' => 1],
                    ['rate' => 10, 'name' => 'IVA 10%', 'class' => 'reduced-rate', 'priority' => 1],
                    ['rate' => 4, 'name' => 'IVA 4%', 'class' => 'zero-rate', 'priority' => 1],
                ],
                'GB' => [
                    ['rate' => 20, 'name' => 'VAT 20%', 'class' => '', 'priority' => 1],
                    ['rate' => 5, 'name' => 'VAT 5%', 'class' => 'reduced-rate', 'priority' => 1],
                ],
                'AT' => [
                    ['rate' => 20, 'name' => 'USt 20%', 'class' => '', 'priority' => 1],
                    ['rate' => 10, 'name' => 'USt 10%', 'class' => 'reduced-rate', 'priority' => 1],
                ],
                'BE' => [
                    ['rate' => 21, 'name' => 'BTW 21%', 'class' => '', 'priority' => 1],
                    ['rate' => 12, 'name' => 'BTW 12%', 'class' => 'reduced-rate', 'priority' => 1],
                    ['rate' => 6, 'name' => 'BTW 6%', 'class' => 'zero-rate', 'priority' => 1],
                ],
                'PL' => [
                    ['rate' => 23, 'name' => 'PTU 23%', 'class' => '', 'priority' => 1],
                    ['rate' => 8, 'name' => 'PTU 8%', 'class' => 'reduced-rate', 'priority' => 1],
                    ['rate' => 5, 'name' => 'PTU 5%', 'class' => 'zero-rate', 'priority' => 1],
                ],
            ];

            $us_states = [
                'AL'=>4,'AZ'=>5.6,'AR'=>6.5,'CA'=>7.25,'CO'=>2.9,'CT'=>6.35,'FL'=>6,'GA'=>4,
                'HI'=>4,'ID'=>6,'IL'=>6.25,'IN'=>7,'IA'=>6,'KS'=>6.5,'KY'=>6,'LA'=>4.45,
                'ME'=>5.5,'MD'=>6,'MA'=>6.25,'MI'=>6,'MN'=>6.875,'MS'=>7,'MO'=>4.225,
                'NE'=>5.5,'NV'=>6.85,'NJ'=>6.625,'NM'=>5.125,'NY'=>4,'NC'=>4.75,'ND'=>5,
                'OH'=>5.75,'OK'=>4.5,'PA'=>6,'RI'=>7,'SC'=>6,'SD'=>4.5,'TN'=>7,'TX'=>6.25,
                'UT'=>6.1,'VT'=>6,'VA'=>5.3,'WA'=>6.5,'WV'=>6,'WI'=>5,'WY'=>4,
            ];

            if ($country === 'US') {
                $states = $params['states'] ?? array_keys($us_states);
                $rates = [];
                foreach ((array)$states as $st) {
                    $st = strtoupper(sanitize_text_field($st));
                    if (isset($us_states[$st])) {
                        $rates[] = ['rate' => $us_states[$st], 'name' => "Sales Tax {$st}", 'class' => '', 'priority' => 1, 'state' => $st];
                    }
                }
            } elseif (isset($tax_configs[$country])) {
                $rates = $tax_configs[$country];
            } else {
                $rate = floatval($params['rate'] ?? $params['vat_rate'] ?? 0);
                if (!$rate) return wpilot_err("No tax configuration found for {$country}. Provide a 'rate' parameter.");
                $rates = [['rate' => $rate, 'name' => "VAT {$rate}%", 'class' => '', 'priority' => 1]];
            }

            update_option('woocommerce_calc_taxes', 'yes');
            update_option('woocommerce_prices_include_tax', sanitize_text_field($params['prices_include_tax'] ?? 'yes'));
            update_option('woocommerce_tax_display_shop', sanitize_text_field($params['display_shop'] ?? 'incl'));
            update_option('woocommerce_tax_display_cart', sanitize_text_field($params['display_cart'] ?? 'incl'));
            update_option('woocommerce_tax_total_display', 'itemized');

            $existing_classes = WC_Tax::get_tax_class_slugs();
            if (!in_array('reduced-rate', $existing_classes)) {
                WC_Tax::create_tax_class('Reduced Rate');
            }
            if (!in_array('zero-rate', $existing_classes)) {
                WC_Tax::create_tax_class('Zero Rate');
            }

            global $wpdb;
            $existing_rates = $wpdb->get_col($wpdb->prepare(
                "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = %s",
                $country
            ));
            foreach ($existing_rates as $rid) {
                WC_Tax::_delete_tax_rate($rid);
            }

            $inserted = 0;
            foreach ($rates as $r) {
                $tax_data = [
                    'tax_rate_country'  => $country,
                    'tax_rate_state'    => $r['state'] ?? '',
                    'tax_rate'          => $r['rate'],
                    'tax_rate_name'     => $r['name'],
                    'tax_rate_priority' => $r['priority'] ?? 1,
                    'tax_rate_compound' => 0,
                    'tax_rate_shipping' => ($r['class'] === '') ? 1 : 0,
                    'tax_rate_order'    => $inserted,
                    'tax_rate_class'    => $r['class'] ?? '',
                ];
                WC_Tax::_insert_tax_rate($tax_data);
                $inserted++;
            }

            $country_name = WC()->countries->get_countries()[$country] ?? $country;
            return wpilot_ok("Tax configured for {$country_name}: {$inserted} rate(s) added.", [
                'country' => $country,
                'rates'   => $rates,
                'prices_include_tax' => get_option('woocommerce_prices_include_tax'),
            ]);

        default:
            return null;
    }
}
