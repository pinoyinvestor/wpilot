<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ═══════════════════════════════════════════════════════════════
//  WPILOT PWA — Progressive Web App for any WordPress site
//
//  Adds installable PWA support via WPilot tools:
//  - manifest.json generated from design profile
//  - Service worker with offline support
//  - Push notification infrastructure
//  - Install prompt banner on mobile
//
//  All colors from design profile / CSS variables — never hardcoded.
//  Works with any theme (Storefront, Elementor, Divi, etc.)
// ═══════════════════════════════════════════════════════════════

define( 'WPI_PWA_VERSION', '1.0.0' );
define( 'WPI_PWA_OPTION', 'wpilot_pwa_settings' );
define( 'WPI_PWA_SUBS_OPTION', 'wpilot_pwa_push_subs' );
define( 'WPI_PWA_ACTIVE_OPTION', 'wpilot_pwa_active' );

// ── Default PWA settings ─────────────────────────────────────
function wpilot_pwa_defaults() {
    return [
        'version'         => WPI_PWA_VERSION,
        'display'         => 'standalone',
        'orientation'     => 'portrait',
        'start_url'       => '/',
        'scope'           => '/',
        'offline_message' => "You're currently offline. Please check your connection and try again.",
        'cache_version'   => 'v1',
        'vapid_public'    => '',
        'vapid_private'   => '',
        'custom_name'     => '',
        'custom_short'    => '',
        'custom_icon'     => '',
        'custom_bg'       => '',
        'custom_theme'    => '',
    ];
}

// ── Get merged settings ──────────────────────────────────────
function wpilot_pwa_get_settings() {
    $saved = get_option( WPI_PWA_OPTION, [] );
    return array_merge( wpilot_pwa_defaults(), $saved );
}

// ── Get design colors (from profile or fallbacks) ────────────
function wpilot_pwa_get_colors() {
    $profile = function_exists( 'wpilot_get_design_profile' ) ? wpilot_get_design_profile() : [];
    $settings = wpilot_pwa_get_settings();

    return [
        'primary'   => $settings['custom_theme'] ?: ( $profile['primary_color'] ?? '#1a1a2e' ),
        'secondary' => $profile['secondary_color'] ?? '#e94560',
        'accent'    => $profile['accent_color'] ?? '#0f3460',
        'bg'        => $settings['custom_bg'] ?: ( $profile['bg_color'] ?? '#ffffff' ),
        'text'      => $profile['text_color'] ?? '#333333',
    ];
}

// ── Get site favicon URL ─────────────────────────────────────
function wpilot_pwa_get_icon_url() {
    $settings = wpilot_pwa_get_settings();
    if ( ! empty( $settings['custom_icon'] ) ) return $settings['custom_icon'];

    $icon_id = get_option( 'site_icon' );
    if ( $icon_id ) {
        $url = wp_get_attachment_image_url( $icon_id, 'full' );
        if ( $url ) return $url;
    }

    return plugins_url( 'assets/default-pwa-icon.png', dirname( __FILE__ ) );
}

// ═══════════════════════════════════════════════════════════════
//  MANIFEST.JSON GENERATOR
// ═══════════════════════════════════════════════════════════════

function wpilot_pwa_manifest_data() {
    $settings = wpilot_pwa_get_settings();
    $colors   = wpilot_pwa_get_colors();
    $icon_url = wpilot_pwa_get_icon_url();

    $name       = $settings['custom_name'] ?: get_bloginfo( 'name' );
    $short_name = $settings['custom_short'] ?: mb_substr( $name, 0, 12 );
    $desc       = get_bloginfo( 'description' ) ?: $name;

    $icons = [];
    $sizes = [ 72, 96, 128, 144, 152, 192, 384, 512 ];
    foreach ( $sizes as $s ) {
        $icons[] = [
            'src'     => add_query_arg( [ 'wpilot_pwa_icon' => 1, 'size' => $s ], home_url( '/' ) ),
            'sizes'   => "{$s}x{$s}",
            'type'    => 'image/png',
            'purpose' => 'any maskable',
        ];
    }

    return [
        'name'             => $name,
        'short_name'       => $short_name,
        'description'      => $desc,
        'start_url'        => $settings['start_url'],
        'scope'            => $settings['scope'],
        'display'          => $settings['display'],
        'orientation'      => $settings['orientation'],
        'background_color' => $colors['bg'],
        'theme_color'      => $colors['primary'],
        'icons'            => $icons,
        'categories'       => [ 'business', 'shopping' ],
        'lang'             => get_locale(),
        'dir'              => is_rtl() ? 'rtl' : 'ltr',
    ];
}

