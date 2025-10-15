// components/TreeList.jsx
import React from 'react';

/** Converte diversi tipi in una label stampabile */
function toLabel(v) {
  if (v == null) return '';
  if (typeof v === 'string' || typeof v === 'number') return String(v);
  if (Array.isArray(v)) return v.map(toLabel).filter(Boolean).join(', ');
  if (typeof v === 'object') {
    // tipico term: {id, slug, name}
    return v.name || v.title || v.slug || (v.id != null ? `#${v.id}` : '');
  }
  return String(v);
}

/** Ritorna il primo valore “stampabile” tra una lista di candidati */
function firstLabel(...vals) {
  for (const v of vals) {
    const s = toLabel(v);
    if (s) return s;
  }
  return '';
}

export default function TreeList({
  nodes = [],
  view = 'tree',                 // "tree" | "subtree" (non usato per logica interna, tenuto per completezza)
  onOpen = () => {},
  onPickMaster = () => {},       // (id) => void — click sul nome
}) {
  if (!Array.isArray(nodes) || nodes.length === 0) {
    return <p className="czgt-empty">Nessun risultato.</p>;
  }

  return (
    <ul className="czgt-list">
      {nodes.map((n) => {
        const id = Number(n.id) || 0;
        const title = toLabel(n.name) || `#${id}`;

        // campi opzionali che possono arrivare come stringa O oggetto {id,slug,name}
        const parentBadge = firstLabel(n.parent_name, n.parent, n.root_name, n.root);

        // alias nomi secondari: accetta sia name_romaji/hanzi che romaji/hanzi
        const romaji = toLabel(n.name_romaji ?? n.romaji);
        const hanzi  = toLabel(n.name_hanzi  ?? n.hanzi);
        const sub = [romaji, hanzi].filter(Boolean).join(' · ');

        return (
          <li key={id} className="czgt-card">
            <div className="czgt-card-media">
              {n.picture ? (
                <img className="czgt-avatar" src={n.picture} alt={title} />
              ) : (
                <div className="czgt-avatar czgt-avatar--placeholder" aria-hidden />
              )}
            </div>

            <div className="czgt-card-body">
              {parentBadge && <span className="czgt-badge">{parentBadge}</span>}

              {/* Nome cliccabile */}
              <button
                type="button"
                className="czgt-link czgt-node-name"
                onClick={() => onPickMaster(id)}
                title={sub ? `${title} — ${sub}` : title}
              >
                {title}
              </button>

              {sub && <div className="czgt-sub">{sub}</div>}
            </div>

            <div className="czgt-card-actions">
              <button
                type="button"
                className="czgt-btn czgt-btn--ghost"
                onClick={() => onOpen(n.url)}
              >
                Apri scheda
              </button>
            </div>
          </li>
        );
      })}
    </ul>
  );
}
