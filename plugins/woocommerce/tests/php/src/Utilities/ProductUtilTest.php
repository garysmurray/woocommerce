<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Utilities;

use Automattic\WooCommerce\Enums\ProductStatus;
use Automattic\WooCommerce\Utilities\ProductUtil;

/**
 * A collection of tests for ProductUtil.
 */
class ProductUtilTest extends \WC_Unit_Test_Case {

	/**
	 * @testdox `get_count_for_type` returns per-status counts for the given product type.
	 */
	public function test_get_count_for_type_returns_per_status_counts(): void {
		$before = ProductUtil::get_count_for_type( 'product' );

		$published = \WC_Helper_Product::create_simple_product();
		$draft     = \WC_Helper_Product::create_simple_product( true, array( 'status' => ProductStatus::DRAFT ) );
		$pending   = \WC_Helper_Product::create_simple_product( true, array( 'status' => ProductStatus::PENDING ) );

		$after = ProductUtil::get_count_for_type( 'product' );

		$this->assertSame( ( $before[ ProductStatus::PUBLISH ] ?? 0 ) + 1, $after[ ProductStatus::PUBLISH ] );
		$this->assertSame( ( $before[ ProductStatus::DRAFT ] ?? 0 ) + 1, $after[ ProductStatus::DRAFT ] );
		$this->assertSame( ( $before[ ProductStatus::PENDING ] ?? 0 ) + 1, $after[ ProductStatus::PENDING ] );

		$published->delete( true );
		$draft->delete( true );
		$pending->delete( true );
	}
}
