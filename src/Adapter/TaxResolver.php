<?php

declare(strict_types=1);

namespace Snt\Quotes\Adapter;

/**
 * Taux de TVA selon l'adresse de taxation, avec la même règle d'exonération que le cœur
 * (Product::getPriceStatic) : PS_TAX désactivé, ou n° de TVA renseigné + pays ≠ VATNUMBER_COUNTRY
 * + VATNUMBER_MANAGEMENT actif (B2B UE autoliquidé, n° validé par snt_inscription_pro).
 */
class TaxResolver
{
    /** @var array<string, float> */
    private array $cache = [];

    /**
     * Adresse retenue pour la taxe : livraison ou facturation selon PS_TAX_ADDRESS_TYPE.
     */
    public function taxAddressId(?int $idAddressDelivery, ?int $idAddressInvoice): int
    {
        $type = (string) \Configuration::get('PS_TAX_ADDRESS_TYPE');

        return (int) ($type === 'id_address_invoice' ? ($idAddressInvoice ?: $idAddressDelivery) : ($idAddressDelivery ?: $idAddressInvoice));
    }

    public function isTaxExempt(int $idAddress): bool
    {
        if (\Tax::excludeTaxeOption()) {
            return true;
        }
        if (!$idAddress) {
            return false;
        }
        $address = new \Address($idAddress);

        return !empty($address->vat_number)
            && (int) $address->id_country !== (int) \Configuration::get('VATNUMBER_COUNTRY')
            && (bool) \Configuration::get('VATNUMBER_MANAGEMENT');
    }

    public function vatNumber(int $idAddress): ?string
    {
        if (!$idAddress) {
            return null;
        }
        $vat = (string) (new \Address($idAddress))->vat_number;

        return $vat !== '' ? $vat : null;
    }

    /**
     * Taux total (%) d'un groupe de règles de taxe pour une adresse. Adresse vide → pays par défaut de la boutique.
     */
    public function rate(int $idTaxRulesGroup, int $idAddress): float
    {
        if ($idTaxRulesGroup <= 0) {
            return 0.0;
        }
        $key = $idTaxRulesGroup . '-' . $idAddress;
        if (!isset($this->cache[$key])) {
            $address = \Address::initialize($idAddress ?: null);
            $this->cache[$key] = (float) \TaxManagerFactory::getManager($address, $idTaxRulesGroup)
                ->getTaxCalculator()
                ->getTotalRate();
        }

        return $this->cache[$key];
    }

    public function productTaxRulesGroup(int $idProduct): int
    {
        return (int) \Product::getIdTaxRulesGroupByIdProduct($idProduct, \Context::getContext());
    }

    public function carrierTaxRulesGroup(int $idCarrier): int
    {
        return $idCarrier ? (int) \Carrier::getIdTaxRulesGroupByIdCarrier($idCarrier, \Context::getContext()) : 0;
    }

    /**
     * Règles de taxe actives de la boutique, pour le sélecteur des lignes libres.
     *
     * @return array<int, array{id: int, name: string, rate: float}>
     */
    public function taxRulesGroups(int $idAddress): array
    {
        $out = [];
        foreach (\TaxRulesGroup::getTaxRulesGroups(true) as $group) {
            $id = (int) $group['id_tax_rules_group'];
            $out[] = ['id' => $id, 'name' => (string) $group['name'], 'rate' => $this->rate($id, $idAddress)];
        }

        return $out;
    }
}
