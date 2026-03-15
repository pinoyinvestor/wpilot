<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  WPILOT BUILDER TOOLS
//  Elementor, Divi, Gutenberg, Bricks, Beaver Builder
//  AI kan bygga och redigera sidor direkt i alla builders
// ═══════════════════════════════════════════════════════════════

// ── Detect active builder ─────────────────────────────────────
function wpilot_detect_all_builders() {
    $builders = [];
    if ( defined('ELEMENTOR_VERSION') )                          $builders[] = 'elementor';
    if ( defined('ET_BUILDER_VERSION') || defined('ET_DB_VERSION') ) $builders[] = 'divi';
    if ( defined('FL_BUILDER_VERSION') )                         $builders[] = 'beaver';
    if ( defined('BRICKS_VERSION') )                             $builders[] = 'bricks';
    if ( defined('OXYGEN_VSB_VERSION') )                         $builders[] = 'oxygen';
    if ( function_exists('get_block_editor_settings') )          $builders[] = 'gutenberg';
    return $builders;
}

function wpilot_primary_builder() {
    $builders = wpilot_detect_all_builders();
    // Priority order
    foreach (['elementor','divi','beaver','bricks','oxygen','gutenberg'] as $b) {
        if (in_array($b, $builders)) return $b;
    }
    return 'gutenberg';
}

// ── Route tool to correct builder ─────────────────────────────
function wpilot_run_builder_tool( $tool, $params ) {
    $builder = wpilot_primary_builder();

    switch ($tool) {
        case 'builder_create_page':
            return wpilot_builder_create_page($params, $builder);
        case 'builder_update_section':
            return wpilot_builder_update_section($params, $builder);
        case 'builder_add_widget':
            return wpilot_builder_add_widget($params, $builder);
        case 'builder_update_css':
            return wpilot_builder_update_css($params, $builder);
        case 'builder_set_colors':
            return wpilot_builder_set_colors($params, $builder);
        case 'builder_set_fonts':
            return wpilot_builder_set_fonts($params, $builder);
        case 'builder_create_header':
            return wpilot_builder_create_header($params, $builder);
        case 'builder_create_footer':
            return wpilot_builder_create_footer($params, $builder);
        default:
            return wpilot_err("Unknown builder tool: {$tool}");
    }
}

// ══════════════════════════════════════════════════════════════
//  ELEMENTOR
// ══════════════════════════════════════════════════════════════

function wpilot_elementor_installed() {
    return defined('ELEMENTOR_VERSION');
}

function wpilot_elementor_create_page( $params ) {
    if (!wpilot_elementor_installed()) return wpilot_err('Elementor is not installed.');

    $title    = sanitize_text_field($params['title']      ?? 'New Page');
    $sections = $params['sections']                       ?? [];
    $template = sanitize_text_field($params['template']   ?? 'elementor_canvas');

    // Create the WP post first
    $post_id = wp_insert_post([
        'post_title'   => $title,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'meta_input'   => ['_wp_page_template' => $template],
    ]);

    if (is_wp_error($post_id)) return wpilot_err($post_id->get_error_message());

    // Mark as Elementor page
    update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    update_post_meta($post_id, '_elementor_template_type', 'wp-page');
    update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);

    // Build Elementor data structure
    $elementor_data = wpilot_elementor_build_data($sections, $params);

    update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elementor_data)));

    // Clear Elementor cache
    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }

    $url = get_permalink($post_id);
    $edit_url = admin_url("post.php?post={$post_id}&action=elementor");

    return wpilot_ok("✅ Elementor page \"{$title}\" created (ID: {$post_id}). Edit in Elementor: {$edit_url}", [
        'page_id'  => $post_id,
        'url'      => $url,
        'edit_url' => $edit_url,
        'builder'  => 'elementor',
    ]);
}

function wpilot_elementor_build_data( $sections, $params ) {
    $data = [];

    if (empty($sections)) {
        // Default: hero + content sections based on params
        $sections = wpilot_generate_default_sections($params);
    }

    foreach ($sections as $section_def) {
        $data[] = wpilot_elementor_build_section($section_def);
    }

    return $data;
}