// ═══════════════════════════════════════════════════════════════
//  SERVICE WORKER GENERATOR
// ═══════════════════════════════════════════════════════════════

function wpilot_pwa_service_worker_js() {
    $settings = wpilot_pwa_get_settings();
    $version  = $settings['cache_version'];
    $colors   = wpilot_pwa_get_colors();
    $name     = esc_js( $settings['custom_name'] ?: get_bloginfo( 'name' ) );
    $msg      = esc_js( $settings['offline_message'] );

    // Build offline page HTML using CSS variables (no hardcoded colors)
    $offline_html = wpilot_pwa_offline_page_html( $name, $msg, $colors );
    $offline_escaped = str_replace( [ '\\', "'", "\n", "\r" ], [ '\\\\', "\\'", '\\n', '' ], $offline_html );

    return <<<JS
'use strict';

const CACHE_NAME = 'wpilot-pwa-{$version}';
const STATIC_CACHE = 'wpilot-static-{$version}';
const OFFLINE_KEY = 'wpilot-offline-page';

const PRECACHE_URLS = [
  '/',
];

// Install — cache critical assets and offline page
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      // Store offline page
      const offlineResponse = new Response('{$offline_escaped}', {
        headers: { 'Content-Type': 'text/html; charset=UTF-8' }
      });
      cache.put(OFFLINE_KEY, offlineResponse);
      return cache.addAll(PRECACHE_URLS).catch(() => {});
    }).then(() => self.skipWaiting())
  );
});

// Activate — purge old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME && k !== STATIC_CACHE && k.startsWith('wpilot-')).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// Fetch strategy
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Skip non-GET, admin, login, wp-json, AJAX
  if (event.request.method !== 'GET') return;
  if (url.pathname.startsWith('/wp-admin')) return;
  if (url.pathname.startsWith('/wp-login')) return;
  if (url.pathname.includes('wp-json')) return;
  if (url.searchParams.has('action')) return;
  if (url.pathname.includes('admin-ajax')) return;

  // Static assets — cache-first
  if (/\.(css|js|woff2?|ttf|eot|svg|png|jpe?g|gif|webp|ico)$/i.test(url.pathname)) {
    event.respondWith(
      caches.open(STATIC_CACHE).then(cache =>
        cache.match(event.request).then(cached => {
          if (cached) return cached;
          return fetch(event.request).then(response => {
            if (response.ok) cache.put(event.request, response.clone());
            return response;
          }).catch(() => cached);
        })
      )
    );
    return;
  }

  // HTML pages — network-first with offline fallback
  if (event.request.headers.get('accept')?.includes('text/html')) {
    event.respondWith(
      fetch(event.request).then(response => {
        const clone = response.clone();
        caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        return response;
      }).catch(() =>
        caches.match(event.request).then(cached =>
          cached || caches.open(CACHE_NAME).then(cache => cache.match(OFFLINE_KEY))
        )
      )
    );
    return;
  }

  // Everything else — network with cache fallback
  event.respondWith(
    fetch(event.request).catch(() => caches.match(event.request))
  );
});

// Push notification handler
self.addEventListener('push', event => {
  let data = { title: '{$name}', body: 'New update available', icon: '/favicon.ico' };
  try { if (event.data) data = Object.assign(data, event.data.json()); } catch(e) {}
  event.waitUntil(
    self.registration.showNotification(data.title, {
      body: data.body,
      icon: data.icon || '/favicon.ico',
      badge: data.badge || data.icon || '/favicon.ico',
      data: data.url ? { url: data.url } : {},
      vibrate: [200, 100, 200],
    })
  );
});

// Notification click — open the target URL
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = event.notification.data?.url || '/';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      for (const client of list) {
        if (client.url === url && 'focus' in client) return client.focus();
      }
      return clients.openWindow(url);
    })
  );
});
JS;
}

// ═══════════════════════════════════════════════════════════════
//  OFFLINE PAGE — uses CSS variables from design profile
// ═══════════════════════════════════════════════════════════════

