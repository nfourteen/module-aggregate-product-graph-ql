<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\AggregateProductGraphQl\Model\Resolver\Cart;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Nfourteen\AggregateProductGraphQl\Model\Cart\AggregateOptionDataProvider;

/**
 * Thin adapter exposing the cart line's "Includes" child breakdown. The snapshot-read and
 * relation-fallback logic lives in AggregateOptionDataProvider.
 */
class AggregateOptions implements ResolverInterface
{
    public function __construct(
        private readonly AggregateOptionDataProvider $optionDataProvider
    ) {
    }

    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (!isset($value['model'])) {
            throw new LocalizedException(__('"model" value should be specified'));
        }

        $values = $this->optionDataProvider->getData($value['model']);
        if (empty($values)) {
            return [];
        }

        return [
            [
                'label' => (string)__('Includes'),
                'values' => $values,
            ]
        ];
    }
}
