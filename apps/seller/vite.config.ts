import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [tailwindcss()],
  test: {
    environment: 'node',
    // tests/e2e — Playwright, отдельный тест-раннер; Vitest его не трогает.
    exclude: ['node_modules/**', 'tests/e2e/**'],
  },
  preview: {
    // Playwright запускает `vite preview` и бьёт по нему напрямую —
    // /api проксируется в nginx (тот же путь nginx→php-fpm, что в проде),
    // с явным Host: nginx маршрутизирует по server_name, без него зайдёт
    // на не тот vhost или в никуда.
    proxy: {
      '/api': {
        target: 'http://nginx',
        headers: { Host: 'app.conwix.localhost' },
      },
    },
  },
})