function wpilot_pwa_offline_page_html( $site_name, $message, $colors ) {
    $primary   = esc_attr( $colors['primary'] );
    $bg        = esc_attr( $colors['bg'] );
    $text      = esc_attr( $colors['text'] );
    $secondary = esc_attr( $colors['secondary'] );
    $name_esc  = esc_html( $site_name );
    $msg_esc   = esc_html( $message );

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Offline — {$name_esc}</title>
<style>
  :root {
    --wp-primary: {$primary};
    --wp-bg: {$bg};
    --wp-text: {$text};
    --wp-secondary: {$secondary};
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--wp-bg);
    color: var(--wp-text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 2rem;
  }
  .offline-container {
    max-width: 480px;
    width: 100%;
  }
  .offline-icon {
    width: 80px; height: 80px; margin: 0 auto 2rem;
    border-radius: 50%;
    background: var(--wp-primary);
    display: flex; align-items: center; justify-content: center;
    opacity: 0.9;
  }
  .offline-icon svg { width: 40px; height: 40px; fill: var(--wp-bg); }
  h1 { font-size: 1.75rem; margin-bottom: 0.75rem; color: var(--wp-primary); }
  .offline-name { font-size: 0.875rem; text-transform: uppercase; letter-spacing: 2px; opacity: 0.6; margin-bottom: 2rem; }
  .offline-msg { font-size: 1.1rem; line-height: 1.6; opacity: 0.8; margin-bottom: 2.5rem; }
  .retry-btn {
    display: inline-block; padding: 0.875rem 2.5rem;
    background: var(--wp-primary); color: var(--wp-bg);
    border: none; border-radius: 6px; font-size: 1rem;
    cursor: pointer; text-decoration: none;
    transition: opacity 0.2s, transform 0.2s;
  }
  .retry-btn:hover { opacity: 0.85; transform: translateY(-1px); }
  .retry-btn:active { transform: translateY(0); }
</style>
</head>
<body>
<div class="offline-container">
  <div class="offline-icon">
    <svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4c-1.48 0-2.85.43-4.01 1.17l1.46 1.46C10.21 6.23 11.08 6 12 6c3.04 0 5.5 2.46 5.5 5.5v.5H19c1.66 0 3 1.34 3 3 0 .99-.49 1.87-1.24 2.41l1.46 1.46C23.32 17.99 24 16.85 24 15.5c0-2.64-2.05-4.78-4.65-4.96zM3 5.27l2.75 2.74C2.56 8.15 0 10.77 0 14c0 3.31 2.69 6 6 6h11.73l2 2 1.27-1.27L4.27 4 3 5.27zM7.73 10l8 8H6c-2.21 0-4-1.79-4-4s1.79-4 4-4h1.73z"/></svg>
  </div>
  <div class="offline-name">{$name_esc}</div>
  <h1>You're Offline</h1>
  <p class="offline-msg">{$msg_esc}</p>
  <button class="retry-btn" onclick="window.location.reload()">Try Again</button>
</div>
</body>
</html>
HTML;
}

// ═══════════════════════════════════════════════════════════════
//  REGISTRATION JS — install prompt + SW registration
// ═══════════════════════════════════════════════════════════════
// Built by Weblease

