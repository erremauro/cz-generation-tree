// Toolbar.jsx
import React, { useRef, useState, useEffect } from 'react';
import MasterPicker from './MasterPicker.jsx';

function useDebouncedCallback(fn, delay = 300) {
  const t = useRef();
  return (...args) => {
    if (t.current) clearTimeout(t.current);
    t.current = setTimeout(() => fn(...args), delay);
  };
}

export default function Toolbar({
  ctx,
  view, setView,
  roots, rootId, onChangeRoot,
  maxDepth, onChangeMaxDepth,
  filters, onChangeFilter,
  schoolTerms = [],
  generationTerms = [],
  onLocalSearchChange, // solo per "Albero Completo"
}) {
  const order = (filters.order === 'desc') ? 'desc' : 'asc';

  // live search (solo tree)
  const [searchLocal, setSearchLocal] = useState('');
  useEffect(() => { setSearchLocal(''); }, [view]);
  const pushLocal = useDebouncedCallback((v) => onLocalSearchChange?.(v), 250);

  const pad = (d) => d > 0 ? '— '.repeat(d) : '';
  const depthOrderControls = (
    <div className="czgt-depth-order">
      <select
        className="czgt-select czgt-order-select"
        value={order}
        onChange={e => onChangeFilter('order', e.target.value)}
        aria-label="Ordine"
      >
        <option value="asc">asc</option>
        <option value="desc">desc</option>
      </select>

      <label className="czgt-input" style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
        <span style={{ opacity: .8 }}>Profondità</span>
        <input
          type="number"
          min={0}
          max={50}
          value={maxDepth || 0}
          onChange={(e) => onChangeMaxDepth(parseInt(e.target.value || '0', 10))}
          aria-label="Profondità massima"
          style={{ width: 90 }}
        />
      </label>
    </div>
  );

  return (
    <div className="czgt-toolbar">
      {/* Vista */}
      <select
        className="czgt-select"
        value={view}
        onChange={e => setView(e.target.value)}
        aria-label="Vista"
      >
        <option value="tree">Albero completo</option>
        <option value="subtree">Discendenza</option>
        { /* <option value="graph">Discendenza (grafico)</option> */ }
      </select>

      {/* ======= Albero Completo ======= */}
      {view === 'tree' && (
        <>
          <input
            type="search"
            className="czgt-input czgt-input--search"
            placeholder="Cerca maestri…"
            aria-label="Cerca maestri"
            value={searchLocal}
            onChange={(e) => { const v = e.target.value; setSearchLocal(v); pushLocal(v); }}
          />

          <select
            className="czgt-select"
            value={filters.generazione || ''}
            onChange={(e) => onChangeFilter('generazione', e.target.value)}
            aria-label="Generazione"
          >
            <option value="">{'Tutte le generazioni'}</option>
            {generationTerms.map(t => (
              <option key={t.slug} value={t.slug}>{pad(t.depth)}{t.name}</option>
            ))}
          </select>

          <select
            className="czgt-select"
            value={filters.school || ''}
            onChange={(e) => onChangeFilter('school', e.target.value)}
            aria-label="Scuola"
          >
            <option value="">{'Tutte le scuole'}</option>
            {schoolTerms.map(t => (
              <option key={t.slug} value={t.slug}>{pad(t.depth)}{t.name}</option>
            ))}
          </select>

          <select
            className="czgt-select czgt-order-select czgt-order-select--tree"
            value={order}
            onChange={e => onChangeFilter('order', e.target.value)}
            aria-label="Ordine"
          >
            <option value="asc">asc</option>
            <option value="desc">desc</option>
          </select>
        </>
      )}

      {/* ======= Discendenza LISTA / GRAFICO (controlli comuni) ======= */}
      {(view === 'subtree' || view === 'graph') && (
        <>
          <MasterPicker
            ctx={ctx}
            valueId={Number(filters.root_id || 0)}
            onChange={(id) => {
              onChangeFilter('root_id', id ? String(id) : '');
              onChangeFilter('from_node', '');
            }}
            label="Seleziona maestro…"
            placeholder="Filtra nella lista…"
            width={420}
          />

          {view === 'graph' && depthOrderControls}

          <select
            className="czgt-select"
            value={filters.generazione || ''}
            onChange={(e) => onChangeFilter('generazione', e.target.value)}
            aria-label="Generazione"
          >
            <option value="">{'Tutte le generazioni'}</option>
            {generationTerms.map(t => (
              <option key={t.slug} value={t.slug}>{pad(t.depth)}{t.name}</option>
            ))}
          </select>

          <select
            className="czgt-select"
            value={filters.school || ''}
            onChange={(e) => onChangeFilter('school', e.target.value)}
            aria-label="Scuola"
          >
            <option value="">{'Tutte le scuole'}</option>
            {schoolTerms.map(t => (
              <option key={t.slug} value={t.slug}>{pad(t.depth)}{t.name}</option>
            ))}
          </select>

          {view === 'subtree' && depthOrderControls}

          {view === 'graph' && (
            <select
              className="czgt-select"
              value={filters.orientation || 'horizontal'}
              onChange={(e) => onChangeFilter('orientation', e.target.value)}
              aria-label="Orientamento"
            >
              <option value="horizontal">orizzontale</option>
              <option value="vertical">verticale</option>
            </select>
          )}
        </>
      )}
    </div>
  );
}
