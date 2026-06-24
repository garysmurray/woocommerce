<?php
declare( strict_types = 1);

namespace Automattic\WooCommerce\Tests\Caching;

use Automattic\WooCommerce\Caches\ProductCountCache;
use Automattic\WooCommerce\Enums\ProductStatus;

/**
 * Class ProductCountCacheTest.
 */
final class ProductCountCacheTest extends \WC_Unit_Test_Case {

	/**
	 * ProductCountCache instance.
	 *
	 * @var ProductCountCache
	 */
	private $product_cache;

	/**
	 * Setup test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->product_cache = new ProductCountCache();
		$this->product_cache->flush( 'product' );
	}

	/**
	 * Test that known and unknown, based on `ProductStatus::get_all()`, statuses and product type can be cached.
	 */
	public function test_cache_product_counts(): void {
		$unregistered_status = 'third-party-unregistered-status';
		$counts              = array(
			ProductStatus::PUBLISH => 5,
			ProductStatus::DRAFT   => 10,
			$unregistered_status   => 20,
		);

		foreach ( $counts as $status => $count ) {
			$this->product_cache->set( 'product', $status, $count );
		}

		$this->assertTrue( $this->product_cache->is_cached( 'product', ProductStatus::PUBLISH ) );
		$this->assertTrue( $this->product_cache->is_cached( 'product', ProductStatus::DRAFT ) );
		$this->assertTrue( $this->product_cache->is_cached( 'product', $unregistered_status ) );

		$this->assertSame( 5, $this->product_cache->get( 'product', array( ProductStatus::PUBLISH ) )[ ProductStatus::PUBLISH ] );
		$this->assertSame( 10, $this->product_cache->get( 'product', array( ProductStatus::DRAFT ) )[ ProductStatus::DRAFT ] );
		$this->assertSame( 20, $this->product_cache->get( 'product', array( $unregistered_status ) )[ $unregistered_status ] );

		// verify when a specific set of statuses isn't requested.
		$this->assertSame( 5, $this->product_cache->get( 'product' )[ ProductStatus::PUBLISH ] );
		$this->assertSame( 10, $this->product_cache->get( 'product' )[ ProductStatus::DRAFT ] );
		$this->assertSame( 20, $this->product_cache->get( 'product' )[ $unregistered_status ] );
	}

	/**
	 * Test that the cache can be flushed.
	 */
	public function test_flush_cache(): void {
		$this->product_cache->set( 'product', ProductStatus::PUBLISH, 5 );
		$this->product_cache->set( 'product', ProductStatus::DRAFT, 10 );
		$this->product_cache->flush( 'product', array( ProductStatus::PUBLISH, ProductStatus::DRAFT ) );
		$this->assertFalse( $this->product_cache->is_cached( 'product', ProductStatus::PUBLISH ) );
		$this->assertFalse( $this->product_cache->is_cached( 'product', ProductStatus::DRAFT ) );
	}

	/**
	 * Test that get returns null when any of the requested statuses has evicted from cache.
	 */
	public function test_get_returns_null_when_any_status_evicted(): void {
		$this->product_cache->set( 'product', ProductStatus::PUBLISH, 5 );
		$this->product_cache->set( 'product', ProductStatus::DRAFT, 10 );

		// Evict one slot; the multi-status query must return null so callers fall back to DB.
		$this->product_cache->flush( 'product', array( ProductStatus::DRAFT ) );

		$this->assertNull( $this->product_cache->get( 'product', array( ProductStatus::PUBLISH, ProductStatus::DRAFT ) ) );
	}

	/**
	 * Test that the cache gets all statuses when no statuses are provided.
	 */
	public function test_cache_gets_all_statuses_when_no_statuses_are_provided(): void {
		foreach ( array_keys( get_post_stati() ) as $status ) {
			$this->product_cache->set( 'product', $status, 5 );
		}

		$cached = $this->product_cache->get( 'product' );
		foreach ( ProductStatus::get_all() as $status ) {
			$this->assertSame( 5, $cached[ $status ] );
		}
	}
}
