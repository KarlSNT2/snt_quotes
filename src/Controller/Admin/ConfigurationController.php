<?php

declare(strict_types=1);

namespace Snt\Quotes\Controller\Admin;

use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Mail\QuoteMailer;
use Snt\Quotes\Service\Permission\QuotePermissions;
use Snt\Quotes\Service\QuoteEditor;
use Snt\Quotes\Storage\S3Storage;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Configuration du module, par boutique : Général, Paiement, PDF et e-mails, Stockage, Droits.
 */
class ConfigurationController extends AbstractQuoteController
{
    /** Champ du formulaire => clé de configuration. */
    private const FIELDS = [
        'validity_days' => ConfigKeys::VALIDITY_DAYS,
        'reminder_days' => ConfigKeys::REMINDER_DAYS,
        'shop_code' => ConfigKeys::SHOP_CODE,
        'ref_pattern' => ConfigKeys::REF_PATTERN,
        'response_delay_days' => ConfigKeys::RESPONSE_DELAY_DAYS,
        'stock_reservation' => ConfigKeys::STOCK_RESERVATION,
        'fo_request_enabled' => ConfigKeys::FO_REQUEST_ENABLED,
        'payment_gateway' => ConfigKeys::PAYMENT_GATEWAY,
        'methods_default' => ConfigKeys::METHODS_DEFAULT,
        'payment_terms' => ConfigKeys::PAYMENT_TERMS,
        'iban' => ConfigKeys::IBAN,
        'bic' => ConfigKeys::BIC,
        'paylink_ttl_days' => ConfigKeys::PAYLINK_TTL_DAYS,
        'os_transfer' => ConfigKeys::OS_TRANSFER,
        'os_deferred' => ConfigKeys::OS_DEFERRED,
        'os_pending' => ConfigKeys::OS_PENDING,
        'pdf_color' => ConfigKeys::PDF_COLOR,
        'pdf_legal' => ConfigKeys::PDF_LEGAL,
        'pdf_cgv_source' => ConfigKeys::PDF_CGV_SOURCE,
        'pdf_cgv_text' => ConfigKeys::PDF_CGV_TEXT,
        'pdf_cgv_id_cms' => ConfigKeys::PDF_CGV_ID_CMS,
        'mail_from' => ConfigKeys::MAIL_FROM,
        'mail_cc_employee' => ConfigKeys::MAIL_CC_EMPLOYEE,
        's3_endpoint' => ConfigKeys::S3_ENDPOINT,
        's3_region' => ConfigKeys::S3_REGION,
        's3_bucket' => ConfigKeys::S3_BUCKET,
        's3_key' => ConfigKeys::S3_KEY,
        'discount_threshold' => ConfigKeys::DISCOUNT_THRESHOLD,
        'profiles_approve' => ConfigKeys::PROFILES_APPROVE,
        'profiles_convert' => ConfigKeys::PROFILES_CONVERT,
        'profiles_see_margin' => ConfigKeys::PROFILES_SEE_MARGIN,
        'price_display' => ConfigKeys::PRICE_DISPLAY,
    ];

    /** Champs stockés sous forme de liste « a,b,c ». */
    private const LIST_FIELDS = ['methods_default', 'profiles_approve', 'profiles_convert', 'profiles_see_margin'];
    private const BOOL_FIELDS = ['stock_reservation', 'fo_request_enabled', 'mail_cc_employee'];
    /** Secrets : laissés vides = valeur actuelle conservée. */
    private const SECRET_FIELDS = ['s3_secret' => ConfigKeys::S3_SECRET];

    public function __construct(
        TranslatorInterface $translator,
        QuotePermissions $permissions,
        private readonly ModuleConfig $config,
        private readonly S3Storage $storage,
    ) {
        parent::__construct($translator, $permissions);
    }

