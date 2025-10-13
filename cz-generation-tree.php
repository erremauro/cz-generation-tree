<?php
/**
 * Plugin Name: CZ Generation Tree
 * Plugin URI:  https://example.com
 * Description: REST API /wp-json/cz-gt/v1/tree per l'albero di trasmissione (CPT: maestro).
 * Version:     1.0.0
 * Author:      Cigno Zen
 * License:     GPL-2.0+
 * Text Domain: cz-generation-tree
 */

if (!defined('ABSPATH')) exit;

/**
 * ROUTE: /wp-json/cz-gt/v1/tree
 * Filtri opzionali:
 *  - school (slug o ID; multipli separati da virgole)
 *  - generazione (slug o ID; multipli separati da virgole)
 *  - search (string)
 *  - limit (int, default 500), offset (int, default 0)
 *  - order (asc|desc, default asc)
 *  - include / exclude (ID separati da virgola)
 *  - fields (nodes|edges|all)
 */
add_action('rest_api_init', function () {
  register_rest_route('cz-gt/v1', '/tree', [
    'methods'  => WP_REST_Server::READABLE,
    'callback' => 'cz_gt_api_tree',
    'args'     => [
      'school'      => ['type'=>'string','required'=>false],
      'generazione' => ['type'=>'string','required'=>false],
      'search'      => ['type'=>'string','required'=>false],
      'limit'       => ['type'=>'integer','required'=>false,'default'=>500],
      'offset'      => ['type'=>'integer','required'=>false,'default'=>0],
      'order'       => ['type'=>'string','required'=>false,'default'=>'asc'],
      'include'     => ['type'=>'string','required'=>false],
      'exclude'     => ['type'=>'string','required'=>false],
      'fields'      => ['type'=>'string','required'=>false], // nodes|edges|all
    ],
    'permission_callback' => '__return_true',
  ]);
});

/** ----------------------------- Utilities ------------------------------ */

function cz_gt_sanitize_csv_ids_or_slugs(?string $csv): array {
  if (!$csv) return [];
  $parts = array_filter(array_map('trim', explode(',', $csv)));
  return array_values(array_unique($parts));
}

function cz_gt_is_id($v): bool {
  return is_numeric($v) && (int)$v > 0;
}

function cz_gt_tax_query_from_csv(string $taxonomy, ?string $csv) {
  $vals = cz_gt_sanitize_csv_ids_or_slugs($csv);
  if (!$vals) return [];
  $ids   = [];
  $slugs = [];
  foreach ($vals as $v) {
    if (cz_gt_is_id($v)) $ids[] = (int)$v;
    else $slugs[] = sanitize_title($v);
  }
  if ($ids && $slugs) {
    return [
      'relation' => 'OR',
      [
        'taxonomy'=>$taxonomy, 'field'=>'term_id', 'terms'=>$ids, 'include_children'=>false, 'operator'=>'IN'
      ],
      [
        'taxonomy'=>$taxonomy, 'field'=>'slug', 'terms'=>$slugs, 'include_children'=>false, 'operator'=>'IN'
      ]
    ];
  }
  $clause = ['taxonomy'=>$taxonomy, 'include_children'=>false, 'operator'=>'IN'];
  if ($ids)   { $clause['field']='term_id'; $clause['terms']=$ids; }
  if ($slugs) { $clause['field']='slug';    $clause['terms']=$slugs; }
  return [$clause];
}

function cz_gt_get_meta($post_id, $key, $default = '') {
  if (function_exists('get_field')) {
    $v = get_field($key, $post_id);
    if ($v !== null && $v !== '') return $v;
  }
  $v = get_post_meta($post_id, $key, true);
  return ($v !== '' && $v !== null) ? $v : $default;
}

/** Ritorna array di interi da relationship/post_object, robusto con o senza ACF. */
function cz_gt_get_meta_ids($post_id, $key): array {
  if (function_exists('get_field')) {
    $v = get_field($key, $post_id);
    if (is_array($v))        return array_values(array_unique(array_map('cz_gt_cast_post_or_id', $v)));
    if ($v instanceof WP_Post) return [(int)$v->ID];
    if (is_numeric($v))        return [(int)$v];
  }
  $raw = get_post_meta($post_id, $key, true); // può essere serializzato
  if (is_array($raw)) return array_values(array_unique(array_map('cz_gt_cast_post_or_id', $raw)));
  if (is_numeric($raw)) return [(int)$raw];
  if (is_string($raw) && strpos($raw, ',') !== false) {
    return array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', $raw))))));
  }
  return [];
}
function cz_gt_cast_post_or_id($v): int {
  if ($v instanceof WP_Post) return (int)$v->ID;
  if (is_numeric($v)) return (int)$v;
  return 0;
}

