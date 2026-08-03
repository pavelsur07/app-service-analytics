import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  use: {
    baseURL: 'http://app.conwix.internal',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
})
