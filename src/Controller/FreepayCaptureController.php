<?php declare(strict_types=1);

namespace Freepay\Shopware\Controller;

use Doctrine\DBAL\Connection;
use Freepay\Shopware\Service\FreepayApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class FreepayCaptureController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $captureRepository,
        private readonly FreepayApiClient $apiClient,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    #[Route(
        path: '/api/_action/freepay/capture/{orderId}',
        name: 'api.action.freepay.capture',
        methods: ['POST']
    )]
    public function capture(string $orderId, Request $request, Context $context): JsonResponse
    {
        $amount = (float) $request->request->get('amount');

        if ($amount <= 0) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Invalid capture amount'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('currency');
        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order instanceof OrderEntity) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Order not found'],
                Response::HTTP_NOT_FOUND
            );
        }

        $transaction = $order->getTransactions()?->last();
        if (!$transaction) {
            return new JsonResponse(
                ['success' => false, 'error' => 'No transaction found for order'],
                Response::HTTP_NOT_FOUND
            );
        }

        $authorizationId = $order->getCustomFields()['freepay_authorization_identifier'] ?? null;
        if (!$authorizationId) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No Freepay authorization found on this order. Payment must be authorized first.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Don't allow capturing more than what is still authorized but uncaptured.
        $transactionTotal = $transaction->getAmount()->getTotalPrice();
        $capturedTotal = $this->getCapturedTotal($transaction->getId());
        $remaining = $transactionTotal - $capturedTotal;

        if ($amount > $remaining + 0.0001) {
            return new JsonResponse([
                'success' => false,
                'error' => sprintf('Capture amount exceeds the remaining capturable amount (%.2f).', $remaining),
            ], Response::HTTP_BAD_REQUEST);
        }

        $currencyIso = $order->getCurrency()?->getIsoCode();
        $minorAmount = $this->apiClient->convertAmount($amount, $currencyIso);

        $result = $this->apiClient->capturePayment($authorizationId, $minorAmount, $order->getSalesChannelId());
        if (!$result) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Freepay capture failed. Check the logs for details.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Record the capture so the refund button (and the captured-total bookkeeping)
        // sees it. Captures must live on the live version — see the note in
        // OrderStateSubscriber::createCaptureRecord().
        $captureContext = $context->getVersionId() !== Defaults::LIVE_VERSION
            ? $context->createWithVersionId(Defaults::LIVE_VERSION)
            : $context;

        $completedStateId = $this->getStateId('order_transaction_capture.state', 'completed', $captureContext);
        if (!$completedStateId) {
            $this->logger->error('Freepay: could not find completed state for order_transaction_capture.state');

            return new JsonResponse([
                'success' => false,
                'error' => 'Capture state machine is not available',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $captureId = Uuid::randomHex();
        $this->captureRepository->create([
            [
                'id' => $captureId,
                'orderTransactionId' => $transaction->getId(),
                'amount' => new CalculatedPrice(
                    $amount,
                    $amount,
                    new CalculatedTaxCollection(),
                    new TaxRuleCollection()
                ),
                'stateId' => $completedStateId,
            ],
        ], $captureContext);

        // Reflect capture progress in the payment status. Best-effort: never fail the
        // capture just because the status transition is awkward (e.g. already paid).
        $newCapturedTotal = $capturedTotal + $amount;
        try {
            if ($newCapturedTotal + 0.0001 >= $transactionTotal) {
                $this->transactionStateHandler->paid($transaction->getId(), $context);
            } else {
                $this->transactionStateHandler->payPartially($transaction->getId(), $context);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Freepay: could not transition payment status after capture', [
                'order_id' => $orderId,
                'current_state' => $transaction->getStateMachineState()?->getTechnicalName(),
                'captured_total' => $newCapturedTotal,
                'transaction_total' => $transactionTotal,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('Freepay: capture initiated', [
            'order_id' => $orderId,
            'capture_id' => $captureId,
            'amount' => $amount,
        ]);

        return new JsonResponse(['success' => true, 'captureId' => $captureId]);
    }

    #[Route(
        path: '/api/_action/freepay/captures/{orderId}',
        name: 'api.action.freepay.captures',
        methods: ['GET']
    )]
    public function captures(string $orderId, Context $context): JsonResponse
    {
        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions.stateMachineState');
        $criteria->addAssociation('currency');
        $order = $this->orderRepository->search($criteria, $context)->first();

        if (!$order instanceof OrderEntity) {
            return new JsonResponse(['success' => false, 'error' => 'Order not found'], Response::HTTP_NOT_FOUND);
        }

        $transaction = $order->getTransactions()?->last();
        $transactionTotal = $transaction ? $transaction->getAmount()->getTotalPrice() : 0.0;
        $capturedTotal = $transaction ? $this->getCapturedTotal($transaction->getId()) : 0.0;

        // Raw SQL on purpose: with Elasticsearch enabled the DAL throws UNMAPPED_FIELD
        // when filtering this entity by the deep association path (transaction.orderId).
        // Must filter version_id = live, otherwise order-edit draft clones return rows
        // multiple times and inflate the captured total.
        $sql = <<<'SQL'
SELECT LOWER(HEX(c.id)) AS id,
       CAST(JSON_EXTRACT(c.amount, '$.totalPrice') AS DECIMAL(20,4)) AS amount,
       c.created_at AS createdAt
FROM order_transaction_capture c
INNER JOIN order_transaction t
    ON t.id = c.order_transaction_id AND t.version_id = c.order_transaction_version_id
WHERE t.order_id = UNHEX(:orderId)
  AND c.version_id = UNHEX(:liveVersion)
ORDER BY c.created_at DESC
SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'orderId' => $orderId,
            'liveVersion' => Defaults::LIVE_VERSION,
        ]);

        $captures = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'amount' => (float) $row['amount'],
            'createdAt' => $row['createdAt'],
        ], $rows);

        return new JsonResponse([
            'captures' => $captures,
            'capturedTotal' => $capturedTotal,
            'transactionTotal' => $transactionTotal,
            'remaining' => max(0.0, $transactionTotal - $capturedTotal),
            'currencyIso' => $order->getCurrency()?->getIsoCode() ?? 'EUR',
            'transactionState' => $transaction?->getStateMachineState()?->getTechnicalName(),
        ]);
    }

    private function getCapturedTotal(string $orderTransactionId): float
    {
        $sql = <<<'SQL'
SELECT COALESCE(SUM(CAST(JSON_EXTRACT(c.amount, '$.totalPrice') AS DECIMAL(20,4))), 0)
FROM order_transaction_capture c
WHERE c.order_transaction_id = UNHEX(:transactionId)
  AND c.version_id = UNHEX(:liveVersion)
SQL;

        return (float) $this->connection->fetchOne($sql, [
            'transactionId' => $orderTransactionId,
            'liveVersion' => Defaults::LIVE_VERSION,
        ]);
    }

    private function getStateId(string $stateMachineName, string $technicalName, Context $context): ?string
    {
        $stateMachine = $this->stateMachineRegistry->getStateMachine($stateMachineName, $context);

        foreach ($stateMachine->getStates() ?? [] as $state) {
            if ($state->getTechnicalName() === $technicalName) {
                return $state->getId();
            }
        }

        return null;
    }
}
