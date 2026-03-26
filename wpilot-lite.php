<?php
/**
 * Plugin Name:  WPilot Lite — AI WordPress Assistant
 * Plugin URI:   https://weblease.se/wpilot
 * Description:  Connect Claude AI to your WordPress site via MCP. Manage content, design, SEO, WooCommerce, and more — just by chatting.
 * Version:      1.0.0
 * Author:       Weblease
 * Author URI:   https://weblease.se
 * License:      GPL-2.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  wpilot-lite
 * Domain Path:  /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPILOT_LITE_VERSION', '1.0.0' );
define( 'WPILOT_LITE_FILE', __FILE__ );

// If Pro version is active, deactivate Lite (Pro takes priority)
if ( in_array( 'wpilot/wpilot.php', get_option( 'active_plugins', [] ), true ) ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-warning"><p>';
        echo wp_kses_post(
            sprintf(
                /* translators: 1: plugin name Lite, 2: plugin name Pro */
                __( '%1$s deactivated — %2$s is active.', 'wpilot-lite' ),
                '<strong>WPilot Lite</strong>',
                '<strong>WPilot Pro</strong>'
            )
        );
        echo '</p></div>';
    } );
    deactivate_plugins( plugin_basename( __FILE__ ) );
    return;
}
add_action( 'init', function () {
    load_plugin_textdomain( 'wpilot-lite', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
} );

add_action( 'rest_api_init', 'wpilot_lite_register_routes' );
add_action( 'admin_menu', 'wpilot_lite_register_admin' );
add_action( 'admin_init', 'wpilot_lite_handle_actions' );
// Built by Weblease
add_filter( 'plugin_row_meta', 'wpilot_lite_plugin_links', 10, 2 );

register_activation_hook( __FILE__, function () { add_option( 'wpilot_lite_do_activation_redirect', true ); } );
add_action( 'admin_init', function () {
    if ( ! current_user_can( 'manage_options' ) ) return;
    if ( get_option( 'wpilot_lite_do_activation_redirect', false ) ) {
        delete_option( 'wpilot_lite_do_activation_redirect' );
        wp_safe_redirect( admin_url( 'admin.php?page=wpilot-lite' ) );
        exit;
    }
} );

// ── Token System ──
function wpilot_lite_get_tokens() { return (array) get_option( 'wpilot_lite_tokens', [] ); }
function wpilot_lite_save_tokens( $t ) { update_option( 'wpilot_lite_tokens', $t, false ); }

function wpilot_lite_create_token( $style, $label ) {
    $raw = 'wpi_' . bin2hex( random_bytes( 32 ) );
    $tokens = wpilot_lite_get_tokens();
    $tokens[] = [ 'hash' => hash('sha256',$raw), 'style' => $style, 'label' => $label, 'created' => current_time('Y-m-d H:i'), 'last_used' => null ];
    wpilot_lite_save_tokens( $tokens );
    return $raw;
}

function wpilot_lite_validate_token( $raw ) {
    if ( empty($raw) ) return false;
    $hash = hash('sha256',$raw); $tokens = wpilot_lite_get_tokens();
    foreach ( $tokens as $i => $t ) {
        if ( hash_equals($t['hash'],$hash) ) { $tokens[$i]['last_used'] = current_time('Y-m-d H:i'); wpilot_lite_save_tokens($tokens); return $t; }
    }
    return false;
}

function wpilot_lite_revoke_token( $hash ) {
    $tokens = array_values( array_filter( wpilot_lite_get_tokens(), fn($t) => $t['hash'] !== $hash ) );
    wpilot_lite_save_tokens( $tokens );
}
function wpilot_lite_get_bearer_token() {
    $h = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';
    if ( empty( $h ) && isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
        $h = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
    }
    if ( function_exists('apache_request_headers') && empty($h) ) { $a = apache_request_headers(); if ( is_array($a) ) { $h = sanitize_text_field( $a['Authorization'] ?? $a['authorization'] ?? '' ); } }
    return preg_match('/^Bearer\s+(.+)$/i', $h, $m) ? trim($m[1]) : '';
}

// ── Usage Check (External: POST weblease.se/api/wpilot/usage) ──
// Sends: license_key, site_url, action. Returns: allowed, remaining, plan. Cached 60s.
function wpilot_lite_check_usage() {
    $ck = 'wpilot_lite_usage_' . gmdate('Y-m-d-H');
    $c = get_transient($ck); if ($c && !empty($c['allowed'])) return $c;
    $r = wp_remote_post('https://weblease.se/api/wpilot/usage', ['timeout'=>5,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['license_key'=>get_option('wpilot_lite_license_key',''),'site_url'=>get_site_url(),'action'=>'count'])]);
    if (is_wp_error($r)) return ['allowed'=>true,'remaining'=>-1,'plan'=>'unknown'];
    $b = json_decode(wp_remote_retrieve_body($r),true);
    $res = $b ?: ['allowed'=>true,'remaining'=>-1,'plan'=>'unknown'];
    set_transient($ck,$res,60); return $res;
}

// ── License (External: POST weblease.se/ai-license/validate) ──
// Sends: license_key, site_url, plugin, version. Returns: valid. Cached 1h.
function wpilot_lite_check_license() {
    $key = get_option('wpilot_lite_license_key',''); if (empty($key)) return 'free';
    $c = get_transient('wpilot_lite_license_status'); if ($c !== false) return $c;
    $r = wp_remote_post('https://weblease.se/ai-license/validate', ['timeout'=>10,'body'=>['license_key'=>$key,'site_url'=>get_site_url(),'plugin'=>'wpilot-lite','version'=>WPILOT_LITE_VERSION]]);
    if (is_wp_error($r)) {
        $fb = get_option('wpilot_lite_last_known_license', 'free');
        set_transient('wpilot_lite_license_status', $fb, 300);
        return $fb;
    }
    $s = (json_decode(wp_remote_retrieve_body($r),true)['valid'] ?? false) ? 'valid' : 'expired';
    set_transient('wpilot_lite_license_status',$s,3600);
    update_option('wpilot_lite_last_known_license', $s, false);
    return $s;
}
function wpilot_lite_is_licensed() { return wpilot_lite_check_license() === 'valid'; }

// ── REST Routes ──
function wpilot_lite_register_routes() {
    register_rest_route('wpilot-lite/v1','/connection-status',['methods'=>'GET','callback'=>'wpilot_lite_connection_status','permission_callback'=>function(){ return current_user_can('manage_options'); }]);
    register_rest_route('wpilot-lite/v1','/mcp',['methods'=>['GET','POST','DELETE'],'callback'=>'wpilot_lite_mcp_endpoint','permission_callback'=>'__return_true']);
}
function wpilot_lite_connection_status() {
    $last = intval(get_option('wpilot_lite_claude_last_seen',0));
    return new WP_REST_Response(['connected'=>(time()-$last)<45,'last_seen'=>$last>0?human_time_diff($last).' ago':'never']);
}

