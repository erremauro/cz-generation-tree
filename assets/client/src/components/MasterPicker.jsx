// assets/client/src/components/MasterPicker.jsx
import React, { useEffect, useMemo, useRef, useState } from 'react';
import { fetchTree } from '../api.js';
import { decodeEntities } from '../utils/text.js';

export default function MasterPicker({
  ctx,
  valueId = 0,
  onChange = () => {},
  label = 'Seleziona maestro…',
  placeholder = 'Filtra nella lista…',
  width = 360,
  limit = 5000,
  allowClear = true,
  clearLabel = 'Nessuno (rimuovi selezione)',
}) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const [busy, setBusy] = useState(false);
  const [items, setItems] = useState([]);
  const [selected, setSelected] = useState(null);

  const wrapRef = useRef(null);
  const inputRef = useRef(null);

  useEffect(() => {
    let ignore = false;
    (async () => {
      try {
        setBusy(true);
        const data = await fetchTree(ctx, { fields: 'nodes', order: 'asc', limit });
        const raw = Array.isArray(data?.nodes) ? data.nodes : [];
        const norm = raw.map(n => ({
          id: n.id,
          name: decodeEntities(n.name || ''),
          name_hanzi: decodeEntities(n.name_hanzi ?? n.hanzi ?? ''),
          name_romaji: decodeEntities(n.name_romaji ?? n.romaji ?? ''),
        }));
        if (!ignore) setItems(norm);
      } catch {
        if (!ignore) setItems([]);
      } finally {
        if (!ignore) setBusy(false);
      }
    })();
    return () => { ignore = true; };
  }, [ctx, limit]);

  useEffect(() => {
    const onDoc = (e) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target)) setOpen(false);
    };
    const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, []);

  useEffect(() => {
    if (!valueId) { setSelected(null); return; }
    const it = items.find(i => Number(i.id) === Number(valueId));
    if (it) setSelected(it);
  }, [valueId, items]);

  const filtered = useMemo(() => {
    const s = q.trim().toLowerCase();
    if (!s) return items;
    const has = (v) => typeof v === 'string' && v.toLowerCase().includes(s);
    return items.filter(n => has(n.name) || has(n.name_hanzi) || has(n.name_romaji));
  }, [items, q]);

  const onPick = (it) => {
    setSelected(it || null);
    setOpen(false);
    onChange(it ? Number(it.id) : 0);
  };

  const clearSelection = () => {
    setSelected(null);
    setQ('');
    onChange(0);
  };

  const displayLabel = selected
    ? (selected.name || selected.name_romaji || selected.name_hanzi || `#${selected.id}`)
    : decodeEntities(label);

  const hasClear = Boolean(allowClear && selected);

  return (
    <div
      ref={wrapRef}
      className="czgt-picker"
      style={{ '--czgt-picker-w': `${width}px` }}
    >
      <div className="czgt-picker-trigger-wrap">
        <button
          type="button"
          className={`czgt-btn czgt-picker-trigger${hasClear ? ' czgt-picker-trigger--has-clear' : ''}`}
          aria-haspopup="listbox"
          aria-expanded={open ? 'true' : 'false'}
          onClick={() => {
            setOpen(o => !o);
            setTimeout(() => inputRef.current?.focus(), 0);
          }}
          title={displayLabel}
        >
          <span className="czgt-picker-label">{displayLabel}</span>
          <span className="czgt-caret" aria-hidden>▾</span>
        </button>

        {hasClear && (
          <button
            type="button"
            className="czgt-picker-clear"
            onClick={clearSelection}
            aria-label="Rimuovi selezione"
            title="Rimuovi selezione"
          >
            ×
          </button>
        )}
      </div>

      {open && (
        <div className="czgt-popover" role="dialog" aria-label="Scegli maestro">
          <div className="czgt-picker-search">
            <input
              ref={inputRef}
              type="text"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              className="czgt-input"
              placeholder={placeholder}
              aria-label={placeholder}
            />
          </div>

          <ul className="czgt-picker-list" role="listbox" aria-label="Maestri">
            {allowClear && (
              <li>
                <button
                  type="button"
                  className="czgt-picker-item"
                  role="option"
                  aria-selected={!selected}
                  onClick={() => onPick(null)}
                  title={clearLabel}
                >
                  {clearLabel}
                </button>
              </li>
            )}

            {busy && <li className="czgt-muted">Caricamento…</li>}
            {!busy && filtered.length === 0 && <li className="czgt-muted">Nessun risultato</li>}
            {!busy && filtered.map(it => (
              <li key={it.id}>
                <button
                  type="button"
                  className="czgt-picker-item"
                  role="option"
                  aria-selected={Number(valueId) === Number(it.id)}
                  onClick={() => onPick(it)}
                  title={[it.name, it.name_hanzi, it.name_romaji].filter(Boolean).join(' / ')}
                >
                  <span className="czgt-picker-name">{it.name}</span>
                  {it.name_hanzi && <span className="czgt-alt"> · {it.name_hanzi}</span>}
                  {it.name_romaji && <span className="czgt-alt"> · {it.name_romaji}</span>}
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
