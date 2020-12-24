<?php

namespace Phung\InventoryInStorePickupSales\Plugin\Model\Order;

use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Validation\ValidationException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemRepositoryInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryInStorePickupSales\Model\Order\GetPickupLocationCode;
use Magento\InventoryInStorePickupSales\Model\Order\IsFulfillable as Subject;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Inventory\Model\ResourceModel\Source\Collection as SourceCollection;
use Magento\Inventory\Model\ResourceModel\Source\CollectionFactory as SourceCollectionFactory;
use Magento\Inventory\Model\Source;
use Magento\InventorySales\Model\ResourceModel\GetAssignedStockIdForWebsite;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;

class IsFulfillable
{
    /** @var SourceItemRepositoryInterface */
    private $sourceItemRepository;

    /** @var SearchCriteriaBuilderFactory */
    private $searchCriteriaBuilderFactory;

    /** @var SourceRepositoryInterface */
    private $sourceRepository;

    /** @var GetPickupLocationCode */
    private $getPickupLocationCode;

    /** @var SourceCollectionFactory */
    private $inventorySourceCollection;

    /** @var GetAssignedStockIdForWebsite */
    private $getStockIdForWebsite;

    /** @var string */
    private $defaultSourceCode;

    /** @var SourceItemsSaveInterface */
    private $sourceItemSaver;

    /**
     * @param SourceItemRepositoryInterface $sourceItemRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilder
     * @param SourceRepositoryInterface $sourceRepository
     * @param GetPickupLocationCode $getPickupLocationCode
     * @param SourceCollectionFactory $inventorySourceCollection
     * @param GetAssignedStockIdForWebsite $getStockIdForWebsite
     * @param SourceItemsSaveInterface $sourceItemSaver
     */
    public function __construct(
        SourceItemRepositoryInterface $sourceItemRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilder,
        SourceRepositoryInterface $sourceRepository,
        GetPickupLocationCode $getPickupLocationCode,
        SourceCollectionFactory $inventorySourceCollection,
        GetAssignedStockIdForWebsite $getStockIdForWebsite,
        SourceItemsSaveInterface $sourceItemSaver
    ) {
        $this->sourceItemRepository = $sourceItemRepository;
        $this->searchCriteriaBuilderFactory = $searchCriteriaBuilder;
        $this->sourceRepository = $sourceRepository;
        $this->getPickupLocationCode = $getPickupLocationCode;
        $this->inventorySourceCollection = $inventorySourceCollection;
        $this->getStockIdForWebsite = $getStockIdForWebsite;
        $this->sourceItemSaver = $sourceItemSaver;
    }

    /**
     * @param Subject $subject
     * @param bool $result
     * @param OrderInterface $order
     * @return bool
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function afterExecute(Subject $subject, $result, OrderInterface $order)
    {
        if (!$result) {
            $sourceCode = $this->getPickupLocationCode->execute($order);

            if ($sourceCode) {
                $webSiteCode = $order->getStore()->getWebsite()->getCode();
                $stockId = $this->getStockIdForWebsite->execute($webSiteCode);
                $defaultSourceCode = $this->getDefaultSourceCode($stockId);

                foreach ($order->getItems() as $item) {
                    if ($item->getHasChildren()) {
                        continue;
                    }

                    // try use stock from default source
                    $result = $this->isItemFulfillable($item->getSku(), $sourceCode, $defaultSourceCode, (float)$item->getQtyOrdered());
                    if (!$result) {
                        return false;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Check if Pickup Location source has enough item qty.
     *
     * @param string $sku
     * @param string $targetSourceCode
     * @param string $defaultSourceCode
     * @param float $qtyOrdered
     * @return bool
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws ValidationException
     */
    private function isItemFulfillable(string $sku, string $targetSourceCode, $defaultSourceCode, float $qtyOrdered): bool
    {
        $targetSearchCriteria = $this->searchCriteriaBuilderFactory
            ->create()
            ->addFilter(SourceItemInterface::SOURCE_CODE, $targetSourceCode)
            ->addFilter(SourceItemInterface::SKU, $sku)
            ->create();

        $targetSourceItems = $this->sourceItemRepository->getList($targetSearchCriteria);
        $isTargetSourceItemFulfillable = false;
        if ($targetSourceItems->getTotalCount()) {
            /** @var SourceItemInterface $targetSourceItem */
            $targetSourceItem = current($targetSourceItems->getItems());
            $targetSource = $this->sourceRepository->get($targetSourceCode);

            $isTargetSourceItemFulfillable = bccomp((string)$targetSourceItem->getQuantity(), (string)$qtyOrdered, 4) >= 0 &&
                $targetSourceItem->getStatus() === SourceItemInterface::STATUS_IN_STOCK &&
                $targetSource->isEnabled();

            if (!$isTargetSourceItemFulfillable && $targetSource->isEnabled()) {
                return $this->updateQtyForTargetSourceItem($sku, $targetSourceItem, $defaultSourceCode, $qtyOrdered);
            }
        }

        return $isTargetSourceItemFulfillable;
    }

