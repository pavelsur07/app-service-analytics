import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

// dev-сервер и `vite preview` бьют /api в nginx (тот же путь nginx→php-fpm,
// что в проде), с явным Host: nginx маршрутизирует по server_name, без
// него зайдёт на не тот vhost или в никуда.
const apiProxy = {
  '/api': {
    target: 'http://nginx',
    headers: { Host: 'app.conwix.localhost' },
  },
}

export default defineConfig({
  plugins: [tailwindcss()],
  test: {
    environment: 'node',
    // tests/e2e — Playwright, отдельный тест-раннер; Vitest его не трогает.
    exclude: ['node_modules/**', 'tests/e2e/**'],
  },
  server: {
    proxy: apiProxy,
  },
  preview: {
    proxy: apiProxy,
  },
})
