<?php

declare(strict_types=1);

namespace Snt\Quotes\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Snt\Quotes\Domain\Exception\QuoteException;
use Snt\Quotes\Service\Permission\QuotePermissions;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Base commune PS 8.2 / 9.x :
 * - FrameworkBundleAdminController existe dans les deux versions ; on n'utilise ni get() (déprécié en 9)
 *   ni trans() (signatures différentes) : dépendances injectées par le constructeur ;
 * - droits vérifiés explicitement (pas d'annotation @AdminSecurity / attribut #[AdminSecurity],
 *   dont la syntaxe diffère entre les deux versions).
 */
abstract class AbstractQuoteController extends FrameworkBundleAdminController
{
    public const DOMAIN = 'Modules.Sntquotes.Admin';
    public const CSRF_ID = 'snt_quotes';

    public function __construct(
        protected readonly TranslatorInterface $translator,
        protected readonly QuotePermissions $permissions,
    ) {
        if (method_exists(FrameworkBundleAdminController::class, '__construct')) {
            parent::__construct();
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function t(string $id, array $parameters = [], string $domain = self::DOMAIN): string
    {
        return $this->translator->trans($id, $parameters, $domain);
    }

    protected function translateException(\Throwable $e): string
    {
        if ($e instanceof QuoteException) {
            return $this->t($e->getMessage(), $e->getParameters());
        }
        \PrestaShopLogger::addLog('[snt_quotes] ' . get_class($e) . ': ' . $e->getMessage(), 3);

        return $this->t('An unexpected error occurred. Try again; if the problem persists, contact support (details in the logs).');
    }

    protected function requireRight(string $right): void
    {
        $allowed = match ($right) {
            'read' => $this->permissions->canRead(),
            'create' => $this->permissions->canCreate(),
            'edit' => $this->permissions->canEdit(),
            'delete' => $this->permissions->canDelete(),
            'convert' => $this->permissions->canConvert(),
            'approve' => $this->permissions->canApproveDiscount(),
            default => false,
        };
        if (!$allowed) {
            throw new AccessDeniedHttpException($this->t('You do not have permission to perform this action. Ask an administrator.'));
        }
    }

    /**
     * Jeton CSRF des appels POST (en-tête X-CSRF-Token ou champ _csrf).
     */
    protected function requireCsrf(Request $request): void
    {
        $token = (string) ($request->headers->get('X-CSRF-Token') ?? $request->request->get('_csrf', ''));
        if (!$this->isCsrfTokenValid(self::CSRF_ID, $token)) {
            throw new AccessDeniedHttpException($this->t('Your session has expired. Reload the page and try again.'));
        }
    }

    protected function loadQuote(int $quoteId): \SntQuote
    {
        $quote = new \SntQuote($quoteId);
        if (!\Validate::isLoadedObject($quote) || $quote->deleted) {
            throw new NotFoundHttpException($this->t('This quote does not exist or has been deleted.'));
        }
        $shops = \Shop::getContextListShopID();
        if ($shops && !in_array((int) $quote->id_shop, array_map('intval', $shops), true)) {
            throw new NotFoundHttpException($this->t('This quote belongs to another shop. Switch shop to open it.'));
        }

        return $quote;
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(Request $request): array
    {
        $content = (string) $request->getContent();
        if ($content === '') {
            return $request->request->all();
        }
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function jsonError(string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return new JsonResponse(['success' => false, 'message' => $message] + $extra, $status);
    }

    /**
     * Exécute une action JSON avec contrôle CSRF / droits et messages d'erreur lisibles.
     */
    protected function jsonAction(Request $request, string $right, callable $action, bool $csrf = true): JsonResponse
    {
        try {
            $this->requireRight($right);
            if ($csrf) {
                $this->requireCsrf($request);
            }
            $result = $action();

            return $result instanceof JsonResponse ? $result : new JsonResponse(['success' => true] + (array) $result);
        } catch (AccessDeniedHttpException $e) {
            return $this->jsonError($e->getMessage(), 403);
        } catch (NotFoundHttpException $e) {
            return $this->jsonError($e->getMessage(), 404);
        } catch (\Throwable $e) {
            return $this->jsonError($this->translateException($e), $e instanceof QuoteException ? 422 : 500);
        }
    }

    /**
     * Tableau d'un ParameterBag, compatible Symfony 4.4 (PS 8 : get() renvoie le tableau)
     * et Symfony 6 (PS 9 : all($key) obligatoire, get() refuse les valeurs non scalaires).
     *
     * @return array<int|string, mixed>
     */
    protected function bagArray(\Symfony\Component\HttpFoundation\ParameterBag $bag, string $key): array
    {
        if ((new \ReflectionMethod($bag, 'all'))->getNumberOfParameters() > 0) {
            return (array) $bag->all($key); // @phpstan-ignore-line (Symfony 6 ; non atteint en Symfony 4.4)
        }
        $value = $bag->get($key, []);

        return is_array($value) ? $value : [];
    }

    protected function shopId(): int
    {
        return (int) \Context::getContext()->shop->id;
    }

    protected function langId(): int
    {
        return (int) \Context::getContext()->language->id;
    }
}
