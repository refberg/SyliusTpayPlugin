<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Refberg\SyliusTpayPlugin\Api\AccessTokenProvider;
use Refberg\SyliusTpayPlugin\Api\AccessTokenProviderInterface;
use Refberg\SyliusTpayPlugin\Api\TpayClient;
use Refberg\SyliusTpayPlugin\Api\TpayClientInterface;
use Refberg\SyliusTpayPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusTpayPlugin\Command\CapturePaymentRequest;
use Refberg\SyliusTpayPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusTpayPlugin\Command\RefundPaymentRequest;
use Refberg\SyliusTpayPlugin\Command\StatusPaymentRequest;
use Refberg\SyliusTpayPlugin\CommandHandler\CancelPaymentRequestHandler;
use Refberg\SyliusTpayPlugin\CommandHandler\CapturePaymentRequestHandler;
use Refberg\SyliusTpayPlugin\CommandHandler\NotifyPaymentRequestHandler;
use Refberg\SyliusTpayPlugin\CommandHandler\RefundPaymentRequestHandler;
use Refberg\SyliusTpayPlugin\CommandHandler\StatusPaymentRequestHandler;
use Refberg\SyliusTpayPlugin\CommandProvider\PaymentRequestCommandProvider;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolver;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusTpayPlugin\EventListener\PaymentTransitionListener;
use Refberg\SyliusTpayPlugin\Form\Type\TpayGatewayConfigurationType;
use Refberg\SyliusTpayPlugin\Notification\NotificationParser;
use Refberg\SyliusTpayPlugin\Notification\NotificationParserInterface;
use Refberg\SyliusTpayPlugin\Order\ExtOrderIdGenerator;
use Refberg\SyliusTpayPlugin\Order\ExtOrderIdGeneratorInterface;
use Refberg\SyliusTpayPlugin\Order\TpayTransactionValidator;
use Refberg\SyliusTpayPlugin\Order\TpayTransactionValidatorInterface;
use Refberg\SyliusTpayPlugin\Order\TransactionRequestFactory;
use Refberg\SyliusTpayPlugin\Order\TransactionRequestFactoryInterface;
use Refberg\SyliusTpayPlugin\OrderPay\CaptureHttpResponseProvider;
use Refberg\SyliusTpayPlugin\Payment\PaymentLocker;
use Refberg\SyliusTpayPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentStateApplier;
use Refberg\SyliusTpayPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusTpayPlugin\Payment\PaymentSynchronizer;
use Refberg\SyliusTpayPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusTpayPlugin\Provider\NotifyResponseProvider;
use Refberg\SyliusTpayPlugin\Provider\TpayNotifyPaymentProvider;
use Refberg\SyliusTpayPlugin\Signature\CertificateProvider;
use Refberg\SyliusTpayPlugin\Signature\CertificateProviderInterface;
use Refberg\SyliusTpayPlugin\Signature\ChecksumVerifier;
use Refberg\SyliusTpayPlugin\Signature\ChecksumVerifierInterface;
use Refberg\SyliusTpayPlugin\Signature\JwsVerifier;
use Refberg\SyliusTpayPlugin\Signature\JwsVerifierInterface;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Bundle\PaymentBundle\CommandProvider\ActionsCommandProvider;
use Sylius\Bundle\PaymentBundle\Provider\ActionsHttpResponseProvider;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('refberg_tpay.http_timeout', 15.0);

    $services = $container->services();
    $services->defaults()->private();

    // Configuration and Tpay API
    $services->set(CredentialsResolverInterface::class, CredentialsResolver::class);

    // Override "refberg_tpay.http_client" to route Tpay traffic through a custom client (proxy, mock).
    $services->alias('refberg_tpay.http_client', 'http_client');

    $services->set(AccessTokenProviderInterface::class, AccessTokenProvider::class)
        ->args([service('refberg_tpay.http_client'), service('refberg_tpay.cache'), param('refberg_tpay.http_timeout')]);

    $services->set(TpayClientInterface::class, TpayClient::class)
        ->args([
            service('refberg_tpay.http_client'),
            service(AccessTokenProviderInterface::class),
            service('logger')->ignoreOnInvalid(),
            param('refberg_tpay.http_timeout'),
        ])
        ->tag('monolog.logger', ['channel' => 'tpay']);

    // Notification signatures
    $services->set(CertificateProviderInterface::class, CertificateProvider::class)
        ->args([service('refberg_tpay.http_client'), service('refberg_tpay.cache'), param('refberg_tpay.http_timeout')]);
    $services->set(JwsVerifierInterface::class, JwsVerifier::class)->args([service(CertificateProviderInterface::class)]);
    $services->set(ChecksumVerifierInterface::class, ChecksumVerifier::class);
    $services->set(NotificationParserInterface::class, NotificationParser::class)
        ->args([service(ChecksumVerifierInterface::class), service(JwsVerifierInterface::class)]);

    // Transactions and payment state
    $services->set(ExtOrderIdGeneratorInterface::class, ExtOrderIdGenerator::class);
    $services->set(TpayTransactionValidatorInterface::class, TpayTransactionValidator::class);
    $services->set(TransactionRequestFactoryInterface::class, TransactionRequestFactory::class)
        ->args([service('router'), null]);

    $services->set(PaymentLockerInterface::class, PaymentLocker::class)
        ->args([service('doctrine.orm.entity_manager')]);

    $services->set(PaymentStateApplierInterface::class, PaymentStateApplier::class)
        ->args([service('sylius_abstraction.state_machine'), service('logger')->ignoreOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'tpay']);

    $services->set(PaymentSynchronizerInterface::class, PaymentSynchronizer::class)
        ->args([
            service(TpayClientInterface::class),
            service(TpayTransactionValidatorInterface::class),
            service(PaymentStateApplierInterface::class),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'tpay']);

    // Admin: gateway configuration form
    $services->set(TpayGatewayConfigurationType::class)
        ->tag('sylius.gateway_configuration_type', ['type' => TpayGateway::FACTORY_NAME, 'label' => 'refberg_tpay.gateway_factory.refberg_tpay'])
        ->tag('form.type');

    // Payment request commands
    $services->set('refberg_tpay.command_provider', ActionsCommandProvider::class)
        ->args([tagged_locator('refberg_tpay.command_provider', indexAttribute: 'action')])
        ->tag('sylius.payment_request.command_provider', ['gateway_factory' => TpayGateway::FACTORY_NAME]);

    $commands = [
        'capture' => [CapturePaymentRequest::class, [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE]],
        'status' => [StatusPaymentRequest::class, [PaymentRequestInterface::ACTION_STATUS]],
        'notify' => [NotifyPaymentRequest::class, [PaymentRequestInterface::ACTION_NOTIFY]],
        'refund' => [RefundPaymentRequest::class, [PaymentRequestInterface::ACTION_REFUND]],
        'cancel' => [CancelPaymentRequest::class, [PaymentRequestInterface::ACTION_CANCEL]],
    ];
    foreach ($commands as $name => [$commandClass, $actions]) {
        $definition = $services->set('refberg_tpay.command_provider.' . $name, PaymentRequestCommandProvider::class)
            ->args([$commandClass]);
        foreach ($actions as $action) {
            $definition->tag('refberg_tpay.command_provider', ['action' => $action]);
        }
    }

    $handlerDefaults = [
        service('sylius.provider.payment_request'),
        service(CredentialsResolverInterface::class),
    ];

    $handlers = [
        CapturePaymentRequestHandler::class => [
            service(TpayClientInterface::class),
            service(TransactionRequestFactoryInterface::class),
            service(ExtOrderIdGeneratorInterface::class),
        ],
        StatusPaymentRequestHandler::class => [
            service(PaymentSynchronizerInterface::class),
        ],
        NotifyPaymentRequestHandler::class => [
            service(NotificationParserInterface::class),
            service(PaymentSynchronizerInterface::class),
        ],
        CancelPaymentRequestHandler::class => [
            service(TpayClientInterface::class),
        ],
        RefundPaymentRequestHandler::class => [
            service(TpayClientInterface::class),
            service(PaymentSynchronizerInterface::class),
        ],
    ];
    foreach ($handlers as $handlerClass => $arguments) {
        $services->set($handlerClass)
            ->args([
                ...$handlerDefaults,
                ...$arguments,
                service(PaymentLockerInterface::class),
                service('sylius_abstraction.state_machine'),
                service('logger')->ignoreOnInvalid(),
            ])
            ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
            ->tag('monolog.logger', ['channel' => 'tpay']);
    }

    // Notifications
    $services->set(TpayNotifyPaymentProvider::class)
        ->args([
            service(CredentialsResolverInterface::class),
            service(NotificationParserInterface::class),
            service(ExtOrderIdGeneratorInterface::class),
            service('sylius.repository.payment'),
        ])
        ->tag('sylius.payment_request.payment_notify_provider');

    $services->set(NotifyResponseProvider::class)
        ->decorate('sylius.provider.payment_request.notify_response')
        ->args([service('.inner'), service(CredentialsResolverInterface::class)]);

    // Shop: HTTP responses
    $services->set('refberg_tpay.http_response_provider', ActionsHttpResponseProvider::class)
        ->args([tagged_locator('refberg_tpay.http_response_provider', indexAttribute: 'action')])
        ->tag('sylius.payment_request.provider.http_response', ['gateway_factory' => TpayGateway::FACTORY_NAME]);

    $services->set(CaptureHttpResponseProvider::class)
        ->tag('refberg_tpay.http_response_provider', ['action' => PaymentRequestInterface::ACTION_CAPTURE])
        ->tag('refberg_tpay.http_response_provider', ['action' => PaymentRequestInterface::ACTION_AUTHORIZE]);

    // Refunds and cancellations from the admin panel
    $transitionActions = [
        'refund' => PaymentRequestInterface::ACTION_REFUND,
        'cancel' => PaymentRequestInterface::ACTION_CANCEL,
    ];
    foreach ($transitionActions as $transition => $action) {
        $services->set('refberg_tpay.listener.payment_transition.' . $transition, PaymentTransitionListener::class)
            ->args([
                service(CredentialsResolverInterface::class),
                service('sylius.factory.payment_request'),
                service('sylius.repository.payment_request'),
                service('sylius.announcer.payment_request'),
                $action,
            ])
            ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.completed.' . $transition]);
    }
};
