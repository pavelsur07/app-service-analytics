import { defineConfig, devices } from '@playwright/test'

// Один конфиг на уровне репозитория, не по одному на приложение: сценарии
// нужны обоим (apps/seller, apps/admin), а Playwright поддерживает несколько
// целей одним прогоном через projects — testDir у каждого свой, baseURL
// свой, тесты полностью независимы (docs/structure.md).
export default defineConfig({
  fullyParallel: true,
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
