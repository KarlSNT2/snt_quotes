<?php

declare(strict_types=1);

namespace Snt\Quotes\Controller\Admin;

use PrestaShop\PrestaShop\Core\Grid\GridFactoryInterface;
use PrestaShop\PrestaShop\Core\Grid\Presenter\GridPresenterInterface;
use PrestaShopBundle\Service\Grid\ResponseBuilder;
use Snt\Quotes\Adapter\TaxResolver;
use Snt\Quotes\Config\ConfigKeys;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Domain\QuoteStatus;
use Snt\Quotes\Grid\QuoteFilters;
use Snt\Quotes\Grid\QuoteGridDefinitionFactory;
use Snt\Quotes\Grid\QuoteQueryBuilder;
use Snt\Quotes\Mail\QuoteMailer;
use Snt\Quotes\Mail\SendOptions;
use Snt\Quotes\Payment\PaymentLinkService;
use Snt\Quotes\Pdf\QuotePdfGenerator;
use Snt\Quotes\Presenter\QuotePresenter;
use Snt\Quotes\Repository\QuoteRepository;
use Snt\Quotes\Service\HistoryLogger;
use Snt\Quotes\Service\Permission\QuotePermissions;
use Snt\Quotes\Service\QuoteEditor;
use Snt\Quotes\Service\QuoteFactory;
use Snt\Quotes\Service\QuoteSender;
use Snt\Quotes\Service\QuoteStateMachine;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Pages BO : liste (Grid), éditeur, fiche, PDF, envoi, changements de statut, duplication.
 */
class QuoteController extends AbstractQuoteController
{
    private const TPL = '@Modules/snt_quotes/views/templates/admin/';

    public function __construct(
        TranslatorInterface $translator,
        QuotePermissions $permissions,
        private readonly GridFactoryInterface $gridFactory,
        private readonly GridPresenterInterface $gridPresenter,
        private readonly QuoteGridDefinitionFactory $gridDefinitionFactory,
        private readonly QuoteQueryBuilder $queryBuilder,
        private readonly ResponseBuilder $responseBuilder,
        private readonly QuoteRepository $repository,
        private readonly QuoteFactory $factory,
        private readonly QuotePresenter $presenter,
        private readonly HistoryLogger $history,
        private readonly QuoteStateMachine $stateMachine,
        private readonly QuotePdfGenerator $pdfGenerator,
        private readonly TaxResolver $taxResolver,
        private readonly ModuleConfig $config,
        private readonly QuoteSender $sender,
        private readonly QuoteMailer $mailer,
        private readonly PaymentLinkService $paymentLinks,
    ) {
        parent::__construct($translator, $permissions);
    }

    /* ------------------------------------------------------------------ */
    /* Liste                                                               */
    /* ------------------------------------------------------------------ */

    public function indexAction(Request $request, QuoteFilters $filters): Response
    {
        $this->requireRight('read');
        $counts = $this->repository->countTabs(array_map('intval', \Shop::getContextListShopID() ?: [$this->shopId()]));
        $tab = (string) $request->query->get('tab', '');
        if (!in_array($tab, [QuoteQueryBuilder::TAB_TODO, QuoteQueryBuilder::TAB_WAITING, QuoteQueryBuilder::TAB_ALL], true)) {
            // « À traiter » par défaut, sauf s'il est vide.
            $tab = $counts['todo'] > 0 ? QuoteQueryBuilder::TAB_TODO : QuoteQueryBuilder::TAB_ALL;
        }
        $this->queryBuilder->setQuickTab($tab);
        $grid = $this->gridFactory->getGrid($filters);

        return $this->render(self::TPL . 'quote/index.html.twig', [
            'layoutTitle' => $this->t('Quotes'),
            'enableSidebar' => true,
            'grid' => $this->gridPresenter->present($grid),
            'currentTab' => $tab,
            'counts' => $counts,
            'canCreate' => $this->permissions->canCreate(),
        ]);
    }

