<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://https://portafolio-adrianquintanilla.vercel.app/
 * @since      1.0.0
 *
 * @package    Shopia_Chatbot_Assistant
 * @subpackage Shopia_Chatbot_Assistant/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Shopia_Chatbot_Assistant
 * @subpackage Shopia_Chatbot_Assistant/admin
 * @author     Quintanilla <adrianq1299@gmail.com>
 */
class Shopia_Chatbot_Assistant_Admin {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register plugin admin menu
	 */
	public function register_menu() {
		// Creamos una página de nivel superior para ver el estado del provisioning.
		add_menu_page(
			'Asistente Shopia',
			'Asistente Shopia',
			'manage_options',
			'shopia-chatbot-assistant',
			array( $this, 'display_admin_page' ),
			'dashicons-format-chat'
		);
	}

	public function display_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Leemos el estado local y la bitácora antes de cargar la vista.
		$store = get_option( 'shopia_provision' );
		$audit = Shopia_Chatbot_Assistant_Provision::get_audit_log();
		$nonce = wp_create_nonce( 'shopia_resend_provision' );
		$nonce_update = wp_create_nonce( 'shopia_update_keys' );
		require_once plugin_dir_path( __FILE__ ) . 'partials/shopia-chatbot-assistant-admin-display.php';
	}

	public function ajax_resend_provision() {
		// Bloqueamos a usuarios sin permisos y validamos nonce para evitar envíos no autorizados.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'shopia_resend_provision' );
		// Reutilizamos el mismo servicio de provisioning para reenviar la última configuración conocida.
		$result = Shopia_Chatbot_Assistant_Provision::resend_provision();
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'error' => $result->get_error_message() ), 500 );
		} else {
			wp_send_json_success( $result );
		}
	}

	public function ajax_update_keys() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'shopia_update_keys' );

		$ck = isset( $_POST['consumerKey'] ) ? sanitize_text_field( wp_unslash( $_POST['consumerKey'] ) ) : '';
		$cs = isset( $_POST['consumerSecret'] ) ? sanitize_text_field( wp_unslash( $_POST['consumerSecret'] ) ) : '';
		if ( empty( $ck ) ) {
			wp_send_json_error( array( 'error' => 'empty_consumer_key' ), 400 );
		}

		if ( empty( $cs ) ) {
			wp_send_json_error( array( 'error' => 'empty_consumer_secret' ), 400 );
		}

		// Both fields must match a real WooCommerce API key pair before we persist anything locally.
		$validated = false;
		if ( ! empty( $ck ) && ! empty( $cs ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'woocommerce_api_keys';
			// Ensure table exists before querying.
			$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table ) ) );
			if ( $exists ) {
				// Compute DB-stored consumer key hash using WC helper if available.
				if ( function_exists( 'wc_api_hash' ) ) {
					$db_key = wc_api_hash( $ck );
				} else {
					$db_key = hash_hmac( 'sha256', $ck, 'wc-api' );
				}
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE consumer_key = %s LIMIT 1", $db_key ), ARRAY_A );
				if ( empty( $row ) ) {
					wp_send_json_error( array( 'error' => 'validation_failed', 'message' => 'No se encontró la clave en la tabla de WooCommerce' ), 400 );
				}
				// Compare secrets (DB stores secret in plain text historically)
				if ( isset( $row['consumer_secret'] ) ) {
					if ( $row['consumer_secret'] !== $cs ) {
						wp_send_json_error( array( 'error' => 'validation_failed', 'message' => 'El secreto no coincide con la entrada de WooCommerce' ), 400 );
					}
				}
				// If we get here, validation passed.
				$validated = true;
			}
		}

		if ( ! $validated ) {
			wp_send_json_error( array( 'error' => 'validation_failed', 'message' => 'No se pudo validar la pareja consumer key / consumer secret' ), 400 );
		}

		$store = get_option( 'shopia_provision', array() );
		$store['consumerKey'] = $ck;
		// The pair is valid, so we persist locally by default.
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-shopia-chatbot-assistant-provision.php';
		$store['consumerSecret_encrypted'] = Shopia_Chatbot_Assistant_Provision::encrypt_secret( $cs );
		$store['consumerSecret_last4'] = substr( $cs, -4 );
		$store['synced_at'] = current_time( 'mysql' );
		update_option( 'shopia_provision', $store, false );
		Shopia_Chatbot_Assistant_Provision::log_event( 'info', 'Admin updated keys', array( 'persist' => 'yes' ) );
		wp_send_json_success( array( 'status' => 'ok' ) );
	}

	/**
	 * Register the stylesheets for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Shopia_Chatbot_Assistant_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Shopia_Chatbot_Assistant_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		// Cargamos los estilos del panel solo en el área administrativa.
		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/shopia-chatbot-assistant-admin.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the admin area.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Shopia_Chatbot_Assistant_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Shopia_Chatbot_Assistant_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		// Igual que con los estilos, el JS se limita al admin.
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/shopia-chatbot-assistant-admin.js', array( 'jquery' ), $this->version, false );

	}

}
