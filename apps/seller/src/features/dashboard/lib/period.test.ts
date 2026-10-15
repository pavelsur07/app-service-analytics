import { describe, expect, it } from 'vitest'

import {
  clampCustom,
  comparisonFor,
  dayCount,
  dayCountLabel,
  isPresetAvailable,
  isValidDate,
  parseSelection,
  type PeriodSelection,
  rangeFor,
  rangeLabel,
  searchWithSelection,
  todayAt,
} from './period'

const TODAY = '2026-10-15'

function selection(
  preset: PeriodSelection['preset'],
  compare: PeriodSelection['compare'] = 'prev',
  custom: PeriodSelection['custom'] = null,
): PeriodSelection {
  return { preset, compare, custom }
}

describe('диапазон пресета', () => {
  it('кончается вчера: начисления за сегодня неполные', () => {
    expect(rangeFor(selection('yesterday'), TODAY)).toEqual({
      from: '2026-10-14',
      to: '2026-10-14',
    })
    expect(rangeFor(selection('7d'), TODAY)).toEqual({
      from: '2026-10-08',
      to: '2026-10-14',
    })
    expect(dayCount(rangeFor(selection('30d'), TODAY))).toBe(30)
  })

  it('месяц, прошлый месяц и квартал — по календарю', () => {
    expect(rangeFor(selection('month'), TODAY)).toEqual({
      from: '2026-10-01',
      to: '2026-10-14',
    })
    expect(rangeFor(selection('prevMonth'), '2026-03-10')).toEqual({
      from: '2026-02-01',
      to: '2026-02-28',
    })
    expect(rangeFor(selection('prevMonth'), '2026-01-05')).toEqual({
      from: '2025-12-01',
      to: '2025-12-31',
    })
    expect(rangeFor(selection('quarter'), '2026-08-20')).toEqual({
      from: '2026-07-01',
      to: '2026-08-19',
    })
  })

  it('первого числа нет полных дней месяца и квартала', () => {
    expect(isPresetAvailable('month', '2026-10-01')).toBe(false)
    expect(isPresetAvailable('quarter', '2026-10-01')).toBe(false)
    expect(isPresetAvailable('quarter', '2026-11-01')).toBe(true)
    expect(isPresetAvailable('month', '2026-10-02')).toBe(true)
    expect(isPresetAvailable('7d', '2026-10-01')).toBe(true)
  })
})

describe('период сравнения', () => {
  it('предыдущий той же длины сразу перед выбранным', () => {
    const range = rangeFor(selection('7d'), TODAY)

    expect(comparisonFor(selection('7d'), range)).toEqual({
      from: '2026-10-01',
      to: '2026-10-07',
    })
  })

  it('начатый месяц — с тем же числом дней прошлого, не дальше его конца', () => {
    const october = { from: '2026-10-01', to: '2026-10-14' }
    expect(comparisonFor(selection('month'), october)).toEqual({
      from: '2026-09-01',
      to: '2026-09-14',
    })

    const march = { from: '2026-03-01', to: '2026-03-30' }
    expect(comparisonFor(selection('month'), march)).toEqual({
      from: '2026-02-01',
      to: '2026-02-28',
    })
  })

  it('начатый квартал — с тем же отрезком прошлого квартала', () => {
    expect(
      comparisonFor(selection('quarter'), {
        from: '2026-07-01',
        to: '2026-08-19',
      }),
    ).toEqual({ from: '2026-04-01', to: '2026-05-20' })
  })

  it('прошлый месяц — с позапрошлым целиком', () => {
    expect(
      comparisonFor(selection('prevMonth'), {
        from: '2026-09-01',
        to: '2026-09-30',
      }),
    ).toEqual({ from: '2026-08-01', to: '2026-08-31' })
  })

  it('год назад — те же даты, 29 февраля становится 28-м', () => {
    expect(
      comparisonFor(selection('custom', 'year'), {
        from: '2024-02-23',
        to: '2024-02-29',
      }),
    ).toEqual({ from: '2023-02-23', to: '2023-02-28' })
  })

  it('год назад не пересекается с годовым периодом', () => {
    const range = { from: '2024-03-01', to: '2025-03-01' }
    const comparison = comparisonFor(selection('custom', 'year'), range)

    expect(comparison.to < range.from).toBe(true)
  })
})

