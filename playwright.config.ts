import { defineConfig, devices } from '@playwright/test'

// Один конфиг на уровне репозитория, не по одному на приложение: сценарии
// нужны обоим (apps/seller, apps/admin), а Playwright поддерживает несколько
// целей одним прогоном через projects — testDir у каждого свой, baseURL
// свой, тесты полностью независимы (docs/structure.md).
export default defineConfig({
  fullyParallel: true,
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
