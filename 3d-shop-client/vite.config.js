import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Port 3000 and these three prefixes keep client and API on one origin: model
// files are served off disk by artisan serve without CORS headers.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 3000,
    proxy: {
      '/api': 'http://localhost:8000',
      '/storage': 'http://localhost:8000',
      '/sanctum': 'http://localhost:8000',
    },
  },
  build: {
    outDir: 'build',
  },
  test: {
    globals: true,
    environment: 'jsdom',
    setupFiles: './src/setupTests.js',
    css: true,
  },
});