function wpilot_pwa_registration_js() {
    $colors    = wpilot_pwa_get_colors();
    $settings  = wpilot_pwa_get_settings();
    $name      = esc_js( $settings['custom_name'] ?: get_bloginfo( 'name' ) );
    $primary   = esc_js( $colors['primary'] );
    $bg        = esc_js( $colors['bg'] );
    $text      = esc_js( $colors['text'] );
    $vapid_pub = esc_js( $settings['vapid_public'] );

    return <<<JS
(function() {
  'use strict';

  // Register service worker
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/wpilot-sw.js', { scope: '/' })
      .then(function(reg) {
        reg.addEventListener('updatefound', function() {
          var nw = reg.installing;
          if (nw) nw.addEventListener('statechange', function() {
            if (nw.state === 'activated' && navigator.serviceWorker.controller) {
              console.log('[WPilot PWA] Updated to new version');
            }
          });
        });
      })
      .catch(function(err) { console.warn('[WPilot PWA] SW registration failed:', err); });
  }

  // Install prompt banner
  var deferredPrompt = null;
  var bannerDismissed = localStorage.getItem('wpilot_pwa_banner_dismissed');

  window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    deferredPrompt = e;

    if (bannerDismissed && Date.now() - parseInt(bannerDismissed) < 604800000) return;

    setTimeout(function() { showInstallBanner(); }, 3000);
  });

  function showInstallBanner() {
    if (document.getElementById('wpilot-pwa-banner')) return;

    var banner = document.createElement('div');
    banner.id = 'wpilot-pwa-banner';
    banner.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:999999;' +
      'background:{$primary};color:{$bg};' +
      'padding:16px 20px;display:flex;align-items:center;justify-content:space-between;' +
      'font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;' +
      'font-size:14px;box-shadow:0 -2px 10px rgba(0,0,0,0.15);' +
      'transform:translateY(100%);transition:transform 0.4s ease;';

    // Build banner content safely with DOM methods
    var textDiv = document.createElement('div');
    textDiv.style.cssText = 'flex:1;margin-right:12px;';

    var strong = document.createElement('strong');
    strong.textContent = 'Add {$name} to Home Screen';
    textDiv.appendChild(strong);

    textDiv.appendChild(document.createElement('br'));

    var hint = document.createElement('span');
    hint.style.cssText = 'opacity:0.85;font-size:12px;';
    hint.textContent = 'Install for quick access, offline support & push notifications';
    textDiv.appendChild(hint);

    var installBtn = document.createElement('button');
    installBtn.id = 'wpilot-pwa-install';
    installBtn.textContent = 'Install';
    installBtn.style.cssText = 'padding:8px 20px;border:2px solid {$bg};' +
      'background:transparent;color:{$bg};border-radius:4px;font-size:13px;font-weight:600;' +
      'cursor:pointer;white-space:nowrap;margin-right:8px;';

    var dismissBtn = document.createElement('button');
    dismissBtn.id = 'wpilot-pwa-dismiss';
    dismissBtn.textContent = '\u00d7';
    dismissBtn.style.cssText = 'background:none;border:none;color:{$bg};' +
      'font-size:20px;cursor:pointer;padding:4px 8px;opacity:0.7;';

    banner.appendChild(textDiv);
    banner.appendChild(installBtn);
    banner.appendChild(dismissBtn);
    document.body.appendChild(banner);

    requestAnimationFrame(function() {
      requestAnimationFrame(function() { banner.style.transform = 'translateY(0)'; });
    });

    installBtn.addEventListener('click', function() {
      if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function() { deferredPrompt = null; });
      }
      removeBanner();
    });

    dismissBtn.addEventListener('click', function() {
      localStorage.setItem('wpilot_pwa_banner_dismissed', Date.now().toString());
      removeBanner();
    });
  }

  function removeBanner() {
    var b = document.getElementById('wpilot-pwa-banner');
    if (b) {
      b.style.transform = 'translateY(100%)';
      setTimeout(function() { b.remove(); }, 400);
    }
  }

  // Push notification subscription
  if ('{$vapid_pub}' && 'PushManager' in window && 'serviceWorker' in navigator) {
    navigator.serviceWorker.ready.then(function(reg) {
      reg.pushManager.getSubscription().then(function(sub) {
        if (sub) return;
        Notification.requestPermission().then(function(perm) {
          if (perm !== 'granted') return;
          reg.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array('{$vapid_pub}')
          }).then(function(newSub) {
            fetch('/wp-json/wpilot/v1/pwa-subscribe', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify(newSub.toJSON())
            });
          });
        });
      });
    });
  }

  function urlBase64ToUint8Array(base64) {
    var padding = '='.repeat((4 - base64.length % 4) % 4);
    var raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));
    var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }
})();
JS;
}

// ═══════════════════════════════════════════════════════════════
//  ICON GENERATOR — resizes site favicon to required sizes
// ═══════════════════════════════════════════════════════════════

function wpilot_pwa_generate_icon( $size ) {
    $icon_url = wpilot_pwa_get_icon_url();

    // Try to get the actual image file
    $icon_id = get_option( 'site_icon' );
    $source  = null;

    if ( $icon_id ) {
        $path = get_attached_file( $icon_id );
        if ( $path && file_exists( $path ) ) {
            $source = $path;
        }
    }

    // Use GD to resize if available
    if ( $source && function_exists( 'imagecreatetruecolor' ) ) {
        $info = getimagesize( $source );
        if ( $info ) {
            $mime = $info['mime'];
            $orig = null;
            if ( $mime === 'image/png' )       $orig = imagecreatefrompng( $source );
            elseif ( $mime === 'image/jpeg' )   $orig = imagecreatefromjpeg( $source );
            elseif ( $mime === 'image/gif' )    $orig = imagecreatefromgif( $source );
            elseif ( $mime === 'image/webp' && function_exists('imagecreatefromwebp') )
                $orig = imagecreatefromwebp( $source );

            if ( $orig ) {
                $resized = imagecreatetruecolor( $size, $size );
                imagealphablending( $resized, false );
                imagesavealpha( $resized, true );
                $transparent = imagecolorallocatealpha( $resized, 0, 0, 0, 127 );
                imagefill( $resized, 0, 0, $transparent );
                imagecopyresampled( $resized, $orig, 0, 0, 0, 0, $size, $size, imagesx( $orig ), imagesy( $orig ) );
                imagedestroy( $orig );

                header( 'Content-Type: image/png' );
                header( 'Cache-Control: public, max-age=31536000' );
                imagepng( $resized );
                imagedestroy( $resized );
                exit;
            }
        }
    }

    // Fallback: generate a colored square with site initial
    $colors = wpilot_pwa_get_colors();
    $img    = imagecreatetruecolor( $size, $size );
    $hex    = ltrim( $colors['primary'], '#' );
    if ( strlen( $hex ) === 3 ) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    $r = hexdec( substr( $hex, 0, 2 ) );
    $g = hexdec( substr( $hex, 2, 2 ) );
    $b = hexdec( substr( $hex, 4, 2 ) );
    $bg_color = imagecolorallocate( $img, $r, $g, $b );
    imagefill( $img, 0, 0, $bg_color );

    $white  = imagecolorallocate( $img, 255, 255, 255 );
    $letter = strtoupper( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ) ?: 'W';
    imagestring( $img, min( 5, intval( $size * 0.5 ) ), intval( $size * 0.3 ), intval( $size * 0.3 ), $letter, $white );

    header( 'Content-Type: image/png' );
    header( 'Cache-Control: public, max-age=31536000' );
    imagepng( $img );
    imagedestroy( $img );
    exit;
}

