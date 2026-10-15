// Единственная точка доступа к localStorage (CLAUDE.md §10: только ключи
// из разрешённого списка). Линтер запрещает localStorage во всех
// остальных файлах; здесь — проверка префикса ключа.
//
// Только удобства одного браузера: что пользователь скрыл, какой период
// выбрал последним. Данные компании сюда не пишутся — они переживали бы
// смену пользователя на общем компьютере (docs/patterns.md).
//
// Хранилище может отсутствовать (Node в тестах, приватный режим)
// или бросать (квота, запрет сайта): тогда чтение — «нет значения»,
// запись — молча ничего, а экран работает на умолчаниях.

const ALLOWED_PREFIXES = [
  // Главная: скрытые виджеты, последний период и режим сравнения
  // (docs/plan/dashboard-widgets.md). Пользователь и компания — в ключе.
  'cwx.dashboard.v1:',
] as const

function assertAllowed(key: string): void {
  if (!ALLOWED_PREFIXES.some((prefix) => key.startsWith(prefix))) {
    throw new Error(`Ключ localStorage вне разрешённого списка: ${key}`)
  }
}

function storage(): Storage | null {
  try {
    return typeof localStorage === 'undefined' ? null : localStorage
  } catch {
    return null
  }
}

export function readPreference(key: string): string | null {
  assertAllowed(key)
  try {
    return storage()?.getItem(key) ?? null
  } catch {
    return null
  }
}

export function writePreference(key: string, value: string): void {
  assertAllowed(key)
  try {
    storage()?.setItem(key, value)
  } catch {
    // Квота или запрет сайта: настройка не сохранится, экран работает.
  }
}

/**
 * Короткий отпечаток строки для ключа (FNV-1a, 32 бита) — чтобы в имени
 * ключа не лежал адрес почты. Не защита: разделить настройки двух
 * пользователей одного браузера, а не скрыть, кто они.
 */
export function preferenceFingerprint(value: string): string {
  let hash = 0x811c9dc5
  for (let index = 0; index < value.length; index += 1) {
    hash ^= value.charCodeAt(index)
    hash = Math.imul(hash, 0x01000193) >>> 0
  }

  return hash.toString(16).padStart(8, '0')
}
