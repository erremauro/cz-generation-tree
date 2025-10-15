<?php
/**
 * Plugin Name: CZ Generation Tree
 * Plugin URI:  https://cignozen.example
 * Description: REST API per l'albero di trasmissione (CPT: maestro): /wp-json/cz-gt/v1/*
 * Version:     1.0.0
 * Author:      Cigno Zen
 * License:     GPL-2.0+
 * Text Domain: cz-generation-tree
 */

if (!defined('ABSPATH')) exit;

define('CZ_GT_VERSION', '1.0.0');
define('CZ_GT_FILE', __FILE__);
define('CZ_GT_PATH', plugin_dir_path(__FILE__));
define('CZ_GT_URL',  plugin_dir_url(__FILE__));

/** Autoloader semplice stile PSR-4 (namespace CZGT\* -> /includes/*) */
require_once CZ_GT_PATH . 'includes/Autoloader.php';
CZGT\Autoloader::register();

/** Avvio plugin */
add_action('plugins_loaded', function () {
    (new CZGT\Plugin())->init();
});

/** Flush transients utili quando si svuota la cache (opzionale) */
register_deactivation_hook(__FILE__, function () {
    // no-op per ora
});
