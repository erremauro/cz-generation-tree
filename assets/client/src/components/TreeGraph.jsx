// assets/client/src/components/TreeGraph.jsx
import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Tree from 'react-d3-tree';
import { decodeEntities } from '../utils/text.js';

function buildHierarchy(nodes = [], rootId = 0) {
  const byId = new Map();
  nodes.forEach(n => byId.set(Number(n.id), n));

  const children = new Map();
  nodes.forEach(n => {
    const id = Number(n.id);
    const p  = Number(n.is_dharma_heir_of || 0);
    if (!children.has(p)) children.set(p, []);
    children.get(p).push(id);
  });

  const toTree = (id) => {
    const n = byId.get(id);
    if (!n) return null;
    const label = decodeEntities(n.name || n.label || `#${id}`);
    const sub   = [
      decodeEntities(n.romaji || n.name_romaji || ''),
      decodeEntities(n.hanzi  || n.name_hanzi  || '')
    ].filter(Boolean).join(' · ');

    const node = {
      name: label,
      attributes: sub ? { alt: sub } : undefined,
      __id: id,
      children: []
    };
    const kids = children.get(id) || [];
    for (const k of kids) {
      const child = toTree(k);
      if (child) node.children.push(child);
    }
    if (node.children.length === 0) delete node.children;
    return node;
  };

  let rid = Number(rootId || 0);
  if (!rid) {
    let best = null;
    for (const n of nodes) {
      const gi = Number(n.generation_index ?? Number.MAX_SAFE_INTEGER);
      if (best == null || gi < best.generation_index) {
        best = { id: Number(n.id), generation_index: gi };
      }
    }
    rid = best?.id || 0;
  }
  if (!rid) return null;

  return toTree(rid);
}

export default function TreeGraph({
  nodes = [],
  rootId = 0,
  onPickMaster = () => {},
  orientation = 'horizontal',
  collapsible = true
}) {
  const data = useMemo(() => buildHierarchy(nodes, rootId) || { name: 'Nessun dato' }, [nodes, rootId]);
  const containerRef = useRef(null);
  const [translate, setTranslate] = useState({ x: 0, y: 0 });
  const [box, setBox] = useState({ width: 0, height: 0 });

  const measure = React.useCallback(() => {
    const el = containerRef.current;
    if (!el) return;
    const { width, height } = el.getBoundingClientRect();
    setBox({ width: Math.max(0, Math.floor(width)), height: Math.max(0, Math.floor(height)) });
    setTranslate({ x: Math.round(width * 0.08), y: Math.round(height * 0.5) });
  }, []);

  useEffect(() => { measure(); }, [measure, orientation, nodes.length]);

  useEffect(() => {
    if (!containerRef.current) return;
    const ro = new ResizeObserver(() => measure());
    ro.observe(containerRef.current);
    window.addEventListener('resize', measure);
    return () => { try { ro.disconnect(); } catch{} window.removeEventListener('resize', measure); };
  }, [measure]);

  const handleNodeClick = useCallback((nodeDatum) => {
    const id = Number(nodeDatum?.__data__?.__id || nodeDatum?.__id || 0);
    if (id) onPickMaster(id);
  }, [onPickMaster]);

  return (
    <div ref={containerRef} className="czgt-graph-wrap">
      <Tree
        data={data}
        dimensions={box.width && box.height ? box : undefined}
        translate={translate}
        orientation={orientation}
        collapsible={collapsible}
        separation={{ siblings: 1.2, nonSiblings: 1.4 }}
        nodeSize={{ x: 260, y: 100 }}
        renderCustomNodeElement={({ nodeDatum }) => {
          const title = nodeDatum.name || '';
          const alt   = nodeDatum.attributes?.alt || '';
          return (
            <g>
              <rect width="220" height="64" x="-110" y="-30" rx="8" ry="8" stroke="currentColor" fill="white" />
              <text textAnchor="middle" fontSize="13" fontWeight="700" dy="-4">{title}</text>
              {alt && <text textAnchor="middle" fontSize="11" dy="14" opacity="0.8">{alt}</text>}
              <rect
                x="-110" y="-30" width="220" height="64" rx="8" ry="8"
                fill="transparent" stroke="transparent"
                style={{ cursor: 'pointer' }}
                onClick={() => handleNodeClick(nodeDatum)}
              />
            </g>
          );
        }}
        onNodeClick={handleNodeClick}
        pathFunc="elbow"
        zoomable
        enableLegacyTransitions
      />
    </div>
  );
}
