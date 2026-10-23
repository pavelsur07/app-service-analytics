import { describe, expect, it } from 'vitest'

import { ozonProductUrl } from './ozonProductUrl'

describe('ozonProductUrl', () => {
  it('строит прямую ссылку по SKU Ozon без названия товара', () => {
    expect(ozonProductUrl('2029269084')).toBe(
      'https://www.ozon.ru/product/2029269084/',
    )
  })

  it('сохраняет длинный SKU без потери точности', () => {
    expect(ozonProductUrl('9007199254740993')).toBe(
      'https://www.ozon.ru/product/9007199254740993/',
    )
  })

  it.each([
    null,
    undefined,
    '',
    '0',
    '0123',
    '-123',
    '1.5',
    '1e10',
    ' 2029269084',
    '2029269084\n',
    'артикул-продавца',
    '2029269084/other',
    '2029269084?redirect=example.com',
    'https://example.com',
  ])('не создаёт ссылку для некорректного SKU %j', (sku) => {
    expect(ozonProductUrl(sku)).toBeNull()
  })
})
