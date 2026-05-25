<?php
/**
 * Plugin singleton.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

use ApexChute\ApexCast\Admin\Admin;
use ApexChute\ApexCast\Publishers\FacebookPagePublisher;
use ApexChute\ApexCast\Publishers\InstagramPublisher;
use ApexChute\ApexCast\Publishers\PinterestBoardService;
use ApexChute\ApexCast\Publishers\PinterestPublisher;
use ApexChute\ApexCast\Rest\RestController;

/**
 * Central wiring point for the plugin.
 *
 * Constructs every long-lived helper lazily through dedicated accessors so
 * tests (and Phase-3 admin pages) can reach individual pieces without
 * triggering an avalanche of initialisation.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Lazy encryption helper.
	 *
	 * @var Encryption|null
	 */
	private ?Encryption $encryption = null;

	/**
	 * Lazy settings store.
	 *
	 * @var Settings|null
	 */
	private ?Settings $settings = null;

	/**
	 * Lazy logger.
	 *
	 * @var Logger|null
	 */
	private ?Logger $logger = null;

	/**
	 * Lazy publisher registry. Owns the set of per-platform publishers
	 * (Pinterest, Facebook Page, Instagram).
	 *
	 * @var PublisherRegistry|null
	 */
	private ?PublisherRegistry $publisher_registry = null;

	/**
	 * Lazy product context builder.
	 *
	 * @var ProductContextBuilder|null
	 */
	private ?ProductContextBuilder $product_context_builder = null;

	/**
	 * Lazy job repository.
	 *
	 * @var JobRepository|null
	 */
	private ?JobRepository $job_repository = null;

	/**
	 * Lazy REST controller.
	 *
	 * @var RestController|null
	 */
	private ?RestController $rest_controller = null;

	/**
	 * Lazy admin (settings page + metabox + asset enqueue).
	 *
	 * @var Admin|null
	 */
	private ?Admin $admin = null;

	/**
	 * Private constructor — use Plugin::instance().
	 */
	private function __construct() {}

	/**
	 * Get the shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the hooks the plugin needs.
	 *
	 * Called from the `plugins_loaded` action in apex-cast.php.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		$this->rest_controller()->register();
		$this->admin()->register();
	}

	/**
	 * Load the plugin's translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'apex-cast',
			false,
			dirname( plugin_basename( APEX_CAST_FILE ) ) . '/languages'
		);
	}

	/**
	 * Encryption helper accessor (lazy).
	 *
	 * @return Encryption
	 */
	public function encryption(): Encryption {
		if ( null === $this->encryption ) {
			$this->encryption = new Encryption();
		}
		return $this->encryption;
	}

	/**
	 * Settings store accessor (lazy).
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		if ( null === $this->settings ) {
			$this->settings = new Settings( $this->encryption() );
		}
		return $this->settings;
	}

	/**
	 * Logger accessor (lazy).
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		if ( null === $this->logger ) {
			$this->logger = new Logger();
		}
		return $this->logger;
	}

	/**
	 * Publisher registry accessor (lazy).
	 *
	 * The registry is empty by default. Each phase that lands a new publisher
	 * registers it here (or via the `apex_cast_register_publishers` filter,
	 * which fires on first access).
	 *
	 * @return PublisherRegistry
	 */
	public function publisher_registry(): PublisherRegistry {
		if ( null === $this->publisher_registry ) {
			$this->publisher_registry = new PublisherRegistry();

			// First-party publishers shipped with apex-cast. Each is always
			// registered; whether it's *configured* is the publisher's own
			// judgement based on stored credentials.
			$this->register_pinterest( $this->publisher_registry );
			$this->register_facebook( $this->publisher_registry );
			$this->register_instagram( $this->publisher_registry );

			/**
			 * Fires once per request after first-party publishers are registered,
			 * giving third-party code a chance to add its own.
			 *
			 * @param PublisherRegistry $registry The registry, populated with the
			 *                                    first-party publishers shipped
			 *                                    with apex-cast.
			 */
			do_action( 'apex_cast_register_publishers', $this->publisher_registry );
		}
		return $this->publisher_registry;
	}

	/**
	 * Build and register the Pinterest publisher with the credentials currently
	 * stored in settings. Missing credentials are passed through as empty
	 * strings — the publisher self-reports as unconfigured in that case.
	 *
	 * Wires in the per-tag routing pieces: the saved tag→board map, the
	 * per-tag auto-create flags, an HTTP board service (only when a token is
	 * available; without one auto-create is silently disabled), and a closure
	 * that persists any auto-created mapping back to settings.
	 *
	 * @param PublisherRegistry $registry The registry to populate.
	 * @return void
	 */
	private function register_pinterest( PublisherRegistry $registry ): void {
		$settings_store   = $this->settings();
		$access_token     = $settings_store->get_secret( 'platforms.pinterest.access_token_encrypted' );
		$default_board_id = (string) $settings_store->get( 'platforms.pinterest.board_id', '' );
		$api_mode         = (string) $settings_store->get( 'platforms.pinterest.api_mode', 'production' );

		$raw_map         = $settings_store->get( 'platforms.pinterest.tag_board_map', array() );
		$tag_board_map   = $this->coerce_tag_string_map( is_array( $raw_map ) ? $raw_map : array() );
		$raw_auto        = $settings_store->get( 'platforms.pinterest.tag_auto_create', array() );
		$tag_auto_create = $this->coerce_tag_bool_map( is_array( $raw_auto ) ? $raw_auto : array() );

		$board_service = '' !== $access_token ? new PinterestBoardService( $access_token, $api_mode ) : null;

		// Persist auto-created mappings back into settings so the user sees them
		// in the UI and so subsequent sends skip the create round-trip. Re-read
		// settings inside the closure to avoid clobbering anything that may
		// have changed between publisher construction and the actual send.
		$on_auto_create = function ( string $slug, string $new_board_id ) use ( $settings_store ): void {
			$current = $settings_store->all();
			if ( ! isset( $current['platforms']['pinterest'] ) || ! is_array( $current['platforms']['pinterest'] ) ) {
				$current['platforms']['pinterest'] = array();
			}
			if ( ! isset( $current['platforms']['pinterest']['tag_board_map'] ) || ! is_array( $current['platforms']['pinterest']['tag_board_map'] ) ) {
				$current['platforms']['pinterest']['tag_board_map'] = array();
			}
			$current['platforms']['pinterest']['tag_board_map'][ $slug ] = $new_board_id;
			$settings_store->save( $current );
		};

		$registry->register(
			new PinterestPublisher(
				$default_board_id,
				$tag_board_map,
				$tag_auto_create,
				$board_service,
				$on_auto_create,
				$access_token,
				$api_mode
			)
		);
	}

	/**
	 * Coerce a raw tag→board-id map from settings into a clean string→string array.
	 *
	 * @param array<int|string, mixed> $raw Untrusted map straight out of options.
	 * @return array<string, string>
	 */
	private function coerce_tag_string_map( array $raw ): array {
		$out = array();
		foreach ( $raw as $slug => $value ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}
			$out[ $slug ] = $value;
		}
		return $out;
	}

	/**
	 * Coerce a raw tag→bool map from settings into a clean string→bool array.
	 *
	 * @param array<int|string, mixed> $raw Untrusted map straight out of options.
	 * @return array<string, bool>
	 */
	private function coerce_tag_bool_map( array $raw ): array {
		$out = array();
		foreach ( $raw as $slug => $value ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}
			$out[ $slug ] = (bool) $value;
		}
		return $out;
	}

	/**
	 * Register the FacebookPagePublisher. Page Access Token + Page id are
	 * captured during the Meta OAuth flow; missing credentials → publisher
	 * self-reports as not_configured.
	 *
	 * @param PublisherRegistry $registry The registry to populate.
	 * @return void
	 */
	private function register_facebook( PublisherRegistry $registry ): void {
		$token     = $this->settings()->get_secret( 'platforms.facebook.page_access_token_encrypted' );
		$page_id   = (string) $this->settings()->get( 'platforms.facebook.page_id', '' );
		$page_name = (string) $this->settings()->get( 'platforms.facebook.page_name', '' );
		$registry->register( new FacebookPagePublisher( $token, $page_id, $page_name ) );
	}

	/**
	 * Register the InstagramPublisher. Uses the same Page Access Token as the
	 * Facebook publisher (Meta vends one token per Page that grants both
	 * Page-posting and IG-publishing for the linked account).
	 *
	 * @param PublisherRegistry $registry The registry to populate.
	 * @return void
	 */
	private function register_instagram( PublisherRegistry $registry ): void {
		$token         = $this->settings()->get_secret( 'platforms.instagram.page_access_token_encrypted' );
		$ig_account_id = (string) $this->settings()->get( 'platforms.instagram.ig_business_account_id', '' );
		$username      = (string) $this->settings()->get( 'platforms.instagram.username', '' );
		$registry->register( new InstagramPublisher( $token, $ig_account_id, $username ) );
	}

	/**
	 * Product context builder accessor (lazy).
	 *
	 * @return ProductContextBuilder
	 */
	public function product_context_builder(): ProductContextBuilder {
		if ( null === $this->product_context_builder ) {
			$this->product_context_builder = new ProductContextBuilder();
		}
		return $this->product_context_builder;
	}

	/**
	 * Job repository accessor (lazy).
	 *
	 * @return JobRepository
	 */
	public function job_repository(): JobRepository {
		if ( null === $this->job_repository ) {
			$this->job_repository = new JobRepository();
		}
		return $this->job_repository;
	}

	/**
	 * REST controller accessor (lazy).
	 *
	 * @return RestController
	 */
	public function rest_controller(): RestController {
		if ( null === $this->rest_controller ) {
			$this->rest_controller = new RestController();
		}
		return $this->rest_controller;
	}

	/**
	 * Admin (settings page + metabox + asset enqueue) accessor (lazy).
	 *
	 * @return Admin
	 */
	public function admin(): Admin {
		if ( null === $this->admin ) {
			$this->admin = new Admin();
		}
		return $this->admin;
	}
}
