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
use Refberg\SyliusPayUPlugin\Order\ExtOrderIdGenerator;
use Refberg\SyliusPayUPlugin\Order\OrderRequestFactory;
use Refberg\SyliusPayUPlugin\Order\OrderRequestFactoryInterface;
use Refberg\SyliusPayUPlugin\Order\PayUOrderValidator;
use Refberg\SyliusPayUPlugin\OrderPay\CaptureHttpResponseProvider;
use Refberg\SyliusPayUPlugin\OrderPay\StatusHttpResponseProvider;
use Refberg\SyliusPayUPlugin\Payment\PaymentLocker;
use Refberg\SyliusPayUPlugin\Payment\PaymentLockerInterface;
use Refberg\SyliusPayUPlugin\Payment\PaymentStateApplier;
use Refberg\SyliusPayUPlugin\Payment\PaymentSynchronizer;
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
    $services->set(NotificationParser::class)->args([service(SignatureVerifierInterface::class)]);

    // Orders and payment state
    $services->set(ExtOrderIdGenerator::class);
    $services->set(PayUOrderValidator::class);
    $services->set(OrderRequestFactoryInterface::class, OrderRequestFactory::class)
        ->args([service('router'), service('sylius_shop.provider.order_pay.after_pay_url')->nullOnInvalid()]);

    $services->set(PaymentLockerInterface::class, PaymentLocker::class)
        ->args([service('doctrine.orm.entity_manager')]);

    $services->set(PaymentStateApplier::class)
        ->args([service('sylius_abstraction.state_machine'), service('logger')->ignoreOnInvalid()])
        ->tag('monolog.logger', ['channel' => 'payu']);

    $services->set(PaymentSynchronizer::class)
        ->args([
            service(PayUClientInterface::class),
            service(PayUOrderValidator::class),
            service(PaymentStateApplier::class),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('monolog.logger', ['channel' => 'payu']);

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
            service(ExtOrderIdGenerator::class),
            service(PaymentLockerInterface::class),
            service('sylius_abstraction.state_machine'),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ->tag('monolog.logger', ['channel' => 'payu']);

    $services->set(StatusPaymentRequestHandler::class)
        ->args([
            ...$handlerDefaults,
            service(PaymentSynchronizer::class),
            service(PaymentLockerInterface::class),
            service('sylius_abstraction.state_machine'),
            service('logger')->ignoreOnInvalid(),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus'])
        ->tag('monolog.logger', ['channel' => 'payu']);

    $services->set(NotifyPaymentRequestHandler::class)
        ->args([
            ...$handlerDefaults,
            service(NotificationParser::class),
            service(PaymentSynchronizer::class),
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
            service(NotificationParser::class),
            service(ExtOrderIdGenerator::class),
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

    $services->set(StatusHttpResponseProvider::class)
        ->args([service('sylius_shop.provider.order_pay.final_url')])
        ->tag('refberg_payu.http_response_provider', ['action' => PaymentRequestInterface::ACTION_STATUS]);

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
