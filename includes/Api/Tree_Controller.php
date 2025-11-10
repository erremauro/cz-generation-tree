<?php
namespace CZGT\Api;

use CZGT\Repository\MaestroRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) exit;

final class Tree_Controller {

    public function register_routes(): void {
        register_rest_route('cz-gt/v1', '/tree', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'tree'],
            'args'     => $this->tree_args(),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('cz-gt/v1', '/roots', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'roots'],
            'args'     => $this->common_args(),
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('cz-gt/v1', '/subtree', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'subtree'],
            'args'     => array_merge($this->common_args(), [
                'root_id'       => ['type'=>'integer','required'=>false],
                'max_depth'     => ['type'=>'integer','required'=>false,'default'=>0],
                // 🆕
                'from_node'     => ['type'=>'integer','required'=>false],
                'root_strategy' => ['type'=>'string','required'=>false,'default'=>'auto','enum'=>['auto','primary','meta','first']],
                'include_self'  => ['type'=>'boolean','required'=>false,'default'=>false],
            ]),
            'permission_callback' => '__return_true',
        ]);


        // 🆕 /terms — lista termini per i dropdown di Scuola/Generazione
        register_rest_route('cz-gt/v1', '/terms', [
            'methods'  => WP_REST_Server::READABLE,
            'callback' => [$this, 'terms'],
            'args'     => [
                'taxonomy'   => ['type'=>'string','required'=>true, 'enum' => ['school','generazione']],
                'search'     => ['type'=>'string','required'=>false],
                'hide_empty' => ['type'=>'boolean','required'=>false,'default'=>false],
                'orderby'    => ['type'=>'string','required'=>false,'default'=>'name'],
                'order'      => ['type'=>'string','required'=>false,'default'=>'ASC'],
                'number'     => ['type'=>'integer','required'=>false,'default'=>0], // 0 = tutti
                'format'     => ['type'=>'string','required'=>false,'default'=>'flat', 'enum'=>['flat','tree']],
            ],
            'permission_callback' => '__return_true',
        ]);

    }

    /* -------------------------- args -------------------------- */

    private function common_args(): array {
        return [
            'school'      => ['type'=>'string','required'=>false],
            'generazione' => ['type'=>'string','required'=>false],
            'search'      => ['type'=>'string','required'=>false],
            'limit'       => ['type'=>'integer','required'=>false,'default'=>500],
            'offset'      => ['type'=>'integer','required'=>false,'default'=>0],
            'order'       => ['type'=>'string','required'=>false,'default'=>'asc'], // asc|desc
            'include'     => ['type'=>'string','required'=>false],
            'exclude'     => ['type'=>'string','required'=>false],
        ];
    }

    private function tree_args(): array {
        return array_merge($this->common_args(), [
            'fields'      => ['type'=>'string','required'=>false,'default'=>'all'], // nodes|edges|all
            'root_id'     => ['type'=>'integer','required'=>false],
        ]);
    }

    /* -------------------------- /tree -------------------------- */

    public function tree(WP_REST_Request $req): WP_REST_Response {
        $params = $this->collect_params($req);

        $cache_key = 'cz_gt_tree_' . md5(json_encode($params));
        if ($cached = get_transient($cache_key)) {
            return new WP_REST_Response($cached, 200, ['X-CZ-Cache' => 'HIT']);
        }

        $repo = new MaestroRepository();
        $q = $repo->query($params);
        [$nodes, $edges] = $repo->build_graph($q);

        // roots e root scelto
        $roots = $repo->compute_roots($nodes, $edges);
        $root_id = (int)($params['root_id'] ?? 0);
        if (!$root_id && $roots) {
            $root_id = $this->pick_root($nodes, $roots);
        }

        $payload = [
            'count' => count($nodes),
            'nodes' => array_values($nodes),
            'edges' => array_values($edges),
            'meta'  => [
                'limit'  => (int)$params['limit'],
                'offset' => (int)$params['offset'],
                'order'  => $params['order'],
                'filters'=> array_filter([
                    'school'      => $params['school'] ?? null,
                    'generazione' => $params['generazione'] ?? null,
                    'search'      => $params['search'] ?? null,
                    'include'     => $params['include'] ?? null,
                    'exclude'     => $params['exclude'] ?? null,
                ]),
                'roots'   => array_values($roots),
                'root_id' => $root_id ?: null,
            ]
        ];

        // arricchisci con generation_index se ho root_id
        if ($root_id) {
            $gen = $repo->compute_generations($nodes, $edges, $root_id);
            if ($gen) {
                foreach ($payload['nodes'] as &$n) {
                    $nid = (int)$n['id'];
                    if (isset($gen[$nid])) $n['generation_index'] = $gen[$nid];
                }
                unset($n);
            }
        }

        // fields
        $fields = $params['fields'] ?? 'all';
        if ($fields === 'nodes') {
            $payload = ['count'=>count($payload['nodes']), 'nodes'=>$payload['nodes'], 'meta'=>$payload['meta']];
        } elseif ($fields === 'edges') {
            $payload = ['edges'=>$payload['edges'], 'meta'=>$payload['meta']];
        }

        set_transient($cache_key, $payload, 10 * MINUTE_IN_SECONDS);
        return new WP_REST_Response($payload, 200, ['X-CZ-Cache' => 'MISS']);
    }

    /* -------------------------- /roots -------------------------- */

    public function roots(\WP_REST_Request $req): \WP_REST_Response {
        $params = $this->collect_params($req);
        $cache_key = 'cz_gt_roots_' . md5(json_encode($params));
        if ($cached = get_transient($cache_key)) {
            return new \WP_REST_Response($cached, 200, ['X-CZ-Cache' => 'HIT']);
        }

        $repo = new MaestroRepository();
        $q = $repo->query($params);
        [$nodes, $edges] = $repo->build_graph($q);

        // 1) standard
        $roots = array_values($repo->compute_roots($nodes, $edges));

        // 2) fallback robusto: nodi senza parent
        if (empty($roots) && !empty($nodes)) {
            $has_parent = [];
            foreach ($nodes as $id => $n) {
                $pid = (int)($n['is_dharma_heir_of'] ?? 0);
                if ($pid) $has_parent[(int)$id] = true;
            }
            foreach ($edges as $e) {
                if (($e['type'] ?? '') === 'heir') {
                    $to = (int)($e['to'] ?? 0);
                    if ($to) $has_parent[$to] = true;
                }
            }
            $roots = [];
            foreach ($nodes as $id => $n) {
                if (!isset($has_parent[(int)$id])) $roots[] = (int)$id;
            }
        }

        if (empty($roots)) {
            $payload = ['roots' => []];
            set_transient($cache_key, $payload, 5 * MINUTE_IN_SECONDS);
            return new \WP_REST_Response($payload, 200, ['X-CZ-Cache' => 'MISS']);
        }

        // Ordina: Bodhidharma, poi anno, poi nome
        usort($roots, function($a, $b) use ($nodes){
            $na=$nodes[$a]??[]; $nb=$nodes[$b]??[];
            $an = strtolower($na['name'] ?? '');
            $bn = strtolower($nb['name'] ?? '');
            $ab = (int)substr(($na['birth_date']??''),0,4);
            $bb = (int)substr(($nb['birth_date']??''),0,4);

            $a_is_bodhi = strpos($an,'bodhidharma') !== false;
            $b_is_bodhi = strpos($bn,'bodhidharma') !== false;
            if ($a_is_bodhi && !$b_is_bodhi) return -1;
            if ($b_is_bodhi && !$a_is_bodhi) return 1;

            if ($ab && $bb && $ab !== $bb) return $ab <=> $bb;
            return strcasecmp($na['name']??'', $nb['name']??'');
        });

        $items = array_map(function($id) use ($nodes){
            $n   = $nodes[$id] ?? [];
            return [
                'id'   => (int)$id,
                'name' => (string)($n['name'] ?? get_the_title((int)$id) ?: ('#'.$id)),
                'url'  => get_permalink((int)$id) ?: '',
            ];
        }, $roots);

        $payload = ['roots' => $items];
        set_transient($cache_key, $payload, 10 * MINUTE_IN_SECONDS);
        return new \WP_REST_Response($payload, 200, ['X-CZ-Cache' => 'MISS']);
    }

    /* -------------------------- /subtree -------------------------- */

    public function subtree(WP_REST_Request $req): WP_REST_Response {
        $params        = $this->collect_params($req);
        $root_id       = (int)$req->get_param('root_id');
        $max_depth     = max(0, (int)$req->get_param('max_depth'));
        $from_node     = (int)$req->get_param('from_node'); 
        $root_strategy = (string)($req->get_param('root_strategy') ?: 'auto'); 
        $include_self  = (bool)$req->get_param('include_self'); 

        $repo = new MaestroRepository();
        $q = $repo->query($params);
        [$nodes, $edges] = $repo->build_graph($q);

        // === NEW: Ancestor hydration per garantire la connettività se il parent è fuori dai filtri ===
        if ($from_node) {
            // 1) Assicurati che from_node sia presente
            if (!isset($nodes[$from_node])) {
                [$n, $es] = $repo->hydrate_node($from_node);
                if ($n) { $nodes[$from_node] = $n; $edges = array_merge($edges, $es); }
            }
            // 2) Risali gli antenati finché non trovi un nodo già incluso o arrivi alla radice
            $cursor = $from_node;
            $guard  = 0;
            while ($guard++ < 1000) { // hard guard contro loop
                $parent = (int)($nodes[$cursor]['is_dharma_heir_of'] ?? 0);
                if ($parent <= 0) break;
                if (!isset($nodes[$parent])) {
                    [$pn, $pes] = $repo->hydrate_node($parent);
                    if ($pn) { $nodes[$parent] = $pn; $edges = array_merge($edges, $pes); }
                }
                // Se il parent esiste, prosegui a risalire
                if (!isset($nodes[$parent])) break;
                // Se abbiamo raggiunto un parent già incluso in origine, possiamo fermarci
                // (ma mantenerlo così rende robusto anche il caso di catena lunga)
                $cursor = $parent;
            }
        }


        if (!$root_id && $from_node && isset($nodes[$from_node])) {
            $root_id = $this->pick_parent_from_node($nodes, $edges, $from_node, $root_strategy);
        }

        if (!$root_id) {
            $roots = $repo->compute_roots($nodes, $edges);
            if (!empty($roots)) {
                $root_id = $this->pick_root($nodes, $roots);
            }
        }

        if (!$root_id) {
            $payload = [
                'error'   => 'missing_root',
                'message' => __('Nessuna radice trovata con i filtri correnti. Seleziona una radice o modifica i filtri.', 'cz-generation-tree'),
                'meta'    => [
                    'filters'=> array_filter([
                        'school'      => $params['school'] ?? null,
                        'generazione' => $params['generazione'] ?? null,
                        'search'      => $params['search'] ?? null,
                        'include'     => $params['include'] ?? null,
                        'exclude'     => $params['exclude'] ?? null,
                    ]),
                ],
            ];
            return new WP_REST_Response($payload, 400);
        }

        $cache_key = 'cz_gt_subtree_' . md5(json_encode([$params, $root_id, $max_depth]));
        if ($cached = get_transient($cache_key)) {
            return new WP_REST_Response($cached, 200, ['X-CZ-Cache' => 'HIT']);
        }

        $gen = $repo->compute_generations($nodes, $edges, $root_id, $max_depth);
        if (!$gen) {
            $payload = ['nodes' => [], 'edges' => [], 'meta' => ['root_id'=>$root_id,'max_depth'=>$max_depth]];
            return new WP_REST_Response($payload, 200);
        }

        $allowed = array_keys($gen);
        $allowed_map = array_fill_keys($allowed, true);

        $sub_nodes = [];
        foreach ($allowed as $nid) {
            if (isset($nodes[$nid])) {
                $n = $nodes[$nid];
                $n['generation_index'] = $gen[$nid];
                $sub_nodes[] = $n;
            }
        }

        $sub_edges = [];
        foreach ($edges as $e) {
            if (($e['type'] ?? '') !== 'heir') continue;
            if (isset($allowed_map[(int)$e['from']], $allowed_map[(int)$e['to']])) {
                $sub_edges[] = $e;
            }
        }

        $order = strtolower($params['order'] ?? 'asc');
        usort($sub_nodes, function($a, $b) use ($order) {
            $ga = (int)($a['generation_index'] ?? PHP_INT_MAX);
            $gb = (int)($b['generation_index'] ?? PHP_INT_MAX);

            if ($ga !== $gb) {
                // se desc: generazioni più lontane dalla radice prima
                return ($order === 'desc') ? ($gb <=> $ga) : ($ga <=> $gb);
            }

            // tie-break: nome/label (asc per stabilità visiva)
            $an = $a['label'] ?? $a['name'] ?? '';
            $bn = $b['label'] ?? $b['name'] ?? '';
            return strcasecmp($an, $bn);
        });

        $payload = [
            'count' => count($sub_nodes),
            'nodes' => $sub_nodes,
            'edges' => $sub_edges,
            'meta'  => [
                'root_id'   => $root_id,
                'max_depth' => $max_depth,
            ],
        ];

        set_transient($cache_key, $payload, 10 * MINUTE_IN_SECONDS);
        return new WP_REST_Response($payload, 200, ['X-CZ-Cache' => 'MISS']);
    }

    /* -------------------------- /terms -------------------------- */

    /* -------------------------- /terms -------------------------- */
    public function terms(\WP_REST_Request $req): \WP_REST_Response {
        $taxonomy   = (string)$req->get_param('taxonomy');                 // school | generazione
        $search     = (string)$req->get_param('search');
        $hide_empty = $req->get_param('hide_empty');
        $hide_empty = is_null($hide_empty) ? false : (bool)$hide_empty;
        $orderby    = $req->get_param('orderby') ?: 'name';
        $order_raw  = strtoupper($req->get_param('order') ?: 'ASC');
        $order      = in_array($order_raw, ['ASC','DESC'], true) ? $order_raw : 'ASC';
        $number     = max(0, (int)$req->get_param('number'));              // 0 = tutti
        $format     = strtolower($req->get_param('format') ?: 'flat');     // flat | tree

        if (!taxonomy_exists($taxonomy)) {
            return new \WP_REST_Response(['error'=>'bad_tax','message'=>'Tassonomia non valida'], 400);
        }

        $cache_key = 'cz_gt_terms_' . md5(json_encode([$taxonomy,$search,$hide_empty,$orderby,$order,$number,$format]));
        if ($cached = get_transient($cache_key)) {
            return new \WP_REST_Response($cached, 200, ['X-CZ-Cache' => 'HIT']);
        }

        $args = [
            'taxonomy'     => $taxonomy,
            'hide_empty'   => $hide_empty,
            'orderby'      => $orderby,
            'order'        => $order,
            'number'       => $number ?: 0,
            'hierarchical' => false,   // prendi tutti, poi ricostruiamo noi la gerarchia
            'fields'       => 'all',
        ];
        if ($search !== '') $args['search'] = $search;

        $terms = get_terms($args);
        if (is_wp_error($terms)) {
            return new \WP_REST_Response(['error'=>'wp_error','message'=>$terms->get_error_message()], 500);
        }

        // indicizza
        $by_id = [];
        foreach ($terms as $t) { $by_id[(int)$t->term_id] = $t; }

        // mappa figli per parent
        $children = [];
        foreach ($terms as $t) {
            $p = (int)$t->parent;
            if (!isset($children[$p])) $children[$p] = [];
            $children[$p][] = (int)$t->term_id;
        }

        // ordina figli per nome
        $sort_by_name = function(array &$ids) use ($by_id) {
            usort($ids, function($a,$b) use ($by_id){
                $ta = $by_id[$a] ?? null; $tb = $by_id[$b] ?? null;
                $an = $ta ? $ta->name : ''; $bn = $tb ? $tb->name : '';
                return strcasecmp($an, $bn);
            });
        };
        foreach ($children as &$ids) $sort_by_name($ids);
        unset($ids);

        $make_item = function($tid, $depth=0) use ($by_id) {
            $t = $by_id[$tid];
            return [
                'id'     => (int)$t->term_id,
                'slug'   => (string)$t->slug,
                'name'   => (string)$t->name,
                'count'  => (int)$t->count,
                'parent' => $t->parent ? (int)$t->parent : null,
                'depth'  => (int)$depth,
            ];
        };

        // radici (parent=0) tra i termini ottenuti (con search potresti non avere i parent)
        $roots = $children[0] ?? [];

        // se non ci sono radici (es. ricerca che ritorna solo figli), tratta tutti come root
        if (empty($roots) && !empty($terms)) {
            $roots = array_map(fn($t)=>(int)$t->term_id, $terms);
            $sort_by_name($roots);
        }

        if ($format === 'tree') {
            $build_tree = function($tid, $depth) use (&$build_tree, &$children, $make_item) {
                $node = $make_item($tid, $depth);
                $node['children'] = [];
                foreach (($children[$tid] ?? []) as $kid) {
                    $node['children'][] = $build_tree($kid, $depth+1);
                }
                return $node;
            };
            $items = array_map(fn($rid) => $build_tree($rid, 0), $roots);
            $payload = ['taxonomy'=>$taxonomy, 'items'=>$items, 'format'=>'tree'];
        } else {
            $flat = [];
            $walk = function($tid, $depth) use (&$walk, &$children, $make_item, &$flat) {
                $flat[] = $make_item($tid, $depth);
                foreach (($children[$tid] ?? []) as $kid) $walk($kid, $depth+1);
            };
            foreach ($roots as $rid) $walk($rid, 0);
            $payload = ['taxonomy'=>$taxonomy, 'items'=>$flat, 'format'=>'flat'];
        }

        set_transient($cache_key, $payload, 10 * MINUTE_IN_SECONDS);
        return new \WP_REST_Response($payload, 200, ['X-CZ-Cache' => 'MISS']);
    }



    /* -------------------------- helpers -------------------------- */

    private function collect_params(WP_REST_Request $req): array {
        $order = strtolower($req->get_param('order') ?: 'asc');
        $order = in_array($order, ['asc','desc'], true) ? $order : 'asc';

        return [
            'school'      => (string)$req->get_param('school'),
            'generazione' => (string)$req->get_param('generazione'),
            'search'      => (string)$req->get_param('search'),
            'limit'       => max(1, (int)$req->get_param('limit')),
            'offset'      => max(0, (int)$req->get_param('offset')),
            'order'       => $order,
            'include'     => (string)$req->get_param('include'),
            'exclude'     => (string)$req->get_param('exclude'),
            'fields'      => strtolower($req->get_param('fields') ?: 'all'),
            'root_id'     => (int)$req->get_param('root_id'),
        ];
    }

    /** Heuristica root: Bodhidharma se presente, altrimenti più antico (birth_date), poi nome */
    private function pick_root(array $nodes, array $roots): int {
        foreach ($roots as $rid) {
            $n = $nodes[$rid] ?? null;
            if (!$n) continue;
            $name  = mb_strtolower($n['name']  ?? '');
            $label = mb_strtolower($n['label'] ?? '');
            if (strpos($name,'bodhidharma')!==false || strpos($label,'bodhidharma')!==false) {
                return (int)$rid;
            }
        }
        usort($roots, function($a, $b) use ($nodes){
            $na=$nodes[$a]??[]; $nb=$nodes[$b]??[];
            $ay=(int)substr(($na['birth_date']??''),0,4);
            $by=(int)substr(($nb['birth_date']??''),0,4);
            if ($ay && $by && $ay !== $by) return $ay <=> $by;
            return strcasecmp($na['name']??'', $nb['name']??'');
        });
        return (int)$roots[0];
    }

    /** Sceglie il "maestro diretto" (parent) di $node_id secondo la strategia richiesta. */
    private function pick_parent_from_node(array $nodes, array $edges, int $node_id, string $strategy='auto'): int {
        // raccogli incoming edge di tipo heir verso $node_id
        $incoming = [];
        foreach ($edges as $e) {
            if (($e['type'] ?? '') !== 'heir') continue;
            if ((int)($e['to'] ?? 0) === $node_id) $incoming[] = $e;
        }

        // Se non ho archi, prova dal meta
        $meta_parent = (int)($nodes[$node_id]['is_dharma_heir_of'] ?? 0);

        $strategy = strtolower($strategy);
        if ($strategy === 'primary' || $strategy === 'auto') {
            foreach ($incoming as $e) {
                if (!empty($e['primary'])) return (int)$e['from'];
            }
            if ($strategy === 'primary') {
                // se chiesto esplicitamente primary ma non c'è, cadiamo a meta/primo
            }
        }

        if ($strategy === 'meta' || $strategy === 'auto') {
            if ($meta_parent) {
                // se esiste anche un edge coerente, meglio
                foreach ($incoming as $e) {
                    if ((int)$e['from'] === $meta_parent) return $meta_parent;
                }
                return $meta_parent;
            }
        }

        // fallback: primo incoming disponibile
        if (!empty($incoming)) return (int)$incoming[0]['from'];

        return 0;
    }

}