    /**
     * @param string $sku
     * @param SourceItemInterface $targetSourceItem
     * @param string $defaultSourceCode
     * @param float $qtyOrdered
     * @return bool
     * @throws CouldNotSaveException
     * @throws InputException
     * @throws ValidationException
     */
    private function updateQtyForTargetSourceItem($sku, $targetSourceItem, $defaultSourceCode, $qtyOrdered)
    {
        $defaultSearchCriteria = $this->searchCriteriaBuilderFactory
            ->create()
            ->addFilter(SourceItemInterface::SOURCE_CODE, $defaultSourceCode)
            ->addFilter(SourceItemInterface::SKU, $sku)
            ->create();

        $defaultSourceItems = $this->sourceItemRepository->getList($defaultSearchCriteria);

        if ($defaultSourceItems->getTotalCount()) {
            /** @var SourceItemInterface $defaultSourceItem */
            $defaultSourceItem = current($defaultSourceItems->getItems());
            $defaultSourceItemQty = $defaultSourceItem->getQuantity();
            $targetSourceItemQty = $targetSourceItem->getQuantity();
            $qtyOrderedForDefaultSourceItem = $qtyOrdered - $targetSourceItemQty;

            $isDefaultSourceItemFulfillable = $defaultSourceItem->getStatus() === SourceItemInterface::STATUS_IN_STOCK &&
                bccomp((string)$defaultSourceItemQty, (string)$qtyOrderedForDefaultSourceItem, 4) >= 0;

            if ($isDefaultSourceItemFulfillable) {
                // update qty & status for target source item
                $targetSourceItem->setQuantity((float)$targetSourceItemQty + (float)$qtyOrderedForDefaultSourceItem);
                $targetSourceItem->setStatus(SourceItemInterface::STATUS_IN_STOCK);
                $this->sourceItemSaver->execute([$targetSourceItem]);

                // update qty & status for default source item
                $calculatedQtyForDefaultSourceItem = $defaultSourceItemQty - $qtyOrderedForDefaultSourceItem;
                $defaultSourceItem->setQuantity($calculatedQtyForDefaultSourceItem);
                if ($calculatedQtyForDefaultSourceItem == 0) {
                    $defaultSourceItem->setStatus(SourceItemInterface::STATUS_OUT_OF_STOCK);
                }
                $this->sourceItemSaver->execute([$defaultSourceItem]);
            }

            return $isDefaultSourceItemFulfillable;
        }

        return false;
    }

    /**
     * @param int $stockId
     * @return string
     * @throws LocalizedException
     */
    private function getDefaultSourceCode($stockId) {

        if (!$this->defaultSourceCode) {
            /** @var SourceCollection $inventorySourceCollection */
            $inventorySourceCollection = $this->inventorySourceCollection->create();

            $inventorySourceCollection->getSelect()
                ->joinInner('inventory_source_stock_link as sl',
                    "sl.source_code = main_table.source_code AND sl.stock_id = $stockId AND main_table.enabled = 1",
                    ''
                )
                ->order('sl.priority')
                ->limit(1);

            if ($inventorySourceCollection->getFirstItem()->getId() === null) {
                throw new LocalizedException(__("Cannot get default source stock"));
            }

            $this->defaultSourceCode = $inventorySourceCollection->getFirstItem()->getData(Source::SOURCE_CODE);
        }

        return $this->defaultSourceCode;
    }
}
