<?php
/**
 * Plugin singleton.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

/**
 * Central wiring point for the plugin.
 *
 * Constructs the encryption / settings / logger helpers lazily and registers
 * the hooks Phase 1 needs (textdomain loading). Phase 2 will hang the REST
 * controllers and admin pages off `init()`; the lazy accessor pattern keeps
 * that growth additive.
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
}
