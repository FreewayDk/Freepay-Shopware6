<?php declare(strict_types=1);

namespace Freepay\Shopware\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class FreepayReturnController extends StorefrontController
{
    private OrderTransactionStateHandler $transactionStateHandler;
    private EntityRepository $orderTransactionRepository;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(
        OrderTransactionStateHandler $transactionStateHandler,
        EntityRepository $orderTransactionRepository,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->transactionStateHandler = $transactionStateHandler;
        $this->orderTransactionRepository = $orderTransactionRepository;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    #[Route(
        path: '/freepay/return/success',
        name: 'payment.freepay.return.success',
        methods: ['GET']
    )]
    public function success(Request $request): Response
    {
        $transactionId = $request->query->get('transaction_id');

        $this->logger->info('Customer returned from Freepay payment (success)', [
            'transaction_id' => $transactionId,
        ]);

        if ($transactionId) {
            $context = Context::createDefaultContext();
            $criteria = new Criteria([$transactionId]);
            $criteria->addAssociation('order');
            
            $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

            if ($transaction && $transaction->getOrder()) {
                $orderId = $transaction->getOrder()->getId();
                
                return $this->redirectToRoute('frontend.checkout.finish.page', [
                    'orderId' => $orderId,
                ]);
            }
        }

        $this->addFlash('success', 'Your payment was successful. You will receive an order confirmation shortly.');
        return $this->redirectToRoute('frontend.home.page');
    }

    #[Route(
        path: '/freepay/return/cancel',
        name: 'payment.freepay.return.cancel',
        methods: ['GET']
    )]
    public function cancel(Request $request): Response
    {
        $transactionId = $request->query->get('transaction_id');

        $this->logger->info('Customer returned from Freepay payment (cancelled)', [
            'transaction_id' => $transactionId,
        ]);

        if ($transactionId) {
            $context = Context::createDefaultContext();
            
            try {
                $this->transactionStateHandler->cancel($transactionId, $context);
                
                $criteria = new Criteria([$transactionId]);
                $criteria->addAssociation('order');
                
                $transaction = $this->orderTransactionRepository->search($criteria, $context)->first();

                if ($transaction && $transaction->getOrder()) {
                    $orderId = $transaction->getOrder()->getId();
                    
                    $this->addFlash('warning', 'Payment was cancelled. Please select a payment method to continue.');
                    
                    return $this->redirectToRoute('frontend.checkout.confirm.page', [
                        'orderId' => $orderId,
                    ]);
                }
                
            } catch (\Exception $e) {
                $this->logger->error('Failed to process cancelled payment return', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->addFlash('warning', 'Payment was cancelled. Please try again.');
        return $this->redirectToRoute('frontend.checkout.cart.page');
    }
}
