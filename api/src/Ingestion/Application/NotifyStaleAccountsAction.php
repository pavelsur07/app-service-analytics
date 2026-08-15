<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityScheduleFacade;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Query\RecentlyIngestedAccountsQuery;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Один проход контроля свежести данных (CheckDataFreshnessCommand, Ui):
 * активные подключения минус те, по которым raw-слой пополнялся недавно,
 * — остаток означает, что синхронизация встала, и порождает письмо.
 *
 * Зачем вообще: устаревшие данные выглядят рабочими и не бросают
 * исключений (CLAUDE.md, «Наблюдаемость»). В трекер ошибок не попадёт
 * ничего — экран отрисуется, цифры покажутся, просто вчерашние. Это
 * единственный отказ продукта, который сам себя не показывает.
 *
 * Класс живёт в собственном узком слое Deptrac (IngestionFreshnessAction)
 * по той же причине, что DispatchActiveOzonSyncsAction в своём: он
 * вызывает межарендаторные чтения (IdentityScheduleFacade и
 * RecentlyIngestedAccountsQuery), и широкий доступ IngestionUi
 * к IngestionApplication открыл бы их будущему HTTP-контроллеру.
 * Слои разные, а не один общий: доступ к запросу свежести не нужен
 * планировщику, и общий слой выдал бы его заодно (CLAUDE.md §1).
 */
