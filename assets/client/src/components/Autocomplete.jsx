import React, { useEffect, useRef, useState } from 'react';
import { fetchTerms } from '../api';

// Debounce semplice
function useDebounced(value, ms = 250) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => { const t = setTimeout(() => setDebounced(value), ms); return () => clearTimeout(t); }, [value, ms]);
  return debounced;
}

export default function AutocompleteTax({
  ctx, tax, label = 'Filtra…', value = '', onChange,
  minChars = 1,
}) {
  const [input, setInput] = useState(value);
  const [open, setOpen] = useState(false);
  const [opts, setOpts] = useState([]);
  const [loading, setLoading] = useState(false);
  const [active, setActive] = useState(-1);
  const boxRef = useRef(null);
  const debounced = useDebounced(input, 250);

  useEffect(() => { setInput(value || ''); }, [value]);

  useEffect(() => {
    let cancel = false;
    (async () => {
      const q = debounced.trim();
      if (q.length < minChars) { setOpts([]); setOpen(false); return; }
      setLoading(true);
      try {
        const res = await fetchTerms(ctx, tax, { search: q, format: 'flat', hide_empty: false, number: 50 });
        const items = res?.items || [];
        if (!cancel) {
          setOpts(items);
          setOpen(true);
          setActive(items.length ? 0 : -1);
        }
      } catch {
        if (!cancel) { setOpts([]); setOpen(false); }
      } finally {
        if (!cancel) setLoading(false);
      }
    })();
    return () => { cancel = true; };
  }, [ctx, tax, debounced, minChars]);

  useEffect(() => {
    const onDocClick = (e) => { if (!boxRef.current) return; if (!boxRef.current.contains(e.target)) setOpen(false); };
    document.addEventListener('mousedown', onDocClick);
    return () => document.removeEventListener('mousedown', onDocClick);
  }, []);

  const onPick = (term) => { setInput(term.slug); setOpen(false); onChange?.(term.slug); };

  const onKeyDown = (e) => {
    if (!open || !opts.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(a => Math.min(a + 1, opts.length - 1)); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(a => Math.max(a - 1, 0)); }
    else if (e.key === 'Enter') {
      e.preventDefault();
      if (active >= 0 && active < opts.length) onPick(opts[active]);
      else if (input.trim() !== '') onChange?.(input.trim());
    } else if (e.key === 'Escape') { setOpen(false); }
  };

  return (
    <div className="czgt-autocomplete" ref={boxRef} style={{ position: 'relative', minWidth: 200 }}>
      <div style={{ display: 'flex', gap: 6 }}>
        <input
          type="text"
          aria-label={label}
          placeholder={label}
          value={input}
          onChange={(e) => { setInput(e.target.value); }}
          onFocus={() => { if (opts.length) setOpen(true); }}
          onKeyDown={onKeyDown}
          autoComplete="off"
          style={{ flex: 1 }}
        />
        {value && (
          <button type="button" aria-label="Pulisci filtro" onClick={() => { setInput(''); onChange?.(''); }} title="Pulisci">×</button>
        )}
      </div>
      {open && (
        <ul role="listbox" className="czgt-ac-list" style={{ position: 'absolute', zIndex: 10, left: 0, right: 0, maxHeight: 260, overflow: 'auto', background: 'var(--czgt-bg, #fff)', border: '1px solid #ddd', borderRadius: 6, marginTop: 4, padding: 4 }}>
          {loading && <li style={{ padding: 8, opacity: .7 }}>Caricamento…</li>}
          {!loading && opts.length === 0 && <li style={{ padding: 8, opacity: .7 }}>Nessun risultato</li>}
          {!loading && opts.map((t, i) => (
            <li key={t.id}
                role="option"
                aria-selected={i === active}
                onMouseDown={(e) => { e.preventDefault(); onPick(t); }}
                onMouseEnter={() => setActive(i)}
                style={{ padding: '6px 8px', borderRadius: 4, background: i === active ? 'rgba(0,0,0,.06)' : 'transparent', cursor: 'pointer' }}>
              <div style={{ fontWeight: 600 }}>{t.name}</div>
              <div style={{ fontSize: 12, opacity: .7 }}>{t.slug}</div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
