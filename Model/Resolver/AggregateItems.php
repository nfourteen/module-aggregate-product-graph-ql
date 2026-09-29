<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProductGraphQl\Model\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchRequestItemInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResolverInterface;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponseFactory;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;

/**
 * Batch resolver for an aggregate product's child items. Gathering every requested parent into a
 * single getForProducts() call replaces the previous per-item getForProduct() (one query + one
 * uncaught NoSuchEntityException risk per row) with one batched load for the whole result set.
 */
class AggregateItems implements BatchResolverInterface
{
    public function __construct(
        private readonly LinkedProductProviderInterface $linkedProductProvider,
        private readonly BatchResponseFactory $batchResponseFactory
    ) {
    }

    public function resolve(ContextInterface $context, Field $field, array $requests): BatchResponse
    {
        $productIdByRequest = [];
        foreach ($requests as $key => $request) {
            $productIdByRequest[$key] = $this->extractProductId($request);
        }

        $uniqueIds = array_values(array_unique(array_filter($productIdByRequest)));
        $linkedByParent = empty($uniqueIds)
            ? []
            : $this->linkedProductProvider->getForProducts($uniqueIds);

        /** @var BatchResponse $response */
        $response = $this->batchResponseFactory->create();
        foreach ($requests as $key => $request) {
            $productId = $productIdByRequest[$key];

            $data = [];
            foreach ($linkedByParent[$productId] ?? [] as $linkedProduct) {
                $data[] = [
                    'qty' => $linkedProduct->getQty(),
                    'sku' => $linkedProduct->getProductSku(),
                ];
            }

            $response->addResponse($request, $data);
        }

        return $response;
    }

    private function extractProductId(BatchRequestItemInterface $request): int
    {
        $value = $request->getValue();
        if (!isset($value['model'])) {
            throw new LocalizedException(__('"model" value should be specified'));
        }

        return (int)$value['model']->getId();
    }
}
