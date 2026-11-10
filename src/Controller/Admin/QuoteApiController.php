<?php

declare(strict_types=1);

namespace Snt\Quotes\Controller\Admin;

use Snt\Quotes\Adapter\CarrierService;
use Snt\Quotes\Adapter\CatalogPriceProvider;
use Snt\Quotes\Adapter\CustomerSearch;
use Snt\Quotes\Adapter\ProductSearch;
use Snt\Quotes\Adapter\TaxResolver;
use Snt\Quotes\Calculator\QuoteTotals;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Repository\QuoteRepository;
use Snt\Quotes\Service\EditorResult;
use Snt\Quotes\Service\Permission\QuotePermissions;
use Snt\Quotes\Service\QuoteEditor;
use Snt\Quotes\Service\QuoteFactory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Endpoints JSON de l'éditeur. Les montants renvoyés sont toujours recalculés côté serveur.
 */
class QuoteApiController extends AbstractQuoteController
{
    public function __construct(
        TranslatorInterface $translator,
        QuotePermissions $permissions,
        private readonly QuoteEditor $editor,
        private readonly QuoteFactory $factory,
        private readonly QuoteRepository $repository,
        private readonly CustomerSearch $customerSearch,
        private readonly ProductSearch $productSearch,
        private readonly CarrierService $carrierService,
        private readonly CatalogPriceProvider $catalog,
        private readonly TaxResolver $taxResolver,
    ) {
        parent::__construct($translator, $permissions);
    }

    /* --- Client --------------------------------------------------------- */

