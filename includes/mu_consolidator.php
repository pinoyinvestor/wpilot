<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  MU-PLUGIN CONSOLIDATOR
//
//  Replaces 11+ separate wpilot-*.php mu-plugin files with ONE
//  consolidated file: wpilot-site.php
//
//  Each module is stored in wp_options as wpilot_mu_modules
//  (array of module_id => code). A single mu-plugin loads them
//  all in order, each wrapped in an IIFE with try/catch so one
//  broken module can't crash the entire site.
//
//  Functions:
//    wpilot_mu_register($id, $code)   — add/update a module
//    wpilot_mu_remove($id)            — remove a module
//    wpilot_mu_list()                 — list active modules
//    wpilot_mu_cleanup()              — migrate old files
//    wpilot_regenerate_mu()           — rebuild wpilot-site.php
// ═══════════════════════════════════════════════════════════════

define( 'WPILOT_MU_OPTION', 'wpilot_mu_modules' );
define( 'WPILOT_MU_FILENAME', 'wpilot-site.php' );

// ── Module load order — controls the sequence in wpilot-site.php ──
function wpilot_mu_load_order() {
    return [
        'fonts',
        'head-code',
        'css-override',
        'theme-override',
        'head-styles',
        'header',
        'footer',
        'mobile-nav',
        'lazy-load',
        'pwa',
        'woo-filter',
        'redirects',
        'cookie-banner',
        'popups',
    ];
}

// ── Get mu-plugins directory ──────────────────────────────────
function wpilot_mu_dir() {
    $dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if ( ! is_dir( $dir ) ) wp_mkdir_p( $dir );
    return $dir;
}

// ═══════════════════════════════════════════════════════════════
//  REGISTER / UPDATE A MODULE
// ═══════════════════════════════════════════════════════════════

function wpilot_mu_register( $module_id, $code ) {
    $module_id = sanitize_key( $module_id );
    if ( empty( $module_id ) || empty( $code ) ) {
        return false;
    }

    $modules = get_option( WPILOT_MU_OPTION, [] );
    $modules[ $module_id ] = [
        'code'       => $code,
        'size'       => strlen( $code ),
        'updated_at' => current_time( 'mysql' ),
    ];
    update_option( WPILOT_MU_OPTION, $modules, false );

    // Regenerate the single mu-plugin file
    return wpilot_regenerate_mu();
}

// ═══════════════════════════════════════════════════════════════
//  REMOVE A MODULE
// ═══════════════════════════════════════════════════════════════

function wpilot_mu_remove( $module_id ) {
    $module_id = sanitize_key( $module_id );
    $modules   = get_option( WPILOT_MU_OPTION, [] );

    if ( ! isset( $modules[ $module_id ] ) ) {
        return false;
    }

    unset( $modules[ $module_id ] );
    update_option( WPILOT_MU_OPTION, $modules, false );

    // If no modules left, remove the file entirely
    if ( empty( $modules ) ) {
        $path = wpilot_mu_dir() . '/' . WPILOT_MU_FILENAME;
        if ( file_exists( $path ) ) @unlink( $path );
        return true;
    }

    return wpilot_regenerate_mu();
}

// ═══════════════════════════════════════════════════════════════
//  LIST ALL ACTIVE MODULES
// ═══════════════════════════════════════════════════════════════
// Built by Christos Ferlachidis & Daniel Hedenberg

function wpilot_mu_list() {
    $modules = get_option( WPILOT_MU_OPTION, [] );
    $list    = [];

    foreach ( $modules as $id => $mod ) {
        $list[ $id ] = [
            'id'         => $id,
            'size'       => $mod['size'] ?? strlen( $mod['code'] ?? '' ),
            'size_human' => size_format( $mod['size'] ?? strlen( $mod['code'] ?? '' ) ),
            'updated_at' => $mod['updated_at'] ?? 'unknown',
        ];
    }

    return $list;
}

// ═══════════════════════════════════════════════════════════════
//  REGENERATE wpilot-site.php FROM STORED MODULES
// ═══════════════════════════════════════════════════════════════

