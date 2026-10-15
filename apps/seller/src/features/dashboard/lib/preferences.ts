// Что главная помнит в браузере: скрытые виджеты, последний период
// и режим сравнения — отдельно для пользователя и компании
// («выбор сохранится для этого кабинета», макет).

import {
  preferenceFingerprint,
  readPreference,
  writePreference,
} from '../../../shared/lib/localPreferences'
import {
  COMPARE_MODES,
  DEFAULT_COMPARE,
  DEFAULT_PRESET,
  isValidDate,
  type PeriodSelection,
  PRESETS,
} from './period'
import { isWidgetId, type WidgetId } from './widgets'

export interface DashboardPreferences {
  hidden: readonly WidgetId[]
  selection: PeriodSelection
}

export const DEFAULT_PREFERENCES: DashboardPreferences = {
  hidden: [],
  selection: { preset: DEFAULT_PRESET, compare: DEFAULT_COMPARE, custom: null },
}

export function preferencesKey(userEmail: string, companyId: string): string {
  return `cwx.dashboard.v1:${preferenceFingerprint(userEmail)}:${companyId}`
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * Разбор сохранённого значения. Значение писал наш же код, но могла
 * писать и прошлая версия, и расширение браузера: всё незнакомое
 * отбрасывается поштучно, а не роняет экран.
 */
export function parsePreferences(raw: string | null): DashboardPreferences {
  if (raw === null) {
    return DEFAULT_PREFERENCES
  }

  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch {
    return DEFAULT_PREFERENCES
  }
  if (!isRecord(parsed)) {
    return DEFAULT_PREFERENCES
  }

  const hidden = Array.isArray(parsed.hidden)
    ? [...new Set(parsed.hidden.filter(isWidgetId))]
    : []
  const preset =
    PRESETS.find((item) => item.id === parsed.preset)?.id ?? DEFAULT_PRESET
  const compare =
    COMPARE_MODES.find((item) => item.id === parsed.compare)?.id ??
    DEFAULT_COMPARE
  const custom =
    isRecord(parsed.custom) &&
    typeof parsed.custom.from === 'string' &&
    typeof parsed.custom.to === 'string' &&
    isValidDate(parsed.custom.from) &&
    isValidDate(parsed.custom.to)
      ? { from: parsed.custom.from, to: parsed.custom.to }
      : null

  return {
    hidden,
    selection: {
      preset: preset === 'custom' && custom === null ? DEFAULT_PRESET : preset,
      compare,
      custom: preset === 'custom' ? custom : null,
    },
  }
}

export function loadPreferences(key: string): DashboardPreferences {
  return parsePreferences(readPreference(key))
}

export function savePreferences(
  key: string,
  preferences: DashboardPreferences,
): void {
  writePreference(
    key,
    JSON.stringify({
      hidden: preferences.hidden,
      preset: preferences.selection.preset,
      compare: preferences.selection.compare,
      custom: preferences.selection.custom,
    }),
  )
}
