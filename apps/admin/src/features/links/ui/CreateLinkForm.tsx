import { CircleAlert } from 'lucide-react'
import { useForm } from 'react-hook-form'

import { Button, Input } from '../../../../../../packages/ui/src'
import { useCreateLink } from '../model/useCreateLink'
import { LinkFormDialog } from './LinkFormDialog'

interface CreateLinkFormValues {
  name: string
  targetUrl: string
}

export function CreateLinkForm({
  onCreated,
  onCancel,
}: {
  onCreated: (linkId: string) => void
  onCancel: () => void
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
    <LinkFormDialog
      busy={createLink.isPending}
      onClose={onCancel}
      title="Новая ссылка"
    >
      <form
        className="flex flex-col gap-4"
        noValidate
        onSubmit={(event) => {
          void onSubmit(event)
        }}
      >
        <p className="text-sm text-text-muted">
          Короткий адрес создаётся на lin.conwix.com.
        </p>

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

        <div className="flex flex-wrap gap-2">
          <Button loading={createLink.isPending} type="submit">
            Создать ссылку
          </Button>
          <Button
            disabled={createLink.isPending}
            onClick={onCancel}
            type="button"
            variant="secondary"
          >
            Отмена
          </Button>
        </div>
      </form>
    </LinkFormDialog>
  )
}
