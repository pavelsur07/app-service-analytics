import fs from 'node:fs'
import js from '@eslint/js'
import tseslint from 'typescript-eslint'
import reactHooks from 'eslint-plugin-react-hooks'
import importPlugin from 'eslint-plugin-import'
import prettierConfig from 'eslint-config-prettier'
import noManualApiResponseType from './eslint-rules/no-manual-api-response-type.js'

// Зоны feature-vs-feature считаются с диска: сейчас features/ пусто или
// не существует, поэтому список пуст — заполнится сам по мере появления
// фич, руками ничего перечислять не надо (docs/patterns.md).
function featureZones() {
  const featuresDir = 'src/features'
  if (!fs.existsSync(featuresDir)) {
    return []
  }

  return fs
    .readdirSync(featuresDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => ({
      target: `./src/features/${entry.name}`,
      from: './src/features',
      except: [`./${entry.name}`],
      message:
        'features/A не импортирует из features/B — общее уходит в shared/.',
    }))
}

export default tseslint.config(
  {
    ignores: [
      'dist/**',
      'node_modules/**',
      'playwright-report/**',
      'test-results/**',
    ],
  },
  js.configs.recommended,
  ...tseslint.configs.strict,
  ...tseslint.configs.stylistic,
  {
    plugins: { 'react-hooks': reactHooks, import: importPlugin },
    settings: {
      // Без TS-резолвера import/no-restricted-paths не может разрешить
      // относительные импорты без расширения .ts и молча не находит
      // нарушений — это не опция, а необходимое условие работы правила.
      'import/resolver': {
        typescript: true,
      },
    },
    rules: {
      ...reactHooks.configs['recommended-latest'].rules,
      '@typescript-eslint/no-explicit-any': 'error',

      // CLAUDE.md §7 — прямой fetch и localStorage вне разрешённого
      // списка. Список пока пуст, поэтому localStorage запрещён целиком.
      'no-restricted-globals': [
        'error',
        {
          name: 'fetch',
          message: 'fetch только внутри src/api/ — используй apiGet.',
        },
        {
          name: 'localStorage',
          message:
            'localStorage — только ключи из разрешённого списка (CLAUDE.md §7); список пока пуст.',
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
      'no-restricted-syntax': [
        'error',
        {
          selector:
            "Property[key.name='queryKey'][value.type='ArrayExpression']",
          message:
            'Литеральный массив в queryKey запрещён — ключ собирается общим хелпером (CLAUDE.md §7).',
        },
      ],
      'import/no-restricted-paths': [
        'error',
        {
          zones: [
            {
              target: './src/shared',
              from: './src/features',
              message: 'shared/ не импортирует из features/.',
            },
            {
              target: './src/!(app)/**/*',
              from: './src/app/**/*',
              message: 'из app/ не импортирует никто.',
            },
            ...featureZones(),
          ],
        },
      ],
    },
  },
  {
    files: ['src/api/**/*.{ts,tsx}'],
    rules: {
      'no-restricted-globals': 'off',
    },
  },
  {
    // projectService только для src/**: остальному (eslint.config.js сам,
    // vite.config.ts, playwright.config.ts, tests/e2e) не нужен TS type
    // checker, а вне tsconfig.json (include: src) типизированный парсинг
    // падает с "not found by the project service". Нужен он здесь ради
    // local/no-manual-api-response-type — проверяет apiGet<T>() по
    // существу (где объявлен T — в схеме или руками), не по имени типа.
    files: ['src/**/*.{ts,tsx}'],
    languageOptions: {
      parserOptions: {
        projectService: true,
        tsconfigRootDir: import.meta.dirname,
      },
    },
    plugins: {
      local: {
        rules: { 'no-manual-api-response-type': noManualApiResponseType },
      },
    },
    rules: {
      'local/no-manual-api-response-type': 'error',
    },
  },
  {
    // Компоненты — .tsx. Чистые .ts-утилиты форматирования (shared/lib)
    // намеренно делают денежную арифметику — это их работа. Flat config
    // не сливает массивы одного правила между блоками, поэтому здесь
    // повторён queryKey вместе с деньгами, а не только добавлены деньги.
    files: ['src/**/*.tsx'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector:
            "Property[key.name='queryKey'][value.type='ArrayExpression']",
          message:
            'Литеральный массив в queryKey запрещён — ключ собирается общим хелпером (CLAUDE.md §7).',
        },
        {
          selector:
            'BinaryExpression[operator=/^[+\\-*/]$/] > Identifier[name=/amount|price|total|money/i]',
          message:
            'Арифметика над денежными величинами в компонентах запрещена — считает бэкенд (docs/patterns.md).',
        },
      ],
    },
  },
  prettierConfig,
)
