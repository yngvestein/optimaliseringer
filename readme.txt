=== Optimaliseringer ===
Contributors: yngvestein
Tags: performance, images, avif, webp, security
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.4.0
License: GPLv2 or later

Bildekomprimering (AVIF/WebP), sikkerhets- og ytelsesoptimaliseringer for WordPress.

== Description ==

Konverterer opplastede bilder til AVIF (med WebP-fallback) og skalerer ned til maks 1920 px. Inkluderer sikkerhets- og ytelsestiltak som deaktivering av XML-RPC, emoji-scripts og unødvendige head-tags, SVG-opplasting for administratorer, og begrensning av post-revisjoner.

== Changelog ==

= 1.4.0 =
* Ny: Sikkerhets-headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, HSTS over HTTPS)
* Ny: Deaktiverer fil-redigering i admin (DISALLOW_FILE_EDIT)

= 1.3.0 =
* Ny: AVIF-konvertering med WebP-fallback (AVIF gir 20–50 % mindre filer enn WebP)

= 1.2.0 =
* Ny: Begrensning av post-revisjoner til maks 5

= 1.1.1 =
* Fix: Fjernet ugyldig remove_action for wlwmanifest_link (fjernet i WP 6.1)

= 1.1.0 =
* Ny: SVG-opplasting (kun administratorer)
* Ny: Deaktiver emoji-scripts
* Ny: Rydder unødvendige head-tags (RSD, shortlink, generator, feeds)
* Ny: Deaktiver XML-RPC og X-Pingback-header

= 1.0.0 =
* Første versjon: WebP-konvertering og reskalering til maks 1920 px