function wpilot_regenerate_mu() {
    $modules  = get_option( WPILOT_MU_OPTION, [] );
    $mu_dir   = wpilot_mu_dir();
    $mu_path  = $mu_dir . '/' . WPILOT_MU_FILENAME;

    if ( empty( $modules ) ) {
        if ( file_exists( $mu_path ) ) @unlink( $mu_path );
        return true;
    }

    // Sort modules by defined load order; unknown modules go last
    $order    = wpilot_mu_load_order();
    $order_map = array_flip( $order );
    uksort( $modules, function( $a, $b ) use ( $order_map ) {
        $pos_a = $order_map[ $a ] ?? 999;
        $pos_b = $order_map[ $b ] ?? 999;
        return $pos_a - $pos_b;
    });

    $count = count( $modules );
    $hash  = substr( md5( serialize( $modules ) ), 0, 12 );
    $date  = current_time( 'Y-m-d H:i:s' );

    // Build the consolidated file
    $output  = "<?php\n";
    $output .= "// WPilot Site Module Loader — Auto-generated, do not edit\n";
    $output .= "// Version: {$hash} | Modules: {$count} | Generated: {$date}\n";
    $output .= "if (!defined('ABSPATH')) exit;\n\n";

    foreach ( $modules as $id => $mod ) {
        $code = $mod['code'] ?? '';
        if ( empty( $code ) ) continue;

        // Strip opening <?php tag if present
        $code = preg_replace( '/^<\?php\s*/i', '', trim( $code ) );

        $safe_id = esc_attr( $id );
        $output .= "// Module: {$safe_id}\n";
        $output .= "(function() {\n";
        $output .= "    try {\n";

        // Indent each line of the module code
        $lines = explode( "\n", $code );
        foreach ( $lines as $line ) {
            $output .= "        " . $line . "\n";
        }

        $output .= "    } catch (\\Throwable \$e) {\n";
        $output .= "        error_log('WPilot mu-module {$safe_id} error: ' . \$e->getMessage());\n";
        $output .= "    }\n";
        $output .= "})();\n\n";
    }

    $result = file_put_contents( $mu_path, $output );
    return $result !== false;
}

// ═══════════════════════════════════════════════════════════════
//  CLEANUP — Migrate old wpilot-*.php files into consolidated
// ═══════════════════════════════════════════════════════════════

function wpilot_mu_cleanup() {
    $mu_dir = wpilot_mu_dir();
    $migrated = [];
    $removed  = [];
    $errors   = [];

    // Map old filenames to module IDs
    $file_map = [
        'wpilot-blueprint-fonts.php'     => 'fonts',
        'wpilot-blueprint-override.php'  => 'css-override',
        'wpilot-custom-header.php'       => 'header',
        'wpilot-custom-footer.php'       => 'footer',
        'wpilot-head-styles.php'         => 'head-styles',
        'wpilot-lazy-load.php'           => 'lazy-load',
        'wpilot-mobile-nav.php'          => 'mobile-nav',
        'wpilot-pwa-sw.php'              => 'pwa',
        'wpilot-theme-override.php'      => 'theme-override',
        'wpilot-woo-filter.php'          => 'woo-filter',
    ];

    $modules = get_option( WPILOT_MU_OPTION, [] );

    foreach ( $file_map as $filename => $module_id ) {
        $path = $mu_dir . '/' . $filename;
        if ( ! file_exists( $path ) ) continue;

        $code = file_get_contents( $path );
        if ( $code === false ) {
            $errors[] = "Could not read {$filename}";
            continue;
        }

        // Only migrate if module doesn't already exist in the consolidated system
        if ( ! isset( $modules[ $module_id ] ) ) {
            $modules[ $module_id ] = [
                'code'       => $code,
                'size'       => strlen( $code ),
                'updated_at' => current_time( 'mysql' ),
            ];
            $migrated[] = $filename . ' -> ' . $module_id;
        }

        // Remove the old file
        if ( @unlink( $path ) ) {
            $removed[] = $filename;
        } else {
            $errors[] = "Could not delete {$filename}";
        }
    }

    // Also catch timestamped head code files: wpilot-custom-head-*.php
    $head_files = glob( $mu_dir . '/wpilot-custom-head-*.php' );
    if ( $head_files ) {
        foreach ( $head_files as $hf ) {
            $fname = basename( $hf );
            $code  = file_get_contents( $hf );
            if ( $code !== false && ! isset( $modules['head-code'] ) ) {
                $modules['head-code'] = [
                    'code'       => $code,
                    'size'       => strlen( $code ),
                    'updated_at' => current_time( 'mysql' ),
                ];
                $migrated[] = $fname . ' -> head-code';
            }
            if ( @unlink( $hf ) ) {
                $removed[] = $fname;
            }
        }
    }

    // Also remove any other leftover wpilot-*.php files (not wpilot-site.php)
    $leftover = glob( $mu_dir . '/wpilot-*.php' );
    if ( $leftover ) {
        foreach ( $leftover as $lf ) {
            $lname = basename( $lf );
            if ( $lname === WPILOT_MU_FILENAME ) continue; // Don't remove our consolidated file
            if ( @unlink( $lf ) ) {
                $removed[] = $lname . ' (leftover)';
            }
        }
    }

    // Save modules and regenerate
    update_option( WPILOT_MU_OPTION, $modules, false );
    wpilot_regenerate_mu();

    return [
        'migrated' => $migrated,
        'removed'  => $removed,
        'errors'   => $errors,
        'total_modules' => count( $modules ),
    ];
}


