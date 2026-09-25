import { describe, expect, it } from 'vitest'
import { connectAdvertisingFailure } from './connectAdvertisingError'

describe('connectAdvertisingFailure', () => {
  it('на отказ ключа не перечитывает список и объясняет, где взять ключ', () => {
    // Ключ не подошёл — данные на экране верны, а лишний refetch
    // сбросил бы форму, в которой человек исправляет ключ.
    const failure = connectAdvertisingFailure(
      'advertising_credentials_rejected',
    )

    expect(failure.refetch).toBe(false)
    expect(failure.description).toContain('Performance API')
  })

  it('объясняет чужой кабинет через последствие', () => {
    const failure = connectAdvertisingFailure(
      'advertising_credentials_of_another_cabinet',
    )

    expect(failure.description).toContain('другого магазина')
    expect(failure.refetch).toBe(false)
  })

  it.each(['version_conflict', 'connection_revoked', 'connection_not_found'])(
    'на %s требует перечитать список',
    (code) => {
      expect(connectAdvertisingFailure(code).refetch).toBe(true)
    },
  )

  it('на незнакомый код не обещает, что ничего не изменилось', () => {
    const failure = connectAdvertisingFailure('something_new')

    expect(failure.refetch).toBe(true)
    expect(failure.title).toBe('Не удалось сохранить рекламный ключ')
  })
})
