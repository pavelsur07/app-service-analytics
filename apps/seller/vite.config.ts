import { fileURLToPath } from 'node:url'

import { defineConfig, type Plugin } from 'vite'
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

// Плагин с apply: 'build', а не проверка в теле defineConfig: конфиг
// читают и инструменты без сборки (knip загружает его в режиме build,
// хуков плагинов не вызывает). Исключение в теле конфига без ключа
// в окружении роняло загрузку целиком, и knip молча терял setupFiles
// Vitest (#186). Сборка без ключа по-прежнему отказывает.
function requireSmartCaptchaClientKey(): Plugin {
  return {
    name: 'require-smartcaptcha-client-key',
    apply: 'build',
    configResolved() {
      const clientKey = process.env.VITE_SMARTCAPTCHA_CLIENT_KEY ?? ''
      if (!SMARTCAPTCHA_CLIENT_KEY_PATTERN.test(clientKey)) {
        throw new Error(
          'VITE_SMARTCAPTCHA_CLIENT_KEY must contain a valid public SmartCaptcha client key',
        )
      }
    },
  }
}

export default defineConfig({
  plugins: [tailwindcss(), requireSmartCaptchaClientKey()],
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
})
