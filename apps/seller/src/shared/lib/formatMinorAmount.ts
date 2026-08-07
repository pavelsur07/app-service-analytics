// Копейки (минорные единицы) в отображаемую сумму. Компонент не считает
// деньги — только форматирует то, что посчитал бэкенд (patterns.md).
export function formatMinorAmount(
  minorAmount: number,
  currency: string,
): string {
  return new Intl.NumberFormat('ru-RU', { style: 'currency', currency }).format(
    minorAmount / 100,
  )
}

// Проба фильтров конвейера: коммит только во фронтенд (критерий 1).
