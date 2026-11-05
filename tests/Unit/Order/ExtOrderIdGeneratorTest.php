<?php

declare(strict_types=1);

namespace Tests\Refberg\SyliusTpayPlugin\Unit\Order;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Refberg\SyliusTpayPlugin\Order\ExtOrderIdGenerator;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;

final class ExtOrderIdGeneratorTest extends TestCase
{
    public function test_it_builds_a_unique_id_that_contains_the_payment_id(): void
    {
        $generator = new ExtOrderIdGenerator();

        $id = $generator->generate($this->payment(42, '#000/12 3'), $this->paymentRequest('3F2504E0-4F89-11D3-9A0C-0305E82C3301'));

        self::assertSame('000123-42-3f2504e04f89', $id);
        self::assertSame(42, $generator->extractPaymentId($id));
    }

    public function test_every_attempt_gets_a_different_id(): void
    {
        $generator = new ExtOrderIdGenerator();
        $payment = $this->payment(7, '000001');

        self::assertNotSame(
            $generator->generate($payment, $this->paymentRequest('aaaaaaaa-0000-0000-0000-000000000000')),
            $generator->generate($payment, $this->paymentRequest('bbbbbbbb-0000-0000-0000-000000000000')),
        );
    }

    public function test_it_does_not_extract_from_foreign_ids(): void
    {
        $generator = new ExtOrderIdGenerator();

        self::assertNull($generator->extractPaymentId('12345'));
        self::assertNull($generator->extractPaymentId('order-abc-0123456789ab'));
        self::assertNull($generator->extractPaymentId('order-1-XYZ'));
    }

    public function test_it_requires_persisted_entities(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ExtOrderIdGenerator())->generate($this->payment(null, '1'), $this->paymentRequest(null));
    }

    private function payment(?int $id, string $number): PaymentInterface
    {
        $order = new Order();
        $order->setNumber($number);

        $payment = self::createStub(PaymentInterface::class);
        $payment->method('getId')->willReturn($id);
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }

    private function paymentRequest(?string $hash): PaymentRequestInterface
    {
        $request = self::createStub(PaymentRequestInterface::class);
        $request->method('getId')->willReturn($hash);

        return $request;
    }
}
