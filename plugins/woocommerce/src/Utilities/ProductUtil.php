<?php
/**
 * A class of utilities related to products.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Utilities;

/**
 * A class of utilities related to products.
 */
final class ProductUtil {
	/**
	 * Counts per-status number of products of specified type.
	 *
	 * @since 11.0.0
	 *
	 * @param string $product_type Product type.
	 * @return array<string,int>
	 */
	public static function get_count_for_type( string $product_type ): array {
		// Performance note: integration point for upcoming persistent counters solution.
		$count_per_status = (array) wp_count_posts( $product_type );

		return array_map( 'intval', $count_per_status );
	}
}
