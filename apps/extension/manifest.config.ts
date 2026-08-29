// Манифест собирается отсюда, а не лежит статическим JSON: dev и prod
// расходятся ровно в одном — какие хосты разрешены. Локальный домен,
// уехавший в опубликованное расширение, это отказ на ревью в сторе
// и неловкий вопрос «зачем расширению доступ к localhost». Здесь
// prod-сборка физически не может его содержать.

// Публичная часть RSA-ключа. Даёт расширению постоянный идентификатор:
// без неё ID выводится из пути к папке, у каждого разработчика свой,
// а к ID привязано подключение на стороне SPA.
//
// Отсюда ID: nnglnfbkiklanbdmfhgflbjjckmmjlah — первые 16 байт sha256
// от DER этого ключа, полубайты 0-f отображены в a-p. То же значение
// стоит в PINNED_EXTENSION_ID приложения; они обязаны совпадать,
// иначе SPA отправит токен в никуда.
//
// Здесь константа, а не переменная окружения: это не секрет, и значение
// обязано быть одинаковым у всех, иначе смысл теряется. Приватная часть
// в репозиторий не кладётся и лежит вне его (см. docs/operations-checklist.md,
// раздел про расширение).
//
// ВАЖНО ПРО ПУБЛИКАЦИЮ: Chrome Web Store при первой загрузке назначает
// расширению собственный идентификатор и не использует этот ключ. После
// первой публикации сюда переносится ключ из панели разработчика стора,
// и ID меняется — вместе с PINNED_EXTENSION_ID. До тех пор значение ниже
// обслуживает установку распакованным и самоподписанный CRX.
const EXTENSION_KEY =
  'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEApDsVTjxcpbXPABYeNM2C4JEolc7TX2xTA/OWNtDP1++FqwyOydl6lefd4a1HZeVxlQceb8mJ4oEpYTF4/LugdAFnK6mMhaOuVBK9hTqbcR7xc7BylWo/RGuEPFhY1uosRtRr0+/aj7lSFuoCpmlcz4IOLgbgtiTC6lL/txmI7qaJbwOzMbREmnf46fSHg5Uwvtq8iRAEJVTxF0aIM8vXJsaxUc8Z2zhPAbUWjK3APuxaZAdnPqoICJRpXurLmUfUH6LY0lKuGIwuPrl4sI8qMpHnO12zMkME+7j5AEducZ5R1oqy0yAh9c5LWLVIYziNw9XZzzd+WJmDx67JfxTulwIDAQAB'

/**
 * Одно значение и для манифеста, и для наблюдений цены: расширение
 * сообщает версию с каждым снимком (ADR-014), и разъехавшиеся числа
 * означали бы, что по журналу нельзя понять, какая сборка их прислала.
 */
export const EXTENSION_VERSION = '0.2.0'

const PROD_HOSTS = ['https://app.conwix.com/*']
const DEV_HOSTS = [
  'http://app.conwix.localhost/*',
  'https://app.conwix.localhost/*',
]

/**
 * Адрес приложения — одна функция и для манифеста, и для клиента
 * (vite.config.ts подставляет её результат в сборку через define).
 *
 * Раньше клиент выводил адрес из import.meta.env.DEV, и это расходилось
 * с манифестом: `vite build --mode development` меняет mode, но не
 * NODE_ENV, поэтому DEV в собранном коде оставался false. Манифест
 * разрешал localhost, а запрос уходил на боевой домен — watch-сборка
 * не работала вовсе. Один источник вместо двух закрывает этот класс
 * расхождений, а не конкретный случай.
 */
export function appOrigin(mode: string, override?: string): string {
  // Переопределение — только для разработки: собрать расширение под
  // туннель на нестандартном порту. Порт нужен именно здесь, потому что
  // фоновый запрос расширения идёт по абсолютному адресу; шаблоны
  // совпадений в манифесте порт игнорируют, и им хватает хоста.
  if (undefined !== override && '' !== override && !isProduction(mode)) {
    return override
  }

  return isProduction(mode)
    ? 'https://app.conwix.com'
    : 'http://app.conwix.localhost'
}

function isProduction(mode: string): boolean {
  return 'production' === mode
}

