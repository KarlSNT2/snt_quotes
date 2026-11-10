<?php

declare(strict_types=1);

namespace Snt\Quotes\Presenter;

use Snt\Quotes\Adapter\PrestaShopRounder;
use Snt\Quotes\Calculator\CalcLine;
use Snt\Quotes\Calculator\QuoteCalculator;
use Snt\Quotes\Calculator\RoundingPolicy;
use Snt\Quotes\Domain\ReductionType;

/**
 * Vue d'un devis enregistré, partagée par la fiche BO, le PDF, les e-mails et le FO :
 * mêmes libellés, mêmes montants, mêmes badges.
 */
class QuotePresenter
{
    public function __construct(private readonly StatusPresenter $statusPresenter)
    {
    }

    public function statusPresenter(): StatusPresenter
    {
        return $this->statusPresenter;
    }

    /**
     * État initial de l'éditeur (JSON).
     *
     * @return array<string, mixed>
     */
    public function toEditorPayload(\SntQuote $quote): array
    {
        $lines = [];
        foreach ($quote->getLines() as $line) {
            $lines[] = [
                'line_type' => (string) $line->line_type,
                'id_product' => (int) $line->id_product,
                'id_product_attribute' => (int) $line->id_product_attribute,
                'label' => (string) $line->label,
                'reference' => (string) $line->reference,
                'qty' => (int) $line->qty,
                'catalog_price_te' => $line->catalog_price_te !== null ? (float) $line->catalog_price_te : null,
                'unit_price_te' => (float) $line->unit_price_te,
                'reduction_type' => $line->reduction_type,
                'reduction_value' => (float) $line->reduction_value,
                'id_tax_rules_group' => (int) $line->id_tax_rules_group,
                'tax_rate' => (float) $line->tax_rate,
                'wholesale_price' => $line->wholesale_price !== null ? (float) $line->wholesale_price : null,
                'comment' => (string) $line->comment,
            ];
        }
        $vouchers = [];
        foreach ($quote->getDiscounts() as $discount) {
            if ($discount->scope !== \SntQuoteDiscount::SCOPE_VOUCHER) {
                continue;
            }
            $params = json_decode((string) $discount->dedicated_params, true) ?: [];
            $vouchers[] = [
                'id_cart_rule' => (int) $discount->id_cart_rule,
                'code' => (string) $discount->code,
                'label' => (string) $discount->label,
                'reduction_type' => (string) $discount->reduction_type,
                'reduction_value' => (float) $discount->reduction_value,
                'is_dedicated' => (bool) $discount->is_dedicated,
                'date_to' => $params['date_to'] ?? null,
                'minimum_amount' => $params['minimum_amount'] ?? 0,
            ];
        }

        return [
            'id_snt_quote' => (int) $quote->id,
            'id_customer' => (int) $quote->id_customer,
            'id_employee' => (int) $quote->id_employee,
            'id_currency' => (int) $quote->id_currency,
            'id_address_delivery' => (int) $quote->id_address_delivery,
            'id_address_invoice' => (int) $quote->id_address_invoice,
            'project_name' => (string) $quote->project_name,
            'lines' => $lines,
            'delivery_mode' => (string) $quote->delivery_mode,
            'id_carrier' => (int) $quote->id_carrier_real,
            'carrier_label' => (string) $quote->carrier_label,
            'shipping_te' => (float) $quote->shipping_tax_excl,
            'shipping_discount_te' => (float) $quote->shipping_discount_te,
            'free_shipping' => (bool) $quote->free_shipping,
            'id_tax_rules_group_shipping' => (int) $quote->id_tax_rules_group_shipping,
            'relay' => $quote->getRelayData(),
            'global_discount_type' => (string) $quote->global_discount_type,
            'global_discount_value' => (float) $quote->global_discount_value,
            'vouchers' => $vouchers,
            'valid_until' => (string) $quote->valid_until,
            'allowed_payment_methods' => $quote->getAllowedPaymentMethods(),
            'customer_message' => (string) $quote->customer_message,
            'internal_note' => (string) $quote->internal_note,
        ];
    }

