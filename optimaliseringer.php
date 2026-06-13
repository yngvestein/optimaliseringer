<?php
/**
 * Plugin Name: Optimaliseringer
 * Description: Bildekomprimering (AVIF/WebP), sikkerhets- og ytelsesoptimaliseringer for WordPress.
 * Version:     1.3.0
 * Author:      Yngve Stein
 * Update URI:  https://github.com/yngvestein/optimaliseringer/
 */

defined('ABSPATH') || exit;

// -------------------------------------------------------------------------
// Auto-oppdatering fra GitHub
// -------------------------------------------------------------------------

require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$optim_updater = PucFactory::buildUpdateChecker(
    'https://github.com/yngvestein/optimaliseringer/',
    __FILE__,
    'optimaliseringer'
);
$optim_updater->setBranch('main');

// -------------------------------------------------------------------------
// 1. Bilder → AVIF (med WebP-fallback) + maks 1920 px
// -------------------------------------------------------------------------

add_filter('wp_handle_upload', 'optim_handle_uploaded_image');

function optim_handle_uploaded_image(array $upload): array {
    $convertible = ['image/jpeg', 'image/png', 'image/gif'];

    if (!in_array($upload['type'], $convertible, true)) {
        return $upload;
    }

    $file = $upload['file'];

    if ($upload['type'] === 'image/gif' && optim_is_animated_gif($file)) {
        return $upload;
    }

    $image = optim_load_image($file, $upload['type']);
    if (!$image) {
        return $upload;
    }

    $image = optim_maybe_resize($image, $upload['type'], 1920);

    $result = optim_save_modern_format($image, $file);
    imagedestroy($image);

    if (!$result) {
        return $upload;
    }

    if ($result['file'] !== $file) {
        @unlink($file);
    }

    return array_merge($upload, [
        'file' => $result['file'],
        'url'  => preg_replace('/\.[^.]+$/', '.' . pathinfo($result['file'], PATHINFO_EXTENSION), $upload['url']),
        'type' => $result['type'],
    ]);
}

function optim_save_modern_format($image, string $file): ?array {
    if (function_exists('imageavif') && (imagetypes() & IMG_AVIF)) {
        $out = preg_replace('/\.[^.]+$/', '.avif', $file);
        if (@imageavif($image, $out, 60, 6)) {
            return ['file' => $out, 'type' => 'image/avif'];
        }
    }
    if (function_exists('imagewebp') && (imagetypes() & IMG_WEBP)) {
        $out = preg_replace('/\.[^.]+$/', '.webp', $file);
        if (@imagewebp($image, $out, 82)) {
            return ['file' => $out, 'type' => 'image/webp'];
        }
    }
    return null;
}

function optim_load_image(string $path, string $type) {
    switch ($type) {
        case 'image/jpeg': return imagecreatefromjpeg($path);
        case 'image/png':  return imagecreatefrompng($path);
        case 'image/gif':  return imagecreatefromgif($path);
    }
    return false;
}

function optim_maybe_resize($image, string $type, int $max) {
    $w = imagesx($image);
    $h = imagesy($image);

    if ($w <= $max && $h <= $max) {
        return $image;
    }

    if ($w >= $h) {
        $nw = $max;
        $nh = (int) round($h * $max / $w);
    } else {
        $nh = $max;
        $nw = (int) round($w * $max / $h);
    }

    $canvas = imagecreatetruecolor($nw, $nh);

    if (in_array($type, ['image/png', 'image/gif'], true)) {
        imagealphablend($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    }

    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($image);

    return $canvas;
}

function optim_is_animated_gif(string $path): bool {
    $data = file_get_contents($path, false, null, 0, 65536);
    return $data !== false && substr_count($data, "\x00\x21\xF9\x04") > 1;
}

// -------------------------------------------------------------------------
// 2. SVG-opplasting (kun for administratorer – SVG kan inneholde skript)
// -------------------------------------------------------------------------

add_filter('upload_mimes',              'optim_allow_svg');
add_filter('wp_check_filetype_and_ext', 'optim_fix_svg_mime', 10, 4);

function optim_allow_svg(array $mimes): array {
    if (current_user_can('manage_options')) {
        $mimes['svg']  = 'image/svg+xml';
        $mimes['svgz'] = 'image/svg+xml';
    }
    return $mimes;
}

function optim_fix_svg_mime(array $data, $file, string $filename, $mimes): array {
    if (empty($data['ext']) && strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
        $data['ext']  = 'svg';
        $data['type'] = 'image/svg+xml';
    }
    return $data;
}

// -------------------------------------------------------------------------
// 3. Deaktiver emoji-scripts
// -------------------------------------------------------------------------

add_action('init', 'optim_disable_emojis');

function optim_disable_emojis(): void {
    remove_action('wp_head',             'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles',     'print_emoji_styles');
    remove_action('admin_print_styles',  'print_emoji_styles');
    remove_filter('the_content_feed',    'wp_staticize_emoji');
    remove_filter('comment_text_rss',    'wp_staticize_emoji');
    remove_filter('wp_mail',             'wp_staticize_emoji_for_email');
    add_filter('tiny_mce_plugins',       'optim_disable_emojis_tinymce');
    add_filter('wp_resource_hints',      'optim_disable_emojis_dns_prefetch', 10, 2);
}

function optim_disable_emojis_tinymce(array $plugins): array {
    return array_diff($plugins, ['wpemoji']);
}

function optim_disable_emojis_dns_prefetch(array $urls, string $relation_type): array {
    if ($relation_type !== 'dns-prefetch') {
        return $urls;
    }
    return array_filter($urls, fn($url) => !str_contains((string) $url, 'twemoji'));
}

// -------------------------------------------------------------------------
// 4. Fjern unødvendige <head>-tags
// -------------------------------------------------------------------------

add_action('init', 'optim_clean_head');

function optim_clean_head(): void {
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wp_shortlink_wp_head');
    remove_action('wp_head', 'wp_generator');
    remove_action('wp_head', 'feed_links_extra', 3);
    remove_action('wp_head', 'adjacent_posts_rel_link_wp_head', 10);
}

// -------------------------------------------------------------------------
// 5. Deaktiver XML-RPC
// -------------------------------------------------------------------------

add_filter('xmlrpc_enabled', '__return_false');
add_filter('xmlrpc_methods', fn() => []);
add_filter('wp_headers',     'optim_remove_pingback_header');

function optim_remove_pingback_header(array $headers): array {
    unset($headers['X-Pingback']);
    return $headers;
}

// -------------------------------------------------------------------------
// 6. Begrens post-revisjoner til 5
// -------------------------------------------------------------------------

add_filter('wp_revisions_to_keep', fn($num, $post) => 5, 10, 2);
