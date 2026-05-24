<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://https://portafolio-adrianquintanilla.vercel.app/
 * @since      1.0.0
 *
 * @package    Shopia_Chatbot_Assistant
 * @subpackage Shopia_Chatbot_Assistant/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Shopia_Chatbot_Assistant
 * @subpackage Shopia_Chatbot_Assistant/includes
 * @author     Quintanilla <adrianq1299@gmail.com>
 */
class Shopia_Chatbot_Assistant {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Shopia_Chatbot_Assistant_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		// Inicialización mínima: versión + slug del plugin.
		if ( defined( 'SHOPIA_CHATBOT_ASSISTANT_VERSION' ) ) {
			$this->version = SHOPIA_CHATBOT_ASSISTANT_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'shopia-chatbot-assistant';

		// Orden importante: primero cargamos clases, luego locale y por último hooks.
		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Shopia_Chatbot_Assistant_Loader. Orchestrates the hooks of the plugin.
	 * - Shopia_Chatbot_Assistant_i18n. Defines internationalization functionality.
	 * - Shopia_Chatbot_Assistant_Admin. Defines all hooks for the admin area.
	 * - Shopia_Chatbot_Assistant_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-shopia-chatbot-assistant-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-shopia-chatbot-assistant-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-shopia-chatbot-assistant-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-shopia-chatbot-assistant-public.php';

		// Provisioning handler
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-shopia-chatbot-assistant-provision.php';

		// El loader es quien finalmente registra add_action/add_filter en WordPress.
		$this->loader = new Shopia_Chatbot_Assistant_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Shopia_Chatbot_Assistant_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Shopia_Chatbot_Assistant_i18n();

		// Cargamos traducciones cuando WordPress ya terminó de cargar plugins.
		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Shopia_Chatbot_Assistant_Admin( $this->get_plugin_name(), $this->get_version() );

		// Todo lo del panel admin (assets, menú y acción AJAX de reenvío).
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
		$this->loader->add_action( 'admin_menu', $plugin_admin, 'register_menu' );
		$this->loader->add_action( 'wp_ajax_shopia_resend_provision', $plugin_admin, 'ajax_resend_provision' );
		// AJAX handler for updating keys from admin UI
		$this->loader->add_action( 'wp_ajax_shopia_update_keys', $plugin_admin, 'ajax_update_keys' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Shopia_Chatbot_Assistant_Public( $this->get_plugin_name(), $this->get_version() );

		// Hooks del front y del flujo automático de provisioning.
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
		$this->loader->add_action( 'init', 'Shopia_Chatbot_Assistant_Provision', 'maybe_run_pending_provision' );

		// register REST routes for provisioning
		$this->loader->add_action( 'rest_api_init', 'Shopia_Chatbot_Assistant_Provision', 'register_routes' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		// Este método dispara el registro real de todos los hooks acumulados.
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Shopia_Chatbot_Assistant_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
