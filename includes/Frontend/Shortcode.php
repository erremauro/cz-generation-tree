<?php
namespace CZGT\Frontend;

if (!defined('ABSPATH')) exit;

final class Shortcode {

    const TAG = 'cz_generation_tree';

    public function register(): void {
        add_shortcode(self::TAG, [$this, 'render']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    }

    public function register_assets(): void {
        // 1) Prova con build Vite (manifest)
        $manifest_path = CZ_GT_PATH . 'assets/dist/.vite/manifest.json';
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
            // entry definita in vite.config.js come src/main.jsx
            $entry = $manifest['src/main.jsx'] ?? null;
            if ($entry && !empty($entry['file'])) {
                $js_url  = CZ_GT_URL . 'assets/dist/' . ltrim($entry['file'], '/');
                $deps    = [];
                $version = (string) filemtime($manifest_path);

                // CSS collegati all’entry
                if (!empty($entry['css']) && is_array($entry['css'])) {
                    foreach ($entry['css'] as $css) {
                        $css_url = CZ_GT_URL . 'assets/dist/' . ltrim($css, '/');
                        wp_register_style('cz-gt-app', $css_url, [], $version, 'all');
                    }
                } else {
                    // nessun CSS prodotto — registriamo handle vuoto
                    wp_register_style('cz-gt-app', false, [], $version);
                }

                wp_register_script('cz-gt-app', $js_url, $deps, $version, true);
                return;
            }
        }

        // 2) Fallback: stub (utile finché non hai buildato)
        $js  = CZ_GT_URL . 'assets/js/cz-gt-app.js';
        $css = CZ_GT_URL . 'assets/css/cz-gt-app.css';
        $js_ver  = file_exists(CZ_GT_PATH . 'assets/js/cz-gt-app.js')  ? (string) filemtime(CZ_GT_PATH . 'assets/js/cz-gt-app.js')  : CZ_GT_VERSION;
        $css_ver = file_exists(CZ_GT_PATH . 'assets/css/cz-gt-app.css') ? (string) filemtime(CZ_GT_PATH . 'assets/css/cz-gt-app.css') : CZ_GT_VERSION;
        wp_register_script('cz-gt-app', $js, [], $js_ver, true);
        wp_register_style('cz-gt-app', $css, [], $css_ver, 'all');
    }

    public function render($atts = [], $content = null, $tag = ''): string {
        $atts = shortcode_atts([
            'view'      => 'tree',  // tree | subtree
            'root_id'   => '',
            'max_depth' => '0',
            'height'    => '70vh',
            'class'     => '',
        ], $atts, $tag ?: self::TAG);

        // enqueue asset (manifest o stub)
        wp_enqueue_script('cz-gt-app');
        wp_enqueue_style('cz-gt-app');

        // bootstrap
        $el_id = 'cz-gt-root-' . wp_unique_id();
        $context = [
            'restBase'  => esc_url_raw(rest_url('cz-gt/v1/')),
            'restNonce' => wp_create_nonce('wp_rest'),
            'siteUrl'   => home_url('/'),
            'i18n'      => [
                'loading' => __('Caricamento…', 'cz-generation-tree'),
                'empty'   => __('Nessun dato disponibile', 'cz-generation-tree'),
                'error'   => __('Si è verificato un errore', 'cz-generation-tree'),
            ],
        ];
        $props = [
            'view'      => in_array(strtolower($atts['view']), ['tree','subtree'], true) ? strtolower($atts['view']) : 'tree',
            'root_id'   => (int) $atts['root_id'],
            'max_depth' => (int) $atts['max_depth'],
            'ui'        => [
                'height' => preg_match('~^\d+(px|vh|vw|%)$~', $atts['height']) ? $atts['height'] : '70vh',
            ],
        ];

        $inline = 'window.CZ_GT_BOOTSTRAP = window.CZ_GT_BOOTSTRAP || [];'
                . 'window.CZ_GT_BOOTSTRAP.push(' . wp_json_encode([
                        'elId'    => $el_id,
                        'context' => $context,
                        'props'   => $props,
                    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . ');';
        wp_add_inline_script('cz-gt-app', $inline, 'before');

        $class = 'cz-gt-root' . ($atts['class'] ? ' ' . sanitize_html_class($atts['class']) : '');

        return sprintf(
            '<div id="%s" class="%s" style="min-height:%s"><div class="cz-gt-placeholder">%s</div><noscript>%s</noscript></div>',
            esc_attr($el_id),
            esc_attr($class),
            esc_attr($props['ui']['height']),
            esc_html($context['i18n']['loading']),
            esc_html__('Attiva JavaScript per vedere il contenuto.', 'cz-generation-tree')
        );
    }
}
