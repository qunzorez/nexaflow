import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { readFileSync } from 'node:fs';

const settings = JSON.parse(readFileSync(new URL('./appsettings.json', import.meta.url), 'utf8'));
const profile = settings.profiles[settings.environment];
if (!profile) throw new Error('Invalid environment in appsettings.json');

export default defineConfig({
  plugins: [react()],
  build: { outDir: 'dist', emptyOutDir: true },
  server: {
    port: 5173,
    proxy: { '/api': { target: profile.base_url, changeOrigin: false } }
  }
});