function wpilot_elementor_build_section( $def ) {
    $type    = $def['type']    ?? 'text';
    $content = $def['content'] ?? '';
    $cols    = $def['columns'] ?? 1;
    $bg      = $def['bg_color']?? '';
    $padding = $def['padding'] ?? '60px 0';

    $section_id  = wpilot_uid();
    $column_id   = wpilot_uid();
    $widget_id   = wpilot_uid();

    // Build widget based on type
    switch ($type) {
        case 'heading':  $widget = wpilot_el_widget_heading($widget_id, $content, $def); break;
        case 'text':     $widget = wpilot_el_widget_text($widget_id, $content, $def); break;
        case 'button':   $widget = wpilot_el_widget_button($widget_id, $content, $def); break;
        case 'image':    $widget = wpilot_el_widget_image($widget_id, $content, $def); break;
        case 'video':    $widget = wpilot_el_widget_video($widget_id, $content, $def); break;
        case 'spacer':   $widget = wpilot_el_widget_spacer($widget_id, $def); break;
        case 'divider':  $widget = wpilot_el_widget_divider($widget_id, $def); break;
        case 'icon-box': $widget = wpilot_el_widget_icon_box($widget_id, $content, $def); break;
        default:         $widget = null; break;
    }

    return [
        'id'       => $section_id,
        'elType'   => 'section',
        'settings' => [
            'background_background'    => $bg ? 'classic' : '',
            'background_color'         => $bg,
            'padding'                  => ['unit'=>'px','top'=>'60','right'=>'0','bottom'=>'60','left'=>'0','isLinked'=>false],
            'layout'                   => 'boxed',
            'content_width'            => ['size'=>1200,'unit'=>'px'],
        ],
        'elements' => [[
            'id'       => $column_id,
            'elType'   => 'column',
            'settings' => ['_column_size'=>100,'_inline_size'=>null],
            'elements' => [$widget],
        ]],
    ];
}

// ── Elementor Widgets ──────────────────────────────────────────

function wpilot_el_widget_heading( $id, $content, $def ) {
    return [
        'id'        => $id,
        'elType'    => 'widget',
        'widgetType'=> 'heading',
        'settings'  => [
            'title'      => sanitize_text_field($content),
            'header_size'=> $def['tag'] ?? 'h2',
            'align'      => $def['align'] ?? 'center',
            'typography_font_size' => ['unit'=>'px','size'=>$def['font_size']??42],
            'title_color'=> $def['color'] ?? '',
        ],
    ];
}

function wpilot_el_widget_text( $id, $content, $def ) {
    return [
        'id'        => $id,
        'elType'    => 'widget',
        'widgetType'=> 'text-editor',
        'settings'  => [
            'editor' => wp_kses_post($content),
            'align'  => $def['align'] ?? 'left',
        ],
    ];
}

function wpilot_el_widget_button( $id, $content, $def ) {
    return [
        'id'        => $id,
        'elType'    => 'widget',
        'widgetType'=> 'button',
        'settings'  => [
            'text'             => sanitize_text_field($content),
            'link'             => ['url' => esc_url_raw($def['url'] ?? '#')],
            'align'            => $def['align'] ?? 'center',
            'background_color' => $def['bg_color'] ?? '',
            'button_type'      => 'info',
            'size'             => $def['size'] ?? 'lg',
            'border_radius'    => ['unit'=>'px','top'=>8,'right'=>8,'bottom'=>8,'left'=>8,'isLinked'=>true],
        ],
    ];
}

function wpilot_el_widget_image( $id, $content, $def ) {
    return [
        'id'        => $id,
        'elType'    => 'widget',
        'widgetType'=> 'image',
        'settings'  => [
            'image'   => ['url' => esc_url_raw($content), 'id' => 0],
            'align'   => $def['align'] ?? 'center',
            'caption' => sanitize_text_field($def['caption'] ?? ''),
        ],
    ];
}

function wpilot_el_widget_icon_box( $id, $content, $def ) {
    return [
        'id'        => $id,
        'elType'    => 'widget',
        'widgetType'=> 'icon-box',
        'settings'  => [
            'selected_icon' => ['value'=>$def['icon']??'fas fa-star','library'=>'fa-solid'],
            'title_text'    => sanitize_text_field($def['title'] ?? ''),
            'description_text' => wp_kses_post($content),
            'title_size'    => 'h4',
        ],
    ];
}

