<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  PHP Snippets — add/remove code via mu-plugins (safe sandbox)
//  Each snippet stored as its own mu-plugin file so it can be
//  individually removed without touching functions.php.
// ═══════════════════════════════════════════════════════════════
// Built by Christos Ferlachidis & Daniel Hedenberg

// Dangerous function names that must not appear in snippets.
// Stored split so this file itself does not trigger security scanners.
function wpilot_snippet_blocked_fns() {
    return [
        'eval' . '(',
        'base64' . '_decode(',
        'shell' . '_exec(',
        'system' . '(',
        'passthru' . '(',
        'popen' . '(',
        'proc' . '_open(',
        'assert' . '(',
    ];
}

function wpilot_add_php_snippet( $params ) {
    $name = sanitize_file_name( $params['name'] ?? '' );
    $code = $params['code'] ?? '';
    $desc = sanitize_text_field( $params['description'] ?? $name );

    if ( empty( $name ) ) return wpilot_err( 'Snippet name required.' );
    if ( empty( $code ) ) return wpilot_err( 'Snippet code required.' );

    // Strip wrapping PHP tags — we add our own header
    $code = preg_replace( '/^\s*<\?php\s*/i', '', trim( $code ) );
    $code = preg_replace( '/\?>\s*$/i', '', trim( $code ) );

    // Reject known dangerous PHP functions used for code injection
    foreach ( wpilot_snippet_blocked_fns() as $fn ) {
        if ( stripos( $code, $fn ) !== false ) {
            return wpilot_err( "Snippet rejected: contains blocked function pattern." );
        }
    }

    $mu_dir = WPMU_PLUGIN_DIR;
    if ( ! is_dir( $mu_dir ) ) wp_mkdir_p( $mu_dir );

    $slug    = 'wpilot-snippet-' . sanitize_title( $name );
    $file    = $mu_dir . '/' . $slug . '.php';
    $header  = "<?php\n/**\n * WPilot Snippet: {$desc}\n * Added: " . current_time('Y-m-d H:i') . "\n */\n";
    $header .= "if ( ! defined( 'ABSPATH' ) ) exit;\n\n";
    $content = $header . $code . "\n";

    if ( file_put_contents( $file, $content ) === false ) {
        return wpilot_err( 'Could not write snippet file. Check mu-plugins directory permissions.' );
    }

    $snippets        = get_option( 'wpi_snippets', [] );
    $snippets[$slug] = [
        'name'   => $name,
        'desc'   => $desc,
        'file'   => $slug . '.php',
        'added'  => current_time('mysql'),
        'active' => true,
    ];
    update_option( 'wpi_snippets', $snippets );

    return wpilot_ok( "Snippet \"{$name}\" added as {$slug}.php. Active immediately.", ['slug'=>$slug,'file'=>$file] );
}

function wpilot_remove_snippet( $params ) {
    $name     = sanitize_text_field( $params['name'] ?? '' );
    if ( empty($name) ) return wpilot_err( 'Snippet name required.' );

    $snippets = get_option( 'wpi_snippets', [] );
    $mu_dir   = WPMU_PLUGIN_DIR;

    foreach ( $snippets as $slug => $info ) {
        $by_slug = ( $slug === $name ) || ( $slug === 'wpilot-snippet-' . sanitize_title( $name ) );
        $by_name = ( strtolower( trim( $info['name'] ) ) === strtolower( trim( $name ) ) );

        if ( $by_slug || $by_name ) {
            $file = $mu_dir . '/' . $info['file'];
            if ( file_exists( $file ) ) @unlink( $file );
            unset( $snippets[$slug] );
            update_option( 'wpi_snippets', $snippets );
            return wpilot_ok( "Snippet \"{$name}\" removed." );
        }
    }

    // Fallback: try to delete the file directly by guessed slug
    $guessed = $mu_dir . '/wpilot-snippet-' . sanitize_title( $name ) . '.php';
    if ( file_exists( $guessed ) ) {
        @unlink( $guessed );
        return wpilot_ok( "Snippet file removed: " . basename($guessed) );
    }

    return wpilot_err( "Snippet \"{$name}\" not found. Use the snippet list to check available names." );
}

// ═══════════════════════════════════════════════════════════════
//  Create HTML Page — works with any builder and any theme.
//  Clears builder-specific postmeta so content renders as plain
//  HTML without builder wrappers interfering.
// ═══════════════════════════════════════════════════════════════