export function buildManifest(mode: string): Record<string, unknown> {
  const isDev = !isProduction(mode)
  const appMatches = isDev ? DEV_HOSTS : PROD_HOSTS

  return {
    manifest_version: 3,
    name: isDev ? 'Conwix (dev)' : 'Conwix',
    // Версия расширения живёт отдельно от версии сервиса: в сторе она
    // обязана только возрастать, а выкладка бэкенда происходит чаще.
    version: EXTENSION_VERSION,
    description: 'Аналитика карточек товаров Ozon поверх кабинета продавца.',
    ...(EXTENSION_KEY ? { key: EXTENSION_KEY } : {}),

    // Знак продукта — тот же, что у приложения. PNG, а не SVG: Chrome
    // в иконках расширения SVG не отображает, в отличие от фавикона
    // на странице. Файлы лежат в public/icons и коммитятся готовыми —
    // растеризатор в зависимости ради четырёх статических картинок
    // не окупается.
    //
    // 48 и 128 растеризованы из packages/ui/src/favicon.svg, 16 и 32 —
    // из favicon-small.svg: у мелких размеров штрих толще и скругление
    // меньше, иначе буква размазывается антиалиасингом. Перегенерация
    // ручная, порядок описан в docs/patterns.md, раздел «Дизайн-система».
    //
    // 128 обязателен для подачи в Chrome Web Store.
    icons: {
      16: 'icons/icon-16.png',
      32: 'icons/icon-32.png',
      48: 'icons/icon-48.png',
      128: 'icons/icon-128.png',
    },
    action: {
      default_popup: 'src/popup/index.html',
      default_title: 'Conwix',
    },
    background: {
      service_worker: 'background.js',
      type: 'module',
    },

    // Только домен приложения, и со сбором наблюдений это не изменилось.
    // host_permissions нужны, чтобы ходить к хосту сетью; мы к Ozon
    // сетью не ходим — открываем его страницу окном и читаем её тем же
    // content-script'ом, который уже объявлен в content_scripts.matches.
    host_permissions: isDev ? DEV_HOSTS : PROD_HOSTS,
    // Разрешения добавляются по одному под конкретную задачу: каждое
    // лишнее удлиняет ревью в сторе и требует обоснования.
    // alarms — обновление каталога артикулов и обход отслеживаемых
    // артикулов раз в полчаса; setInterval для этого не годится,
    // service worker засыпает через полминуты.
    //
    // Разрешения на окна здесь нет намеренно: chrome.windows.create
    // и remove его не требуют, а `tabs` понадобился бы только чтобы
    // читать адрес чужой вкладки — своё окно мы и так знаем
    // по идентификатору. Каждое лишнее разрешение удлиняет ревью
    // в сторе и требует обоснования.
    permissions: ['storage', 'alarms'],

    // Кто имеет право обратиться к расширению снаружи. Только наше
    // приложение — через этот канал SPA передаёт выпущенный токен
    // (ADR-010), и открывать его шире нельзя ни на шаг.
    externally_connectable: {
      matches: appMatches,
    },

    // Один скрипт на страницах самого приложения: сообщает ей id
    // расширения, без которого она не может отправить сюда сообщение.
    // На маркетплейсах скриптов нет — они появятся вместе с оверлеем
    // и только после спайка.
    content_scripts: [
      {
        matches: appMatches,
        js: ['content/announce.js'],
        run_at: 'document_start',
      },
      // Оверлей на карточках товаров Ozon. Сужено до /product/ —
      // на поиске, в категориях и в кабинете продавца скрипту делать
      // нечего, а каждый лишний адрес в matches это вопрос на ревью
      // в сторе.
      // document_idle, а не document_start: карточка Ozon — Vue
      // с серверным рендерингом, и узел, вставленный до гидратации,
      // ломает её (hydration mismatch) и тут же сносится вместе
      // с перерисованным поддеревом. Раннего запуска здесь не нужно —
      // ждать всё равно приходится.
      {
        matches: ['https://www.ozon.ru/product/*', 'https://ozon.ru/product/*'],
        js: ['content/overlay.js'],
        run_at: 'document_idle',
      },
    ],
  }
}
