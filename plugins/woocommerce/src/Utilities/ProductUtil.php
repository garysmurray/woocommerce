<?php
/**
 * A class of utilities related to products.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Utilities;

use Automattic\WooCommerce\Caches\ProductCountCache;

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
		$product_count_cache = new ProductCountCache();
		$count_per_status    = $product_count_cache->get( $product_type );

		if ( null === $count_per_status ) {
			$count_per_status = (array) wp_count_posts( $product_type );

			// Make sure all post statuses are included just in case.
			$count_per_status = array_merge(
				array_fill_keys( array_keys( get_post_stati() ), 0 ),
				$count_per_status
			);

			$product_count_cache->set_multiple( $product_type, $count_per_status );
		}

		return array_map( 'intval', $count_per_status );
	}
}