    /**
     * Vue complète pour l'affichage.
     *
     * @param bool $forCustomer masque la note interne, les prix d'achat et les marges
     *
     * @return array<string, mixed>
     */
    public function present(\SntQuote $quote, bool $forCustomer = false): array
    {
        $currency = new \Currency((int) $quote->id_currency);
        $locale = \Context::getContext()->getCurrentLocale();
        $money = static fn (float $amount): string => $locale ? $locale->formatPrice($amount, (string) $currency->iso_code) : number_format($amount, 2, ',', ' ') . ' ' . $currency->sign;
        $policy = PrestaShopRounder::policy();

        $lines = [];
        foreach ($quote->getLines() as $line) {
            $type = $line->getType();
            $calc = new CalcLine($type, (int) $line->qty, (float) $line->unit_price_te, (float) $line->tax_rate, $line->getReductionType(), (float) $line->reduction_value);
            $unitNet = QuoteCalculator::unitNet($calc);
            $unitTi = $policy->round($unitNet * (1 + (float) $line->tax_rate / 100));
            $hasReduction = $type->hasAmount() && $unitNet < (float) $line->unit_price_te;
            // Total TTC de la ligne, arrondi comme le calculateur (par article / par ligne / sur le total).
            $rawUnitTi = $unitNet * (1 + (float) $line->tax_rate / 100);
            $lineTi = $policy->roundType === RoundingPolicy::ROUND_ITEM
                ? $policy->round($policy->round($rawUnitTi) * (int) $line->qty)
                : $policy->round($rawUnitTi * (int) $line->qty);
            $row = [
                'type' => $type->value,
                'has_amount' => $type->hasAmount(),
                'label' => (string) $line->label,
                'reference' => (string) $line->reference,
                'qty' => (int) $line->qty,
                'unit_price_te' => (float) $line->unit_price_te,
                'unit_price_te_display' => $money((float) $line->unit_price_te),
                'unit_net_te' => $unitNet,
                'unit_net_te_display' => $money($policy->round($unitNet)),
                'unit_net_ti_display' => $money($unitTi),
                'has_reduction' => $hasReduction,
                'reduction_display' => $this->reductionLabel($line->getReductionType(), (float) $line->reduction_value, $money),
                'total_te' => (float) $line->total_te,
                'total_te_display' => $money((float) $line->total_te),
                'total_ti_display' => $money($lineTi),
                'unit_price_ti_display' => $money($policy->round((float) $line->unit_price_te * (1 + (float) $line->tax_rate / 100))),
                'tax_rate' => (float) $line->tax_rate,
                'tax_rate_display' => rtrim(rtrim(number_format((float) $line->tax_rate, 3, ',', ''), '0'), ','),
                'comment' => (string) $line->comment,
                'id_product' => (int) $line->id_product,
                'id_product_attribute' => (int) $line->id_product_attribute,
            ];
            if (!$forCustomer) {
                $row['wholesale_price'] = $line->wholesale_price !== null ? (float) $line->wholesale_price : null;
                $row['margin_display'] = $line->wholesale_price !== null && (float) $line->wholesale_price > 0
                    ? $money(($unitNet - (float) $line->wholesale_price) * (int) $line->qty)
                    : null;
                $row['below_cost'] = $line->wholesale_price !== null && (float) $line->wholesale_price > 0 && $unitNet < (float) $line->wholesale_price;
                $row['catalog_price_te_display'] = $line->catalog_price_te !== null ? $money((float) $line->catalog_price_te) : null;
            }
            $lines[] = $row;
        }

        $discounts = [];
        foreach ($quote->getDiscounts() as $discount) {
            $discounts[] = [
                'scope' => (string) $discount->scope,
                'label' => (string) $discount->label,
                'code' => (string) $discount->code,
                'is_dedicated' => (bool) $discount->is_dedicated,
                'reduction_display' => $this->reductionLabel($discount->getReductionType(), (float) $discount->reduction_value, $money),
                'amount_te' => (float) $discount->amount_te,
                'amount_te_display' => $money((float) $discount->amount_te),
                'amount_ti_display' => $money((float) $discount->amount_ti),
            ];
        }

        $taxes = [];
        foreach ($quote->getTaxBreakdown() as $row) {
            $taxes[] = [
                'rate_display' => rtrim(rtrim(number_format((float) $row['rate'], 3, ',', ''), '0'), ','),
                'base_display' => $money((float) $row['base']),
                'tax_display' => $money((float) $row['tax']),
            ];
        }

        $customer = new \Customer((int) $quote->id_customer);
        $employee = $quote->id_employee ? new \Employee((int) $quote->id_employee) : null;
        $status = $quote->getStatus();
        $daysLeft = null;
        if ($quote->valid_until) {
            $daysLeft = (int) (new \DateTimeImmutable('today'))->diff(new \DateTimeImmutable((string) $quote->valid_until))->format('%r%a');
        }
        $shippingGross = (float) $quote->shipping_tax_excl;
        $shippingNet = (float) $quote->total_shipping_te;
        $shippingTi = $policy->round($shippingNet * (1 + (float) $quote->shipping_tax_rate / 100));
        // Totaux TTC non stockés : remises TTC = somme des remises, produits TTC = le reste, pour que
        // produits − remises + port = total TTC affiché, au centime.
        $discountsTi = 0.0;
        foreach ($quote->getDiscounts() as $discount) {
            $discountsTi += (float) $discount->amount_ti;
        }
        $productsTi = $policy->round((float) $quote->total_ti - $shippingTi + $discountsTi);

        $view = [
            'id' => (int) $quote->id,
            'reference' => (string) $quote->reference,
            'version' => (int) $quote->version,
            'full_reference' => $quote->getFullReference(),
            'project_name' => (string) $quote->project_name,
            'status' => $this->statusPresenter->present($status),
            'is_editable' => $quote->isEditable(),
            'date_add' => (string) $quote->date_add,
            'date_add_display' => \Tools::displayDate((string) $quote->date_add, false),
            'sent_at_display' => $quote->sent_at ? \Tools::displayDate((string) $quote->sent_at, false) : null,
            'valid_until' => (string) $quote->valid_until,
            'valid_until_display' => $quote->valid_until ? \Tools::displayDate((string) $quote->valid_until, false) : null,
            'days_left' => $daysLeft,
            'validity_warning' => $daysLeft !== null && $daysLeft < 7 && $status->isOpen(),
            'currency_iso' => (string) $currency->iso_code,
            'customer' => [
                'id' => (int) $customer->id,
                'name' => trim($customer->firstname . ' ' . $customer->lastname),
                'company' => (string) $customer->company,
                'email' => (string) $customer->email,
                'siret' => (string) $customer->siret,
            ],
            'employee' => $employee && \Validate::isLoadedObject($employee) ? [
                'id' => (int) $employee->id,
                'name' => trim($employee->firstname . ' ' . $employee->lastname),
                'email' => (string) $employee->email,
                'phone' => $this->employeePhone((int) $quote->id_shop),
            ] : null,
            'address_delivery' => $this->formatAddress((int) $quote->id_address_delivery),
            'address_invoice' => $this->formatAddress((int) $quote->id_address_invoice),
            'lines' => $lines,
            'discounts' => $discounts,
            'delivery_mode' => (string) $quote->delivery_mode,
            'carrier_label' => (string) $quote->carrier_label,
            'id_carrier' => (int) $quote->id_carrier_real,
            'relay' => $quote->getRelayData(),
            'shipping_gross_te_display' => $money($shippingGross),
            'shipping_te' => $shippingNet,
            'shipping_te_display' => $money($shippingNet),
            'shipping_ti_display' => $money($shippingTi),
            'free_shipping' => (bool) $quote->free_shipping || ($shippingGross > 0 && $shippingNet <= 0),
            'shipping_discounted' => $shippingGross > $shippingNet,
            'tax_exempt' => (bool) $quote->tax_exempt,
            'vat_number' => (string) $quote->vat_number,
            'taxes' => $taxes,
            'total_products_te_display' => $money((float) $quote->total_products_te),
            'total_discount_te' => (float) $quote->total_discount_te,
            'total_discount_te_display' => $money((float) $quote->total_discount_te),
            'total_products_ti_display' => $money($productsTi),
            'total_discount_ti_display' => $money($policy->round($discountsTi)),
            'total_te' => (float) $quote->total_te,
            'total_te_display' => $money((float) $quote->total_te),
            'total_tax_display' => $money((float) $quote->total_tax),
            'total_ti' => (float) $quote->total_ti,
            'total_ti_display' => $money((float) $quote->total_ti),
            'payment_methods' => $quote->getAllowedPaymentMethods(),
            'customer_message' => (string) $quote->customer_message,
            'id_order' => (int) $quote->id_order,
        ];
        if (!$forCustomer) {
            $view['internal_note'] = (string) $quote->internal_note;
            $view['margin_total_display'] = $this->marginTotal($lines, $money);
        }

        return $view;
    }

