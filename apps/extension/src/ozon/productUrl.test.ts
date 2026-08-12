import { describe, expect, it } from 'vitest'

import { ozonProductIdFromUrl } from './productUrl'

describe('идентификатор товара из адреса карточки Ozon', () => {
  it('берёт цифры из хвоста слага', () => {
    expect(
      ozonProductIdFromUrl(
        'https://www.ozon.ru/product/nazvanie-tovara-1988146647/',
      ),
    ).toBe('1988146647')
  })

  it('не спотыкается о хвост запроса и якорь', () => {
    // Реальные адреса приходят с utm, идентификатором рекламы, якорем —
    // всё это к идентификатору товара отношения не имеет.
    expect(
      ozonProductIdFromUrl(
        'https://www.ozon.ru/product/tovar-2029261620/?advert=abc&utm_source=x#section-description',
      ),
    ).toBe('2029261620')
  })

  it('работает и без www, и без завершающего слэша', () => {
    expect(
      ozonProductIdFromUrl('https://ozon.ru/product/tovar-220280923'),
    ).toBe('220280923')
  })

  it('переживает слаг с цифрами внутри', () => {
    // «-3-litra-» посреди названия не должно перебить настоящий хвост.
    expect(
      ozonProductIdFromUrl(
        'https://www.ozon.ru/product/banka-3-litra-2658723651/',
      ),
    ).toBe('2658723651')
  })

  it('отличает карточку от остальных страниц Ozon', () => {
    expect(
      ozonProductIdFromUrl('https://www.ozon.ru/category/noutbuki-15692/'),
    ).toBeNull()
    expect(
      ozonProductIdFromUrl('https://www.ozon.ru/search/?text=noutbuk'),
    ).toBeNull()
    expect(ozonProductIdFromUrl('https://www.ozon.ru/')).toBeNull()
  })

  it('кабинет продавца карточкой не считается', () => {
    // seller.ozon.ru — другой сайт по смыслу, и оверлею там делать нечего.
    expect(
      ozonProductIdFromUrl('https://seller.ozon.ru/product/tovar-1988146647/'),
    ).toBeNull()
  })

  it('чужие домены отбрасываются целиком', () => {
    // Защита от адреса, который лишь выглядит как ozon.ru.
    expect(
      ozonProductIdFromUrl(
        'https://ozon.ru.evil.example/product/tovar-1988146647/',
      ),
    ).toBeNull()
    expect(
      ozonProductIdFromUrl(
        'https://www.wildberries.ru/product/tovar-1988146647/',
      ),
    ).toBeNull()
  })

  it('мусор вместо адреса — это null, а не исключение', () => {
    expect(ozonProductIdFromUrl('не адрес')).toBeNull()
    expect(ozonProductIdFromUrl('')).toBeNull()
  })
})
