<?php

declare(strict_types=1);

namespace Snt\Quotes\Adapter;

/**
 * Recherche produit : nom, référence, EAN (produit ou déclinaison), avec le prix du client.
 * Inclut les produits désactivés ou invisibles : un devis peut porter un article hors catalogue public.
 */
class ProductSearch
{
    public function __construct(private readonly CatalogPriceProvider $catalog)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $term, int $idCustomer, int $idAddress, int $idCurrency, int $idShop, int $idLang, int $limit = 15): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '\'%' . pSQL(str_replace(['%', '_'], ['\%', '\_'], $term)) . '%\'';
        $exact = '\'' . pSQL($term) . '\'';

        // Correspondance exacte sur une déclinaison (référence / EAN) : ligne prête, déclinaison choisie.
        $rows = \Db::getInstance()->executeS(
            'SELECT p.`id_product`, pa.`id_product_attribute`
             FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = pa.`id_product`
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             WHERE pa.`reference` = ' . $exact . ' OR pa.`ean13` = ' . $exact . ' OR pa.`supplier_reference` = ' . $exact . '
             LIMIT 5'
        ) ?: [];

        $rows = array_merge($rows, \Db::getInstance()->executeS(
            'SELECT DISTINCT p.`id_product`, 0 AS id_product_attribute
             FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $idShop . '
             INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . $idLang . ' AND pl.`id_shop` = ' . $idShop . '
             LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pa.`id_product` = p.`id_product`
             WHERE (pl.`name` LIKE ' . $like . ' OR p.`reference` LIKE ' . $like . ' OR p.`ean13` LIKE ' . $like . '
                OR p.`supplier_reference` LIKE ' . $like . ' OR pa.`reference` LIKE ' . $like . ' OR pa.`ean13` LIKE ' . $like . ')
               AND p.`reference` NOT LIKE \'SNT-QUOTE-FREE%\'
             ORDER BY (p.`reference` = ' . $exact . ') DESC, ps.`active` DESC, pl.`name` ASC
             LIMIT ' . max(1, $limit)
        ) ?: []);

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $idProduct = (int) $row['id_product'];
            $idAttribute = (int) $row['id_product_attribute'];
            $key = $idProduct . '-' . $idAttribute;
            if (isset($seen[$key]) || ($idAttribute === 0 && isset($seen[$idProduct . '-any']))) {
                continue;
            }
            $seen[$key] = true;
            if ($idAttribute) {
                $seen[$idProduct . '-any'] = true;
            }
            $product = new \Product($idProduct, false, $idLang, $idShop);
            $hasCombinations = (bool) $product->hasAttributes();
            $info = $this->catalog->productInfo($idProduct, $idAttribute ?: ($hasCombinations ? (int) \Product::getDefaultAttribute($idProduct) : 0), 1, $idCustomer, $idAddress, $idCurrency, $idShop, $idLang);
            $out[] = [
                'id_product' => $idProduct,
                'id_product_attribute' => $idAttribute,
                'name' => $idAttribute ? $info['name'] : (string) $product->name,
                'reference' => $info['reference'],
                'ean13' => $info['ean13'],
                'price_te' => $info['price_te'],
                'wholesale_price' => $info['wholesale'],
                'stock' => $info['stock'],
                'active' => (bool) $product->active,
                'is_virtual' => (bool) $product->is_virtual,
                'is_pack' => \Pack::isPack($idProduct),
                'has_combinations' => $hasCombinations,
                'needs_combination' => $hasCombinations && !$idAttribute,
                'image' => $this->coverUrl($idProduct, $idAttribute, $idLang, (string) $product->link_rewrite),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function coverUrl(int $idProduct, int $idAttribute, int $idLang, string $rewrite): ?string
    {
        $cover = $idAttribute ? \Image::getBestImageAttribute((int) \Context::getContext()->shop->id, $idLang, $idProduct, $idAttribute) : null;
        $idImage = (int) ($cover['id_image'] ?? 0);
        if (!$idImage) {
            $cover = \Image::getCover($idProduct);
            $idImage = (int) ($cover['id_image'] ?? 0);
        }
        if (!$idImage) {
            return null;
        }

        return \Context::getContext()->link->getImageLink($rewrite ?: 'product', (string) $idImage, 'small_default');
    }
}
