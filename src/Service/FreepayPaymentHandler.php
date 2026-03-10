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
    private string $shopwareVersion;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        FreepayApiClient $apiClient,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        EntityRepository $refundRepository,
        string $shopwareVersion
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->apiClient = $apiClient;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->refundRepository = $refundRepository;
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

            $pluginRepository = $this->container->get('plugin.repository');
           
            $plugin = $pluginRepository->search(
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

            if (!$paymentSession || !isset($paymentSession['payment_url'])) {
                throw PaymentException::asyncProcessInterrupted(
                    $orderTransaction->getId(),
                    'Failed to create payment session with Freepay'
                );
            }

            // Store Freepay transaction ID in custom fields for later reference
            $customFields = $orderTransaction->getCustomFields() ?? [];
            $customFields['freepay_transaction_id'] = $paymentSession['transaction_id'] ?? null;
            $customFields['freepay_session_created_at'] = date('Y-m-d H:i:s');

            $this->logger->info('Freepay payment session created', [
                'order_number' => $order->getOrderNumber(),
                'transaction_id' => $orderTransaction->getId(),
                'freepay_transaction_id' => $paymentSession['transaction_id'] ?? null,
            ]);

            // Redirect customer to Freepay payment window
            return new RedirectResponse($paymentSession['payment_url']);

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
            $freepayTransactionId = $request->query->get('transaction_id');

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

            // Verify payment status with Freepay API
            $paymentStatus = $this->apiClient->getPaymentStatus(
                $freepayTransactionId,
                $salesChannelId
            );

            if (!$paymentStatus) {
                throw PaymentException::asyncProcessInterrupted(
                    $transactionId,
                    'Could not verify payment status with Freepay'
                );
            }

            // Handle different payment states
            switch ($paymentStatus['status'] ?? '') {
                case 'paid':
                case 'completed':
                case 'authorized':
                    // Payment successful - transition to paid or authorized state
                    $autoCapture = $this->systemConfigService->getBool(
                        'FreepayPaymentShopware6.config.autoCapture',
                        $salesChannelId
                    );

                    if ($autoCapture || $paymentStatus['status'] === 'paid') {
                        $this->transactionStateHandler->paid($transactionId, $context);
                        $this->logger->info('Payment paid', ['transaction_id' => $transactionId]);
                    } else {
                        $this->transactionStateHandler->authorize($transactionId, $context);
                        $this->logger->info('Payment authorized', ['transaction_id' => $transactionId]);
                    }
                    break;

                case 'pending':
                    $this->transactionStateHandler->process($transactionId, $context);
                    $this->logger->info('Payment pending', ['transaction_id' => $transactionId]);
                    break;

                case 'failed':
                    $this->transactionStateHandler->fail($transactionId, $context);
                    throw PaymentException::asyncProcessInterrupted(
                        $transactionId,
                        'Payment failed. Please try again or use a different payment method.'
                    );

                case 'cancelled':
                    throw PaymentException::customerCanceled(
                        $transactionId,
                        'Payment was cancelled'
                    );

                default:
                    throw PaymentException::asyncProcessInterrupted(
                        $transactionId,
                        sprintf('Unknown payment status: %s', $paymentStatus['status'] ?? 'unknown')
                    );
            }

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
        $sendCustomerData = $this->systemConfigService->getBool(
            'FreepayPaymentShopware6.config.sendCustomerData',
            $salesChannelId
        );

        if (!$sendCustomerData) {
            return [];
        }

        $customer = $order->getOrderCustomer();
        $billingAddress = $order->getBillingAddress();

        return [
            'Email' => $customer->getEmail(),
            'CellPhone' => $billingAddress->getPhoneNumber(),
            'AddressLine1' => $billingAddress->getStreet(),
            'City' => $billingAddress->getCity(),
            'PostCode' => $billingAddress->getZipcode(),
            'Country' => $billingAddress->getCountry() ? $billingAddress->getCountry()->getIso() : null,
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