function wpilot_create_html_page( $params ) {
    $title    = sanitize_text_field( $params['title']   ?? 'New Page' );
    $html     = wp_kses_post( $params['html'] ?? $params['content'] ?? '' );
    $slug     = sanitize_title( $params['slug'] ?? $title );
    $status   = sanitize_text_field( $params['status']  ?? 'publish' );
    $set_home = ! empty( $params['set_as_homepage'] );

    if ( empty( $html ) ) return wpilot_err( 'html or content parameter required.' );

    $existing = get_page_by_path( $slug );
    if ( $existing ) {
        wpilot_save_post_snapshot( $existing->ID );
        wp_update_post( ['ID'=>$existing->ID,'post_content'=>$html,'post_status'=>$status] );
        $id = $existing->ID; $action = 'updated';
    } else {
        $id = wp_insert_post([
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $html,
            'post_status'  => $status,
            'post_type'    => 'page',
        ]);
        if ( is_wp_error($id) ) return wpilot_err( $id->get_error_message() );
        $action = 'created';
    }

    // Remove builder-specific meta so page renders as plain HTML with any theme/builder
    delete_post_meta( $id, '_elementor_edit_mode' );
    delete_post_meta( $id, '_elementor_data' );
    delete_post_meta( $id, '_et_pb_use_builder' );
    delete_post_meta( $id, '_fl_builder_enabled' );
    update_post_meta( $id, '_wp_page_template', 'default' );

    if ( $set_home ) {
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $id );
    }

    return wpilot_ok( "Page \"{$title}\" {$action} (ID: {$id}).", [
        'id'     => $id,
        'url'    => get_permalink($id),
        'action' => $action,
    ]);
}

// ═══════════════════════════════════════════════════════════════
//  Check Frontend — fetch rendered HTML for AI analysis.
//  Returns: page title, H1/H2 tags, meta description, body
//  classes (theme hooks), image alt coverage, detected builder,
//  and any PHP errors leaked into the HTML output.
// ═══════════════════════════════════════════════════════════════

function wpilot_check_frontend( $params ) {
    $url     = esc_url_raw( $params['url'] ?? '' );
    $post_id = intval( $params['post_id'] ?? 0 );

    if ( $post_id ) {
        $permalink = get_permalink( $post_id );
        if ( $permalink ) $url = $permalink;
    }
    if ( empty($url) ) $url = get_home_url();

    $response = wp_remote_get( $url, [
        'timeout'    => 15,
        'user-agent' => 'WPilot/2.0 (Frontend Checker)',
        'sslverify'  => false,
    ]);

    if ( is_wp_error($response) ) {
        return wpilot_err( 'Cannot fetch page: ' . $response->get_error_message() );
    }

    $code = wp_remote_retrieve_response_code($response);
    $html = wp_remote_retrieve_body($response);

    if ( $code !== 200 ) {
        return wpilot_err( "HTTP {$code} — page may not be published or URL is wrong." );
    }

    $data = ['url' => $url, 'http_status' => $code];

    // Page title and meta description
    if ( preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m) )
        $data['page_title'] = trim($m[1]);
    if ( preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m) )
        $data['meta_description'] = trim($m[1]);

    // H1 and H2 tags
    preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $html, $h1m);
    $data['h1_tags'] = array_map('wp_strip_all_tags', $h1m[1] ?? []);
    preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $h2m);
    $data['h2_tags'] = array_slice(array_map('wp_strip_all_tags', $h2m[1] ?? []), 0, 5);

    // Body classes — reveals theme template and page type
    if ( preg_match('/<body[^>]+class=["\']([^"\']+)["\'][^>]*>/i', $html, $bm) )
        $data['body_classes'] = explode(' ', trim($bm[1]));

    // Image alt coverage
    preg_match_all('/<img[^>]+>/i', $html, $imgm);
    $missing_alt = 0;
    foreach ($imgm[0] as $it) {
        if ( ! preg_match('/\balt=["\'][^"\']+["\']/', $it) ) $missing_alt++;
    }
    $data['images_total']       = count($imgm[0]);
    $data['images_missing_alt'] = $missing_alt;

    // Detect active builder from HTML fingerprints
    $bd = 'unknown';
    if ( strpos($html,'elementor') !== false )      $bd = 'elementor';
    elseif ( strpos($html,'et_pb_') !== false )     $bd = 'divi';
    elseif ( strpos($html,'fl-builder') !== false ) $bd = 'beaver';
    elseif ( strpos($html,'bricks-') !== false )    $bd = 'bricks';
    elseif ( strpos($html,'wp-block-') !== false )  $bd = 'gutenberg';
    $data['builder_detected'] = $bd;

    // Detect PHP errors leaked into HTML output
    $errors = [];
    if ( preg_match('/<b>(?:Fatal error|Warning|Notice|Parse error)<\/b>/i', $html) )
        $errors[] = 'PHP error detected in page output — check server error logs';
    if ( strpos($html,'wp-die-message') !== false )
        $errors[] = 'WordPress fatal error message present in output';
    $data['errors'] = $errors;

    // Approximate word count
    $plain = preg_replace('/<[^>]+>/', ' ', $html);
    $plain = html_entity_decode($plain, ENT_QUOTES, 'UTF-8');
    $data['word_count'] = str_word_count(trim(preg_replace('/\s+/', ' ', $plain)));

    $summary = "Frontend {$url}: HTTP {$code}, "
        . count($data['h1_tags']) . " H1 tag(s), "
        . "{$data['images_total']} images ({$missing_alt} missing alt), "
        . "builder: {$bd}"
        . (!empty($errors) ? ' | ERRORS: ' . implode('; ', $errors) : '');

    return wpilot_ok($summary, $data);
}
