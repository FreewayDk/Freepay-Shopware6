<?php declare(strict_types=1);

namespace Freepay\Shopware\Controller;

use Freepay\Shopware\Service\FreepayApiClient;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class FreepayWebhookController extends AbstractController
{
    private FreepayApiClient $apiClient;
    private OrderTransactionStateHandler $transactionStateHandler;
    private EntityRepository $orderTransactionRepository;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(
        FreepayApiClient $apiClient,
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepository $orderTransactionRepository,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->apiClient = $apiClient;
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    #[Route(
        path: '/freepay/webhook',
        name: 'payment.freepay.webhook',
        methods: ['POST']
    )]
    public function webhook(Request $request): JsonResponse
    {
        $context = Context::createDefaultContext();

        try {
            $payload = json_decode($request->getContent(), true);

            if (!$payload) {
                $this->logger->error('Invalid webhook payload - not valid JSON' );
                return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
            }

            $signature = $request->headers->get('X-Freepay-Signature', '');
            if (!$this->apiClient->verifyWebhookSignature($payload, $signature)) {
                $this->logger->error('Webhook signature verification failed');
                return new JsonResponse(['error' => 'Invalid signature'], Response::HTTP_UNAUTHORIZED);
            }

            $freepayTransactionId = $payload['transaction_id'] ?? null;
            $paymentStatus = $payload['status'] ?? null;

            if (!$freepayTransactionId || !$paymentStatus) {
                $this->logger->error('Missing required webhook data');
                return new JsonResponse(['error' => 'Missing required data'], Response::HTTP_BAD_REQUEST);
            }

            $this->logger->info('Freepay webhook received', [
                'freepay_transaction_id' => $freepayTransactionId,
                'status' => $paymentStatus,
            ]);

            $orderTransaction = $this->findOrderTransaction($freepayTransactionId, $context);

            if (!$orderTransaction) {
                $this->logger->warning('Order transaction not found for webhook');
                return new JsonResponse(['status' => 'transaction_not_found'], Response::HTTP_OK);
            }

            $transactionId = $orderTransaction['id'];

            if ($this->wasWebhookProcessed($orderTransaction, $freepayTransactionId, $paymentStatus)) {
                return new JsonResponse(['status' => 'already_processed'], Response::HTTP_OK);
            }

            $this->processPaymentStatus($transactionId, $paymentStatus, $payload, $context);
            $this->markWebhookProcessed($transactionId, $freepayTransactionId, $paymentStatus, $context);

            return new JsonResponse(['status' => 'success'], Response::HTTP_OK);

        } catch (\Exception $e) {
            $this->logger->error('Webhook processing failed', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function findOrderTransaction(string $freepayTransactionId, Context $context): ?array
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('customFields.freepay_transaction_id', $freepayTransactionId)
        );

        $result = $this->orderTransactionRepository->search($criteria, $context);
        return $result->getTotal() === 0 ? null : $result->first();
    }

    private function wasWebhookProcessed(array $orderTransaction, string $freepayTransactionId, string $status): bool
    {
        $customFields = $orderTransaction['customFields'] ?? [];
        $lastWebhookStatus = $customFields['freepay_last_webhook_status'] ?? null;
        $lastWebhookId = $customFields['freepay_last_webhook_id'] ?? null;

        return $lastWebhookId === $freepayTransactionId && $lastWebhookStatus === $status;
    }

    private function markWebhookProcessed(string $transactionId, string $freepayTransactionId, string $status, Context $context): void
    {
        $this->orderTransactionRepository->update([
            [
                'id' => $transactionId,
                'customFields' => [
                    'freepay_last_webhook_id' => $freepayTransactionId,
                    'freepay_last_webhook_status' => $status,
                    'freepay_last_webhook_at' => date('Y-m-d H:i:s'),
                ],
            ],
        ], $context);
    }

    private function processPaymentStatus(string $transactionId, string $status, array $payload, Context $context): void
    {
        try {
            switch ($status) {
                case 'paid':
                case 'completed':
                case 'captured':
                    $this->transactionStateHandler->paid($transactionId, $context);
                    break;
                case 'authorized':
                    $this->transactionStateHandler->authorize($transactionId, $context);
                    break;
                case 'pending':
                case 'processing':
                    $this->transactionStateHandler->process($transactionId, $context);
                    break;
                case 'failed':
                case 'declined':
                    $this->transactionStateHandler->fail($transactionId, $context);
                    break;
                case 'cancelled':
                case 'canceled':
                    $this->transactionStateHandler->cancel($transactionId, $context);
                    break;
                case 'refunded':
                    $this->transactionStateHandler->refund($transactionId, $context);
                    break;
                case 'partially_refunded':
                    $this->transactionStateHandler->refundPartially($transactionId, $context);
                    break;
                default:
                    $this->logger->warning('Unknown payment status from webhook', ['status' => $status]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to update transaction state from webhook', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
