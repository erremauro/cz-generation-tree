(() => {
  // helper: parse filtri dall'URL (solo quelli riconosciuti)
  function readFiltersFromUrl() {
    const p = new URLSearchParams(window.location.search);

    const filters = {};
    const getStr = (k) => (p.has(k) ? String(p.get(k)) : '');
    const getInt = (k) => (p.has(k) ? parseInt(String(p.get(k)), 10) || 0 : 0);

    // filtri supportati dalle API
    filters.school      = getStr('school');
    filters.generazione = getStr('generazione');
    filters.search      = getStr('search');
    filters.include     = getStr('include');
    filters.exclude     = getStr('exclude');

    // paging/ordinamento (opzionali)
    const order = getStr('order').toLowerCase();
    filters.order  = (order === 'desc') ? 'desc' : (order === 'asc' ? 'asc' : undefined);
    const limit  = getInt('limit');  if (limit)  filters.limit  = limit;
    const offset = getInt('offset'); if (offset) filters.offset = offset;

    // parametri subtree opzionali
    const root_id   = getInt('root_id');   if (root_id)   filters.root_id = root_id;
    const max_depth = getInt('max_depth'); if (max_depth) filters.max_depth = max_depth;

    // rimuovi chiavi vuote/undefined
    Object.keys(filters).forEach(k => {
      if (filters[k] === '' || filters[k] === undefined || filters[k] === null) delete filters[k];
    });

    return filters;
  }

  async function jget(url, nonce) {
    const res = await fetch(url, { headers: nonce ? { 'X-WP-Nonce': nonce } : {} });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  }

  function mount(el, ctx, props) {
    if (!el) return;

    const wrap = document.createElement('div');
    wrap.className = 'cz-gt-app';
    const head = document.createElement('div');
    head.className = 'cz-gt-head';
    head.textContent = 'CZ Generation Tree (stub)';
    const info = document.createElement('div');
    info.className = 'cz-gt-info';
    info.textContent = 'Carico dati…';
    wrap.appendChild(head);
    wrap.appendChild(info);
    el.innerHTML = '';
    el.appendChild(wrap);

    // leggi filtri dall’URL; props contengono solo stato iniziale "strutturale"
    const urlFilters = readFiltersFromUrl();

    const view = (props.view || 'tree').toLowerCase();
    const base = new URL(ctx.restBase + (view === 'subtree' ? 'subtree' : 'tree'));

    // subtree: root_id e max_depth possono venire da props o querystring
    const root_id   = urlFilters.root_id ?? props.root_id;
    const max_depth = urlFilters.max_depth ?? props.max_depth;

    // applica filtri alle query
    const params = { ...urlFilters };
    delete params.root_id;
    delete params.max_depth;

    Object.entries(params).forEach(([k, v]) => base.searchParams.set(k, String(v)));
    if (view === 'subtree' && root_id)   base.searchParams.set('root_id', String(root_id));
    if (view === 'subtree' && max_depth) base.searchParams.set('max_depth', String(max_depth));

    jget(base.toString(), ctx.restNonce)
      .then(data => {
        const n = (data && data.nodes) ? data.nodes.length : ((data && data.count) ? data.count : 0);
        info.textContent = `Dati caricati: ${n} nodi. (UI interattiva in arrivo)`;
      })
      .catch(() => {
        info.textContent = 'Errore nel caricamento.';
      });
  }

  function processQueue() {
    if (!Array.isArray(window.CZ_GT_BOOTSTRAP)) return;
    window.CZ_GT_BOOTSTRAP.forEach(item => {
      try {
        const el = document.getElementById(item.elId);
        mount(el, item.context || {}, item.props || {});
      } catch (e) {}
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', processQueue);
  } else {
    processQueue();
  }
})();
