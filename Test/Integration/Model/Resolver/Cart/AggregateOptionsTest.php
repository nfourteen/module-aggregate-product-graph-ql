<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\AggregateProductGraphQl\Test\Integration\Model\Resolver\Cart;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Test\Fixture\AddProductToCart as AddProductToCartFixture;
use Magento\Quote\Test\Fixture\GuestCart as GuestCartFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorage;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Api\LinkedProductProviderInterface;
use Nfourteen\AggregateProduct\Api\RelationMetadataRepositoryInterface;
use Nfourteen\AggregateProduct\Model\Product\Type\Aggregate;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use Nfourteen\AggregateProductGraphQl\Model\Cart\AggregateOptionDataProvider;
use Nfourteen\AggregateProductGraphQl\Model\Resolver\Cart\AggregateOptions;
use PHPUnit\Framework\TestCase;

/**
 * The cart "Includes" GraphQL resolver must read the purchase-time snapshot stored on the quote
 * item, not live relations. A later relation edit/delete must not change what an in-cart customer
 * sees and must not surface a NoSuchEntityException into the cart render. Items added before
 * snapshots existed fall back to live relations, guarded so a missing relation set degrades to an
 * empty option instead of throwing.
 */
class AggregateOptionsTest extends TestCase
{
    private ?ObjectManagerInterface $objectManager = null;
    private ?AggregateOptions $resolver = null;
    private ?CartRepositoryInterface $cartRepository = null;
    private ?ProductRepositoryInterface $productRepository = null;
    private ?RelationMetadataRepositoryInterface $relationRepository = null;
    private ?DataFixtureStorage $fixtures = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        // Construct the resolver directly: fetching it through DI returns an interceptor whose
        // unrelated reCaptcha GraphQL plugin dereferences ResolveInfo::$operation, which a bare
        // resolver call has no need to populate.
        $this->resolver = new AggregateOptions(
            new AggregateOptionDataProvider(
                $this->objectManager->get(LinkedProductProviderInterface::class),
                $this->objectManager->get(Json::class)
            )
        );
        $this->cartRepository = $this->objectManager->get(CartRepositoryInterface::class);
        $this->productRepository = $this->objectManager->create(ProductRepositoryInterface::class);
        $this->relationRepository = $this->objectManager->get(RelationMetadataRepositoryInterface::class);
        $this->fixtures = DataFixtureStorageManager::getStorage();
    }

    #[
        DataFixture(ProductFixture::class, as: 'child1'),
        DataFixture(ProductFixture::class, as: 'child2'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-cart-snapshot',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 2],
                ['product_id' => '$child2.id$', 'qty' => 3],
            ],
        ], as: 'aggregate'),
        DataFixture(GuestCartFixture::class, as: 'cart'),
        DataFixture(AddProductToCartFixture::class, ['cart_id' => '$cart.id$', 'product_id' => '$aggregate.id$', 'qty' => 1]),
    ]
    public function testResolvesSnapshotAndSurvivesRelationDeletion(): void
    {
        $quoteItem = $this->getAggregateParentQuoteItem();
        $this->assertNotNull(
            $quoteItem->getOptionByCode(Aggregate::SNAPSHOT_OPTION),
            'add-to-cart should persist the children snapshot on the quote item'
        );

        $before = $this->resolver->resolve($this->field(), null, $this->info(), ['model' => $quoteItem]);
        $this->assertIncludes([2.0, 3.0], $before);

        // Delete every relation through the real repository path.
        $parentId = (int)$quoteItem->getProduct()->getId();
        $this->relationRepository->delete($this->relationRepository->getByParentId($parentId));
        $this->assertEmpty($this->relationRepository->getByParentId($parentId));

        $after = $this->resolver->resolve($this->field(), null, $this->info(), ['model' => $quoteItem]);
        $this->assertIncludes([2.0, 3.0], $after, 'snapshot still drives the resolver after deletion');
    }

    #[
        DataFixture(ProductFixture::class, as: 'child1'),
        DataFixture(ProductFixture::class, as: 'child2'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-cart-fallback',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 2],
                ['product_id' => '$child2.id$', 'qty' => 3],
            ],
        ], as: 'aggregate'),
    ]
    public function testFallbackDegradesGracefullyWithoutSnapshot(): void
    {
        $product = $this->productRepository->get('agg-cart-fallback', false, null, true);

        // A synthetic quote item with no snapshot option mirrors a pre-snapshot legacy line.
        /** @var QuoteItem $quoteItem */
        $quoteItem = $this->objectManager->create(QuoteItem::class);
        $quoteItem->setProduct($product);

        $before = $this->resolver->resolve($this->field(), null, $this->info(), ['model' => $quoteItem]);
        $this->assertIncludes([2.0, 3.0], $before, 'live relations feed the fallback while present');

        $this->relationRepository->delete($this->relationRepository->getByParentId((int)$product->getId()));

        $after = $this->resolver->resolve($this->field(), null, $this->info(), ['model' => $quoteItem]);
        $this->assertSame([], $after, 'no relations and no snapshot degrades to an empty option, no throw');
    }

    /**
     * @param float[] $expectedQtys
     * @param mixed $result
     */
    private function assertIncludes(array $expectedQtys, $result, string $message = ''): void
    {
        $this->assertIsArray($result);
        $this->assertCount(1, $result, $message);
        $this->assertSame((string)__('Includes'), $result[0]['label']);
        $qtys = array_map(static fn ($v) => (float)$v['quantity'], $result[0]['values']);
        $this->assertEqualsCanonicalizing($expectedQtys, $qtys, $message);
    }

    private function getAggregateParentQuoteItem(): QuoteItem
    {
        /** @var Quote $quote */
        $quote = $this->cartRepository->get((int)$this->fixtures->get('cart')->getId());
        foreach ($quote->getAllItems() as $item) {
            if ($item->getProductType() === Aggregate::TYPE_CODE && !$item->getParentItemId()) {
                return $item;
            }
        }
        $this->fail('Aggregate parent quote item not found');
    }

    private function field(): Field
    {
        return $this->createMock(Field::class);
    }

    private function info(): ResolveInfo
    {
        return $this->createMock(ResolveInfo::class);
    }
}