    public function indexAction(Request $request): Response
    {
        $this->requireRight('edit');
        $form = $this->buildForm();
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $this->save($form->getData());
                $this->addFlash('success', $this->t('Settings saved for the shop « %shop% ».', ['%shop%' => \Context::getContext()->shop->name]));

                return $this->redirectToRoute('admin_snt_quotes_configuration', ['tab' => $request->request->get('active_tab', 'general')]);
            }
            $this->addFlash('error', $this->t('Some fields are invalid: check the highlighted fields.'));
        }

        return $this->render('@Modules/snt_quotes/views/templates/admin/configuration/index.html.twig', $this->withAssetVersion([
            'layoutTitle' => $this->t('Quotes — settings'),
            'enableSidebar' => true,
            'form' => $form->createView(),
            'activeTab' => (string) $request->query->get('tab', 'general'),
            'shopName' => (string) \Context::getContext()->shop->name,
            'isAllShopsContext' => \Shop::isFeatureActive() && \Shop::getContext() !== \Shop::CONTEXT_SHOP,
            'cronUrl' => \Context::getContext()->link->getModuleLink('snt_quotes', 'cron', ['token' => $this->config->get(ConfigKeys::CRON_TOKEN)], true),
            's3Configured' => $this->storage->isConfigured($this->shopId()),
            's3TestUrl' => $this->generateUrl('admin_snt_quotes_configuration_s3_test'),
            'csrf' => (string) $this->container->get('security.csrf.token_manager')->getToken(self::CSRF_ID)->getValue(),
            'listUrl' => $this->generateUrl('admin_snt_quotes_index'),
        ]));
    }

    public function s3TestAction(Request $request): JsonResponse
    {
        return $this->jsonAction($request, 'edit', function (): array {
            $result = $this->storage->selfTest($this->shopId());

            return [
                'ok' => $result === 'ok',
                'message' => match ($result) {
                    'ok' => $this->t('Connection OK: a test file was written and read back.'),
                    'not_configured' => $this->t('Fill in the bucket, access key and secret key, save, then test again.'),
                    default => $this->t('The file was written but could not be read back. Check the key permissions.'),
                },
            ];
        });
    }

    /**
     * @return FormInterface<string, FormInterface<string, mixed>>
     */
    private function buildForm(): FormInterface
    {
        $data = [];
        foreach (self::FIELDS as $field => $key) {
            $value = $this->config->get($key);
            $data[$field] = match (true) {
                in_array($field, self::LIST_FIELDS, true) => $this->config->getList($key),
                in_array($field, self::BOOL_FIELDS, true) => $this->config->getBool($key),
                default => $value,
            };
        }
        foreach (['validity_days', 'reminder_days', 'response_delay_days', 'paylink_ttl_days', 'pdf_cgv_id_cms', 'os_transfer', 'os_deferred', 'os_pending'] as $intField) {
            $data[$intField] = (int) $data[$intField];
        }
        $data['discount_threshold'] = $this->config->getFloat(ConfigKeys::DISCOUNT_THRESHOLD);
        $data['mail_enabled'] = array_values(array_diff(QuoteMailer::TEMPLATES, $this->config->getList(ConfigKeys::MAIL_DISABLED)));

        $profiles = [];
        foreach (\Profile::getProfiles($this->langId()) as $profile) {
            $profiles[(string) $profile['name']] = (string) $profile['id_profile'];
        }
        $states = [];
        foreach (\OrderState::getOrderStates($this->langId()) as $state) {
            if (!$state['deleted']) {
                $states[(string) $state['name']] = (int) $state['id_order_state'];
            }
        }
        $cms = ['—' => 0];
        foreach (\CMS::getCMSPages($this->langId(), null, true, $this->shopId()) as $page) {
            $cms[(string) $page['meta_title']] = (int) $page['id_cms'];
        }
        $help = fn (string $text): array => ['help' => $this->t($text)];

        return $this->createFormBuilder($data, ['translation_domain' => false])
            // Général
            ->add('validity_days', IntegerType::class, ['label' => $this->t('Default validity (days)'), 'constraints' => [new Assert\Range(['min' => 1, 'max' => 365])]] + $help('Validity date proposed for each new quote.'))
            ->add('reminder_days', IntegerType::class, ['label' => $this->t('Automatic reminder (days before expiry)'), 'constraints' => [new Assert\Range(['min' => 0, 'max' => 60])]] + $help('0 = no automatic reminder. Requires the cron URL to be called by n8n.'))
            ->add('shop_code', TextType::class, ['label' => $this->t('Site code'), 'constraints' => [new Assert\Regex(['pattern' => '/^[A-Za-z0-9]{1,10}$/'])]] + $help('Used in references (DEV-{SHOP}-…) and in the PDF storage folder. Letters and digits only.'))
            ->add('ref_pattern', TextType::class, ['label' => $this->t('Reference pattern')] + $help('Tokens: {SHOP}, {YEAR}, {YY}, {NUM} (5 digits, restarts every year).'))
            ->add('response_delay_days', IntegerType::class, ['label' => $this->t('Announced response time (working days)'), 'required' => false])
            ->add('price_display', ChoiceType::class, [
                'label' => $this->t('Prices shown to customers'),
                'choices' => [
                    $this->t('Tax included first (excl. tax as a reminder)') => 'ti',
                    $this->t('Tax excluded first (incl. tax as a reminder)') => 'te',
                    $this->t('According to the customer group setting') => 'group',
                ],
            ] + $help('Payment page and « My quotes » space. « According to the customer group » follows Customers > Groups > Price display method (B2B groups usually show prices excl. tax).'))
            ->add('stock_reservation', CheckboxType::class, ['label' => $this->t('Reserve stock when a quote is accepted'), 'required' => false])
            ->add('fo_request_enabled', CheckboxType::class, ['label' => $this->t('Allow customers to request a quote from their cart'), 'required' => false])
            // Paiement
            ->add('payment_gateway', ChoiceType::class, [
                'label' => $this->t('Online payment module'),
                'choices' => ['HiPay Payments' => 'hipaypayments', 'PayPlug' => 'payplug', $this->t('None') => 'none'],
            ] + $help('Module used for the payment link. Its own credentials are used: nothing to enter here.'))
            ->add('methods_default', ChoiceType::class, [
                'label' => $this->t('Payment methods proposed by default'),
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    $this->t('Credit card (online)') => 'card',
                    $this->t('Bank transfer') => 'transfer',
                    $this->t('Deferred payment (B2B)') => 'deferred',
                    $this->t('Quote — awaiting payment') => 'pending',
                ],
            ])
            ->add('payment_terms', TextareaType::class, ['label' => $this->t('Payment terms (printed on the PDF)'), 'required' => false, 'attr' => ['rows' => 3]])
            ->add('iban', TextType::class, ['label' => 'IBAN', 'required' => false] + $help('Printed on the PDF when bank transfer is allowed.'))
            ->add('bic', TextType::class, ['label' => 'BIC', 'required' => false])
            ->add('os_transfer', ChoiceType::class, ['label' => $this->t('Initial order status — bank transfer'), 'choices' => $states])
            ->add('os_deferred', ChoiceType::class, ['label' => $this->t('Initial order status — deferred payment (B2B)'), 'choices' => $states])
            ->add('os_pending', ChoiceType::class, ['label' => $this->t('Initial order status — awaiting payment / card by phone'), 'choices' => $states] + $help('Orders are synchronised with the ERP according to their status: choose statuses that n8n does not send to OASIS until payment.'))
            ->add('paylink_ttl_days', IntegerType::class, ['label' => $this->t('Payment link validity (days)'), 'constraints' => [new Assert\Range(['min' => 1, 'max' => 90])]])
            // PDF et e-mails
            ->add('pdf_color', TextType::class, ['label' => $this->t('Main colour'), 'attr' => ['type' => 'color'], 'constraints' => [new Assert\Regex(['pattern' => '/^#[0-9A-Fa-f]{6}$/'])]])
            ->add('pdf_legal', TextareaType::class, ['label' => $this->t('Legal notices (PDF footer)'), 'required' => false, 'attr' => ['rows' => 3]] + $help('SIRET, VAT number, share capital, RCS… Leave empty to use the shop details.'))
            ->add('pdf_cgv_source', ChoiceType::class, [
                'label' => $this->t('Terms and conditions appended to the PDF'),
                'choices' => [$this->t('None') => 'none', $this->t('Text below') => 'text', $this->t('CMS page') => 'cms'],
            ])
            ->add('pdf_cgv_text', TextareaType::class, ['label' => $this->t('Terms and conditions (text)'), 'required' => false, 'attr' => ['rows' => 6]])
            ->add('pdf_cgv_id_cms', ChoiceType::class, ['label' => $this->t('Terms and conditions (CMS page)'), 'choices' => $cms, 'required' => false])
            ->add('mail_from', ChoiceType::class, [
                'label' => $this->t('E-mail sender'),
                'choices' => [$this->t('The shop') => 'shop', $this->t('The assigned sales rep (reply to the rep)') => 'employee'],
            ] + $help('E-mails always leave from the shop address (deliverability); with « sales rep », the customer replies directly to the rep.'))
            ->add('mail_cc_employee', CheckboxType::class, ['label' => $this->t('Send a copy to the sales rep'), 'required' => false])
            ->add('mail_enabled', ChoiceType::class, [
                'label' => $this->t('E-mails enabled'),
                'multiple' => true,
                'expanded' => true,
                'choices' => [
                    $this->t('Quote sent') => QuoteMailer::TPL_SENT,
                    $this->t('New version') => QuoteMailer::TPL_NEW_VERSION,
                    $this->t('Reminder before expiry') => QuoteMailer::TPL_REMINDER,
                    $this->t('Payment link') => QuoteMailer::TPL_PAYMENT_LINK,
                    $this->t('Order created (to the sales rep)') => QuoteMailer::TPL_ORDERED_EMPLOYEE,
                    $this->t('Customer request or message (to the sales rep)') => QuoteMailer::TPL_CUSTOMER_ACTION,
                    $this->t('Reply from the team (to the customer)') => QuoteMailer::TPL_REPLY,
                ],
            ])
            // Stockage
            ->add('s3_endpoint', UrlType::class, ['label' => $this->t('Object storage endpoint'), 'required' => false])
            ->add('s3_region', TextType::class, ['label' => $this->t('Region'), 'required' => false])
            ->add('s3_bucket', TextType::class, ['label' => $this->t('Bucket'), 'required' => false])
            ->add('s3_key', TextType::class, ['label' => $this->t('Access key'), 'required' => false])
            ->add('s3_secret', PasswordType::class, ['label' => $this->t('Secret key'), 'required' => false, 'always_empty' => true] + $help('Leave empty to keep the current key.'))
            // Droits
            ->add('discount_threshold', NumberType::class, ['label' => $this->t('Discount threshold requiring approval (%)'), 'scale' => 2, 'constraints' => [new Assert\Range(['min' => 0, 'max' => 100])]] + $help('0 = no approval. Beyond this threshold, the quote waits for a manager before it can be sent.'))
            ->add('profiles_approve', ChoiceType::class, ['label' => $this->t('Profiles allowed to approve large discounts'), 'multiple' => true, 'expanded' => true, 'choices' => $profiles])
            ->add('profiles_convert', ChoiceType::class, ['label' => $this->t('Profiles allowed to convert a quote into an order'), 'multiple' => true, 'expanded' => true, 'choices' => $profiles])
            ->add('profiles_see_margin', ChoiceType::class, ['label' => $this->t('Profiles allowed to see purchase prices and margins'), 'multiple' => true, 'expanded' => true, 'choices' => $profiles])
            ->getForm();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function save(array $data): void
    {
        foreach (self::FIELDS as $field => $key) {
            $value = $data[$field] ?? null;
            if (in_array($field, self::LIST_FIELDS, true)) {
                $value = implode(',', array_map('strval', (array) $value));
            } elseif (in_array($field, self::BOOL_FIELDS, true)) {
                $value = (int) (bool) $value;
            }
            if ($field === 'methods_default') {
                $value = implode(',', array_intersect(QuoteEditor::PAYMENT_METHODS, explode(',', (string) $value)));
            }
            if ($field === 'shop_code') {
                $value = strtoupper((string) $value);
            }
            $this->config->set($key, is_scalar($value) ? $value : '', null, in_array($field, ['pdf_legal', 'pdf_cgv_text', 'payment_terms'], true));
        }
        foreach (self::SECRET_FIELDS as $field => $key) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') {
                $this->config->set($key, $value);
            }
        }
        $this->config->set(ConfigKeys::MAIL_DISABLED, implode(',', array_diff(QuoteMailer::TEMPLATES, (array) ($data['mail_enabled'] ?? []))));
    }
}