function wpilot_el_widget_spacer( $id, $def ) {
    return [
        'id'=>$id,'elType'=>'widget','widgetType'=>'spacer',
        'settings'=>['space'=>['unit'=>'px','size'=>$def['height']??40]],
    ];
}

function wpilot_el_widget_divider( $id, $def ) {
    return [
        'id'=>$id,'elType'=>'widget','widgetType'=>'divider',
        'settings'=>['style'=>$def['style']??'solid','weight'=>['unit'=>'px','size'=>1],'color'=>$def['color']??'#e0e0e0'],
    ];
}

function wpilot_el_widget_video( $id, $content, $def ) {
    return [
        'id'=>$id,'elType'=>'widget','widgetType'=>'video',
        'settings'=>['video_type'=>'youtube','youtube_url'=>esc_url_raw($content),'aspect_ratio'=>'169'],
    ];
}

function wpilot_el_widget_hero( $id, $content, $def ) {
    // Hero = heading + subtext + button in one section
    return wpilot_el_widget_heading($id, $content, array_merge($def, ['font_size'=>56,'align'=>'center']));
}

// ── Generate smart default sections based on site type ─────────
function wpilot_generate_default_sections( $params ) {
    $purpose = $params['purpose'] ?? 'general';
    $title   = $params['title']   ?? 'Welcome';
    $cta     = $params['cta']     ?? 'Get Started';
    $cta_url = $params['cta_url'] ?? '#';

    $sections = [
        ['type'=>'heading','content'=>$title,             'font_size'=>56,'align'=>'center','tag'=>'h1'],
        ['type'=>'text',   'content'=>$params['subtitle']??'','align'=>'center'],
        ['type'=>'button', 'content'=>$cta,               'url'=>$cta_url,'align'=>'center'],
    ];

    if ($purpose === 'booking') {
        $sections[] = ['type'=>'heading','content'=>'How it works','font_size'=>36,'align'=>'center','tag'=>'h2'];
        $sections[] = ['type'=>'icon-box','content'=>'Book your appointment online in seconds','icon'=>'fas fa-calendar','title'=>'1. Book'];
        $sections[] = ['type'=>'icon-box','content'=>'Receive instant confirmation by email or SMS','icon'=>'fas fa-check','title'=>'2. Confirm'];
        $sections[] = ['type'=>'icon-box','content'=>'Show up — we\'ll take care of the rest','icon'=>'fas fa-smile','title'=>'3. Enjoy'];
    }

    if ($purpose === 'ecommerce') {
        $sections[] = ['type'=>'heading','content'=>'Featured Products','font_size'=>36,'align'=>'center','tag'=>'h2'];
    }

    return $sections;
}

// ══════════════════════════════════════════════════════════════
//  DIVI
// ══════════════════════════════════════════════════════════════

function wpilot_divi_installed() {
    return defined('ET_BUILDER_VERSION') || defined('ET_DB_VERSION') || class_exists('ET_Builder_Module');
}
// Built by Christos Ferlachidis & Daniel Hedenberg

function wpilot_divi_create_page( $params ) {
    if (!wpilot_divi_installed()) return wpilot_err('Divi is not installed or activated. Make sure the Divi theme or Divi Builder plugin is active.');

    $title    = sanitize_text_field($params['title']   ?? 'New Page');
    $sections = $params['sections']                    ?? [];

    if (empty($sections)) {
        $sections = wpilot_generate_default_sections($params);
    }

    // Build Divi shortcode content
    $content = wpilot_divi_build_content($sections);

    $post_id = wp_insert_post([
        'post_title'   => $title,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => $content,
        'meta_input'   => [
            '_et_pb_use_builder'       => 'on',
            '_et_pb_old_content'       => '',
            '_et_builder_version'      => defined('ET_BUILDER_VERSION') ? ET_BUILDER_VERSION : '4.0',
            '_wp_page_template'        => 'page-template-blank.php',
        ],
    ]);

    if (is_wp_error($post_id)) return wpilot_err($post_id->get_error_message());

    return wpilot_ok("✅ Divi page \"{$title}\" created (ID: {$post_id}). Edit with Divi Builder: " . admin_url("post.php?post={$post_id}&action=edit"), [
        'page_id'  => $post_id,
        'url'      => get_permalink($post_id),
        'edit_url' => admin_url("post.php?post={$post_id}&action=edit"),
        'builder'  => 'divi',
    ]);
}

