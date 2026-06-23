<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Caches;

use WC_Product;
use WP_Post;

/**
 * A service class to help with updates to the aggregate product counts cache.
 *
 * @internal
 */
class ProductCountCacheService {

	const BACKGROUND_EVENT_HOOK = 'woocommerce_refresh_product_count_cache';

	/**
	 * ProductCountCache instance.
	 *
	 * @var ProductCountCache
	 */
	private ProductCountCache $product_count_cache;

	/**
	 * Array of product IDs with their last transitioned status as key value pairs.
	 * Guarantees idempotency for product status transitions when multiple hooks fire for the same product.
	 *
	 * @var array<int, string>
	 */
	private array $product_statuses = array();

	/**
	 * Array of product IDs with their initial status as key value pairs.
	 * Guarantees idempotency for product status transitions when multiple hooks fire for the same product.
	 *
	 * @var array<int, string>
	 */
	private array $initial_product_statuses = array();

	/**
	 * Class initialization, invoked by the DI container.
	 */
	final public function init(): void {
		$this->product_count_cache = new ProductCountCache();

		// Scheduling: keep the cache warm via Action Scheduler.
		add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'schedule_background_actions' ) );
		add_action( self::BACKGROUND_EVENT_HOOK, array( $this, 'prime_cache_if_cold' ) );
		if ( defined( 'WC_PLUGIN_BASENAME' ) ) {
			add_action( 'deactivate_' . WC_PLUGIN_BASENAME, array( $this, 'unschedule_background_actions' ) );
		}

		// WooCommerce product hooks: complement transition_post_status for brand-new products whose
		// old_status is 'new' (never cached) and would otherwise miss the initial count increment.
		// Not needed: woocommerce_update_product (status changes go through transition_post_status),
		// woocommerce_trash_product (fires after wp_trash_post which already fires transition_post_status),
		// woocommerce_before_delete_product (fires before wp_delete_post which fires before_delete_post),
		// woocommerce_delete_product (fires after deletion — post gone, status unreadable).
		add_action( 'woocommerce_new_product', array( $this, 'update_on_new_product' ), 10, 2 );

		// WordPress post hooks: cover all status changes and permanent deletions regardless of whether
		// they originate from the WC product API or direct WP post operations.
		// Not needed: trashed_post/untrashed_post (both fire after transition_post_status — already covered),
		// save_post_product (status changes fire transition_post_status within wp_insert_post — covered),
		// deleted_post (fires after deletion — post gone, status unreadable; before_delete_post is used instead).
		add_action( 'transition_post_status', array( $this, 'update_on_product_status_changed' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'update_on_product_deleted' ), 10, 2 );
	}

	/**
	 * Keeps the cache warm for a specific product type to maintain admin performance, especially after extended
	 * periods of inactivity or when the cache has been cleared.
	 *
	 * @param string $product_type The product post type.
	 * @return void
	 */
	public function prime_cache_if_cold( string $product_type = 'product' ): void {
		// Cache warm-up is only effective when an object cache plugin is active, and the cache entry is missing.
		if ( wp_using_ext_object_cache() && null === $this->product_count_cache->get( $product_type ) ) {
			$this->product_count_cache->flush( $product_type );
			// TBD: \Automattic\WooCommerce\Utilities\ProductUtil::get_count_for_type( $product_type );
		}
	}

	/**
	 * Register background caching for each product type.
	 *
	 * @return void
	 */
	public function schedule_background_actions(): void {
		$frequency = HOUR_IN_SECONDS * 12;
		$timestamp = time() + $frequency;
		as_schedule_recurring_action( $timestamp, $frequency, self::BACKGROUND_EVENT_HOOK, array( 'product' ), 'count', true );
	}

	/**
	 * Unschedules background actions.
	 *
	 * @return void
	 */
	public function unschedule_background_actions(): void {
		WC()->queue()->cancel_all( self::BACKGROUND_EVENT_HOOK );
	}

	/**
	 * Update the cache when a new product is created.
	 *
	 * @param int        $product_id Product ID.
	 * @param WC_Product $product    The product.
	 * @return void
	 */
	public function update_on_new_product( int $product_id, WC_Product $product ): void {
		$product_status = $product->get_status();

		if ( ! $this->product_count_cache->is_cached( 'product', $product_status ) ) {
			return;
		}

		// If the status was already transitioned via transition_post_status, restore any errantly
		// decremented initial status (the old_status='new' guard would have prevented decrement,
		// but if a real prior status was decremented, undo it here).
		if ( isset( $this->initial_product_statuses[ $product_id ] ) ) {
			$this->product_count_cache->increment( 'product', $this->initial_product_statuses[ $product_id ] );
		}

		// If this status was already incremented via transition_post_status, skip.
		if ( isset( $this->product_statuses[ $product_id ] ) && $this->product_statuses[ $product_id ] === $product_status ) {
			return;
		}

		$this->product_statuses[ $product_id ] = $product_status;
		$this->product_count_cache->increment( 'product', $product_status );
	}

	/**
	 * Update the cache whenever a product status changes.
	 *
	 * Fires on the WordPress transition_post_status hook. For brand-new posts, WordPress passes
	 * old_status='new', which is never cached, causing an early return; the increment is then
	 * handled by update_on_new_product.
	 *
	 * @param string  $new_status The new post status.
	 * @param string  $old_status The previous post status.
	 * @param WP_Post $post       The post object.
	 * @return void
	 */
	public function update_on_product_status_changed( string $new_status, string $old_status, WP_Post $post ): void {
		if (
			'product' !== $post->post_type ||
			! $this->product_count_cache->is_cached( 'product', $new_status ) ||
			! $this->product_count_cache->is_cached( 'product', $old_status )
		) {
			return;
		}

		$product_id = $post->ID;

		// If the status count has already been incremented for this product, skip.
		if ( isset( $this->product_statuses[ $product_id ] ) && $this->product_statuses[ $product_id ] === $new_status ) {
			return;
		}

		$this->product_statuses[ $product_id ] = $new_status;
		$was_decremented                        = $this->product_count_cache->decrement( 'product', $old_status );
		$this->product_count_cache->increment( 'product', $new_status );

		// Set the initial product status in case this is a new product and the previous status should not be decremented.
		if ( ! isset( $this->initial_product_statuses[ $product_id ] ) && $was_decremented ) {
			$this->initial_product_statuses[ $product_id ] = $old_status;
		}
	}

	/**
	 * Update the cache when a product is permanently deleted.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    The post object.
	 * @return void
	 */
	public function update_on_product_deleted( int $post_id, WP_Post $post ): void {
		$product_status = $post->post_status;
		if ( 'product' === $post->post_type && $this->product_count_cache->is_cached( 'product', $product_status ) ) {
			$this->product_count_cache->decrement( 'product', $product_status );
		}
	}
}
