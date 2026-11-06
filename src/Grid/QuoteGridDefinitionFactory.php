<?php

declare(strict_types=1);

namespace Snt\Quotes\Grid;

use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\BulkActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction;
use PrestaShop\PrestaShop\Core\Grid\Action\GridActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollection;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\RowActionCollectionInterface;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\SubmitRowAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Type\SimpleGridAction;
use PrestaShop\PrestaShop\Core\Grid\Column\ColumnCollection;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\ActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\BulkActionColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\HtmlColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\Common\LinkColumn;
use PrestaShop\PrestaShop\Core\Grid\Column\Type\DataColumn;
use PrestaShop\PrestaShop\Core\Grid\Definition\Factory\AbstractGridDefinitionFactory;
use PrestaShop\PrestaShop\Core\Grid\Filter\Filter;
use PrestaShop\PrestaShop\Core\Grid\Filter\FilterCollection;
use PrestaShop\PrestaShop\Core\Hook\HookDispatcherInterface;
use PrestaShopBundle\Form\Admin\Type\DateRangeType;
use PrestaShopBundle\Form\Admin\Type\SearchAndResetType;
use Snt\Quotes\Presenter\StatusPresenter;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Liste des devis. Colonnes : Référence, Client, Commercial, Montant TTC, Statut, Validité, Date, Commande liée.
 */
final class QuoteGridDefinitionFactory extends AbstractGridDefinitionFactory
{
    public const GRID_ID = 'snt_quote';
    private const DOMAIN = 'Modules.Sntquotes.Admin';

    public function __construct(HookDispatcherInterface $hookDispatcher, private readonly StatusPresenter $statusPresenter)
    {
        parent::__construct($hookDispatcher);
    }

    protected function getId()
    {
        return self::GRID_ID;
    }

    protected function getName()
    {
        return $this->trans('Quotes', [], self::DOMAIN);
    }

    protected function getColumns()
    {
        return (new ColumnCollection())
            ->add((new BulkActionColumn('bulk'))->setOptions(['bulk_field' => 'id_snt_quote']))
            ->add((new LinkColumn('reference'))
                ->setName($this->trans('Reference', [], self::DOMAIN))
                ->setOptions([
                    'field' => 'reference_display',
                    'route' => 'admin_snt_quotes_view',
                    'route_param_name' => 'quoteId',
                    'route_param_field' => 'id_snt_quote',
                ]))
            ->add((new HtmlColumn('customer'))
                ->setName($this->trans('Customer', [], self::DOMAIN))
                ->setOptions(['field' => 'customer_html']))
            ->add((new DataColumn('employee'))
                ->setName($this->trans('Sales rep', [], self::DOMAIN))
                ->setOptions(['field' => 'employee_name']))
            ->add((new DataColumn('total_ti'))
                ->setName($this->trans('Amount incl. tax', [], self::DOMAIN))
                ->setOptions(['field' => 'total_ti_display']))
            ->add((new HtmlColumn('status'))
                ->setName($this->trans('Status', [], self::DOMAIN))
                ->setOptions(['field' => 'status_html']))
            ->add((new HtmlColumn('valid_until'))
                ->setName($this->trans('Valid until', [], self::DOMAIN))
                ->setOptions(['field' => 'valid_until_html']))
            ->add((new DataColumn('date_add'))
                ->setName($this->trans('Date', [], self::DOMAIN))
                ->setOptions(['field' => 'date_add_display']))
            ->add((new HtmlColumn('order_reference'))
                ->setName($this->trans('Order', [], self::DOMAIN))
                ->setOptions(['field' => 'order_html']))
            ->add((new ActionColumn('actions'))
                ->setName($this->trans('Actions', [], 'Admin.Global'))
                ->setOptions(['actions' => $this->getRowActions()]));
    }