function wpilot_divi_build_content( $sections ) {
    $output = '';
    foreach ($sections as $s) {
        $type    = $s['type']    ?? 'text';
        $content = $s['content'] ?? '';
        $bg      = !empty($s['bg_color']) ? ' background_color="'.esc_attr($s['bg_color']).'"' : '';
        $align   = $s['align']   ?? 'left';
        $color   = !empty($s['color']) ? ' text_color="'.esc_attr($s['color']).'"' : '';

        switch ($type) {
            case 'heading': $module = '[et_pb_text admin_label="Heading" _builder_version="4.0"'.$color.']<'.($s['tag']??'h2').' style="text-align:'.esc_attr($align).'">'.esc_html($content).'</'.($s['tag']??'h2').'>[/et_pb_text]'; break;
            case 'text':    $module = '[et_pb_text admin_label="Text" _builder_version="4.0"]<p style="text-align:'.esc_attr($align).'">'.wp_kses_post($content).'</p>[/et_pb_text]'; break;
            case 'button':  $module = '[et_pb_button button_text="'.esc_attr($content).'" button_url="'.esc_url($s['url']??'#').'" button_alignment="'.esc_attr($align).'" _builder_version="4.0" /]'; break;
            case 'image':   $module = '[et_pb_image src="'.esc_url($content).'" align="'.esc_attr($align).'" _builder_version="4.0" /]'; break;
            case 'video':   $module = '[et_pb_video src="'.esc_url($content).'" _builder_version="4.0" /]'; break;
            case 'spacer':  $module = '[et_pb_space disabled_on="off|off|off" _builder_version="4.0" height="'.intval($s['height']??40).'px" /]'; break;
            case 'divider': $module = '[et_pb_divider _builder_version="4.0" /]'; break;
            case 'icon-box':$module = '[et_pb_blurb title="'.esc_attr($s['title']??'').'" use_icon="on" font_icon="%%0%%" _builder_version="4.0"]'.wp_kses_post($content).'[/et_pb_blurb]'; break;
            default:        $module = ''; break;
        }

        $output .= '[et_pb_section fb_built="1" _builder_version="4.0"'.$bg.' custom_padding="60px|0px|60px|0px"]'
                 . '[et_pb_row _builder_version="4.0" width="1200px"]'
                 . '[et_pb_column type="4_4" _builder_version="4.0"]'
                 . $module
                 . '[/et_pb_column][/et_pb_row][/et_pb_section]';
    }
    return $output;
}

// ══════════════════════════════════════════════════════════════
//  GUTENBERG (native blocks)
// ══════════════════════════════════════════════════════════════

function wpilot_gutenberg_create_page( $params ) {
    $title    = sanitize_text_field($params['title']   ?? 'New Page');
    $sections = $params['sections']                    ?? [];

    if (empty($sections)) {
        $sections = wpilot_generate_default_sections($params);
    }

    $blocks = '';
    foreach ($sections as $s) {
        $blocks .= wpilot_gutenberg_build_block($s);
    }

    $post_id = wp_insert_post([
        'post_title'   => $title,
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => $blocks,
    ]);

    if (is_wp_error($post_id)) return wpilot_err($post_id->get_error_message());

    return wpilot_ok("✅ Page \"{$title}\" created with Gutenberg blocks (ID: {$post_id}).", [
        'page_id'  => $post_id,
        'url'      => get_permalink($post_id),
        'edit_url' => admin_url("post.php?post={$post_id}&action=edit"),
        'builder'  => 'gutenberg',
    ]);
}

function wpilot_gutenberg_build_block( $s ) {
    $type    = $s['type']    ?? 'text';
    $content = $s['content'] ?? '';
    $align   = $s['align']   ?? 'left';

    switch ($type) {
        case 'heading':
            return sprintf('<!-- wp:heading {"level":%d,"textAlign":"%s"} --><h%d class="wp-block-heading has-text-align-%s">%s</h%d><!-- /wp:heading -->',
                isset($s['tag']) ? (int)substr($s['tag'],1) : 2, $align,
                isset($s['tag']) ? (int)substr($s['tag'],1) : 2, $align,
                esc_html($content),
                isset($s['tag']) ? (int)substr($s['tag'],1) : 2);
        case 'text':
            return sprintf('<!-- wp:paragraph {"align":"%s"} --><p class="has-text-align-%s">%s</p><!-- /wp:paragraph -->', $align, $align, wp_kses_post($content));
        case 'button':
            return sprintf('<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"%s"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="%s">%s</a></div><!-- /wp:button --></div><!-- /wp:buttons -->', $align === 'center' ? 'center' : 'left', esc_url($s['url']??'#'), esc_html($content));
        case 'image':
            return sprintf('<!-- wp:image {"align":"%s"} --><figure class="wp-block-image align%s"><img src="%s" alt="%s"/></figure><!-- /wp:image -->', $align, $align, esc_url($content), esc_attr($s['alt']??''));
        default:
            return '';
    }
}