// ═══════════════════════════════════════════════════════════════
//  REWRITE RULES & REQUEST HANDLERS
// ═══════════════════════════════════════════════════════════════

// Serve manifest.json, service worker, and icons via WordPress
add_action( 'init', function() {
    if ( ! get_option( WPI_PWA_ACTIVE_OPTION ) ) return;

    // Handle manifest.json
    if ( isset( $_GET['wpilot_pwa_manifest'] ) ) {
        header( 'Content-Type: application/manifest+json' );
        header( 'Cache-Control: public, max-age=86400' );
        echo wp_json_encode( wpilot_pwa_manifest_data(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        exit;
    }

    // Handle icon generation
    if ( isset( $_GET['wpilot_pwa_icon'] ) ) {
        $size = intval( $_GET['size'] ?? 192 );
        $size = max( 16, min( 1024, $size ) );
        wpilot_pwa_generate_icon( $size );
    }
});

// Serve service worker from root scope via template_redirect
add_action( 'template_redirect', function() {
    if ( ! get_option( WPI_PWA_ACTIVE_OPTION ) ) return;

    $request = $_SERVER['REQUEST_URI'] ?? '';
    $path    = parse_url( $request, PHP_URL_PATH );

    if ( $path === '/wpilot-sw.js' ) {
        header( 'Content-Type: application/javascript' );
        header( 'Cache-Control: no-cache' );
        header( 'Service-Worker-Allowed: /' );
        echo wpilot_pwa_service_worker_js();
        exit;
    }
});

// Inject <link rel="manifest">, <meta name="theme-color">, and registration script
add_action( 'wp_head', function() {
    if ( ! get_option( WPI_PWA_ACTIVE_OPTION ) ) return;
    if ( is_admin() ) return;

    $colors   = wpilot_pwa_get_colors();
    $manifest = home_url( '/wpilot-manifest.json' );
    ?>
    <link rel="manifest" href="<?php echo esc_url( $manifest ); ?>">
    <meta name="theme-color" content="<?php echo esc_attr( $colors['primary'] ); ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
    <link rel="apple-touch-icon" href="<?php echo esc_url( add_query_arg( [ 'wpilot_pwa_icon' => 1, 'size' => 180 ], home_url( '/' ) ) ); ?>">
    <meta name="msapplication-TileColor" content="<?php echo esc_attr( $colors['primary'] ); ?>">
    <?php
}, 1 );

add_action( 'wp_footer', function() {
    if ( ! get_option( WPI_PWA_ACTIVE_OPTION ) ) return;
    if ( is_admin() ) return;

    echo '<script>' . wpilot_pwa_registration_js() . '</script>' . "\n";
}, 99 );

// ═══════════════════════════════════════════════════════════════
//  REST API — Push subscription endpoint
// ═══════════════════════════════════════════════════════════════

add_action( 'rest_api_init', function() {
    register_rest_route( 'wpilot/v1', '/pwa-subscribe', [
        'methods'             => 'POST',
        'callback'            => 'wpilot_pwa_handle_subscribe',
        'permission_callback' => '__return_true',
    ]);
});

function wpilot_pwa_handle_subscribe( $request ) {
    $data = $request->get_json_params();
    if ( empty( $data['endpoint'] ) ) {
        return new WP_REST_Response( [ 'error' => 'Missing endpoint' ], 400 );
    }

    $sub = [
        'endpoint' => esc_url_raw( $data['endpoint'] ),
        'keys'     => [
            'p256dh' => sanitize_text_field( $data['keys']['p256dh'] ?? '' ),
            'auth'   => sanitize_text_field( $data['keys']['auth'] ?? '' ),
        ],
        'created'  => current_time( 'mysql' ),
        'ip'       => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
        'ua'       => sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200 ) ),
    ];

    $subs = get_option( WPI_PWA_SUBS_OPTION, [] );

    // Deduplicate by endpoint
    $subs = array_filter( $subs, function( $s ) use ( $sub ) {
        return $s['endpoint'] !== $sub['endpoint'];
    });

    $subs[] = $sub;

    // Cap at 10000 subscribers
    if ( count( $subs ) > 10000 ) {
        $subs = array_slice( $subs, -10000 );
    }

    update_option( WPI_PWA_SUBS_OPTION, array_values( $subs ), false );

    return new WP_REST_Response( [ 'success' => true, 'total' => count( $subs ) ] );
}

