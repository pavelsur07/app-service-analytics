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
 * Пока идентификатор не закреплён, доверять DOM разрешено только
 * на локальном домене разработки.
 */
export function extensionRecipient(): Recipient {
  return recipientFor(
    location.hostname,
    document.documentElement.dataset.conwixExtensionId,
    PINNED_EXTENSION_ID,
  )
}

/**
 * Отделено от DOM ради проверяемости: это решение о том, кому уходит
 * действующий секрет, и оно обязано быть покрыто тестом, а не
 * подтверждаться чтением.
 *
 * Признак разработки — сам адрес страницы, а не режим сборки. По режиму
 * не выходит: `app.conwix.localhost` отдаёт nginx из `apps/seller/dist`,
 * то есть production-сборку, и `import.meta.env.DEV` там false.
 * Проверка по адресу отвечает на настоящий вопрос — «эта страница
 * локальная?» — а не на косвенный.
 */
export function recipientFor(
  hostname: string,
  announcedId: string | undefined,
  pinnedId: string,
): Recipient {
  if (pinnedId !== '') {
    return { kind: 'pinned', id: pinnedId }
  }

  if (!isLocalHostname(hostname)) {
    return { kind: 'not-configured' }
  }

  // Пустая строка — это тоже «не сообщил»: атрибут может оказаться
  // в разметке пустым, и отправлять токен «расширению с пустым id»
  // означало бы показать клиенту ошибку подключения вместо честного
  // «не установлено».
  return announcedId === undefined || announcedId === ''
    ? { kind: 'not-installed' }
    : { kind: 'discovered', id: announcedId }
}

/**
 * Только настоящий локальный домен. Проверка по концу строки с точкой
 * перед ним — иначе `app.conwix.localhost.evil.example` сошёл бы
 * за локальный и получил бы токен.
 */
function isLocalHostname(hostname: string): boolean {
  return hostname === 'localhost' || hostname.endsWith('.localhost')
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