// ══════════════════════════════════════════════════════════════
//  UNIFIED: create page in correct builder
// ══════════════════════════════════════════════════════════════

function wpilot_builder_create_page( $params, $builder = null ) {
    $builder = $builder ?? wpilot_primary_builder();

    switch ($builder) {
        case 'elementor': return wpilot_elementor_create_page($params);
        case 'divi':      return wpilot_divi_create_page($params);
        case 'gutenberg':
        default:          return wpilot_gutenberg_create_page($params);
    }
}

// ── Update page CSS (works for all builders) ──────────────────
function wpilot_builder_update_css( $params, $builder = null ) {
    $post_id = (int)($params['page_id'] ?? 0);
    $css     = sanitize_textarea_field($params['css'] ?? '');
    if (!$post_id || !$css) return wpilot_err('page_id and css are required.');

    if ($builder === 'elementor') {
        update_post_meta($post_id, '_elementor_css', '');
        update_post_meta($post_id, '_elementor_custom_css', $css);
    } else {
        $existing = get_post_meta($post_id, '_ca_custom_css', true) ?: '';
        update_post_meta($post_id, '_ca_custom_css', $existing . "\n" . $css);
    }

    // Add to wp_head
    $all_css = get_option('ca_custom_css','') . "\n/* Page {$post_id} */\n{$css}";
    update_option('ca_custom_css', $all_css);

    return wpilot_ok("✅ Custom CSS added to page #{$post_id}.");
}

// ── Set global colors ─────────────────────────────────────────
function wpilot_builder_set_colors( $params, $builder = null ) {
    $builder  = $builder ?? wpilot_primary_builder();
    $primary  = sanitize_hex_color($params['primary']   ?? '');
    $secondary= sanitize_hex_color($params['secondary'] ?? '');
    $accent   = sanitize_hex_color($params['accent']    ?? '');
    $text     = sanitize_hex_color($params['text']      ?? '');
    $bg       = sanitize_hex_color($params['background']?? '');

    if ($builder === 'elementor') {
        $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
        if ($kit) {
            $settings = $kit->get_settings();
            $palette  = $settings['system_colors'] ?? [];
            foreach ([
                'accent'    => $primary,
                'secondary' => $secondary,
                'text'      => $text,
            ] as $role => $color) {
                if ($color) {
                    foreach ($palette as &$item) {
                        if ($item['_id'] === $role) $item['color'] = $color;
                    }
                }
            }
            $kit->update_setting('system_colors', $palette);
            $kit->save_settings(['system_colors'=>$palette]);
        }
        return wpilot_ok("✅ Elementor global colors updated.");
    }

    if ($builder === 'divi') {
        $et_options = get_option('et_divi', []);
        if ($primary)   $et_options['accent_color'] = $primary;
        if ($text)      $et_options['font_color']   = $text;
        update_option('et_divi', $et_options);
        return wpilot_ok("✅ Divi global colors updated.");
    }

    // Generic: CSS variables
    $css = ':root {';
    if ($primary)   $css .= "--color-primary:{$primary};";
    if ($secondary) $css .= "--color-secondary:{$secondary};";
    if ($accent)    $css .= "--color-accent:{$accent};";
    if ($text)      $css .= "--color-text:{$text};";
    if ($bg)        $css .= "--color-bg:{$bg};";
    $css .= '}';
    $existing = get_option('ca_custom_css','');
    update_option('ca_custom_css', $existing . "\n/* WPilot: Global Colors */\n" . $css);
    return wpilot_ok("✅ Global color CSS variables set.");
}