// ═══════════════════════════════════════════════════════════════
//  AI BUBBLE TOOLS — mu_status + mu_cleanup
// ═══════════════════════════════════════════════════════════════

function wpilot_run_mu_tools( $tool, $params = [] ) {
    switch ( $tool ) {

        // ── MU Status ─────────────────────────────────────────
        case 'mu_status':
            $modules  = wpilot_mu_list();
            $mu_dir   = wpilot_mu_dir();
            $mu_path  = $mu_dir . '/' . WPILOT_MU_FILENAME;
            $file_ok  = file_exists( $mu_path );

            // Check for old scattered files
            $old_files = glob( $mu_dir . '/wpilot-*.php' );
            $old_count = 0;
            $old_names = [];
            if ( $old_files ) {
                foreach ( $old_files as $of ) {
                    $name = basename( $of );
                    if ( $name === WPILOT_MU_FILENAME ) continue;
                    $old_count++;
                    $old_names[] = $name;
                }
            }

            $total_size = 0;
            $module_list = '';
            foreach ( $modules as $id => $mod ) {
                $total_size += $mod['size'];
                $module_list .= "  - {$id}: {$mod['size_human']} (updated {$mod['updated_at']})\n";
            }

            $msg = count( $modules ) . " mu-modules active";
            if ( $file_ok ) {
                $msg .= ", consolidated file OK (" . size_format( filesize( $mu_path ) ) . ")";
            } else {
                $msg .= ", consolidated file MISSING";
            }

            if ( $old_count > 0 ) {
                $msg .= ". WARNING: {$old_count} old scattered mu-plugins found: " . implode( ', ', $old_names ) . ". Run mu_cleanup to migrate.";
            }

            $msg .= ".\n\nModules:\n" . $module_list;

            return wpilot_ok( $msg, [
                'modules'        => $modules,
                'total_modules'  => count( $modules ),
                'total_size'     => $total_size,
                'file_exists'    => $file_ok,
                'old_files'      => $old_names,
                'old_file_count' => $old_count,
            ]);

        // ── MU Cleanup ────────────────────────────────────────
        case 'mu_cleanup':
            $result = wpilot_mu_cleanup();

            $msg = "MU cleanup complete. ";
            if ( ! empty( $result['migrated'] ) ) {
                $msg .= count( $result['migrated'] ) . " modules migrated: " . implode( ', ', $result['migrated'] ) . ". ";
            }
            if ( ! empty( $result['removed'] ) ) {
                $msg .= count( $result['removed'] ) . " old files removed: " . implode( ', ', $result['removed'] ) . ". ";
            }
            if ( ! empty( $result['errors'] ) ) {
                $msg .= "Errors: " . implode( ', ', $result['errors'] ) . ". ";
            }
            $msg .= "Total modules now: {$result['total_modules']}.";

            return wpilot_ok( $msg, $result );

        default:
            return null;
    }
}
