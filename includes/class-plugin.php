<?php
/**
 * Plugin singleton.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

use ApexChute\ApexCast\AI\AIProviderInterface;
use ApexChute\ApexCast\Adapters\BackendAdapterInterface;
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
	 * Lazy AI provider factory.
	 *
	 * @var ProviderFactory|null
	 */
	private ?ProviderFactory $provider_factory = null;

	/**
	 * Lazy backend adapter factory.
	 *
	 * @var AdapterFactory|null
	 */
	private ?AdapterFactory $adapter_factory = null;

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
	 * Build (or null) the currently-configured AI provider.
	 *
	 * Returns null when no provider is configured (no API key, unknown id, etc.).
	 *
	 * @return AIProviderInterface|null
	 */
	public function ai_provider(): ?AIProviderInterface {
		if ( null === $this->provider_factory ) {
			$this->provider_factory = new ProviderFactory( $this->settings() );
		}
		return $this->provider_factory->create();
	}

	/**
	 * Build (or null) the currently-configured backend adapter.
	 *
	 * @return BackendAdapterInterface|null
	 */
	public function backend_adapter(): ?BackendAdapterInterface {
		if ( null === $this->adapter_factory ) {
			$this->adapter_factory = new AdapterFactory( $this->settings() );
		}
		return $this->adapter_factory->create();
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
}
