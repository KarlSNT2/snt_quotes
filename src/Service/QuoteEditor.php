<?php

declare(strict_types=1);

namespace Snt\Quotes\Service;

use Snt\Quotes\Adapter\CatalogPriceProvider;
use Snt\Quotes\Adapter\PrestaShopRounder;
use Snt\Quotes\Adapter\TaxResolver;
use Snt\Quotes\Calculator\CalcDiscount;
use Snt\Quotes\Calculator\CalcInput;
use Snt\Quotes\Calculator\CalcLine;
use Snt\Quotes\Calculator\DiscountGuard;
use Snt\Quotes\Calculator\QuoteCalculator;
use Snt\Quotes\Calculator\QuoteTotals;
use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Domain\LineType;
use Snt\Quotes\Domain\ReductionType;

/**
 * Applique le contenu envoyé par l'éditeur BO à un devis.
 *
 * Tout est revalidé côté serveur : prix catalogue, prix d'achat, taux de TVA, transporteur, bons.
 * Le navigateur n'envoie que des intentions (produit, quantité, prix saisi, remise…), jamais de total.
 */
class QuoteEditor
{
    public const PAYMENT_METHODS = ['card', 'transfer', 'deferred', 'pending'];
    public const DELIVERY_MODES = ['carrier', 'pickup', 'special'];
    private const MAX_LINES = 500;

    public function __construct(
        private readonly TaxResolver $taxResolver,
        private readonly CatalogPriceProvider $catalog,
        private readonly ModuleConfig $config,
        private readonly HistoryLogger $history,
    ) {
    }

    /**
     * Calcule sans enregistrer (récapitulatif AJAX).
     *
     * @param array<string, mixed> $payload
     */
    public function preview(\SntQuote $quote, array $payload): EditorResult
    {
        return $this->compute($quote, $this->normalize($quote, $payload));
    }

    /**
     * Valide, calcule et enregistre (en-tête, lignes, remises, totaux).
     *
     * @param array<string, mixed> $payload
     */
    public function save(\SntQuote $quote, array $payload, bool $logHistory = true): EditorResult
    {
        if ($quote->id && !$quote->isEditable()) {
            throw new QuoteException('This quote can no longer be modified. Create a new version to change it.');
        }
        $data = $this->normalize($quote, $payload);
        $result = $this->compute($quote, $data);
        $totals = $result->totals;
        $h = $data['header'];

        $isNew = !$quote->id;
        foreach ($h as $field => $value) {
            $quote->{$field} = $value;
        }
        $quote->total_products_te = $totals->productsTe;
        $quote->total_discount_te = $totals->discountsTe;
        $quote->total_shipping_te = $totals->shippingTe;
        $quote->total_te = $totals->totalTe;
        $quote->total_tax = $totals->totalTax;
        $quote->total_ti = $totals->totalTi;
        $quote->tax_breakdown = (string) json_encode(array_values($totals->taxBreakdown));

        $db = \Db::getInstance();
        $db->execute('START TRANSACTION');
        try {
            if (!($isNew ? $quote->add() : $quote->update())) {
                throw new \RuntimeException('Quote header could not be saved.');
            }
            $this->replaceLines($quote, $data['lines'], $totals);
            $this->replaceDiscounts($quote, $data['discounts'], $totals);
            $db->execute('COMMIT');
        } catch (\Throwable $e) {
            $db->execute('ROLLBACK');
            throw $e;
        }

        if ($logHistory) {
            $this->history->log($quote, $isNew ? \SntQuoteHistory::EVENT_CREATED : \SntQuoteHistory::EVENT_UPDATED, null, [
                'total_te' => $totals->totalTe,
                'total_ti' => $totals->totalTi,
            ]);
        }

        return $result;
    }

