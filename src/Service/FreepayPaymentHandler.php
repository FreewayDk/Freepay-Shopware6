<?php declare(strict_types=1);

namespace Freepay\Shopware\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class FreepayPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private FreepayApiClient $apiClient;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        FreepayApiClient $apiClient,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->apiClient = $apiClient;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * Initiates the payment process by creating a Freepay payment session
     * and redirecting the customer to the external payment window
     */
    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        try {
            $order = $transaction->getOrder();
            $orderTransaction = $transaction->getOrderTransaction();

            // Prepare payment data
            $paymentData = [
                'amount' => (int) round($orderTransaction->getAmount()->getTotalPrice() * 100), // Convert to cents
                'currency' => $salesChannelContext->getCurrency()->getIsoCode(),
                'order_id' => $order->getOrderNumber(),
                'transaction_id' => $orderTransaction->getId(),
                'return_url' => $transaction->getReturnUrl(),
                'customer' => $this->prepareCustomerData($order, $salesChannelContext),
            ];

            // Create payment session with Freepay
            $paymentSession = $this->apiClient->createPaymentSession($paymentData, $salesChannelContext->getSalesChannelId());

            if (!$paymentSession || !isset($paymentSession['payment_url'])) {
                throw new AsyncPaymentProcessException(
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
                'transaction_id' => $transaction->getOrderTransaction()->getId(),
            ]);

            throw new AsyncPaymentProcessException(
                $transaction->getOrderTransaction()->getId(),
                'An error occurred during payment initiation. Please try again or contact support.'
            );
        }
    }

    /**
     * Finalizes the payment after customer returns from Freepay payment window
     * Validates the payment status and updates the order transaction accordingly
     */
    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $transactionId = $transaction->getOrderTransaction()->getId();
        $context = $salesChannelContext->getContext();

        try {
            // Check for cancellation
            if ($request->query->get('status') === 'cancelled') {
                throw new CustomerCanceledAsyncPaymentException(
                    $transactionId,
                    'Payment was cancelled by the customer'
                );
            }

            // Get payment status from query parameters
            $freepayTransactionId = $request->query->get('transaction_id');
            $status = $request->query->get('status');

            if (!$freepayTransactionId) {
                throw new AsyncPaymentProcessException(
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
                $salesChannelContext->getSalesChannelId()
            );

            if (!$paymentStatus) {
                throw new AsyncPaymentProcessException(
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
                        'FreepayPayment.config.autoCapture',
                        $salesChannelContext->getSalesChannelId()
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
                    throw new AsyncPaymentProcessException(
                        $transactionId,
                        'Payment failed. Please try again or use a different payment method.'
                    );

                case 'cancelled':
                    throw new CustomerCanceledAsyncPaymentException(
                        $transactionId,
                        'Payment was cancelled'
                    );

                default:
                    throw new AsyncPaymentProcessException(
                        $transactionId,
                        sprintf('Unknown payment status: %s', $paymentStatus['status'] ?? 'unknown')
                    );
            }

        } catch (CustomerCanceledAsyncPaymentException $e) {
            $this->logger->info('Payment cancelled by customer', [
                'transaction_id' => $transactionId,
            ]);
            throw $e;

        } catch (AsyncPaymentProcessException $e) {
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

            throw new AsyncPaymentProcessException(
                $transactionId,
                'An unexpected error occurred. Please contact support.'
            );
        }
    }

    /**
     * Prepares customer data for Freepay payment request
     */
    private function prepareCustomerData($order, SalesChannelContext $context): array
    {
        $sendCustomerData = $this->systemConfigService->getBool(
            'FreepayPayment.config.sendCustomerData',
            $context->getSalesChannelId()
        );

        if (!$sendCustomerData) {
            return [];
        }

        $customer = $order->getOrderCustomer();
        $billingAddress = $order->getBillingAddress();

        return [
            'email' => $customer->getEmail(),
            'first_name' => $customer->getFirstName(),
            'last_name' => $customer->getLastName(),
            'phone' => $billingAddress->getPhoneNumber(),
            'address' => [
                'street' => $billingAddress->getStreet(),
                'city' => $billingAddress->getCity(),
                'postal_code' => $billingAddress->getZipcode(),
                'country' => $billingAddress->getCountry() ? $billingAddress->getCountry()->getIso() : null,
            ],
        ];
    }
}
