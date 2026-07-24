import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

// Builds two stable output files: smartbulk.js + style.css under dist/
// Twig shell loads them via direct <script type="module"> + <link rel="stylesheet">.
export default defineConfig({
  plugins: [react()],
  base: '/modules/smartbulk/views/js/app/dist/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    rollupOptions: {
      input: resolve(__dirname, 'src/main.tsx'),
      output: {
        entryFileNames: 'smartbulk.js',
        chunkFileNames: 'smartbulk-[name].js',
        assetFileNames: (info) => {
          if (info.name?.endsWith('.css')) return 'style.css';
          return 'assets/[name]-[hash][extname]';
        },
      },
    },
  },
  server: {
    port: 5173,
  },
});
