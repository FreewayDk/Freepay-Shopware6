<?php declare(strict_types=1);

namespace Freepay\Shopware\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentRefundProcessor;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class FreepayRefundController extends AbstractController
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $captureRepository,
        private readonly EntityRepository $refundRepository,
        private readonly PaymentRefundProcessor $refundProcessor,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly OrderTransactionStateHandler $transactionStateHandler,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {}

    #[Route(
        path: '/api/_action/freepay/refund/{orderId}',
        name: 'api.action.freepay.refund',
        methods: ['POST']
    )]
    public function refund(string $orderId, Request $request, Context $context): JsonResponse
    {
        $amount = (float) $request->request->get('amount');

        if ($amount <= 0) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Invalid refund amount'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $criteria = new Criteria([$orderId]);
        $criteria->addAssociation('transactions.stateMachineState');
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

        // A refund must target an existing capture (Shopware Core does not create
        // captures itself; OrderStateSubscriber writes them on successful capture).
        $captureCriteria = new Criteria();
        $captureCriteria->addFilter(new EqualsFilter('orderTransactionId', $transaction->getId()));
        $capture = $this->captureRepository->search($captureCriteria, $context)->first();

        if (!$capture) {
            return new JsonResponse([
                'success' => false,
                'error' => 'No capture found to refund. The payment must be captured first.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $openStateId = $this->getStateId('order_transaction_capture_refund.state', 'open', $context);
        if (!$openStateId) {
            return new JsonResponse(
                ['success' => false, 'error' => 'Refund state machine is not available'],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        $refundId = Uuid::randomHex();

        try {
            $this->refundRepository->create([
                [
                    'id' => $refundId,
                    'captureId' => $capture->getId(),
                    'stateId' => $openStateId,
                    'amount' => new CalculatedPrice(
                        $amount,
                        $amount,
                        new CalculatedTaxCollection(),
                        new TaxRuleCollection()
                    ),
                ],
            ], $context);

            // Hands off to the core processor, which transitions the refund states
            // and invokes FreepayPaymentHandler::refund() (the Freepay credit call).
            $this->refundProcessor->processRefund($refundId, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Freepay: refund failed', [
                'order_id' => $orderId,
                'refund_id' => $refundId,
                'error' => $e->getMessage(),
            ]);

            return new JsonResponse(
                ['success' => false, 'error' => $e->getMessage()],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // Reflect the refund in the order's payment status (full vs. partial),
        // so the admin shows feedback. Core's refund processor only transitions
        // the refund record itself, not the order transaction. Best-effort:
        // never fail the refund just because the status transition is awkward.
        $refundedTotal = null;
        $transactionTotal = $transaction->getAmount()->getTotalPrice();
        try {
            $refundedTotal = $this->getRefundedTotal($transaction->getId());

            if ($refundedTotal + 0.0001 >= $transactionTotal) {
                $this->transactionStateHandler->refund($transaction->getId(), $context);
            } else {
                $this->transactionStateHandler->refundPartially($transaction->getId(), $context);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Freepay: could not transition payment status after refund', [
                'order_id' => $orderId,
                'current_state' => $transaction->getStateMachineState()?->getTechnicalName(),
                'refunded_total' => $refundedTotal,
                'transaction_total' => $transactionTotal,
                'error' => $e->getMessage(),
            ]);
        }

        $this->logger->info('Freepay: refund initiated', [
            'order_id' => $orderId,
            'refund_id' => $refundId,
            'amount' => $amount,
        ]);

        return new JsonResponse(['success' => true, 'refundId' => $refundId]);
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

    #[Route(
        path: '/api/_action/freepay/refunds/{orderId}',
        name: 'api.action.freepay.refunds',
        methods: ['GET']
    )]
    public function refunds(string $orderId): JsonResponse
    {
        // Raw SQL on purpose: with Elasticsearch enabled the DAL cannot filter this
        // entity by the deep association path (capture.transaction.orderId) — it throws
        // UNMAPPED_FIELD. SQL bypasses ES entirely.
        $sql = <<<'SQL'
SELECT LOWER(HEX(r.id)) AS id,
       CAST(JSON_EXTRACT(r.amount, '$.totalPrice') AS DECIMAL(20,4)) AS amount,
       r.created_at AS createdAt,
       cur.iso_code AS currencyIso
FROM order_transaction_capture_refund r
INNER JOIN order_transaction_capture c
    ON c.id = r.capture_id AND c.version_id = r.capture_version_id
INNER JOIN order_transaction t
    ON t.id = c.order_transaction_id AND t.version_id = c.order_transaction_version_id
INNER JOIN `order` o
    ON o.id = t.order_id AND o.version_id = t.order_version_id
INNER JOIN currency cur ON cur.id = o.currency_id
WHERE t.order_id = UNHEX(:orderId)
  AND r.version_id = UNHEX(:liveVersion)
ORDER BY r.created_at DESC
SQL;

        $rows = $this->connection->fetchAllAssociative($sql, [
            'orderId' => $orderId,
            'liveVersion' => Defaults::LIVE_VERSION,
        ]);

        $refunds = array_map(static fn (array $row): array => [
            'id' => $row['id'],
            'amount' => (float) $row['amount'],
            'createdAt' => $row['createdAt'],
            'currencyIso' => $row['currencyIso'],
        ], $rows);

        return new JsonResponse(['refunds' => $refunds]);
    }

    private function getRefundedTotal(string $orderTransactionId): float
    {
        $sql = <<<'SQL'
SELECT COALESCE(SUM(CAST(JSON_EXTRACT(r.amount, '$.totalPrice') AS DECIMAL(20,4))), 0)
FROM order_transaction_capture_refund r
INNER JOIN order_transaction_capture c
    ON c.id = r.capture_id AND c.version_id = r.capture_version_id
WHERE c.order_transaction_id = UNHEX(:transactionId)
  AND r.version_id = UNHEX(:liveVersion)
SQL;

        return (float) $this->connection->fetchOne($sql, [
            'transactionId' => $orderTransactionId,
            'liveVersion' => Defaults::LIVE_VERSION,
        ]);
    }
}