    /**
     * Reconstitue l'entrée du calculateur depuis un devis enregistré (PDF, FO, conversion).
     */
    public function calcInputFromQuote(\SntQuote $quote): CalcInput
    {
        $lines = [];
        foreach ($quote->getLines() as $line) {
            $lines[] = new CalcLine(
                $line->getType(),
                (int) $line->qty,
                (float) $line->unit_price_te,
                (float) $line->tax_rate,
                $line->getReductionType(),
                (float) $line->reduction_value,
                $line->wholesale_price !== null ? (float) $line->wholesale_price : null,
                $line->catalog_price_te !== null ? (float) $line->catalog_price_te : null,
            );
        }

        return new CalcInput(
            $lines,
            $this->calcDiscountsFromQuote($quote),
            (float) $quote->shipping_tax_excl,
            (float) $quote->shipping_tax_rate,
            (float) $quote->shipping_discount_te,
            (bool) $quote->free_shipping,
            (bool) $quote->tax_exempt
        );
    }

    public function totalsFromQuote(\SntQuote $quote): QuoteTotals
    {
        return $this->calculator()->calculate($this->calcInputFromQuote($quote));
    }

    /**
     * @return CalcDiscount[]
     */
    private function calcDiscountsFromQuote(\SntQuote $quote): array
    {
        $out = [];
        if ($quote->global_discount_type && (float) $quote->global_discount_value > 0) {
            $out[] = new CalcDiscount(ReductionType::from((string) $quote->global_discount_type), (float) $quote->global_discount_value);
        }
        foreach ($quote->getDiscounts() as $discount) {
            if ($discount->scope === \SntQuoteDiscount::SCOPE_VOUCHER) {
                $out[] = new CalcDiscount($discount->getReductionType(), (float) $discount->reduction_value, (string) $discount->label);
            }
        }

        return $out;
    }

    private function calculator(): QuoteCalculator
    {
        return new QuoteCalculator(PrestaShopRounder::policy());
    }

    /**
     * @param array{header: array<string, mixed>, lines: array<int, array<string, mixed>>, discounts: array<int, array<string, mixed>>, global: ?CalcDiscount} $data
     */
    private function compute(\SntQuote $quote, array $data): EditorResult
    {
        $calcLines = array_map(static fn (array $l): CalcLine => new CalcLine(
            LineType::from($l['line_type']),
            (int) $l['qty'],
            (float) $l['unit_price_te'],
            (float) $l['tax_rate'],
            $l['reduction_type'] ? ReductionType::from($l['reduction_type']) : null,
            (float) $l['reduction_value'],
            $l['wholesale_price'],
            $l['catalog_price_te'],
        ), $data['lines']);

        $calcDiscounts = $data['global'] ? [$data['global']] : [];
        foreach ($data['discounts'] as $d) {
            $calcDiscounts[] = new CalcDiscount(ReductionType::from($d['reduction_type']), (float) $d['reduction_value'], (string) $d['label']);
        }

        $h = $data['header'];
        $input = new CalcInput(
            $calcLines,
            $calcDiscounts,
            (float) $h['shipping_tax_excl'],
            (float) $h['shipping_tax_rate'],
            (float) $h['shipping_discount_te'],
            (bool) $h['free_shipping'],
            (bool) $h['tax_exempt']
        );
        $totals = $this->calculator()->calculate($input);

        $guard = new DiscountGuard($this->config->getFloat(ConfigKeys::DISCOUNT_THRESHOLD, (int) $h['id_shop']));
        $warnings = [];
        foreach ($guard->linesBelowCost($totals) as $index) {
            $warnings[] = ['type' => 'below_cost', 'line' => $index];
        }
        foreach ($data['lines'] as $index => $line) {
            if ($line['line_type'] === LineType::Product->value && $line['stock'] !== null && $line['stock'] < (int) $line['qty']) {
                $warnings[] = ['type' => 'stock', 'line' => $index, 'stock' => $line['stock']];
            }
        }

        return new EditorResult(
            $totals,
            $warnings,
            $guard->requiresApproval($input, $totals),
            $guard->maxDiscountPercent($input, $totals),
            $data['lines']
        );
    }

