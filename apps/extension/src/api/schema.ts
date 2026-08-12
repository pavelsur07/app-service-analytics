// Единственное место, реэкспортирующее сгенерированную схему — остальной
// код импортирует типы ответов отсюда, а не пишет их руками (CLAUDE.md §10).
// Тот же приём, что в apps/seller/src/api/schema.ts.
export type { components } from '../../../../packages/api-schema/src/schema'