describe('свой период', () => {
  it('переворачивает, обрезает по вчера, по 2020 году и по 366 дням', () => {
    expect(
      clampCustom({ from: '2026-10-10', to: '2026-10-01' }, TODAY),
    ).toEqual({
      from: '2026-10-01',
      to: '2026-10-10',
    })
    expect(
      clampCustom({ from: '2026-10-10', to: '2026-12-31' }, TODAY),
    ).toEqual({
      from: '2026-10-10',
      to: '2026-10-14',
    })
    expect(
      clampCustom({ from: '2019-01-01', to: '2020-01-10' }, TODAY),
    ).toEqual({
      from: '2020-01-01',
      to: '2020-01-10',
    })
    const long = clampCustom({ from: '2024-01-01', to: '2026-01-01' }, TODAY)
    expect(dayCount(long)).toBe(366)
    expect(long.to).toBe('2026-01-01')
  })

  it('принимает только существующие даты', () => {
    expect(isValidDate('2024-02-29')).toBe(true)
    expect(isValidDate('2026-02-29')).toBe(false)
    expect(isValidDate('2026-2-01')).toBe(false)
    expect(isValidDate(null)).toBe(false)
  })
})

describe('адресная строка', () => {
  const fallback = selection('30d', 'year')

  it('мусор заменяется запасным выбором', () => {
    expect(
      parseSelection(
        new URLSearchParams('period=today&compare=x'),
        fallback,
        TODAY,
      ),
    ).toEqual(fallback)
  })

  it('свой период без дат — пресет по умолчанию', () => {
    expect(
      parseSelection(new URLSearchParams('period=custom'), fallback, TODAY)
        .preset,
    ).toBe('7d')
  })

  it('недоступный сегодня пресет — по умолчанию', () => {
    expect(
      parseSelection(
        new URLSearchParams('period=month'),
        fallback,
        '2026-10-01',
      ).preset,
    ).toBe('7d')
  })

  it('туда и обратно без потерь', () => {
    const chosen = selection('custom', 'year', {
      from: '2026-09-01',
      to: '2026-09-10',
    })
    const search = searchWithSelection(new URLSearchParams('keep=1'), chosen)

    expect(search.get('keep')).toBe('1')
    expect(parseSelection(search, fallback, TODAY)).toEqual(chosen)
    expect(searchWithSelection(search, selection('7d')).has('from')).toBe(false)
  })
})

describe('подписи', () => {
  it('диапазон и число дней', () => {
    expect(rangeLabel({ from: '2026-10-08', to: '2026-10-14' })).toBe(
      '8 окт – 14 окт 2026',
    )
    expect(rangeLabel({ from: '2025-12-29', to: '2026-01-04' })).toBe(
      '29 дек 2025 – 4 янв 2026',
    )
    expect(rangeLabel({ from: '2026-10-14', to: '2026-10-14' })).toBe(
      '14 окт 2026',
    )
    expect(dayCountLabel({ from: '2026-10-14', to: '2026-10-14' })).toBe(
      '1 день',
    )
    expect(dayCountLabel({ from: '2026-10-08', to: '2026-10-14' })).toBe(
      '7 дн.',
    )
  })

  it('сегодня — по Москве, а не по часовому поясу браузера', () => {
    // 22:30 UTC 14 октября — в Москве уже 15-е.
    expect(todayAt(new Date('2026-10-14T22:30:00Z'))).toBe('2026-10-15')
  })
})
