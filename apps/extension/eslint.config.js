import js from '@eslint/js'
import prettierConfig from 'eslint-config-prettier'
import importPlugin from 'eslint-plugin-import'
import reactHooks from 'eslint-plugin-react-hooks'
import tseslint from 'typescript-eslint'

// Конфиг повторяет apps/seller по составу правил, но не тащит его целиком:
// у расширения нет TanStack Query (правило про queryKey не к чему
// применять), нет features/ (зоны import/no-restricted-paths не нужны)
// и нет денежной арифметики в компонентах — считает бэкенд, расширение
// только показывает готовое.
export default tseslint.config(
  {
    ignores: ['dist/**', 'node_modules/**'],
  },
  js.configs.recommended,
  ...tseslint.configs.strict,
  ...tseslint.configs.stylistic,
  {
    plugins: { 'react-hooks': reactHooks, import: importPlugin },
    settings: {
      'import/resolver': {
        typescript: true,
      },
    },
    rules: {
      ...reactHooks.configs['recommended-latest'].rules,
      '@typescript-eslint/no-explicit-any': 'error',

      'no-restricted-globals': [
        'error',
        {
          name: 'fetch',
          message: 'fetch только внутри src/api/ (CLAUDE.md §10).',
        },
        {
          name: 'localStorage',
          message:
            'В расширении состояние живёт в chrome.storage, не в localStorage: у popup и service worker разные контексты, localStorage между ними не общий.',
        },
      ],
      'no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: 'axios',
              message:
                'axios не используем — fetch покрывает нужды (docs/patterns.md).',
            },
          ],
        },
      ],
      'import/no-restricted-paths': [
        'error',
        {
          zones: [
            {
              // Service worker MV3 засыпает через полминуты простоя,
              // popup живёт секунды по клику. Общее у них — только
              // shared/ и api/; прямой импорт между ними означал бы,
              // что чей-то код рассчитывает на чужой жизненный цикл.
              target: './src/popup',
              from: './src/background',
              message:
                'popup не импортирует из background — общее уходит в shared/.',
            },
            {
              target: './src/background',
              from: './src/popup',
              message:
                'background не импортирует из popup — общее уходит в shared/.',
            },
          ],
        },
      ],
    },
  },
  {
    files: ['src/api/**/*.ts'],
    rules: {
      'no-restricted-globals': 'off',
    },
  },
  prettierConfig,
)
