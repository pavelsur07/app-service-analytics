import { useState } from 'react'
import { Button } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { FormDialog } from '../../../shared/ui/FormDialog'
import { useSetAccountStatus } from '../model/useSetAccountStatus'

type Account = components['schemas']['AdminCompanyResponse']

export function AccountStatusButton({ account }: { account: Account }) {
  const [target, setTarget] = useState<'active' | 'blocked' | null>(null)
  const setStatus = useSetAccountStatus()
  const action = account.status === 'active' ? 'Заблокировать' : 'Включить'
  const blocking = target === 'blocked'
  const confirmationAction = blocking ? 'Заблокировать' : 'Включить'

  return (
    <div className="flex flex-col items-end gap-2">
      <Button
        type="button"
        variant="secondary"
        size="compact"
        onClick={() => {
          setStatus.reset()
          setTarget(account.status === 'active' ? 'blocked' : 'active')
        }}
      >
        {action}
      </Button>
      {setStatus.isSuccess && setStatus.data.status === account.status && (
        <span role="status" className="text-xs text-text-secondary">
          {setStatus.data.status === 'blocked'
            ? 'Компания заблокирована'
            : 'Компания включена'}
        </span>
      )}
      {target && (
        <FormDialog
          title={`${confirmationAction} компанию?`}
          busy={setStatus.isPending}
          onClose={() => {
            setTarget(null)
          }}
        >
          <div className="flex flex-col gap-4 text-sm">
            <p className="break-words font-medium">{account.name}</p>
            <p className="text-text-secondary">
              {blocking
                ? 'Участники потеряют доступ к данным компании в приложении и расширении. Данные сохранятся. Доступ можно восстановить кнопкой «Включить».'
                : 'Участники снова получат доступ к данным компании в приложении и расширении.'}
            </p>
            {setStatus.isError && (
              <p role="alert" className="text-negative-text">
                {setStatus.error.message}
              </p>
            )}
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                loading={setStatus.isPending}
                onClick={() => {
                  setStatus.mutate(
                    { id: account.id, status: target },
                    {
                      onSuccess: () => {
                        setTarget(null)
                      },
                    },
                  )
                }}
              >
                {confirmationAction}
              </Button>
              <Button
                type="button"
                variant="secondary"
                disabled={setStatus.isPending}
                onClick={() => {
                  setTarget(null)
                }}
              >
                Отмена
              </Button>
            </div>
          </div>
        </FormDialog>
      )}
    </div>
  )
}