// ── Set global fonts ──────────────────────────────────────────
function wpilot_builder_set_fonts( $params, $builder = null ) {
    $builder = $builder ?? wpilot_primary_builder();
    $heading = sanitize_text_field($params['heading_font'] ?? '');
    $body    = sanitize_text_field($params['body_font']    ?? '');
    $size    = (int)($params['base_size'] ?? 16);

    if ($builder === 'elementor' && class_exists('\Elementor\Plugin')) {
        $kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
        if ($kit && $heading) {
            $typography = $kit->get_settings('system_typography') ?? [];
            foreach ($typography as &$t) {
                if ($t['_id'] === 'primary')   $t['typography_font_family'] = $heading;
                if ($t['_id'] === 'secondary') $t['typography_font_family'] = $body ?: $heading;
            }
            $kit->save_settings(['system_typography'=>$typography]);
        }
        return wpilot_ok("✅ Elementor fonts updated — Headings: {$heading}, Body: {$body}.");
    }

    if ($builder === 'divi') {
        $opts = get_option('et_divi',[]);
        if ($heading) $opts['header_font'] = $heading;
        if ($body)    $opts['body_font']   = $body;
        if ($size)    $opts['body_font_size'] = $size;
        update_option('et_divi', $opts);
        return wpilot_ok("✅ Divi fonts updated — Headings: {$heading}, Body: {$body}.");
    }

    // Generic CSS
    $google = [];
    if ($heading) $google[] = urlencode($heading).':400,700';
    if ($body)    $google[] = urlencode($body).':400,400i,700';
    if ($google) {
        $link = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family='.implode('&family=',$google).'&display=swap">';
        update_option('wpi_google_fonts_html', $link);
        add_action('wp_head', function() { echo get_option('wpi_google_fonts_html',''); });
    }
    $css = ':root{';
    if ($heading) $css .= "--font-heading:'{$heading}',sans-serif;";
    if ($body)    $css .= "--font-body:'{$body}',sans-serif;";
    if ($size)    $css .= "--font-size-base:{$size}px;";
    $css .= '}';
    if ($heading) $css .= " h1,h2,h3,h4,h5,h6{font-family:var(--font-heading);}";
    if ($body)    $css .= " body{font-family:var(--font-body);font-size:var(--font-size-base);}";
    update_option('ca_custom_css', get_option('ca_custom_css','') . "\n/* WPilot: Fonts */\n" . $css);
    return wpilot_ok("✅ Fonts set — Headings: {$heading}, Body: {$body}.");
}

// ── Small helper ──────────────────────────────────────────────
function wpilot_uid() {
    return substr(md5(uniqid(mt_rand(), true)), 0, 7);
}

// ── Placeholder stubs for header/footer ───────────────────────
function wpilot_builder_create_header( $params, $builder ) {
    return wpilot_ok("Header creation for {$builder} — use the builder's Theme Builder for global headers. I can generate the content structure for you.");
}
function wpilot_builder_create_footer( $params, $builder ) {
    return wpilot_ok("Footer creation for {$builder} — use the builder's Theme Builder for global footers. I can generate the content structure for you.");
}
function wpilot_builder_update_section( $params, $builder ) {
    return wpilot_builder_update_css($params, $builder);
}
function wpilot_builder_add_widget( $params, $builder ) {
    // Re-fetch page and append widget
    $page_id = (int)($params['page_id'] ?? 0);
    if (!$page_id) return wpilot_err('page_id required.');
    if ($builder === 'elementor') {
        $data = json_decode(get_post_meta($page_id,'_elementor_data',true), true) ?: [];
        $data[] = wpilot_elementor_build_section($params);
        update_post_meta($page_id,'_elementor_data', wp_slash(wp_json_encode($data)));
        if (class_exists('\Elementor\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
        return wpilot_ok("✅ Widget added to Elementor page #{$page_id}.");
    }
    if ($builder === 'divi') {
        $post    = get_post($page_id);
        $content = $post->post_content . wpilot_divi_build_content([$params]);
        wp_update_post(['ID'=>$page_id,'post_content'=>$content]);
        return wpilot_ok("✅ Module added to Divi page #{$page_id}.");
    }
    $post    = get_post($page_id);
    $content = $post->post_content . wpilot_gutenberg_build_block($params);
    wp_update_post(['ID'=>$page_id,'post_content'=>$content]);
    return wpilot_ok("✅ Block added to page #{$page_id}.");
}