// ═══════════════════════════════════════════════════════════════
//  MU-PLUGIN — Service Worker rewrite from root
// ═══════════════════════════════════════════════════════════════

function wpilot_pwa_install_mu_plugin() {
    $mu_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    if ( ! is_dir( $mu_dir ) ) wp_mkdir_p( $mu_dir );

    $mu_path = $mu_dir . '/wpilot-pwa-sw.php';
    // Write manifest.json as a static file in the plugin directory
    $manifest_data = function_exists( 'wpilot_pwa_manifest_data' ) ? wpilot_pwa_manifest_data() : [
        'name'             => get_bloginfo( 'name' ),
        'short_name'       => substr( get_bloginfo( 'name' ), 0, 12 ),
        'start_url'        => '/',
        'display'          => 'standalone',
        'background_color' => '#ffffff',
        'theme_color'      => '#000000',
    ];
    $manifest_json = wp_json_encode( $manifest_data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
    file_put_contents( ABSPATH . 'wpilot-manifest.json', $manifest_json );

    // Write service worker JS as static file in root
    if ( function_exists( 'wpilot_pwa_service_worker_js' ) ) {
        file_put_contents( ABSPATH . 'wpilot-sw.js', wpilot_pwa_service_worker_js() );
    }

    // mu-plugin only needed for icon generation fallback
    $mu_code = "<?php\n";
    $mu_code .= "// WPilot PWA — Icon generation handler\n";
    $mu_code .= "if (!defined('ABSPATH')) exit;\n\n";
    $mu_code .= "add_action('init', function() {\n";
    $mu_code .= "    if (!get_option('wpilot_pwa_active')) return;\n";
    $mu_code .= "    if (isset(\$_GET['wpilot_pwa_icon']) && function_exists('wpilot_pwa_generate_icon')) {\n";
    $mu_code .= "        wpilot_pwa_generate_icon(intval(\$_GET['size'] ?? 192));\n";
    $mu_code .= "        exit;\n";
    $mu_code .= "    }\n";
    $mu_code .= "}, 0);\n";

    if ( function_exists( 'wpilot_mu_register' ) ) {
        wpilot_mu_register( 'pwa', $mu_code );
    } else {
        // Fallback: write directly
        file_put_contents( $mu_path, $mu_code );
    }
}

function wpilot_pwa_remove_mu_plugin() {
    if ( function_exists( 'wpilot_mu_remove' ) ) {
        wpilot_mu_remove( 'pwa' );
    }
    // Also remove legacy file if it exists
    $mu_dir  = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
    $mu_path = $mu_dir . '/wpilot-pwa-sw.php';
    if ( file_exists( $mu_path ) ) @unlink( $mu_path );
}

// ═══════════════════════════════════════════════════════════════
//  WOOCOMMERCE — Push on order status change
// ═══════════════════════════════════════════════════════════════

add_action( 'woocommerce_order_status_changed', function( $order_id, $from, $to ) {
    if ( ! get_option( WPI_PWA_ACTIVE_OPTION ) ) return;

    $settings = wpilot_pwa_get_settings();
    if ( empty( $settings['vapid_public'] ) || empty( $settings['vapid_private'] ) ) return;

    if ( ! function_exists( 'wc_get_order' ) ) return;
    $order = wc_get_order( $order_id );
    if ( ! $order ) return;

    $messages = [
        'processing' => 'Your order #%s is being processed!',
        'completed'  => 'Your order #%s has been completed!',
        'on-hold'    => 'Your order #%s is on hold.',
        'cancelled'  => 'Your order #%s has been cancelled.',
        'refunded'   => 'Your order #%s has been refunded.',
        'shipped'    => 'Your order #%s has been shipped!',
    ];

    if ( ! isset( $messages[ $to ] ) ) return;

    $payload = [
        'title' => get_bloginfo( 'name' ) . ' — Order Update',
        'body'  => sprintf( $messages[ $to ], $order->get_order_number() ),
        'icon'  => wpilot_pwa_get_icon_url(),
        'url'   => $order->get_view_order_url(),
    ];

    wpilot_pwa_send_push_to_all( $payload );
}, 10, 3 );

// ═══════════════════════════════════════════════════════════════
//  PUSH NOTIFICATION SENDER
// ═══════════════════════════════════════════════════════════════

function wpilot_pwa_send_push_to_all( $payload ) {
    $settings = wpilot_pwa_get_settings();
    $subs     = get_option( WPI_PWA_SUBS_OPTION, [] );

    if ( empty( $subs ) ) return [ 'sent' => 0, 'failed' => 0, 'total' => 0 ];
    if ( empty( $settings['vapid_public'] ) || empty( $settings['vapid_private'] ) ) {
        return [ 'sent' => 0, 'failed' => 0, 'error' => 'VAPID keys not configured' ];
    }

    $json_payload = wp_json_encode( $payload );
    $sent   = 0;
    $failed = 0;
    $dead   = [];

    foreach ( $subs as $i => $sub ) {
        if ( empty( $sub['endpoint'] ) ) { $dead[] = $i; continue; }

        $response = wp_remote_post( $sub['endpoint'], [
            'timeout' => 10,
            'headers' => [
                'Content-Type'     => 'application/json',
                'TTL'              => '86400',
            ],
            'body' => $json_payload,
        ]);

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            $sent++;
        } else {
            $failed++;
            // Remove expired/invalid subscriptions (410 Gone, 404 Not Found)
            if ( $code === 410 || $code === 404 ) {
                $dead[] = $i;
            }
        }
    }

    // Clean dead subscriptions
    if ( ! empty( $dead ) ) {
        foreach ( $dead as $idx ) unset( $subs[ $idx ] );
        update_option( WPI_PWA_SUBS_OPTION, array_values( $subs ), false );
    }

    return [ 'sent' => $sent, 'failed' => $failed, 'total' => count( $subs ) ];
}

