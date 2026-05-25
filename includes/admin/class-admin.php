<?php
/**
 * Admin: settings page, product-editor metabox, asset enqueue.
 *
 * @package ApexChute\ApexCast\Admin
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Admin;

use ApexChute\ApexCast\Plugin;
use ApexChute\ApexCast\Publishers\PinterestBoardService;
use ApexChute\ApexCast\Publishers\PublisherException;
use WP_Post;

/**
 * Wires the admin-only surfaces: the Settings → Apex Cast page, the product-
 * editor metabox, and the asset enqueue for the React apps that render each.
 *
 * The build pipeline (`@wordpress/scripts build`) emits one JS bundle per
 * entry plus a sibling `.asset.php` file listing the WP package dependencies
 * and a content-hash version string — we include that file at enqueue time so
 * caching and dependency ordering are correct.
 */
final class Admin {

	private const SETTINGS_PAGE_SLUG = 'apex-cast-settings';

	/**
	 * Register every admin-side hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'add_meta_boxes_product', array( $this, 'register_metabox' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register the Settings → Apex Cast page.
	 *
	 * @return void
	 */
	public function register_settings_page(): void {
		add_options_page(
			'Apex Cast',
			'Apex Cast',
			'manage_woocommerce',
			self::SETTINGS_PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render the settings page shell. React mounts into the empty div.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Apex Cast', 'apex-cast' ) . '</h1>';
		echo '<div id="apex-cast-settings-root"></div></div>';
	}

	/**
	 * Register the product-editor metabox.
	 *
	 * @return void
	 */
	public function register_metabox(): void {
		add_meta_box(
			'apex-cast-metabox',
			__( 'Apex Cast', 'apex-cast' ),
			array( $this, 'render_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Render the metabox shell. React mounts into the empty div.
	 *
	 * @param WP_Post $post Current product post.
	 * @return void
	 */
	public function render_metabox( WP_Post $post ): void {
		echo '<div id="apex-cast-metabox-root" data-product-id="' . esc_attr( (string) $post->ID ) . '"></div>';
	}

	/**
	 * Enqueue the appropriate React bundle on either the settings page or the
	 * product editor; do nothing on unrelated admin screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		$base_path = APEX_CAST_PATH . 'assets/build/';
		$base_url  = APEX_CAST_URL . 'assets/build/';

		if ( 'settings_page_' . self::SETTINGS_PAGE_SLUG === $hook ) {
			$this->enqueue_entry( 'settings', $base_path, $base_url );
			wp_localize_script(
				'apex-cast-settings',
				'APEX_CAST_SETTINGS_DATA',
				array(
					'restUrl'   => esc_url_raw( rest_url( 'apex-cast/v1' ) ),
					'nonce'     => wp_create_nonce( 'wp_rest' ),
					'platforms' => array_keys( Plugin::instance()->publisher_registry()->all() ),
				)
			);
			return;
		}

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'product' !== $screen->post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading the standard wp-admin post ID query var for display only; no state changes.
		$product_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;

		$last_sent_at = $product_id > 0 ? (int) get_post_meta( $product_id, '_apex_cast_last_sent_at', true ) : 0;
		$last_job_id  = $product_id > 0 ? (int) get_post_meta( $product_id, '_apex_cast_last_job_id', true ) : 0;

		$defaults_raw = Plugin::instance()->settings()->get( 'store.default_platforms', array() );
		$defaults     = is_array( $defaults_raw ) ? array_values( array_filter( array_map( 'strval', $defaults_raw ) ) ) : array();

		$plugin               = Plugin::instance();
		$configured_platforms = $plugin->publisher_registry()->configured_platforms();
		$supported_platforms  = array_keys( $plugin->publisher_registry()->all() );

		// Build the product preview the metabox shows — what *will* be sent.
		$short_description = '';
		$featured_image    = '';
		$tags              = array();
		$tag_slugs         = array();
		if ( $product_id > 0 ) {
			$context = $plugin->product_context_builder()->build( $product_id );
			if ( null !== $context ) {
				$short_description = '' !== trim( $context->short_description ) ? $context->short_description : $context->title;
				$featured_image    = $context->featured_image;
				$tags              = $context->tags;
				$tag_slugs         = $context->tag_slugs;
			}
		}

		$resolved_board_name = $this->resolve_pinterest_board_name( $tag_slugs );

		$this->enqueue_entry( 'metabox', $base_path, $base_url );
		wp_localize_script(
			'apex-cast-metabox',
			'APEX_CAST_DATA',
			array(
				'restUrl'                    => esc_url_raw( rest_url( 'apex-cast/v1' ) ),
				'nonce'                      => wp_create_nonce( 'wp_rest' ),
				'productId'                  => $product_id,
				'shortDescription'           => $short_description,
				'featuredImage'              => $featured_image,
				'tags'                       => $tags,
				'tagSlugs'                   => $tag_slugs,
				'lastSentAt'                 => $last_sent_at,
				'lastJobId'                  => $last_job_id,
				'defaultPlatforms'           => $defaults,
				'configuredPlatforms'        => $configured_platforms,
				'supportedPlatforms'         => $supported_platforms,
				'pinterestResolvedBoardName' => $resolved_board_name,
			)
		);
	}

	/**
	 * Replicate the publisher's tag→board resolution server-side to compute the
	 * human-readable name the metabox shows in its preview.
	 *
	 * Walks the given tag slugs through `platforms.pinterest.tag_board_map`.
	 * First match wins; on miss, falls back to the default board id. We then
	 * look up the matching name in the cached Pinterest boards list (TTL 300s
	 * via `wp_cache_*`) so a fresh metabox render doesn't beat the API.
	 *
	 * @param string[] $tag_slugs Tag slugs in priority order (from ProductContext).
	 * @return string Human-readable board name, or empty string when nothing resolves.
	 */
	private function resolve_pinterest_board_name( array $tag_slugs ): string {
		$settings_store = Plugin::instance()->settings();

		$tag_board_map_raw = $settings_store->get( 'platforms.pinterest.tag_board_map', array() );
		$tag_board_map     = is_array( $tag_board_map_raw ) ? $tag_board_map_raw : array();
		$default_board_id  = (string) $settings_store->get( 'platforms.pinterest.board_id', '' );

		$resolved_id = '';
		foreach ( $tag_slugs as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}
			if ( isset( $tag_board_map[ $slug ] ) && is_string( $tag_board_map[ $slug ] ) && '' !== $tag_board_map[ $slug ] ) {
				$resolved_id = (string) $tag_board_map[ $slug ];
				break;
			}
		}
		if ( '' === $resolved_id ) {
			$resolved_id = $default_board_id;
		}
		if ( '' === $resolved_id ) {
			return '';
		}

		$access_token = $settings_store->get_secret( 'platforms.pinterest.access_token_encrypted' );
		if ( '' === $access_token ) {
			return '';
		}

		$api_mode = (string) $settings_store->get( 'platforms.pinterest.api_mode', 'production' );

		$boards = wp_cache_get( 'apex_cast_pinterest_boards', 'apex-cast' );
		if ( ! is_array( $boards ) ) {
			try {
				$service = new PinterestBoardService( $access_token, $api_mode );
				$boards  = $service->list_boards();
				wp_cache_set( 'apex_cast_pinterest_boards', $boards, 'apex-cast', 300 );
			} catch ( PublisherException $e ) {
				return '';
			}
		}

		foreach ( $boards as $board ) {
			if ( is_array( $board ) && isset( $board['id'], $board['name'] ) && (string) $board['id'] === $resolved_id ) {
				return (string) $board['name'];
			}
		}

		return '';
	}

	/**
	 * Enqueue a single wp-scripts-built entry (JS + CSS) using its sibling
	 * `.asset.php` for dependency + version metadata.
	 *
	 * Silently skips when the build output doesn't exist — happens during
	 * fresh-clone CI runs that haven't built assets yet, or in development
	 * before `npm run build`.
	 *
	 * @param string $entry     Entry name (matches the JSX filename without extension).
	 * @param string $base_path Filesystem base path to the build output directory.
	 * @param string $base_url  URL base for the build output directory.
	 * @return void
	 */
	private function enqueue_entry( string $entry, string $base_path, string $base_url ): void {
		// wp-scripts preserves the source extension in the output filename
		// (e.g. `metabox.jsx` -> `metabox.jsx.js`), so the on-disk basename for
		// every artifact is the entry name with `.jsx` already appended.
		$basename = $entry . '.jsx';

		$asset_file = $base_path . $basename . '.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions, WordPressVIPMinimum.Files.IncludingFile, WordPress.Files.FileName -- Including the wp-scripts-emitted dependency manifest, which is a deterministic build artifact.
		$asset = include $asset_file;
		if ( ! is_array( $asset ) ) {
			return;
		}

		$deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version = isset( $asset['version'] ) ? (string) $asset['version'] : APEX_CAST_VERSION;

		wp_enqueue_script(
			'apex-cast-' . $entry,
			$base_url . $basename . '.js',
			$deps,
			$version,
			true
		);

		$css_path = $base_path . $basename . '.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'apex-cast-' . $entry,
				$base_url . $basename . '.css',
				array(),
				$version
			);
		}
	}
}
