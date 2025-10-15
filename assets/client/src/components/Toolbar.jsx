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
            className="czgt-select"
            value={order}
            onChange={e => onChangeFilter('order', e.target.value)}
            aria-label="Ordine"
          >
            <option value="asc">asc</option>
            <option value="desc">desc</option>
          </select>
        </>
      )}

      {/* ======= Discendenza ======= */}
      {view === 'subtree' && (
        <>
        <MasterPicker
          ctx={ctx}
          valueId={Number(filters.from_node || 0)}
          onChange={(id) => {
            onChangeFilter('from_node', id ? String(id) : '');
            onChangeRoot(0);
          }}
          label="Seleziona maestro…"
          placeholder="Filtra nella lista…"
          width={420}
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
            className="czgt-select"
            value={order}
            onChange={e => onChangeFilter('order', e.target.value)}
            aria-label="Ordine"
          >
            <option value="asc">asc</option>
            <option value="desc">desc</option>
          </select>
        </>
      )}
    </div>
  );
}