    public function searchAction(Request $request): RedirectResponse
    {
        $this->requireRight('read');

        return $this->responseBuilder->buildSearchResponse(
            $this->gridDefinitionFactory,
            $request,
            QuoteGridDefinitionFactory::GRID_ID,
            'admin_snt_quotes_index',
            ['tab']
        );
    }

    /* ------------------------------------------------------------------ */
    /* Éditeur                                                             */
    /* ------------------------------------------------------------------ */

    public function createAction(Request $request): Response
    {
        $this->requireRight('create');
        $quote = $this->factory->blank((int) $request->query->get('id_customer', 0));
        $payload = $this->presenter->toEditorPayload($quote);
        $payload['valid_until'] = (new \DateTimeImmutable('today'))
            ->modify('+' . max(1, $this->config->getInt(ConfigKeys::VALIDITY_DAYS)) . ' days')->format('Y-m-d');
        $payload['allowed_payment_methods'] = array_values(array_intersect(QuoteEditor::PAYMENT_METHODS, $this->config->getList(ConfigKeys::METHODS_DEFAULT))) ?: ['card'];

        return $this->renderEditor($quote, $payload, (string) $request->query->get('import', ''));
    }

    public function editAction(int $quoteId): Response
    {
        $this->requireRight('edit');
        $quote = $this->loadQuote($quoteId);
        if (!$quote->isEditable()) {
            $this->addFlash('warning', $this->t('This quote has been sent: to change it, create a new version.'));

            return $this->redirectToRoute('admin_snt_quotes_view', ['quoteId' => $quoteId]);
        }

        return $this->renderEditor($quote, $this->presenter->toEditorPayload($quote));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderEditor(\SntQuote $quote, array $payload, string $import = ''): Response
    {
        $context = \Context::getContext();
        $employees = array_map(static fn (array $e): array => [
            'id' => (int) $e['id_employee'],
            'name' => trim($e['firstname'] . ' ' . $e['lastname']),
        ], \Employee::getEmployees(true) ?: []);
        $taxAddress = $this->taxResolver->taxAddressId((int) $quote->id_address_delivery, (int) $quote->id_address_invoice);
        $currency = new \Currency((int) $quote->id_currency);

        $bootstrap = [
            'quote' => $payload,
            'isNew' => !$quote->id,
            'reference' => $quote->id ? $quote->getFullReference() : null,
            'statusBadge' => $quote->id ? $this->presenter->statusPresenter()->present($quote->getStatus()) : null,
            'currency' => ['id' => (int) $currency->id, 'iso' => (string) $currency->iso_code, 'sign' => (string) $currency->sign],
            'locale' => (string) $context->language->locale,
            'employees' => $employees,
            'taxRules' => $this->taxResolver->taxRulesGroups($taxAddress),
            'permissions' => $this->permissions->toArray(),
            'discountThreshold' => $this->config->getFloat(ConfigKeys::DISCOUNT_THRESHOLD),
            'paymentMethods' => QuoteEditor::PAYMENT_METHODS,
            'import' => $import,
            'countries' => array_values(array_map(static fn (array $c): array => [
                'id' => (int) $c['id_country'],
                'name' => (string) $c['name'],
                'default' => (int) $c['id_country'] === (int) \Configuration::get('PS_COUNTRY_DEFAULT'),
            ], \Country::getCountries($this->langId(), true))),
            'csrf' => $this->csrfToken(),
            'urls' => $this->editorUrls($quote),
        ];

        return $this->render(self::TPL . 'quote/edit.html.twig', [
            'layoutTitle' => $quote->id ? $this->t('Edit quote %ref%', ['%ref%' => $quote->getFullReference()]) : $this->t('New quote'),
            'enableSidebar' => true,
            'bootstrap' => $bootstrap,
            'quoteId' => (int) $quote->id,
            'moduleUri' => __PS_BASE_URI__ . 'modules/snt_quotes/',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function editorUrls(\SntQuote $quote): array
    {
        $id = (int) $quote->id;
        $urls = [
            'index' => $this->generateUrl('admin_snt_quotes_index'),
            'save' => $this->generateUrl('admin_snt_quotes_api_save'),
            'recalc' => $this->generateUrl('admin_snt_quotes_api_recalc'),
            'customers' => $this->generateUrl('admin_snt_quotes_api_customers'),
            'customer' => $this->generateUrl('admin_snt_quotes_api_customer'),
            'quickCustomer' => $this->generateUrl('admin_snt_quotes_api_quick_customer'),
            'quickAddress' => $this->generateUrl('admin_snt_quotes_api_quick_address'),
            'products' => $this->generateUrl('admin_snt_quotes_api_products'),
            'productLine' => $this->generateUrl('admin_snt_quotes_api_product_line'),
            'combinations' => $this->generateUrl('admin_snt_quotes_api_combinations'),
            'vouchers' => $this->generateUrl('admin_snt_quotes_api_vouchers'),
            'carriers' => $this->generateUrl('admin_snt_quotes_api_carriers'),
            'shipping' => $this->generateUrl('admin_snt_quotes_api_shipping'),
            'relays' => $this->generateUrl('admin_snt_quotes_api_relays'),
            'cartLines' => $this->generateUrl('admin_snt_quotes_api_cart_lines'),
            'bulkLines' => $this->generateUrl('admin_snt_quotes_api_bulk_lines'),
            'taxRules' => $this->generateUrl('admin_snt_quotes_api_tax_rules'),
            'edit' => $this->generateUrl('admin_snt_quotes_edit', ['quoteId' => 999999999]),
            'view' => $this->generateUrl('admin_snt_quotes_view', ['quoteId' => 999999999]),
        ];
        if ($id) {
            $urls['pdf'] = $this->generateUrl('admin_snt_quotes_pdf', ['quoteId' => $id]);
            $urls['sendPreview'] = $this->generateUrl('admin_snt_quotes_send_preview', ['quoteId' => $id]);
            $urls['send'] = $this->generateUrl('admin_snt_quotes_send', ['quoteId' => $id]);
        }

        return $urls;
    }

    /* ------------------------------------------------------------------ */
    /* Fiche                                                               */
    /* ------------------------------------------------------------------ */

    public function viewAction(int $quoteId): Response
    {
        $this->requireRight('read');
        $quote = $this->loadQuote($quoteId);
        $view = $this->presenter->present($quote, !$this->permissions->canSeeMargin());
        $status = $quote->getStatus();

        $archives = \Db::getInstance()->executeS(
            'SELECT `id_snt_quote_pdf`, `version`, `sha256`, `date_add` FROM `' . _DB_PREFIX_ . 'snt_quote_pdf`
             WHERE `id_snt_quote` = ' . (int) $quote->id . ' ORDER BY `id_snt_quote_pdf` DESC'
        ) ?: [];
        $customer = new \Customer((int) $quote->id_customer);

        return $this->render(self::TPL . 'quote/view.html.twig', [
            'layoutTitle' => $this->t('Quote %ref%', ['%ref%' => $quote->getFullReference()]),
            'enableSidebar' => true,
            'quote' => $view,
            'quoteId' => (int) $quote->id,
            'status' => $status->value,
            'actions' => $this->availableActions($quote),
            'history' => $this->history->getEntries((int) $quote->id),
            'versions' => $this->repository->findVersions($quote),
            'archives' => $archives,
            'permissions' => $this->permissions->toArray(),
            'requiresApproval' => $status === QuoteStatus::PendingApproval,
            'csrf' => $this->csrfToken(),
            'customerUrl' => \Context::getContext()->link->getAdminLink('AdminCustomers', true, ['route' => 'admin_customers_view', 'customerId' => (int) $quote->id_customer]),
            'orderUrl' => $quote->id_order ? \Context::getContext()->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => (int) $quote->id_order]) : null,
            'paymentLink' => $this->paymentLinks->latest($quote),
            'conversionUrls' => [
                'preview' => $this->generateUrl('admin_snt_quotes_convert_preview', ['quoteId' => (int) $quote->id]),
                'convert' => $this->generateUrl('admin_snt_quotes_convert', ['quoteId' => (int) $quote->id]),
                'paymentLink' => $this->generateUrl('admin_snt_quotes_payment_link', ['quoteId' => (int) $quote->id]),
                'revokeLink' => $this->generateUrl('admin_snt_quotes_payment_link_revoke', ['quoteId' => (int) $quote->id]),
            ],
            'sendDefaults' => [
                'to' => (string) $customer->email,
                'subject' => $this->mailer->defaultSubject($quote, $this->mailer->templateFor($quote)),
                'copyEmployee' => $this->config->getBool(ConfigKeys::MAIL_CC_EMPLOYEE),
            ],
        ]);
    }

    /**
     * Barre d'actions contextuelle selon le statut (une action principale par écran).
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableActions(\SntQuote $quote): array
    {
        $id = (int) $quote->id;
        $status = $quote->getStatus();
        $actions = [];
        $add = function (string $key, string $label, string $kind, string $url, bool $primary = false, ?string $confirm = null, string $icon = '') use (&$actions): void {
            $actions[] = compact('key', 'label', 'kind', 'url', 'primary', 'confirm', 'icon');
        };
        $canEdit = $this->permissions->canEdit();

        if ($quote->isEditable() && $canEdit) {
            $add('send', $this->t('Send to customer'), 'send', $this->generateUrl('admin_snt_quotes_send', ['quoteId' => $id]), true, null, 'send');
            $add('edit', $this->t('Edit'), 'link', $this->generateUrl('admin_snt_quotes_edit', ['quoteId' => $id]), false, null, 'edit');
        }
        if ($status->isOpen() && $canEdit) {
            $add('remind', $this->t('Send a reminder'), 'post', $this->generateUrl('admin_snt_quotes_remind', ['quoteId' => $id]), true, null, 'notifications');
            $add('resend', $this->t('Send again'), 'send', $this->generateUrl('admin_snt_quotes_send', ['quoteId' => $id]), false, null, 'forward_to_inbox');
            $add('accept', $this->t('Mark as accepted'), 'post', $this->transitionUrl($id, QuoteStatus::Accepted), false, $this->t('Confirm that the customer accepted this quote (e.g. by phone or signed PDF)?'), 'check_circle');
            $add('decline', $this->t('Mark as declined'), 'post', $this->transitionUrl($id, QuoteStatus::Declined), false, $this->t('Mark this quote as declined by the customer?'), 'cancel');
            $add('expire', $this->t('Expire'), 'post', $this->transitionUrl($id, QuoteStatus::Expired), false, $this->t('The customer will no longer be able to accept this quote. Continue?'), 'timer_off');
        }
        $canConvert = $this->permissions->canConvert();
        if (!$quote->id_order && $status === QuoteStatus::Accepted) {
            if ($canConvert) {
                $add('convert', $this->t('Convert to order'), 'convert', $this->generateUrl('admin_snt_quotes_convert', ['quoteId' => $id]), true, null, 'shopping_cart');
            }
            if ($canEdit) {
                $add('paylink', $this->t('Payment link'), 'paylink', $this->generateUrl('admin_snt_quotes_payment_link', ['quoteId' => $id]), false, null, 'link');
            }
        }
        if (!$quote->id_order && $status->isOpen()) {
            if ($canEdit) {
                $add('paylink', $this->t('Payment link'), 'paylink', $this->generateUrl('admin_snt_quotes_payment_link', ['quoteId' => $id]), false, null, 'link');
            }
            if ($canConvert) {
                $add('convert', $this->t('Convert to order (customer agreed)'), 'convert', $this->generateUrl('admin_snt_quotes_convert', ['quoteId' => $id]), false, null, 'shopping_cart');
            }
        }
        if ($status === QuoteStatus::Ordered || $status === QuoteStatus::Paid) {
            if ($quote->id_order) {
                $add('order', $this->t('View the order'), 'link', \Context::getContext()->link->getAdminLink('AdminOrders', true, ['route' => 'admin_orders_view', 'orderId' => (int) $quote->id_order]), true, null, 'receipt');
            }
        }
        if (in_array($status, [QuoteStatus::Declined, QuoteStatus::Expired, QuoteStatus::RevisionRequested], true) && $canEdit) {
            $add('reopen', $this->t('Reopen as draft'), 'post', $this->transitionUrl($id, QuoteStatus::Draft), $status === QuoteStatus::RevisionRequested, null, 'undo');
        }
        if (!$quote->isEditable() && !in_array($status, [QuoteStatus::Ordered, QuoteStatus::Paid], true) && $this->permissions->canCreate()) {
            $add('new_version', $this->t('New version'), 'post', $this->generateUrl('admin_snt_quotes_new_version', ['quoteId' => $id]), false, $this->t('Create version %v% of this quote? The current version will be replaced when the new one is sent.', ['%v%' => (int) $quote->version + 1]), 'difference');
        }
        $add('pdf', $this->t('Download the PDF'), 'link', $this->generateUrl('admin_snt_quotes_pdf', ['quoteId' => $id]), false, null, 'picture_as_pdf');
        if ($this->permissions->canCreate()) {
            $add('duplicate', $this->t('Duplicate'), 'post', $this->generateUrl('admin_snt_quotes_duplicate', ['quoteId' => $id]), false, $this->t('Create a new draft quote from this one?'), 'content_copy');
        }
        if ($this->permissions->canDelete() && in_array($status, [QuoteStatus::Draft, QuoteStatus::Cancelled], true) && !$quote->sent_at) {
            $add('delete', $this->t('Delete'), 'post', $this->generateUrl('admin_snt_quotes_delete', ['quoteId' => $id]), false, $this->t('Permanently delete the draft %ref%?', ['%ref%' => $quote->getFullReference()]), 'delete');
        }

        return $actions;
    }

    private function transitionUrl(int $id, QuoteStatus $to): string
    {
        return $this->generateUrl('admin_snt_quotes_transition', ['quoteId' => $id, 'to' => $to->value]);
    }

    /* ------------------------------------------------------------------ */
    /* PDF                                                                 */
    /* ------------------------------------------------------------------ */

    public function pdfAction(Request $request, int $quoteId): Response
    {
        $this->requireRight('read');
        $quote = $this->loadQuote($quoteId);
        $filename = \HTMLTemplateSntQuote::filenameFor($quote);
        try {
            $idArchive = (int) $request->query->get('archive', 0);
            if ($idArchive) {
                $archive = new \SntQuotePdf($idArchive);
                if ((int) $archive->id_snt_quote !== (int) $quote->id) {
                    throw new AccessDeniedHttpException();
                }
                $url = $this->pdfGenerator->storage()->temporaryUrl((string) $archive->storage, (string) $archive->file_path, (int) $quote->id_shop, basename((string) $archive->file_path));
                if ($url) {
                    return new RedirectResponse($url);
                }
                $content = $this->pdfGenerator->archivedContent($archive, (int) $quote->id_shop);
                $filename = basename((string) $archive->file_path);
            } else {
                $content = $this->pdfGenerator->render($quote, $this->presenter);
            }
        } catch (AccessDeniedHttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->translateException($e));

            return $this->redirectToRoute('admin_snt_quotes_view', ['quoteId' => $quoteId]);
        }

        return new Response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->query->getBoolean('download') ? 'attachment' : 'inline') . '; filename="' . $filename . '"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Envoi                                                               */
    /* ------------------------------------------------------------------ */

    public function sendPreviewAction(Request $request, int $quoteId): JsonResponse
    {
        return $this->jsonAction($request, 'edit', function () use ($request, $quoteId): array {
            $quote = $this->loadQuote($quoteId);
            $this->sender->assertSendable($quote);
            $body = $this->jsonBody($request);
            $template = $this->mailer->templateFor($quote);

            return [
                'html' => $this->mailer->preview($quote, $template, (string) ($body['message'] ?? '')),
                'subject' => $this->mailer->defaultSubject($quote, $template),
                'pdfUrl' => $this->generateUrl('admin_snt_quotes_pdf', ['quoteId' => $quoteId]),
                'requiresApproval' => $this->sender->requiresApproval($quote) && !$this->permissions->canApproveDiscount(),
                'enabled' => $this->mailer->isEnabled($template, (int) $quote->id_shop),
            ];
        });
    }

    public function sendAction(Request $request, int $quoteId): JsonResponse
    {
        return $this->jsonAction($request, 'edit', function () use ($request, $quoteId): array {
            $quote = $this->loadQuote($quoteId);
            $attachments = [];
            foreach ((array) ($request->files->get('attachments') ?? []) as $file) {
                if (!$file || !$file->isValid()) {
                    continue;
                }
                if ($file->getSize() > 8 * 1024 * 1024) {
                    throw new \Snt\Quotes\Domain\Exception\QuoteException('The attachment « %name% » is larger than 8 MB.', ['%name%' => $file->getClientOriginalName()]);
                }
                $attachments[] = [
                    'content' => (string) file_get_contents($file->getPathname()),
                    'name' => $file->getClientOriginalName(),
                    'mime' => $file->getMimeType() ?: 'application/octet-stream',
                ];
            }
            $options = new SendOptions(
                SendOptions::parseEmails($request->request->get('to', '')),
                SendOptions::parseEmails($request->request->get('cc', '')),
                (string) $request->request->get('subject', ''),
                (string) $request->request->get('message', ''),
                $attachments,
                $request->request->getBoolean('copy_employee', true)
            );
            $result = $this->sender->send($quote, $options);

            return [
                'result' => $result,
                'message' => $result === QuoteSender::RESULT_SENT
                    ? $this->t('The quote %ref% has been sent to %to%.', ['%ref%' => $quote->getFullReference(), '%to%' => implode(', ', $options->to)])
                    : $this->t('The discount exceeds the authorised threshold: the quote is awaiting approval by a manager before it can be sent.'),
                'redirect' => $this->generateUrl('admin_snt_quotes_view', ['quoteId' => $quoteId]),
            ];
        });
    }

    public function remindAction(Request $request, int $quoteId): RedirectResponse
    {
        return $this->postAndRedirect($request, 'edit', $quoteId, function (\SntQuote $quote): string {
            $this->sender->remind($quote);

            return $this->t('A reminder has been sent to the customer.');
        });
    }

    /* ------------------------------------------------------------------ */
    /* Statuts, duplication, suppression                                   */
    /* ------------------------------------------------------------------ */

    public function transitionAction(Request $request, int $quoteId, string $to): RedirectResponse
    {
        return $this->postAndRedirect($request, 'edit', $quoteId, function (\SntQuote $quote) use ($to, $request): string {
            $target = QuoteStatus::tryFrom($to);
            $manual = [QuoteStatus::Accepted, QuoteStatus::Declined, QuoteStatus::Expired, QuoteStatus::Draft];
            if (!$target || !in_array($target, $manual, true)) {
                throw new AccessDeniedHttpException();
            }
            $this->stateMachine->apply($quote, $target, trim((string) $request->request->get('comment', '')) ?: null);

            return $this->t('The quote is now « %status% ».', ['%status%' => $this->presenter->statusPresenter()->label($target)]);
        });
    }

    public function duplicateAction(Request $request, int $quoteId): RedirectResponse
    {
        try {
            $this->requireRight('create');
            $this->requireCsrfOrGridToken($request);
            $copy = $this->factory->duplicate($this->loadQuote($quoteId), false);
            $this->addFlash('success', $this->t('Draft %ref% created from the quote. You can now edit it.', ['%ref%' => $copy->getFullReference()]));

            return $this->redirectToRoute('admin_snt_quotes_edit', ['quoteId' => (int) $copy->id]);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof AccessDeniedHttpException ? $e->getMessage() : $this->translateException($e));

            return $this->redirectToRoute('admin_snt_quotes_index');
        }
    }

