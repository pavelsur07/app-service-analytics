// Манифест собирается отсюда, а не лежит статическим JSON: dev и prod
// расходятся ровно в одном — какие хосты разрешены. Локальный домен,
// уехавший в опубликованное расширение, это отказ на ревью в сторе
// и неловкий вопрос «зачем расширению доступ к localhost». Здесь
// prod-сборка физически не может его содержать.

// Публичная часть RSA-ключа. Даёт расширению постоянный идентификатор
// в режиме разработчика: без неё ID выводится из пути к папке, у каждого
// разработчика свой, а к ID привязаны externally_connectable на стороне
// SPA и allowlist на бэкенде.
//
// Здесь константа, а не переменная окружения: это не секрет (секрет —
// приватная часть, она в репозиторий не кладётся), и значение обязано
// быть одинаковым у всех, иначе смысл теряется. Пустая строка —
// «ключ ещё не выпущен»: ID плавает, подключение из SPA не работает.
// Заполняется один раз, перед первой сборкой для установки.
const EXTENSION_KEY = ''

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
    version: '0.1.0',
    description: 'Аналитика карточек товаров Ozon поверх кабинета продавца.',
    ...(EXTENSION_KEY ? { key: EXTENSION_KEY } : {}),

    action: {
      default_popup: 'src/popup/index.html',
      default_title: 'Conwix',
    },
    background: {
      service_worker: 'background.js',
      type: 'module',
    },

    // Только домен приложения. Ozon сюда не добавлен намеренно:
    // host_permissions нужны, чтобы ходить к хосту сетью, а оверлей
    // к Ozon не обращается вовсе — он читает свою же страницу через
    // content_scripts и берёт цифры из нашего API. Запросы к Ozon
    // появятся вместе со сбором наблюдений (этап 3), тогда же и разрешение.
    host_permissions: isDev ? DEV_HOSTS : PROD_HOSTS,
    // Разрешения добавляются по одному под конкретную задачу: каждое
    // лишнее удлиняет ревью в сторе и требует обоснования.
    // alarms — обновление каталога артикулов раз в сутки; setInterval
    // для этого не годится, service worker засыпает через полминуты.
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
