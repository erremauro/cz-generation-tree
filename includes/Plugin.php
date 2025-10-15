<?php
namespace CZGT;

use CZGT\Api\Tree_Controller;
use CZGT\Frontend\Shortcode;

if (!defined('ABSPATH')) exit;

final class Plugin {
    public function init(): void {
        // REST API
        add_action('rest_api_init', function () {
            if (class_exists(Tree_Controller::class)) {
                (new Tree_Controller())->register_routes();
            }
        });

        // Shortcode
        add_action('init', function () {
            if (class_exists(Shortcode::class)) {
                (new Shortcode())->register();
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[CZGT] Shortcode class non trovata');
                }
            }
        });
    }
}
