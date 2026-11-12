<?php

declare(strict_types=1);

namespace Snt\Quotes;

use Snt\Quotes\Adapter\CatalogPriceProvider;
use Snt\Quotes\Adapter\TaxResolver;
use Snt\Quotes\Config\ModuleConfig;
use Snt\Quotes\Conversion\OrderPostProcessor;
use Snt\Quotes\Conversion\QuoteToOrderConverter;
use Snt\Quotes\Conversion\TechnicalCartBuilder;
use Snt\Quotes\Install\TechnicalSetup;
use Snt\Quotes\Mail\QuoteMailer;
use Snt\Quotes\Payment\HiPayGateway;
use Snt\Quotes\Payment\PaymentLinkService;
use Snt\Quotes\Payment\PayPlugGateway;
use Snt\Quotes\Pdf\QuotePdfGenerator;
use Snt\Quotes\Presenter\PriceDisplay;
use Snt\Quotes\Presenter\QuotePresenter;
use Snt\Quotes\Presenter\StatusPresenter;
use Snt\Quotes\Repository\CustomerQuoteRepository;
use Snt\Quotes\Service\HistoryLogger;
use Snt\Quotes\Service\QuoteEditor;
use Snt\Quotes\Service\QuoteFactory;
use Snt\Quotes\Service\QuoteMessages;
use Snt\Quotes\Service\ReferenceGenerator;
use Snt\Quotes\Service\QuoteStateMachine;
use Snt\Quotes\Storage\S3Storage;

/**
 * Services hors conteneur Symfony : hooks du module et contrôleurs front (en PS 8, le conteneur front ne charge pas
 * config/services.yml des modules). Mêmes classes que le BO, instanciées une fois par requête.
 */
final class ServiceFactory
{
    /** @var array<string, object> */
    private static array $instances = [];

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param callable(): T   $factory
     *
     * @return T
     */
    private static function shared(string $class, callable $factory): object
    {
        /** @var T $instance */
        $instance = self::$instances[$class] ??= $factory();

        return $instance;
    }

    public static function config(): ModuleConfig
    {
        return self::shared(ModuleConfig::class, static fn (): ModuleConfig => new ModuleConfig());
    }

    public static function history(): HistoryLogger
    {
        return self::shared(HistoryLogger::class, static fn (): HistoryLogger => new HistoryLogger());
    }

    public static function stateMachine(): QuoteStateMachine
    {
        return self::shared(QuoteStateMachine::class, static fn (): QuoteStateMachine => new QuoteStateMachine(self::history()));
    }

    public static function technicalSetup(): TechnicalSetup
    {
        return self::shared(TechnicalSetup::class, static fn (): TechnicalSetup => new TechnicalSetup());
    }

    public static function cartBuilder(): TechnicalCartBuilder
    {
        return self::shared(TechnicalCartBuilder::class, static fn (): TechnicalCartBuilder => new TechnicalCartBuilder(self::technicalSetup()));
    }

    public static function postProcessor(): OrderPostProcessor
    {
        return self::shared(OrderPostProcessor::class, static fn (): OrderPostProcessor => new OrderPostProcessor(
            self::stateMachine(),
            self::history(),
            self::technicalSetup(),
            self::cartBuilder()
        ));
    }

    public static function converter(): QuoteToOrderConverter
    {
        return self::shared(QuoteToOrderConverter::class, static fn (): QuoteToOrderConverter => new QuoteToOrderConverter(
            self::cartBuilder(),
            self::postProcessor(),
            self::stateMachine(),
            self::config(),
            new PayPlugGateway()
        ));
    }

    public static function presenter(): QuotePresenter
    {
        return self::shared(QuotePresenter::class, static fn (): QuotePresenter => new QuotePresenter(new StatusPresenter()));
    }

    public static function paymentLinks(): PaymentLinkService
    {
        return self::shared(PaymentLinkService::class, static fn (): PaymentLinkService => new PaymentLinkService(self::config(), self::history()));
    }

    public static function hipay(): HiPayGateway
    {
        return self::shared(HiPayGateway::class, static fn (): HiPayGateway => new HiPayGateway());
    }

    public static function payplug(): PayPlugGateway
    {
        return self::shared(PayPlugGateway::class, static fn (): PayPlugGateway => new PayPlugGateway());
    }

    public static function mailer(): QuoteMailer
    {
        return self::shared(QuoteMailer::class, static fn (): QuoteMailer => new QuoteMailer(self::config(), self::presenter(), self::history()));
    }

    public static function editor(): QuoteEditor
    {
        return self::shared(QuoteEditor::class, static fn (): QuoteEditor => new QuoteEditor(new TaxResolver(), new CatalogPriceProvider(), self::config(), self::history()));
    }

    public static function factory(): QuoteFactory
    {
        return self::shared(QuoteFactory::class, static fn (): QuoteFactory => new QuoteFactory(self::editor(), new ReferenceGenerator(self::config()), self::presenter(), self::history()));
    }

    public static function messages(): QuoteMessages
    {
        return self::shared(QuoteMessages::class, static fn (): QuoteMessages => new QuoteMessages(self::history()));
    }

    public static function customerQuotes(): CustomerQuoteRepository
    {
        return self::shared(CustomerQuoteRepository::class, static fn (): CustomerQuoteRepository => new CustomerQuoteRepository());
    }

    public static function priceDisplay(): PriceDisplay
    {
        return self::shared(PriceDisplay::class, static fn (): PriceDisplay => new PriceDisplay(self::config()));
    }

    public static function pdf(): QuotePdfGenerator
    {
        return self::shared(QuotePdfGenerator::class, static fn (): QuotePdfGenerator => new QuotePdfGenerator(new S3Storage(self::config()), self::history(), self::config()));
    }
}
