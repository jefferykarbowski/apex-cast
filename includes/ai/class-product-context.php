<?php
/**
 * Product context value object.
 *
 * @package ApexChute\ApexCast\AI
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\AI;

/**
 * Immutable snapshot of the WooCommerce product fields the AI provider needs.
 *
 * Built server-side from canonical WooCommerce data so that nothing the
 * browser sends can influence the generation prompt.
 */
final class ProductContext {

	/**
	 * Constructor.
	 *
	 * @param int      $product_id          WooCommerce product ID.
	 * @param string   $title               Product title.
	 * @param string   $permalink           Public product URL.
	 * @param string   $short_description   Product short description (plain text).
	 * @param string   $description_excerpt Truncated full description (plain text).
	 * @param string   $price               Formatted price string.
	 * @param string[] $categories          Product category names.
	 * @param string[] $tags                Product tag names.
	 * @param string   $stock_status        WooCommerce stock status slug.
	 * @param string   $featured_image      Featured image URL (may be empty).
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly string $title,
		public readonly string $permalink,
		public readonly string $short_description,
		public readonly string $description_excerpt,
		public readonly string $price,
		public readonly array $categories,
		public readonly array $tags,
		public readonly string $stock_status,
		public readonly string $featured_image
	) {}

	/**
	 * Export as a plain associative array for prompt assembly.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'product_id'          => $this->product_id,
			'title'               => $this->title,
			'permalink'           => $this->permalink,
			'short_description'   => $this->short_description,
			'description_excerpt' => $this->description_excerpt,
			'price'               => $this->price,
			'categories'          => $this->categories,
			'tags'                => $this->tags,
			'stock_status'        => $this->stock_status,
			'featured_image'      => $this->featured_image,
		);
	}
}