/** ISO date parziale da year/month/day + precision. */
function cz_gt_iso_date($y, $m, $d, $prec) {
  $y = (int)$y; $m = (int)$m; $d = (int)$d;
  if (!$y) return null;
  $pad = function($n){ return str_pad((string)$n, 2, '0', STR_PAD_LEFT); };
  if ($prec === 'full' && $m && $d) return sprintf('%04d-%s-%s', $y, $pad($m), $pad($d));
  if ($prec === 'year-month' && $m) return sprintf('%04d-%s', $y, $pad($m));
  return (string)$y;
}

/** Term payload semplice (id, slug, name). */
function cz_gt_terms_payload($post_id, $tax): array {
  $terms = get_the_terms($post_id, $tax);
  if (!$terms || is_wp_error($terms)) return [];
  return array_map(function($t){
    return ['id'=>(int)$t->term_id, 'slug'=>$t->slug, 'name'=>$t->name];
  }, $terms);
}

/** ------------------------------ Callback ------------------------------ */

function cz_gt_api_tree(WP_REST_Request $req) {
  $params = [
    'school'      => (string)$req->get_param('school'),
    'generazione' => (string)$req->get_param('generazione'),
    'search'      => (string)$req->get_param('search'),
    'limit'       => max(1, (int)$req->get_param('limit')),
    'offset'      => max(0, (int)$req->get_param('offset')),
    'order'       => strtolower($req->get_param('order') ?: 'asc'),
    'include'     => (string)$req->get_param('include'),
    'exclude'     => (string)$req->get_param('exclude'),
    'fields'      => strtolower($req->get_param('fields') ?: 'all'),
  ];

  $cache_key = 'cz_gt_tree_' . md5(json_encode($params));
  $cached = get_transient($cache_key);
  if ($cached) {
    return new WP_REST_Response($cached, 200, ['X-CZ-Cache'=>'HIT']);
  }

  // Tax filters
  $tax_query = ['relation'=>'AND'];
  $tax_school = cz_gt_tax_query_from_csv('school', $params['school']);
  if ($tax_school) $tax_query[] = $tax_school;

  $tax_gen = cz_gt_tax_query_from_csv('generazione', $params['generazione']);
  if ($tax_gen) $tax_query[] = $tax_gen;

  if (count($tax_query) === 1) $tax_query = []; // nessun filtro realmente attivo

  // Include/Exclude
  $include_ids = array_filter(array_map('intval', cz_gt_sanitize_csv_ids_or_slugs($params['include'])));
  $exclude_ids = array_filter(array_map('intval', cz_gt_sanitize_csv_ids_or_slugs($params['exclude'])));

  $order = ($params['order'] === 'desc') ? 'DESC' : 'ASC';

  $q = new WP_Query([
    'post_type'           => 'maestro',
    'post_status'         => 'publish',
    'posts_per_page'      => $params['limit'],
    'offset'              => $params['offset'],
    'no_found_rows'       => true,
    'ignore_sticky_posts' => true,
    'orderby'             => 'title', // fallback; ordinamento storico meglio farlo client side
    'order'               => $order,
    's'                   => $params['search'] ?: '',
    'tax_query'           => $tax_query ?: [],
    'post__in'            => $include_ids ?: null,
    'post__not_in'        => $exclude_ids ?: null,
  ]);

  $nodes = [];
  $edges = [];

  if ($q->have_posts()) {
    foreach ($q->posts as $p) {
      $pid = (int)$p->ID;

      // Metadati principali
      $uuid        = cz_gt_get_meta($pid, 'cz_uuid', '');
      $name_latin  = cz_gt_get_meta($pid, 'name_latin', get_the_title($pid));
      $name_romaji = cz_gt_get_meta($pid, 'name_romaji', '');
      $name_hanzi  = cz_gt_get_meta($pid, 'name_hanzi', '');
      $honorific   = cz_gt_get_meta($pid, 'honorific_name', '');

      // Immagine
      $portrait_id = (int) cz_gt_get_meta($pid, 'portrait', 0);
      $picture     = $portrait_id ? wp_get_attachment_image_url($portrait_id, 'medium') : '';

      // Date
      $by = (int) cz_gt_get_meta($pid, 'birth_year', 0);
      $bm = (int) cz_gt_get_meta($pid, 'birth_month', 0);
      $bd = (int) cz_gt_get_meta($pid, 'birth_day', 0);
      $bp = (string) cz_gt_get_meta($pid, 'birth_precision', 'year');

      $dy = (int) cz_gt_get_meta($pid, 'death_year', 0);
      $dm = (int) cz_gt_get_meta($pid, 'death_month', 0);
      $dd = (int) cz_gt_get_meta($pid, 'death_day', 0);
      $dp = (string) cz_gt_get_meta($pid, 'death_precision', 'year');

      $birth_iso = cz_gt_iso_date($by, $bm, $bd, $bp);
      $death_iso = cz_gt_iso_date($dy, $dm, $dd, $dp);

      // Luoghi
      $b_place = [
        'name' => cz_gt_get_meta($pid, 'birth_place_name', ''),
        'lat'  => (float) cz_gt_get_meta($pid, 'birth_place_lat', ''),
        'lng'  => (float) cz_gt_get_meta($pid, 'birth_place_lng', ''),
      ];
      if (!$b_place['name'] && !$b_place['lat'] && !$b_place['lng']) $b_place = null;

      $d_place = [
        'name' => cz_gt_get_meta($pid, 'death_place_name', ''),
        'lat'  => (float) cz_gt_get_meta($pid, 'death_place_lat', ''),
        'lng'  => (float) cz_gt_get_meta($pid, 'death_place_lng', ''),
      ];
      if (!$d_place['name'] && !$d_place['lat'] && !$d_place['lng']) $d_place = null;

      // Tassonomie
      $schools      = cz_gt_terms_payload($pid, 'school');
      $generations  = cz_gt_terms_payload($pid, 'generazione');

      // Relazioni
      $teachers_ids = cz_gt_get_meta_ids($pid, 'teachers');
      $primary_id   = current(cz_gt_get_meta_ids($pid, 'primary_teacher')) ?: 0;
      $heir_id      = current(cz_gt_get_meta_ids($pid, 'is_dharma_heir_of'))   ?: 0;

      // Etichetta nodo (romaji || latin)
      $label = $name_romaji ? $name_romaji : $name_latin;

      $nodes[$pid] = array_filter([
        'id'          => $pid,
        'uuid'        => $uuid ?: null,
        'url'         => get_permalink($pid),
        'name'        => $name_latin,
        'label'       => $label,
        'romaji'      => $name_romaji ?: null,
        'hanzi'       => $name_hanzi ?: null,
        'honorific'   => $honorific ?: null,
        'picture'     => $picture ?: null,
        'birth_date'  => $birth_iso ?: null,
        'death_date'  => $death_iso ?: null,
        'birth_place' => $b_place,
        'death_place' => $d_place,
        'school'      => $schools ?: null,
        'generazione' => $generations ?: null,
        'teachers'    => $teachers_ids ?: null,
        'primary_teacher'    => $primary_id ?: null,
        'is_dharma_heir_of'  => $heir_id ?: null,
      ], function($v){ return $v !== null && $v !== '' && $v !== []; });

      // Archi
      if ($primary_id) $edges[] = ['type'=>'primary', 'from'=>(int)$primary_id, 'to'=>$pid];
      if ($heir_id)    $edges[] = ['type'=>'heir',    'from'=>(int)$heir_id,    'to'=>$pid];
    }
  }

  // Payload
  $payload = [
    'count' => count($nodes),
    'nodes' => array_values($nodes),
    'edges' => $edges,
    'meta'  => [
      'limit'  => (int)$params['limit'],
      'offset' => (int)$params['offset'],
      'order'  => $params['order'],
      'filters'=> array_filter([
        'school'      => $params['school'] ?: null,
        'generazione' => $params['generazione'] ?: null,
        'search'      => $params['search'] ?: null,
        'include'     => $params['include'] ?: null,
        'exclude'     => $params['exclude'] ?: null,
      ])
    ]
  ];

  // fields=nodes|edges|all
  if ($params['fields'] === 'nodes') {
    $payload = ['count'=>count($nodes), 'nodes'=>array_values($nodes)];
  } elseif ($params['fields'] === 'edges') {
    $payload = ['edges'=>$edges];
  }

  set_transient($cache_key, $payload, MINUTE_IN_SECONDS * 10);
  return new WP_REST_Response($payload, 200, ['X-CZ-Cache'=>'MISS']);
}
