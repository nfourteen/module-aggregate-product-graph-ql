<?php
declare(strict_types=1);
/**
 * Copyright © David Nimorwicz. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Nfourteen\AggregateProductGraphQl\Test\Integration\Model\Resolver\Order\Item;

use GraphQL\Executor\Promise\Adapter\SyncPromise;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Checkout\Test\Fixture\PlaceOrder as PlaceOrderFixture;
use Magento\Checkout\Test\Fixture\SetBillingAddress as SetBillingAddressFixture;
use Magento\Checkout\Test\Fixture\SetDeliveryMethod as SetDeliveryMethodFixture;
use Magento\Checkout\Test\Fixture\SetGuestEmail as SetGuestEmailFixture;
use Magento\Checkout\Test\Fixture\SetPaymentMethod as SetPaymentMethodFixture;
use Magento\Checkout\Test\Fixture\SetShippingAddress as SetShippingAddressFixture;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\TestFramework\Fixture\AppIsolation;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use Nfourteen\AggregateProductGraphQl\Model\Resolver\Order\Item\AggregateOptions;
use PHPUnit\Framework\TestCase;

/**
 * The order-item "Includes" GraphQL resolver reads the aggregate_config persisted on the parent
 * order item at conversion time — the customer's order history must show what was bought even if
 * catalog relations change later.
 */
class AggregateOptionsTest extends TestCase
{
    private ?ObjectManagerInterface $objectManager = null;
    private ?AggregateOptions $resolver = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        // Construct the resolver directly for the same reason as the cart resolver test: the DI
        // interceptor's unrelated reCaptcha GraphQL plugin dereferences ResolveInfo internals a
        // bare resolver call has no need to populate.
        $this->resolver = new AggregateOptions($this->objectManager->get(ValueFactory::class));
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DbIsolation(false),
        AppIsolation(true),
        Config('carriers/flatrate/active', 1, 'store', 'default'),
        Config('payment/checkmo/active', 1, 'store', 'default'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-gql-order-child-1', 'price' => 10.0], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-gql-order-child-2', 'price' => 7.5], as: 'child2'),
        DataFixture(AggregateProductFixture::class, ['sku' => 'agg-gql-order-parent', 'price' => 50.0, '_children' => [
            ['product_id' => '$child1.id$', 'qty' => 2],
            ['product_id' => '$child2.id$', 'qty' => 3],
        ]], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
        DataFixture(SetBillingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetShippingAddressFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetGuestEmailFixture::class, ['cart_id' => '$cart.id$']),
        DataFixture(SetDeliveryMethodFixture::class, ['cart_id' => '$cart.id$', 'carrier_code' => 'flatrate', 'method_code' => 'flatrate']),
        DataFixture(SetPaymentMethodFixture::class, ['cart_id' => '$cart.id$', 'method' => 'checkmo']),
        DataFixture(PlaceOrderFixture::class, ['cart_id' => '$cart.id$'], as: 'order'),
    ]
    public function testResolvesAggregateOptionsFromPlacedOrderItem(): void
    {
        $parentItem = $this->getAggregateParentItem($this->fixtures->get('order'));
        $this->assertNotNull($parentItem, 'Aggregate parent item should exist in order');

        $deferred = $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            ['model' => $parentItem]
        );
        SyncPromise::runQueue();
        $result = $deferred->result;

        $this->assertIsArray($result);
        $this->assertCount(1, $result, 'aggregate_config should resolve to a single option group');
        $this->assertSame((string)__('Includes'), $result[0]['label']);

        $qtysByName = [];
        foreach ($result[0]['values'] as $value) {
            $qtysByName[$value['product_name']] = (float)$value['quantity'];
        }
        $expected = [
            $this->fixtures->get('child1')->getName() => 2.0,
            $this->fixtures->get('child2')->getName() => 3.0,
        ];
        ksort($expected);
        ksort($qtysByName);
        $this->assertSame($expected, $qtysByName, 'values must mirror the aggregate_config child name/qty pairs');
    }

    private function getAggregateParentItem(OrderInterface $order): ?OrderItemInterface
    {
        foreach ($order->getItems() as $item) {
            if ($item->getProductType() === Aggregate::TYPE_CODE && !$item->getParentItemId()) {
                return $item;
            }
        }

        return null;
    }
}
