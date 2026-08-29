import { describe, expect, it } from 'vitest'
import { adminQueryKey } from './adminQueryKey'

// Ключ кэша системного контура. Проверяется не форма ради формы:
// ключ, собранный на месте вызова, однажды разойдётся с ключом
// инвалидации, и экран покажет несвежие данные — а линтер ловит только
// литеральный массив, не расхождение.
describe('adminQueryKey', () => {
  it('начинается с общего префикса контура', () => {
    expect(adminQueryKey('me')).toEqual(['admin', 'me'])
  })

  it('различает разные сущности', () => {
    expect(adminQueryKey('me')).not.toEqual(adminQueryKey('administrators'))
  })

  it('включает параметры в ключ', () => {
    expect(adminQueryKey('administrators', { page: 2 })).toEqual([
      'admin',
      'administrators',
      { page: 2 },
    ])
    expect(adminQueryKey('administrators', { page: 1 })).not.toEqual(
      adminQueryKey('administrators', { page: 2 }),
    )
  })
})
