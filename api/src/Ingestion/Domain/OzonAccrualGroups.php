<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Группы начислений так, как их показывает отчёт «Начисления» кабинета
 * Ozon. Соответствие «тип → группа» снято с выгрузки кабинета за август
 * 2026 — все типы, встретившиеся у первого клиента.
 *
 * Снимок в коде, как и `OzonFeeTypeNames`: это справочник площадки,
 * а не данные клиента. Куда кабинет относит остальные типы справочника,
 * неизвестно, и угадывать нельзя: сверка, собранная по догадке, сошлась
 * бы в итоге и разошлась в группах. Такой тип попадает в «Без группы»
 * с кодом и названием — видимо, а не молча, — и соответствие
 * дописывается по следующей выгрузке.
 *
 * Выручка (тип 0) делится по знаку: `+` — продажи, `−` — возвраты
 * (ADR-036). Кабинет относит часть отрицательной выручки к «Продажам»
 * (корректировки продаж); по ленте их от возврата не отличить, поэтому
 * разбивка между двумя группами расходится с кабинетом, а их сумма — нет.
 */
final class OzonAccrualGroups
{
    public const string SALES = 'sales';
    public const string RETURNS = 'returns';
    public const string COMMISSION = 'commission';
    public const string DELIVERY = 'delivery';
    public const string PARTNERS = 'partners';
    public const string FBO = 'fbo';
    public const string PROMOTION = 'promotion';
    public const string OTHER_SERVICES = 'other_services';
    public const string COMPENSATIONS = 'compensations';
    public const string UNGROUPED = 'ungrouped';

    /** Порядок — как в кабинете; «Без группы» последней. */
    public const array LABELS = [
        self::SALES => 'Продажи',
        self::RETURNS => 'Возвраты',
        self::COMMISSION => 'Вознаграждение Ozon',
        self::DELIVERY => 'Услуги доставки',
        self::PARTNERS => 'Услуги партнёров',
        self::FBO => 'Услуги FBO',
        self::PROMOTION => 'Продвижение и реклама',
        self::OTHER_SERVICES => 'Другие услуги и штрафы',
        self::COMPENSATIONS => 'Компенсации и декомпенсации',
        self::UNGROUPED => 'Без группы',
    ];

    /** Тип выручки (0) здесь не стоит: его группу задаёт знак. */
    public const array GROUP_OF_TYPE = [
        OzonFeeTypeNames::SALE_COMMISSION => self::COMMISSION,
        32 => self::DELIVERY,
        59 => self::DELIVERY,
        98 => self::DELIVERY,
        1 => self::PARTNERS,
        29 => self::PARTNERS,
        39 => self::PARTNERS,
        45 => self::PARTNERS,
        76 => self::PARTNERS,
        79 => self::PARTNERS,
        12 => self::FBO,
        46 => self::FBO,
        71 => self::FBO,
        77 => self::FBO,
        22 => self::PROMOTION,
        41 => self::PROMOTION,
        96 => self::PROMOTION,
        15 => self::OTHER_SERVICES,
        18 => self::OTHER_SERVICES,
        38 => self::OTHER_SERVICES,
        25 => self::COMPENSATIONS,
    ];

    private function __construct()
    {
    }

    public static function of(int $feeTypeId, bool $negativeRevenue): string
    {
        if (OzonFeeTypeNames::REVENUE === $feeTypeId) {
            return $negativeRevenue ? self::RETURNS : self::SALES;
        }

        return self::GROUP_OF_TYPE[$feeTypeId] ?? self::UNGROUPED;
    }
}
