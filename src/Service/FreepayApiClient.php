<?php declare(strict_types=1);

namespace Freepay\Shopware\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class FreepayApiClient
{
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    /**
     * Creates a payment session with Freepay
     */
    public function createPaymentSession(array $paymentData, ?string $salesChannelId = null): ?array
    {
        $apiUrl = $this->getApiUrl($salesChannelId);
        $endpoint = $apiUrl . '/payments';

        $payload = [
            'merchant_id' => $this->getConfig('merchantId', $salesChannelId),
            'amount' => $paymentData['amount'],
            'currency' => $paymentData['currency'],
            'order_id' => $paymentData['order_id'],
            'transaction_id' => $paymentData['transaction_id'],
            'return_url' => $paymentData['return_url'],
            'webhook_url' => $this->getWebhookUrl(),
            'customer' => $paymentData['customer'] ?? [],
            'timestamp' => time(),
        ];

        try {
            $response = $this->sendRequest('POST', $endpoint, $payload, $salesChannelId);

            if ($this->isLoggingEnabled($salesChannelId)) {
                $this->logger->info('Freepay payment session created', [
                    'order_id' => $paymentData['order_id'],
                    'response' => $response,
                ]);
            }

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create Freepay payment session', [
                'error' => $e->getMessage(),
                'order_id' => $paymentData['order_id'] ?? null,
            ]);

            return null;
        }
    }

    /**
     * Retrieves payment status from Freepay
     */
    public function getPaymentStatus(string $freepayTransactionId, ?string $salesChannelId = null): ?array
    {
        $apiUrl = $this->getApiUrl($salesChannelId);
        $endpoint = $apiUrl . '/payments/' . $freepayTransactionId;

        try {
            $response = $this->sendRequest('GET', $endpoint, [], $salesChannelId);

            if ($this->isLoggingEnabled($salesChannelId)) {
                $this->logger->info('Freepay payment status retrieved', [
                    'freepay_transaction_id' => $freepayTransactionId,
                    'status' => $response['status'] ?? 'unknown',
                ]);
            }

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve Freepay payment status', [
                'error' => $e->getMessage(),
                'freepay_transaction_id' => $freepayTransactionId,
            ]);

            return null;
        }
    }

    /**
     * Captures an authorized payment
     */
    public function capturePayment(string $freepayTransactionId, ?int $amount = null, ?string $salesChannelId = null): ?array
    {
        $apiUrl = $this->getApiUrl($salesChannelId);
        $endpoint = $apiUrl . '/payments/' . $freepayTransactionId . '/capture';

        $payload = [];
        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        try {
            $response = $this->sendRequest('POST', $endpoint, $payload, $salesChannelId);

            $this->logger->info('Freepay payment captured', [
                'freepay_transaction_id' => $freepayTransactionId,
                'amount' => $amount,
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Failed to capture Freepay payment', [
                'error' => $e->getMessage(),
                'freepay_transaction_id' => $freepayTransactionId,
            ]);

            return null;
        }
    }

    /**
     * Refunds a payment
     */
    public function refundPayment(string $freepayTransactionId, ?int $amount = null, ?string $salesChannelId = null): ?array
    {
        $apiUrl = $this->getApiUrl($salesChannelId);
        $endpoint = $apiUrl . '/payments/' . $freepayTransactionId . '/refund';

        $payload = [];
        if ($amount !== null) {
            $payload['amount'] = $amount;
        }

        try {
            $response = $this->sendRequest('POST', $endpoint, $payload, $salesChannelId);

            $this->logger->info('Freepay payment refunded', [
                'freepay_transaction_id' => $freepayTransactionId,
                'amount' => $amount,
            ]);

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Failed to refund Freepay payment', [
                'error' => $e->getMessage(),
                'freepay_transaction_id' => $freepayTransactionId,
            ]);

            return null;
        }
    }

    /**
     * Verifies webhook signature from Freepay
     */
    public function verifyWebhookSignature(array $payload, string $signature, ?string $salesChannelId = null): bool
    {
        $secret = $this->getConfig('webhookSecret', $salesChannelId);
        
        if (!$secret) {
            $this->logger->error('Webhook secret not configured');
            return false;
        }

        $payloadString = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $expectedSignature = hash_hmac('sha256', $payloadString, $secret);

        $isValid = hash_equals($expectedSignature, $signature);

        if (!$isValid) {
            $this->logger->warning('Invalid webhook signature', [
                'expected' => $expectedSignature,
                'received' => $signature,
            ]);
        }

        return $isValid;
    }

    /**
     * Sends HTTP request to Freepay API
     */
    private function sendRequest(string $method, string $endpoint, array $payload, ?string $salesChannelId = null): array
    {
        $apiKey = $this->getConfig('apiKey', $salesChannelId);

        $client = new Client([
            'timeout' => 30,
            'verify' => true,
        ]);

        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
            ],
        ];

        if (!empty($payload) && $method !== 'GET') {
            $options['json'] = $payload;
        }

        try {
            $response = $client->request($method, $endpoint, $options);
            $body = $response->getBody()->getContents();
            
            return json_decode($body, true) ?? [];

        } catch (GuzzleException $e) {
            $this->logger->error('Freepay API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new \Exception('Freepay API request failed: ' . $e->getMessage());
        }
    }

    private function getApiUrl(?string $salesChannelId = null): string
    {
        $sandboxMode = $this->systemConfigService->getBool(
            'FreepayPayment.config.sandboxMode',
            $salesChannelId
        );

        if ($sandboxMode) {
            return $this->getConfig('apiUrlSandbox', $salesChannelId);
        }

        return $this->getConfig('apiUrlProduction', $salesChannelId);
    }

    private function getWebhookUrl(): string
    {
        return $_ENV['APP_URL'] . '/freepay/webhook';
    }

    private function getConfig(string $key, ?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->getString(
            'FreepayPayment.config.' . $key,
            $salesChannelId
        );
    }

    private function isLoggingEnabled(?string $salesChannelId = null): bool
    {
        return $this->systemConfigService->getBool(
            'FreepayPayment.config.enableLogging',
            $salesChannelId
        );
    }
}
