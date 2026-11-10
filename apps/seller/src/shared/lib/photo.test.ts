import { describe, expect, it } from 'vitest'
import { thumbnailUrl } from './photo'

describe('thumbnailUrl', () => {
  it('вставляет размер перед именем файла', () => {
    expect(
      thumbnailUrl('https://ir.ozone.ru/s3/multimedia-1-h/11107018133.jpg'),
    ).toBe('https://ir.ozone.ru/s3/multimedia-1-h/wc100/11107018133.jpg')
  })

  it('не зависит от шарда бакета', () => {
    // Шард гуляет от карточки к карточке: в снятой фикстуре встречаются
    // и multimedia-1-h, и multimedia-x. Подстановка не должна его знать.
    expect(
      thumbnailUrl('https://ir.ozone.ru/s3/multimedia-x/7801304386.jpg'),
    ).toBe('https://ir.ozone.ru/s3/multimedia-x/wc100/7801304386.jpg')
    expect(
      thumbnailUrl('https://ir.ozone.ru/s3/multimedia-n/7708167474.jpg'),
    ).toBe('https://ir.ozone.ru/s3/multimedia-n/wc100/7708167474.jpg')
  })

  it('нет адреса — нет превью', () => {
    expect(thumbnailUrl(null)).toBeNull()
    expect(thumbnailUrl(undefined)).toBeNull()
    expect(thumbnailUrl('')).toBeNull()
  })

  it('не вставляет размер второй раз', () => {
    const once = 'https://ir.ozone.ru/s3/multimedia-1-h/wc100/11107018133.jpg'

    expect(thumbnailUrl(once)).toBe(once)
  })

  it('трогает только проверенный CDN, остальное отдаёт как есть', () => {
    // Сегмент размера замерен ровно на ir.ozone.ru/s3. Вставлять его
    // куда попало значит однажды превратить рабочий адрес чужого CDN
    // в битый — и заметит это клиент пустыми квадратами. Лучше
    // оригинал во всю ширину, чем ничего.
    expect(thumbnailUrl('https://example.test')).toBe('https://example.test')
    expect(thumbnailUrl('https://cdn.example/image.jpg')).toBe(
      'https://cdn.example/image.jpg',
    )
    expect(thumbnailUrl('https://ir.ozone.ru/other/11107018133.jpg')).toBe(
      'https://ir.ozone.ru/other/11107018133.jpg',
    )
  })
})
