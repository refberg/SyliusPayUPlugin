<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Refberg\SyliusPayUPlugin\Api\AccessTokenProvider;
use Refberg\SyliusPayUPlugin\Api\AccessTokenProviderInterface;
use Refberg\SyliusPayUPlugin\Api\PayUClient;
use Refberg\SyliusPayUPlugin\Api\PayUClientInterface;
use Refberg\SyliusPayUPlugin\Command\CancelPaymentRequest;
use Refberg\SyliusPayUPlugin\Command\CapturePaymentRequest;
use Refberg\SyliusPayUPlugin\Command\NotifyPaymentRequest;
use Refberg\SyliusPayUPlugin\Command\RefundPaymentRequest;
use Refberg\SyliusPayUPlugin\Command\StatusPaymentRequest;
use Refberg\SyliusPayUPlugin\CommandHandler\CancelPaymentRequestHandler;
use Refberg\SyliusPayUPlugin\CommandHandler\CapturePaymentRequestHandler;
use Refberg\SyliusPayUPlugin\CommandHandler\NotifyPaymentRequestHandler;
use Refberg\SyliusPayUPlugin\CommandHandler\RefundPaymentRequestHandler;
use Refberg\SyliusPayUPlugin\CommandHandler\StatusPaymentRequestHandler;
use Refberg\SyliusPayUPlugin\CommandProvider\PaymentRequestCommandProvider;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolver;
use Refberg\SyliusPayUPlugin\Config\CredentialsResolverInterface;
use Refberg\SyliusPayUPlugin\EventListener\PaymentTransitionListener;
use Refberg\SyliusPayUPlugin\Form\Type\PayUGatewayConfigurationType;
use Refberg\SyliusPayUPlugin\Notification\NotificationParser;
use Refberg\SyliusPayUPlugin\Notification\NotificationParserInterface;
use Refberg\SyliusPayUPlugin\Order\ExtOrderIdGenerator;
use Refberg\SyliusPayUPlugin\Order\ExtOrderIdGeneratorInterface;
use Refberg\SyliusPayUPlugin\Order\OrderRequestFactory;
use Refberg\SyliusPayUPlugin\Order\OrderRequestFactoryInterface;
use Refberg\SyliusPayUPlugin\Order\PayUOrderValidator;
use Refberg\SyliusPayUPlugin\Order\PayUOrderValidatorInterface;
use Refberg\SyliusPayUPlugin\OrderPay\CaptureHttpResponseProvider;
use Refberg\SyliusPayUPlugin\Payment\PaymentLocker;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentStateApplier;
use Refberg\SyliusPayUPlugin\Payment\PaymentStateApplierInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizer;
use Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizerInterface;
use Refberg\SyliusPayUPlugin\PayUGateway;
use Refberg\SyliusPayUPlugin\Provider\NotifyResponseProvider;
use Refberg\SyliusPayUPlugin\Provider\PayUNotifyPaymentProvider;
use Refberg\SyliusPayUPlugin\Signature\SignatureVerifier;
use Refberg\SyliusPayUPlugin\Signature\SignatureVerifierInterface;
use Sylius\Bundle\PaymentBundle\CommandProvider\ActionsCommandProvider;
use Sylius\Bundle\PaymentBundle\Provider\ActionsHttpResponseProvider;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

