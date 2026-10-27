import { describe, expect, it } from 'vitest'
import { calendarKeyboardRow, calendarWindow } from './calendarWindow'

describe('bounded calendar DOM window', () => {
  it('covers all 500 records with bounded rows and exact spacer geometry', () => {
    for (const scroll of [0, 64, 640, 15000, 32000, 999999]) {
      const window = calendarWindow(500, scroll)
      expect(window.end - window.start).toBeLessThanOrEqual(10)
      expect(
        window.top + (window.end - window.start) * 64 + window.bottom,
      ).toBe(500 * 64)
      expect(window.start).toBeGreaterThanOrEqual(0)
      expect(window.end).toBeLessThanOrEqual(500)
    }
    expect(calendarWindow(500, 0).start).toBe(0)
    expect(calendarWindow(500, 32000).end).toBe(500)
    expect(calendarWindow(200, 0)).toEqual({
      start: 0,
      end: 200,
      top: 0,
      bottom: 0,
    })
    expect(calendarWindow(201, 0).end).toBeLessThan(201)
    expect(calendarWindow(0, 0)).toEqual({
      start: 0,
      end: 0,
      top: 0,
      bottom: 0,
    })
  })
  it('reaches first and last virtual rows by keyboard without leaving the dataset', () => {
    expect(calendarKeyboardRow('End', 0, 500)).toBe(499)
    expect(calendarKeyboardRow('Home', 499, 500)).toBe(0)
    expect(calendarKeyboardRow('ArrowDown', 499, 500)).toBe(499)
    expect(calendarKeyboardRow('ArrowUp', 0, 500)).toBe(0)
    expect(calendarKeyboardRow('PageDown', 197, 500)).toBe(203)
    expect(calendarKeyboardRow('PageUp', 203, 500)).toBe(197)
    expect(calendarKeyboardRow('Enter', 10, 500)).toBeNull()
    expect(calendarKeyboardRow('Home', 0, 0)).toBeNull()
  })
})
