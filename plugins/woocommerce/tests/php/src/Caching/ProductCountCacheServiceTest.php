<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Caching;

use WC_Helper_Product;
use WC_Product_Simple;
use Automattic\WooCommerce\Caches\ProductCountCache;
use Automattic\WooCommerce\Caches\ProductCountCacheService;
use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Utilities\ProductUtil;

/**
 * Class ProductCountCacheServiceTest.
 */
final class ProductCountCacheServiceTest extends \WC_Unit_Test_Case {

	/**
	 * ProductCountCache instance.
	 *
	 * @var ProductCountCache
	 */
	private ProductCountCache $product_cache;

	/**
	 * Setup test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->product_cache = new ProductCountCache();
		$this->product_cache->flush();
	}

	/**
	 * Test that count gets incremented on new products.
	 */
	public function test_count_incremented_on_product_create(): void {
		$initial_count = ProductUtil::get_count_for_type( 'product' )[ ProductStatus::PUBLISH ];

		$product = WC_Helper_Product::create_simple_product();
		$product->set_status( ProductStatus::PUBLISH );
		$product->save();

		$counts = ProductUtil::get_count_for_type( 'product' );

		$this->assertSame( $initial_count + 1, $counts[ ProductStatus::PUBLISH ] );
	}

	/**
	 * Test that product count gets reduced when product is deleted.
	 */
	public function test_count_decremented_on_product_delete(): void {
		$initial_count = ProductUtil::get_count_for_type( 'product' );

		$product = WC_Helper_Product::create_simple_product();
		$product->set_status( ProductStatus::PUBLISH );
		$product->save();
		$product->delete( true );

		$counts = ProductUtil::get_count_for_type( 'product' );

		$this->assertSame( $initial_count[ ProductStatus::PUBLISH ], $counts[ ProductStatus::PUBLISH ] );
	}

	/**
	 * Test that product counts get updated respectively when changing a product status.
	 */
	public function test_count_on_product_status_change(): void {
		$product = WC_Helper_Product::create_simple_product();
		$product->set_status( ProductStatus::PUBLISH );
		$product->save();

		$initial_count = ProductUtil::get_count_for_type( 'product' );

		$product->set_status( ProductStatus::DRAFT );
		$product->save();

		$count = ProductUtil::get_count_for_type( 'product' );

		$this->assertSame( $initial_count[ ProductStatus::PUBLISH ] - 1, $count[ ProductStatus::PUBLISH ] );
		$this->assertSame( $initial_count[ ProductStatus::DRAFT ] + 1, $count[ ProductStatus::DRAFT ] );
	}

	/**
	 * Test that count gets incremented on new products with initial status and does not incorrectly decrement the publish count.
	 */
	public function test_count_on_new_product_with_initial_status(): void {
		$initial_count = ProductUtil::get_count_for_type( 'product' );

		WC_Helper_Product::create_simple_product( true, array( 'status' => ProductStatus::PENDING ) );

		$count = ProductUtil::get_count_for_type( 'product' );

		$this->assertSame( $initial_count[ ProductStatus::PUBLISH ], $count[ ProductStatus::PUBLISH ] );
		$this->assertSame( $initial_count[ ProductStatus::PENDING ] + 1, $count[ ProductStatus::PENDING ] );
	}

	/**
	 * Test that count works when status change hook is triggered on new products.
	 */
	public function test_count_on_new_product_with_status_change(): void {
		$initial_count = ProductUtil::get_count_for_type( 'product' );

		$product = new WC_Product_Simple();
		$product->set_status( ProductStatus::DRAFT );
		$product->save();

		$count = ProductUtil::get_count_for_type( 'product' );

		$this->assertSame( $initial_count[ ProductStatus::DRAFT ] + 1, $count[ ProductStatus::DRAFT ] );
		$this->assertSame( $initial_count[ ProductStatus::PUBLISH ], $count[ ProductStatus::PUBLISH ] );
	}

	/**
	 * Test that count works when status change hook is triggered on multiple status changes.
	 */
	public function test_count_on_multiple_status_changes(): void {
		$initial_count = ProductUtil::get_count_for_type( 'product' );

		$product = new WC_Product_Simple();
		$product->set_status( ProductStatus::PUBLISH );
		$product->set_status( ProductStatus::PENDING );
		$product->save();

		$count = ProductUtil::get_count_for_type( 'product' );

		$this->assertSame( $initial_count[ ProductStatus::PUBLISH ], $count[ ProductStatus::PUBLISH ] );
		$this->assertSame( $initial_count[ ProductStatus::DRAFT ], $count[ ProductStatus::DRAFT ] );
		$this->assertSame( $initial_count[ ProductStatus::PENDING ] + 1, $count[ ProductStatus::PENDING ] );
	}

	/**
	 * Test that background actions are scheduled.
	 */
	public function test_background_actions_scheduled(): void {
		$product_count_cache_service = wc_get_container()->get( ProductCountCacheService::class );
		$product_count_cache_service->schedule_background_actions();
		$this->assertTrue( as_has_scheduled_action( 'woocommerce_refresh_product_count_cache' ) );
	}

	/**
	 * Test prime_cache_if_cold method: engaging when the cache is cold.
	 */
	public function test_prime_cache_if_cold_when_cache_is_cold(): void {
		global $_wp_using_ext_object_cache;
		$_before                    = $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->product_cache->flush();
		$this->assertNull( $this->product_cache->get( 'product', array( ProductStatus::PUBLISH ) ) );

		// We expect the cache to be populated with the relevant values.
		$product_count_cache_service = wc_get_container()->get( ProductCountCacheService::class );
		$product_count_cache_service->prime_cache_if_cold( 'product' );

		$cached = $this->product_cache->get( 'product', array( ProductStatus::PUBLISH ) );
		$this->assertNotNull( $cached );
		$this->assertArrayHasKey( ProductStatus::PUBLISH, $cached );

		$_wp_using_ext_object_cache = $_before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Test prime_cache_if_cold method: not engaging when cache is warm.
	 */
	public function test_prime_cache_if_cold_when_cache_is_warm(): void {
		global $_wp_using_ext_object_cache;
		$_before                    = $_wp_using_ext_object_cache;
		$_wp_using_ext_object_cache = true; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$publish_count = ProductUtil::get_count_for_type( 'product' )[ ProductStatus::PUBLISH ];
		$this->product_cache->set( 'product', ProductStatus::PUBLISH, $publish_count + 10 );

		// We expect the cached values to remain same as counting skipped for warm caches.
		$product_count_cache_service = wc_get_container()->get( ProductCountCacheService::class );
		$product_count_cache_service->prime_cache_if_cold( 'product' );

		$this->assertSame( $publish_count + 10, $this->product_cache->get( 'product', array( ProductStatus::PUBLISH ) )[ ProductStatus::PUBLISH ] );

		$_wp_using_ext_object_cache = $_before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Test prime_cache_if_cold method: not engaging when no object caching plugins are in use.
	 */
	public function test_prime_cache_if_cold_when_object_cache_unavailable(): void {
		$this->product_cache->flush();
		$this->assertNull( $this->product_cache->get( 'product', array( ProductStatus::PUBLISH ) ) );

		// We expect the cache to remain unpopulated as object caching is unavailable.
		$product_count_cache_service = wc_get_container()->get( ProductCountCacheService::class );
		$product_count_cache_service->prime_cache_if_cold( 'product' );

		$this->assertNull( $this->product_cache->get( 'product', array( ProductStatus::PUBLISH ) ) );
	}
}
