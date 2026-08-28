import { describe, expect, it } from 'vitest'
import type { UnitEconomicsParams } from './useUnitEconomics'
import { unitEconomicsPath, unitEconomicsQueryKey } from './useUnitEconomics'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

const BASE: UnitEconomicsParams = {
  days: 30,
  limit: 25,
  sort: 'revenue',
  direction: 'desc',
  cursor: null,
}

const withParams = (
  patch: Partial<UnitEconomicsParams>,
): UnitEconomicsParams => ({
  ...BASE,
  ...patch,
})

describe('ключ кэша юнит-экономики', () => {
  it('содержит companyId и различает компании', () => {
    // Проверяется ключ самого хука (CLAUDE.md §7): потерянный companyId
    // означал бы, что после переключения компании экран покажет чужую
    // выручку и чужие расходы — на экране, ради которого сюда и заходят.
    expect(unitEconomicsQueryKey(ONE, BASE)).toContain(ONE)
    expect(unitEconomicsQueryKey(ONE, BASE)).not.toEqual(
      unitEconomicsQueryKey(TWO, BASE),
    )
  })

  it('различает окна одной компании', () => {
    // Иначе переключение «7 дней / 30 дней» отдало бы прежние числа
    // из кэша, и клиент решил бы, что за неделю заработал столько же,
    // сколько за месяц.
    expect(unitEconomicsQueryKey(ONE, withParams({ days: 7 }))).not.toEqual(
      unitEconomicsQueryKey(ONE, BASE),
    )
  })

  it('различает страницы', () => {
    // Иначе вторая страница отдала бы первую из кэша, и «Дальше»
    // не двигало бы список.
    expect(
      unitEconomicsQueryKey(
        ONE,
        withParams({ cursor: 'revenue:desc:30:100:111' }),
      ),
    ).not.toEqual(unitEconomicsQueryKey(ONE, BASE))
  })

  it('различает размеры страницы', () => {
    expect(unitEconomicsQueryKey(ONE, withParams({ limit: 40 }))).not.toEqual(
      unitEconomicsQueryKey(ONE, BASE),
    )
  })

  it('различает порядок', () => {
    // Без этого клик по заголовку отдал бы прежний порядок из кэша:
    // стрелка переехала бы, а строки остались на месте.
    expect(
      unitEconomicsQueryKey(ONE, withParams({ sort: 'margin' })),
    ).not.toEqual(unitEconomicsQueryKey(ONE, BASE))
    expect(
      unitEconomicsQueryKey(ONE, withParams({ direction: 'asc' })),
    ).not.toEqual(unitEconomicsQueryKey(ONE, BASE))
  })
})

describe('строка запроса', () => {
  // Опечатка здесь молчит: бэкенд подставит умолчание, и экран окажется
  // отсортирован не так, как показывает стрелка. Компонентных тестов
  // у приложения нет, так что проверить это можно только здесь.
  it('несёт окно, размер страницы и порядок', () => {
    expect(unitEconomicsPath(BASE)).toBe(
      '/unit-economics?days=30&limit=25&sort=revenue&direction=desc',
    )
  })

  it('добавляет курсор только когда он есть', () => {
    expect(
      unitEconomicsPath(withParams({ cursor: 'margin:asc:30:-500:111' })),
    ).toBe(
      '/unit-economics?days=30&limit=25&sort=revenue&direction=desc&cursor=margin%3Aasc%3A30%3A-500%3A111',
    )
  })
})
