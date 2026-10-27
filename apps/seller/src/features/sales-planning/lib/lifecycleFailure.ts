import { ApiError } from '../../../api/ApiError'

export function lifecycleFailure(error: unknown): {
  message: string
  versionConflict: boolean
} {
  if (!(error instanceof ApiError))
    return {
      message:
        'Не удалось выполнить действие. Ваш ввод сохранён. Проверьте соединение и повторите.',
      versionConflict: false,
    }
  if (error.code === 'version_conflict')
    return {
      message:
        'План изменился. Изменения не применены, ваш ввод сохранён. Перечитайте данные и проверьте версию.',
      versionConflict: true,
    }
  if (error.code === 'sales_plan_day_conflict')
    return {
      message: `${error.message} Измените период или состав черновика либо проверьте пересекающийся утверждённый план. Ваш ввод сохранён.`,
      versionConflict: false,
    }
  const reasons: Record<string, string> = {
    sales_plan_lineage_closed:
      'Линия плана закрыта. Новую версию нельзя утвердить. Откройте действующий план или создайте новый план.',
    sales_plan_not_draft:
      'План больше не является черновиком. Перечитайте его перед утверждением.',
    sales_plan_not_editable:
      'Этот план уже нельзя редактировать. Перечитайте его и при необходимости создайте новую версию.',
    sales_plan_not_approved:
      'Новая версия создаётся только из утверждённого плана. Перечитайте статус плана.',
    sales_plan_invalid_status:
      'Текущий статус плана не допускает это действие. Перечитайте план.',
    sales_plan_metric_immutable:
      'Смысл утверждённой цели менять нельзя. Для другой метрики нужен новый план.',
    sales_plan_metric_required: 'Перед утверждением выберите смысл цели.',
    sales_plan_empty: 'Добавьте хотя бы один SKU перед утверждением.',
  }
  return {
    message:
      reasons[error.code ?? ''] ??
      'Действие не выполнено. Ваш ввод сохранён. Перечитайте план и проверьте доступ.',
    versionConflict: false,
  }
}
