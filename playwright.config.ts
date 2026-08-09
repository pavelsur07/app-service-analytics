import { defineConfig, devices } from '@playwright/test'

// Один конфиг на уровне репозитория, не по одному на приложение: сценарии
// нужны обоим (apps/seller, apps/admin), а Playwright поддерживает несколько
// целей одним прогоном через projects — testDir у каждого свой, baseURL
// свой, тесты полностью независимы (docs/structure.md).
export default defineConfig({
  fullyParallel: true,
  // Один воркер: с ADR-007 (auth) вход хэширует пароль bcrypt-стоимостью
  // 'auto' (медленно намеренно, when@test её снижает, но E2E идёт не через
  // test-окружение — через реальный php-fpm/nginx). Два параллельных
  // воркера — это два полных Chromium плюс два bcrypt-хэширования разом:
  // на не безграничном CPU (песочница, раннер CI) итоговое время запроса
  // подходит к nginx fastcgi_read_timeout (5s, docker/nginx/default.conf —
  // подобран для быстрого обнаружения мёртвого php-fpm, не для CPU-тяжёлых,
  // но живых запросов), и login зависает до таймаута. Само по себе
  // приложение конкурентный вход обрабатывает корректно (проверено curl'ом
  // двумя параллельными запросами вне Playwright — оба 200 за ~1.5s);
  // это исключительно теснота E2E-инфраструктуры на одной машине с тестом.
  workers: 1,
  // Следы прогонов — в var/, как у инструментов бэкенда (api/var/phpstan,
  // api/var/phpunit и остальные). По умолчанию Playwright пишет в
  // test-results/ в корне репозитория, и этот каталог приходилось
  // перечислять отдельно в .gitignore и .dockerignore; var/ там уже есть.
  outputDir: 'var/playwright',
  projects: [
    {
      name: 'seller',
      testDir: './apps/seller/tests/e2e',
      use: {
        ...devices['Desktop Chrome'],
        baseURL: 'http://app.conwix.internal',
      },
    },
    {
      name: 'admin',
      testDir: './apps/admin/tests/e2e',
      use: {
        ...devices['Desktop Chrome'],
        baseURL: 'http://admin.conwix.internal',
      },
    },
  ],
})
