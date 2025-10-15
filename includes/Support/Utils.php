<?php
namespace CZGT\Support;

use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class Utils {

    /** CSV -> array di stringhe uniche trim() */
    public static function csv_to_list(?string $csv): array {
        if (!$csv) return [];
        $parts = array_filter(array_map('trim', explode(',', $csv)));
        return array_values(array_unique($parts));
    }

    /** Ritorna bool: stringa è un ID numerico > 0 */
    public static function is_id($v): bool {
        return is_numeric($v) && (int)$v > 0;
    }

    /** Build WP tax_query clause da csv (ID/slug) */
    public static function tax_query_from_csv(string $taxonomy, ?string $csv) {
        $vals = self::csv_to_list($csv);
        if (!$vals) return [];

        $ids = []; $slugs = [];
        foreach ($vals as $v) {
            if (self::is_id($v)) $ids[] = (int)$v;
            else $slugs[] = sanitize_title($v);
        }

        if ($ids && $slugs) {
            return [
                'relation' => 'OR',
                [
                    'taxonomy' => $taxonomy, 'field' => 'term_id',
                    'terms' => $ids, 'include_children' => false, 'operator' => 'IN'
                ],
                [
                    'taxonomy' => $taxonomy, 'field' => 'slug',
                    'terms' => $slugs, 'include_children' => false, 'operator' => 'IN'
                ],
            ];
        }

        $clause = ['taxonomy' => $taxonomy, 'include_children' => false, 'operator' => 'IN'];
        if ($ids)   { $clause['field'] = 'term_id'; $clause['terms'] = $ids; }
        if ($slugs) { $clause['field'] = 'slug';    $clause['terms'] = $slugs; }
        return [$clause];
    }

    /** get_field() se ACF presente, altrimenti get_post_meta() */
    public static function get_meta($post_id, string $key, $default = '') {
        if (function_exists('get_field')) {
            $v = get_field($key, $post_id);
            if ($v !== null && $v !== '') return $v;
        }
        $v = get_post_meta($post_id, $key, true);
        return ($v !== '' && $v !== null) ? $v : $default;
    }

    /** Converte relationship/post_object in array di ID */
    public static function get_meta_ids($post_id, string $key): array {
        if (function_exists('get_field')) {
            $v = get_field($key, $post_id);
            if (is_array($v))         return array_values(array_unique(array_map([__CLASS__,'cast_post_or_id'], $v)));
            if ($v instanceof WP_Post) return [(int)$v->ID];
            if (is_numeric($v))        return [(int)$v];
        }
        $raw = get_post_meta($post_id, $key, true);
        if (is_array($raw)) return array_values(array_unique(array_map([__CLASS__,'cast_post_or_id'], $raw)));
        if (is_numeric($raw)) return [(int)$raw];
        if (is_string($raw) && strpos($raw, ',') !== false) {
            return array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', $raw))))));
        }
        return [];
    }

    public static function cast_post_or_id($v): int {
        if ($v instanceof WP_Post) return (int)$v->ID;
        if (is_numeric($v)) return (int)$v;
        return 0;
    }

    /** ISO date (Y, Y-m, Y-m-d) da year/month/day + precision */
    public static function iso_date($y, $m, $d, $prec) {
        $y = (int)$y; $m = (int)$m; $d = (int)$d;
        if (!$y) return null;
        $pad = fn($n) => str_pad((string)$n, 2, '0', STR_PAD_LEFT);
        if ($prec === 'full' && $m && $d) return sprintf('%04d-%s-%s', $y, $pad($m), $pad($d));
        if ($prec === 'year-month' && $m) return sprintf('%04d-%s', $y, $pad($m));
        return (string)$y;
    }

    /** Term payload semplice (id, slug, name) */
    public static function terms_payload($post_id, string $tax): array {
        $terms = get_the_terms($post_id, $tax);
        if (!$terms || is_wp_error($terms)) return [];
        return array_map(function($t){
            return ['id'=>(int)$t->term_id, 'slug'=>$t->slug, 'name'=>$t->name];
        }, $terms);
    }
}
