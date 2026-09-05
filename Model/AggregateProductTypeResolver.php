<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\AggregateProductGraphQl\Model;

use Magento\Framework\GraphQl\Query\Resolver\TypeResolverInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;

class AggregateProductTypeResolver implements TypeResolverInterface
{
    public const AGGREGATE_PRODUCT = 'AggregateProduct';

    public function resolveType(array $data) : string
    {
        if (isset($data['type_id']) && $data['type_id'] === Aggregate::TYPE_CODE) {
            return self::AGGREGATE_PRODUCT;
        }
        return '';
    }
}