    /**
     * @param callable(float): string $money
     */
    private function reductionLabel(?ReductionType $type, float $value, callable $money): ?string
    {
        if (!$type || $value <= 0) {
            return null;
        }

        return $type === ReductionType::Percent
            ? '-' . rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',') . ' %'
            : '-' . $money($value);
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     * @param callable(float): string          $money
     */
    private function marginTotal(array $lines, callable $money): ?string
    {
        $margin = 0.0;
        $known = false;
        foreach ($lines as $line) {
            if (!empty($line['has_amount']) && isset($line['wholesale_price']) && $line['wholesale_price'] > 0) {
                $margin += ($line['unit_net_te'] - $line['wholesale_price']) * $line['qty'];
                $known = true;
            }
        }

        return $known ? $money($margin) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatAddress(int $idAddress): ?array
    {
        if (!$idAddress) {
            return null;
        }
        $address = new \Address($idAddress);
        if (!\Validate::isLoadedObject($address)) {
            return null;
        }

        return [
            'id' => $idAddress,
            'alias' => (string) $address->alias,
            'company' => (string) $address->company,
            'name' => trim($address->firstname . ' ' . $address->lastname),
            'lines' => \AddressFormat::generateAddress($address, [], '<br>'),
            'lines_text' => \AddressFormat::generateAddress($address, [], "\n"),
            'country' => (string) \Country::getNameById((int) \Context::getContext()->language->id, (int) $address->id_country),
            'vat_number' => (string) $address->vat_number,
            'phone' => (string) ($address->phone ?: $address->phone_mobile),
        ];
    }

    /**
     * Pas de champ téléphone natif sur Employee : téléphone de la boutique du devis.
     */
    private function employeePhone(int $idShop): string
    {
        return (string) \Configuration::get('PS_SHOP_PHONE', null, null, $idShop);
    }
}
