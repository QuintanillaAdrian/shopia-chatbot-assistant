<?php

/**
 * Fired during plugin activation
 *
 * @link       https://https://portafolio-adrianquintanilla.vercel.app/
 * @since      1.0.0
 *
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/includes
 * @author     Quintanilla <adrianq1299@gmail.com>
 */
class Chatbot_Assistant_Activator {

	const TABLE         = 'chatbot_tenants';
	const SCHEMA_VERSION = '1.0';

	/**
	 * Ejecuta todas las tareas de activación:
	 * 1. Crea/actualiza la tabla de tenants.
	 * 2. Inserta el registro del tenant si no existe.
	 * 3. Guarda en wp_options solo las dos claves livianas que WP necesita.
	 *
	 * Las credenciales de WC/WA NO se guardan aquí.
	 * El plugin las lee, las envía al backend una sola vez por HTTPS y las olvida.
	 */
	public static function activate() {
		self::create_table();
		self::maybe_insert_tenant();
		self::update_options();
	}

	/**
	 * Crea la tabla `{prefix}chatbot_tenants` con dbDelta().
	 * Es seguro llamarlo en cada activación: solo aplica cambios si difiere.
	 *
	 * Columnas de credenciales intencionalmente ausentes:
	 * - wc_consumer_key / wc_consumer_secret → se envían al backend y se borran.
	 * - wa_token → igual: transita por HTTPS, nunca persiste en el hosting del cliente.
	 */
	public static function create_table() {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
			tenant_id       VARCHAR(36)         NOT NULL,
			api_key_hash    VARCHAR(64)         NOT NULL,
			state_token     VARCHAR(64)         NULL,
			backend_url     VARCHAR(255)        NOT NULL DEFAULT '',
			bot_name        VARCHAR(100)        NOT NULL DEFAULT 'Chatbot Assistant',
			welcome_message TEXT                NULL,
			system_prompt   TEXT                NULL,
			status          VARCHAR(20)         NOT NULL DEFAULT 'pending',
			wc_verified     TINYINT(1)          NOT NULL DEFAULT 0,
			wa_verified     TINYINT(1)          NOT NULL DEFAULT 0,
			mcp_verified    TINYINT(1)          NOT NULL DEFAULT 0,
			current_step    TINYINT             NOT NULL DEFAULT 1,
			wa_phone_number VARCHAR(20)         NULL,
			completed_at    DATETIME            NULL,
			created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_id    (tenant_id),
			UNIQUE KEY api_key_hash (api_key_hash)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Inserta la fila inicial del tenant si todavía no existe.
	 * Genera un tenant_id (UUID v4 simplificado) y un api_key_hash.
	 */
	public static function maybe_insert_tenant() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// Comprobamos por tenant_id guardado en wp_options para evitar
		// buscar en la tabla antes de que dbDelta la haya creado del todo.
		$existing_tenant_id = get_option( 'chatbot_tenant_id', '' );
		if ( ! empty( $existing_tenant_id ) ) {
			return;
		}

		$tenant_id    = self::generate_uuid();
		$raw_api_key  = 'sk_' . bin2hex( random_bytes( 24 ) );
		$api_key_hash = hash( 'sha256', $raw_api_key );

		$wpdb->insert(
			$table,
			array(
				'tenant_id'    => $tenant_id,
				'api_key_hash' => $api_key_hash,
				'backend_url'  => '',
				'bot_name'     => 'Chatbot Assistant',
				'status'       => 'pending',
				'current_step' => 1,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		// Guardamos el tenant_id en wp_options para acceso rápido (sin query).
		// La api_key en crudo se guarda temporalmente solo para mostrarla al admin
		// durante el wizard; se elimina en cuanto el backend la confirme.
		update_option( 'chatbot_tenant_id', $tenant_id, false );
		update_option( 'chatbot_api_key_plain', $raw_api_key, false );
	}

	/**
	 * Actualiza las dos opciones livianas de wp_options.
	 * Solo chatbot_version y chatbot_tenant_id viven aquí; todo lo demás en la tabla.
	 */
	public static function update_options() {
		update_option( 'chatbot_version', self::SCHEMA_VERSION, false );
	}

	/**
	 * Devuelve la fila del tenant activo, o null si no existe.
	 */
	public static function get_tenant() {
		global $wpdb;
		$table     = $wpdb->prefix . self::TABLE;
		$tenant_id = get_option( 'chatbot_tenant_id', '' );
		if ( empty( $tenant_id ) ) {
			return null;
		}
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE tenant_id = %s LIMIT 1", $tenant_id ),
			ARRAY_A
		);
	}

	/**
	 * Genera un UUID v4 simplificado.
	 */
	private static function generate_uuid() {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

}