    private function getRowActions(): RowActionCollectionInterface
    {
        return (new RowActionCollection())
            ->add((new LinkRowAction('view'))
                ->setName($this->trans('View', [], 'Admin.Actions'))
                ->setIcon('visibility')
                ->setOptions([
                    'route' => 'admin_snt_quotes_view',
                    'route_param_name' => 'quoteId',
                    'route_param_field' => 'id_snt_quote',
                    'clickable_row' => true,
                ]))
            ->add((new SubmitRowAction('duplicate'))
                ->setName($this->trans('Duplicate', [], 'Admin.Actions'))
                ->setIcon('content_copy')
                ->setOptions([
                    'route' => 'admin_snt_quotes_duplicate',
                    'route_param_name' => 'quoteId',
                    'route_param_field' => 'id_snt_quote',
                    'confirm_message' => $this->trans('Create a new draft quote from this one?', [], self::DOMAIN),
                ]))
            ->add((new LinkRowAction('pdf'))
                ->setName($this->trans('Download the PDF', [], self::DOMAIN))
                ->setIcon('picture_as_pdf')
                ->setOptions([
                    'route' => 'admin_snt_quotes_pdf',
                    'route_param_name' => 'quoteId',
                    'route_param_field' => 'id_snt_quote',
                    'target' => '_blank',
                ]));
    }

    protected function getFilters()
    {
        return (new FilterCollection())
            ->add((new Filter('reference', TextType::class))
                ->setTypeOptions(['required' => false, 'attr' => ['placeholder' => $this->trans('Reference', [], self::DOMAIN)]])
                ->setAssociatedColumn('reference'))
            ->add((new Filter('customer', TextType::class))
                ->setTypeOptions(['required' => false, 'attr' => ['placeholder' => $this->trans('Company, name, e-mail', [], self::DOMAIN)]])
                ->setAssociatedColumn('customer'))
            ->add((new Filter('employee', TextType::class))
                ->setTypeOptions(['required' => false, 'attr' => ['placeholder' => $this->trans('Sales rep', [], self::DOMAIN)]])
                ->setAssociatedColumn('employee'))
            ->add((new Filter('total_ti', TextType::class))
                ->setTypeOptions(['required' => false, 'attr' => ['placeholder' => $this->trans('e.g. >1000 or 500-1000', [], self::DOMAIN)]])
                ->setAssociatedColumn('total_ti'))
            ->add((new Filter('status', ChoiceType::class))
                ->setTypeOptions([
                    'required' => false,
                    'choices' => $this->statusPresenter->choices(),
                    'choice_translation_domain' => false,
                ])
                ->setAssociatedColumn('status'))
            ->add((new Filter('valid_until', DateRangeType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('valid_until'))
            ->add((new Filter('date_add', DateRangeType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('date_add'))
            ->add((new Filter('order_reference', TextType::class))
                ->setTypeOptions(['required' => false])
                ->setAssociatedColumn('order_reference'))
            ->add((new Filter('actions', SearchAndResetType::class))
                ->setTypeOptions([
                    'reset_route' => 'admin_common_reset_search_by_filter_id',
                    'reset_route_params' => ['filterId' => self::GRID_ID],
                    'redirect_route' => 'admin_snt_quotes_index',
                ])
                ->setAssociatedColumn('actions'));
    }

    protected function getGridActions()
    {
        return (new GridActionCollection())
            ->add((new SimpleGridAction('common_refresh_list'))
                ->setName($this->trans('Refresh list', [], 'Admin.Advparameters.Feature'))
                ->setIcon('refresh'));
    }

    protected function getBulkActions()
    {
        return (new BulkActionCollection())
            ->add((new SubmitBulkAction('remind'))
                ->setName($this->trans('Send a reminder', [], self::DOMAIN))
                ->setOptions(['submit_route' => 'admin_snt_quotes_bulk_remind']))
            ->add((new SubmitBulkAction('expire'))
                ->setName($this->trans('Mark as expired', [], self::DOMAIN))
                ->setOptions([
                    'submit_route' => 'admin_snt_quotes_bulk_expire',
                    'confirm_message' => $this->trans('The selected quotes will be marked as expired. Customers will no longer be able to accept them.', [], self::DOMAIN),
                ]));
    }
}
