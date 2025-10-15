<?php
namespace CZGT;

if (!defined('ABSPATH')) exit;

final class Autoloader {
    public static function register(): void {
        spl_autoload_register([__CLASS__, 'autoload']);
    }

    public static function autoload(string $class): void {
        // Namespace atteso: CZGT\...
        if (strpos($class, __NAMESPACE__ . '\\') !== 0) return;

        $rel = str_replace(__NAMESPACE__ . '\\', '', $class);
        $rel = str_replace('\\', DIRECTORY_SEPARATOR, $rel);
        $file = CZ_GT_PATH . 'includes/' . $rel . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
}
