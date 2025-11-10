// assets/client/src/components/TreeList.jsx
import React from 'react';
import { decodeEntities } from '../utils/text.js';

/** Converte diversi tipi in una label stampabile (decodifica entità HTML) */
function toLabel(v) {
  if (v == null) return '';
  if (typeof v === 'string' || typeof v === 'number') return decodeEntities(String(v));
  if (Array.isArray(v)) return v.map(toLabel).filter(Boolean).join(', ');
  if (typeof v === 'object') {
    return decodeEntities(v.name || v.title || v.slug || (v.id != null ? `#${v.id}` : ''));
  }
  return decodeEntities(String(v));
}

/** Estrae ID da possibili forme (number | string | {id}) */
function idOf(v) {
  if (v == null) return 0;
  if (typeof v === 'number' && v > 0) return v;
  if (typeof v === 'string') {
    const n = parseInt(v, 10);
    return Number.isFinite(n) && n > 0 ? n : 0;
  }
  if (typeof v === 'object' && v.id != null) {
    const n = parseInt(v.id, 10);
    return Number.isFinite(n) && n > 0 ? n : 0;
  }
  return 0;
}

/** Estrae solo l'anno da 'YYYY', 'YYYY-MM', 'YYYY-MM-DD' e rimuove zeri iniziali */
function yearOnly(iso) {
  if (!iso) return null;
  const head = String(iso).split('-')[0] || '';
  const trimmed = head.replace(/^0+/, ''); // '0680' -> '680'
  return trimmed || null;
}

/** Etichetta anno con supporto 'circa' (precision = 'circa' → 'c. <anno>') */
function yearLabel(iso, precision) {
  const y = yearOnly(iso);
  if (!y) return null;
  return precision === 'circa' ? `c. ${y}` : y;
}

/** Format "Ybirth - Ydeath" con fallback (?, Y) */
function formatYears(birthISO, deathISO, birthPrec, deathPrec) {
  const yb = yearLabel(birthISO, birthPrec);
  const yd = yearLabel(deathISO, deathPrec);
  if (!yb && !yd) return '';
  return `${yb || '?'} - ${yd || '?'}`
}

export default function TreeList({
  nodes = [],
  view = 'tree',
  onOpen = () => {},
  onPickMaster = () => {},   // usato anche per selezionare il predecessore come radice
}) {
  if (!Array.isArray(nodes) || nodes.length === 0) {
    return <p className="czgt-empty">Nessun risultato.</p>;
  }

  return (
    <ul className="czgt-list">
      {nodes.map((n) => {
        const id = Number(n.id) || 0;
        const title = toLabel(n.name) || `#${id}`;

        // Predecessore (maestro diretto): prendi l'ID da is_dharma_heir_of (payload API),
        // con fallback su parent/root se fossero inviati come oggetti.
        const parentId = idOf(n.is_dharma_heir_of) || idOf(n.parent) || idOf(n.root);
        const parentLabel =
          toLabel(n.parent_name) ||
          toLabel(n.parent) ||
          toLabel(n.root_name) ||
          toLabel(n.root);

        const romaji = toLabel(n.name_romaji ?? n.romaji);
        const hanzi  = toLabel(n.name_hanzi  ?? n.hanzi);
        const sub = [romaji, hanzi].filter(Boolean).join(' · ');
        const years = formatYears(n.birth_date, n.death_date, n.birth_precision, n.death_precision);

        return (
          <li key={id} className="czgt-card">
            <div className="czgt-card-media">
              {n.picture ? (
                <img className="czgt-avatar" src={n.picture} alt={title} />
              ) : (
                <img className="czgt-avatar czgt-avatar--placeholder" src="" aria-hidden />
              )}
            </div>

            <div className="czgt-card-body">
              {/* Nome cliccabile -> apre Discendenza per questo ID */}
              <button
                type="button"
                className="czgt-link czgt-node-name"
                onClick={() => onPickMaster(id)}
                title={sub ? `${title} — ${sub}` : title}
              >
                {title}
              </button>

              {sub && <div className="czgt-sub">{sub}</div>}

              {years && <div className="czgt-years" aria-label="Anni">{years}</div>}

              {/* Maestro (predecessore): se ho l'ID lo rendo cliccabile per selezionarlo come radice */}
              {parentLabel && (
                <div className="czgt-badge">
                  Erede Di: {' '}
                  {parentId ? (
                    <button
                      type="button"
                      className="czgt-btn czgt-link"
                      onClick={() => onPickMaster(parentId)}
                      title={`Vai alla discendenza di ${parentLabel}`}
                    >
                       {parentLabel}
                    </button>
                  ) : (
                    parentLabel
                  )}
                </div>
              )}
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
