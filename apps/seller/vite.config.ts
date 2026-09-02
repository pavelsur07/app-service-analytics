import { fileURLToPath } from 'node:url'

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

const SMARTCAPTCHA_CLIENT_KEY_PATTERN = /^ysc1_[A-Za-z0-9_-]+$/

export default defineConfig(({ command }) => {
  const clientKey = process.env.VITE_SMARTCAPTCHA_CLIENT_KEY ?? ''

  if (command === 'build' && !SMARTCAPTCHA_CLIENT_KEY_PATTERN.test(clientKey)) {
    throw new Error('VITE_SMARTCAPTCHA_CLIENT_KEY must contain a valid public SmartCaptcha client key')
  }

  return {
    plugins: [tailwindcss()],
    resolve: {
      // Та же причина, что в tsconfig: packages/ui без своего node_modules,
      // и react ему отдаёт приложение. dedupe страхует от второй копии,
      // если зависимость всё же появится глубже.
      alias: {
        react: fileURLToPath(new URL('./node_modules/react', import.meta.url)),
      },
      dedupe: ['react', 'react-dom'],
    },
    test: {
      environment: 'node',
      // Мок-сервер по схеме OpenAPI поднимается на все тесты
      // (CLAUDE.md §10). Хендлеры задаёт каждый тест сам.
      setupFiles: ['./tests/msw/setup.ts'],
      // tests/e2e — Playwright, отдельный тест-раннер; Vitest его не трогает.
      exclude: ['node_modules/**', 'tests/e2e/**'],
    },
    server: {
      proxy: apiProxy,
    },
    preview: {
      proxy: apiProxy,
    },
  }
})
