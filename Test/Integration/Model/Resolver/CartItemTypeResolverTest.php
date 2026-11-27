<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProductGraphQl\Test\Integration\Model\Resolver;

use Magento\QuoteGraphQl\Model\Resolver\CartItemTypeResolver;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Adding the aggregate cart item type must keep the core types resolvable in the GraphQL area.
 */
#[AppArea('graphql')]
class CartItemTypeResolverTest extends TestCase
{
    public static function cartItemTypes(): array
    {
        return [
            'aggregate' => ['aggregate', 'AggregateCartItem'],
            'simple' => ['simple', 'SimpleCartItem'],
        ];
    }

    #[DataProvider('cartItemTypes')]
    public function testResolvesCartItemTypeInGraphQlArea(string $productType, string $cartItemType): void
    {
        $resolver = Bootstrap::getObjectManager()->create(CartItemTypeResolver::class);

        $this->assertSame($cartItemType, $resolver->resolveType(['product' => ['type_id' => $productType]]));
    }
}
