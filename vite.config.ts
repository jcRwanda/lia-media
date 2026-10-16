import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import path from 'node:path';

export default defineConfig({
    plugins: [laravel({ input: ['resources/css/app.css', 'resources/js/main.tsx'], refresh: true }), react(), tailwindcss()],
    resolve: { alias: { '@': path.resolve(__dirname, 'resources/js') } },
    test: { environment: 'jsdom', globals: true, setupFiles: ['resources/js/test/setup.ts'], include: ['resources/js/**/*.test.{ts,tsx}'] },
} as any);
