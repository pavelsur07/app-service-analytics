import { expect, test } from '@playwright/test'

test('seller start screen shows data from its own endpoint', async ({
  page,
}) => {
  await page.goto('/')

  await expect(
    page.getByRole('heading', { name: 'Conwix — Seller' }),
  ).toBeVisible()
  await expect(page.getByText('app: conwix-seller-api')).toBeVisible()
})
