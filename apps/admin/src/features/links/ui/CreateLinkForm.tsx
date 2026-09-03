import { CircleAlert, CircleCheck } from 'lucide-react'
import { useForm } from 'react-hook-form'

import { Button, Card, Input } from '../../../../../../packages/ui/src'
import { useCreateLink } from '../model/useCreateLink'

interface CreateLinkFormValues {
  name: string
  targetUrl: string
}

export function CreateLinkForm({
  onCreated,
}: {
  onCreated: (linkId: string) => void
}) {
  const createLink = useCreateLink()
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<CreateLinkFormValues>()

  const onSubmit = handleSubmit((values) => {
    createLink.mutate(values, {
      onSuccess: (link) => {
        reset()
        onCreated(link.id)
      },
    })
  })

  return (
    <Card>
      <form
        className="flex flex-col gap-4"
        noValidate
        onSubmit={(event) => {
          void onSubmit(event)
        }}
      >
        <div>
          <h2 className="text-lg font-semibold">Новая ссылка</h2>
          <p className="mt-1 text-sm text-text-muted">
            Короткий адрес создаётся на lin.conwix.com.
          </p>
        </div>

        <Input
          error={errors.name?.message}
          label="Название ссылки"
          {...register('name', { required: 'Введите название' })}
        />
        <Input
          error={errors.targetUrl?.message}
          label="Адрес назначения"
          placeholder="https://example.com/campaign"
          type="url"
          {...register('targetUrl', { required: 'Введите адрес назначения' })}
        />

        {createLink.isError && (
          <div
            className="flex items-center gap-2 rounded-lg border border-negative-border bg-negative-bg p-3 text-xs text-negative-text"
            role="alert"
          >
            <CircleAlert aria-hidden="true" size={16} />
            <span>
              {createLink.error instanceof Error
                ? createLink.error.message
                : 'Не удалось создать ссылку'}
            </span>
          </div>
        )}

        {createLink.isSuccess && (
          <div
            className="flex items-center gap-2 rounded-lg border border-positive-border bg-positive-bg p-3 text-xs text-positive-text"
            role="status"
          >
            <CircleCheck aria-hidden="true" size={16} />
            <span>Ссылка создана</span>
          </div>
        )}

        <Button loading={createLink.isPending} type="submit">
          Создать ссылку
        </Button>
      </form>
    </Card>
  )
}
