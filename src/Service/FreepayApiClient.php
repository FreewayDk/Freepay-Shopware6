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
        $endpoint = 'gw.freepay.dk/api/payment';

        try {
            $response = $this->sendRequest('POST', $endpoint, $paymentData, $salesChannelId);

            // $this->logger->info('Freepay payment session created', [
            //     'order_id' => $paymentData['order_id'],
            //     'response' => $response,
            // ]);

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
        $endpoint = $apiUrl . $freepayTransactionId;

        try {
            $response = $this->sendRequest('GET', $endpoint, [], $salesChannelId);

            // $this->logger->info('Freepay payment status retrieved', [
            //     'freepay_transaction_id' => $freepayTransactionId,
            //     'status' => $response['status'] ?? 'unknown',
            // ]);

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
        $endpoint = $apiUrl . $freepayTransactionId . '/capture';

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
        $endpoint = $apiUrl . $freepayTransactionId . '/credit';

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
                'Authorization' => $apiKey,
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
        return 'https://mw.freepay.dk/api/v2/';
    }

    private function getConfig(string $key, ?string $salesChannelId = null): ?string
    {
        return $this->systemConfigService->getString(
            'FreepayPaymentShopware6.config.' . $key,
            $salesChannelId
        );
    }
}
