import { describe, expect, it } from 'vitest'
import { parseAmountToMinor } from './parseAmountToMinor'

describe('разбор суммы в минорные единицы', () => {
  it('переводит рубли с копейками без умножения на сто', () => {
    // Ровно тот случай, ради которого разбор строковый: 420.10 * 100
    // в двоичной плавающей точке даёт 42009.999999999996.
    expect(parseAmountToMinor('420,10')).toBe(42010)
    expect(parseAmountToMinor('420.10')).toBe(42010)
    expect(parseAmountToMinor('0,01')).toBe(1)
  })

  it('дополняет одну цифру дробной части до копеек', () => {
    // «420,5» человек имеет в виду как 420 рублей 50 копеек, а не 5.
    expect(parseAmountToMinor('420,5')).toBe(42050)
  })

  it('целое число считает рублями, а не копейками', () => {
    expect(parseAmountToMinor('420')).toBe(42000)
  })

  it('на нечисло отвечает null, а не нулём', () => {
    // Ноль — это тоже цена. Подставить его на опечатку означало бы
    // записать «себестоимость 0» вместо отказа, и прибыль стала бы
    // равна выручке минус расходы площадки.
    expect(parseAmountToMinor('')).toBeNull()
    expect(parseAmountToMinor('   ')).toBeNull()
    expect(parseAmountToMinor('четыреста')).toBeNull()
    expect(parseAmountToMinor('-420')).toBeNull()
    expect(parseAmountToMinor('420,105')).toBeNull()
  })

  it('ноль разбирает как ноль', () => {
    expect(parseAmountToMinor('0')).toBe(0)
  })
})
