<?php declare(strict_types=1);

namespace Freepay\Shopware\Subscriber;

use Freepay\Shopware\Service\FreepayApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\Event\StateMachineTransitionEvent;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderStateSubscriber implements EventSubscriberInterface
{
    private bool $processingAutoCapture = false;

    public function __construct(
        private readonly FreepayApiClient $apiClient,
        private readonly SystemConfigService $systemConfigService,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly EntityRepository $captureRepository,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            StateMachineTransitionEvent::class => 'onStateTransition',
        ];
    }

    public function onStateTransition(StateMachineTransitionEvent $event): void
    {
        $entity   = $event->getEntityName();
        $toState  = $event->getToPlace()->getTechnicalName();
        $fromState = $event->getFromPlace()->getTechnicalName();
        $context  = $event->getContext();

        $this->logger->info('Freepay state transition', [
            'entity' => $entity,
            'from' => $fromState,
            'to' => $toState,
        ]);

        if ($entity === 'order_transaction') {
            // Capturing the remainder works from "authorized" (capture everything) and
            // from "paid_partially" (capture what's left). handleCapture is idempotent:
            // it only sends the still-uncaptured amount to Freepay.
            if ($toState === 'paid' && in_array($fromState, ['authorized', 'paid_partially'], true)) {
                $this->handleCapture($event->getEntityId(), $context);
            } elseif ($toState === 'cancelled' && $fromState === 'authorized') {
                $this->handleCancel($event->getEntityId(), $context);
            }
        }

        if ($entity === 'order_delivery' && in_array($toState, ['shipped', 'shipped_partially'], true)) {
            $this->handleAutoCapture($event->getEntityId(), $context, $toState === 'shipped');
        }
    }

    private function handleCapture(string $transactionId, Context $context): void
    {
        if ($this->processingAutoCapture) {
            return;
        }

        [$order, $transaction] = $this->loadOrderForTransaction($transactionId, $context);
        if (!$order || !$transaction) {
            return;
        }

        $authorizationId = $order->getCustomFields()['freepay_authorization_identifier'] ?? null;
        if (!$authorizationId) {
            return;
        }

        // Only capture what hasn't been captured yet. The merchant may already have
        // captured part (or all) of the authorization through the Freepay capture
        // card; without this guard, flipping the order to "Paid" would capture again.
        $transactionTotal = $transaction->getAmount()->getTotalPrice();
        $capturedTotal = $this->getCapturedTotal($transaction->getId(), $context);
        $remaining = $transactionTotal - $capturedTotal;

        if ($remaining <= 0.0001) {
            $this->logger->info('Freepay capture skipped: authorization already fully captured', [
                'transaction_id' => $transactionId,
                'captured_total' => $capturedTotal,
                'transaction_total' => $transactionTotal,
            ]);
            return;
        }

        $amount = $this->apiClient->convertAmount($remaining, $order->getCurrency()?->getIsoCode());
        $result = $this->apiClient->capturePayment($authorizationId, $amount, $order->getSalesChannelId());

        if (!$result) {
            $this->logger->error('Freepay capture failed', [
                'transaction_id' => $transactionId,
                'authorization_id' => $authorizationId,
            ]);
            return;
        }

        $this->createCaptureRecord($transaction, $this->buildPrice($remaining), $context);
    }

    private function handleCancel(string $transactionId, Context $context): void
    {
        [$order] = $this->loadOrderForTransaction($transactionId, $context);
        if (!$order) {
            return;
        }

        $authorizationId = $order->getCustomFields()['freepay_authorization_identifier'] ?? null;
        if (!$authorizationId) {
            return;
        }

        $result = $this->apiClient->cancelPayment($authorizationId, $order->getSalesChannelId());

        if (!$result) {
            $this->logger->error('Freepay cancel failed', [
                'transaction_id' => $transactionId,
                'authorization_id' => $authorizationId,
            ]);
        }
    }

    private function handleAutoCapture(string $deliveryId, Context $context, bool $fullShipment = true): void
    {
        $delivery = $this->loadOrderDelivery($deliveryId, $context);
        if (!$delivery) {
            return;
        }

        $order = $delivery->getOrder();
        if (!$order) {
            return;
        }

        if (!$this->systemConfigService->getBool('FreepayPaymentShopware6.config.captureOnShipment', $order->getSalesChannelId())) {
            return;
        }

        $transaction = null;
        foreach ($order->getTransactions() ?? [] as $t) {
            if ($t->getStateMachineState()?->getTechnicalName() === 'authorized') {
                $transaction = $t;
                break;
            }
        }

        if (!$transaction) {
            return;
        }

        $authorizationId = $order->getCustomFields()['freepay_authorization_identifier'] ?? null;
        if (!$authorizationId) {
            $this->logger->error('Freepay auto-capture failed: no authorization identifier', [
                'order_id' => $order->getId(),
            ]);
            return;
        }

        $currencyCode = $order->getCurrency()?->getIsoCode();
        $captureTotal = 0.0;
        foreach ($delivery->getPositions() ?? [] as $position) {
            $captureTotal += $position->getPrice()->getTotalPrice();
        }
        $amount = $this->apiClient->convertAmount($captureTotal, $currencyCode);

        $result = $this->apiClient->capturePayment($authorizationId, $amount, $order->getSalesChannelId());

        if ($result) {
            $this->createCaptureRecord($transaction, $this->buildPrice($captureTotal), $context);
            if ($fullShipment) {
                $this->processingAutoCapture = true;
                try {
                    $this->transactionStateHandler->paid($transaction->getId(), $context);
                } finally {
                    $this->processingAutoCapture = false;
                }
            }
            $this->logger->info('Freepay auto-capture successful', [
                'order_id' => $order->getId(),
                'amount' => $amount,
                'partial' => !$fullShipment,
            ]);
        } else {
            $this->logger->error('Freepay auto-capture failed', ['order_id' => $order->getId()]);
        }
    }

    private function createCaptureRecord(OrderTransactionEntity $transaction, CalculatedPrice $amount, Context $context): void
    {
        try {
            // Captures must be written to the live version, otherwise the admin
            // (which shows the live-version order) won't associate them and the
            // refund option never appears.
            if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
                $context = $context->createWithVersionId(Defaults::LIVE_VERSION);
            }

            $stateMachine = $this->stateMachineRegistry->getStateMachine(
                'order_transaction_capture.state',
                $context
            );

            $completedStateId = null;
            foreach ($stateMachine->getStates() ?? [] as $state) {
                if ($state->getTechnicalName() === 'completed') {
                    $completedStateId = $state->getId();
                    break;
                }
            }

            if (!$completedStateId) {
                $this->logger->error('Freepay: could not find completed state for order_transaction_capture.state');
                return;
            }

            $captureId = Uuid::randomHex();
            $this->captureRepository->create([
                [
                    'id' => $captureId,
                    'orderTransactionId' => $transaction->getId(),
                    'amount' => $amount,
                    'stateId' => $completedStateId,
                ]
            ], $context);

            $this->logger->info('Freepay: capture record created', [
                'capture_id' => $captureId,
                'transaction_id' => $transaction->getId(),
                'state_id' => $completedStateId,
                'total' => $amount->getTotalPrice(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Freepay: failed to create capture record', [
                'transaction_id' => $transaction->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sum of capture amounts already recorded for a transaction, read on the live
     * version (captures are always written there — see createCaptureRecord()).
     * Filters the capture entity by its direct orderTransactionId field, so it is
     * safe under Elasticsearch (no deep association path).
     */
    private function getCapturedTotal(string $transactionId, Context $context): float
    {
        if ($context->getVersionId() !== Defaults::LIVE_VERSION) {
            $context = $context->createWithVersionId(Defaults::LIVE_VERSION);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderTransactionId', $transactionId));

        $total = 0.0;
        foreach ($this->captureRepository->search($criteria, $context) as $capture) {
            $total += $capture->getAmount()->getTotalPrice();
        }

        return $total;
    }

    private function buildPrice(float $amount): CalculatedPrice
    {
        return new CalculatedPrice($amount, $amount, new CalculatedTaxCollection(), new TaxRuleCollection());
    }

    private function loadOrderForTransaction(string $transactionId, Context $context): array
    {
        $criteria = new Criteria([$transactionId]);
        $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

        if (!$transaction instanceof OrderTransactionEntity) {
            return [null, null];
        }

        $criteria = new Criteria([$transaction->getOrderId()]);
        $criteria->addAssociation('currency');
        $order = $this->orderRepository->search($criteria, $context)->first();

        return [$order instanceof OrderEntity ? $order : null, $transaction];
    }

    private function loadOrderDelivery(string $deliveryId, Context $context): ?OrderDeliveryEntity
    {
        $criteria = new Criteria([$deliveryId]);
        $criteria->addAssociation('positions');
        $criteria->addAssociation('order.currency');
        $criteria->addAssociation('order.transactions.stateMachineState');

        $delivery = $this->orderDeliveryRepository->search($criteria, $context)->first();
        return $delivery instanceof OrderDeliveryEntity ? $delivery : null;
    }

}
