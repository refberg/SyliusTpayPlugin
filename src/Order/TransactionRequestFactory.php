<?php

declare(strict_types=1);

namespace Refberg\SyliusTpayPlugin\Order;

use InvalidArgumentException;
use Refberg\SyliusTpayPlugin\Api\Json;
use Refberg\SyliusTpayPlugin\Config\TpayCredentials;
use Sylius\Bundle\CoreBundle\OrderPay\Provider\UrlProviderInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentRequestInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function filter_var;
use function in_array;
use function is_array;
use function is_string;
use function mb_substr;
use function parse_url;
use function sprintf;
use function str_contains;
use function strtolower;
use function substr;
use function trim;
use const FILTER_VALIDATE_IP;
use const FILTER_VALIDATE_URL;
use const PHP_URL_SCHEME;

/**
 * Builds the body of POST /transactions from a Sylius payment.
 *
 * Amount and currency always come from the Sylius payment, never from the client.
 * No payment channel is preselected: the customer chooses it on the Tpay payment page.
 */
final readonly class TransactionRequestFactory implements TransactionRequestFactoryInterface
{
    public const PAYLOAD_CONTINUE_URL = 'continue_url';

    /** Query parameter added to the shop return URLs, so the shop knows which Tpay URL the customer used. */
    public const RETURN_PARAMETER = 'tpay_return';

    public const RETURN_SUCCESS = 'success';

    public const RETURN_ERROR = 'error';

    private const NOTIFY_ROUTE = 'sylius_payment_method_notify';

    private const LANGUAGES = ['en', 'pl', 'de', 'fr', 'ru', 'it', 'es', 'uk'];

    private const DESCRIPTION_MAX_LENGTH = 128;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private ?UrlProviderInterface $afterPayUrlProvider,
    ) {
    }

    public function create(
        PaymentRequestInterface $paymentRequest,
        PaymentInterface $payment,
        TpayCredentials $credentials,
        string $extOrderId,
    ): array {
        $order = $payment->getOrder();
        $method = $paymentRequest->getMethod();
        $amount = $payment->getAmount();
        $currency = $payment->getCurrencyCode();

        if (!$order instanceof OrderInterface || null === $method->getCode()) {
            throw new InvalidArgumentException('Payment has no order or payment method.');
        }
        if (null === $amount || $amount <= 0 || null === $currency || '' === $currency) {
            throw new InvalidArgumentException('Payment amount and currency are required.');
        }

        $email = $order->getCustomer()?->getEmail();
        if (null === $email || '' === $email) {
            throw new InvalidArgumentException('Tpay requires the customer e-mail address.');
        }

        [$successUrl, $errorUrl] = $this->returnUrls($paymentRequest);

        $body = [
            'amount' => (float) Json::toMajorUnits($amount),
            'currency' => $currency,
            'description' => mb_substr(sprintf('Order %s', (string) $order->getNumber()), 0, self::DESCRIPTION_MAX_LENGTH),
            'hiddenDescription' => $extOrderId,
            'payer' => $this->payer($order, $email),
            'callbacks' => [
                'payerUrls' => [
                    'success' => $successUrl,
                    'error' => $errorUrl,
                ],
                'notification' => [
                    'url' => $this->urlGenerator->generate(self::NOTIFY_ROUTE, ['code' => $method->getCode()], UrlGeneratorInterface::ABSOLUTE_URL),
                ],
            ],
        ];

        $language = null !== $order->getLocaleCode() ? strtolower(substr($order->getLocaleCode(), 0, 2)) : null;
        if (in_array($language, self::LANGUAGES, true)) {
            $body['lang'] = $language;
        }

        return $body;
    }

    /**
     * A continue URL from the API client is used as it is; the shop after-pay URL gets RETURN_PARAMETER.
     *
     * @return array{string, string} success and error URL
     */
    private function returnUrls(PaymentRequestInterface $paymentRequest): array
    {
        $payload = $paymentRequest->getPayload();
        $requested = is_array($payload) ? ($payload[self::PAYLOAD_CONTINUE_URL] ?? null) : null;

        if (null !== $requested) {
            if (!is_string($requested) || !self::isAbsoluteHttpUrl($requested)) {
                throw new InvalidArgumentException(sprintf('Payload "%s" must be an absolute http(s) URL.', self::PAYLOAD_CONTINUE_URL));
            }

            return [$requested, $requested];
        }

        if (!$this->afterPayUrlProvider instanceof UrlProviderInterface) {
            throw new InvalidArgumentException(sprintf('Payload "%s" is required when the shop is not installed.', self::PAYLOAD_CONTINUE_URL));
        }

        $url = $this->afterPayUrlProvider->getUrl($paymentRequest, UrlGeneratorInterface::ABSOLUTE_URL);
        $separator = str_contains($url, '?') ? '&' : '?';

        return [
            $url . $separator . self::RETURN_PARAMETER . '=' . self::RETURN_SUCCESS,
            $url . $separator . self::RETURN_PARAMETER . '=' . self::RETURN_ERROR,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function payer(OrderInterface $order, string $email): array
    {
        $payer = ['email' => $email];

        $address = $order->getBillingAddress();
        $name = $address instanceof AddressInterface ? trim((string) $address->getFullName()) : '';
        if ('' === $name) {
            $name = trim((string) $order->getCustomer()?->getFullName());
        }
        // The payer name is required by Tpay.
        $payer['name'] = '' !== $name ? $name : $email;

        if ($address instanceof AddressInterface) {
            $phone = trim((string) $address->getPhoneNumber());
            if ('' !== $phone) {
                $payer['phone'] = $phone;
            }
        }

        $ip = $order->getCustomerIp();
        if (null !== $ip && false !== filter_var($ip, FILTER_VALIDATE_IP)) {
            $payer['ip'] = $ip;
        }

        return $payer;
    }

    private static function isAbsoluteHttpUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }
}