// ═══════════════════════════════════════════════════════════════
//  BLUEPRINT AUTO-INTEGRATION
// ═══════════════════════════════════════════════════════════════

// When apply_blueprint runs, auto-update PWA manifest colors
add_action( 'update_option_wpilot_design_profile', function( $old, $new ) {
    if ( ! get_option( WPI_PWA_ACTIVE_OPTION ) ) return;

    // Bump cache version to force SW update
    $settings = wpilot_pwa_get_settings();
    $v = intval( str_replace( 'v', '', $settings['cache_version'] ) );
    $settings['cache_version'] = 'v' . ( $v + 1 );
    update_option( WPI_PWA_OPTION, $settings );
}, 10, 2 );

// ═══════════════════════════════════════════════════════════════
//  AI BUBBLE TOOLS
// ═══════════════════════════════════════════════════════════════

function wpilot_run_pwa_tools( $tool, $params = [] ) {
    switch ( $tool ) {

        // ── Enable PWA ───────────────────────────────────────
        case 'enable_pwa':
            if ( get_option( WPI_PWA_ACTIVE_OPTION ) ) {
                return wpilot_ok( 'PWA is already active on this site.' );
            }

            update_option( WPI_PWA_ACTIVE_OPTION, true );

            // Install mu-plugin for root-scope service worker
            wpilot_pwa_install_mu_plugin();

            // Flush rewrite rules so manifest URL works
            flush_rewrite_rules();

            // Bust caches
            if ( function_exists( 'wpilot_bust_cache' ) ) wpilot_bust_cache();

            $colors = wpilot_pwa_get_colors();
            return wpilot_ok(
                'PWA enabled! Site is now installable on mobile devices. Manifest, service worker, offline page, and install banner are all active.',
                [
                    'manifest_url' => add_query_arg( 'wpilot_pwa_manifest', '1', home_url( '/' ) ),
                    'sw_url'       => home_url( '/wpilot-sw.js' ),
                    'theme_color'  => $colors['primary'],
                    'bg_color'     => $colors['bg'],
                ]
            );

        // ── Disable PWA ──────────────────────────────────────
        case 'disable_pwa':
            delete_option( WPI_PWA_ACTIVE_OPTION );
            wpilot_pwa_remove_mu_plugin();
            flush_rewrite_rules();

            if ( function_exists( 'wpilot_bust_cache' ) ) wpilot_bust_cache();

            return wpilot_ok( 'PWA disabled. Manifest, service worker, and install banner removed.' );

        // ── PWA Status ───────────────────────────────────────
        case 'pwa_status':
            $active   = (bool) get_option( WPI_PWA_ACTIVE_OPTION );
            $settings = wpilot_pwa_get_settings();
            $colors   = wpilot_pwa_get_colors();
            $subs     = get_option( WPI_PWA_SUBS_OPTION, [] );
            $mu_dir   = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

            $status = [
                'active'           => $active,
                'cache_version'    => $settings['cache_version'],
                'theme_color'      => $colors['primary'],
                'bg_color'         => $colors['bg'],
                'display'          => $settings['display'],
                'subscribers'      => count( $subs ),
                'vapid_configured' => ! empty( $settings['vapid_public'] ),
                'mu_plugin'        => file_exists( $mu_dir . '/wpilot-pwa-sw.php' ),
                'has_favicon'      => (bool) get_option( 'site_icon' ),
                'manifest_url'     => $active ? add_query_arg( 'wpilot_pwa_manifest', '1', home_url( '/' ) ) : null,
                'sw_url'           => $active ? home_url( '/wpilot-sw.js' ) : null,
            ];

            $msg = $active
                ? "PWA is active. {$status['subscribers']} push subscribers. Cache {$settings['cache_version']}."
                : 'PWA is not active. Use enable_pwa to activate.';

            return wpilot_ok( $msg, [ 'status' => $status ] );

        // ── Send Push Notification ───────────────────────────
        case 'send_push':
            if ( ! get_option( WPI_PWA_ACTIVE_OPTION ) ) {
                return wpilot_err( 'PWA is not active. Enable it first with enable_pwa.' );
            }

            $title = sanitize_text_field( $params['title'] ?? get_bloginfo( 'name' ) );
            $body  = sanitize_text_field( $params['body'] ?? $params['message'] ?? '' );
            $url   = esc_url_raw( $params['url'] ?? home_url( '/' ) );
            $icon  = esc_url_raw( $params['icon'] ?? wpilot_pwa_get_icon_url() );

            if ( empty( $body ) ) {
                return wpilot_err( 'Push notification body/message required.' );
            }

            $payload = [
                'title' => $title,
                'body'  => $body,
                'icon'  => $icon,
                'url'   => $url,
            ];

            $result = wpilot_pwa_send_push_to_all( $payload );

            if ( isset( $result['error'] ) ) {
                return wpilot_err( "Push failed: {$result['error']}. Configure VAPID keys with pwa_configure." );
            }

            return wpilot_ok(
                "Push notification sent to {$result['sent']}/{$result['total']} subscribers.",
                [ 'result' => $result, 'payload' => $payload ]
            );

        // ── Configure PWA ────────────────────────────────────
        case 'pwa_configure':
            $settings = wpilot_pwa_get_settings();
            $updated  = [];

            $map = [
                'name'            => 'custom_name',
                'short_name'      => 'custom_short',
                'icon'            => 'custom_icon',
                'bg_color'        => 'custom_bg',
                'theme_color'     => 'custom_theme',
                'display'         => 'display',
                'orientation'     => 'orientation',
                'start_url'       => 'start_url',
                'offline_message' => 'offline_message',
                'vapid_public'    => 'vapid_public',
                'vapid_private'   => 'vapid_private',
            ];

            foreach ( $map as $param_key => $setting_key ) {
                if ( isset( $params[ $param_key ] ) && $params[ $param_key ] !== '' ) {
                    $settings[ $setting_key ] = sanitize_text_field( $params[ $param_key ] );
                    $updated[] = $param_key;
                }
            }

            if ( empty( $updated ) ) {
                return wpilot_err( 'No settings provided. Available: name, short_name, icon, bg_color, theme_color, display, orientation, start_url, offline_message, vapid_public, vapid_private.' );
            }

            // Bump cache version
            $v = intval( str_replace( 'v', '', $settings['cache_version'] ) );
            $settings['cache_version'] = 'v' . ( $v + 1 );

            update_option( WPI_PWA_OPTION, $settings );

            if ( function_exists( 'wpilot_bust_cache' ) ) wpilot_bust_cache();

            return wpilot_ok(
                'PWA settings updated: ' . implode( ', ', $updated ) . '. Cache version bumped to ' . $settings['cache_version'] . '.',
                [ 'settings' => $settings ]
            );

        default:
            return null;
    }
}
