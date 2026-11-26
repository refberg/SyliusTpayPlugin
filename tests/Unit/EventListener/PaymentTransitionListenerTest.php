<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\EventListener;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Config\CredentialsResolver;
use Refberg\SyliusTpayPlugin\EventListener\PaymentTransitionListener;
use Refberg\SyliusTpayPlugin\TpayGateway;
use Sylius\Bundle\PaymentBundle\Announcer\PaymentRequestAnnouncerInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfig;
use Sylius\Component\Core\Model\Payment;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Payment\Factory\PaymentRequestFactoryInterface;
use Sylius\Component\Payment\Model\PaymentRequest;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Sylius\Component\Payment\Repository\PaymentRequestRepositoryInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;

final class PaymentTransitionListenerTest extends TestCase
{
    #[TestWith([false])]
    #[TestWith([true])]
    public function test_one_payment_request_is_created_per_action(bool $existing): void
    {
        $method = new PaymentMethod();
        $gatewayConfig = new GatewayConfig();
        $gatewayConfig->setFactoryName(TpayGateway::FACTORY_NAME);
        $method->setGatewayConfig($gatewayConfig);
        $payment = new Payment();
        $payment->setMethod($method);
        $created = new PaymentRequest($payment, $method);

        $repository = $this->createMock(PaymentRequestRepositoryInterface::class);
        $repository->method('findOneByActionPaymentAndMethod')
            ->with(PaymentRequestInterface::ACTION_REFUND, $payment, $method)
            ->willReturn($existing ? new PaymentRequest($payment, $method) : null);
        $repository->expects($existing ? self::never() : self::once())->method('add')->with(self::callback(static fn (PaymentRequestInterface $paymentRequest): bool => $paymentRequest === $created && PaymentRequestInterface::ACTION_REFUND === $paymentRequest->getAction()));
        $factory = $this->createMock(PaymentRequestFactoryInterface::class);
        $factory->expects($existing ? self::never() : self::once())->method('create')->with($payment, $method)->willReturn($created);
        $announcer = $this->createMock(PaymentRequestAnnouncerInterface::class);
        $announcer->expects($existing ? self::never() : self::once())->method('dispatchPaymentRequestCommand')->with($created);

        $listener = new PaymentTransitionListener(new CredentialsResolver(), $factory, $repository, $announcer, PaymentRequestInterface::ACTION_REFUND);
        $listener(new CompletedEvent($payment, new Marking()));
    }
}
