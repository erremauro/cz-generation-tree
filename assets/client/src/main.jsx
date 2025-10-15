import React from 'react';
import { createRoot } from 'react-dom/client';
import './styles/cz-gt-app.css';
import App from './App.jsx';

// In WP: leggiamo la queue window.CZ_GT_BOOTSTRAP
function mountWP() {
  const queue = window.CZ_GT_BOOTSTRAP || [];
  queue.forEach(({ elId, context, props }) => {
    const el = document.getElementById(elId);
    if (!el) return;
    const root = createRoot(el);
    root.render(<App context={context} initialProps={props} />);
  });
}

// In dev: monta su #dev-root, con un contesto fake
function mountDev() {
  const el = document.getElementById('dev-root');
  if (!el) return;
  const context = {
    restBase: '/wp-json/cz-gt/v1/', // se lanci vite in proxy puoi mettere il tuo dominio
    restNonce: '',                  // non serve in GET pubbliche
    siteUrl: '/'
  };
  const props = { view: 'tree', root_id: 0, max_depth: 0, ui: { height: '70vh' } };
  const root = createRoot(el);
  root.render(<App context={context} initialProps={props} />);
}

if (document.getElementById('dev-root')) {
  mountDev();
} else {
  mountWP();
}
