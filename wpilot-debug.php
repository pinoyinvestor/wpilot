<?php
/**
 * WPilot Debug Page
 * SECURITY: Direct URL access blocked. Must be loaded through WordPress.
 */
// SECURITY: Block direct access
if (!defined('ABSPATH')) {
    http_response_code(403);
    die('Direct access forbidden.');
}
if (!current_user_can('manage_options')) {
    wp_die('Admin access required.');
}

$r = [];
$r['plugin_active'] = is_plugin_active('wpilot/wpilot.php') ? 'YES' : 'NO';
$r['CA_VERSION'] = defined('CA_VERSION') ? CA_VERSION : 'NOT DEFINED';
$r['CA_MODEL'] = defined('CA_MODEL') ? CA_MODEL : 'NOT DEFINED';
$r['php_version'] = PHP_VERSION;
$r['wp_version'] = get_bloginfo('version');
$r['theme'] = wp_get_theme()->get('Name');
$r['user'] = wp_get_current_user()->user_login;
$r['roles'] = implode(', ', wp_get_current_user()->roles);
$r['manage_options'] = current_user_can('manage_options') ? 'YES' : 'NO';

$fns = ['wpilot_render_bubble','wpilot_is_connected','wpilot_user_has_access','wpilot_can_use','wpilot_is_locked','wpilot_allowed_roles','wpilot_enqueue_bubble'];
foreach ($fns as $fn) $r['fn_'.$fn] = function_exists($fn) ? 'YES' : 'MISSING';

if (function_exists('wpilot_user_has_access')) $r['user_has_access'] = wpilot_user_has_access() ? 'YES' : 'NO';
if (function_exists('wpilot_can_use')) $r['can_use'] = wpilot_can_use() ? 'YES' : 'NO';
if (function_exists('wpilot_is_locked')) $r['is_locked'] = wpilot_is_locked() ? 'YES' : 'NO';
if (function_exists('wpilot_is_connected')) $r['connected'] = wpilot_is_connected() ? 'YES' : 'NO';
if (function_exists('wpilot_allowed_roles')) $r['allowed_roles'] = implode(', ', wpilot_allowed_roles());

$r['ca_api_key'] = !empty(get_option('ca_api_key','')) ? 'SET ('.strlen(get_option('ca_api_key')).' chars)' : 'EMPTY';
$r['wpilot_theme'] = get_option('wpilot_theme', 'not set');
$r['prompts_used'] = get_option('wpilot_prompts_used', 0);

if (function_exists('wpilot_render_bubble')) {
    ob_start(); wpilot_render_bubble(); $html = ob_get_clean();
    $r['bubble_html_bytes'] = strlen($html);
    $r['bubble_has_caRoot'] = strpos($html,'caRoot')!==false ? 'YES' : 'NO';
    $r['bubble_has_connectBtn'] = strpos($html,'capConnectBtn')!==false ? 'YES' : 'NO';
    $r['bubble_has_wpiConnect'] = strpos($html,'wpiConnect')!==false ? 'YES' : 'NO';
}

$r['active_plugins'] = implode(', ', array_map(function($p){return explode('/',$p)[0];}, get_option('active_plugins',[])));
$nonce = wp_create_nonce('ca_nonce');
// Built by Weblease
?><!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>WPilot Debug</title>
<style>
*{box-sizing:border-box}body{font-family:-apple-system,sans-serif;background:#0a0e17;color:#e0e6f0;padding:20px;max-width:800px;margin:0 auto}
h1{color:#5B7FFF}h2{color:#5E6E91;font-size:13px;text-transform:uppercase;letter-spacing:2px;margin-top:30px}
table{width:100%;border-collapse:collapse;margin:10px 0}td{padding:8px 12px;border-bottom:1px solid rgba(255,255,255,.06);font-size:14px}
td:first-child{color:#5E6E91;width:40%;font-family:monospace}.ok{color:#10B981;font-weight:700}.fail{color:#EF4444;font-weight:700}
.btn{background:linear-gradient(135deg,#5B7FFF,#7C5CFC);color:#fff;border:none;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:700;cursor:pointer;margin:10px 5px}
.btn:hover{opacity:.9}input[type=password]{width:100%;padding:10px;background:#111827;border:1px solid rgba(255,255,255,.1);border-radius:8px;color:#e0e6f0;font-family:monospace;font-size:14px;margin:10px 0}
#out{margin:10px 0;padding:12px;background:rgba(255,255,255,.03);border-radius:8px;font-family:monospace;font-size:13px;white-space:pre-wrap;min-height:40px}
</style></head><body>
<h1>WPilot Debug</h1>
<h2>Status</h2>
<table>
<?php foreach($r as $k=>$v): $cls = ''; if($v==='YES')$cls='ok'; if($v==='NO'||$v==='MISSING'||$v==='EMPTY'||$v==='NOT DEFINED')$cls='fail'; ?>
<tr><td><?php echo esc_html($k); ?></td><td class="<?php echo $cls; ?>"><?php echo esc_html($v); ?></td></tr>
<?php endforeach; ?>
</table>

<h2>Test API Key Connection</h2>
<input type="password" id="k" placeholder="sk-ant-api03-...">
<button class="btn" onclick="go()">Test Connect</button>
<div id="out">Paste your key and click Test Connect</div>

<script>
function go(){
    var k=document.getElementById('k').value.trim(),o=document.getElementById('out');
    if(!k){o.textContent='Enter a key first';return;}
    o.textContent='Connecting...';
    var x=new XMLHttpRequest();
    x.open('POST','<?php echo esc_url(admin_url("admin-ajax.php")); ?>',true);
    x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    x.onload=function(){o.textContent='HTTP '+x.status+'\n'+x.responseText;};
    x.onerror=function(){o.textContent='NETWORK ERROR - cannot reach admin-ajax.php';};
    x.send('action=ca_test_connection&nonce=<?php echo esc_js($nonce); ?>&key='+encodeURIComponent(k));
}
</script>
</body></html>
<?php exit;
