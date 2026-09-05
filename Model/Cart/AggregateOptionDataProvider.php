<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\AggregateProductGraphQl\Model\Cart;

use InvalidArgumentException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

/**
 * Builds the cart line's "Includes" child breakdown from the purchase-time snapshot stored on the
 * quote item. Reading the snapshot (rather than live * relations) means a later relation edit/delete
 * can't change what an in-cart customer sees and can't throw a NoSuchEntityException into the cart
 * render. Live relations remain only as a guarded fallback for items added before snapshots existed.
 */
class AggregateOptionDataProvider
{
    public function __construct(
        private readonly LinkedProductProviderInterface $linkedProductProvider,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @return array<int, array{product_name: string, quantity: float}>
     */
    public function getData(QuoteItem $quoteItem): array
    {
        $values = $this->getValuesFromSnapshot($quoteItem);
        if ($values === null) {
            $values = $this->getValuesFromRelations($quoteItem);
        }

        return $values;
    }

    /**
     * @return array<int, array{product_name: string, quantity: float}>|null null when no snapshot
     */
    private function getValuesFromSnapshot(QuoteItem $quoteItem): ?array
    {
        $option = $quoteItem->getOptionByCode(Aggregate::SNAPSHOT_OPTION);
        if ($option === null || $option->getValue() === null || $option->getValue() === '') {
            return null;
        }

        try {
            $snapshot = $this->serializer->unserialize($option->getValue());
        } catch (InvalidArgumentException $e) {
            return null;
        }

        if (!is_array($snapshot) || empty($snapshot)) {
            return null;
        }

        $values = [];
        foreach ($snapshot as $child) {
            $values[] = [
                'product_name' => (string)($child['name'] ?? ''),
                'quantity' => (float)($child['qty'] ?? 0),
            ];
        }

        return $values;
    }

    /**
     * @return array<int, array{product_name: string, quantity: float}>
     */
    private function getValuesFromRelations(QuoteItem $quoteItem): array
    {
        $product = $quoteItem->getProduct();
        if ($product === null || (int)$product->getId() === 0) {
            return [];
        }

        try {
            $linkedProducts = $this->linkedProductProvider->getForProduct((int)$product->getId());
        } catch (LocalizedException $e) {
            return [];
        }

        $values = [];
        foreach ($linkedProducts as $linkedProduct) {
            $values[] = [
                'product_name' => (string)$linkedProduct->getProductName(),
                'quantity' => (float)$linkedProduct->getQty(),
            ];
        }

        return $values;
    }
}
