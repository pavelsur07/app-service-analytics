import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import {
  actionText,
  adsError,
  budgetInput,
  moscowTime,
  observationStatus,
  parseBudget,
  parseTab,
  reasonText,
  uncertainWrite,
  validCampaign,
  validInterval,
  validWindow,
} from './presentation'
import { samplePolicy } from './policy.fixture'

describe('наблюдение Ads: деньги и качество', () => {
  it('переводит десятичный ввод точно, включая предел JS, не округляет лишние копейки', () => {
    for (const [input, minor] of [
      ['1,03', 103],
      ['4.63', 463],
      ['0.01', 1],
      ['90071992547409.91', Number.MAX_SAFE_INTEGER],
      [' 100 ', 10000],
    ] as const)
      expect(parseBudget(input)).toBe(minor)
    for (const text of [
      '',
      '0',
      '-1',
      '+1',
      '1e3',
      'NaN',
      'Infinity',
      '1.001',
      '01',
      '90071992547409.92',
    ])
      expect(parseBudget(text)).toBeNull()
    expect(budgetInput(Number.MAX_SAFE_INTEGER)).toBe('90071992547409.91')
    expect(budgetInput(103)).toBe('1.03')
    expect(budgetInput(-1)).toBe('')
  })
  it('различает пустоту, сохранённую версию, устаревшую и вчерашнюю проекцию', () => {
    const now = Date.parse('2026-11-08T10:01:00Z')
    const p = samplePolicy()
    expect(observationStatus(p, now)).toBe('Расчёт запуска заблокирован')
    expect(observationStatus({ ...p, observation: null }, now)).toBe(
      'Ожидается первый расчёт',
    )
    expect(observationStatus({ ...p, deliveryStatus: 'saved' }, now)).toContain(
      'ещё не применена',
    )
    expect(observationStatus(p, now + 800_000)).toContain('Давно нет')
    expect(observationStatus({ ...p, enabled: false }, now)).toBe(
      'Наблюдение выключено',
    )
    const o = p.observation
    if (o === null) throw new Error('fixture')
    expect(
      observationStatus(
        { ...p, observation: { ...o, day: '2026-11-07' } },
        now,
      ),
    ).toContain('Давно нет')
    expect(
      observationStatus(
        { ...p, observation: { ...o, evaluatedAt: '2026-11-08T11:00:00Z' } },
        now,
      ),
    ).toContain('Давно нет')
    expect(actionText('would_stop')).toBe('Предполагаемая остановка')
    expect(reasonText('unknown-provider-secret')).not.toContain('secret')
  })
  it('валидирует расписание, интервалы и ID без преобразования ID в Number', () => {
    expect(validWindow('09:00', '21:00')).toBe(true)
    for (const [a, b] of [
      ['21:00', '09:00'],
      ['09:00', '09:00'],
      ['09:60', '21:00'],
      ['24:00', '24:01'],
    ])
      expect(validWindow(a ?? '', b ?? '')).toBe(false)
    expect(validCampaign('9007199254740991999')).toBe(true)
    expect(validCampaign('01')).toBe(false)
    expect(validInterval('600')).toBe(600)
    expect(validInterval('86400')).toBe(86400)
    expect(validInterval('599')).toBeNull()
    expect(validInterval('600.0')).toBeNull()
    expect(parseTab(null)).toBe('campaigns')
    expect(parseTab('history')).toBe('history')
    expect(parseTab('other')).toBe('campaigns')
    expect(moscowTime('invalid')).toBe('Время неизвестно')
  })
  it('показывает безопасные ошибки, различает неопределённую запись и 409', () => {
    for (const status of [401, 403, 404, 422, 429, 503])
      expect(
        adsError(new ApiError(status, null, 'private-secret')),
      ).not.toContain('private-secret')
    expect(
      adsError(new ApiError(409, 'version_conflict', 'private')),
    ).toContain('актуальную версию')
    expect(
      uncertainWrite(new ApiError(409, 'version_conflict', 'private')),
    ).toBe(false)
    expect(uncertainWrite(new Error('network'))).toBe(true)
    expect(uncertainWrite(new ApiError(503, null, 'private'))).toBe(true)
  })
})
