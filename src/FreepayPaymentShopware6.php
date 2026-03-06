<?php declare(strict_types=1);

namespace Freepay\Shopware;

use Freepay\Shopware\Service\FreepayPaymentHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;

class FreepayPaymentShopware6 extends Plugin
{
    private ?EntityRepository $paymentMethodRepository = null;

    public function install(InstallContext $installContext): void
    {
        $this->addPaymentMethod($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        // Keep payment method data for historical orders
        $this->setPaymentMethodIsActive(false, $uninstallContext->getContext());
    }

    public function activate(ActivateContext $activateContext): void
    {
        $this->setPaymentMethodIsActive(true, $activateContext->getContext());
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        $this->setPaymentMethodIsActive(false, $deactivateContext->getContext());
        parent::deactivate($deactivateContext);
    }

    private function getPaymentMethodRepository(): EntityRepository
    {
        if ($this->paymentMethodRepository === null) {
            $this->paymentMethodRepository = $this->container->get('payment_method.repository');
        }

        return $this->paymentMethodRepository;
    }

    private function addPaymentMethod(Context $context): void
    {
        $paymentMethodExists = $this->getPaymentMethodId();

        if ($paymentMethodExists) {
            return;
        }

        /** @var PluginIdProvider $pluginIdProvider */
        $pluginIdProvider = $this->container->get(PluginIdProvider::class);
        $pluginId = $pluginIdProvider->getPluginIdByBaseClass(self::class, $context);

        $paymentData = [
            'handlerIdentifier' => FreepayPaymentHandler::class,
            'name' => 'Freepay',
            'description' => 'Pay securely with Freepay payment gateway',
            'pluginId' => $pluginId,
            'afterOrderEnabled' => true,
            'translations' => [
                'da-DK' => [
                    'name' => 'Freepay',
                    'description' => 'Betal sikkert med Freepay betalingsgateway',
                ],
            ],
            'technicalName' => 'freepay-payment-shopware6',
        ];

        $paymentRepository = $this->getPaymentMethodRepository();
        $paymentRepository->create([$paymentData], $context);
    }

    private function setPaymentMethodIsActive(bool $active, Context $context): void
    {
        $paymentRepository = $this->getPaymentMethodRepository();

        $paymentMethodId = $this->getPaymentMethodId();

        if (!$paymentMethodId) {
            return;
        }

        $paymentMethod = [
            'id' => $paymentMethodId,
            'active' => $active,
        ];

        $paymentRepository->update([$paymentMethod], $context);
    }

    private function getPaymentMethodId(): ?string
    {
        $paymentRepository = $this->getPaymentMethodRepository();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', FreepayPaymentHandler::class));

        return $paymentRepository->searchIds($criteria, Context::createDefaultContext())->firstId();
    }
}