    public function newVersionAction(Request $request, int $quoteId): RedirectResponse
    {
        try {
            $this->requireRight('create');
            $this->requireCsrf($request);
            $source = $this->loadQuote($quoteId);
            if (in_array($source->getStatus(), [QuoteStatus::Ordered, QuoteStatus::Paid], true)) {
                throw new \Snt\Quotes\Domain\Exception\QuoteException('This quote has already been ordered: duplicate it instead.');
            }
            $version = $this->factory->duplicate($source, true);
            $this->addFlash('success', $this->t('Version %v% created. Edit it, then send it: it will replace the previous version.', ['%v%' => (int) $version->version]));

            return $this->redirectToRoute('admin_snt_quotes_edit', ['quoteId' => (int) $version->id]);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof AccessDeniedHttpException ? $e->getMessage() : $this->translateException($e));

            return $this->redirectToRoute('admin_snt_quotes_view', ['quoteId' => $quoteId]);
        }
    }

    public function deleteAction(Request $request, int $quoteId): RedirectResponse
    {
        try {
            $this->requireRight('delete');
            $this->requireCsrf($request);
            $quote = $this->loadQuote($quoteId);
            if (!in_array($quote->getStatus(), [QuoteStatus::Draft, QuoteStatus::Cancelled], true) || $quote->sent_at) {
                throw new \Snt\Quotes\Domain\Exception\QuoteException('Only drafts that have never been sent can be deleted. Expire the quote instead.');
            }
            $reference = $quote->getFullReference();
            $quote->delete();
            $this->addFlash('success', $this->t('Draft %ref% deleted.', ['%ref%' => $reference]));
        } catch (\Throwable $e) {
            $this->addFlash('error', $e instanceof AccessDeniedHttpException ? $e->getMessage() : $this->translateException($e));
        }

        return $this->redirectToRoute('admin_snt_quotes_index');
    }

    public function bulkExpireAction(Request $request): RedirectResponse
    {
        return $this->bulk($request, function (\SntQuote $quote): void {
            $this->sender->expire($quote, 'Bulk action');
        }, 'Quotes marked as expired: %count%.');
    }

    public function bulkRemindAction(Request $request): RedirectResponse
    {
        return $this->bulk($request, function (\SntQuote $quote): void {
            $this->sender->remind($quote);
        }, 'Reminders sent: %count%.');
    }

    private function bulk(Request $request, callable $action, string $successMessage): RedirectResponse
    {
        try {
            $this->requireRight('edit');
        } catch (AccessDeniedHttpException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('admin_snt_quotes_index');
        }
        $ids = array_map('intval', $this->bagArray($request->request, QuoteGridDefinitionFactory::GRID_ID . '_bulk'));
        $done = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                $quote = $this->loadQuote($id);
                $action($quote);
                ++$done;
            } catch (\Throwable $e) {
                $errors[] = ($quote ?? null) instanceof \SntQuote ? $quote->getFullReference() . ' : ' . $this->translateException($e) : $this->translateException($e);
            }
            unset($quote);
        }
        if ($done) {
            $this->addFlash('success', $this->t($successMessage, ['%count%' => $done]));
        }
        foreach (array_slice($errors, 0, 10) as $error) {
            $this->addFlash('error', $error);
        }

        return $this->redirectToRoute('admin_snt_quotes_index', ['tab' => $request->query->get('tab')]);
    }

    private function postAndRedirect(Request $request, string $right, int $quoteId, callable $action): RedirectResponse
    {
        try {
            $this->requireRight($right);
            $this->requireCsrf($request);
            $this->addFlash('success', $action($this->loadQuote($quoteId)));
        } catch (AccessDeniedHttpException $e) {
            $this->addFlash('error', $e->getMessage() ?: $this->t('This action is not allowed.'));
        } catch (\Throwable $e) {
            $this->addFlash('error', $this->translateException($e));
        }

        return $this->redirectToRoute('admin_snt_quotes_view', ['quoteId' => $quoteId]);
    }

    /**
     * Les actions de ligne de la Grid sont des formulaires POST protégés par le jeton d'URL admin
     * (_token) ; les autres formulaires du module envoient en plus le jeton CSRF du module.
     */
    private function requireCsrfOrGridToken(Request $request): void
    {
        if ($request->request->has('_csrf') || $request->headers->has('X-CSRF-Token')) {
            $this->requireCsrf($request);
        }
    }

    private function csrfToken(): string
    {
        return (string) $this->container->get('security.csrf.token_manager')->getToken(self::CSRF_ID)->getValue();
    }
}
