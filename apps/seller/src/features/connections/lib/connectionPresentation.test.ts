import { describe, expect, it } from 'vitest'
import { connectionPresentation, reportLabel } from './connectionPresentation'

describe('connectionPresentation', () => {
  it('называет сломанное подключение тем, что нужно сделать', () => {
    const broken = connectionPresentation('broken')

    // ADR-007 требует метку с указанием, что именно переподключить.
    // «broken» такой меткой не является: клиенту нужно действие,
    // а не имя состояния из нашей базы.
    expect(broken.tone).toBe('negative')
    expect(broken.label).toBe('Нужно переподключить')
    expect(broken.explanation).toContain('ключ')
  })

  it('различает работающее и отключённое', () => {
    expect(connectionPresentation('active').tone).toBe('positive')
    expect(connectionPresentation('revoked').tone).toBe('neutral')
  })

  it('не падает на неизвестном состоянии', () => {
    // Состояния добавляются на бэкенде раньше, чем на экране;
    // экран из-за этого падать не должен.
    expect(connectionPresentation('some_future_state').label).toBe(
      'some_future_state',
    )
  })
})

describe('reportLabel', () => {
  it('переводит типы выгрузок', () => {
    expect(reportLabel('ozon_posting_fbo_list')).toBe('Продажи')
    expect(reportLabel('ozon_product_list')).toBe('Каталог')
  })

  it('показывает незнакомый тип как есть', () => {
    // Коннекторов будет больше, и новый тип не повод ломать экран.
    expect(reportLabel('wb_orders')).toBe('wb_orders')
  })
})
