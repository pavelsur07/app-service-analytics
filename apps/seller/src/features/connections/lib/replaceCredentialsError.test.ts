import { describe, expect, it } from 'vitest'
import { replaceCredentialsFailure } from './replaceCredentialsError'

describe('replaceCredentialsFailure', () => {
  it('на отказ площадки не предлагает обновлять страницу', () => {
    // Ключ не подошёл — данные на экране верны, перечитывать нечего.
    // Лишний refetch здесь сбросил бы форму, в которой человек уже
    // исправляет ключ.
    const failure = replaceCredentialsFailure('credentials_rejected')

    expect(failure.refetch).toBe(false)
    expect(failure.title).toBe('Площадка не приняла ключ')
  })

  it('на конфликт версий требует перечитать список', () => {
    // Версия в форме устарела: повторная отправка снова упрётся
    // в конфликт, пока экран не обновит данные (ADR-008).
    expect(replaceCredentialsFailure('version_conflict').refetch).toBe(true)
  })

  it('объясняет чужой кабинет через последствие, а не через код', () => {
    const failure = replaceCredentialsFailure('credentials_of_another_cabinet')

    expect(failure.description).toContain('другого магазина')
  })

  // Та же расширенная проба, что у подключения: замена ключа, прошедшего
  // только товарную область, оживила бы сломанное подключение на секунды.
  it.each([
    ['credentials_rejected_sales', 'продажи'],
    ['credentials_rejected_expenses', 'финансы'],
    ['credentials_rejected_returns', 'возвраты'],
  ] as const)(
    'называет область для кода %s и не требует refetch',
    (code, area) => {
      const failure = replaceCredentialsFailure(code)

      expect(failure.title).toContain(area)
      expect(failure.refetch).toBe(false)
    },
  )

  it('незнакомый код не обещает, что старый ключ на месте', () => {
    // Неизвестно, дошёл ли запрос: сеть могла упасть после сохранения.
    const failure = replaceCredentialsFailure('some_future_code')

    expect(failure.title).toBe('Не удалось заменить ключ')
    expect(failure.refetch).toBe(true)
  })

  it('ответ без тела разбирается так же, как незнакомый код', () => {
    // parseApiError отдаёт code = null, когда тела нет (прокси вернул
    // HTML, соединение оборвалось).
    expect(replaceCredentialsFailure(null).refetch).toBe(true)
  })
})
