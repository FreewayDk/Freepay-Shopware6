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
use Shopware\Core\System\CustomField\CustomFieldTypes;

class FreepayPaymentShopware6 extends Plugin
{
    private ?EntityRepository $paymentMethodRepository = null;

    public function install(InstallContext $installContext): void
    {
        $this->addPaymentMethod($installContext->getContext());
        $this->registerCustomFieldSet($installContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $this->setPaymentMethodIsActive(false, $uninstallContext->getContext());

        if (!$uninstallContext->keepUserData()) {
            $this->removeCustomFieldSet($uninstallContext->getContext());
        }
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

    private function registerCustomFieldSet(Context $context): void
    {
        $repository = $this->container->get('custom_field_set.repository');

        $repository->upsert([
            [
                'name' => 'freepay_order_transaction',
                'config' => [
                    'label' => ['en-GB' => 'Freepay', 'da-DK' => 'Freepay'],
                ],
                'relations' => [
                    ['entityName' => 'order_transaction'],
                ],
                'customFields' => [
                    [
                        'name' => 'freepay_payment_identifier',
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Payment Identifier', 'da-DK' => 'Betalingsidentifikator'],
                            'customFieldType' => 'text',
                            'customFieldPosition' => 1,
                        ],
                    ],
                    [
                        'name' => 'freepay_authorization_identifier',
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Authorization Identifier', 'da-DK' => 'Autorisationsidentifikator'],
                            'customFieldType' => 'text',
                            'customFieldPosition' => 2,
                        ],
                    ],
                ],
            ],
        ], $context);
    }

    private function removeCustomFieldSet(Context $context): void
    {
        $repository = $this->container->get('custom_field_set.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'freepay_order_transaction'));

        $id = $repository->searchIds($criteria, $context)->firstId();

        if ($id) {
            $repository->delete([['id' => $id]], $context);
        }
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
