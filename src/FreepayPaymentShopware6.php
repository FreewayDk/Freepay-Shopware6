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
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Util\PluginIdProvider;
use Shopware\Core\Framework\Uuid\Uuid;
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

    public function update(UpdateContext $updateContext): void
    {
        $this->registerCustomFieldSet($updateContext->getContext());
        parent::update($updateContext);
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

        // Resolve IDs by name so upsert() always targets the same primary keys and
        // becomes idempotent across install/update/reinstall. Without explicit IDs
        // the DAL generates fresh UUIDs and treats the upsert as an insert, which
        // collides with leftover rows on uniq.custom_field.name (1062 Duplicate
        // entry) whenever the custom fields survived a previous uninstall
        // (keepUserData). Reusing any existing row id by name also self-heals a DB
        // that already has orphaned freepay_* custom fields with random UUIDs.
        $setId = $this->resolveExistingId('custom_field_set.repository', 'freepay_order_transaction')
            ?? Uuid::fromStringToHex('freepay_order_transaction');
        $paymentFieldId = $this->resolveExistingId('custom_field.repository', 'freepay_payment_identifier')
            ?? Uuid::fromStringToHex('freepay_payment_identifier');
        $authorizationFieldId = $this->resolveExistingId('custom_field.repository', 'freepay_authorization_identifier')
            ?? Uuid::fromStringToHex('freepay_authorization_identifier');
        // The relation also needs a stable id: when the set already exists, an
        // id-less relation is inserted as a new row and collides on
        // uniq.custom_field_set_relation.entity_name (set_id + entity_name).
        $relationId = $this->resolveExistingRelationId($setId, 'order')
            ?? Uuid::fromStringToHex('freepay_order_transaction.order');

        $repository->upsert([
            [
                'id' => $setId,
                'name' => 'freepay_order_transaction',
                'config' => [
                    'label' => ['en-GB' => 'Freepay', 'da-DK' => 'Freepay'],
                ],
                'relations' => [
                    ['id' => $relationId, 'entityName' => 'order'],
                ],
                'customFields' => [
                    [
                        'id' => $paymentFieldId,
                        'name' => 'freepay_payment_identifier',
                        'type' => CustomFieldTypes::TEXT,
                        'config' => [
                            'label' => ['en-GB' => 'Payment Identifier', 'da-DK' => 'Betalingsidentifikator'],
                            'customFieldType' => 'text',
                            'customFieldPosition' => 1,
                        ],
                    ],
                    [
                        'id' => $authorizationFieldId,
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

    private function resolveExistingId(string $repositoryName, string $name): ?string
    {
        $repository = $this->container->get($repositoryName);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));

        return $repository->searchIds($criteria, Context::createDefaultContext())->firstId();
    }

    private function resolveExistingRelationId(string $customFieldSetId, string $entityName): ?string
    {
        $repository = $this->container->get('custom_field_set_relation.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customFieldSetId', $customFieldSetId));
        $criteria->addFilter(new EqualsFilter('entityName', $entityName));

        return $repository->searchIds($criteria, Context::createDefaultContext())->firstId();
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
