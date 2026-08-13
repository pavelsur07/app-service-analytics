import { fileURLToPath } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import { defineConfig, type Plugin } from 'vite'

import { appOrigin, buildManifest } from './manifest.config'

// Манифест пишется в dist на этапе сборки — сгенерированный файл,
// в репозитории его нет (см. manifest.config.ts, почему не статический JSON).
function manifestPlugin(mode: string): Plugin {
  return {
    name: 'conwix-extension-manifest',
    generateBundle() {
      this.emitFile({
        type: 'asset',
        fileName: 'manifest.json',
        source: JSON.stringify(buildManifest(mode), null, 2),
      })
    },
  }
}

// Какой content-script собирает этот проход. Пусто — собираем popup
// и service worker, для них модули допустимы и удобны.
const contentEntry = process.env.CONWIX_CONTENT

export default defineConfig(({ mode }) => ({
  plugins: [tailwindcss(), manifestPlugin(mode)],
  // Адрес приложения считается тем же вызовом, что и хосты манифеста,
  // и подставляется в сборку константой. import.meta.env.DEV для этого
  // не годится: `vite build --mode development` меняет mode, но не
  // NODE_ENV, и DEV в собранном коде остаётся false — манифест разрешал
  // бы localhost, а запрос уходил на боевой домен.
  // CONWIX_APP_ORIGIN — для локальной проверки через SSH-туннель
  // на нестандартном порту: CONWIX_APP_ORIGIN=http://app.conwix.localhost:8080
  // В production-сборке игнорируется (см. appOrigin).
  define: {
    __APP_ORIGIN__: JSON.stringify(
      appOrigin(mode, process.env.CONWIX_APP_ORIGIN),
    ),
  },
  resolve: {
    // Та же причина, что у seller: packages/ui без своего node_modules,
    // react ему отдаёт приложение; dedupe страхует от второй копии.
    alias: {
      react: fileURLToPath(new URL('./node_modules/react', import.meta.url)),
    },
    dedupe: ['react', 'react-dom'],
  },
  build: {
    // Content-script собирается отдельным проходом и в формате iife.
    //
    // Причина не стилистическая: content-script в Manifest V3 грузится
    // обычным скриптом, не модулем, и `import` в нём — синтаксическая
    // ошибка, после которой не выполняется ничего. В общей сборке Vite
    // выносит код, нужный сразу нескольким входам, в отдельные чанки
    // и подставляет во вход `import` на них — то есть ломает ровно те
    // файлы, которые ломать нельзя. Отдельный проход с одним входом
    // делать этого не может: делить не с кем.
    //
    // Формат iife в rollup несовместим с несколькими входами, поэтому
    // проход на каждый скрипт свой (см. package.json, build:content).
    rollupOptions: {
      input:
        contentEntry === undefined
          ? {
              popup: fileURLToPath(
                new URL('./src/popup/index.html', import.meta.url),
              ),
              background: fileURLToPath(
                new URL('./src/background/index.ts', import.meta.url),
              ),
            }
          : fileURLToPath(
              new URL(`./src/content/${contentEntry}.ts`, import.meta.url),
            ),
      output:
        contentEntry === undefined
          ? {
              entryFileNames: '[name].js',
              chunkFileNames: 'chunks/[name].js',
              assetFileNames: 'assets/[name][extname]',
            }
          : {
              // iife — самодостаточный обычный скрипт без import.
              format: 'iife',
              entryFileNames: `content/${contentEntry}.js`,
              assetFileNames: 'assets/[name][extname]',
            },
    },
    // MV3 запрещает eval и подгрузку кода со стороны; sourcemap отдельным
    // файлом это не нарушает и сильно помогает при отладке service worker.
    sourcemap: true,
    emptyOutDir: contentEntry === undefined,
  },
  test: {
    environment: 'node',
    // Мок-сервер по схеме OpenAPI поднимается на все тесты
    // (CLAUDE.md §10). Хендлеры задаёт каждый тест сам.
    setupFiles: ['./tests/msw/setup.ts'],
  },
}))
