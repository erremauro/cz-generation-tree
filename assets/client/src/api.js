// api.js
// Helpers comuni
function makeUrl(base, path, params = {}) {
  const u = new URL(path.replace(/^\/+/, ''), base); // base = ctx.restBase (termina con "/")
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return;
    qs.set(k, String(v));
  });
  if ([...qs].length) u.search = qs.toString();
  return u;
}

function stdHeaders(ctx) {
  const h = { Accept: 'application/json' };
  if (ctx.restNonce) h['X-WP-Nonce'] = ctx.restNonce;
  return h;
}

async function fetchJson(u, headers) {
  const res  = await fetch(u.toString(), { headers, credentials: 'same-origin' });
  const text = await res.text();
  let data;
  try { data = text ? JSON.parse(text) : {}; } catch { data = { _raw: text }; }
  if (!res.ok) {
    const msg = data?.message || `HTTP ${res.status}`;
    const err = new Error(msg);
    err.status = res.status; err.data = data;
    throw err;
  }
  return data;
}

// ---- Endpoints CZ-GT ----
export async function fetchRoots(ctx, filters = {}) {
  const u = makeUrl(ctx.restBase, 'roots', filters);
  return fetchJson(u, stdHeaders(ctx)); // {roots:[{id,name,url}]}
}

export async function fetchTree(ctx, params = {}) {
  const u = makeUrl(ctx.restBase, 'tree', params);
  return fetchJson(u, stdHeaders(ctx));
}

export async function fetchSubtree(ctx, params = {}) {
  const u = makeUrl(ctx.restBase, 'subtree', params);
  return fetchJson(u, stdHeaders(ctx));
}

// Tassonomie: school, generazione, ...
export async function fetchTerms(ctx, taxonomy, opts = {}) {
  const u = makeUrl(ctx.restBase, 'terms', {
    taxonomy,
    format: opts.format || 'flat',
    hide_empty: opts.hide_empty ? '1' : '0',
    orderby: opts.orderby || 'name',
    order: opts.order || 'asc',
    number: opts.number,
    search: opts.search || ''
  });
  return fetchJson(u, stdHeaders(ctx)); // { taxonomy, items:[...] }
}

// cerca/elenchi maestri (q può essere vuota per elenco iniziale)
export async function searchMaestri(ctx, { q = '', limit = 500, offset = 0 } = {}) {
  const u = new URL(ctx.restBase + 'tree');
  u.searchParams.set('fields', 'nodes');
  if (q) u.searchParams.set('search', q);
  u.searchParams.set('limit', String(limit));
  u.searchParams.set('offset', String(offset));
  const data = await fetchJson(u, stdHeaders(ctx));
  // ordina client-side per nome (fallback)
  const list = Array.isArray(data?.nodes) ? data.nodes.slice() : [];
  list.sort((a, b) => (a.name || '').localeCompare(b.name || '', 'it', { sensitivity: 'base' }));
  return list;
}

