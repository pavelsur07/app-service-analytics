import type { components } from '../../../api/schema'

type Connection = components['schemas']['ConnectionResponse']

export interface DataFreshness {
  tone: 'neutral' | 'warning' | 'negative'
  text: string
}

/**
 * Порог «данные могли устареть». Кит называет 3 часа для продаж,
 * здесь 36 — то же число, что у серверного сторожа
 * (`NotifyStaleAccountsAction`, CLAUDE.md, «Наблюдаемость»).
 *
 * Причина не в осторожности: raw-документ дедуплицируется по содержимому,
 * и на тихом подключении соседние документы отстоят на сутки. Трёхчасовой
 * порог красил бы исправную синхронизацию в жёлтое каждый день, а индикатор,
 * который врёт, через неделю перестают читать. Два разных определения
 * свежести — в письме и в шапке — тоже нельзя: клиент сверяет их между собой.
 */
const STALE_AFTER_MS = 36 * 60 * 60 * 1000

// ponytail: берётся самая свежая загрузка любого типа отчёта, а не отдельно
// продажи и расходы. Индикатор в шапке отвечает на вопрос «связь жива?»;
// подробности по каждой выгрузке — экран подключений. Понадобится
// разделение — считать здесь по тем же типам, что и NotifyStaleAccountsAction.
function lastLoadedAt(connection: Connection): number | undefined {
  const times = Object.values(connection.lastLoadedAt)
    .map((at) => new Date(at).getTime())
    .filter((at) => Number.isFinite(at))

  return times.length === 0 ? undefined : Math.max(...times)
}

function formatAgo(ms: number): string {
  const minutes = Math.floor(ms / 60000)

  if (minutes < 60) {
    return `${Math.max(minutes, 1)} мин назад`
  }

  const hours = Math.floor(minutes / 60)

  return hours < 48 ? `${hours} ч назад` : `${Math.floor(hours / 24)} дн назад`
}

function formatAt(at: number): string {
  return new Date(at).toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/**
 * Свежесть данных одной строкой для шапки (кит, раздел 10: «индикатор
 * всегда рядом с датой периода, а не в футере страницы»).
 *
 * Состояние подключения важнее времени: сломанный ключ — это не «данные
 * постарели», а «загрузки больше нет», и молчаливая остановка синхронизации
 * запрещена (ADR-007). Отключённые подключения (`revoked`) не считаются
 * ни за свежесть, ни за поломку: их остановили намеренно.
 */
export function dataFreshness(
  connections: readonly Connection[],
  now: number,
): DataFreshness | undefined {
  if (connections.some((connection) => connection.state === 'broken')) {
    return { tone: 'negative', text: 'Синхронизация не проходит' }
  }

  const loads = connections
    .filter((connection) => connection.state === 'active')
    .map(lastLoadedAt)
    .filter((at): at is number => at !== undefined)

  if (loads.length === 0) {
    return undefined
  }

  const latest = Math.max(...loads)
  const age = now - latest

  return age > STALE_AFTER_MS
    ? {
        tone: 'warning',
        text: `Данные могли устареть · последняя сверка ${formatAgo(age)}`,
      }
    : {
        tone: 'neutral',
        text: `Обновлено ${formatAgo(age)} · ${formatAt(latest)}`,
      }
}