    /**
     * Contrôle et enrichit le contenu envoyé.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{header: array<string, mixed>, lines: array<int, array<string, mixed>>, discounts: array<int, array<string, mixed>>, global: ?CalcDiscount}
     */
    private function normalize(\SntQuote $quote, array $payload): array
    {
        $context = \Context::getContext();
        $idShop = (int) ($quote->id_shop ?: $context->shop->id);
        $idLang = (int) ($quote->id_lang ?: $context->language->id);

        // --- Client et adresses
        $idCustomer = (int) ($payload['id_customer'] ?? 0);
        if ($idCustomer <= 0 || !\Validate::isLoadedObject($customer = new \Customer($idCustomer)) || $customer->deleted) {
            throw new QuoteException('Choose a customer for this quote.');
        }
        $idAddressDelivery = $this->customerAddress((int) ($payload['id_address_delivery'] ?? 0), $idCustomer);
        $idAddressInvoice = $this->customerAddress((int) ($payload['id_address_invoice'] ?? 0), $idCustomer) ?: $idAddressDelivery;
        $idLang = (int) $customer->id_lang ?: $idLang;

        $idCurrency = (int) ($quote->id_currency ?: ($payload['id_currency'] ?? 0)) ?: (int) $context->currency->id;
        $currency = new \Currency($idCurrency);
        if (!\Validate::isLoadedObject($currency) || !$currency->active) {
            throw new QuoteException('The selected currency is not available.');
        }

        $taxAddress = $this->taxResolver->taxAddressId($idAddressDelivery, $idAddressInvoice);
        $taxExempt = $this->taxResolver->isTaxExempt($taxAddress);

        // --- Lignes
        $rawLines = is_array($payload['lines'] ?? null) ? array_values($payload['lines']) : [];
        if (count($rawLines) > self::MAX_LINES) {
            throw new QuoteException('A quote cannot contain more than %max% lines.', ['%max%' => self::MAX_LINES]);
        }
        $lines = [];
        foreach ($rawLines as $position => $raw) {
            $lines[] = $this->normalizeLine((array) $raw, $position, $idCustomer, $taxAddress, $idCurrency, $idShop, $idLang);
        }

        // --- Livraison
        // Absent (ex. demande depuis le panier) ou inconnu → « Transporteur ».
        $deliveryMode = (string) ($payload['delivery_mode'] ?? 'carrier');
        $deliveryMode = in_array($deliveryMode, self::DELIVERY_MODES, true) ? $deliveryMode : 'carrier';
        $idCarrier = (int) ($payload['id_carrier'] ?? 0);
        $carrierLabel = null;
        $idCarrierReference = null;
        if ($idCarrier) {
            $carrier = new \Carrier($idCarrier, $idLang);
            if (!\Validate::isLoadedObject($carrier) || $carrier->deleted) {
                throw new QuoteException('The selected carrier no longer exists. Choose another one.');
            }
            $carrierLabel = (string) $carrier->name;
            $idCarrierReference = (int) $carrier->id_reference;
        } elseif ($deliveryMode === 'carrier') {
            $idCarrier = 0;
        }
        if ($deliveryMode === 'special' && trim((string) ($payload['carrier_label'] ?? '')) !== '') {
            $carrierLabel = mb_substr(trim(strip_tags((string) $payload['carrier_label'])), 0, 128);
        }
        if ($deliveryMode === 'pickup') {
            $carrierLabel = $carrierLabel ?: 'Retrait';
        }

        $shippingTe = $deliveryMode === 'pickup' ? 0.0 : $this->money($payload['shipping_te'] ?? 0);
        $idTaxShipping = $idCarrier ? $this->taxResolver->carrierTaxRulesGroup($idCarrier) : (int) ($payload['id_tax_rules_group_shipping'] ?? 0);
        $shippingRate = $taxExempt ? 0.0 : $this->taxResolver->rate($idTaxShipping, $taxAddress);
        $freeShipping = !empty($payload['free_shipping']);
        $shippingDiscount = min($shippingTe, $this->money($payload['shipping_discount_te'] ?? 0));

        $relay = null;
        if (!empty($payload['relay']) && is_array($payload['relay'])) {
            $relay = $this->normalizeRelay($payload['relay']);
        }

        // --- Remises
        $global = null;
        $globalType = (string) ($payload['global_discount_type'] ?? '');
        $globalValue = $this->money($payload['global_discount_value'] ?? 0);
        if ($globalValue > 0 && ReductionType::tryFrom($globalType)) {
            if ($globalType === ReductionType::Percent->value && $globalValue > 100) {
                throw new QuoteException('A percentage discount cannot exceed 100%.');
            }
            $global = new CalcDiscount(ReductionType::from($globalType), $globalValue, 'global');
        }
        // Taux moyen pondéré des produits : sert à ramener en HT un bon exprimé TTC.
        $baseTe = 0.0;
        $baseTax = 0.0;
        foreach ($lines as $l) {
            $baseTe += (float) $l['unit_price_te'] * (int) $l['qty'];
            $baseTax += (float) $l['unit_price_te'] * (int) $l['qty'] * (float) $l['tax_rate'] / 100;
        }
        $averageRate = $baseTe > 0 ? $baseTax / $baseTe * 100 : 0.0;

        $discounts = [];
        foreach ((array) ($payload['vouchers'] ?? []) as $voucher) {
            $discounts[] = $this->normalizeVoucher((array) $voucher, $idCustomer, $averageRate);
        }
        foreach ($discounts as $d) {
            if (!empty($d['free_shipping'])) {
                $freeShipping = true;
            }
        }

        // --- Conditions
        $validUntil = (string) ($payload['valid_until'] ?? '');
        if ($validUntil === '' || !\Validate::isDateFormat($validUntil)) {
            $validUntil = (new \DateTimeImmutable('today'))
                ->modify('+' . max(1, $this->config->getInt(ConfigKeys::VALIDITY_DAYS, $idShop)) . ' days')
                ->format('Y-m-d');
        }
        $methods = array_values(array_intersect(self::PAYMENT_METHODS, array_map('strval', (array) ($payload['allowed_payment_methods'] ?? []))));
        if ($methods === []) {
            $methods = array_values(array_intersect(self::PAYMENT_METHODS, $this->config->getList(ConfigKeys::METHODS_DEFAULT, $idShop))) ?: ['card'];
        }

        $idEmployee = (int) ($payload['id_employee'] ?? 0);
        if ($idEmployee && !\Validate::isLoadedObject(new \Employee($idEmployee))) {
            throw new QuoteException('The selected sales representative does not exist.');
        }
        $idEmployee = $idEmployee ?: ((int) $quote->id_employee ?: (int) ($context->employee->id ?? 0));

        $header = [
            'id_shop' => $idShop,
            'id_shop_group' => (int) (new \Shop($idShop))->id_shop_group,
            'id_lang' => $idLang,
            'id_currency' => $idCurrency,
            'conversion_rate' => (float) $currency->conversion_rate,
            'id_customer' => $idCustomer,
            'id_employee' => $idEmployee ?: null,
            'id_address_delivery' => $idAddressDelivery ?: null,
            'id_address_invoice' => $idAddressInvoice ?: null,
            'project_name' => $this->text($payload['project_name'] ?? null, 128),
            'id_carrier_real' => $idCarrier ?: null,
            'id_carrier_reference' => $idCarrierReference,
            'carrier_label' => $carrierLabel,
            'delivery_mode' => $deliveryMode,
            'relay_data' => $relay ? (string) json_encode($relay, JSON_UNESCAPED_UNICODE) : null,
            'shipping_tax_excl' => $shippingTe,
            'shipping_discount_te' => $shippingDiscount,
            'free_shipping' => $freeShipping,
            'id_tax_rules_group_shipping' => $idTaxShipping ?: null,
            'shipping_tax_rate' => $shippingRate,
            'global_discount_type' => $global?->type->value,
            'global_discount_value' => $global?->value,
            'vat_number' => $this->taxResolver->vatNumber($taxAddress),
            'tax_exempt' => $taxExempt,
            'valid_until' => $validUntil,
            'allowed_payment_methods' => (string) json_encode($methods),
            'customer_message' => $this->text($payload['customer_message'] ?? null, 5000),
            'internal_note' => $this->text($payload['internal_note'] ?? null, 5000),
        ];

        return ['header' => $header, 'lines' => $lines, 'discounts' => $discounts, 'global' => $global];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalizeLine(array $raw, int $position, int $idCustomer, int $taxAddress, int $idCurrency, int $idShop, int $idLang): array
    {
        $type = LineType::tryFrom((string) ($raw['line_type'] ?? '')) ?? LineType::Product;
        $line = [
            'line_type' => $type->value,
            'id_product' => null,
            'id_product_attribute' => null,
            'label' => $this->text($raw['label'] ?? null, 255),
            'reference' => $this->text($raw['reference'] ?? null, 64),
            'qty' => 0,
            'catalog_price_te' => null,
            'unit_price_te' => 0.0,
            'reduction_type' => null,
            'reduction_value' => 0.0,
            'id_tax_rules_group' => null,
            'tax_rate' => 0.0,
            'ecotax' => 0.0,
            'wholesale_price' => null,
            'comment' => $this->text($raw['comment'] ?? null, 2000),
            'position' => $position,
            'stock' => null,
        ];

        if (!$type->hasAmount()) {
            if ($line['label'] === null && $line['comment'] === null) {
                throw new QuoteException('Line %n%: a title or comment line cannot be empty.', ['%n%' => $position + 1]);
            }

            return $line;
        }

        $qty = (int) ($raw['qty'] ?? 0);
        if ($qty < 1 || $qty > 1000000) {
            throw new QuoteException('Line %n%: the quantity must be at least 1.', ['%n%' => $position + 1]);
        }
        $line['qty'] = $qty;
        $line['unit_price_te'] = $this->money($raw['unit_price_te'] ?? 0, 6);

        $reductionType = ReductionType::tryFrom((string) ($raw['reduction_type'] ?? ''));
        $reductionValue = $this->money($raw['reduction_value'] ?? 0, 6);
        if ($reductionType && $reductionValue > 0) {
            if ($reductionType === ReductionType::Percent && $reductionValue > 100) {
                throw new QuoteException('Line %n%: a discount cannot exceed 100%.', ['%n%' => $position + 1]);
            }
            $line['reduction_type'] = $reductionType->value;
            $line['reduction_value'] = $reductionValue;
        }

        if ($type === LineType::Product) {
            $idProduct = (int) ($raw['id_product'] ?? 0);
            $idProductAttribute = (int) ($raw['id_product_attribute'] ?? 0);
            $product = new \Product($idProduct, false, $idLang, $idShop);
            if (!\Validate::isLoadedObject($product)) {
                throw new QuoteException('Line %n%: this product no longer exists. Remove the line or replace it.', ['%n%' => $position + 1]);
            }
            if ($idProductAttribute && !$this->attributeBelongsTo($idProductAttribute, $idProduct)) {
                throw new QuoteException('Line %n%: choose a valid combination.', ['%n%' => $position + 1]);
            }
            if (!$idProductAttribute && $product->hasAttributes()) {
                throw new QuoteException('Line %n%: choose a combination (size, colour…) for « %name% ».', ['%n%' => $position + 1, '%name%' => (string) $product->name]);
            }
            $info = $this->catalog->productInfo($idProduct, $idProductAttribute, $qty, $idCustomer, $taxAddress, $idCurrency, $idShop, $idLang);
            $line['id_product'] = $idProduct;
            $line['id_product_attribute'] = $idProductAttribute ?: 0;
            $line['label'] = $line['label'] ?? $info['name'];
            $line['reference'] = $info['reference'] !== '' ? mb_substr($info['reference'], 0, 64) : $line['reference'];
            $line['catalog_price_te'] = round($info['price_te'], 6);
            $line['wholesale_price'] = $info['wholesale'] > 0 ? round($info['wholesale'], 6) : null;
            $line['ecotax'] = $info['ecotax'];
            $line['stock'] = $info['stock'];
            $line['id_tax_rules_group'] = $info['id_tax_rules_group'];
            if (!isset($raw['unit_price_te']) || $raw['unit_price_te'] === '') {
                $line['unit_price_te'] = $line['catalog_price_te'];
            }
        } else {
            if ($line['label'] === null) {
                throw new QuoteException('Line %n%: enter a label for the free line.', ['%n%' => $position + 1]);
            }
            $idTaxRulesGroup = (int) ($raw['id_tax_rules_group'] ?? 0);
            if ($idTaxRulesGroup && !\Validate::isLoadedObject(new \TaxRulesGroup($idTaxRulesGroup))) {
                throw new QuoteException('Line %n%: choose a valid VAT rate.', ['%n%' => $position + 1]);
            }
            $line['id_tax_rules_group'] = $idTaxRulesGroup ?: null;
        }

        $line['tax_rate'] = $this->taxResolver->isTaxExempt($taxAddress)
            ? 0.0
            : $this->taxResolver->rate((int) $line['id_tax_rules_group'], $taxAddress);

        return $line;
    }

    private function attributeBelongsTo(int $idProductAttribute, int $idProduct): bool
    {
        return (int) \Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . $idProductAttribute
        ) === $idProduct;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function normalizeVoucher(array $raw, int $idCustomer, float $averageRate): array
    {
        $idCartRule = (int) ($raw['id_cart_rule'] ?? 0);
        if ($idCartRule) {
            $rule = new \CartRule($idCartRule, (int) \Context::getContext()->language->id);
            if (!\Validate::isLoadedObject($rule) || !$rule->active) {
                throw new QuoteException('The voucher « %code% » is not active.', ['%code%' => (string) ($raw['code'] ?? $idCartRule)]);
            }
            if ($rule->date_to && strtotime((string) $rule->date_to) < time()) {
                throw new QuoteException('The voucher « %code% » has expired.', ['%code%' => (string) $rule->code]);
            }
            if ((int) $rule->quantity <= 0) {
                throw new QuoteException('The voucher « %code% » has already been fully used.', ['%code%' => (string) $rule->code]);
            }
            if ((int) $rule->id_customer && (int) $rule->id_customer !== $idCustomer) {
                throw new QuoteException('The voucher « %code% » is reserved for another customer.', ['%code%' => (string) $rule->code]);
            }
            $isPercent = (float) $rule->reduction_percent > 0;
            $value = $isPercent ? (float) $rule->reduction_percent : (float) $rule->reduction_amount;
            if (!$isPercent && $rule->reduction_tax && $value > 0) {
                // Bon exprimé TTC : ramené en HT au taux moyen des produits du devis.
                $value = round($value / (1 + $averageRate / 100), 6);
            }

            return [
                'scope' => \SntQuoteDiscount::SCOPE_VOUCHER,
                'id_cart_rule' => $idCartRule,
                'is_dedicated' => false,
                'code' => (string) $rule->code,
                'label' => (string) ($rule->name ?: $rule->code),
                'reduction_type' => $isPercent ? ReductionType::Percent->value : ReductionType::Amount->value,
                'reduction_value' => $value,
                'free_shipping' => (bool) $rule->free_shipping,
                'dedicated_params' => null,
            ];
        }

        // Bon dédié, créé à la conversion.
        $type = ReductionType::tryFrom((string) ($raw['reduction_type'] ?? '')) ?? ReductionType::Amount;
        $value = $this->money($raw['reduction_value'] ?? 0);
        if ($value <= 0) {
            throw new QuoteException('Enter the amount or percentage of the dedicated voucher.');
        }
        if ($type === ReductionType::Percent && $value > 100) {
            throw new QuoteException('A percentage discount cannot exceed 100%.');
        }
        $dateTo = (string) ($raw['date_to'] ?? '');

        return [
            'scope' => \SntQuoteDiscount::SCOPE_VOUCHER,
            'id_cart_rule' => null,
            'is_dedicated' => true,
            'code' => null,
            'label' => $this->text($raw['label'] ?? null, 255) ?? 'Remise commerciale',
            'reduction_type' => $type->value,
            'reduction_value' => $value,
            'free_shipping' => false,
            'dedicated_params' => (string) json_encode([
                'date_to' => \Validate::isDateFormat($dateTo) ? $dateTo : null,
                'minimum_amount' => $this->money($raw['minimum_amount'] ?? 0),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, string>
     */
    private function normalizeRelay(array $raw): array
    {
        $out = [];
        foreach (['provider', 'relay_id', 'name', 'address', 'zip', 'city', 'country'] as $key) {
            $out[$key] = mb_substr(trim(strip_tags((string) ($raw[$key] ?? ''))), 0, 255);
        }
        if ($out['provider'] === '' || $out['relay_id'] === '') {
            throw new QuoteException('Choose a pickup point for this carrier.');
        }

        return $out;
    }

    private function customerAddress(int $idAddress, int $idCustomer): int
    {
        if ($idAddress <= 0) {
            return 0;
        }
        $address = new \Address($idAddress);
        if (!\Validate::isLoadedObject($address) || (int) $address->id_customer !== $idCustomer || $address->deleted) {
            throw new QuoteException('The selected address does not belong to this customer.');
        }

        return $idAddress;
    }

    /**
     * @param array<int, array<string, mixed>> $lines
     */
    private function replaceLines(\SntQuote $quote, array $lines, QuoteTotals $totals): void
    {
        \Db::getInstance()->delete('snt_quote_line', '`id_snt_quote` = ' . (int) $quote->id);
        foreach ($lines as $index => $data) {
            $line = new \SntQuoteLine();
            $line->id_snt_quote = (int) $quote->id;
            foreach ($data as $field => $value) {
                if ($field !== 'stock' && property_exists($line, $field)) {
                    $line->{$field} = $value;
                }
            }
            $line->unit_net_te = $totals->lines[$index]->unitNetTe ?? 0.0;
            $line->total_te = $totals->lines[$index]->totalTe ?? 0.0;
            if (!$line->add()) {
                throw new \RuntimeException('Quote line could not be saved.');
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $discounts
     */
    private function replaceDiscounts(\SntQuote $quote, array $discounts, QuoteTotals $totals): void
    {
        \Db::getInstance()->delete('snt_quote_discount', '`id_snt_quote` = ' . (int) $quote->id);
        // Index des montants calculés : la remise globale (si présente) occupe l'index 0.
        $offset = $quote->global_discount_type ? 1 : 0;
        if ($offset) {
            $global = new \SntQuoteDiscount();
            $global->id_snt_quote = (int) $quote->id;
            $global->scope = \SntQuoteDiscount::SCOPE_GLOBAL;
            $global->label = 'Remise globale';
            $global->reduction_type = (string) $quote->global_discount_type;
            $global->reduction_value = (float) $quote->global_discount_value;
            $global->amount_te = $totals->discounts[0]['te'] ?? 0.0;
            $global->amount_ti = $totals->discounts[0]['ti'] ?? 0.0;
            $global->add();
        }
        foreach ($discounts as $i => $data) {
            $discount = new \SntQuoteDiscount();
            $discount->id_snt_quote = (int) $quote->id;
            foreach (['scope', 'id_cart_rule', 'is_dedicated', 'code', 'label', 'reduction_type', 'reduction_value', 'dedicated_params'] as $field) {
                $discount->{$field} = $data[$field];
            }
            $discount->amount_te = $totals->discounts[$i + $offset]['te'] ?? 0.0;
            $discount->amount_ti = $totals->discounts[$i + $offset]['ti'] ?? 0.0;
            if (!$discount->add()) {
                throw new \RuntimeException('Quote discount could not be saved.');
            }
        }
    }

    private function money(mixed $value, int $decimals = 6): float
    {
        if (is_string($value)) {
            $value = str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], $value);
        }
        if (!is_numeric($value)) {
            return 0.0;
        }

        return round(max(0.0, (float) $value), $decimals);
    }

    private function text(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim(strip_tags((string) $value));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
