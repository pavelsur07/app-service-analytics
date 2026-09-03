import { CircleAlert } from 'lucide-react'
import { useForm } from 'react-hook-form'

import { Button, Card, Input } from '../../../../../../packages/ui/src'
import { ApiError } from '../../../api/ApiError'
import type { ShortLink } from '../model/useLinks'
import { useUpdateLink } from '../model/useUpdateLink'

interface EditLinkFormValues {
  name: string
  targetUrl: string
}

export function EditLinkForm({
  link,
  onCancel,
  onSaved,
}: {
  link: ShortLink
  onCancel: () => void
  onSaved: () => void
}) {
  const updateLink = useUpdateLink(link.id)
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<EditLinkFormValues>({
    defaultValues: { name: link.name, targetUrl: link.targetUrl },
  })

  const onSubmit = handleSubmit((values) => {
    updateLink.mutate(
      { ...values, version: link.version },
      { onSuccess: onSaved },
    )
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
        <h2 className="text-lg font-semibold">Изменить ссылку</h2>
        <Input
          error={errors.name?.message}
          label="Название ссылки для изменения"
          {...register('name', { required: 'Введите название' })}
        />
        <Input
          error={errors.targetUrl?.message}
          label="Адрес назначения для изменения"
          type="url"
          {...register('targetUrl', { required: 'Введите адрес назначения' })}
        />

        {updateLink.isError && (
          <div
            className="flex items-center gap-2 rounded-lg border border-negative-border bg-negative-bg p-3 text-xs text-negative-text"
            role="alert"
          >
            <CircleAlert aria-hidden="true" size={16} />
            <span>
              {updateLink.error instanceof ApiError &&
              updateLink.error.status === 409
                ? 'Ссылку уже изменили. Введённые значения сохранены — сверьте их и повторите.'
                : updateLink.error instanceof Error
                  ? updateLink.error.message
                  : 'Не удалось изменить ссылку'}
            </span>
          </div>
        )}

        <div className="flex flex-wrap gap-2">
          <Button loading={updateLink.isPending} type="submit">
            Сохранить изменения
          </Button>
          <Button
            disabled={updateLink.isPending}
            onClick={onCancel}
            type="button"
            variant="secondary"
          >
            Отмена
          </Button>
        </div>
      </form>
    </Card>
  )
}
