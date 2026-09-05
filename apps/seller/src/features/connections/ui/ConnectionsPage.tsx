import { useEffect } from 'react'
import { CircleX, PlugZap } from 'lucide-react'
import { useNavigate, useParams } from 'react-router'
import { ApiError } from '../../../api/ApiError'
import {
  Badge,
  Button,
  Card,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import {
  connectionPresentation,
  reportLabel,
} from '../lib/connectionPresentation'
import { useConnections } from '../model/useConnections'
import { onboardingPathToAddAnotherCabinet } from '../../../shared/lib/onboardingIntent'
import { ReplaceCredentialsForm } from './ReplaceCredentialsForm'

// Экран отвечает на два вопроса, на которые ответить было негде:
// «данные вообще обновляются?» и «что означает письмо о сломанном
// подключении?». Второй — прямое требование ADR-007: уведомление без
// места, куда прийти, обрывается на письме.
export function ConnectionsPage() {
  const navigate = useNavigate()
  const { companyId } = useParams<{ companyId: string }>()

  const query = useConnections(companyId ?? '', {
    enabled: companyId !== undefined,
  })

  // 403 — не тихий пустой экран: companyId в адресе не означает доступ.
  useEffect(() => {
    if (query.error instanceof ApiError && query.error.status === 403) {
      void navigate('/companies', { replace: true })
    }
  }, [query.error, navigate])

  if (companyId === undefined) {
    return (
      <div>
        <Card tone="negative">
          <StatusPanel
            description="В адресе не указан companyId."
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Некорректный адрес"
            tone="negative"
          />
        </Card>
      </div>
    )
  }

  return (
    <div>
      <div className="flex flex-col gap-4">
        <div className="flex items-center justify-between gap-2">
          <h1 className="text-xl font-semibold">Подключения</h1>

          {/* Действие над списком, не над карточкой конкретного
              подключения — доступно всегда, когда список прочитан
              (а не только когда он пуст): второй кабинет — норма,
              не исключение, и подключить его нужно и компании,
              у которой уже есть рабочее подключение. Адрес несёт
              осознанное намерение (`intent`), а не просто companyId:
              без него `resolveOnboardingDecision` увела бы обратно
              сюда же — тот самый тупик, который и защищает признак. */}
          {query.status === 'success' && (
            <Button
              type="button"
              variant="primary"
              size="compact"
              onClick={() => {
                void navigate(onboardingPathToAddAnotherCabinet(companyId))
              }}
            >
              Подключить кабинет
            </Button>
          )}
        </div>

        {query.status === 'pending' && (
          <Card>
            <div className="h-24 animate-pulse rounded bg-border-subtle" />
          </Card>
        )}

        {query.status === 'error' && (
          <Card tone="negative">
            <StatusPanel
              action={
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  onClick={() => {
                    void query.refetch()
                  }}
                >
                  Повторить
                </Button>
              }
              description={
                query.error instanceof Error
                  ? query.error.message
                  : 'Неизвестная ошибка'
              }
              icon={<CircleX aria-hidden="true" size={20} />}
              role="alert"
              title="Не удалось загрузить подключения"
              tone="negative"
            />
          </Card>
        )}

        {query.status === 'success' && query.data.connections.length === 0 && (
          <Card>
            {/* Действие «Подключить кабинет» теперь одно и живёт в шапке
                выше — не здесь: оно уже не отличается от того же действия
                для компании со списком подключений. */}
            <StatusPanel
              description="Подключите кабинет Ozon сами — ключи проверяются сразу, и данные начнут загружаться, как только площадка их примет."
              icon={<PlugZap aria-hidden="true" size={20} />}
              title="Подключений пока нет"
              tone="neutral"
            />
          </Card>
        )}

        {query.status === 'success' &&
          query.data.connections.map((connection) => {
            const state = connectionPresentation(connection.state)
            const loads = Object.entries(connection.lastLoadedAt)

            return (
              <Card key={connection.id}>
                <div className="flex flex-col gap-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium capitalize">
                      {connection.marketplace}
                    </span>
                    <span className="text-sm text-text-muted">
                      магазин {connection.externalShopId}
                    </span>
                    <Badge tone={state.tone}>{state.label}</Badge>
                  </div>

                  <p className="text-sm">{state.explanation}</p>

                  <dl className="flex flex-wrap gap-x-8 gap-y-1 text-sm">
                    {loads.length === 0 && (
                      <div>
                        <dt className="text-text-muted">Загрузки</dt>
                        {/* Пусто и у только что заведённого подключения,
                            и у сломанного с первого дня: различает их
                            состояние выше, а не эта строка. */}
                        <dd>ещё не было</dd>
                      </div>
                    )}
                    {loads.map(([reportType, at]) => (
                      <div key={reportType}>
                        <dt className="text-text-muted">
                          {reportLabel(reportType)}
                        </dt>
                        <dd>
                          <time dateTime={at}>
                            {new Date(at).toLocaleString('ru-RU')}
                          </time>
                        </dd>
                      </div>
                    ))}
                    <div>
                      <dt className="text-text-muted">Подключено</dt>
                      <dd>
                        <time dateTime={connection.createdAt}>
                          {new Date(connection.createdAt).toLocaleDateString(
                            'ru-RU',
                          )}
                        </time>
                      </dd>
                    </div>
                  </dl>

                  {/* Отключённому подключению замена ключа не помогает:
                      отзыв необратим (ADR-011), и предлагать действие,
                      которое сервер отвергнет, — обман. */}
                  {connection.state !== 'revoked' && (
                    <ReplaceCredentialsForm
                      companyId={companyId}
                      externalShopId={connection.externalShopId}
                      marketplaceAccountId={connection.id}
                      version={connection.version}
                    />
                  )}
                </div>
              </Card>
            )
          })}
      </div>
    </div>
  )
}
