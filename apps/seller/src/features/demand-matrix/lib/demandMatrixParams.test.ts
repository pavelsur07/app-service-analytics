import { describe, expect, it } from 'vitest'

import {
  demandMatrixSearchWithCursor,
  demandMatrixSearchWithText,
  parseDemandMatrixSearch,
} from './demandMatrixParams'

describe('параметры матрицы спроса в адресе', () => {
  it('поиск из ссылки — без пробелов по краям и не длиннее предела сервера', () => {
    expect(parseDemandMatrixSearch(new URLSearchParams(''))).toBe('')
    expect(
      parseDemandMatrixSearch(new URLSearchParams('search=%20abc%20')),
    ).toBe('abc')
    expect(
      parseDemandMatrixSearch(new URLSearchParams(`search=${'я'.repeat(120)}`)),
    ).toHaveLength(100)
  })

  it('новый поиск сбрасывает курсор, пустой — убирает параметр', () => {
    const current = new URLSearchParams('search=old&cursor=c1')

    expect(demandMatrixSearchWithText(current, ' new ').toString()).toBe(
      'search=new',
    )
    expect(demandMatrixSearchWithText(current, '   ').toString()).toBe('')
  })

  it('курсор пишется и убирается, поиск остаётся', () => {
    const current = new URLSearchParams('search=a')

    expect(demandMatrixSearchWithCursor(current, 'c2').toString()).toBe(
      'search=a&cursor=c2',
    )
    expect(
      demandMatrixSearchWithCursor(
        new URLSearchParams('search=a&cursor=c2'),
        null,
      ).toString(),
    ).toBe('search=a')
  })
})
