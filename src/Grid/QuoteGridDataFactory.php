<?php

declare(strict_types=1);

namespace Snt\Quotes\Grid;

use PrestaShop\PrestaShop\Core\Grid\Data\Factory\GridDataFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Data\GridData;
use PrestaShop\PrestaShop\Core\Grid\Record\RecordCollection;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Presenter\StatusPresenter;

/**
 * Met en forme les lignes de la Grid : badges, montants dans la devise, validité en rouge si < 7 jours.
 */
final class QuoteGridDataFactory implements GridDataFactoryInterface
{
    public function __construct(
        private readonly GridDataFactoryInterface $inner,
        private readonly StatusPresenter $statusPresenter,
    ) {
    }

    public function getData(SearchCriteriaInterface $searchCriteria)
    {
        $data = $this->inner->getData($searchCriteria);
        $locale = \Context::getContext()->getCurrentLocale();
        $link = \Context::getContext()->link;
        $today = new \DateTimeImmutable('today');
        $currencies = [];

        $records = [];
        foreach ($data->getRecords()->all() as $row) {
            $idCurrency = (int) $row['id_currency'];
            $currencies[$idCurrency] ??= (string) (new \Currency($idCurrency))->iso_code;
            $status = QuoteStatus::tryFrom((string) $row['status']) ?? QuoteStatus::Draft;

            $row['reference_display'] = $row['reference'] . ((int) $row['version'] > 1 ? ' v' . (int) $row['version'] : '');
            $company = trim((string) $row['customer_company']);
            $row['customer_html'] = $company !== ''
                ? '<strong>' . $this->e($company) . '</strong><br><span class="text-muted">' . $this->e((string) $row['customer_name']) . '</span>'
                : $this->e((string) $row['customer_name']);
            if ($row['project_name']) {
                $row['customer_html'] .= '<br><small class="text-muted">' . $this->e((string) $row['project_name']) . '</small>';
            }
            $row['total_ti_display'] = $locale ? $locale->formatPrice((float) $row['total_ti'], $currencies[$idCurrency]) : number_format((float) $row['total_ti'], 2, ',', ' ');
            $row['status_html'] = $this->statusPresenter->badgeHtml($status);

            $row['valid_until_html'] = '';
            if ($row['valid_until']) {
                $validUntil = new \DateTimeImmutable((string) $row['valid_until']);
                $days = (int) $today->diff($validUntil)->format('%r%a');
                $label = $this->e(\Tools::displayDate((string) $row['valid_until'], false));
                $urgent = $days < 7 && $status->isOpen();
                $row['valid_until_html'] = $urgent ? '<span class="text-danger font-weight-bold">' . $label . '</span>' : $label;
            }
            $row['date_add_display'] = \Tools::displayDate((string) $row['date_add'], false);
            $row['order_html'] = $row['id_order']
                ? '<a href="' . $this->e($link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => (int) $row['id_order']])) . '">' . $this->e((string) ($row['order_reference'] ?: '#' . $row['id_order'])) . '</a>'
                : '';
            $records[] = $row;
        }

        return new GridData(new RecordCollection($records), $data->getRecordsTotal(), $data->getQuery());
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
