<?php

declare(strict_types=1);

namespace Snt\Quotes\Grid;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\AbstractDoctrineQueryBuilder;
use PrestaShop\PrestaShop\Core\Grid\Query\DoctrineSearchCriteriaApplicatorInterface;
use PrestaShop\PrestaShop\Core\Grid\Search\SearchCriteriaInterface;
use Snt\Quotes\Domain\QuoteStatus;

/**
 * Requête de la liste des devis (boutiques du contexte uniquement, onglet rapide appliqué).
 */
class QuoteQueryBuilder extends AbstractDoctrineQueryBuilder
{
    public const TAB_TODO = 'todo';
    public const TAB_WAITING = 'waiting';
    public const TAB_ALL = 'all';

    private string $quickTab = self::TAB_ALL;

    public function __construct(
        Connection $connection,
        string $dbPrefix,
        private readonly DoctrineSearchCriteriaApplicatorInterface $criteriaApplicator,
    ) {
        parent::__construct($connection, $dbPrefix);
    }

    public function setQuickTab(string $tab): void
    {
        $this->quickTab = in_array($tab, [self::TAB_TODO, self::TAB_WAITING, self::TAB_ALL], true) ? $tab : self::TAB_ALL;
    }

    public function getSearchQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        $qb = $this->getBaseQuery($searchCriteria->getFilters());
        $qb->select(
            'q.id_snt_quote, q.reference, q.version, q.status, q.total_ti, q.total_te, q.id_currency, q.valid_until, q.date_add, q.id_order, q.id_shop, q.project_name',
            'c.company AS customer_company',
            'CONCAT(c.firstname, \' \', c.lastname) AS customer_name',
            'COALESCE(NULLIF(c.company, \'\'), c.lastname) AS customer', // alias de tri
            'CONCAT(e.firstname, \' \', e.lastname) AS employee_name',
            'e.lastname AS employee', // alias de tri
            'o.reference AS order_reference'
        );
        $this->criteriaApplicator
            ->applyPagination($searchCriteria, $qb)
            ->applySorting($searchCriteria, $qb);

        return $qb;
    }

    public function getCountQueryBuilder(SearchCriteriaInterface $searchCriteria): QueryBuilder
    {
        return $this->getBaseQuery($searchCriteria->getFilters())->select('COUNT(DISTINCT q.id_snt_quote)');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function getBaseQuery(array $filters): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->from($this->dbPrefix . 'snt_quote', 'q')
            ->leftJoin('q', $this->dbPrefix . 'customer', 'c', 'c.id_customer = q.id_customer')
            ->leftJoin('q', $this->dbPrefix . 'employee', 'e', 'e.id_employee = q.id_employee')
            ->leftJoin('q', $this->dbPrefix . 'orders', 'o', 'o.id_order = q.id_order')
            ->where('q.deleted = 0')
            ->andWhere('q.id_shop IN (:shops)')
            ->setParameter('shops', $this->contextShopIds(), Connection::PARAM_INT_ARRAY);

        if ($this->quickTab === self::TAB_TODO) {
            $qb->andWhere('q.status IN (:tab_statuses)')
                ->andWhere('q.id_order IS NULL')
                ->setParameter('tab_statuses', array_map(static fn (QuoteStatus $s): string => $s->value, QuoteStatus::toProcess()), Connection::PARAM_STR_ARRAY);
        } elseif ($this->quickTab === self::TAB_WAITING) {
            $qb->andWhere('q.status IN (:tab_statuses)')
                ->setParameter('tab_statuses', array_map(static fn (QuoteStatus $s): string => $s->value, QuoteStatus::waitingCustomer()), Connection::PARAM_STR_ARRAY);
        }

        foreach ($filters as $name => $value) {
            if ($value === '' || $value === null || $value === []) {
                continue;
            }
            switch ($name) {
                case 'reference':
                    $qb->andWhere('q.reference LIKE :reference')->setParameter('reference', '%' . $value . '%');
                    break;
                case 'customer':
                    $qb->andWhere('(c.company LIKE :customer OR c.lastname LIKE :customer OR c.firstname LIKE :customer OR c.email LIKE :customer OR CONCAT(c.firstname, \' \', c.lastname) LIKE :customer)')
                        ->setParameter('customer', '%' . $value . '%');
                    break;
                case 'employee':
                    $qb->andWhere('(e.lastname LIKE :employee OR e.firstname LIKE :employee)')->setParameter('employee', '%' . $value . '%');
                    break;
                case 'status':
                    $qb->andWhere('q.status = :status')->setParameter('status', (string) $value);
                    break;
                case 'total_ti':
                    $this->applyAmountFilter($qb, (string) $value);
                    break;
                case 'date_add':
                    if (!empty($value['from'])) {
                        $qb->andWhere('q.date_add >= :date_from')->setParameter('date_from', $value['from'] . ' 00:00:00');
                    }
                    if (!empty($value['to'])) {
                        $qb->andWhere('q.date_add <= :date_to')->setParameter('date_to', $value['to'] . ' 23:59:59');
                    }
                    break;
                case 'valid_until':
                    if (!empty($value['from'])) {
                        $qb->andWhere('q.valid_until >= :valid_from')->setParameter('valid_from', $value['from']);
                    }
                    if (!empty($value['to'])) {
                        $qb->andWhere('q.valid_until <= :valid_to')->setParameter('valid_to', $value['to']);
                    }
                    break;
                case 'order_reference':
                    $qb->andWhere('o.reference LIKE :order_ref')->setParameter('order_ref', '%' . $value . '%');
                    break;
                default:
                    break;
            }
        }

        return $qb;
    }

    /**
     * « 1000 » = égal ; « >1000 », « <500 », « 500-1000 » acceptés.
     */
    private function applyAmountFilter(QueryBuilder $qb, string $value): void
    {
        $value = str_replace([' ', ','], ['', '.'], $value);
        if (preg_match('/^(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/', $value, $m)) {
            $qb->andWhere('q.total_ti BETWEEN :amount_min AND :amount_max')
                ->setParameter('amount_min', (float) $m[1])
                ->setParameter('amount_max', (float) $m[2]);
        } elseif (preg_match('/^([<>]=?)(\d+(?:\.\d+)?)$/', $value, $m)) {
            $qb->andWhere('q.total_ti ' . $m[1] . ' :amount')->setParameter('amount', (float) $m[2]);
        } elseif (is_numeric($value)) {
            $qb->andWhere('q.total_ti BETWEEN :amount_min AND :amount_max')
                ->setParameter('amount_min', (float) $value - 0.005)
                ->setParameter('amount_max', (float) $value + 0.005);
        }
    }

    /**
     * @return int[]
     */
    private function contextShopIds(): array
    {
        $ids = \Shop::getContextListShopID();

        return $ids ? array_map('intval', $ids) : [(int) \Context::getContext()->shop->id];
    }
}
