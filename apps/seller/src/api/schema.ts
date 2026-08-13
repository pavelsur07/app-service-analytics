// Единственное место, реэкспортирующее сгенерированную схему — компоненты
// импортируют типы ответов отсюда, а не пишут их руками (CLAUDE.md §10).
export type {
  components,
  paths,
} from '../../../../packages/api-schema/src/schema'
