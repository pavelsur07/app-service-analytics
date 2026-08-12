// Единственное место в приложении, знающее про API расширений браузера.
//
// Тип объявлен здесь, а не пакетом @types/chrome: странице доступны ровно
// два вызова, и тянуть полное описание всех API расширений ради них
// незачем (новая зависимость требует согласования — CLAUDE.md).
interface ExtensionBridge {
  readonly runtime?: {
    sendMessage(
      extensionId: string,
      message: unknown,
      callback: (response: unknown) => void,
    ): void
    readonly lastError?: { message?: string }
  }
}

export interface ConnectResult {
  readonly ok: boolean
  readonly companyName?: string
}

/**
 * Идентификатор расширения, которому будет отправлен токен. Пустая
 * строка означает «ещё не выпущен»: ключ в манифесте расширения тоже
 * пуст, и постоянного идентификатора у него пока нет.
 *
 * Заполняется одновременно с ключом в apps/extension/manifest.config.ts —
 * это одно и то же значение с двух сторон.
 */
const PINNED_EXTENSION_ID = ''

export type Recipient =
  | { readonly kind: 'pinned'; readonly id: string }
  // Расширение сообщило id само, и мы ему верим — только в разработке.
  | { readonly kind: 'discovered'; readonly id: string }
  | { readonly kind: 'not-installed' }
  // Боевая сборка без закреплённого id: отправлять токен некому,
  // потому что довериться DOM здесь нельзя.
  | { readonly kind: 'not-configured' }

/**
 * Кому отдавать токен.
 *
 * Идентификатор берётся из закреплённой константы, а не из DOM: атрибут
 * `data-conwix-extension-id` проставляет content-script нашего расширения,
 * но перезаписать его может content-script любого другого расширения
 * на этой же странице. Тогда приложение выпустило бы токен и отправило
 * его чужому расширению — и это была бы не кража сессии, а выдача
 * действующих учётных данных по доброй воле.
 *
 * В разработке закреплённого id ещё нет, поэтому там DOM используется
 * осознанно: в dev-сборке чужие расширения на localhost — не та угроза,
 * ради которой стоит блокировать работу. В боевой сборке без
 * закреплённого id подключение просто недоступно.
 */
export function extensionRecipient(): Recipient {
  const announced = document.documentElement.dataset.conwixExtensionId

  if (PINNED_EXTENSION_ID !== '') {
    return { kind: 'pinned', id: PINNED_EXTENSION_ID }
  }

  if (!import.meta.env.DEV) {
    return { kind: 'not-configured' }
  }

  return announced === undefined
    ? { kind: 'not-installed' }
    : { kind: 'discovered', id: announced }
}

/**
 * Передача выпущенного токена расширению (ADR-010). Канал —
 * externally_connectable, браузер сам проверяет, что страница с нашего
 * домена: токен не уходит ни в URL, ни в localStorage, ни через
 * postMessage, который прочитал бы любой скрипт на странице.
 */
export async function sendTokenToExtension(
  extensionId: string,
  token: string,
): Promise<ConnectResult> {
  const bridge = (window as unknown as { chrome?: ExtensionBridge }).chrome

  if (bridge?.runtime === undefined) {
    return { ok: false }
  }

  const runtime = bridge.runtime

  return new Promise<ConnectResult>((resolve) => {
    runtime.sendMessage(
      extensionId,
      { type: 'conwix:connect', token },
      (response) => {
        // lastError выставляется, когда расширение не ответило (удалено,
        // выключено, обновляется). Не прочитать его — предупреждение
        // в консоли; не обработать — «вечная загрузка» на экране.
        if (runtime.lastError !== undefined) {
          resolve({ ok: false })

          return
        }

        resolve(parseConnectResult(response))
      },
    )
  })
}

function parseConnectResult(value: unknown): ConnectResult {
  if (value === null || typeof value !== 'object') {
    return { ok: false }
  }

  const candidate = value as Record<string, unknown>
  if (candidate.ok !== true) {
    return { ok: false }
  }

  return typeof candidate.companyName === 'string'
    ? { ok: true, companyName: candidate.companyName }
    : { ok: true }
}
