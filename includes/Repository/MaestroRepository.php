<?php
namespace CZGT\Repository;

use CZGT\Support\Utils;
use WP_Query;

if (!defined('ABSPATH')) exit;

final class MaestroRepository {

    /** Query dei maestri secondo i filtri */
    public function query(array $args): WP_Query {
        $tax_query = ['relation' => 'AND'];

        if (!empty($args['school'])) {
            $tq = Utils::tax_query_from_csv('school', $args['school']);
            if ($tq) $tax_query[] = $tq;
        }
        if (!empty($args['generazione'])) {
            $tq = Utils::tax_query_from_csv('generazione', $args['generazione']);
            if ($tq) $tax_query[] = $tq;
        }
        if (count($tax_query) === 1) $tax_query = [];

        $include_ids = $this->csv_to_ids($args['include'] ?? '');
        $exclude_ids = $this->csv_to_ids($args['exclude'] ?? '');

        $order = (strtolower($args['order'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

        return new WP_Query([
            'post_type'           => 'maestro',
            'post_status'         => 'publish',
            'posts_per_page'      => (int)($args['limit'] ?? 500),
            'offset'              => (int)($args['offset'] ?? 0),
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'orderby'             => 'title',
            'order'               => $order,
            's'                   => (string)($args['search'] ?? ''),
            'tax_query'           => $tax_query,
            'post__in'            => $include_ids ?: null,
            'post__not_in'        => $exclude_ids ?: null,
            'fields'              => 'ids',
        ]);
    }

    /** Costruisce i nodi+archi in base ai post trovati */
    public function build_graph(\WP_Query $q): array {
        $nodes = [];
        $edges = [];

        foreach ($q->posts as $pid) {
            $pid = (int)$pid;

            // Metadati
            $uuid        = Utils::get_meta($pid, 'cz_uuid', '');
            $name_latin  = Utils::get_meta($pid, 'name_latin', get_the_title($pid));
            $name_romaji = Utils::get_meta($pid, 'name_romaji', '');
            $name_hanzi  = Utils::get_meta($pid, 'name_hanzi', '');
            $honorific   = Utils::get_meta($pid, 'honorific_name', '');

            $portrait_id = (int) Utils::get_meta($pid, 'portrait', 0);
            $picture     = $portrait_id ? wp_get_attachment_image_url($portrait_id, 'medium') : '';

            // Date
            $by = (int) Utils::get_meta($pid, 'birth_year', 0);
            $bm = (int) Utils::get_meta($pid, 'birth_month', 0);
            $bd = (int) Utils::get_meta($pid, 'birth_day', 0);
            $bp = (string) Utils::get_meta($pid, 'birth_precision', 'year');

            $dy = (int) Utils::get_meta($pid, 'death_year', 0);
            $dm = (int) Utils::get_meta($pid, 'death_month', 0);
            $dd = (int) Utils::get_meta($pid, 'death_day', 0);
            $dp = (string) Utils::get_meta($pid, 'death_precision', 'year');

            $birth_iso = Utils::iso_date($by, $bm, $bd, $bp);
            $death_iso = Utils::iso_date($dy, $dm, $dd, $dp);

            // Luoghi
            $b_place = [
                'name' => (string) Utils::get_meta($pid, 'birth_place_name', ''),
                'lat'  => (float)  Utils::get_meta($pid, 'birth_place_lat', ''),
                'lng'  => (float)  Utils::get_meta($pid, 'birth_place_lng', ''),
            ];
            if (!$b_place['name'] && !$b_place['lat'] && !$b_place['lng']) $b_place = null;

            $d_place = [
                'name' => (string) Utils::get_meta($pid, 'death_place_name', ''),
                'lat'  => (float)  Utils::get_meta($pid, 'death_place_lat', ''),
                'lng'  => (float)  Utils::get_meta($pid, 'death_place_lng', ''),
            ];
            if (!$d_place['name'] && !$d_place['lat'] && !$d_place['lng']) $d_place = null;

            // Tassonomie
            $schools     = Utils::terms_payload($pid, 'school');
            $generations = Utils::terms_payload($pid, 'generazione');

            // Relazioni
            $teachers_ids = Utils::get_meta_ids($pid, 'teachers');
            $primary_id   = current(Utils::get_meta_ids($pid, 'primary_teacher')) ?: 0;
            $heir_id      = current(Utils::get_meta_ids($pid, 'is_dharma_heir_of')) ?: 0;

            $label       = $name_romaji ?: $name_latin;
            $parent_name = $heir_id ? get_the_title((int)$heir_id) : '';

            $nodes[$pid] = array_filter([
                'id'                 => $pid,
                'uuid'               => $uuid ?: null,
                'url'                => get_permalink($pid),
                'name'               => $name_latin,
                'label'              => $label,
                'romaji'             => $name_romaji ?: null,
                'hanzi'              => $name_hanzi ?: null,
                'honorific'          => $honorific ?: null,
                'picture'            => $picture ?: null,
                'birth_date'         => $birth_iso ?: null,
                'death_date'         => $death_iso ?: null,
                'birth_precision'    => $bp ?: null,
                'death_precision'    => $dp ?: null,
                'birth_place'        => $b_place,
                'death_place'        => $d_place,
                'school'             => $schools ?: null,
                'generazione'        => $generations ?: null,
                'teachers'           => $teachers_ids ?: null,
                'primary_teacher'    => $primary_id ?: null,
                'is_dharma_heir_of'  => $heir_id ?: null,
                'parent_name'        => $parent_name ?: null,
            ], fn($v) => $v !== null && $v !== '' && $v !== []);

            if ($primary_id) $edges[] = ['type' => 'primary', 'from' => (int)$primary_id, 'to' => $pid];
            if ($heir_id)    $edges[] = ['type' => 'heir',    'from' => (int)$heir_id,    'to' => $pid];
        }

        return [$nodes, $edges];
    }

    /** Individua radici considerando SOLO archi 'heir' */
    public function compute_roots(array $nodes, array $edges): array {
        $indegree = [];
        foreach ($nodes as $nid => $_) {
            $indegree[(int)$nid] = 0;
        }
        foreach ($edges as $e) {
            if (($e['type'] ?? '') !== 'heir') continue;
            $to = (int)$e['to'];
            if (isset($indegree[$to])) $indegree[$to]++;
        }
        $roots = [];
        foreach ($indegree as $nid => $deg) {
            if ($deg === 0) $roots[] = $nid;
        }
        return $roots;
    }

    /** Calcola generation_index via BFS da $root_id (solo archi heir) */
    public function compute_generations(array $nodes, array $edges, int $root_id, int $max_depth = 0): array {
        // adjacency heir
        $children = [];
        foreach ($nodes as $nid => $_) $children[(int)$nid] = [];
        foreach ($edges as $e) {
            if (($e['type'] ?? '') !== 'heir') continue;
            $children[(int)$e['from']][] = (int)$e['to'];
        }

        $gen = [];
        if (!isset($nodes[$root_id])) return $gen;

        $queue = [ $root_id ];
        $gen[$root_id] = 1;
        $visited = [ $root_id => true ];

        while ($queue) {
            $curr = array_shift($queue);
            $g = $gen[$curr];

            // rispetto max_depth (0 = no limit)
            if ($max_depth > 0 && $g >= $max_depth) continue;

            foreach ($children[$curr] ?? [] as $to) {
                if (!isset($visited[$to])) {
                    $visited[$to] = true;
                    $gen[$to] = $g + 1;
                    $queue[] = $to;
                }
            }
        }
        return $gen;
    }

    /** Converte CSV in array di ID interi */
    private function csv_to_ids(?string $csv): array {
        $list = Utils::csv_to_list($csv);
        $ids = [];
        foreach ($list as $v) if (Utils::is_id($v)) $ids[] = (int)$v;
        return $ids;
    }

    /**
     * Idrata un singolo maestro + relativi edge principali (primary/heir).
     * Ritorna [node, edges[]]. Utile per includere antenati fuori filtro.
     */
    public function hydrate_node(int $pid): array {
        $pid = (int)$pid;
        if ($pid <= 0) return [null, []];

        // Metadati (stessa logica di build_graph, ma per un singolo ID)
        $uuid        = Utils::get_meta($pid, 'cz_uuid', '');
        $name_latin  = Utils::get_meta($pid, 'name_latin', get_the_title($pid));
        $name_romaji = Utils::get_meta($pid, 'name_romaji', '');
        $name_hanzi  = Utils::get_meta($pid, 'name_hanzi', '');
        $honorific   = Utils::get_meta($pid, 'honorific_name', '');

        $portrait_id = (int) Utils::get_meta($pid, 'portrait', 0);
        $picture     = $portrait_id ? wp_get_attachment_image_url($portrait_id, 'medium') : '';

        $by = (int) Utils::get_meta($pid, 'birth_year', 0);
        $bm = (int) Utils::get_meta($pid, 'birth_month', 0);
        $bd = (int) Utils::get_meta($pid, 'birth_day', 0);
        $bp = (string) Utils::get_meta($pid, 'birth_precision', 'year');

        $dy = (int) Utils::get_meta($pid, 'death_year', 0);
        $dm = (int) Utils::get_meta($pid, 'death_month', 0);
        $dd = (int) Utils::get_meta($pid, 'death_day', 0);
        $dp = (string) Utils::get_meta($pid, 'death_precision', 'year');

        $birth_iso = Utils::iso_date($by, $bm, $bd, $bp);
        $death_iso = Utils::iso_date($dy, $dm, $dd, $dp);

        $b_place = [
            'name' => (string) Utils::get_meta($pid, 'birth_place_name', ''),
            'lat'  => (float)  Utils::get_meta($pid, 'birth_place_lat', ''),
            'lng'  => (float)  Utils::get_meta($pid, 'birth_place_lng', ''),
        ];
        if (!$b_place['name'] && !$b_place['lat'] && !$b_place['lng']) $b_place = null;

        $d_place = [
            'name' => (string) Utils::get_meta($pid, 'death_place_name', ''),
            'lat'  => (float)  Utils::get_meta($pid, 'death_place_lat', ''),
            'lng'  => (float)  Utils::get_meta($pid, 'death_place_lng', ''),
        ];
        if (!$d_place['name'] && !$d_place['lat'] && !$d_place['lng']) $d_place = null;

        $schools     = Utils::terms_payload($pid, 'school');
        $generations = Utils::terms_payload($pid, 'generazione');

        $teachers_ids = Utils::get_meta_ids($pid, 'teachers');
        $primary_id   = current(Utils::get_meta_ids($pid, 'primary_teacher')) ?: 0;
        $heir_id      = current(Utils::get_meta_ids($pid, 'is_dharma_heir_of')) ?: 0;

        $label = $name_romaji ?: $name_latin;

        $node = array_filter([
            'id'                 => $pid,
            'uuid'               => $uuid ?: null,
            'url'                => get_permalink($pid),
            'name'               => $name_latin,
            'label'              => $label,
            'romaji'             => $name_romaji ?: null,
            'hanzi'              => $name_hanzi ?: null,
            'honorific'          => $honorific ?: null,
            'picture'            => $picture ?: null,
            'birth_date'         => $birth_iso ?: null,
            'death_date'         => $death_iso ?: null,
            'birth_precision'    => $bp ?: null,
            'death_precision'    => $dp ?: null,
            'birth_place'        => $b_place,
            'death_place'        => $d_place,
            'school'             => $schools ?: null,
            'generazione'        => $generations ?: null,
            'teachers'           => $teachers_ids ?: null,
            'primary_teacher'    => $primary_id ?: null,
            'is_dharma_heir_of'  => $heir_id ?: null,
        ], fn($v) => $v !== null && $v !== '' && $v !== []);

        $edges = [];
        if ($primary_id) $edges[] = ['type' => 'primary', 'from' => (int)$primary_id, 'to' => $pid];
        if ($heir_id)    $edges[] = ['type' => 'heir',    'from' => (int)$heir_id,    'to' => $pid];

        return [$node, $edges];
    }
}
