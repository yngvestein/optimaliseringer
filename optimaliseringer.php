<?php
/**
 * Plugin Name: Optimaliseringer
 * Description: Bildekomprimering (AVIF/WebP), sikkerhets- og ytelsesoptimaliseringer for WordPress.
 * Version:     1.5.1
 * Requires at least: 7.0
 * Author:      Yngve Stein
 * Update URI:  https://github.com/yngvestein/optimaliseringer/
 */

defined('ABSPATH') || exit;

// Deaktiver fil-redigering i admin (tema-/plugin-editor) – hindrer kjøring
// av vilkårlig kode dersom en admin-konto kompromitteres.
if (!defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}

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
// 1. Bildekomprimering: konverter til AVIF/WebP + skaler ned ved opplasting
//    Format og maks. dimensjon styres fra Innstillinger → Media.
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

    $image = optim_maybe_resize($image, $upload['type'], (int) get_option('optim_max_dimension', 1920));

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
    $format = get_option('optim_image_format', 'avif');

    if ($format === 'avif' && function_exists('imageavif') && (imagetypes() & IMG_AVIF)) {
        $out = preg_replace('/\.[^.]+$/', '.avif', $file);
        if (@imageavif($image, $out, 60, 6)) {
            return ['file' => $out, 'type' => 'image/avif'];
        }
        // Server støtter ikke AVIF – fall tilbake til WebP
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

// -------------------------------------------------------------------------
// 7. Sikkerhets-headers (frontend)
// -------------------------------------------------------------------------
// Trygge headers som ikke bryter sidebyggere/eksterne skript. Bevisst UTEN
// Content-Security-Policy (krever per-side-tilpasning) og UTEN
// includeSubDomains på HSTS (subdomener kan ha self-signed sertifikat).

add_action('send_headers', 'optim_security_headers');

function optim_security_headers(): void {
    if (is_admin()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if (is_ssl()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

// -------------------------------------------------------------------------
// 8. Automatisk alt-tekst ved bildeopplasting (krever WP 7.0+)
//    Steg 1: filnavn → lesbar alt-tekst (synkront, umiddelbart)
//    Steg 2: WP AI Client analyserer bildet i bakgrunnen (WP-Cron)
//            for bilder med svake filnavn (kamera-IDer, stockfoto, etc.)
// -------------------------------------------------------------------------

add_action('add_attachment',              'optim_auto_alt_on_upload');
add_action('optim_gemini_alt',           'optim_ai_analyze_and_set', 10, 1);
add_action('wp_ajax_optim_bulk_alt',     'optim_ajax_bulk_alt');
add_action('wp_ajax_optim_poll_ai_prog', 'optim_ajax_poll_ai_prog');

function optim_auto_alt_on_upload(int $attachment_id): void {
    if (!wp_attachment_is_image($attachment_id)) {
        return;
    }
    if (get_post_meta($attachment_id, '_wp_attachment_image_alt', true) !== '') {
        return;
    }

    $filename = pathinfo(get_attached_file($attachment_id), PATHINFO_FILENAME);
    $alt      = optim_alt_from_filename($filename);

    if ($alt !== '') {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
    }

    if (optim_filename_is_weak($filename)) {
        update_post_meta($attachment_id, '_optim_ai_pending', '1');
        wp_schedule_single_event(time(), 'optim_gemini_alt', [$attachment_id]);
    }
}

/**
 * Renser filnavn til lesbar alt-tekst.
 * Returnerer tom streng om resultatet ikke er meningsfullt.
 */
function optim_alt_from_filename(string $filename): string {
    // Fjern WordPress-suffiks som -scaled, -e{timestamp}, -NNNxNNN, -rotated
    $clean = preg_replace('/-e\d{10,}/', '', $filename);
    $clean = preg_replace('/-\d+x\d+$/', '', $clean);
    $clean = preg_replace('/-(scaled|rotated|copy)$/i', '', $clean);

    // Erstatt skilletegn med mellomrom
    $clean = str_replace(['_', '-'], ' ', $clean);
    $clean = preg_replace('/\s+/', ' ', trim($clean));

    // Filtrer ut rene tallord (kamera-IDer, tidsstempler)
    $words = array_filter(explode(' ', $clean), fn($w) => !preg_match('/^\d+$/', $w) && strlen($w) > 0);

    if (count($words) < 1) {
        return '';
    }

    $alt = implode(' ', $words);
    return mb_strtoupper(mb_substr($alt, 0, 1)) . mb_substr($alt, 1);
}

/**
 * Returnerer true om filnavnet ikke gir nok kontekst for en god alt-tekst.
 * Disse bildene egner seg for Gemini-analyse.
 */
function optim_filename_is_weak(string $filename): bool {
    $lower = strtolower($filename);

    // Stockfoto-IDer
    if (preg_match('/^(adobestock|shutterstock|istock|gettyimages|dreamstime)[_-]/', $lower)) {
        return true;
    }

    // Kamera-filnavn: IMG_1234, DSC_0001, DSCN1234, P1234567, 20240315_143022
    if (preg_match('/^(img|dsc|dscn|p|photo|pic|image)[_-]?\d+/i', $lower)) {
        return true;
    }

    // Rene UUID-er eller hash-er
    if (preg_match('/^[a-f0-9]{8,}(-[a-f0-9]+)*$/i', $lower)) {
        return true;
    }

    // Svært korte filnavn (1 meningsfylt ord etter rensing)
    $alt = optim_alt_from_filename($filename);
    $words = array_filter(explode(' ', $alt), fn($w) => strlen($w) > 2);
    if (count($words) <= 1) {
        return true;
    }

    return false;
}

/**
 * Kjøres av WP-Cron i bakgrunnen.
 * Bruker WordPress 7.0 AI Client — leverandør konfigureres under Innstillinger → Connectors.
 */
function optim_ai_analyze_and_set(int $attachment_id): void {
    $file = get_attached_file($attachment_id);
    if (!$file || !file_exists($file)) {
        return;
    }

    $mime      = get_post_mime_type($attachment_id);
    $supported = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/avif'];
    if (!in_array($mime, $supported, true)) {
        return;
    }

    $locale   = get_locale();
    $norwegian = str_starts_with($locale, 'nb') || str_starts_with($locale, 'nn');
    $prompt   = $norwegian
        ? 'Generer en kort, beskrivende alt-tekst for dette bildet på norsk. Maks 10 ord. Kun alt-teksten, ingen forklaring eller tegnsetting på slutten.'
        : 'Generate a short, descriptive alt text for this image. Maximum 10 words. Only the alt text, no explanation or trailing punctuation.';

    try {
        $file_dto = new \WordPress\AiClient\Files\DTO\File([
            'data'      => base64_encode(file_get_contents($file)),
            'mime_type' => $mime,
        ]);

        $result = wp_ai_client_prompt()
            ->with_text($prompt)
            ->with_file($file_dto)
            ->using_max_tokens(60)
            ->using_temperature(0.2)
            ->generate_text();

        if (!is_wp_error($result)) {
            $alt = trim((string) $result, " \t\n\r\0\x0B.\"'");
            if ($alt !== '') {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
            }
        }
    } catch (\Throwable $e) {
        // Ingen connector konfigurert — filnavn-alt-tekst gjelder
    } finally {
        delete_post_meta($attachment_id, '_optim_ai_pending');
    }
}

function optim_ajax_bulk_alt(): void {
    check_ajax_referer('optim_bulk_alt');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Ingen tilgang.');
    }

    global $wpdb;

    // Rydd opp eventuelle hengede pending-flagg fra avbrutte kjøringer
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_optim_ai_pending'");

    $ids = $wpdb->get_col(
        "SELECT p.ID FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm
           ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
         WHERE p.post_type = 'attachment'
           AND p.post_mime_type LIKE 'image/%'
           AND (pm.meta_value IS NULL OR pm.meta_value = '')
         ORDER BY p.ID DESC"
    );

    $done_sync = 0;
    $queued_ai = 0;

    foreach ($ids as $id) {
        $id       = (int) $id;
        $filename = pathinfo(get_attached_file($id), PATHINFO_FILENAME);
        $alt      = optim_alt_from_filename($filename);

        if ($alt !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', $alt);
            $done_sync++;
        }

        if (optim_filename_is_weak($filename)) {
            update_post_meta($id, '_optim_ai_pending', '1');
            wp_schedule_single_event(time(), 'optim_gemini_alt', [$id]);
            $queued_ai++;
        }
    }

    $msg = sprintf('%d bilder oppdatert fra filnavn', $done_sync);
    if ($queued_ai > 0) {
        $msg .= sprintf(', %d satt i kø for AI-analyse', $queued_ai);
    } else {
        $msg .= '.';
    }

    wp_send_json_success(['message' => $msg, 'total' => count($ids), 'queued_ai' => $queued_ai]);
}

function optim_ajax_poll_ai_prog(): void {
    check_ajax_referer('optim_bulk_alt');
    if (!current_user_can('manage_options')) {
        wp_send_json_error();
    }

    global $wpdb;
    $remaining = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_optim_ai_pending'"
    );

    wp_send_json_success(['remaining' => $remaining]);
}

// -------------------------------------------------------------------------
// 9. Innstillinger → Media: komprimering og bulk alt-tekst
// -------------------------------------------------------------------------

add_action('admin_init', 'optim_register_media_settings');

function optim_register_media_settings(): void {
    register_setting('media', 'optim_max_dimension', [
        'type'              => 'integer',
        'default'           => 1920,
        'sanitize_callback' => fn($v) => max(400, min(8000, (int) $v)),
    ]);
    register_setting('media', 'optim_image_format', [
        'type'              => 'string',
        'default'           => 'avif',
        'sanitize_callback' => fn($v) => in_array($v, ['avif', 'webp'], true) ? $v : 'avif',
    ]);

    add_settings_section('optim_compression', 'Bildekomprimering', '__return_false', 'media');

    add_settings_field(
        'optim_max_dimension', 'Maks bildestørrelse',
        'optim_field_max_dimension', 'media', 'optim_compression'
    );
    add_settings_field(
        'optim_image_format', 'Utdataformat',
        'optim_field_image_format', 'media', 'optim_compression'
    );

    add_settings_section('optim_alt_bulk', 'Alt-tekst for mediebibliotek', '__return_false', 'media');

    add_settings_field(
        'optim_bulk_alt_field', 'Bilder uten alt-tekst',
        'optim_field_bulk_alt', 'media', 'optim_alt_bulk'
    );
}

function optim_field_max_dimension(): void {
    $val = (int) get_option('optim_max_dimension', 1920);
    printf(
        '<input type="number" name="optim_max_dimension" id="optim_max_dimension"
                value="%d" min="400" max="8000" step="10" class="small-text" /> px
         <p class="description">Bilder som er bredere eller høyere enn dette skaleres ned ved opplasting.</p>',
        $val
    );
}

function optim_field_image_format(): void {
    $format = get_option('optim_image_format', 'avif');
    foreach (['avif' => 'AVIF (anbefalt)', 'webp' => 'WebP'] as $value => $label) {
        printf(
            '<label style="margin-right:1.5em"><input type="radio" name="optim_image_format"
                    value="%s" %s /> %s</label>',
            esc_attr($value),
            checked($format, $value, false),
            esc_html($label)
        );
    }
    echo '<p class="description">Alle opplastede JPEG-, PNG- og GIF-bilder konverteres alltid til valgt format.</p>';
}

function optim_field_bulk_alt(): void {
    global $wpdb;
    $count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm
           ON pm.post_id = p.ID AND pm.meta_key = '_wp_attachment_image_alt'
         WHERE p.post_type = 'attachment'
           AND p.post_mime_type LIKE 'image/%'
           AND (pm.meta_value IS NULL OR pm.meta_value = '')"
    );

    if ($count === 0) {
        echo '<span style="color:#00a32a">&#10003; Alle bilder har alt-tekst.</span>';
        return;
    }

    printf(
        '<p style="margin:0 0 .6em">%d bilder mangler alt-tekst.</p>
         <button type="button" id="optim-bulk-btn" class="button button-primary" data-nonce="%s">
             Generer alt-tekst for alle
         </button>',
        $count,
        esc_attr(wp_create_nonce('optim_bulk_alt'))
    );
    ?>
    <div id="optim-bulk-wrap" style="margin-top:10px;max-width:420px;display:none">
        <div style="background:#f0f0f1;border-radius:100px;height:8px;overflow:hidden">
            <div id="optim-bar-fill" style="
                height:100%;width:4%;
                background:linear-gradient(90deg,#2271b1 0%,#72aee6 50%,#2271b1 100%);
                background-size:200% 100%;
                border-radius:100px;
                transition:width .5s ease;
            "></div>
        </div>
        <p id="optim-bar-msg" style="margin:.5em 0 0;color:#646970;font-size:13px;font-style:italic"></p>
    </div>
    <p id="optim-bulk-done" style="display:none;color:#00a32a;font-weight:600;margin-top:8px"></p>
    <style>
    #optim-bar-fill { animation: optim-shimmer 1.8s linear infinite; }
    @keyframes optim-shimmer {
        from { background-position: 200% 0; }
        to   { background-position: -200% 0; }
    }
    </style>
    <script>
    (function () {
        const msgs = [
            'Viser bildet til AI-en og venter spent…',
            'Nevrale nettverk surrer behagelig…',
            'Forsøker å ikke hallusinere…',
            'Ansvarsfull AI™ tenker hardt…',
            'Omgjør piksler til presise ord…',
            'Spør skyene snilt om hjelp…',
            'Henter frem sin indre poet…',
            'Teller farger med matematisk nøyaktighet…',
            'Omgjør bildets sjel til maks. 10 ord…',
            'Litt til — AI er grundig…',
        ];

        document.getElementById('optim-bulk-btn').addEventListener('click', function () {
            const btn   = this;
            const wrap  = document.getElementById('optim-bulk-wrap');
            const fill  = document.getElementById('optim-bar-fill');
            const msg   = document.getElementById('optim-bar-msg');
            const done  = document.getElementById('optim-bulk-done');
            const nonce = btn.dataset.nonce;

            btn.disabled = true;
            wrap.style.display = 'block';
            msg.textContent    = 'Oppdaterer fra filnavn…';
            fill.style.width   = '4%';

            fetch(ajaxurl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    new URLSearchParams({ action: 'optim_bulk_alt', _ajax_nonce: nonce }),
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    wrap.style.display = 'none';
                    done.style.color   = '#d63638';
                    done.style.display = 'block';
                    done.textContent   = '✗ ' + (data.data || 'Ukjent feil');
                    btn.disabled = false;
                    return;
                }

                const aiQueued = data.data.queued_ai || 0;

                if (aiQueued === 0) {
                    wrap.style.display = 'none';
                    done.style.display = 'block';
                    done.textContent   = '✓ ' + data.data.message;
                    return;
                }

                // Filnavn er ferdig – vis AI-fremdrift
                msg.textContent  = msgs[0];
                fill.style.width = '8%';

                let msgIdx  = 0;
                let elapsed = 0;
                const maxWait = 120; // sekunder

                const msgTimer = setInterval(() => {
                    msgIdx = (msgIdx + 1) % msgs.length;
                    msg.textContent = msgs[msgIdx];
                }, 2200);

                const pollTimer = setInterval(() => {
                    elapsed += 2;

                    if (elapsed >= maxWait) {
                        clearInterval(pollTimer);
                        clearInterval(msgTimer);
                        wrap.style.display = 'none';
                        done.style.color   = '#646970';
                        done.style.display = 'block';
                        done.textContent   = '⏱ ' + data.data.message + '. AI-analysen kjøres automatisk i bakgrunnen.';
                        return;
                    }

                    fetch(ajaxurl, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    new URLSearchParams({ action: 'optim_poll_ai_prog', _ajax_nonce: nonce }),
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (!d.success) return;
                        const remaining = d.data.remaining;
                        const pct = Math.round(((aiQueued - remaining) / aiQueued) * 100);
                        fill.style.width = Math.min(96, Math.max(8, pct)) + '%';

                        if (remaining === 0) {
                            clearInterval(pollTimer);
                            clearInterval(msgTimer);
                            fill.style.width = '100%';
                            setTimeout(() => {
                                wrap.style.display = 'none';
                                done.style.display = 'block';
                                done.textContent   = '✓ ' + data.data.message + ' — ' + aiQueued + ' analysert av AI. Bra jobba, begge to!';
                            }, 600);
                        }
                    })
                    .catch(() => {});
                }, 2000);
            })
            .catch(() => {
                wrap.style.display = 'none';
                done.style.color   = '#d63638';
                done.style.display = 'block';
                done.textContent   = '✗ Tilkoblingsfeil.';
                btn.disabled = false;
            });
        });
    }());
    </script>
    <?php
}
