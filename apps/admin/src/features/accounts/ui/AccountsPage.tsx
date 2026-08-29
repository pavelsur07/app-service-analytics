import { useState } from 'react'
import { CircleAlert, CircleX, LoaderCircle } from 'lucide-react'
import {
  Badge,
  Button,
  Card,
  StatusPanel,
} from '../../../../../../packages/ui/src'
import { useClientAccounts } from '../model/useClientAccounts'
import { useSetAccountStatus } from '../model/useSetAccountStatus'
import { RegisterAccountForm } from './RegisterAccountForm'

// Экран управления клиентскими аккаунтами (ADR-017). Доступен обеим
// ролям контура — в отличие от заведения администраторов.
export function AccountsPage() {
  const [page, setPage] = useState(1)
  const accounts = useClientAccounts(page)
  const setStatus = useSetAccountStatus()

  return (
    <div className="flex flex-col gap-6">
      <RegisterAccountForm />

      <Card>
        <h2 className="mb-4 text-lg font-semibold">Аккаунты</h2>

        {accounts.status === 'pending' && (
          <StatusPanel
            icon={
              <LoaderCircle
                aria-hidden="true"
                className="animate-spin"
                size={20}
              />
            }
            title="Загружаем аккаунты"
          />
        )}

        {accounts.status === 'error' && (
          <StatusPanel
            action={
              <Button
                type="button"
                variant="secondary"
                size="compact"
                onClick={() => {
                  void accounts.refetch()
                }}
              >
                Повторить
              </Button>
            }
            description="Не удалось загрузить список."
            icon={<CircleX aria-hidden="true" size={20} />}
            role="alert"
            title="Ошибка"
            tone="negative"
          />
        )}

        {setStatus.isError && (
          // Молчаливый отказ здесь опаснее обычного: кнопка перестаёт
          // грузиться, строка остаётся в прежнем состоянии, и со стороны
          // это неотличимо от «уже было так». Администратор ушёл бы
          // с экрана уверенным, что аккаунт заблокирован.
          <div
            className="mb-4 flex items-center gap-2 rounded-lg border border-negative-border bg-negative-bg p-3 text-xs text-negative-text"
            role="alert"
          >
            <CircleAlert aria-hidden="true" size={16} />
            <span>
              {setStatus.error instanceof Error
                ? setStatus.error.message
                : 'Не удалось изменить состояние аккаунта'}
            </span>
          </div>
        )}

        {accounts.status === 'success' && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-left text-xs text-muted">
                    <th className="py-2">Компания</th>
                    <th className="py-2">Состояние</th>
                    <th className="py-2">Заведён</th>
                    <th className="py-2" />
                  </tr>
                </thead>
                <tbody>
                  {accounts.data.items.map((account) => (
                    <tr key={account.id} className="border-t border-border">
                      <td className="py-2">{account.name}</td>
                      <td className="py-2">
                        <Badge
                          tone={
                            account.status === 'active'
                              ? 'positive'
                              : 'negative'
                          }
                        >
                          {account.status === 'active'
                            ? 'работает'
                            : 'заблокирован'}
                        </Badge>
                      </td>
                      <td className="py-2 text-muted">
                        {account.createdAt.slice(0, 10)}
                      </td>
                      <td className="py-2 text-right">
                        <Button
                          type="button"
                          variant="secondary"
                          size="compact"
                          loading={
                            setStatus.isPending &&
                            setStatus.variables?.id === account.id
                          }
                          onClick={() => {
                            setStatus.mutate({
                              id: account.id,
                              status:
                                account.status === 'active'
                                  ? 'blocked'
                                  : 'active',
                            })
                          }}
                        >
                          {account.status === 'active'
                            ? 'Заблокировать'
                            : 'Включить'}
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {accounts.data.pages > 1 && (
              <div className="mt-4 flex items-center gap-3 text-sm">
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={page <= 1}
                  onClick={() => {
                    setPage((current) => current - 1)
                  }}
                >
                  Назад
                </Button>
                <span className="text-muted">
                  {accounts.data.page} из {accounts.data.pages}
                </span>
                <Button
                  type="button"
                  variant="secondary"
                  size="compact"
                  disabled={page >= accounts.data.pages}
                  onClick={() => {
                    setPage((current) => current + 1)
                  }}
                >
                  Вперёд
                </Button>
              </div>
            )}
          </>
        )}
      </Card>
    </div>
  )
}
