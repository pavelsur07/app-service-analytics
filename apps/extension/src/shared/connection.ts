// Состояние подключения расширения к Conwix: токен и компания, к которой
// он привязан (ADR-010). Хранится в chrome.storage.local, потому что
// chrome.storage.session не переживает перезапуск браузера, а требовать
// подключения каждое утро клиент не простит.

export interface Connection {
  readonly token: string
  readonly companyId: string
  readonly companyName: string
}

const KEY = 'connection'

// Обёртка над chrome.storage, а не прямые вызовы по коду: в тестах
// подменяется объектом на десяток строк, без библиотеки моков.
export interface Storage {
  get(keys: string[]): Promise<Record<string, unknown>>
  set(items: Record<string, unknown>): Promise<void>
  clear(): Promise<void>
}

export function browserStorage(): Storage {
  return chrome.storage.local
}

export async function readConnection(
  storage: Storage,
): Promise<Connection | null> {
  const stored = await storage.get([KEY])

  return parseConnection(stored[KEY])
}

/**
 * Запись подключения стирает хранилище целиком — `clear()`, а не удаление
 * одного ключа. Причина — CLAUDE.md §7: расширение переподключают
 * к другой компании, и всё, что осталось от прежней, обязано исчезнуть
 * в тот же момент. Удаление по списку известных ключей означало бы, что
 * каждый будущий кэш надо не забыть добавить в этот список, — а забытый
 * ключ отдал бы данные предыдущей компании, и выглядело бы это как
 * работающее подключение.
 *
 * Ничего, кроме подключения и производных от него кэшей, расширение
 * в storage не держит, поэтому терять при очистке нечего.
 */
export async function writeConnection(
  storage: Storage,
  connection: Connection,
): Promise<void> {
  await storage.clear()
  await storage.set({ [KEY]: connection })
}

export async function clearConnection(storage: Storage): Promise<void> {
  await storage.clear()
}

/**
 * Разбор с проверкой формы: в storage могло остаться что угодно
 * от предыдущей версии расширения, и старая запись не должна
 * притворяться живым подключением.
 */
export function parseConnection(value: unknown): Connection | null {
  if (null === value || 'object' !== typeof value) {
    return null
  }

  const candidate = value as Record<string, unknown>
  const { token, companyId, companyName } = candidate

  if ('string' !== typeof token || '' === token) {
    return null
  }
  if ('string' !== typeof companyId || '' === companyId) {
    return null
  }
  if ('string' !== typeof companyName) {
    return null
  }

  return { token, companyId, companyName }
}
