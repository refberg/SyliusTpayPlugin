<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Order;

use InvalidArgumentException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use function is_scalar;
use function mb_substr;
use function preg_match;
use function preg_replace;
use function sprintf;
use function str_replace;
use function strtolower;
use function substr;

/**
 * Builds the Tpay "hiddenDescription" (returned as "tr_crc" in notifications):
 * "<orderNumber>-<paymentId>-<12 hex chars of the payment request hash>".
 *
 * Every payment attempt gets its own value, so a notification identifies the attempt.
 * The payment ID can be read back from a notification without trusting anything else in it.
 */
final readonly class ExtOrderIdGenerator implements ExtOrderIdGeneratorInterface
{
    private const PATTERN = '/-(\d+)-([0-9a-f]{12})$/D';

    public function generate(PaymentInterface $payment, PaymentRequestInterface $paymentRequest): string
    {
        $paymentId = $payment->getId();
        $hash = $paymentRequest->getId();
        if (!is_scalar($paymentId) || '' === (string) $paymentId || null === $hash) {
            throw new InvalidArgumentException('Payment and payment request must be persisted before creating a Tpay transaction.');
        }

        $order = $payment->getOrder();
        $number = $order instanceof OrderInterface ? (string) $order->getNumber() : '';
        $number = preg_replace('/[^A-Za-z0-9_.]/', '', $number) ?? '';
        if ('' === $number) {
            $number = 'order';
        }

        return sprintf('%s-%d-%s', mb_substr($number, 0, 40), (int) $paymentId, substr(str_replace('-', '', strtolower($hash)), 0, 12));
    }

    public function extractPaymentId(string $extOrderId): ?int
    {
        if (1 !== preg_match(self::PATTERN, $extOrderId, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }
}