return static function (ContainerConfigurator $container): void {
    $container->parameters()->set('refberg_payu.http_timeout', 15.0);

    $services = $container->services();
    $services->defaults()->private();

    // Configuration and PayU API
    $services->set(CredentialsResolverInterface::class, CredentialsResolver::class);

    // Override "refberg_payu.http_client" to route PayU traffic through a custom client (proxy, mock).
    $services->alias('refberg_payu.http_client', 'http_client');

    $services->set(AccessTokenProviderInterface::class, AccessTokenProvider::class)
        ->args([service('refberg_payu.http_client'), service('refberg_payu.cache'), param('refberg_payu.http_timeout')]);

    $services->set(PayUClientInterface::class, PayUClient::class)
        ->args([
            service('refberg_payu.http_client'),
            service(AccessTokenProviderInterface::class),
            service('logger')->ignoreOnInvalid(),
            param('refberg_payu.http_timeout'),
        ])
        ->tag('monolog.logger', ['channel' => 'payu']);

    $services->set(SignatureVerifierInterface::class, SignatureVerifier::class);
    $services->set(NotificationParserInterface::class, NotificationParser::class)->args([service(SignatureVerifierInterface::class)]);

    // Orders and payment state
    $services->set(ExtOrderIdGeneratorInterface::class, ExtOrderIdGenerator::class);
    $services->set(PayUOrderValidatorInterface::class, PayUOrderValidator::class);
    $services->set(OrderRequestFactoryInterface::class, OrderRequestFactory::class)
        ->args([service('router'), null]);

    $services->set(PaymentLockerInterface::class, PaymentLocker::class)
        ->args([service('doctrine.orm.entity_manager')]);

    $services->set(PaymentStateApplierInterface::class, PaymentStateApplier::class)
        ->args([service('sylius_abstraction.state_machine'), service('logger')->ignoreOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'payu']);

    $services->set(PaymentSynchronizerInterface::class, PaymentSynchronizer::class)
        ->args([
            service(PayUClientInterface::class),
            service(PayUOrderValidatorInterface::class),
            service(PaymentStateApplierInterface::class),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'payu']);

    // Service IDs before 1.1, kept for applications that reference them.
    $deprecatedAliases = [
        NotificationParser::class => NotificationParserInterface::class,
        ExtOrderIdGenerator::class => ExtOrderIdGeneratorInterface::class,
        PayUOrderValidator::class => PayUOrderValidatorInterface::class,
        PaymentStateApplier::class => PaymentStateApplierInterface::class,
        PaymentSynchronizer::class => PaymentSynchronizerInterface::class,
    ];
    foreach ($deprecatedAliases as $alias => $id) {
        $services->alias($alias, $id)
            ->deprecate('refberg/sylius-payu-plugin', '1.1', 'The "%alias_id%" service alias is deprecated, use "' . $id . '" instead.');
    }

    // Admin: gateway configuration form
    $services->set(PayUGatewayConfigurationType::class)
        ->tag('sylius.gateway_configuration_type', ['type' => PayUGateway::FACTORY_NAME, 'label' => 'refberg_payu.gateway_factory.refberg_payu'])
        ->tag('form.type');

    // Payment request commands
    $services->set('refberg_payu.command_provider', ActionsCommandProvider::class)
        ->args([tagged_locator('refberg_payu.command_provider', indexAttribute: 'action')])
        ->tag('sylius.payment_request.command_provider', ['gateway_factory' => PayUGateway::FACTORY_NAME]);

    $commands = [
        'capture' => [CapturePaymentRequest::class, [PaymentRequestInterface::ACTION_CAPTURE, PaymentRequestInterface::ACTION_AUTHORIZE]],
        'status' => [StatusPaymentRequest::class, [PaymentRequestInterface::ACTION_STATUS]],
        'notify' => [NotifyPaymentRequest::class, [PaymentRequestInterface::ACTION_NOTIFY]],
        'refund' => [RefundPaymentRequest::class, [PaymentRequestInterface::ACTION_REFUND]],
        'cancel' => [CancelPaymentRequest::class, [PaymentRequestInterface::ACTION_CANCEL]],
    ];
    foreach ($commands as $name => [$commandClass, $actions]) {
        $definition = $services->set('refberg_payu.command_provider.' . $name, PaymentRequestCommandProvider::class)
            ->args([$commandClass]);
        foreach ($actions as $action) {
            $definition->tag('refberg_payu.command_provider', ['action' => $action]);
        }
    }

    $handlerDefaults = [
        service('sylius.provider.payment_request'),
        service(CredentialsResolverInterface::class),
    ];

    $services->set(CapturePaymentRequestHandler::class)
        ->args([
            ...$handlerDefaults,
            service(PayUClientInterface::class),
            service(OrderRequestFactoryInterface::class),
            service(ExtOrderIdGeneratorInterface::class),
            service(PaymentLockerInterface::class),
            service('sylius_abstraction.state_machine'),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ->tag('monolog.logger', ['channel' => 'payu']);

    $services->set(StatusPaymentRequestHandler::class)
        ->args([
            ...$handlerDefaults,
            service(PaymentSynchronizerInterface::class),
            service(PaymentLockerInterface::class),
            service('sylius_abstraction.state_machine'),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ->tag('monolog.logger', ['channel' => 'payu']);

    $services->set(NotifyPaymentRequestHandler::class)
        ->args([
            ...$handlerDefaults,
            service(NotificationParserInterface::class),
            service(PaymentSynchronizerInterface::class),
            service(PaymentLockerInterface::class),
            service('sylius_abstraction.state_machine'),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ->tag('monolog.logger', ['channel' => 'payu']);

    $services->set(CancelPaymentRequestHandler::class)
        ->args([
            ...$handlerDefaults,
            service(PayUClientInterface::class),
            service(PaymentLockerInterface::class),
            service('sylius_abstraction.state_machine'),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ->tag('monolog.logger', ['channel' => 'payu']);

    $services->set(RefundPaymentRequestHandler::class)
        ->args([
            ...$handlerDefaults,
            service(PayUClientInterface::class),
            service(PaymentLockerInterface::class),
            service('sylius_abstraction.state_machine'),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ->tag('monolog.logger', ['channel' => 'payu']);

    // Notifications
    $services->set(PayUNotifyPaymentProvider::class)
        ->args([
            service(CredentialsResolverInterface::class),
            service(NotificationParserInterface::class),
            service(ExtOrderIdGeneratorInterface::class),
            service('sylius.repository.payment'),
            service('sylius.repository.payment_request'),
        ])
        ->tag('sylius.payment_request.payment_notify_provider');

    $services->set(NotifyResponseProvider::class)
        ->decorate('sylius.provider.payment_request.notify_response')
        ->args([service('.inner'), service(CredentialsResolverInterface::class)]);

    // Shop: HTTP responses
    $services->set('refberg_payu.http_response_provider', ActionsHttpResponseProvider::class)
        ->args([tagged_locator('refberg_payu.http_response_provider', indexAttribute: 'action')])
        ->tag('sylius.payment_request.provider.http_response', ['gateway_factory' => PayUGateway::FACTORY_NAME]);

    $services->set(CaptureHttpResponseProvider::class)
        ->tag('refberg_payu.http_response_provider', ['action' => PaymentRequestInterface::ACTION_CAPTURE])
        ->tag('refberg_payu.http_response_provider', ['action' => PaymentRequestInterface::ACTION_AUTHORIZE]);

    // Refunds and cancellations from the admin panel
    $transitionActions = [
        'refund' => PaymentRequestInterface::ACTION_REFUND,
        'cancel' => PaymentRequestInterface::ACTION_CANCEL,
    ];
    foreach ($transitionActions as $transition => $action) {
        $services->set('refberg_payu.listener.payment_transition.' . $transition, PaymentTransitionListener::class)
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
