import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

// output diretto nella cartella del plugin
export default defineConfig({
  plugins: [react()],
  root: resolve(__dirname),          // assets/spa
  base: '',                          // percorsi relativi
  build: {
    outDir: resolve(__dirname, '../dist'),
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: resolve(__dirname, 'src/main.jsx')
    }
  },
  server: {
    port: 5173,
    strictPort: true,
    cors: true
  }
});