// ── MCP Server (JSON-RPC 2.0) ──
function wpilot_lite_mcp_endpoint( $request ) {
    $method = $request->get_method();
    if ($method==='GET') {
        $response = new WP_REST_Response(['jsonrpc'=>'2.0','error'=>['code'=>-32600,'message'=>'Use POST.'],'id'=>null],200);
        $response->header('Cache-Control', 'no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }
    if ($method==='DELETE') return new WP_REST_Response(null,204);

    $token_data = wpilot_lite_validate_token(wpilot_lite_get_bearer_token());
    if (!$token_data) {
        $response = new WP_REST_Response(['jsonrpc'=>'2.0','error'=>['code'=>-32000,'message'=>'Unauthorized.'],'id'=>null],401);
        $response->header('Cache-Control', 'no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    $style = $token_data['style'] ?? 'simple';
    update_option('wpilot_lite_claude_last_seen',time(),false);

    // Rate limit
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
    $tk = 'wpilot_lite_rl_'.substr($token_data['hash'],0,16); $ik = 'wpilot_lite_rl_ip_'.md5($ip);
    $tc = intval(get_transient($tk)?:0); $ic = intval(get_transient($ik)?:0);
    if ($tc>=60||$ic>=120) {
        $response = new WP_REST_Response(['jsonrpc'=>'2.0','error'=>['code'=>-32000,'message'=>'Rate limit.'],'id'=>null],429);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
    set_transient($tk,$tc+1,60); set_transient($ik,$ic+1,60);

    $body = $request->get_json_params(); $rpc = $body['method']??''; $params = $body['params']??[]; $id = $body['id']??null;
    switch ($rpc) {
        case 'initialize': return wpilot_lite_rpc_ok($id,['protocolVersion'=>'2025-03-26','capabilities'=>['tools'=>(object)[]],'serverInfo'=>['name'=>'wpilot-lite','version'=>WPILOT_LITE_VERSION],'instructions'=>wpilot_lite_system_prompt($style)]);
        case 'notifications/initialized': return new WP_REST_Response(null,204);
        case 'tools/list': return wpilot_lite_rpc_ok($id,['tools'=>[wpilot_lite_tool_definition()]]);
        case 'tools/call': return wpilot_lite_handle_execute($id,$params,$style);
        default: return new WP_REST_Response(['jsonrpc'=>'2.0','error'=>['code'=>-32601,'message'=>'Unknown method.'],'id'=>$id],200);
    }
}
function wpilot_lite_rpc_ok($id,$result) { return new WP_REST_Response(['jsonrpc'=>'2.0','result'=>$result,'id'=>$id],200); }
function wpilot_lite_rpc_tool_result($id,$text,$err) { return new WP_REST_Response(['jsonrpc'=>'2.0','result'=>['content'=>[['type'=>'text','text'=>$text]],'isError'=>$err],'id'=>$id],200); }

// ── Tool Definition ──
function wpilot_lite_tool_definition() {
    return ['name'=>'wordpress','description'=>'Run WordPress PHP code on this site. All WP functions available. Use return to output data.','inputSchema'=>['type'=>'object','properties'=>['action'=>['type'=>'string','description'=>'PHP code to run. No opening tag. Use return for output.']],'required'=>['action']]];
}

// ── System Prompt ──
function wpilot_lite_system_prompt( $style = 'simple' ) {
    $sn = get_bloginfo('name')?:'this website'; $su = get_site_url();
    $pr = (array) get_option('wpilot_lite_site_profile',[]);
    $lang = get_locale(); $u = wpilot_lite_check_usage(); $rem = isset($u['remaining'])&&$u['remaining']>=0?$u['remaining']:'?';
    $lic = wpilot_lite_is_licensed(); $plan = $lic?'Pro — unlimited':"Lite — {$rem} left today";
    $own = $pr['owner_name']??''; $biz = $pr['business_type']??''; $tone = $pr['tone']??'friendly and professional';
    // Built by Weblease
    $lg = $pr['language']??''; if(empty($lg)) $lg = str_starts_with($lang,'sv')?'Swedish':'the same language the user writes in';
    $p = "You are WPilot, a WordPress assistant connected to \"{$sn}\" ({$su}).\nTheme: ".wp_get_theme()->get('Name').". Language: {$lang}.\n";
    if(class_exists('WooCommerce'))$p.="WooCommerce is active.\n";
    $p.="Plan: {$plan}\n"; if($own)$p.="Owner: {$own}\n"; if($biz)$p.="Business: {$biz}\n";
    $p.="\nCOMMUNICATION:\n- Language: {$lg}\n- Tone: {$tone}\n- Style: {$style}\n";
    $p.=$style==='technical'?"- Include IDs and technical details.\n":"- Simple language. Focus on results.\n";
    $p.="\nTOOLS:\nOne tool: 'wordpress'. Runs PHP inside WordPress. All WP functions available.\n";
    $p.="\nAPPROACH:\n- Check before changing.\n- Confirm destructive actions.\n- Break large tasks into steps.\n";
    $p.="\nSECURITY:\n- Never expose credentials.\n- Don't modify wp-config.php or .htaccess.\n- Don't tamper with WPilot settings.\n";
    return $p;
}

// ── Sandbox Violation ──
function wpilot_lite_sandbox_violation($id,$msg) {
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
    $k='wpilot_lite_attacks_'.md5($ip);
    $c=intval(get_transient($k)?:0)+1; set_transient($k,$c,3600);
    if($c>=5)set_transient('wpilot_lite_ban_'.md5($ip),true,86400);
    return wpilot_lite_rpc_tool_result($id,$msg,true);
}

// ── Execute Handler ──
// NOTE: Uses eval() intentionally — this is the core MCP feature.
// Authenticated (token), rate-limited (60/min), sandboxed (security checks).
function wpilot_lite_handle_execute($id,$params,$style='simple') {
    $code = $params['arguments']['action'] ?? $params['arguments']['code'] ?? '';
    $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
    if(get_transient('wpilot_lite_ban_'.md5($ip))) return wpilot_lite_rpc_tool_result($id,'Blocked.',true);
    if(empty($code)) return wpilot_lite_rpc_tool_result($id,'No action.',true);
    $uc = wpilot_lite_check_usage();
    if(empty($uc['allowed'])) return wpilot_lite_rpc_tool_result($id,$uc['message']??'Limit reached.',true);
    if(strlen($code)>51200) return wpilot_lite_rpc_tool_result($id,'Too large.',true);

    // Sandbox checks
    if(preg_match('/\b(exec|shell_exec|system|passthru|popen|proc_open|pcntl_exec|pcntl_fork)\s*\(/i',$code)) return wpilot_lite_sandbox_violation($id,'Shell commands blocked.');
    if(preg_match('/\beval\s*\(|\bassert\s*\(|\bcreate_function\s*\(/',$code)) return wpilot_lite_sandbox_violation($id,'Dynamic code execution blocked.');
    if(preg_match('/\b(include|require|include_once|require_once)\s*[\s(]/i',$code)) return wpilot_lite_sandbox_violation($id,'File inclusion blocked.');
    if(preg_match('/\$_(SERVER|ENV|REQUEST|COOKIE|SESSION)\b/',$code)) return wpilot_lite_sandbox_violation($id,'Use WP functions instead.');
    if(preg_match('/\b(phpinfo|php_uname|getenv|putenv|get_defined_constants|get_defined_vars)\s*\(/i',$code)) return wpilot_lite_sandbox_violation($id,'Server info blocked.');
    if(preg_match('/\b(call_user_func|call_user_func_array|forward_static_call|forward_static_call_array)\s*\(/i',$code)) return wpilot_lite_sandbox_violation($id,'Indirect calls blocked.');
    if(preg_match('/\b(file_get_contents|file_put_contents|fopen|fwrite|fread|fclose|readfile|file|unlink|rename|copy|mkdir|rmdir|glob|scandir|symlink|link|chmod|chown|chgrp|tempnam|tmpfile)\s*\(/i',$code)) return wpilot_lite_sandbox_violation($id,'Use WP Filesystem API instead.');
    if(preg_match('/\b(mail|ini_set|ini_alter|ini_restore|putenv|dl|set_time_limit|set_include_path)\s*\(/i',$code)) return wpilot_lite_sandbox_violation($id,'Function blocked.');
    foreach(['DB_PASSWORD','DB_USER','DB_HOST','DB_NAME','AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT','LOGGED_IN_SALT','NONCE_SALT'] as $s) { if(strpos($code,$s)!==false) return wpilot_lite_sandbox_violation($id,'Credential access blocked.'); }
    foreach(['wpilot_lite_tokens','wpilot_lite_license_key','wpilot_lite_license_status','wpilot_oauth_clients','wpilot_oauth_codes','wp-config.php','.htaccess'] as $p) { if(strpos($code,$p)!==false) return wpilot_lite_sandbox_violation($id,'WPilot internals protected.'); }
    if(preg_match('/\bquery\s*\(.*\b(DROP|TRUNCATE|ALTER|GRANT|REVOKE)\b/i',$code)) return wpilot_lite_sandbox_violation($id,'Destructive SQL blocked.');
    if(preg_match('/\b(curl_init|fsockopen|stream_socket|socket_create)\s*\(/i',$code)) return wpilot_lite_sandbox_violation($id,'Use wp_remote_get/post.');
    // Extended dangerous function list
    $df=['exec','shell_exec','system','passthru','popen','proc_open','phpinfo','eval','assert','create_function','unserialize','call_user_func','call_user_func_array','forward_static_call','forward_static_call_array'];
    foreach($df as $f) { if(preg_match('/["\x27]'.preg_quote($f,'/').  '["\x27]/i',$code)) return wpilot_lite_sandbox_violation($id,'Blocked.'); }
    if(preg_match('/Closure\s*::\s*fromCallable|new\s+class\b/i',$code)) return wpilot_lite_sandbox_violation($id,'Blocked.');
    foreach($df as $f) { if(preg_match('/\b'.preg_quote($f,'/').'\s*\(/i',$code)) return wpilot_lite_sandbox_violation($id,'Not allowed.'); }
    if(preg_match('/preg_replace\s*\(\s*[\'"].*\/[a-z]*e[a-z]*[\'"]/i',$code)) return wpilot_lite_sandbox_violation($id,'Not allowed.');
    if(preg_match('/\b(array_map|array_filter|usort|uasort|uksort)\s*\(\s*[\'"][a-z_]+[\'"]/i',$code)) return wpilot_lite_sandbox_violation($id,'Not allowed.');
    // Backtick operator, variable variables
    if(preg_match('/`[^`]+`/',$code)||preg_match('/\$\$[a-zA-Z_]/',$code)||preg_match('/\$\{/',$code)) return wpilot_lite_sandbox_violation($id,'Not allowed.');
    foreach($df as $f) { if(strlen($f)<4)continue; $st=str_replace(['"',"'","."," "],'',$code); if(stripos($st,$f.'(')!==false&&!preg_match('/\b'.preg_quote($f).'\s*\(/i',$code)) return wpilot_lite_sandbox_violation($id,'Not allowed.'); }
    if(preg_match('/\bpack\s*\(/i',$code)||preg_match('/sprintf\s*\([^)]*%c/i',$code)) return wpilot_lite_sandbox_violation($id,'Not allowed.');

    // NOTE: eval() is intentional — sandboxed MCP code execution (core feature).
    @set_time_limit(30); ob_start(); $rv=null; $err=null;
    try { $fn = function() use($code) { return eval($code); }; $rv = $fn(); } catch(\Throwable $e) { $err=$e->getMessage(); }
    $out = ob_get_clean();

    if($err) { $c=preg_replace('/\s+on line \d+/','',$err); $c=preg_replace('/\s+in\s+\/[^\s]+/','',$c); $c=preg_replace('/\/[a-z0-9\/._-]*\.(php|html|js)/i','[path]',$c); $c=preg_replace('/Stack trace:[\s\S]*$/','',$c); $c=trim(preg_replace('/\s+/',' ',$c)); if(strlen($c)>200)$c=substr($c,0,200).'...'; return wpilot_lite_rpc_tool_result($id,"Error: {$c}",true); }

    $result='';
    if($rv!==null&&$rv!=='') $result=is_array($rv)||is_object($rv)?json_encode($rv,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE):(string)$rv;
    if(!empty($out)) $result=$result?$result."\n\nOutput:\n".$out:$out;
    if(empty($result))$result='Done.';
    if(strlen($result)>50000)$result=substr($result,0,50000)."\n[Truncated]";
    if(isset($uc['remaining'])&&$uc['remaining']>=0) $result.="\n\n[WPilot: {$uc['remaining']} requests remaining today]";
    return wpilot_lite_rpc_tool_result($id,$result,false);
}

function wpilot_lite_plugin_links($links,$file) {
    if($file==='wpilot-lite/wpilot-lite.php') {
        $links[]='<a href="https://weblease.se/wpilot" target="_blank">' . esc_html__('Docs', 'wpilot-lite') . '</a>';
        $links[]='<a href="https://weblease.se/wpilot-checkout" target="_blank" style="color:#22c55e;font-weight:600;">' . esc_html__('Upgrade to Pro', 'wpilot-lite') . '</a>';
    }
    return $links;
}

// ── Admin Actions ──
// Built by Weblease — Quiet_Skin for silent plugin installation
if (!class_exists('Quiet_Skin')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    class Quiet_Skin extends WP_Upgrader_Skin {
        public function feedback($feedback, ...$args) {}
        public function header() {}
        public function footer() {}
    }
}

function wpilot_lite_handle_actions() {
    if(!current_user_can('manage_options')||!isset($_POST['wpilot_action']))return; // Built by Weblease
    if(!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce']??'')),'wpilot_lite_admin'))return;
    $a=sanitize_text_field($_POST['wpilot_action']);
    if($a==='save_profile'){update_option('wpilot_lite_site_profile',['owner_name'=>sanitize_text_field(wp_unslash($_POST['owner_name']??'')),'business_type'=>sanitize_text_field(wp_unslash($_POST['business_type']??'')),'tone'=>sanitize_text_field(wp_unslash($_POST['tone']??'friendly and professional')),'language'=>sanitize_text_field(wp_unslash($_POST['language']??'')),'completed'=>true]);wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-settings&saved=profile'));exit;}
    if($a==='create_token'){if(count(wpilot_lite_get_tokens())>=3){wp_safe_redirect(admin_url('admin.php?page=wpilot-lite&error=limit'));exit;}if(empty(trim($_POST['token_label']??''))){wp_safe_redirect(admin_url('admin.php?page=wpilot-lite&error=name'));exit;}$st=in_array(wp_unslash($_POST['token_style']??''),['simple','technical'],true)?sanitize_text_field(wp_unslash($_POST['token_style'])):'simple';$lb=sanitize_text_field(wp_unslash($_POST['token_label']??''))?:'My connection';$raw=wpilot_lite_create_token($st,$lb);set_transient('wpilot_lite_new_token',$raw,120);set_transient('wpilot_lite_new_token_label',$lb,120);wp_safe_redirect(admin_url('admin.php?page=wpilot-lite&saved=token'));exit;}
    if($a==='revoke_token'){$h=sanitize_text_field(wp_unslash($_POST['token_hash']??''));if($h)wpilot_lite_revoke_token($h);wp_safe_redirect(admin_url('admin.php?page=wpilot-lite&saved=revoked'));exit;}
    if($a==='save_license'){$k=sanitize_text_field(wp_unslash($_POST['license_key']??''));if(!empty($k)){update_option('wpilot_lite_license_key',$k);delete_transient('wpilot_lite_license_status');$s=wpilot_lite_check_license();wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-plan&saved=license&status='.$s));}else{wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-plan&error=nokey'));}exit;}
    if($a==='remove_license'){delete_option('wpilot_lite_license_key');delete_option('wpilot_lite_last_known_license');delete_transient('wpilot_lite_license_status');wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-plan&saved=removed'));exit;}
    if($a==='send_feedback'){
        $msg=sanitize_textarea_field(wp_unslash($_POST['feedback_message']??''));
        $allowed_types = ['feedback','bug','feature','question'];
        $type = sanitize_text_field(wp_unslash($_POST['feedback_type']??'feedback'));
        if(!in_array($type, $allowed_types, true)) { $type = 'feedback'; }
        if(!empty($msg)){
            wp_mail('info@weblease.se','[WPilot Lite] '.$type,$msg."\n\nSite: ".get_site_url()."\nEmail: ".get_option('admin_email'));
            wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-help&saved=feedback'));
        } else {
            wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-help&error=empty'));
        }
        exit;
    }
    if($a==='upgrade_to_pro'){
        $key=sanitize_text_field(wp_unslash($_POST['pro_license_key']??''));
        if(empty($key)){wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-plan&error=empty_key'));exit;}
        // Validate license against weblease.se
        $response=wp_remote_post('https://weblease.se/api/ai-license/validate',['timeout'=>15,'headers'=>['Content-Type'=>'application/json'],'body'=>wp_json_encode(['license_key'=>$key,'site_url'=>get_site_url()])]);
        if(is_wp_error($response)){wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-plan&error=server'));exit;}
        $body=json_decode(wp_remote_retrieve_body($response),true);
        if(empty($body['valid'])){wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-plan&error=invalid_key'));exit;}
        // License valid — download and install Pro
        // Built by Weblease
        $download_url='https://weblease.se/api/plugin/download?license='.urlencode($key);
        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/plugin-install.php';
        require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH.'wp-admin/includes/plugin.php';
        $tmp_file=download_url($download_url,60);
        if(is_wp_error($tmp_file)){wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-plan&error=download'));exit;}
        $upgrader=new Plugin_Upgrader(new Quiet_Skin());
        $result=$upgrader->install($tmp_file);
        @unlink($tmp_file);
        if(is_wp_error($result)||!$result){wp_safe_redirect(admin_url('admin.php?page=wpilot-lite-plan&error=install'));exit;}
        // Save license key for Pro
        update_option('wpilot_license_key',$key);
        if(!empty($body['engine_key'])){update_option('wpilot_engine_key',sanitize_text_field($body['engine_key']),false);}
        if(!empty($body['prompt_key'])){update_option('wpilot_prompt_key',sanitize_text_field($body['prompt_key']),false);}
        // Migrate Lite settings to Pro
        $lite_profile=get_option('wpilot_lite_site_profile',null);
        if($lite_profile!==null&&$lite_profile!==false){
            $existing=get_option('wpilot_site_profile',[]);
            if(empty($existing)){update_option('wpilot_site_profile',$lite_profile);}
        }
        $lite_tokens=get_option('wpilot_lite_tokens',null);
        if($lite_tokens!==null&&$lite_tokens!==false){
            $pro_tokens=get_option('wpilot_tokens',[]);
            if(!is_array($pro_tokens))$pro_tokens=[];
            if(!is_array($lite_tokens))$lite_tokens=[];
            update_option('wpilot_tokens',array_merge($pro_tokens,$lite_tokens));
        }
        // Deactivate Lite, activate Pro
        deactivate_plugins('wpilot-lite/wpilot-lite.php');
        activate_plugin('wpilot/wpilot.php');
        wp_safe_redirect(admin_url('admin.php?page=wpilot&upgraded=yes'));
        exit;
    }
}

// ── Admin Menu ──
function wpilot_lite_register_admin() {
    $b=wpilot_lite_is_licensed()?' <span style="font-size:9px;background:rgba(78,201,176,.25);color:#4ec9b0;padding:2px 7px;border-radius:10px;font-weight:700;vertical-align:middle;margin-left:4px;">PRO</span>':' <span style="font-size:9px;background:rgba(234,179,8,.25);color:#eab308;padding:2px 7px;border-radius:10px;font-weight:700;vertical-align:middle;margin-left:4px;">LITE</span>';
    add_menu_page('WPilot Lite','WPilot'.$b,'manage_options','wpilot-lite','wpilot_lite_page_connect','dashicons-cloud',80);
    add_submenu_page('wpilot-lite', esc_html__('Connect','wpilot-lite'), esc_html__('Connect','wpilot-lite'),'manage_options','wpilot-lite','wpilot_lite_page_connect');
    add_submenu_page('wpilot-lite', esc_html__('Plan','wpilot-lite'), esc_html__('Plan','wpilot-lite'),'manage_options','wpilot-lite-plan','wpilot_lite_page_plan');
    add_submenu_page('wpilot-lite', esc_html__('Settings','wpilot-lite'), esc_html__('Settings','wpilot-lite'),'manage_options','wpilot-lite-settings','wpilot_lite_page_settings');
    add_submenu_page('wpilot-lite', esc_html__('Help','wpilot-lite'), esc_html__('Help','wpilot-lite'),'manage_options','wpilot-lite-help','wpilot_lite_page_help');
}

// ── Admin CSS ──
function wpilot_lite_enqueue_admin_styles( $hook ) {
    if ( strpos( $hook, 'wpilot' ) === false ) return;
    wp_register_style( 'wpilot-lite-admin', false );
    wp_enqueue_style( 'wpilot-lite-admin' );
    wp_add_inline_style( 'wpilot-lite-admin', '#wpbody-content .wpi *{box-sizing:border-box!important}#wpbody-content .wpi{max-width:860px!important;margin:0 auto!important;padding:24px 0 60px!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif!important;color:#1e293b!important;line-height:1.6!important}#wpbody-content .wpi .wpi-hero{background:linear-gradient(135deg,#0f172a,#1e293b 50%,#0f3460)!important;border-radius:18px 18px 0 0!important;padding:38px 42px 34px!important;color:#fff!important;margin-bottom:0!important;position:relative!important;overflow:hidden!important}#wpbody-content .wpi .wpi-hero::before{content:""!important;position:absolute!important;top:-60%!important;right:-15%!important;width:340px!important;height:340px!important;background:radial-gradient(circle,rgba(78,201,176,.18) 0%,transparent 70%)!important;pointer-events:none!important}#wpbody-content .wpi .wpi-hero h1{font-size:30px!important;font-weight:800!important;margin:0 0 6px!important;display:flex!important;align-items:center!important;gap:12px!important;position:relative!important;z-index:1!important;color:#fff!important}#wpbody-content .wpi .wpi-hero .wpi-tagline{color:#94a3b8!important;font-size:14px!important;margin:0!important;position:relative!important;z-index:1!important}#wpbody-content .wpi .wpi-badge{font-size:10px!important;background:rgba(78,201,176,.2)!important;color:#4ec9b0!important;padding:3px 11px!important;border-radius:20px!important;font-weight:700!important;text-transform:uppercase!important}#wpbody-content .wpi .wpi-badge-lite{font-size:10px!important;background:rgba(234,179,8,.2)!important;color:#eab308!important;padding:3px 11px!important;border-radius:20px!important;font-weight:700!important;text-transform:uppercase!important}#wpbody-content .wpi .wpi-nav{display:flex!important;gap:2px!important;background:#f1f5f9!important;border-radius:0 0 18px 18px!important;padding:6px!important;margin-bottom:28px!important;flex-wrap:wrap!important}#wpbody-content .wpi .wpi-nav a{display:flex!important;align-items:center!important;gap:7px!important;padding:11px 20px!important;border-radius:12px!important;font-size:13px!important;font-weight:600!important;color:#64748b!important;text-decoration:none!important;flex:1!important;justify-content:center!important}#wpbody-content .wpi .wpi-nav a:hover{color:#1e293b!important;background:#fff!important}#wpbody-content .wpi .wpi-nav a.wpi-nav-active{background:#1e293b!important;color:#4ec9b0!important}#wpbody-content .wpi .wpi-nav a svg{width:16px!important;height:16px!important}#wpbody-content .wpi .wpi-card{background:#fff!important;border:1px solid #dde3ea!important;border-radius:16px!important;padding:32px!important;margin-bottom:22px!important;box-shadow:0 1px 3px rgba(0,0,0,.04)!important}#wpbody-content .wpi .wpi-card h2{margin:0 0 6px!important;font-size:18px!important;font-weight:700!important;display:flex!important;align-items:center!important;gap:10px!important}#wpbody-content .wpi .wpi-card .wpi-sub{color:#64748b!important;font-size:14px!important;margin:0 0 22px!important}#wpbody-content .wpi .wpi-tabs{display:flex!important;gap:4px!important;margin-bottom:24px!important}#wpbody-content .wpi .wpi-tab{display:flex!important;align-items:center!important;gap:8px!important;padding:10px 22px!important;border-radius:10px!important;border:2px solid #dde3ea!important;background:#fff!important;font-size:14px!important;font-weight:600!important;color:#64748b!important;cursor:pointer!important}#wpbody-content .wpi .wpi-tab.active{background:#1e293b!important;color:#4ec9b0!important;border-color:#1e293b!important}#wpbody-content .wpi .wpi-tab svg{width:18px!important;height:18px!important}#wpbody-content .wpi .wpi-tab-panel{display:none!important}#wpbody-content .wpi .wpi-tab-panel.active{display:block!important}#wpbody-content .wpi .wpi-step{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:36px!important;height:36px!important;border-radius:50%!important;background:linear-gradient(135deg,#22c55e,#16a34a)!important;color:#fff!important;font-size:15px!important;font-weight:800!important;flex-shrink:0!important;margin-right:16px!important}#wpbody-content .wpi .wpi-step-row{display:flex!important;align-items:flex-start!important;margin-bottom:24px!important}#wpbody-content .wpi .wpi-step-body h3{margin:0 0 4px!important;font-size:15px!important;font-weight:700!important}#wpbody-content .wpi .wpi-step-body p{margin:0!important;font-size:13px!important;color:#64748b!important}#wpbody-content .wpi .wpi-step-highlight{background:#f0fdf4!important;border:1px solid #bbf7d0!important;border-radius:10px!important;padding:10px 16px!important;margin-top:8px!important;font-size:13px!important;color:#166534!important;font-weight:600!important}#wpbody-content .wpi .wpi-code{display:block!important;padding:20px 22px!important;padding-right:90px!important;background:#0f172a!important;color:#5eead4!important;border-radius:12px!important;font-size:13px!important;font-family:"SF Mono",Monaco,Consolas,monospace!important;word-break:break-all!important;line-height:1.8!important;cursor:pointer!important;border:2px solid rgba(78,201,176,.15)!important;margin-top:10px!important;white-space:pre-wrap!important;position:relative!important}#wpbody-content .wpi .wpi-code::after{content:"COPY"!important;position:absolute!important;top:12px!important;right:12px!important;background:linear-gradient(135deg,#22c55e,#16a34a)!important;color:#fff!important;font-family:-apple-system,sans-serif!important;font-size:11px!important;font-weight:700!important;padding:6px 14px!important;border-radius:6px!important}#wpbody-content .wpi .wpi-code:hover{border-color:#4ec9b0!important}#wpbody-content .wpi .wpi-code.copied::after{content:"COPIED!"!important;background:#059669!important}#wpbody-content .wpi .wpi-field{margin-bottom:20px!important}#wpbody-content .wpi .wpi-field label{display:block!important;font-weight:600!important;font-size:13px!important;color:#374151!important;margin-bottom:6px!important}#wpbody-content .wpi .wpi-field .wpi-hint{display:block!important;font-size:12px!important;color:#94a3b8!important;margin-top:4px!important}#wpbody-content .wpi .wpi-field input[type=text],#wpbody-content .wpi .wpi-field textarea,#wpbody-content .wpi .wpi-field select{width:100%!important;padding:11px 16px!important;border:2px solid #dde3ea!important;border-radius:10px!important;font-size:14px!important;color:#1e293b!important;background:#fafbfc!important;font-family:inherit!important}#wpbody-content .wpi .wpi-field input:focus,#wpbody-content .wpi .wpi-field textarea:focus,#wpbody-content .wpi .wpi-field select:focus{border-color:#4ec9b0!important;outline:none!important;box-shadow:0 0 0 4px rgba(78,201,176,.15)!important}#wpbody-content .wpi .wpi-btn{display:inline-flex!important;align-items:center!important;gap:8px!important;padding:12px 26px!important;border-radius:10px!important;font-size:14px!important;font-weight:700!important;cursor:pointer!important;border:none!important;text-decoration:none!important}#wpbody-content .wpi .wpi-btn-dark{background:#1e293b!important;color:#fff!important}#wpbody-content .wpi .wpi-btn-green{background:linear-gradient(135deg,#22c55e,#16a34a)!important;color:#fff!important}#wpbody-content .wpi .wpi-btn-red{background:transparent!important;border:2px solid #fca5a5!important;color:#dc2626!important;padding:8px 18px!important;font-size:13px!important}#wpbody-content .wpi .wpi-btn-sm{padding:8px 16px!important;font-size:12px!important}#wpbody-content .wpi .wpi-alert{padding:16px 20px!important;border-radius:12px!important;margin-bottom:22px!important;font-size:14px!important}#wpbody-content .wpi .wpi-alert-ok{background:#f0fdf4!important;border:1px solid #bbf7d0!important;color:#166534!important}#wpbody-content .wpi .wpi-alert-warn{background:#fffbeb!important;border:1px solid #fde68a!important;color:#92400e!important}#wpbody-content .wpi .wpi-alert-info{background:#eff6ff!important;border:1px solid #bfdbfe!important;color:#1e40af!important}#wpbody-content .wpi .wpi-alert strong{display:block!important;margin-bottom:2px!important}#wpbody-content .wpi .wpi-table{width:100%!important;border-collapse:collapse!important;margin-top:22px!important}#wpbody-content .wpi .wpi-table th{text-align:left!important;font-size:11px!important;color:#94a3b8!important;text-transform:uppercase!important;padding:10px 14px!important;border-bottom:2px solid #f1f5f9!important;font-weight:700!important}#wpbody-content .wpi .wpi-table td{padding:14px!important;border-bottom:1px solid #f1f5f9!important;font-size:14px!important;color:#475569!important}#wpbody-content .wpi .wpi-style-badge{display:inline-block!important;padding:3px 12px!important;border-radius:20px!important;font-size:12px!important;font-weight:600!important}#wpbody-content .wpi .wpi-style-simple{background:#e0f2fe!important;color:#0284c7!important}#wpbody-content .wpi .wpi-style-technical{background:#ede9fe!important;color:#7c3aed!important}#wpbody-content .wpi .wpi-pricing{display:grid!important;grid-template-columns:repeat(3,1fr)!important;gap:18px!important;margin-top:24px!important}#wpbody-content .wpi .wpi-plan{background:#fff!important;border:2px solid #dde3ea!important;border-radius:16px!important;padding:30px 24px!important;text-align:center!important;position:relative!important}#wpbody-content .wpi .wpi-plan-pop{border-color:#4ec9b0!important}#wpbody-content .wpi .wpi-plan-pop::before{content:"Most popular"!important;position:absolute!important;top:-13px!important;left:50%!important;transform:translateX(-50%)!important;background:linear-gradient(135deg,#4ec9b0,#22c55e)!important;color:#fff!important;font-size:11px!important;font-weight:700!important;padding:4px 18px!important;border-radius:20px!important;text-transform:uppercase!important}#wpbody-content .wpi .wpi-plan h3{margin:0 0 8px!important;font-size:18px!important;font-weight:800!important}#wpbody-content .wpi .wpi-plan .wpi-price{font-size:38px!important;font-weight:800!important;margin:14px 0 4px!important}#wpbody-content .wpi .wpi-plan .wpi-price span{font-size:15px!important;font-weight:400!important;color:#94a3b8!important}#wpbody-content .wpi .wpi-plan .wpi-price-note{font-size:13px!important;color:#94a3b8!important;margin:0 0 18px!important}#wpbody-content .wpi .wpi-plan ul{list-style:none!important;padding:0!important;margin:0 0 22px!important;text-align:left!important}#wpbody-content .wpi .wpi-plan ul li{padding:7px 0!important;font-size:13px!important;color:#475569!important;border-bottom:1px solid #f1f5f9!important}#wpbody-content .wpi .wpi-plan ul li:last-child{border:none!important}#wpbody-content .wpi .wpi-plan ul li::before{content:"\2713"!important;color:#4ec9b0!important;font-weight:700!important;margin-right:8px!important}#wpbody-content .wpi .wpi-plan .wpi-btn{width:100%!important;justify-content:center!important}#wpbody-content .wpi .wpi-grid-2{display:grid!important;grid-template-columns:1fr 1fr!important;gap:18px!important}#wpbody-content .wpi .wpi-examples{display:grid!important;grid-template-columns:1fr 1fr!important;gap:16px!important;margin-top:18px!important}#wpbody-content .wpi .wpi-example{background:#f8fafc!important;border-radius:12px!important;padding:20px 22px!important;border:1px solid #f1f5f9!important}#wpbody-content .wpi .wpi-example h3{font-size:12px!important;color:#94a3b8!important;text-transform:uppercase!important;margin:0 0 10px!important;font-weight:700!important}#wpbody-content .wpi .wpi-example p{margin:0!important;padding:6px 0!important;color:#475569!important;font-size:13px!important;font-style:italic!important;border-bottom:1px solid #f1f5f9!important}#wpbody-content .wpi .wpi-example p:last-child{border:none!important}#wpbody-content .wpi .wpi-info-row{display:grid!important;grid-template-columns:repeat(3,1fr)!important;gap:14px!important;margin-top:24px!important}#wpbody-content .wpi .wpi-info-item{background:#f8fafc!important;border:1px solid #f1f5f9!important;border-radius:12px!important;padding:18px!important;text-align:center!important}#wpbody-content .wpi .wpi-info-item h4{margin:8px 0 4px!important;font-size:13px!important;font-weight:700!important}#wpbody-content .wpi .wpi-info-item p{margin:0!important;font-size:12px!important;color:#64748b!important}#wpbody-content .wpi .wpi-radios{display:flex!important;gap:6px!important;flex-wrap:wrap!important;margin-bottom:16px!important}#wpbody-content .wpi .wpi-radios label{display:flex!important;align-items:center!important;gap:6px!important;padding:8px 16px!important;border-radius:8px!important;border:2px solid #dde3ea!important;font-size:13px!important;font-weight:600!important;color:#64748b!important;cursor:pointer!important}#wpbody-content .wpi .wpi-radios input[type=radio]{display:none!important}#wpbody-content .wpi .wpi-radios label:has(input:checked){border-color:#4ec9b0!important;color:#4ec9b0!important}#wpbody-content .wpi .wpi-links{display:grid!important;grid-template-columns:1fr 1fr!important;gap:12px!important;margin-top:20px!important}#wpbody-content .wpi .wpi-links a{display:flex!important;align-items:center!important;gap:10px!important;padding:16px 20px!important;background:#f8fafc!important;border:2px solid #eef2f6!important;border-radius:12px!important;color:#1e293b!important;text-decoration:none!important;font-weight:600!important;font-size:14px!important}#wpbody-content .wpi .wpi-links a:hover{border-color:#4ec9b0!important;background:#f0fdf4!important}#wpbody-content .wpi h1,#wpbody-content .wpi h2,#wpbody-content .wpi h3,#wpbody-content .wpi h4{padding:0!important;margin-top:0!important}#wpbody-content .wpi p{font-size:14px!important}#wpbody-content .wpi a{color:#4ec9b0!important;text-decoration:none!important}#wpbody-content .wpi .wpi-card a.wpi-btn{color:#fff!important}#wpbody-content .wpi .wpi-card a.wpi-btn-red{color:#dc2626!important}@media(max-width:782px){#wpbody-content .wpi .wpi-hero{padding:24px 20px!important}#wpbody-content .wpi .wpi-hero h1{font-size:22px!important}#wpbody-content .wpi .wpi-card{padding:22px 18px!important}#wpbody-content .wpi .wpi-pricing{grid-template-columns:1fr!important}#wpbody-content .wpi .wpi-grid-2{grid-template-columns:1fr!important}#wpbody-content .wpi .wpi-examples{grid-template-columns:1fr!important}#wpbody-content .wpi .wpi-info-row{grid-template-columns:1fr!important}}' );
}
add_action( 'admin_enqueue_scripts', 'wpilot_lite_enqueue_admin_styles' );

// ── Navigation ──
function wpilot_lite_page_nav($current) {
    $pro=wpilot_lite_is_licensed();
    $pages=['wpilot-lite'=>['l'=>__('Connect','wpilot-lite'),'i'=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>'],'wpilot-lite-plan'=>['l'=>__('Plan','wpilot-lite'),'i'=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>'],'wpilot-lite-settings'=>['l'=>__('Settings','wpilot-lite'),'i'=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'],'wpilot-lite-help'=>['l'=>__('Help','wpilot-lite'),'i'=>'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>']];
    ?><div class="wpi"><div class="wpi-hero"><h1>WPilot Lite <?php echo $pro?'<span class="wpi-badge">PRO</span>':'<span class="wpi-badge-lite">LITE</span>'; ?> <span class="wpi-badge">v<?php echo esc_html(WPILOT_LITE_VERSION); ?></span></h1><p class="wpi-tagline"><?php esc_html_e('Manage your WordPress site with AI. Just talk — Claude does the rest.', 'wpilot-lite'); ?></p></div><div class="wpi-nav"><?php foreach($pages as $sl=>$pg): ?><a href="<?php echo esc_url(admin_url('admin.php?page='.$sl)); ?>" class="<?php echo $sl===$current?'wpi-nav-active':''; ?>"><?php echo $pg['i']; ?> <?php echo esc_html($pg['l']); ?></a><?php endforeach; ?></div><?php
}

// ── PAGE: Connect ──
function wpilot_lite_page_connect() {
    if(!current_user_can('manage_options'))return; wpilot_lite_page_nav('wpilot-lite');
    $tokens=wpilot_lite_get_tokens();
    $nt=get_transient('wpilot_lite_new_token'); $nl=get_transient('wpilot_lite_new_token_label');
    if($nt) { delete_transient('wpilot_lite_new_token'); delete_transient('wpilot_lite_new_token_label'); }
    $mcp=get_site_url().'/wp-json/wpilot-lite/v1/mcp'; $sv=sanitize_text_field(wp_unslash($_GET['saved']??'')); $er=sanitize_text_field(wp_unslash($_GET['error']??''));
    if($sv==='token')echo '<div class="wpi-alert wpi-alert-ok"><strong>' . esc_html__('Connection created!', 'wpilot-lite') . '</strong></div>';
    elseif($sv==='revoked')echo '<div class="wpi-alert wpi-alert-warn"><strong>' . esc_html__('Revoked.', 'wpilot-lite') . '</strong></div>';
    if($er==='limit')echo '<div class="wpi-alert wpi-alert-warn">' . wp_kses_post( sprintf( __('Free: 3 max. <a href="%s" target="_blank">Upgrade</a>', 'wpilot-lite'), 'https://weblease.se/wpilot-checkout' ) ) . '</div>';
    elseif($er==='name')echo '<div class="wpi-alert wpi-alert-warn">' . esc_html__('Enter a name.', 'wpilot-lite') . '</div>';
    $td=$nt?esc_attr($nt):''; $tm=$nt?substr($nt,0,8).'****'.substr($nt,-6):''; $ht=!empty($nt);
    ?><div class="wpi-card"><h2><?php esc_html_e('Get started', 'wpilot-lite'); ?></h2><p class="wpi-sub"><?php esc_html_e('Connect Claude in 2 minutes.', 'wpilot-lite'); ?></p>
    <div class="wpi-tabs"><button class="wpi-tab active" onclick="wpiTab('desktop')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg> <?php esc_html_e('Desktop', 'wpilot-lite'); ?></button><button class="wpi-tab" onclick="wpiTab('terminal')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg> <?php esc_html_e('Terminal', 'wpilot-lite'); ?></button></div>
    <div class="wpi-tab-panel active" id="wpi-panel-desktop"><?php if($ht): ?>
    <div class="wpi-step-row"><span class="wpi-step">1</span><div class="wpi-step-body"><h3><?php esc_html_e('Copy connection code', 'wpilot-lite'); ?></h3><code class="wpi-code" onclick="wpiCopy(this)" data-copy='{"mcpServers":{"wpilot":{"command":"npx","args":["-y","mcp-remote","<?php echo esc_url($mcp); ?>","--header","Authorization:${AUTH_HEADER}"],"env":{"AUTH_HEADER":"Bearer <?php echo $td; ?>"}}}}'>{
  "mcpServers": {
    "wpilot": {
      "command": "npx",
      "args": ["-y","mcp-remote","<?php echo esc_url($mcp); ?>","--header","Authorization:${AUTH_HEADER}"],
      "env": {"AUTH_HEADER": "Bearer <?php echo esc_html($tm); ?>"}
    }
  }
}</code></div></div>
    <div class="wpi-step-row"><span class="wpi-step">2</span><div class="wpi-step-body"><h3><?php esc_html_e('Open Claude Desktop', 'wpilot-lite'); ?></h3><p><a href="https://claude.ai/download" target="_blank">claude.ai/download</a></p></div></div>
    <div class="wpi-step-row" style="margin-bottom:0!important"><span class="wpi-step">3</span><div class="wpi-step-body"><h3><?php esc_html_e('Paste and send', 'wpilot-lite'); ?></h3><div class="wpi-step-highlight"><?php esc_html_e('Done! Start chatting.', 'wpilot-lite'); ?></div></div></div>
    <?php else: ?><div class="wpi-step-row"><span class="wpi-step">1</span><div class="wpi-step-body"><h3><?php esc_html_e('Download Claude', 'wpilot-lite'); ?></h3><p><a href="https://claude.ai/download" target="_blank">claude.ai/download</a></p></div></div>
    <div class="wpi-step-row"><span class="wpi-step">2</span><div class="wpi-step-body"><h3><?php esc_html_e('Create a connection below', 'wpilot-lite'); ?></h3></div></div><?php endif; ?></div>
    <div class="wpi-tab-panel" id="wpi-panel-terminal"><div class="wpi-step-row"><span class="wpi-step">1</span><div class="wpi-step-body"><h3><?php esc_html_e('Install', 'wpilot-lite'); ?></h3><code class="wpi-code" onclick="wpiCopy(this)" data-copy="npm install -g @anthropic-ai/claude-code">npm install -g @anthropic-ai/claude-code</code></div></div><?php if($ht): ?>
    <div class="wpi-step-row"><span class="wpi-step">2</span><div class="wpi-step-body"><h3><?php esc_html_e('Add MCP', 'wpilot-lite'); ?></h3><code class="wpi-code" onclick="wpiCopy(this)" data-copy="claude mcp add-json wpilot '{&quot;type&quot;:&quot;http&quot;,&quot;url&quot;:&quot;<?php echo esc_url($mcp); ?>&quot;,&quot;headers&quot;:{&quot;Authorization&quot;:&quot;Bearer <?php echo $td; ?>&quot;}}' --scope user">claude mcp add-json wpilot '...' --scope user</code></div></div>
    <div class="wpi-step-row" style="margin-bottom:0!important"><span class="wpi-step">3</span><div class="wpi-step-body"><h3><?php esc_html_e('Start', 'wpilot-lite'); ?></h3><code class="wpi-code" onclick="wpiCopy(this)" data-copy="claude">claude</code></div></div>
    <?php else: ?><div class="wpi-step-row"><span class="wpi-step">2</span><div class="wpi-step-body"><h3><?php esc_html_e('Create connection below first', 'wpilot-lite'); ?></h3></div></div><?php endif; ?></div></div>
    <div class="wpi-card"><h2><?php esc_html_e('Connections', 'wpilot-lite'); ?></h2><p class="wpi-sub"><?php esc_html_e('Each person/device needs one. Free: 3 max.', 'wpilot-lite'); ?></p>
    <?php if(!empty($tokens)): ?><table class="wpi-table"><thead><tr><th><?php esc_html_e('Name', 'wpilot-lite'); ?></th><th><?php esc_html_e('Mode', 'wpilot-lite'); ?></th><th><?php esc_html_e('Created', 'wpilot-lite'); ?></th><th><?php esc_html_e('Last used', 'wpilot-lite'); ?></th><th></th></tr></thead><tbody><?php foreach($tokens as $t):$s=$t['style']??'simple'; ?><tr><td style="font-weight:600!important"><?php echo esc_html($t['label']??''); ?></td><td><span class="wpi-style-badge wpi-style-<?php echo esc_attr($s); ?>"><?php echo $s==='technical'? esc_html__('Technical','wpilot-lite') : esc_html__('Simple','wpilot-lite'); ?></span></td><td><?php echo esc_html($t['created']??''); ?></td><td><?php echo esc_html($t['last_used']??__('Never','wpilot-lite')); ?></td><td style="text-align:right!important"><form method="post" style="display:inline!important" onsubmit="return confirm('<?php echo esc_js(__('Revoke?','wpilot-lite')); ?>')"><?php wp_nonce_field('wpilot_lite_admin'); ?><input type="hidden" name="wpilot_action" value="revoke_token"><input type="hidden" name="token_hash" value="<?php echo esc_attr($t['hash']); ?>"><button class="wpi-btn wpi-btn-red wpi-btn-sm"><?php esc_html_e('Revoke', 'wpilot-lite'); ?></button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <div style="margin-top:24px!important;padding-top:24px!important;border-top:1px solid #f1f5f9!important"><h3 style="font-size:15px!important;font-weight:700!important;margin:0 0 14px!important"><?php esc_html_e('Create new connection', 'wpilot-lite'); ?></h3>
    <form method="post" style="display:flex!important;gap:12px!important;align-items:end!important;flex-wrap:wrap!important"><?php wp_nonce_field('wpilot_lite_admin'); ?><input type="hidden" name="wpilot_action" value="create_token"><div class="wpi-field" style="margin:0!important"><label><?php esc_html_e('Name', 'wpilot-lite'); ?></label><input type="text" name="token_label" placeholder="<?php echo esc_attr__('e.g. Lisa', 'wpilot-lite'); ?>" style="max-width:220px!important"></div><div class="wpi-field" style="margin:0!important"><label><?php esc_html_e('Mode', 'wpilot-lite'); ?></label><select name="token_style" style="max-width:260px!important"><option value="simple"><?php esc_html_e('Simple', 'wpilot-lite'); ?></option><option value="technical"><?php esc_html_e('Technical', 'wpilot-lite'); ?></option></select></div><button class="wpi-btn wpi-btn-green"><?php esc_html_e('Create', 'wpilot-lite'); ?></button></form></div>
    <div class="wpi-info-row"><div class="wpi-info-item"><h4><?php esc_html_e('Devices', 'wpilot-lite'); ?></h4><p><?php esc_html_e('One per computer.', 'wpilot-lite'); ?></p></div><div class="wpi-info-item"><h4><?php esc_html_e('Team', 'wpilot-lite'); ?></h4><p><?php esc_html_e('One per person.', 'wpilot-lite'); ?></p></div><div class="wpi-info-item"><h4><?php esc_html_e('Clients', 'wpilot-lite'); ?></h4><p><?php esc_html_e('Self-service.', 'wpilot-lite'); ?></p></div></div></div>
    <?php wpilot_lite_page_js(); echo '</div>';
}

// ── PAGE: Plan ──
function wpilot_lite_page_plan() {
    if(!current_user_can('manage_options'))return; wpilot_lite_page_nav('wpilot-lite-plan');
    $sv=sanitize_text_field(wp_unslash($_GET['saved']??'')); $st=sanitize_text_field(wp_unslash($_GET['status']??'')); $pro=wpilot_lite_is_licensed(); $key=get_option('wpilot_lite_license_key',''); $u=wpilot_lite_check_usage();
    if($sv==='license'&&$st==='valid')echo '<div class="wpi-alert wpi-alert-ok"><strong>' . esc_html__('Activated!', 'wpilot-lite') . '</strong></div>';
    elseif($sv==='license')echo '<div class="wpi-alert wpi-alert-warn"><strong>' . esc_html__('Invalid key.', 'wpilot-lite') . '</strong></div>';
    elseif($sv==='removed')echo '<div class="wpi-alert wpi-alert-info"><strong>' . esc_html__('Removed.', 'wpilot-lite') . '</strong></div>';
    $error=sanitize_text_field(wp_unslash($_GET['error']??''));
    if($error==='empty_key')echo '<div class="wpi-alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:16px;">' . esc_html__('Please enter your license key.', 'wpilot-lite') . '</div>';
    if($error==='server')echo '<div class="wpi-alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:16px;">' . esc_html__('Could not reach the license server. Please try again.', 'wpilot-lite') . '</div>';
    if($error==='invalid_key')echo '<div class="wpi-alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:16px;">' . wp_kses_post( sprintf( __('Invalid license key. Check your email or visit %syour account%s.', 'wpilot-lite'), '<a href="https://weblease.se/wpilot-account" target="_blank">', '</a>' ) ) . '</div>';
    if($error==='download')echo '<div class="wpi-alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:16px;">' . esc_html__('Could not download WPilot Pro. Please try again.', 'wpilot-lite') . '</div>';
    if($error==='install')echo '<div class="wpi-alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:12px 16px;border-radius:10px;margin-bottom:16px;">' . wp_kses_post( sprintf( __('Could not install WPilot Pro. Try uploading manually from %syour account%s.', 'wpilot-lite'), '<a href="https://weblease.se/wpilot-account" target="_blank">', '</a>' ) ) . '</div>';
    if(!$pro): ?>
    <!-- Upgrade to Pro — Built by Weblease -->
    <div class="wpi-card" style="border:2px solid #22c55e !important;background:linear-gradient(135deg, #f0fdf4, #ecfdf5) !important;">
        <h2 style="color:#166534 !important;"><?php esc_html_e('Upgrade to Pro', 'wpilot-lite'); ?></h2>
        <p style="font-size:14px !important;color:#15803d !important;margin-bottom:16px !important;">
            <?php esc_html_e('Unlimited prompts, Chat Agent, smart sandbox, auto-updates, and priority support.', 'wpilot-lite'); ?>
        </p>
        <div style="background:#fff !important;border:1px solid #bbf7d0 !important;border-radius:12px !important;padding:20px !important;margin-bottom:16px !important;">
            <p style="font-size:13px !important;color:#475569 !important;margin:0 0 12px !important;font-weight:600 !important;">
                <?php echo wp_kses_post( sprintf( __('1. Get your license key at %sweblease.se/wpilot%s', 'wpilot-lite'), '<a href="https://weblease.se/wpilot" target="_blank" style="color:#4f46e5 !important;">', '</a>' ) ); ?><br>
                <?php esc_html_e('2. Paste it below', 'wpilot-lite'); ?><br>
                <?php esc_html_e('3. Pro installs automatically', 'wpilot-lite'); ?>
            </p>
            <form method="post" style="display:flex !important;gap:8px !important;align-items:end !important;">
                <?php wp_nonce_field('wpilot_lite_admin'); ?>
                <input type="hidden" name="wpilot_action" value="upgrade_to_pro">
                <div style="flex:1 !important;">
                    <input type="text" name="pro_license_key" placeholder="WPILOT-XXXX-XXXX-XXXX" style="width:100% !important;font-size:15px !important;padding:12px 16px !important;border:2px solid #bbf7d0 !important;border-radius:10px !important;font-family:monospace !important;">
                </div>
                <button type="submit" class="wpi-btn" style="background:#22c55e !important;color:#fff !important;padding:12px 28px !important;font-size:15px !important;font-weight:700 !important;border-radius:10px !important;white-space:nowrap !important;"><?php esc_html_e('Activate Pro', 'wpilot-lite'); ?></button>
            </form>
        </div>
        <p style="font-size:12px !important;color:#64748b !important;margin:0 !important;">
            <?php echo wp_kses_post( sprintf( __('Don\'t have a key? %sGet Pro — $9/month%s', 'wpilot-lite'), '<a href="https://weblease.se/wpilot-checkout?site=' . urlencode(get_site_url()) . '&amp;email=' . urlencode(get_option('admin_email')) . '&amp;plan=pro" target="_blank" style="color:#4f46e5 !important;font-weight:600 !important;">', '</a>' ) ); ?>
        </p>
    </div>
    <?php endif;
    ?><div class="wpi-card"><h2><?php esc_html_e('Your Plan', 'wpilot-lite'); ?></h2><div style="display:grid!important;grid-template-columns:1fr 1fr!important;gap:12px!important"><div style="padding:16px!important;background:<?php echo $pro?'#f0fdf4':'#fefce8'; ?>!important;border:1px solid <?php echo $pro?'#bbf7d0':'#fde68a'; ?>!important;border-radius:12px!important;text-align:center!important"><span style="font-size:12px!important;color:#64748b!important;font-weight:600!important"><?php esc_html_e('Plan', 'wpilot-lite'); ?></span><div style="font-size:20px!important;font-weight:800!important;margin-top:4px!important"><?php echo $pro? esc_html__('Pro','wpilot-lite') : esc_html__('Free','wpilot-lite'); ?></div></div><div style="padding:16px!important;background:#f8fafc!important;border:1px solid #e2e8f0!important;border-radius:12px!important;text-align:center!important"><span style="font-size:12px!important;color:#64748b!important;font-weight:600!important"><?php esc_html_e('Today', 'wpilot-lite'); ?></span><div style="font-size:20px!important;font-weight:800!important;margin-top:4px!important"><?php echo $pro? esc_html__('Unlimited','wpilot-lite') : esc_html(intval($u['used']??0).'/'.intval($u['limit']??20)); ?></div></div></div></div>
    <div class="wpi-card"><h2><?php esc_html_e('License Key', 'wpilot-lite'); ?></h2><p class="wpi-sub"><?php esc_html_e('Unlock unlimited requests.', 'wpilot-lite'); ?></p><?php if($pro&&!empty($key)): ?><div class="wpi-alert wpi-alert-ok"><strong><?php esc_html_e('Active:', 'wpilot-lite'); ?></strong> <?php echo esc_html(substr($key,0,8).'****'.substr($key,-4)); ?></div><form method="post"><?php wp_nonce_field('wpilot_lite_admin'); ?><input type="hidden" name="wpilot_action" value="remove_license"><button class="wpi-btn wpi-btn-red" onclick="return confirm('<?php echo esc_js(__('Remove?','wpilot-lite')); ?>')"><?php esc_html_e('Remove', 'wpilot-lite'); ?></button></form><?php else: ?><form method="post" style="display:flex!important;gap:12px!important;align-items:end!important;flex-wrap:wrap!important"><?php wp_nonce_field('wpilot_lite_admin'); ?><input type="hidden" name="wpilot_action" value="save_license"><div class="wpi-field" style="margin:0!important;flex:1!important"><label><?php esc_html_e('Key', 'wpilot-lite'); ?></label><input type="text" name="license_key" placeholder="<?php echo esc_attr__('Enter key...', 'wpilot-lite'); ?>" value="<?php echo esc_attr($key); ?>"></div><button class="wpi-btn wpi-btn-green"><?php esc_html_e('Activate', 'wpilot-lite'); ?></button></form><p style="margin-top:12px!important;font-size:13px!important;color:#64748b!important"><a href="https://weblease.se/wpilot-checkout" target="_blank"><?php esc_html_e('Get a license', 'wpilot-lite'); ?></a></p><?php endif; ?></div>
    <div class="wpi-card"><h2><?php esc_html_e('Plans', 'wpilot-lite'); ?></h2><div class="wpi-pricing"><div class="wpi-plan"><h3><?php esc_html_e('Free', 'wpilot-lite'); ?></h3><div class="wpi-price">$0</div><div class="wpi-price-note"><?php esc_html_e('Forever', 'wpilot-lite'); ?></div><ul><li><?php esc_html_e('20 requests/day', 'wpilot-lite'); ?></li><li><?php esc_html_e('3 connections', 'wpilot-lite'); ?></li><li><?php esc_html_e('Full WP access', 'wpilot-lite'); ?></li><li><?php esc_html_e('Content, SEO, WooCommerce', 'wpilot-lite'); ?></li></ul><span class="wpi-btn wpi-btn-dark" style="opacity:.5!important;cursor:default!important"><?php esc_html_e('Current', 'wpilot-lite'); ?></span></div><div class="wpi-plan wpi-plan-pop"><h3><?php esc_html_e('Pro', 'wpilot-lite'); ?></h3><div class="wpi-price">$9<span>/<?php esc_html_e('mo', 'wpilot-lite'); ?></span></div><div class="wpi-price-note"><?php esc_html_e('Per site', 'wpilot-lite'); ?></div><ul><li><?php esc_html_e('Unlimited requests', 'wpilot-lite'); ?></li><li><?php esc_html_e('Unlimited connections', 'wpilot-lite'); ?></li><li><?php esc_html_e('Priority support', 'wpilot-lite'); ?></li><li><?php esc_html_e('Advanced sandbox', 'wpilot-lite'); ?></li></ul><a href="https://weblease.se/wpilot-checkout" target="_blank" class="wpi-btn wpi-btn-green"><?php esc_html_e('Get Pro', 'wpilot-lite'); ?></a></div><div class="wpi-plan"><h3><?php esc_html_e('Pro + Chat', 'wpilot-lite'); ?></h3><div class="wpi-price">$29<span>/<?php esc_html_e('mo', 'wpilot-lite'); ?></span></div><div class="wpi-price-note"><?php esc_html_e('Per site', 'wpilot-lite'); ?></div><ul><li><?php esc_html_e('Everything in Pro', 'wpilot-lite'); ?></li><li><?php esc_html_e('AI chat widget', 'wpilot-lite'); ?></li><li><?php esc_html_e('Knowledge base', 'wpilot-lite'); ?></li><li><?php esc_html_e('Auto-answer', 'wpilot-lite'); ?></li></ul><a href="https://weblease.se/wpilot-checkout" target="_blank" class="wpi-btn wpi-btn-green"><?php esc_html_e('Get', 'wpilot-lite'); ?></a></div></div></div><?php echo '</div>';
}

// ── PAGE: Settings ──
function wpilot_lite_page_settings() {
    if(!current_user_can('manage_options'))return; wpilot_lite_page_nav('wpilot-lite-settings');
    $pr=(array)get_option('wpilot_lite_site_profile',[]); $sv=sanitize_text_field(wp_unslash($_GET['saved']??''));
    if($sv==='profile')echo '<div class="wpi-alert wpi-alert-ok"><strong>' . esc_html__('Saved!', 'wpilot-lite') . '</strong></div>';
    ?><div class="wpi-card"><h2><?php esc_html_e('Site Profile', 'wpilot-lite'); ?></h2><p class="wpi-sub"><?php esc_html_e('Personalize Claude.', 'wpilot-lite'); ?></p><form method="post"><?php wp_nonce_field('wpilot_lite_admin'); ?><input type="hidden" name="wpilot_action" value="save_profile"><div class="wpi-grid-2"><div class="wpi-field"><label><?php esc_html_e('Your name', 'wpilot-lite'); ?></label><input type="text" name="owner_name" value="<?php echo esc_attr($pr['owner_name']??''); ?>" placeholder="<?php echo esc_attr__('e.g. Lisa', 'wpilot-lite'); ?>"><span class="wpi-hint"><?php esc_html_e('Claude uses this.', 'wpilot-lite'); ?></span></div><div class="wpi-field"><label><?php esc_html_e('Business', 'wpilot-lite'); ?></label><input type="text" name="business_type" value="<?php echo esc_attr($pr['business_type']??''); ?>" placeholder="<?php echo esc_attr__('e.g. Bakery', 'wpilot-lite'); ?>"><span class="wpi-hint"><?php esc_html_e('Content suggestions.', 'wpilot-lite'); ?></span></div><div class="wpi-field"><label><?php esc_html_e('Tone', 'wpilot-lite'); ?></label><select name="tone"><?php $ts=['friendly and professional'=>__('Friendly','wpilot-lite'),'casual and relaxed'=>__('Casual','wpilot-lite'),'formal and business-like'=>__('Formal','wpilot-lite'),'warm and personal'=>__('Warm','wpilot-lite'),'short and direct'=>__('Direct','wpilot-lite')]; $c=$pr['tone']??'friendly and professional'; foreach($ts as $v=>$l)echo '<option value="'.esc_attr($v).'"'.selected($c,$v,false).'>'.esc_html($l).'</option>'; ?></select></div><div class="wpi-field"><label><?php esc_html_e('Language', 'wpilot-lite'); ?></label><select name="language"><?php $ls=[''=>__('Auto','wpilot-lite'),'Swedish'=>__('Swedish','wpilot-lite'),'English'=>__('English','wpilot-lite'),'Spanish'=>__('Spanish','wpilot-lite'),'German'=>__('German','wpilot-lite'),'French'=>__('French','wpilot-lite'),'Norwegian'=>__('Norwegian','wpilot-lite'),'Danish'=>__('Danish','wpilot-lite'),'Finnish'=>__('Finnish','wpilot-lite'),'Dutch'=>__('Dutch','wpilot-lite'),'Italian'=>__('Italian','wpilot-lite'),'Portuguese'=>__('Portuguese','wpilot-lite'),'Arabic'=>__('Arabic','wpilot-lite'),'Greek'=>__('Greek','wpilot-lite'),'Turkish'=>__('Turkish','wpilot-lite'),'Polish'=>__('Polish','wpilot-lite')]; $cl=$pr['language']??''; foreach($ls as $v=>$l)echo '<option value="'.esc_attr($v).'"'.selected($cl,$v,false).'>'.esc_html($l).'</option>'; ?></select></div></div><button class="wpi-btn wpi-btn-dark" style="margin-top:8px!important"><?php esc_html_e('Save', 'wpilot-lite'); ?></button></form></div>
    <div class="wpi-card"><h2><?php esc_html_e('Plugin Info', 'wpilot-lite'); ?></h2><div style="display:grid!important;grid-template-columns:140px 1fr!important;gap:8px 16px!important;font-size:13px!important"><span style="color:#64748b!important;font-weight:600!important"><?php esc_html_e('Version', 'wpilot-lite'); ?></span><span><?php echo esc_html(WPILOT_LITE_VERSION); ?></span><span style="color:#64748b!important;font-weight:600!important"><?php esc_html_e('Plugin', 'wpilot-lite'); ?></span><span>WPilot Lite</span><span style="color:#64748b!important;font-weight:600!important"><?php esc_html_e('Site', 'wpilot-lite'); ?></span><span><?php echo esc_html(get_site_url()); ?></span><span style="color:#64748b!important;font-weight:600!important">PHP</span><span><?php echo esc_html(phpversion()); ?></span><span style="color:#64748b!important;font-weight:600!important">WP</span><span><?php echo esc_html(get_bloginfo('version')); ?></span></div></div><?php echo '</div>';
}

// ── PAGE: Help ──
function wpilot_lite_page_help() {
    if(!current_user_can('manage_options'))return; wpilot_lite_page_nav('wpilot-lite-help');
    $sv=sanitize_text_field(wp_unslash($_GET['saved']??'')); $er=sanitize_text_field(wp_unslash($_GET['error']??''));
    if($sv==='feedback')echo '<div class="wpi-alert wpi-alert-ok"><strong>' . esc_html__('Sent!', 'wpilot-lite') . '</strong></div>';
    if($er==='empty')echo '<div class="wpi-alert wpi-alert-warn">' . esc_html__('Please enter a message.', 'wpilot-lite') . '</div>';
    ?><div class="wpi-card"><h2><?php esc_html_e('What can Claude do?', 'wpilot-lite'); ?></h2><p class="wpi-sub"><?php esc_html_e('Talk naturally. Examples:', 'wpilot-lite'); ?></p><div class="wpi-examples"><div class="wpi-example"><h3><?php esc_html_e('Design', 'wpilot-lite'); ?></h3><p>"<?php esc_html_e('Create a contact page', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('Make header dark blue', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('Write About Us', 'wpilot-lite'); ?>"</p></div><div class="wpi-example"><h3><?php esc_html_e('SEO', 'wpilot-lite'); ?></h3><p>"<?php esc_html_e('Optimize for Google', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('Write meta descriptions', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('Best keywords?', 'wpilot-lite'); ?>"</p></div><div class="wpi-example"><h3>WooCommerce</h3><p>"<?php esc_html_e('Add product for $29', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('20% coupon', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('Orders this month?', 'wpilot-lite'); ?>"</p></div><div class="wpi-example"><h3><?php esc_html_e('Management', 'wpilot-lite'); ?></h3><p>"<?php esc_html_e('Show all pages', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('Clean revisions', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('What plugins?', 'wpilot-lite'); ?>"</p></div><div class="wpi-example"><h3><?php esc_html_e('Security', 'wpilot-lite'); ?></h3><p>"<?php esc_html_e('Outdated plugins?', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('Security check', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('Admin users?', 'wpilot-lite'); ?>"</p></div><div class="wpi-example"><h3><?php esc_html_e('Performance', 'wpilot-lite'); ?></h3><p>"<?php esc_html_e('Site speed?', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('Optimize images', 'wpilot-lite'); ?>"</p><p>"<?php esc_html_e('What\'s slow?', 'wpilot-lite'); ?>"</p></div></div></div>
    <div class="wpi-card"><h2><?php esc_html_e('Want Chat Agent?', 'wpilot-lite'); ?></h2><p class="wpi-sub"><?php esc_html_e('AI chat widget for visitors. Auto-answers from your content.', 'wpilot-lite'); ?></p><a href="https://weblease.se/wpilot" target="_blank" class="wpi-btn wpi-btn-green"><?php esc_html_e('Learn about Pro', 'wpilot-lite'); ?></a></div>
    <div class="wpi-card"><h2><?php esc_html_e('Feedback', 'wpilot-lite'); ?></h2><form method="post"><?php wp_nonce_field('wpilot_lite_admin'); ?><input type="hidden" name="wpilot_action" value="send_feedback"><div class="wpi-radios"><label><input type="radio" name="feedback_type" value="feedback" checked><span><?php esc_html_e('Feedback', 'wpilot-lite'); ?></span></label><label><input type="radio" name="feedback_type" value="bug"><span><?php esc_html_e('Bug', 'wpilot-lite'); ?></span></label><label><input type="radio" name="feedback_type" value="feature"><span><?php esc_html_e('Feature', 'wpilot-lite'); ?></span></label><label><input type="radio" name="feedback_type" value="question"><span><?php esc_html_e('Question', 'wpilot-lite'); ?></span></label></div><div class="wpi-field"><textarea name="feedback_message" rows="4" placeholder="<?php echo esc_attr__('Tell us...', 'wpilot-lite'); ?>"></textarea></div><button class="wpi-btn wpi-btn-dark"><?php esc_html_e('Send', 'wpilot-lite'); ?></button></form></div>
    <div class="wpi-card"><h2><?php esc_html_e('Resources', 'wpilot-lite'); ?></h2><div class="wpi-links"><a href="https://weblease.se/wpilot" target="_blank"><?php esc_html_e('Documentation', 'wpilot-lite'); ?></a><a href="https://claude.ai/download" target="_blank"><?php esc_html_e('Download Claude', 'wpilot-lite'); ?></a><a href="mailto:info@weblease.se"><?php esc_html_e('Email Support', 'wpilot-lite'); ?></a><a href="https://weblease.se/wpilot-checkout" target="_blank"><?php esc_html_e('Upgrade to Pro', 'wpilot-lite'); ?></a></div></div><?php echo '</div>';
}

// ── JavaScript — loaded via wp_add_inline_script ──
// Built by Weblease
function wpilot_lite_enqueue_admin_scripts( $hook ) {
    if ( strpos( $hook, 'wpilot' ) === false ) return;
    wp_register_script( 'wpilot-lite-admin-js', false );
    wp_enqueue_script( 'wpilot-lite-admin-js' );
    wp_add_inline_script( 'wpilot-lite-admin-js', 'function wpiTab(n){var t=document.querySelectorAll(\'#wpbody-content .wpi .wpi-tab\'),p=document.querySelectorAll(\'#wpbody-content .wpi .wpi-tab-panel\');for(var i=0;i<t.length;i++)t[i].classList.remove(\'active\');for(var i=0;i<p.length;i++)p[i].classList.remove(\'active\');var e=document.getElementById(\'wpi-panel-\'+n);if(e)e.classList.add(\'active\');for(var j=0;j<t.length;j++){if((t[j].getAttribute(\'onclick\')||\'\').indexOf(n)!==-1)t[j].classList.add(\'active\');}}function wpiCopy(el){var t=el.getAttribute(\'data-copy\')||el.textContent||\'\';if(navigator.clipboard){navigator.clipboard.writeText(t.trim()).then(function(){el.classList.add(\'copied\');setTimeout(function(){el.classList.remove(\'copied\');},2500);});}else{var a=document.createElement(\'textarea\');a.value=t.trim();a.style.position=\'fixed\';a.style.opacity=\'0\';document.body.appendChild(a);a.select();document.execCommand(\'copy\');document.body.removeChild(a);el.classList.add(\'copied\');setTimeout(function(){el.classList.remove(\'copied\');},2500);}}' );
}
add_action( 'admin_enqueue_scripts', 'wpilot_lite_enqueue_admin_scripts' );
function wpilot_lite_page_js() {
    // JS now loaded via wp_add_inline_script
}