final readonly class NotifyStaleAccountsAction
{
    /**
     * Тридцать шесть часов, а не ровно сутки. Raw-документ на тихом
     * подключении появляется примерно раз в сутки: в течение дня ответ
     * площадки не меняется, и повторная загрузка отсекается уникальным
     * индексом по body_hash (ADR-006) — новая строка появляется только
     * со сменой period, то есть в первый тик новых суток. Порог ровно
     * в 24 часа отличался бы от этого интервала на минуты, и любое
     * смещение тика давало бы ложную тревогу по исправному подключению.
     * Тревога, которая иногда врёт, перестаёт читаться через неделю.
     */
    private const string STALE_AFTER = 'PT36H';

    /**
     * Одно письмо в сутки на подключение. Проверка идёт каждый час,
     * а сломанная синхронизация чинится не мгновенно — без этого
     * почтовый ящик получал бы одно и то же двадцать четыре раза,
     * и следующая настоящая тревога потерялась бы среди повторов.
     * Замок Redis, а не колонка в базе: состояние подавления живёт
     * ровно сутки и переживать перезапуск не обязано, а таблица
     * подключений принадлежит Identity и Ingestion её не пишет.
     */
    private const int ALERT_INTERVAL_SECONDS = 86400;

    /**
     * Что под контролем и как это называется в письме. Один список,
     * а не два рядом: тип, добавленный в отслеживание без имени,
     * пришёл бы к получателю строкой `ozon_accrual_by_day`.
     *
     * Расходы здесь потому, что из них складывается цифра на экране
     * наравне с продажами: вставшая загрузка расходов — не пустой экран,
     * а рабочий с завышенной маржой, и заметить её без сторожа нельзя.
     * Каталога здесь нет намеренно — вспомогательный список для оверлея,
     * отдельная тревога по нему пока не окупается.
     */
    private const array WATCHED_REPORTS = [
        MarketplaceReportType::OzonPostingFboList => 'продажи',
        MarketplaceReportType::OzonAccrualByDay => 'расходы',
    ];

    public function __construct(
        private IdentityScheduleFacade $identitySchedule,
        private RecentlyIngestedAccountsQuery $recentlyIngested,
        private MailerInterface $mailer,
        private LockFactory $lockFactory,
        private string $alertEmail,
        private string $mailerDsn,
    ) {
    }

    /**
     * @return list<string> выгрузки, о которых отправлено письмо, в форме
     *                      «companyId:marketplaceAccountId:reportType»
     */
    public function __invoke(): array
    {
        if ('' === $this->alertEmail) {
            // Громко: контроль свежести с пустым адресом — это контроль,
            // о срабатывании которого никто не узнает. Ровно тот отказ,
            // от которого он сам и защищает.
            throw new \RuntimeException('ALERT_EMAIL не задан — письму о несвежих данных некуда идти.');
        }

        if ('' === $this->mailerDsn || str_starts_with($this->mailerDsn, 'null://')) {
            // Отдельно от адреса: заданный ALERT_EMAIL при null-транспорте
            // выглядит настроенным сторожем, который каждый час исправно
            // «отправляет» письма в никуда. Symfony при null://null не
            // бросает ничего — молчание тут неотличимо от успеха, поэтому
            // проверка своя.
            throw new \RuntimeException('MAILER_DSN — null-транспорт: письмо о несвежих данных будет проглочено молча.');
        }

        $active = $this->identitySchedule->findActiveOzonSyncTargets();
        if ([] === $active) {
            return [];
        }

        $fresh = $this->freshKeys(new \DateTimeImmutable('now'));

        /** @var array<string, LockInterface> $claimed */
        $claimed = [];
        /** @var list<string> $lines */
        $lines = [];
        foreach ($active as $target) {
            // Каждая отслеживаемая выгрузка проверяется своей отметкой:
            // подключение бывает наполовину живым, и «данные по нему
            // идут» — не ответ на вопрос «идут ли расходы».
            foreach (self::WATCHED_REPORTS as $reportType => $label) {
                $key = RecentlyIngestedAccountsQuery::key($target->companyId, $target->marketplaceAccountId, $reportType);
                if (isset($fresh[$key])) {
                    continue;
                }
                $lock = $this->claimAlert($key);
                if (null === $lock) {
                    continue;
                }
                $claimed[$key] = $lock;
                // Строка письма собирается здесь, где тип ещё известен
                // как значение: разбирать его обратно из ключа означало бы
                // держать формат ключа в двух местах.
                $lines[] = \sprintf('  %s:%s — %s', $target->companyId, $target->marketplaceAccountId, $label);
            }
        }

        if ([] === $claimed) {
            return [];
        }

        $stale = array_keys($claimed);

        try {
            $this->mailer->send($this->alertAbout($lines));
        } catch (\Throwable $failure) {
            // Замок снимается, иначе временная недоступность SMTP стоила бы
            // суток тишины: подавление повторов сработало бы на письмо,
            // которое не ушло. Следующий тик через час попробует снова.
            foreach ($claimed as $lock) {
                $lock->release();
            }

            throw $failure;
        }

        return $stale;
    }

    /**
     * @return array<string, true>
     */
    private function freshKeys(\DateTimeImmutable $now): array
    {
        $rows = $this->recentlyIngested->build($now->sub(new \DateInterval(self::STALE_AFTER)), array_keys(self::WATCHED_REPORTS))
            ->executeQuery()
            ->fetchAllAssociative();

        $ceiling = RecentlyIngestedAccountsQuery::MAX_ACCOUNTS * \count(self::WATCHED_REPORTS);
        if (\count($rows) > $ceiling) {
            // Тот же приём, что в IdentityScheduleFacade: тихая обрезка
            // до потолка объявила бы часть исправных подключений
            // несвежими и разослала бы ложные письма.
            throw new \RuntimeException(\sprintf('Свежих выгрузок больше защитного потолка %d — нужна курсорная выборка.', $ceiling));
        }

        $keys = [];
        foreach ($rows as $row) {
            $fresh = RecentlyIngestedAccountsQuery::mapRow($row);
            $keys[RecentlyIngestedAccountsQuery::key($fresh->companyId, $fresh->marketplaceAccountId, $fresh->reportType)] = true;
        }

        return $keys;
    }

    private function claimAlert(string $key): ?LockInterface
    {
        // autoRelease: false — замок обязан пережить конец процесса,
        // иначе подавление повторов исчезло бы вместе с тиком.
        $lock = $this->lockFactory
            ->createLock('ingestion.freshness-alert.'.$key, self::ALERT_INTERVAL_SECONDS, false);

        return $lock->acquire() ? $lock : null;
    }

    /**
     * @param list<string> $lines готовые строки «подключение — выгрузка»
     */
    private function alertAbout(array $lines): Email
    {
        // From не задаётся здесь: адрес отправителя привязан к учётным
        // данным SMTP, а не к письму (config/packages/mailer.yaml).
        return (new Email())
            ->to($this->alertEmail)
            ->subject(\sprintf('Conwix: данные не обновляются (%d выгрузок)', \count($lines)))
            ->text(
                "По этим выгрузкам нет новых raw-документов дольше 36 часов,\n"
                ."то есть синхронизация с площадкой не проходит:\n\n"
                .implode("\n", $lines)."\n\n"
                ."Формат строки: companyId:marketplaceAccountId — выгрузка.\n"
                ."По идентификаторам ищутся логи и очередь.\n\n"
                ."Названа именно выгрузка, а не подключение: у одного кабинета\n"
                ."они встают порознь, и вставшие расходы при живых продажах —\n"
                ."это рабочий экран с завышенной маржой, а не пустой.\n\n"
                ."Что смотреть: жив ли воркер async_ingestion, нет ли задержанных\n"
                ."и отказавших сообщений (messenger:failed:show), не перешло ли\n"
                ."подключение в broken (ADR-007), нет ли отказов в трекере.\n"
            );
    }
}
