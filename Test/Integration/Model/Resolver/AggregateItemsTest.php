<?php
declare(strict_types=1);
/**
 * Copyright © Nfourteen. All Rights Reserved.
 * See COPYING.txt for license details.
 **/

namespace Nfourteen\AggregateProductGraphQl\Test\Integration\Model\Resolver;

use Laminas\Http\Headers;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\GraphQl\Controller\GraphQl;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Helper\Bootstrap;
use Nfourteen\AggregateProduct\Test\Fixture\AggregateProduct as AggregateProductFixture;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real GraphQL endpoint so the whole module wiring is on the asserted path
 * (schema registration, type resolver, batch resolver). The route query is used instead
 * of products(filter) to keep the test independent of the search engine.
 */
#[
    AppArea('graphql'),
]
class AggregateItemsTest extends TestCase
{
    private ?ObjectManagerInterface $objectManager = null;
    private ?GraphQl $graphqlController = null;
    private ?SerializerInterface $jsonSerializer = null;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->graphqlController = $this->objectManager->get(GraphQl::class);
        $this->jsonSerializer = $this->objectManager->get(SerializerInterface::class);
    }

    #[
        DataFixture(ProductFixture::class, ['sku' => 'agg-gql-child-1'], as: 'child1'),
        DataFixture(ProductFixture::class, ['sku' => 'agg-gql-child-2'], as: 'child2'),
        DataFixture(AggregateProductFixture::class, [
            'sku' => 'agg-gql-items',
            '_children' => [
                ['product_id' => '$child1.id$', 'qty' => 2],
                ['product_id' => '$child2.id$', 'qty' => 3],
            ],
        ], as: 'aggregate'),
    ]
    public function testRouteQueryResolvesAggregateChildrenWithQty(): void
    {
        $product = $this->objectManager->get(ProductRepositoryInterface::class)->get('agg-gql-items');
        $suffix = (string)$this->objectManager->get(ScopeConfigInterface::class)
            ->getValue('catalog/seo/product_url_suffix', ScopeInterface::SCOPE_STORE);

        $query = sprintf(
            <<<'QUERY'
{
    route(url: "%s") {
        __typename
        ... on AggregateProduct {
            sku
            items {
                qty
                product {
                    sku
                }
            }
        }
    }
}
QUERY,
            $product->getUrlKey() . $suffix
        );

        $output = $this->dispatchQuery($query);

        $this->assertArrayNotHasKey(
            'errors',
            $output,
            'GraphQL response must not contain errors: ' . json_encode($output['errors'] ?? [])
        );
        $route = $output['data']['route'] ?? null;
        $this->assertNotNull($route, 'route query should resolve the aggregate product URL');

        $this->assertSame(
            'AggregateProduct',
            $route['__typename'],
            'type resolver must map type_id=aggregate to the AggregateProduct GraphQL type'
        );
        $this->assertSame('agg-gql-items', $route['sku']);

        $qtyByChildSku = [];
        foreach ($route['items'] as $item) {
            $qtyByChildSku[$item['product']['sku']] = (float)$item['qty'];
        }
        ksort($qtyByChildSku);
        $this->assertSame(
            [
                'agg-gql-child-1' => 2.0,
                'agg-gql-child-2' => 3.0,
            ],
            $qtyByChildSku,
            'items field must expose exactly the linked children with their link quantities'
        );
    }

    private function dispatchQuery(string $query): array
    {
        /** @var Http $request */
        $request = $this->objectManager->create(Http::class);
        $request->setPathInfo('/graphql');
        $request->setMethod('POST');
        $request->setContent(json_encode(['query' => $query, 'variables' => null, 'operationName' => null]));
        $request->setHeaders(
            $this->objectManager->create(Headers::class)->addHeaders(['Content-Type' => 'application/json'])
        );

        $response = $this->graphqlController->dispatch($request);

        return $this->jsonSerializer->unserialize($response->getContent());
    }
}
