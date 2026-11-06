<?php

declare(strict_types=1);

namespace Snt\Quotes\Adapter;

/**
 * Prix catalogue « du client » : prix de groupe, prix spécifiques et dégressifs inclus (moteur natif),
 * sans le panier courant de l'employé. Prix HT, devise du devis.
 */
class CatalogPriceProvider
{
    /**
     * @return array{price_te: float, wholesale: float, reference: string, name: string, ean13: string, stock: int, id_tax_rules_group: int, ecotax: float}
     */
    public function productInfo(
        int $idProduct,
        int $idProductAttribute,
        int $qty,
        int $idCustomer,
        int $idAddress,
        int $idCurrency,
        int $idShop,
        int $idLang,
    ): array {
        $context = $this->contextFor($idCustomer, $idCurrency, $idShop, $idLang);
        $specificPriceOutput = null;

        $price = (float) \Product::getPriceStatic(
            $idProduct,
            false,
            $idProductAttribute ?: null,
            6,
            null,
            false,
            true,
            max(1, $qty),
            false,
            $idCustomer ?: null,
            null,
            $idAddress ?: null,
            $specificPriceOutput,
            true,
            true,
            $context
        );

        $product = new \Product($idProduct, false, $idLang, $idShop);
        $wholesale = (float) $product->wholesale_price;
        $reference = (string) $product->reference;
        $ean = (string) $product->ean13;
        if ($idProductAttribute) {
            $combination = new \Combination($idProductAttribute);
            if (\Validate::isLoadedObject($combination)) {
                $wholesale = (float) $combination->wholesale_price > 0 ? (float) $combination->wholesale_price : $wholesale;
                $reference = (string) $combination->reference !== '' ? (string) $combination->reference : $reference;
                $ean = (string) $combination->ean13 !== '' ? (string) $combination->ean13 : $ean;
            }
        }
        // Le prix d'achat est stocké dans la devise par défaut.
        $defaultCurrency = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        if ($idCurrency && $idCurrency !== $defaultCurrency) {
            $wholesale = (float) \Tools::convertPrice($wholesale, $idCurrency);
        }

        return [
            'price_te' => $price,
            'wholesale' => $wholesale,
            'reference' => $reference,
            'name' => $this->displayName($idProduct, $idProductAttribute, $idLang),
            'ean13' => $ean,
            'stock' => (int) \StockAvailable::getQuantityAvailableByProduct($idProduct, $idProductAttribute ?: null, $idShop),
            'id_tax_rules_group' => (int) $product->id_tax_rules_group,
            'ecotax' => (float) $product->ecotax,
        ];
    }

    /**
     * « Nom du produit — Taille : M, Couleur : Noir ».
     */
    public function displayName(int $idProduct, int $idProductAttribute, int $idLang): string
    {
        $name = (string) \Product::getProductName($idProduct, null, $idLang);
        if ($idProductAttribute) {
            $attributes = \Product::getAttributesParams($idProduct, $idProductAttribute);
            if ($attributes) {
                $name .= ' — ' . implode(', ', array_map(
                    static fn (array $a): string => $a['group'] . ' : ' . $a['name'],
                    $attributes
                ));
            }
        }

        return $name;
    }

    /**
     * Déclinaisons d'un produit pour le sélecteur de la ligne.
     *
     * @return array<int, array{id: int, label: string, reference: string}>
     */
    public function combinations(int $idProduct, int $idLang): array
    {
        $out = [];
        foreach ((new \Product($idProduct))->getAttributeCombinations($idLang) as $row) {
            $id = (int) $row['id_product_attribute'];
            $out[$id] ??= ['id' => $id, 'label' => '', 'reference' => (string) $row['reference']];
            $out[$id]['label'] .= ($out[$id]['label'] === '' ? '' : ', ') . $row['group_name'] . ' : ' . $row['attribute_name'];
        }

        return array_values($out);
    }

    private function contextFor(int $idCustomer, int $idCurrency, int $idShop, int $idLang): \Context
    {
        $context = \Context::getContext()->cloneContext();
        $context->cart = new \Cart();
        $context->currency = new \Currency($idCurrency ?: (int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $context->shop = new \Shop($idShop);
        $context->language = new \Language($idLang);
        $context->customer = $idCustomer ? new \Customer($idCustomer) : new \Customer();

        return $context;
    }
}
