<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityScheduleFacade;
use App\Ingestion\Infrastructure\Query\RecentlyIngestedAccountsQuery;
use Symfony\Component\Lock\LockFactory;
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
 * Класс живёт в узком слое IngestionOperationalAction по той же причине,
 * что DispatchActiveOzonSyncsAction: он вызывает межарендаторные чтения
 * (IdentityScheduleFacade и RecentlyIngestedAccountsQuery), и широкий
 * доступ IngestionUi к IngestionApplication открыл бы их будущему
 * HTTP-контроллеру.
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

    public function __construct(
        private IdentityScheduleFacade $identitySchedule,
        private RecentlyIngestedAccountsQuery $recentlyIngested,
        private MailerInterface $mailer,
        private LockFactory $lockFactory,
        private string $alertEmail,
    ) {
    }

    /**
     * @return list<string> подключения, о которых отправлено письмо,
     *                      в форме «companyId:marketplaceAccountId»
     */
    public function __invoke(): array
    {
        if ('' === $this->alertEmail) {
            // Громко: контроль свежести с пустым адресом — это контроль,
            // о срабатывании которого никто не узнает. Ровно тот отказ,
            // от которого он сам и защищает.
            throw new \RuntimeException('ALERT_EMAIL не задан — письму о несвежих данных некуда идти.');
        }

        $active = $this->identitySchedule->findActiveOzonSyncTargets();
        if ([] === $active) {
            return [];
        }

        $fresh = $this->freshKeys(new \DateTimeImmutable('now'));

        $stale = [];
        foreach ($active as $target) {
            $key = RecentlyIngestedAccountsQuery::key($target->companyId, $target->marketplaceAccountId);
            if (isset($fresh[$key])) {
                continue;
            }
            if (!$this->claimAlert($key)) {
                continue;
            }
            $stale[] = $key;
        }

        if ([] === $stale) {
            return [];
        }

        $this->mailer->send($this->alertAbout($stale));

        return $stale;
    }

    /**
     * @return array<string, true>
     */
    private function freshKeys(\DateTimeImmutable $now): array
    {
        $rows = $this->recentlyIngested->build($now->sub(new \DateInterval(self::STALE_AFTER)))
            ->executeQuery()
            ->fetchAllAssociative();

        if (\count($rows) > RecentlyIngestedAccountsQuery::MAX_RESULTS) {
            // Тот же приём, что в IdentityScheduleFacade: тихая обрезка
            // до потолка объявила бы часть исправных подключений
            // несвежими и разослала бы ложные письма.
            throw new \RuntimeException(\sprintf('Подключений со свежими данными больше защитного потолка %d — нужна курсорная выборка.', RecentlyIngestedAccountsQuery::MAX_RESULTS));
        }

        $keys = [];
        foreach ($rows as $row) {
            $keys[RecentlyIngestedAccountsQuery::keyOfRow($row)] = true;
        }

        return $keys;
    }

    private function claimAlert(string $key): bool
    {
        // autoRelease: false — замок обязан пережить конец процесса,
        // иначе подавление повторов исчезло бы вместе с тиком.
        return $this->lockFactory
            ->createLock('ingestion.freshness-alert.'.$key, self::ALERT_INTERVAL_SECONDS, false)
            ->acquire();
    }

    /**
     * @param list<string> $stale
     */
    private function alertAbout(array $stale): Email
    {
        $lines = array_map(
            static fn (string $key): string => '  '.$key,
            $stale,
        );

        return (new Email())
            ->from('no-reply@conwix.com')
            ->to($this->alertEmail)
            ->subject(\sprintf('Conwix: данные не обновляются (%d подключений)', \count($stale)))
            ->text(
                "По этим подключениям нет новых raw-документов дольше 36 часов,\n"
                ."то есть синхронизация с площадкой не проходит:\n\n"
                .implode("\n", $lines)."\n\n"
                ."Формат строки: companyId:marketplaceAccountId — по ним же\n"
                ."ищутся логи и очередь.\n\n"
                ."Что смотреть: жив ли воркер async_ingestion, не перешло ли\n"
                ."подключение в broken (ADR-007), нет ли отказов в трекере.\n"
            );
    }
}
