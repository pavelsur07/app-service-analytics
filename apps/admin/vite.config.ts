import { fileURLToPath } from 'node:url'

import { defineConfig } from 'vite'
import tailwindcss from '@tailwindcss/vite'

// dev-сервер бьёт /api в nginx (тот же путь nginx→php-fpm, что в проде),
// с явным Host: nginx маршрутизирует по server_name, без него зайдёт
// на не тот vhost или в никуда.
const apiProxy = {
  '/api': {
    target: 'http://nginx',
    headers: { Host: 'admin.conwix.localhost' },
  },
}

export default defineConfig({
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
    // Мок-сервер по схеме OpenAPI поднимается для всех тестов
    // (CLAUDE.md §10): без него запрос без хендлера ушёл бы в сеть.
    setupFiles: ['./tests/msw/setup.ts'],
    // Файлы делят воркер и кэш модулей: без этого подготовка каждого файла
    // (msw, импорты) занимала ~85% прогона. Глобальные подмены поэтому
    // откатываются механически, а не договорённостью: stubGlobal/stubEnv
    // и spyOn — опциями ниже, таймеры — в tests/msw/setup.ts.
    // vi.mock так не откатывается: он подменяет модуль в общем кэше
    // воркера, и его применение зависит от того, кто загрузил модуль
    // раньше. Файлу с vi.mock нужен отдельный проект с isolate: true.
    isolate: false,
    unstubGlobals: true,
    unstubEnvs: true,
    restoreMocks: true,
    // tests/e2e — Playwright, отдельный тест-раннер; Vitest его не трогает.
    exclude: ['node_modules/**', 'tests/e2e/**'],
  },
  server: {
    proxy: apiProxy,
  },
})
