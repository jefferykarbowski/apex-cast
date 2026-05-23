<?php
/**
 * Product context builder.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

use ApexChute\ApexCast\AI\ProductContext;
use WC_Product;

/**
 * Builds a `ProductContext` value object from a WooCommerce product ID.
 *
 * Lives between the REST controller and the AI provider so the controller
 * cannot pass arbitrary user-supplied product fields into the prompt — only
 * canonical WooCommerce data.
 */
final class ProductContextBuilder {

	private const DESCRIPTION_EXCERPT_LENGTH = 600;

	/**
	 * Build a `ProductContext` for the given product ID.
	 *
	 * @param int $product_id WooCommerce product ID.
	 * @return ProductContext|null Null when the product does not exist.
	 */
	public function build( int $product_id ): ?ProductContext {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$description = wp_strip_all_tags( (string) $product->get_description() );
		$excerpt     = function_exists( 'mb_strimwidth' )
			? (string) mb_strimwidth( $description, 0, self::DESCRIPTION_EXCERPT_LENGTH, '…' )
			: substr( $description, 0, self::DESCRIPTION_EXCERPT_LENGTH );

		$featured_image = '';
		$image_id       = (int) $product->get_image_id();
		if ( $image_id > 0 ) {
			$src = wp_get_attachment_image_url( $image_id, 'large' );
			if ( is_string( $src ) ) {
				$featured_image = $src;
			}
		}

		return new ProductContext(
			(int) $product->get_id(),
			(string) $product->get_name(),
			(string) get_permalink( $product->get_id() ),
			wp_strip_all_tags( (string) $product->get_short_description() ),
			$excerpt,
			wp_strip_all_tags( (string) wc_price( (float) $product->get_price() ) ),
			$this->terms_to_names( (int) $product->get_id(), 'product_cat' ),
			$this->terms_to_names( (int) $product->get_id(), 'product_tag' ),
			(string) $product->get_stock_status(),
			$featured_image
		);
	}

	/**
	 * Convert a taxonomy's terms (for a product) to plain string names.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $taxonomy   Taxonomy slug.
	 * @return string[]
	 */
	private function terms_to_names( int $product_id, string $taxonomy ): array {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$names = array();
		foreach ( $terms as $term ) {
			$names[] = (string) $term->name;
		}
		return $names;
	}
}