    public function customersAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', fn (): array => [
            'items' => $this->customerSearch->search((string) $request->query->get('q', '')),
        ], false);
    }

    public function customerAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', function () use ($request): array {
            $idCustomer = (int) $request->query->get('id', 0);
            $details = $this->customerSearch->details($idCustomer, $this->langId());
            if (!$details) {
                throw new QuoteException('This customer no longer exists.');
            }
            $carts = array_map(fn (array $c): array => [
                'id' => (int) $c['id_cart'],
                'date' => \Tools::displayDate((string) $c['date_upd'], true),
                'nb_lines' => (int) $c['nb_lines'],
                'nb_products' => (int) $c['nb_products'],
            ], $this->repository->findOpenCartsForCustomer($idCustomer, $this->shopId()));

            return ['customer' => $details, 'carts' => $carts, 'quotes' => $this->repository->findLatestForCustomer($idCustomer, 5)];
        }, false);
    }

    public function quickCustomerAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'create', function () use ($request): array {
            $data = $this->jsonBody($request);
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if (!\Validate::isEmail($email)) {
                throw new QuoteException('Enter a valid e-mail address.');
            }
            if (\Customer::customerExists($email, true, true)) {
                throw new QuoteException('A customer already uses this e-mail address: search for it instead.');
            }
            $firstname = trim((string) ($data['firstname'] ?? ''));
            $lastname = trim((string) ($data['lastname'] ?? ''));
            if (!\Validate::isCustomerName($firstname) || !\Validate::isCustomerName($lastname) || $firstname === '' || $lastname === '') {
                throw new QuoteException('Enter the first and last name (letters only).');
            }
            $customer = new \Customer();
            $customer->firstname = $firstname;
            $customer->lastname = $lastname;
            $customer->email = $email;
            $customer->company = mb_substr(trim(strip_tags((string) ($data['company'] ?? ''))), 0, 255);
            $customer->siret = mb_substr(preg_replace('/\s+/', '', (string) ($data['siret'] ?? '')) ?? '', 0, 14);
            $customer->id_lang = $this->langId();
            $customer->id_shop = $this->shopId();
            $customer->id_shop_group = (int) \Context::getContext()->shop->id_shop_group;
            $customer->active = true;
            $customer->newsletter = false;
            $customer->optin = false;
            // Mot de passe aléatoire : le client utilisera « Mot de passe oublié » pour activer son espace.
            $customer->passwd = (new \PrestaShop\PrestaShop\Core\Crypto\Hashing())->hash(bin2hex(random_bytes(16)));
            if (!$customer->add()) {
                throw new QuoteException('The customer could not be created. Check the information entered.');
            }

            return ['customer' => $this->customerSearch->details((int) $customer->id, $this->langId())];
        });
    }

    public function quickAddressAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'create', function () use ($request): array {
            $data = $this->jsonBody($request);
            $idCustomer = (int) ($data['id_customer'] ?? 0);
            $customer = new \Customer($idCustomer);
            if (!\Validate::isLoadedObject($customer)) {
                throw new QuoteException('Choose a customer first.');
            }
            $address = new \Address();
            $address->id_customer = $idCustomer;
            $address->alias = mb_substr(trim((string) ($data['alias'] ?? '')) ?: 'Adresse', 0, 32);
            $address->company = mb_substr(trim((string) ($data['company'] ?? $customer->company)), 0, 255);
            $address->firstname = trim((string) ($data['firstname'] ?? '')) ?: $customer->firstname;
            $address->lastname = trim((string) ($data['lastname'] ?? '')) ?: $customer->lastname;
            $address->address1 = trim((string) ($data['address1'] ?? ''));
            $address->address2 = trim((string) ($data['address2'] ?? ''));
            $address->postcode = trim((string) ($data['postcode'] ?? ''));
            $address->city = trim((string) ($data['city'] ?? ''));
            $address->id_country = (int) ($data['id_country'] ?? 0) ?: (int) \Configuration::get('PS_COUNTRY_DEFAULT');
            $address->phone = trim((string) ($data['phone'] ?? ''));
            $address->vat_number = strtoupper(preg_replace('/\s+/', '', (string) ($data['vat_number'] ?? '')) ?? '');
            $errors = $address->validateFields(false, true);
            if ($errors !== true) {
                throw new QuoteException('Check the address: %error%', ['%error%' => is_string($errors) ? strip_tags($errors) : '']);
            }
            $country = new \Country($address->id_country);
            if ($country->need_zip_code && !$country->checkZipCode($address->postcode)) {
                throw new QuoteException('The postcode is not valid for this country.');
            }
            if (!$address->add()) {
                throw new QuoteException('The address could not be created.');
            }

            return ['customer' => $this->customerSearch->details($idCustomer, $this->langId()), 'id_address' => (int) $address->id];
        });
    }

    /* --- Produits -------------------------------------------------------- */

    public function productsAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', function () use ($request): array {
            $items = $this->productSearch->search(
                (string) $request->query->get('q', ''),
                (int) $request->query->get('id_customer', 0),
                (int) $request->query->get('id_address', 0),
                (int) $request->query->get('id_currency', 0) ?: (int) \Context::getContext()->currency->id,
                $this->shopId(),
                $this->langId()
            );
            if (!$this->permissions->canSeeMargin()) {
                foreach ($items as &$item) {
                    $item['wholesale_price'] = null;
                }
                unset($item);
            }

            return ['items' => $items];
        }, false);
    }

    /**
     * Prix du client pour une quantité donnée (prix dégressifs) et informations de ligne.
     */
    public function productLineAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', function () use ($request): array {
            $idProduct = (int) $request->query->get('id_product', 0);
            $idAttribute = (int) $request->query->get('id_product_attribute', 0);
            $idAddress = (int) $request->query->get('id_address', 0);
            $info = $this->catalog->productInfo(
                $idProduct,
                $idAttribute,
                max(1, (int) $request->query->get('qty', 1)),
                (int) $request->query->get('id_customer', 0),
                $idAddress,
                (int) $request->query->get('id_currency', 0) ?: (int) \Context::getContext()->currency->id,
                $this->shopId(),
                $this->langId()
            );
            $info['tax_rate'] = $this->taxResolver->isTaxExempt($idAddress) ? 0.0 : $this->taxResolver->rate($info['id_tax_rules_group'], $idAddress);
            if (!$this->permissions->canSeeMargin()) {
                unset($info['wholesale']);
            }

            return ['line' => $info];
        }, false);
    }

    public function combinationsAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', fn (): array => [
            'items' => $this->catalog->combinations((int) $request->query->get('id_product', 0), $this->langId()),
        ], false);
    }

    /**
     * Lignes d'un panier client (reprise panier → devis).
     */
    public function cartLinesAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', function () use ($request): array {
            $lines = $this->factory->linesFromCart((int) $request->query->get('id_cart', 0), (int) $request->query->get('id_customer', 0));

            return ['lines' => $this->enrichLines($lines, $request), 'id_cart' => (int) $request->query->get('id_cart', 0)];
        }, false);
    }

    /**
     * Ajout en masse : texte « référence ; quantité » (une par ligne) ou fichier CSV.
     */
    public function bulkLinesAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'edit', function () use ($request): array {
            $text = (string) $request->request->get('text', '');
            $file = $request->files->get('file');
            if ($file && $file->isValid()) {
                if ($file->getSize() > 2 * 1024 * 1024) {
                    throw new QuoteException('The file is larger than 2 MB.');
                }
                $text .= "\n" . (string) file_get_contents($file->getPathname());
            }
            $lines = [];
            $errors = [];
            $n = 0;
            foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $raw) {
                ++$n;
                $raw = trim(preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? '');
                if ($raw === '') {
                    continue;
                }
                $cols = str_getcsv($raw, str_contains($raw, ';') ? ';' : (str_contains($raw, "\t") ? "\t" : ','));
                $reference = trim((string) ($cols[0] ?? ''));
                $qty = (int) trim((string) ($cols[1] ?? '1'));
                if ($n === 1 && !is_numeric($cols[1] ?? '1')) {
                    continue; // ligne d'en-tête
                }
                if ($reference === '' || $qty < 1) {
                    $errors[] = $this->t('Line %n%: reference or quantity missing.', ['%n%' => $n]);
                    continue;
                }
                $match = $this->findByReference($reference);
                if (!$match) {
                    $errors[] = $this->t('Line %n%: no product with reference « %ref% ».', ['%n%' => $n, '%ref%' => $reference]);
                    continue;
                }
                $lines[] = ['line_type' => 'product', 'id_product' => $match[0], 'id_product_attribute' => $match[1], 'qty' => $qty, 'unit_price_te' => null];
                if (count($lines) >= 300) {
                    $errors[] = $this->t('Import limited to 300 lines.');
                    break;
                }
            }

            return ['lines' => $this->enrichLines($lines, $request), 'errors' => $errors];
        });
    }

    /* --- Remises --------------------------------------------------------- */

    public function vouchersAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', function () use ($request): array {
            $term = trim((string) $request->query->get('q', ''));
            if (mb_strlen($term) < 2) {
                return ['items' => []];
            }
            $idCustomer = (int) $request->query->get('id_customer', 0);
            $like = '\'%' . pSQL($term) . '%\'';
            $rows = \Db::getInstance()->executeS(
                'SELECT cr.`id_cart_rule`, cr.`code`, crl.`name`, cr.`reduction_percent`, cr.`reduction_amount`, cr.`reduction_tax`,
                        cr.`free_shipping`, cr.`date_to`, cr.`quantity`, cr.`id_customer`, cr.`active`
                 FROM `' . _DB_PREFIX_ . 'cart_rule` cr
                 LEFT JOIN `' . _DB_PREFIX_ . 'cart_rule_lang` crl ON crl.`id_cart_rule` = cr.`id_cart_rule` AND crl.`id_lang` = ' . $this->langId() . '
                 WHERE (cr.`code` LIKE ' . $like . ' OR crl.`name` LIKE ' . $like . ')
                   AND (cr.`id_customer` = 0 OR cr.`id_customer` = ' . $idCustomer . ')
                 ORDER BY cr.`active` DESC, cr.`date_to` DESC
                 LIMIT 15'
            ) ?: [];
            $now = time();

            return ['items' => array_map(fn (array $r): array => [
                'id_cart_rule' => (int) $r['id_cart_rule'],
                'code' => (string) $r['code'],
                'label' => (string) ($r['name'] ?: $r['code']),
                'reduction_type' => (float) $r['reduction_percent'] > 0 ? 'percent' : 'amount',
                'reduction_value' => (float) $r['reduction_percent'] > 0 ? (float) $r['reduction_percent'] : (float) $r['reduction_amount'],
                'tax_included' => (bool) $r['reduction_tax'],
                'free_shipping' => (bool) $r['free_shipping'],
                'valid' => (bool) $r['active'] && (int) $r['quantity'] > 0 && strtotime((string) $r['date_to']) > $now,
                'date_to' => \Tools::displayDate((string) $r['date_to']),
            ], $rows)];
        }, false);
    }

    /* --- Livraison ------------------------------------------------------- */

    public function carriersAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', function () use ($request): array {
            $data = $this->jsonBody($request);

            return ['items' => $this->carrierService->listForQuote(
                (int) ($data['id_address_delivery'] ?? 0),
                (int) ($data['id_customer'] ?? 0),
                $this->productLines($data),
                $this->langId()
            )];
        });
    }

    public function shippingAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', function () use ($request): array {
            $data = $this->jsonBody($request);
            $cost = $this->carrierService->calculatedShipping(
                (int) ($data['id_carrier'] ?? 0),
                (int) ($data['id_customer'] ?? 0),
                (int) ($data['id_address_delivery'] ?? 0),
                (int) ($data['id_address_invoice'] ?? 0),
                (int) ($data['id_currency'] ?? 0) ?: (int) \Context::getContext()->currency->id,
                $this->langId(),
                $this->productLines($data)
            );
            if ($cost === null) {
                throw new QuoteException('No calculated rate: this carrier does not normally deliver this address or weight. Enter the shipping cost manually.');
            }

            return ['shipping_te' => $cost];
        });
    }

    public function relaysAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', function () use ($request): array {
            $postcode = trim((string) $request->query->get('postcode', ''));
            if ($postcode === '') {
                throw new QuoteException('Enter a postcode to search for pickup points.');
            }

            // Rue et ville de l'adresse de livraison, seulement si la recherche porte sur son code postal.
            // La ville saisie prime ; à défaut, celle de l'adresse de livraison.
            $street = '';
            $city = trim((string) $request->query->get('city', ''));
            $idAddress = (int) $request->query->get('id_address', 0);
            if ($idAddress) {
                $address = new \Address($idAddress);
                if (\Validate::isLoadedObject($address) && trim((string) $address->postcode) === $postcode) {
                    $street = (string) $address->address1;
                    $city = $city !== '' ? $city : (string) $address->city;
                }
            }

            return ['items' => $this->carrierService->searchRelays(
                (int) $request->query->get('id_carrier', 0),
                $postcode,
                (string) $request->query->get('country', 'FR'),
                $street,
                $city
            )];
        }, false);
    }

    public function taxRulesAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', fn (): array => [
            'items' => $this->taxResolver->taxRulesGroups((int) $request->query->get('id_address', 0)),
        ], false);
    }

    /* --- Calcul et enregistrement ---------------------------------------- */

    public function recalcAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'read', function () use ($request): array {
            $data = $this->jsonBody($request);
            $quote = !empty($data['id_snt_quote']) ? $this->loadQuote((int) $data['id_snt_quote']) : $this->factory->blank((int) ($data['id_customer'] ?? 0));

            return $this->result($this->editor->preview($quote, $data));
        });
    }

    public function saveAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'edit', function () use ($request): array {
            $data = $this->jsonBody($request);
            $autosave = !empty($data['autosave']);
            if (!empty($data['id_snt_quote'])) {
                $quote = $this->loadQuote((int) $data['id_snt_quote']);
                $result = $this->editor->save($quote, $data, !$autosave);
            } else {
                $this->requireRight('create');
                $created = $this->factory->create($data);
                $quote = $created->quote;
                $result = $created->result;
            }

            return $this->result($result) + [
                'id_snt_quote' => (int) $quote->id,
                'reference' => $quote->getFullReference(),
                'saved_at' => date('H:i'),
                'message' => $autosave ? '' : $this->t('Draft %ref% saved.', ['%ref%' => $quote->getFullReference()]),
                'urls' => [
                    'edit' => $this->generateUrl('admin_snt_quotes_edit', ['quoteId' => (int) $quote->id]),
                    'view' => $this->generateUrl('admin_snt_quotes_view', ['quoteId' => (int) $quote->id]),
                    'pdf' => $this->generateUrl('admin_snt_quotes_pdf', ['quoteId' => (int) $quote->id]),
                    'sendPreview' => $this->generateUrl('admin_snt_quotes_send_preview', ['quoteId' => (int) $quote->id]),
                    'send' => $this->generateUrl('admin_snt_quotes_send', ['quoteId' => (int) $quote->id]),
                ],
            ];
        });
    }

    /* --- Outils ---------------------------------------------------------- */

    /**
     * @return array<string, mixed>
     */
    private function result(EditorResult $result): array
    {
        $totals = $result->totals;
        $lines = [];
        foreach ($result->lines as $i => $line) {
            $calc = $totals->lines[$i] ?? null;
            $lines[] = [
                'catalog_price_te' => $line['catalog_price_te'],
                'unit_price_te' => $line['unit_price_te'],
                'tax_rate' => $line['tax_rate'],
                'stock' => $line['stock'],
                'label' => $line['label'],
                'reference' => $line['reference'],
                'wholesale_price' => $this->permissions->canSeeMargin() ? $line['wholesale_price'] : null,
                'unit_net_te' => $calc?->unitNetTe,
                'unit_net_ti' => $calc?->unitNetTi,
                'total_te' => $calc?->totalTe,
                'total_ti' => $calc?->totalTi,
                'discount_percent' => $calc?->effectiveDiscountPercent,
                'below_cost' => $this->permissions->canSeeMargin() && $calc?->belowCost,
            ];
        }

        return [
            'totals' => $this->totalsArray($totals),
            'lines' => $lines,
            'warnings' => $this->translateWarnings($result->warnings),
            'requires_approval' => $result->requiresApproval,
            'can_approve' => $this->permissions->canApproveDiscount(),
            'max_discount_percent' => $result->maxDiscountPercent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function totalsArray(QuoteTotals $totals): array
    {
        $data = $totals->toArray();
        unset($data['lines']);

        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $warnings
     *
     * @return array<int, array<string, mixed>>
     */
    private function translateWarnings(array $warnings): array
    {
        return array_values(array_filter(array_map(function (array $w): ?array {
            return match ($w['type']) {
                'below_cost' => $this->permissions->canSeeMargin()
                    ? $w + ['message' => $this->t('Line %n%: net price below the purchase price.', ['%n%' => $w['line'] + 1])]
                    : null,
                'stock' => $w + ['message' => $this->t('Line %n%: only %stock% in stock (the quote can still be sent).', ['%n%' => $w['line'] + 1, '%stock%' => $w['stock']])],
                default => null,
            };
        }, $warnings)));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, array{id_product: int, id_product_attribute: int, qty: int, unit_price_te: float}>
     */
    private function productLines(array $data): array
    {
        $out = [];
        foreach ((array) ($data['lines'] ?? []) as $line) {
            if (($line['line_type'] ?? 'product') !== 'product' || empty($line['id_product'])) {
                continue;
            }
            $out[] = [
                'id_product' => (int) $line['id_product'],
                'id_product_attribute' => (int) ($line['id_product_attribute'] ?? 0),
                'qty' => max(1, (int) ($line['qty'] ?? 1)),
                'unit_price_te' => (float) ($line['unit_price_te'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Complète des lignes produit (nom, référence, prix client, stock) pour affichage immédiat.
     *
     * @param array<int, array<string, mixed>> $lines
     *
     * @return array<int, array<string, mixed>>
     */
    private function enrichLines(array $lines, Request $request): array
    {
        $idCustomer = (int) $request->get('id_customer', 0);
        $idAddress = (int) $request->get('id_address', 0);
        $idCurrency = (int) $request->get('id_currency', 0) ?: (int) \Context::getContext()->currency->id;
        foreach ($lines as &$line) {
            $info = $this->catalog->productInfo((int) $line['id_product'], (int) $line['id_product_attribute'], (int) $line['qty'], $idCustomer, $idAddress, $idCurrency, $this->shopId(), $this->langId());
            $line['label'] = $info['name'];
            $line['reference'] = $info['reference'];
            $line['catalog_price_te'] = $info['price_te'];
            $line['unit_price_te'] = $info['price_te'];
            $line['stock'] = $info['stock'];
            $line['tax_rate'] = $this->taxResolver->isTaxExempt($idAddress) ? 0.0 : $this->taxResolver->rate($info['id_tax_rules_group'], $idAddress);
            $line['wholesale_price'] = $this->permissions->canSeeMargin() ? $info['wholesale'] : null;
            $line['reduction_type'] = null;
            $line['reduction_value'] = 0;
            $line['comment'] = '';
        }
        unset($line);

        return $lines;
    }

    /**
     * Référence ou EAN → [id_product, id_product_attribute].
     *
     * @return array{0: int, 1: int}|null
     */
    private function findByReference(string $reference): ?array
    {
        $ref = pSQL($reference);
        $row = \Db::getInstance()->getRow(
            'SELECT pa.`id_product`, pa.`id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute` pa
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = pa.`id_product` AND ps.`id_shop` = ' . $this->shopId() . '
             WHERE pa.`reference` = \'' . $ref . '\' OR pa.`ean13` = \'' . $ref . '\''
        );
        if ($row) {
            return [(int) $row['id_product'], (int) $row['id_product_attribute']];
        }
        $idProduct = (int) \Db::getInstance()->getValue(
            'SELECT p.`id_product` FROM `' . _DB_PREFIX_ . 'product` p
             INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $this->shopId() . '
             WHERE p.`reference` = \'' . $ref . '\' OR p.`ean13` = \'' . $ref . '\''
        );
        if (!$idProduct) {
            return null;
        }
        $hasAttributes = (new \Product($idProduct))->hasAttributes();

        return [$idProduct, $hasAttributes ? (int) \Product::getDefaultAttribute($idProduct) : 0];
    }
}
