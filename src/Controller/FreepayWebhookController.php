<?php declare(strict_types=1);

namespace Freepay\Shopware\Controller;

use Freepay\Shopware\Service\FreepayApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class FreepayWebhookController extends AbstractController
{
    public function __construct(
        private readonly FreepayApiClient $apiClient,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * Freepay ServerCallbackUrl. Sent as application/x-www-form-urlencoded with:
     *   - authorizationIdentifier: guid used for capture/refund/void
     *   - savedCardIdentifier:     guid (zero-guid unless a subscription is created) — unused
     *   - paymentIdentifier:       guid of the payment link (stored on the order in pay())
     *   - authorizationAccepted:   bool, whether the payment succeeded
     *
     * This is the server-to-server authorization result — the safety net for when the
     * customer never returns to the shop and finalize() never runs.
     */
    #[Route(
        path: '/freepay/webhook',
        name: 'payment.freepay.webhook',
        defaults: ['_routeScope' => ['storefront'], 'auth_required' => false],
        methods: ['POST']
    )]
    public function webhook(Request $request): JsonResponse
    {
        $context = Context::createDefaultContext();

        try {
            $authorizationIdentifier = $this->param($request, 'authorizationIdentifier');
            $paymentIdentifier = $this->param($request, 'paymentIdentifier');
            $accepted = filter_var(
                $this->param($request, 'authorizationAccepted'),
                FILTER_VALIDATE_BOOLEAN
            );

            $this->logger->info('Freepay webhook received', [
                'authorizationIdentifier' => $authorizationIdentifier,
                'paymentIdentifier' => $paymentIdentifier,
                'authorizationAccepted' => $accepted,
            ]);

            if (!$paymentIdentifier && !$authorizationIdentifier) {
                $this->logger->error('Freepay webhook: missing identifiers');
                return new JsonResponse(['error' => 'Missing required data'], Response::HTTP_BAD_REQUEST);
            }

            $order = $this->findOrder($paymentIdentifier, $authorizationIdentifier, $context);
            if (!$order instanceof OrderEntity) {
                $this->logger->warning('Freepay webhook: order not found', [
                    'paymentIdentifier' => $paymentIdentifier,
                    'authorizationIdentifier' => $authorizationIdentifier,
                ]);
                // Ack with 200 so Freepay does not retry a callback we can never match.
                return new JsonResponse(['status' => 'order_not_found'], Response::HTTP_OK);
            }

            $transaction = $order->getTransactions()?->last();
            if (!$transaction instanceof OrderTransactionEntity) {
                return new JsonResponse(['status' => 'transaction_not_found'], Response::HTTP_OK);
            }

            $dedupeStatus = $accepted ? 'accepted' : 'declined';
            if ($this->wasWebhookProcessed($transaction, $authorizationIdentifier, $dedupeStatus)) {
                return new JsonResponse(['status' => 'already_processed'], Response::HTTP_OK);
            }

            if ($accepted) {
                // Verify against the Freepay API (authenticated, server-to-server) before
                // authorizing, rather than trusting the callback body.
                $payment = $authorizationIdentifier
                    ? $this->apiClient->getPayment($authorizationIdentifier, $order->getSalesChannelId())
                    : null;

                $verified = $payment !== null
                    && ($payment['OrderID'] ?? null) === $order->getOrderNumber();

                if (!$verified) {
                    $this->logger->warning('Freepay webhook: could not verify authorization, skipping', [
                        'order_number' => $order->getOrderNumber(),
                        'authorizationIdentifier' => $authorizationIdentifier,
                    ]);
                    return new JsonResponse(['status' => 'unverified'], Response::HTTP_OK);
                }

                // Persist the authorization id so capture / cancel / refund work even when
                // the customer never returned and finalize() never stored it.
                $this->orderRepository->update([
                    [
                        'id' => $order->getId(),
                        'customFields' => ['freepay_authorization_identifier' => $authorizationIdentifier],
                    ],
                ], $context);

                $this->authorize($transaction, $context);
            } else {
                $this->fail($transaction, $context);
            }

            $this->markWebhookProcessed($transaction->getId(), $authorizationIdentifier, $dedupeStatus, $context);

            return new JsonResponse(['status' => 'success'], Response::HTTP_OK);

        } catch (\Throwable $e) {
            $this->logger->error('Freepay webhook: processing failed', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function param(Request $request, string $key): ?string
    {
        $value = $request->request->get($key) ?? $request->query->get($key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function findOrder(?string $paymentIdentifier, ?string $authorizationIdentifier, Context $context): ?OrderEntity
    {
        $candidates = [];
        if ($paymentIdentifier) {
            $candidates['freepay_payment_identifier'] = $paymentIdentifier;
        }
        if ($authorizationIdentifier) {
            $candidates['freepay_authorization_identifier'] = $authorizationIdentifier;
        }

        foreach ($candidates as $field => $value) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('customFields.' . $field, $value));
            $criteria->addAssociation('transactions.stateMachineState');
            $criteria->addAssociation('currency');

            $order = $this->orderRepository->search($criteria, $context)->first();
            if ($order instanceof OrderEntity) {
                return $order;
            }
        }

        return null;
    }

    private function authorize(OrderTransactionEntity $transaction, Context $context): void
    {
        $current = $transaction->getStateMachineState()?->getTechnicalName();

        // Don't downgrade an already authorized/paid transaction.
        if (in_array($current, [OrderTransactionStates::STATE_AUTHORIZED, OrderTransactionStates::STATE_PAID], true)) {
            return;
        }

        try {
            $this->transactionStateHandler->authorize($transaction->getId(), $context);
            $this->logger->info('Freepay webhook: transaction authorized', ['transaction_id' => $transaction->getId()]);
        } catch (\Throwable $e) {
            $this->logger->info('Freepay webhook: authorize transition skipped', [
                'transaction_id' => $transaction->getId(),
                'from' => $current,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fail(OrderTransactionEntity $transaction, Context $context): void
    {
        $current = $transaction->getStateMachineState()?->getTechnicalName();

        if ($current === OrderTransactionStates::STATE_FAILED) {
            return;
        }

        try {
            $this->transactionStateHandler->fail($transaction->getId(), $context);
            $this->logger->info('Freepay webhook: transaction failed (declined)', ['transaction_id' => $transaction->getId()]);
        } catch (\Throwable $e) {
            $this->logger->info('Freepay webhook: fail transition skipped', [
                'transaction_id' => $transaction->getId(),
                'from' => $current,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function wasWebhookProcessed(OrderTransactionEntity $transaction, ?string $authorizationIdentifier, string $status): bool
    {
        $customFields = $transaction->getCustomFields() ?? [];

        return ($customFields['freepay_last_webhook_id'] ?? null) === $authorizationIdentifier
            && ($customFields['freepay_last_webhook_status'] ?? null) === $status;
    }

    private function markWebhookProcessed(string $transactionId, ?string $authorizationIdentifier, string $status, Context $context): void
    {
        $this->orderTransactionRepository->update([
            [
                'id' => $transactionId,
                'customFields' => [
                    'freepay_last_webhook_id' => $authorizationIdentifier,
                    'freepay_last_webhook_status' => $status,
                ],
            ],
        ], $context);
    }
}
