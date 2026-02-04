// App.jsx
import React, { useEffect, useMemo, useState } from 'react';
import { fetchTree, fetchSubtree, fetchRoots, fetchTerms } from './api.js';
import useQueryParams from './hooks/useQueryParams.js';
import Toolbar from './components/Toolbar.jsx';
import TreeList from './components/TreeList.jsx';
import TreeGraph from './components/TreeGraph.jsx';
import { foldSearch } from './utils/text.js';

function normalizeView(v) {
  return (v === 'subtree' || v === 'graph') ? v : 'tree';
}

export default function App({ context, initialProps }) {
  const { params, setParam, delParam } = useQueryParams();

  // --- VIEW deep-linking
  const viewFromUrl  = normalizeView(params.get('view') || initialProps.view || 'tree');
  const [view, setViewState] = useState(viewFromUrl);

  useEffect(() => {
    const v = normalizeView(params.get('view') || initialProps.view || 'tree');
    if (v !== view) setViewState(v);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [params]);

  const setView = (next) => {
    const v = normalizeView(next);
    setViewState(v);
    if (v === 'tree') {
      delParam('view');
      delParam('root_id');
      delParam('max_depth');
      delParam('from_node');
      delParam('orientation');
    } else {
      setParam('view', v);
    }
  };

  // --- stato dati
  const [loading, setLoading] = useState(true);
  const [nodes, setNodes]     = useState([]);
  const [error, setError]     = useState(null);
  const [roots, setRoots]     = useState([]);

  // tassonomie
  const [schoolTerms, setSchoolTerms] = useState([]);
  const [generationTerms, setGenerationTerms] = useState([]);

  const rootId   = Number(params.get('root_id') || initialProps.root_id || 0);
  const maxDepth = Number(params.get('max_depth') || initialProps.max_depth || 0);

  const filters = useMemo(() => {
    const p = new URLSearchParams(params.toString());
    const out = {};
    ['school','generazione','search','include','exclude','order','limit','offset','from_node','root_strategy','orientation']
      .forEach(k => { if (p.has(k) && p.get(k) !== '') out[k] = p.get(k); });
    return out;
  }, [params]);

  // Ricerca locale (solo in tree)
  const [localQuery, setLocalQuery] = useState(filters.search || '');
  useEffect(() => { setLocalQuery(filters.search || ''); }, [filters.search]);

  // carica tassonomie
  useEffect(() => {
    let ignore = false;
    (async () => {
      try {
        const [s,g] = await Promise.all([
          fetchTerms(context, 'school', { format: 'flat', hide_empty: false }),
          fetchTerms(context, 'generazione', { format: 'flat', hide_empty: false }),
        ]);
        if (!ignore) {
          setSchoolTerms(s.items || []);
          setGenerationTerms(g.items || []);
        }
      } catch {
        if (!ignore) { setSchoolTerms([]); setGenerationTerms([]); }
      }
    })();
    return () => { ignore = true; };
  }, [context]);

  // carica roots
  useEffect(() => {
    let ignore = false;
    (async () => {
      try {
        const data = await fetchRoots(context, {
          school: filters.school,
          generazione: filters.generazione,
          search: filters.search
        });
        if (!ignore) setRoots(data?.roots || []);
      } catch {
        if (!ignore) setRoots([]);
      }
    })();
    return () => { ignore = true; };
  }, [context, filters.school, filters.generazione, filters.search]);

  // auto-root in subtree/graph se non impostata
  useEffect(() => {
    if (view === 'tree') return;
    if (filters.from_node) return;
    if (rootId) return;
    if (Array.isArray(roots) && roots.length > 0) {
      const first = typeof roots[0] === 'object' ? roots[0].id : roots[0];
      if (first) setParam('root_id', String(first));
    }
  }, [view, rootId, roots, filters.from_node, setParam]);

  // fetch dati principali
  useEffect(() => {
    let ignore = false;
    setLoading(true);
    setError(null);

    (async () => {
      try {
        const data = (view === 'subtree' || view === 'graph')
          ? await fetchSubtree(context, {
              ...filters,
              root_id: filters.from_node ? undefined : (rootId || undefined),
              max_depth: maxDepth || undefined,
            })
          : await fetchTree(context, { ...filters });

        if (!ignore) setNodes(data?.nodes || []);
      } catch (e) {
        if ((view === 'subtree' || view === 'graph') && (!rootId || String(e?.message || '').includes('HTTP 400'))) {
          try {
            const r = await fetchRoots(context, {
              school: filters.school,
              generazione: filters.generazione,
              search: filters.search
            });
            const rlist = r?.roots || [];
            if (rlist.length > 0) {
              const first = typeof rlist[0] === 'object' ? rlist[0].id : rlist[0];
              if (first) setParam('root_id', String(first));
              return;
            }
          } catch {}
        }
        if (!ignore) setError(e?.message || 'Errore');
      } finally {
        if (!ignore) setLoading(false);
      }
    })();

    return () => { ignore = true; };
  }, [context, view, rootId, maxDepth, filters, setParam]);

  // Filtro + Ordinamento client-side (per lista)
  const displayNodes = useMemo(() => {
    let list = nodes;

    if (view === 'tree') {
      const q = foldSearch((localQuery || '').trim());
      if (q) {
        const has = (s) => typeof s === 'string' && foldSearch(s).includes(q);
        list = list.filter(n => has(n.name) || has(n.hanzi) || has(n.romaji));
      }
      list = [...list].sort((a, b) => {
        const an = (a.name || '').toLowerCase();
        const bn = (b.name || '').toLowerCase();
        return (filters.order === 'desc') ? (bn.localeCompare(an)) : (an.localeCompare(bn));
      });
      return list;
    }

    if (view === 'subtree') {
      const cmpGen = (a, b) => {
        const ga = Number(a.generation_index ?? Number.MAX_SAFE_INTEGER);
        const gb = Number(b.generation_index ?? Number.MAX_SAFE_INTEGER);
        if (ga !== gb) return ga - gb;
        const an = (a.name || '').toLowerCase();
        const bn = (b.name || '').toLowerCase();
        return an.localeCompare(bn);
      };
      list = [...list].sort(cmpGen);
      if (filters.order === 'desc') list.reverse();
      return list;
    }

    // graph: l'ordinamento non è rilevante, restituiamo i nodi così come arrivano
    return nodes;
  }, [nodes, view, localQuery, filters.order]);

  const handlePickMaster = (id) => {
    if (view === 'tree') {
      setParam('view', 'subtree');
    } else if (view === 'graph') {
      setParam('view', 'graph');
    }
    setParam('root_id', String(id));
    delParam('from_node');
  };

  return (
    <div className="czgt-app h-full flex flex-col">
      <Toolbar
        ctx={context}
        view={view}
        setView={setView}
        onLocalSearchChange={(v) => setLocalQuery(v)}
        roots={roots}
        rootId={rootId}
        onChangeRoot={(id) => (id ? setParam('root_id', String(id)) : delParam('root_id'))}
        maxDepth={maxDepth}
        onChangeMaxDepth={(d) => (d ? setParam('max_depth', String(d)) : delParam('max_depth'))}
        filters={filters}
        onChangeFilter={(k, v) => (v ? setParam(k, v) : delParam(k))}
        schoolTerms={schoolTerms}
        generationTerms={generationTerms}
      />

      <div className="czgt-body">
        {loading && <p>{context?.i18n?.loading || 'Caricamento…'}</p>}
        {error   && <p style={{ color: 'crimson' }}>{context?.i18n?.error || 'Errore'}: {error}</p>}
        {!loading && !error && (
          <>
            {view === 'tree' && (
              <TreeList
                nodes={displayNodes}
                view={view}
                onOpen={(url) => { if (url) window.location.href = url; }}
                onPickMaster={handlePickMaster}
              />
            )}
            {view === 'subtree' && (
              <TreeList
                nodes={displayNodes}
                view={view}
                onOpen={(url) => { if (url) window.location.href = url; }}
                onPickMaster={handlePickMaster}
              />
            )}
            {view === 'graph' && (
             <div style={{ height: '100%' }}>
                <TreeGraph
                  nodes={displayNodes}
                  rootId={rootId || Number(filters.from_node || 0)}
                  orientation={filters.orientation || 'horizontal'}
                  onPickMaster={handlePickMaster}
                />
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}
