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
  remove(keys: string[]): Promise<void>
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
 * Запись подключения всегда стирает хранилище целиком, а не дописывает
 * поверх. Причина — CLAUDE.md §7: расширение переподключают к другой
 * компании, и всё, что осталось от прежней, обязано исчезнуть в тот же
 * момент. Дописывание оставило бы кэш предыдущей компании живым,
 * а выглядело бы это как работающее подключение.
 */
export async function writeConnection(
  storage: Storage,
  connection: Connection,
): Promise<void> {
  await storage.remove([KEY])
  await storage.set({ [KEY]: connection })
}

export async function clearConnection(storage: Storage): Promise<void> {
  await storage.remove([KEY])
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
