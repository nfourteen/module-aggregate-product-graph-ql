<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProductGraphQl\Model\Resolver\Order\Item;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\Data\CreditmemoItemInterface;
use Magento\Sales\Api\Data\InvoiceItemInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\ShipmentItemInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

class AggregateOptions implements ResolverInterface
{
    public function __construct(
        private readonly ValueFactory $valueFactory
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        return $this->valueFactory->create(function () use ($value) {
            if (!isset($value['model'])) {
                throw new LocalizedException(__('"model" value should be specified'));
            }

            $model = $value['model'];

            if ($model instanceof OrderItemInterface) {
                return $this->getAggregateOptions($model);
            }

            if ($model instanceof InvoiceItemInterface
                || $model instanceof ShipmentItemInterface
                || $model instanceof CreditmemoItemInterface
            ) {
                return $this->getAggregateOptions($model->getOrderItem());
            }

            return null;
        });
    }

    private function getAggregateOptions(OrderItemInterface $orderItem): array
    {
        if ($orderItem->getProductType() !== Aggregate::TYPE_CODE) {
            return [];
        }

        $productOptions = $orderItem->getProductOptions();
        $aggregateConfig = $productOptions['aggregate_config'] ?? [];
        $options = [];

        foreach ($aggregateConfig as $option) {
            $values = [];
            foreach ($option['value'] ?? [] as $child) {
                $values[] = [
                    'product_name' => $child['name'] ?? '',
                    'quantity' => (float)($child['qty'] ?? 0),
                ];
            }

            $options[] = [
                'label' => $option['label'] ?? '',
                'values' => $values,
            ];
        }

        return $options;
    }
}
