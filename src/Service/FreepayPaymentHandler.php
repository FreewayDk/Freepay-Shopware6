<?php declare(strict_types=1);

namespace Freepay\Shopware\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransactionCaptureRefund\OrderTransactionCaptureRefundEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\RefundPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class FreepayPaymentHandler extends AbstractPaymentHandler
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private FreepayApiClient $apiClient;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;
    private EntityRepository $orderRepository;
    private EntityRepository $orderTransactionRepository;
    private EntityRepository $refundRepository;
    private EntityRepository $pluginRepository;
    private string $shopwareVersion;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        FreepayApiClient $apiClient,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        EntityRepository $refundRepository,
        EntityRepository $pluginRepository,
        string $shopwareVersion
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->apiClient = $apiClient;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->refundRepository = $refundRepository;
        $this->pluginRepository = $pluginRepository;
        $this->shopwareVersion = $shopwareVersion;
    }

    public function supports(
        PaymentHandlerType $type,
        string $paymentMethodId,
        Context $context
    ): bool {
        // Return true only for refund support
        return $type === PaymentHandlerType::REFUND;
    }

    /**
     * Initiates the payment process by creating a Freepay payment session
     * and redirecting the customer to the external payment window
     */
    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        try {
            $orderTransaction = $this->loadOrderTransaction($transaction->getOrderTransactionId(), $context);
            $order = $this->loadOrder($orderTransaction->getOrderId(), $context);
            $salesChannelId = $order->getSalesChannelId();
            $currencyCode = $order->getCurrency()?->getIsoCode();

            $plugin = $this->pluginRepository->search(
                (new Criteria())->addFilter(new EqualsFilter('name', 'freepay-payment-shopware6')),
                Context::createDefaultContext()
            )->first();

            if ($plugin) {
                $version = $plugin->getVersion();
            }

            // Prepare payment data
            $paymentData = [
                'OrderNumber' => $order->getOrderNumber(),
                'CustomerAcceptUrl' => $transaction->getReturnUrl(),
                'CustomerDeclineUrl' => $transaction->getReturnUrl(),
                'ServerCallbackUrl' => $_ENV['APP_URL'] . '/freepay/webhook',
                'Amount' => $this->convertAmountToCurrencySubunits($orderTransaction->getAmount()->getTotalPrice(), $currencyCode),
                'SaveCard' => false,
                'Client' => array(
                    'CMS'				=> array(
                        'Name'			=> "Shopware",
                        'Version'		=> $this->shopwareVersion,
                    ),
                    'Shop'				=> array(
                        'Name'			=> "Shopware",
                        'Version'		=> $this->shopwareVersion
                    ),
                    'Plugin'			=> array(
                        'Name'			=> "Freepay",
                        'Version'		=> $version ?? 'Unknown'
                    ),
                    'API'   			=> array(
                        'Name'			=> "Freepay",
                        'Version'		=> '2.0'
                    ),
                ),
                'Currency' => $currencyCode,
                'BillingAddress' => $this->prepareCustomerData($order, $salesChannelId),
                'ShippingAddress' => $this->prepareCustomerData($order, $salesChannelId),
                'Options' => [
                    'TestMode' => $this->systemConfigService->getBool(
                        'FreepayPaymentShopware6.config.sandboxMode',
                        $salesChannelId
                    ),
                ]
            ];

            // Create payment session with Freepay
            $paymentSession = $this->apiClient->createPaymentSession($paymentData, $salesChannelId);

            if (!$paymentSession || !isset($paymentSession['paymentWindowLink'])) {
                throw PaymentException::asyncProcessInterrupted(
                    $orderTransaction->getId(),
                    'Failed to create payment session with Freepay'
                );
            }

            $customFields = $orderTransaction->getCustomFields() ?? [];
            $customFields['freepay_payment_identifier'] = $paymentSession['paymentIdentifier'] ?? null;
            $this->orderTransactionRepository->update([
                ['id' => $orderTransaction->getId(), 'customFields' => $customFields],
            ], $context);

            // Redirect customer to Freepay payment window
            return new RedirectResponse($paymentSession['paymentWindowLink']);

        } catch (\Exception $e) {
            $this->logger->error('Freepay payment initiation failed', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction->getOrderTransactionId(),
            ]);

            throw PaymentException::asyncProcessInterrupted(
                $transaction->getOrderTransactionId(),
                'An error occurred during payment initiation. Please try again or contact support.'
            );
        }
    }

    /**
     * Finalizes the payment after customer returns from Freepay payment window
     * Validates the payment status and updates the order transaction accordingly
     */
    public function finalize(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $orderTransaction = $this->loadOrderTransaction($transaction->getOrderTransactionId(), $context);
        $transactionId = $orderTransaction->getId();
        $order = $this->loadOrder($orderTransaction->getOrderId(), $context);
        $salesChannelId = $order->getSalesChannelId();

        try {
            $status = $request->query->get('status');

            // Check for cancellation
            if ($status === 'cancelled') {
                throw PaymentException::customerCanceled(
                    $transactionId,
                    'Payment was cancelled by the customer'
                );
            }

            // Get payment status from query parameters
            $freepayTransactionId = $request->query->get('authorizationIdentifier');

            if (!$freepayTransactionId) {
                throw PaymentException::asyncProcessInterrupted(
                    $transactionId,
                    'Missing transaction ID from Freepay response'
                );
            }

            $this->logger->info('Freepay payment finalization', [
                'transaction_id' => $transactionId,
                'freepay_transaction_id' => $freepayTransactionId,
                'status' => $status,
            ]);

            // Verify payment with Freepay API
            $payment = $this->apiClient->getPayment(
                $freepayTransactionId,
                $salesChannelId
            );

            if (!$payment) {
                throw PaymentException::asyncProcessInterrupted(
                    $transactionId,
                    'Could not retrieve payment from Freepay'
                );
            }

            $currencyCode = $order->getCurrency()?->getIsoCode();
            $expectedAmount = $this->convertAmountToCurrencySubunits(
                $orderTransaction->getAmount()->getTotalPrice(),
                $currencyCode
            );

            if (($payment['OrderID'] ?? null) !== $order->getOrderNumber()
                || ($payment['AuthorizationAmount'] ?? null) !== $expectedAmount) {
                throw PaymentException::asyncProcessInterrupted(
                    $transactionId,
                    'Payment verification failed: order ID or amount mismatch'
                );
            }

            $customFields = $orderTransaction->getCustomFields() ?? [];
            $customFields['freepay_authorization_identifier'] = $payment['authorizationIdentifier'] ?? null;
            $this->orderTransactionRepository->update([
                ['id' => $transactionId, 'customFields' => $customFields],
            ], $context);

            $this->transactionStateHandler->authorize($transactionId, $context);
            $this->logger->info('Payment authorized', ['transaction_id' => $transactionId]);

        } catch (PaymentException $e) {
            $this->logger->error('Payment finalization failed', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;

        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during payment finalization', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            throw PaymentException::asyncProcessInterrupted(
                $transactionId,
                'An unexpected error occurred. Please contact support.'
            );
        }
    }

    /**
     * Handles refund requests from Shopware
     * Converts the refund amount using currency-aware conversion
     */
    public function refund(
        RefundPaymentTransactionStruct $transaction,
        Context $context
    ): void {
        try {
            // Load the refund entity to get the amount
            $refund = $this->loadRefund($transaction->getRefundId(), $context);
            $orderTransaction = $this->loadOrderTransaction($transaction->getOrderTransactionId(), $context);
            $order = $this->loadOrder($orderTransaction->getOrderId(), $context);
            
            $currencyCode = $order->getCurrency()?->getIsoCode();
            $refundAmountInMinorUnits = $this->convertAmountToCurrencySubunits(
                $refund->getAmount()->getTotalPrice(),
                $currencyCode
            );

            // Get the Freepay transaction ID from custom fields
            $customFields = $orderTransaction->getCustomFields() ?? [];
            $freepayTransactionId = $customFields['freepay_transaction_id'] ?? null;

            if (!$freepayTransactionId) {
                throw PaymentException::asyncProcessInterrupted(
                    $orderTransaction->getId(),
                    'Freepay transaction ID not found in order transaction'
                );
            }

            // Process refund via Freepay API
            $result = $this->apiClient->refundPayment(
                $freepayTransactionId,
                $refundAmountInMinorUnits,
                $order->getSalesChannelId()
            );

            if (!$result) {
                throw PaymentException::asyncProcessInterrupted(
                    $orderTransaction->getId(),
                    'Failed to process refund with Freepay'
                );
            }

            $this->logger->info('Freepay refund processed', [
                'refund_id' => $refund->getId(),
                'transaction_id' => $orderTransaction->getId(),
                'amount' => $refundAmountInMinorUnits,
                'currency' => $currencyCode,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Freepay refund failed', [
                'refund_id' => $transaction->getRefundId(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Prepares customer data for Freepay payment request
     */
    private function prepareCustomerData($order, string $salesChannelId): array
    {
        $customer = $order->getOrderCustomer();
        $billingAddress = $order->getBillingAddress();

        $countryCode = null;
        if ($billingAddress->getCountry()) {
            $countryCode = $this->getNumericCountryCode($billingAddress->getCountry()->getIso());
        }

        return [
            'Email' => $customer->getEmail(),
            'CellPhone' => $billingAddress->getPhoneNumber(),
            'AddressLine1' => $billingAddress->getStreet(),
            'City' => $billingAddress->getCity(),
            'PostCode' => $billingAddress->getZipcode(),
            'Country' => $countryCode,
        ];
    }

    /**
     * Load order entity from repository
     */
    private function loadOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('currency');
        $criteria->addAssociation('orderCustomer');
        $criteria->addAssociation('billingAddress.country');

        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order instanceof OrderEntity) {
            throw PaymentException::asyncProcessInterrupted(
                $orderId,
                'Order not found'
            );
        }

        return $order;
    }

    /**
     * Load order transaction entity from repository
     */
    private function loadOrderTransaction(string $orderTransactionId, Context $context): OrderTransactionEntity
    {
        $criteria = new Criteria([$orderTransactionId]);
        $criteria->addAssociation('order');

        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$transaction instanceof OrderTransactionEntity) {
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Order transaction not found'
            );
        }

        return $transaction;
    }

    /**
     * Load refund entity from repository
     */
    private function loadRefund(string $refundId, Context $context): OrderTransactionCaptureRefundEntity
    {
        $criteria = new Criteria([$refundId]);
        $criteria->addAssociation('transactionCapture.transaction.order.currency');

        $refund = $this->refundRepository->search($criteria, $context)->first();

        if (!$refund instanceof OrderTransactionCaptureRefundEntity) {
            throw PaymentException::asyncProcessInterrupted(
                $refundId,
                'Refund not found'
            );
        }

        return $refund;
    }

    /**
     * Convert amount to currency subunits (e.g., cents for USD, no conversion for JPY)
     * Handles currencies with different decimal places
     */
    private function convertAmountToCurrencySubunits(float $amount, ?string $currencyCode): int
    {
        if (!$currencyCode) {
            return (int) round($amount * 100); // Default to 2 decimals
        }

        $multiplier = $this->getCurrencyMultiplier($currencyCode);
        return (int) round($amount * $multiplier);
    }

    /**
     * Convert ISO 3166-1 alpha-2 country code to numeric country code
     */
    private function getNumericCountryCode(string $isoCode): ?string
    {
        // ISO 3166-1 numeric country codes mapping
        $countryMap = [
            'AF' => '004', 'AX' => '248', 'AL' => '008', 'DZ' => '012', 'AS' => '016',
            'AD' => '020', 'AO' => '024', 'AI' => '660', 'AQ' => '010', 'AG' => '028',
            'AR' => '032', 'AM' => '051', 'AW' => '533', 'AU' => '036', 'AT' => '040',
            'AZ' => '031', 'BS' => '044', 'BH' => '048', 'BD' => '050', 'BB' => '052',
            'BY' => '112', 'BE' => '056', 'BZ' => '084', 'BJ' => '204', 'BM' => '060',
            'BT' => '064', 'BO' => '068', 'BQ' => '535', 'BA' => '070', 'BW' => '072',
            'BV' => '074', 'BR' => '076', 'IO' => '086', 'BN' => '096', 'BG' => '100',
            'BF' => '854', 'BI' => '108', 'KH' => '116', 'CM' => '120', 'CA' => '124',
            'CV' => '132', 'KY' => '136', 'CF' => '140', 'TD' => '148', 'CL' => '152',
            'CN' => '156', 'CX' => '162', 'CC' => '166', 'CO' => '170', 'KM' => '174',
            'CG' => '178', 'CD' => '180', 'CK' => '184', 'CR' => '188', 'CI' => '384',
            'HR' => '191', 'CU' => '192', 'CW' => '531', 'CY' => '196', 'CZ' => '203',
            'DK' => '208', 'DJ' => '262', 'DM' => '212', 'DO' => '214', 'EC' => '218',
            'EG' => '818', 'SV' => '222', 'GQ' => '226', 'ER' => '232', 'EE' => '233',
            'ET' => '231', 'FK' => '238', 'FO' => '234', 'FJ' => '242', 'FI' => '246',
            'FR' => '250', 'GF' => '254', 'PF' => '258', 'TF' => '260', 'GA' => '266',
            'GM' => '270', 'GE' => '268', 'DE' => '276', 'GH' => '288', 'GI' => '292',
            'GR' => '300', 'GL' => '304', 'GD' => '308', 'GP' => '312', 'GU' => '316',
            'GT' => '320', 'GG' => '831', 'GN' => '324', 'GW' => '624', 'GY' => '328',
            'HT' => '332', 'HM' => '334', 'VA' => '336', 'HN' => '340', 'HK' => '344',
            'HU' => '348', 'IS' => '352', 'IN' => '356', 'ID' => '360', 'IR' => '364',
            'IQ' => '368', 'IE' => '372', 'IM' => '833', 'IL' => '376', 'IT' => '380',
            'JM' => '388', 'JP' => '392', 'JE' => '832', 'JO' => '400', 'KZ' => '398',
            'KE' => '404', 'KI' => '296', 'KP' => '408', 'KR' => '410', 'KW' => '414',
            'KG' => '417', 'LA' => '418', 'LV' => '428', 'LB' => '422', 'LS' => '426',
            'LR' => '430', 'LY' => '434', 'LI' => '438', 'LT' => '440', 'LU' => '442',
            'MO' => '446', 'MK' => '807', 'MG' => '450', 'MW' => '454', 'MY' => '458',
            'MV' => '462', 'ML' => '466', 'MT' => '470', 'MH' => '584', 'MQ' => '474',
            'MR' => '478', 'MU' => '480', 'YT' => '175', 'MX' => '484', 'FM' => '583',
            'MD' => '498', 'MC' => '492', 'MN' => '496', 'ME' => '499', 'MS' => '500',
            'MA' => '504', 'MZ' => '508', 'MM' => '104', 'NA' => '516', 'NR' => '520',
            'NP' => '524', 'NL' => '528', 'NC' => '540', 'NZ' => '554', 'NI' => '558',
            'NE' => '562', 'NG' => '566', 'NU' => '570', 'NF' => '574', 'MP' => '580',
            'NO' => '578', 'OM' => '512', 'PK' => '586', 'PW' => '585', 'PS' => '275',
            'PA' => '591', 'PG' => '598', 'PY' => '600', 'PE' => '604', 'PH' => '608',
            'PN' => '612', 'PL' => '616', 'PT' => '620', 'PR' => '630', 'QA' => '634',
            'RE' => '638', 'RO' => '642', 'RU' => '643', 'RW' => '646', 'BL' => '652',
            'SH' => '654', 'KN' => '659', 'LC' => '662', 'MF' => '663', 'PM' => '666',
            'VC' => '670', 'WS' => '882', 'SM' => '674', 'ST' => '678', 'SA' => '682',
            'SN' => '686', 'RS' => '688', 'SC' => '690', 'SL' => '694', 'SG' => '702',
            'SX' => '534', 'SK' => '703', 'SI' => '705', 'SB' => '090', 'SO' => '706',
            'ZA' => '710', 'GS' => '239', 'SS' => '728', 'ES' => '724', 'LK' => '144',
            'SD' => '729', 'SR' => '740', 'SJ' => '744', 'SZ' => '748', 'SE' => '752',
            'CH' => '756', 'SY' => '760', 'TW' => '158', 'TJ' => '762', 'TZ' => '834',
            'TH' => '764', 'TL' => '626', 'TG' => '768', 'TK' => '772', 'TO' => '776',
            'TT' => '780', 'TN' => '788', 'TR' => '792', 'TM' => '795', 'TC' => '796',
            'TV' => '798', 'UG' => '800', 'UA' => '804', 'AE' => '784', 'GB' => '826',
            'US' => '840', 'UM' => '581', 'UY' => '858', 'UZ' => '860', 'VU' => '548',
            'VE' => '862', 'VN' => '704', 'VG' => '092', 'VI' => '850', 'WF' => '876',
            'EH' => '732', 'YE' => '887', 'ZM' => '894', 'ZW' => '716',
        ];

        return $countryMap[strtoupper($isoCode)] ?? null;
    }

    /**
     * Get the multiplier for converting currency amount to its smallest unit
     * Based on ISO 4217 currency decimal places
     */
    private function getCurrencyMultiplier(string $currencyCode): int
    {
        // Currencies with 0 decimal places (no cents/subunits)
        $zeroDecimalCurrencies = [
            'BIF', // Burundian Franc
            'CLP', // Chilean Peso
            'DJF', // Djiboutian Franc
            'GNF', // Guinean Franc
            'ISK', // Icelandic Króna
            'JPY', // Japanese Yen
            'KMF', // Comorian Franc
            'KRW', // South Korean Won
            'PYG', // Paraguayan Guaraní
            'RWF', // Rwandan Franc
            'UGX', // Ugandan Shilling
            'VND', // Vietnamese Đồng
            'VUV', // Vanuatu Vatu
            'XAF', // Central African CFA Franc
            'XOF', // West African CFA Franc
            'XPF', // CFP Franc
        ];

        // Currencies with 3 decimal places
        $threeDecimalCurrencies = [
            'BHD', // Bahraini Dinar
            'IQD', // Iraqi Dinar
            'JOD', // Jordanian Dinar
            'KWD', // Kuwaiti Dinar
            'LYD', // Libyan Dinar
            'OMR', // Omani Rial
            'TND', // Tunisian Dinar
        ];

        if (in_array($currencyCode, $zeroDecimalCurrencies, true)) {
            return 1; // No multiplication needed
        }

        if (in_array($currencyCode, $threeDecimalCurrencies, true)) {
            return 1000; // Multiply by 1000 for 3 decimal places
        }

        // Default: 2 decimal places (most common)
        return 100;
    }
}
