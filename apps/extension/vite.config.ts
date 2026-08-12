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

export default defineConfig(({ mode }) => ({
  plugins: [tailwindcss(), manifestPlugin(mode)],
  // Адрес приложения считается тем же вызовом, что и хосты манифеста,
  // и подставляется в сборку константой. import.meta.env.DEV для этого
  // не годится: `vite build --mode development` меняет mode, но не
  // NODE_ENV, и DEV в собранном коде остаётся false — манифест разрешал
  // бы localhost, а запрос уходил на боевой домен.
  define: {
    __APP_ORIGIN__: JSON.stringify(appOrigin(mode)),
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
    // Имена файлов фиксированы, без хэшей: на них ссылается манифест,
    // а он статический. Для расширения кэширование по имени и не нужно —
    // файлы едут в пакете, а не по сети.
    rollupOptions: {
      input: {
        popup: fileURLToPath(
          new URL('./src/popup/index.html', import.meta.url),
        ),
        background: fileURLToPath(
          new URL('./src/background/index.ts', import.meta.url),
        ),
        'content/announce': fileURLToPath(
          new URL('./src/content/announce.ts', import.meta.url),
        ),
        'content/overlay': fileURLToPath(
          new URL('./src/content/overlay.ts', import.meta.url),
        ),
      },
      output: {
        entryFileNames: '[name].js',
        chunkFileNames: 'chunks/[name].js',
        assetFileNames: 'assets/[name][extname]',
      },
    },
    // MV3 запрещает eval и подгрузку кода со стороны; sourcemap отдельным
    // файлом это не нарушает и сильно помогает при отладке service worker.
    sourcemap: true,
    emptyOutDir: true,
  },
  test: {
    environment: 'node',
  },
}))
