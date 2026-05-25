<?php
/**
 * Provisioning REST endpoint and MCP sender.
 *
 * Esta clase concentra todo el flujo de alta:
 * - recibe el payload desde fuera o desde el panel de admin,
 * - completa los datos que se pueden derivar desde WordPress,
 * - intenta crear automáticamente credenciales reales de WooCommerce durante el provisioning,
 * - persiste estado local,
 * - y envía el paquete final al MCP.
 */
if ( ! defined( 'WPINC' ) ) {
    die;
}

class Shopia_Chatbot_Assistant_Provision {

    const OPTION_KEY = 'shopia_provision';
    const EXTERNAL_DOMAIN_OPTION_KEY = 'shopia_external_domain';
    const ENV_DOMAIN_KEY = 'SITE_DOMAIN';
    // NOTE: removed separate pending option — we track one-shot attempts via `auto_attempted` in the main option
    const AUDIT_OPTION_KEY = 'shopia_provision_audit';
    const MCP_URL = 'https://seahorse-app-r7lxh.ondigitalocean.app/register';
    const SECRET_CIPHER = 'AES-256-CBC';
    const AUDIT_LIMIT = 10;
    // NOTE: We no longer depend on the WC REST controller; keys are created directly in DB.
    const STATE_FIELD = 'state'; // keys_ready | sent | send_failed
    const ATTEMPTS_FIELD = 'attempts';

    public static function register_routes() {
    /**
     * Register REST routes.
     *
     * The plugin exposes a POST /shopia/v1/provision endpoint as a manual
     * entry point for UI-triggered provisioning or for remote callers. The
     * automatic activation flow calls the same handler in-process, so the
     * endpoint is optional for automation but useful as a fallback.
     */
    public static function register_routes() {
        // Expone un endpoint REST propio para poder recibir el provisioning por HTTP.
        register_rest_route( 'shopia/v1', '/provision', array(
            'methods' => 'POST',
            'callback' => array( __CLASS__, 'handle_provision' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Registra las rutas REST del plugin.
     * Ruta: POST /wp-json/shopia/v1/provision
     * - No requiere autenticación en el endpoint porque se usa en entornos controlados
     *   (la seguridad adicional se debe aplicar en el MCP o la red de despliegue).
     */

    public static function queue_activation_provision() {
        // Marcamos el trabajo como pendiente para ejecutarlo cuando WordPress ya esté cargado.
        // Deprecated: queue mechanism removed. Keep for backward-compatibility (no-op).
        self::log_event( 'info', 'queue_activation_provision() called (noop)', array() );
    }

    /**
     * Cola un intento de provisioning durante la activación del plugin.
     * Se usa desde el activador para posponer la ejecución hasta que WP esté listo.
     */

    public static function maybe_run_pending_provision() {
            /**
             * Si existe una cola de provisioning marcada en opciones, intenta ejecutarla.
             * - Construye un payload mínimo con datos del sitio
             * - Intenta generar llaves de WooCommerce si se pidió
             * - Si no puede generar las llaves, devuelve y reintenta en futuros loads
             * - Si tiene éxito, guarda estado local y envía al MCP
             */
    
        // Only run once: use a flag `auto_attempted` inside the main `shopia_provision` option
        if ( ! function_exists( 'get_home_url' ) ) {
            return;
        }

        // Do not auto-provision until an external/public domain has been configured.
        $external_domain = self::get_external_domain();
        if ( empty( $external_domain ) ) {
            return;
        }

        $store = get_option( self::OPTION_KEY, array() );
        if ( isset( $store['auto_attempted'] ) && $store['auto_attempted'] ) {
            return;
        }

        // Aquí armamos el payload base con datos que WordPress sí conoce.
        $site_url = self::resolve_site_url();

        // Fase 1: generación de llaves (si aún no existen en el store).
        $store = get_option( self::OPTION_KEY, array() );
        if ( empty( $store ) || empty( $store['consumerKey'] ) ) {
            // Generamos llaves directamente en la BD y las almacenamos localmente.
            $payload = self::build_payload( array(
                'siteUrl' => $site_url,
                'storeName' => get_bloginfo( 'name' ),
                'generate_keys' => true,
            ) );

            if ( empty( $payload['consumerKey'] ) || empty( $payload['consumerSecret'] ) ) {
                // Si por alguna razón no se generaron, guardamos estado y salimos.
                self::log_event( 'error', 'Key generation failed', array() );
                $store = get_option( self::OPTION_KEY, array() );
                $store[self::ATTEMPTS_FIELD] = isset( $store[self::ATTEMPTS_FIELD] ) ? $store[self::ATTEMPTS_FIELD] + 1 : 1;
                $store['auto_attempted'] = true;
                update_option( self::OPTION_KEY, $store, false );
                return;
            }

            // Guardamos las llaves y marcamos que están listas para enviar.
            self::store_provision( $payload, true );
            $store = get_option( self::OPTION_KEY, array() );
            $store[self::STATE_FIELD] = 'keys_ready';
            $store[self::ATTEMPTS_FIELD] = 0;
            // do not mark auto_attempted yet — allow the send phase below to set it
            update_option( self::OPTION_KEY, $store, false );
            self::log_event( 'info', 'Keys stored locally', array() );
        }

        // Fase 2: envío al MCP (solo si las llaves están listas y no se ha enviado aún).
        $store = get_option( self::OPTION_KEY, array() );
        if ( ! empty( $store ) && empty( $store['mcp_status'] ) && ! empty( $store['consumerKey'] ) ) {
            $payload = array(
                'siteUrl' => isset( $store['siteUrl'] ) ? $store['siteUrl'] : $site_url,
                'storeName' => isset( $store['storeName'] ) ? $store['storeName'] : get_bloginfo( 'name' ),
                'consumerKey' => $store['consumerKey'],
            );
            if ( ! empty( $store['consumerSecret_encrypted'] ) ) {
                $payload['consumerSecret'] = self::decrypt_secret( $store['consumerSecret_encrypted'] );
            }

            // Intentos inmediatos con backoff corto; no re-generar llaves aquí.
            $response = self::send_to_mcp( $payload );
            self::update_store_with_response( $payload, $response );

            $code = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
            if ( $code >= 200 && $code < 300 ) {
                // Éxito: marcamos completado.
                $store[self::STATE_FIELD] = 'sent';
                $store['mcp_status'] = $code;
                $store['mcp_updated_at'] = current_time( 'mysql' );
                $store['auto_attempted'] = true;
                update_option( self::OPTION_KEY, $store, false );
                self::log_event( 'success', 'Provision completed', array( 'mcp_status' => $code ) );
                self::clear_audit_log();
                return;
            }

            // Falló el envío: registrar y marcar estado de fallo. No reintentamos en background.
            $store[self::STATE_FIELD] = 'send_failed';
            $store[self::ATTEMPTS_FIELD] = isset( $store[self::ATTEMPTS_FIELD] ) ? $store[self::ATTEMPTS_FIELD] + 1 : 1;
            $store['mcp_status'] = $code;
            $store['mcp_response'] = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
            $store['mcp_updated_at'] = current_time( 'mysql' );
            $store['auto_attempted'] = true;
            update_option( self::OPTION_KEY, $store, false );
            self::log_event( 'error', 'Provision send failed', array( 'mcp_status' => $code ) );
            return;
        }
        // No cleanup required for WooCommerce hooks — we no longer register any.
    }

    public static function handle_provision( $request ) {
        // El endpoint espera JSON. Si no llega nada útil, cortamos aquí.
        $params = $request->get_json_params();
        if ( empty( $params ) ) {
            return new WP_REST_Response( array( 'error' => 'empty_payload' ), 400 );
        }

        // Construimos el payload final completando valores derivados del sitio.
        $payload = self::build_payload( $params );

        if ( empty( $payload['siteUrl'] ) ) {
            $payload['siteUrl'] = self::resolve_site_url();
        }

        // Política: si ya hay un `mcp_status` registrado que indica autorización
        // denegada (401/403), no reintentamos salvo que el caller pase
        // `force_resend=true` en el body.
        $store = get_option( self::OPTION_KEY, array() );
        $force = isset( $params['force_resend'] ) && $params['force_resend'];
        // Si ya hay un 401/403 pero NO tenemos llaves locales, permitimos
        // continuar porque puede que la primera ejecución se hiciera sin keys.
        if ( isset( $store['mcp_status'] ) && in_array( (int) $store['mcp_status'], array( 401, 403 ), true ) && ! $force && ! empty( $store['consumerKey'] ) ) {
            return new WP_REST_Response( array( 'error' => 'mcp_authorization_failed', 'mcp_status' => (int) $store['mcp_status'] ), 403 );
        }

        // Primero enviamos al MCP y luego registramos el estado local del intento.
        $response = self::send_to_mcp( $payload );
        self::store_provision( $payload, isset( $params['persist_secret'] ) && $params['persist_secret'] );
        self::update_store_with_response( $payload, $response );

        return new WP_REST_Response( array(
            'sent' => true,
            'mcp_status' => is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response ),
            'mcp_body' => wp_remote_retrieve_body( $response ),
        ), is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response ) );
    }

    /**
     * Callback del endpoint REST `/provision`.
     * - Recibe JSON con campos opcionales como `generate_keys` y `persist_secret`.
     * - Devuelve el estado del POST al MCP y el cuerpo de respuesta.
     * - Persiste localmente la configuración solicitada para reenvíos y auditoría.
     *
     * Request body example:
     * {
     *   "siteUrl":"https://example.com",
     *   "generate_keys": true,
     *   "persist_secret": true
     * }
     */

    public static function send_to_mcp( $data ) {
        // El MCP recibe el JSON por POST. El sslverify queda activo porque es tráfico sensible.
        $headers = array( 'Content-Type' => 'application/json' );

        // Soportar token de autorización en la llamada al MCP. Se puede pasar
        // por variable de entorno `MCP_BEARER_TOKEN` o por una constante `MCP_BEARER_TOKEN`.
        $token = getenv( 'MCP_BEARER_TOKEN' );
        if ( empty( $token ) ) {
            $token = self::read_env_file_value( 'MCP_BEARER_TOKEN' );
        }
        // Allow storing the token in a WP option `shopia_mcp_bearer_token` for environments
        // where exporting env vars into PHP is inconvenient (CI, containers, etc.).
        if ( empty( $token ) && function_exists( 'get_option' ) ) {
            $opt_token = get_option( 'shopia_mcp_bearer_token', '' );
            if ( ! empty( $opt_token ) ) {
                $token = $opt_token;
            }
        }
        if ( ! empty( $token ) ) {
            $headers['Authorization'] = 'Bearer ' . $token;
            self::log_event( 'info', 'Using MCP bearer token', array() );
        }

        $args = array(
            'headers' => $headers,
            'body' => wp_json_encode( $data ),
            'timeout' => 20,
            'sslverify' => true,
        );

        return wp_remote_post( self::MCP_URL, $args );
    }

    /**
     * Envía el payload como JSON al endpoint del MCP definido en `MCP_URL`.
     * Retorna lo que devuelve `wp_remote_post` para permitir inspección posterior.
     */

    public static function build_payload( $params ) {
        // Esta función centraliza la normalización del payload.
        $data = array();
        $fields = array( 'siteUrl', 'consumerKey', 'consumerSecret', 'oauthSignatureBaseUrl', 'signatureBaseUrl', 'storeName', 'wordpressVersion', 'woocommerceVersion' );
        foreach ( $fields as $field ) {
            if ( isset( $params[ $field ] ) ) {
                $data[ $field ] = sanitize_text_field( $params[ $field ] );
            }
        }

        if ( empty( $data['siteUrl'] ) ) {
            $data['siteUrl'] = self::resolve_site_url();
        }

        if ( empty( $data['storeName'] ) ) {
            $data['storeName'] = get_bloginfo( 'name' );
        }

        if ( empty( $data['wordpressVersion'] ) ) {
            $data['wordpressVersion'] = get_bloginfo( 'version' );
        }

        if ( empty( $data['woocommerceVersion'] ) && defined( 'WC_VERSION' ) ) {
            $data['woocommerceVersion'] = WC_VERSION;
        }

        // Campo fijo requerido por el MCP para identificar el modo de autenticación.
        $data['authMode'] = 'oauth';

        if ( empty( $data['signatureBaseUrl'] ) ) {
            // Base de la API REST de WooCommerce para firmar o consumir endpoints.
            $data['signatureBaseUrl'] = $data['siteUrl'];
        }

        if ( empty( $data['oauthSignatureBaseUrl'] ) ) {
            // Ruta base usada por integraciones OAuth o flujos de autorización similares.
            $data['oauthSignatureBaseUrl'] = $data['siteUrl'];
        }

        if ( empty( $data['consumerKey'] ) && ! empty( $params['generate_keys'] ) ) {
            // Si el provisioning pidió generar llaves, intentamos crear el par de credenciales.
            $generated = self::generate_wc_keys();
            if ( $generated ) {
                $data['consumerKey'] = $generated['key'];
                $data['consumerSecret'] = $generated['secret'];
            }
        }

        return $data;
    }

    /**
     * Returns the configured external domain, if any.
     */
    public static function get_external_domain() {
        $env_domain = getenv( self::ENV_DOMAIN_KEY );
        if ( empty( $env_domain ) && isset( $_ENV[ self::ENV_DOMAIN_KEY ] ) ) {
            $env_domain = $_ENV[ self::ENV_DOMAIN_KEY ];
        }
        if ( empty( $env_domain ) ) {
            $env_domain = self::read_env_file_value( self::ENV_DOMAIN_KEY );
        }
        if ( ! empty( $env_domain ) ) {
            return esc_url_raw( trim( (string) $env_domain ) );
        }

        $url = get_option( self::EXTERNAL_DOMAIN_OPTION_KEY, '' );
        if ( ! is_string( $url ) ) {
            return '';
        }
        return esc_url_raw( trim( $url ) );
    }

    /**
     * Resolves the public site URL using the external domain first.
     */
    public static function resolve_site_url() {
        $external = self::get_external_domain();
        if ( ! empty( $external ) ) {
            return $external;
        }
        return home_url();
    }

    /**
     * Reads a single key from a .env file if the runtime did not export it.
     */
    public static function read_env_file_value( $key ) {
        $paths = array(
            plugin_dir_path( dirname( __FILE__ ) ) . '.env',
            ABSPATH . '.env',
        );

        foreach ( $paths as $path ) {
            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                continue;
            }

            $lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
            if ( false === $lines ) {
                continue;
            }

            foreach ( $lines as $line ) {
                $line = trim( $line );
                if ( '' === $line || '#' === $line[0] ) {
                    continue;
                }

                $parts = explode( '=', $line, 2 );
                if ( 2 !== count( $parts ) ) {
                    continue;
                }

                if ( trim( $parts[0] ) !== $key ) {
                    continue;
                }

                $value = trim( $parts[1] );
                $value = trim( $value, "\"'" );
                return $value;
            }
        }

        return '';
    }

    /**
     * Normaliza y completa los campos del payload que enviaremos al MCP.
     * - Normaliza entradas con `sanitize_text_field`
     * - Rellena valores por defecto (siteUrl, storeName, signatureBaseUrl, oauthSignatureBaseUrl)
     * - Opcionalmente genera llaves WooCommerce cuando se solicita.
     */

    public static function masked_payload( $payload ) {
        // Para UI o logs, nunca mostramos el secreto completo.
        $masked = $payload;
        if ( ! empty( $masked['consumerSecret'] ) ) {
            $masked['consumerSecret'] = '[protected]';
        }
        return $masked;
    }

    /**
     * Devuelve una copia del payload con el `consumerSecret` protegido para mostrar en UI.
     */

    public static function store_provision( $payload, $persist_secret = false ) {
        // Guardamos el estado local del último provisioning exitoso o intentado.
        $store = get_option( self::OPTION_KEY, array() );
        $store['siteUrl'] = isset( $payload['siteUrl'] ) ? $payload['siteUrl'] : self::resolve_site_url();
        $store['storeName'] = isset( $payload['storeName'] ) ? $payload['storeName'] : get_bloginfo( 'name' );
        $store['wordpressVersion'] = isset( $payload['wordpressVersion'] ) ? $payload['wordpressVersion'] : get_bloginfo( 'version' );
        $store['woocommerceVersion'] = isset( $payload['woocommerceVersion'] ) ? $payload['woocommerceVersion'] : ( defined( 'WC_VERSION' ) ? WC_VERSION : '' );
        $store['signatureBaseUrl'] = ! empty( $payload['signatureBaseUrl'] ) ? $payload['signatureBaseUrl'] : $store['siteUrl'];
        $store['oauthSignatureBaseUrl'] = ! empty( $payload['oauthSignatureBaseUrl'] ) ? $payload['oauthSignatureBaseUrl'] : $store['siteUrl'];
        $store['authMode'] = isset( $payload['authMode'] ) ? $payload['authMode'] : '';
        $store['consumerKey'] = isset( $payload['consumerKey'] ) ? sanitize_text_field( $payload['consumerKey'] ) : '';
        $store['consumerSecret_encrypted'] = '';
        $store['consumerSecret_last4'] = '';

        if ( $persist_secret && ! empty( $payload['consumerSecret'] ) ) {
            // El secreto solo se guarda cifrado para permitir reenvíos posteriores.
            $store['consumerSecret_encrypted'] = self::encrypt_secret( $payload['consumerSecret'] );
            $store['consumerSecret_last4'] = substr( sanitize_text_field( $payload['consumerSecret'] ), -4 );
        }

        $store['synced_at'] = current_time( 'mysql' );
        $store['mcp_status'] = isset( $store['mcp_status'] ) ? $store['mcp_status'] : 0;
        $store['mcp_response'] = isset( $store['mcp_response'] ) ? $store['mcp_response'] : '';

        update_option( self::OPTION_KEY, $store, false );
        self::log_event( 'info', 'Provision saved locally', array( 'secret' => $persist_secret ? 'yes' : 'no' ) );
    }

    /**
     * Guarda la información del provisioning en la opción `shopia_provision`.
     * Si `persist_secret` es true, almacena el secreto cifrado y los últimos 4 caracteres.
     */

    public static function update_store_with_response( $payload, $response ) {
        // Guardamos el resultado del POST al MCP para verlo luego en el panel.
        $store = get_option( self::OPTION_KEY, array() );
        $store['mcp_status'] = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
        $store['mcp_response'] = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
        $store['mcp_updated_at'] = current_time( 'mysql' );
        update_option( self::OPTION_KEY, $store, false );

        self::log_event( 'info', 'MCP response received', array( 'status' => $store['mcp_status'] ) );
    }

    /**
     * Actualiza el registro local con el código y cuerpo devuelto por el MCP.
     */

    public static function encrypt_secret( $secret ) {
        // Cifrado simple con claves derivadas de salts de WordPress.
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        $iv = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );
        return base64_encode( openssl_encrypt( $secret, self::SECRET_CIPHER, $key, OPENSSL_RAW_DATA, $iv ) );
    }

    /**
     * Cifra el secreto usando una clave derivada de los salts de WP.
     * - No es prison-grade: para producción considerar soluciones KMS/secret manager.
     */

    public static function decrypt_secret( $encrypted ) {
        // Solo se usa al reintentar un reenvío desde el admin.
        if ( empty( $encrypted ) ) {
            return '';
        }
        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        $iv = substr( hash( 'sha256', wp_salt( 'secure_auth' ), true ), 0, 16 );
        return openssl_decrypt( base64_decode( $encrypted ), self::SECRET_CIPHER, $key, OPENSSL_RAW_DATA, $iv );
    }

    /**
     * Descifra el secreto previamente cifrado con `encrypt_secret`.
     */

    public static function generate_wc_keys() {
        // Usamos la inserción directa en la BD como mecanismo primario
        // para generar claves de WooCommerce.
        return self::generate_wc_keys_direct();
    }

    /**
     * Crea un par de credenciales WooCommerce directamente en la tabla interna.
     * Este método es ahora el flujo primario para generar claves y persitirlas
     * en la tabla `woocommerce_api_keys` (no dependemos del controlador REST).
     */
    public static function generate_wc_keys_direct() {
        global $wpdb;

        $user = get_user_by( 'login', 'admin' );
        if ( ! $user ) {
            $users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
            if ( ! empty( $users ) ) {
                $user = $users[0];
            }
        }
        if ( ! $user ) {
            self::log_event( 'error', 'No admin user for key generation', array() );
            return false;
        }

        // Generación de claves compatible con WooCommerce:
        // - consumer_key: 'ck_' . wc_rand_hash()
        // - consumer_secret: 'cs_' . wc_rand_hash()
        // - consumer_key almacenado en BD como wc_api_hash( $consumer_key )
        try {
            if ( function_exists( 'wc_rand_hash' ) ) {
                $consumer_key = 'ck_' . wc_rand_hash();
                $consumer_secret = 'cs_' . wc_rand_hash();
            } else {
                // Fallback si WC no ha definido helpers aún.
                $consumer_key = 'ck_' . bin2hex( random_bytes( 20 ) );
                $consumer_secret = 'cs_' . bin2hex( random_bytes( 20 ) );
            }

            if ( function_exists( 'wc_api_hash' ) ) {
                $db_consumer_key = wc_api_hash( $consumer_key );
            } else {
                $db_consumer_key = hash_hmac( 'sha256', $consumer_key, 'wc-api' );
            }

            $truncated_key = substr( $consumer_key, -7 );

            $data = array(
                'user_id'         => (int) $user->ID,
                'description'     => 'provisioning',
                'permissions'     => 'read_write',
                'consumer_key'    => $db_consumer_key,
                'consumer_secret' => $consumer_secret,
                'truncated_key'   => $truncated_key,
            );

            $formats = array( '%d', '%s', '%s', '%s', '%s', '%s' );

            $wpdb->insert( $wpdb->prefix . 'woocommerce_api_keys', $data, $formats );

            if ( 0 === (int) $wpdb->insert_id ) {
                self::log_event( 'error', 'Direct key insert failed', array() );
                return false;
            }

            self::log_event( 'success', 'Direct keys inserted', array() );

            return array(
                'key'    => $consumer_key,
                'secret' => $consumer_secret,
            );
        } catch ( Exception $e ) {
            self::log_event( 'error', 'Direct key generation exception', array() );
            return false;
        }
    }

    /**
     * Intenta crear un par de `consumer_key`/`consumer_secret` mediante las APIs internas
     * de WooCommerce. Devuelve false si WooCommerce no está disponible o si ocurrió un error.
     */

    public static function resend_provision() {
        // Reenvía exactamente la última configuración conocida.
        $args = func_get_args();
        $force = false;
        if ( isset( $args[0] ) && is_bool( $args[0] ) ) {
            $force = $args[0];
        }
        $store = get_option( self::OPTION_KEY );
        if ( empty( $store ) ) {
            return new WP_Error( 'no_data', 'No provision data stored' );
        }
        $payload = $store;
        if ( ! empty( $store['consumerSecret_encrypted'] ) ) {
            // Si el secreto fue persistido, lo recuperamos solo para este envío.
            $payload['consumerSecret'] = self::decrypt_secret( $store['consumerSecret_encrypted'] );
        }
        if ( $force ) {
            self::log_event( 'info', 'Manual resend requested', array() );
        }
        $response = self::send_to_mcp( $payload );
        $res_body = wp_remote_retrieve_body( $response );
        $res_code = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
        $store['mcp_response'] = $res_body;
        $store['mcp_status'] = $res_code;
        $store['mcp_updated_at'] = current_time( 'mysql' );
        update_option( self::OPTION_KEY, $store );
        self::log_event( $res_code >= 200 && $res_code < 300 ? 'success' : 'error', 'Manual resend completed', array( 'status' => $res_code ) );
        if ( $res_code >= 200 && $res_code < 300 ) {
            self::clear_audit_log();
        }
        return array( 'status' => $res_code, 'body' => $res_body );
    }

    /**
     * Reenvía la última configuración almacenada a MCP.
     * - Si el secreto fue persistido, lo descifra para incluirlo en el envío temporal.
     */

    public static function log_event( $level, $message, $context = array() ) {
        // Guardamos una bitácora corta, ordenada del evento más nuevo al más viejo.
        $audit = get_option( self::AUDIT_OPTION_KEY, array() );
        if ( ! is_array( $audit ) ) {
            $audit = array();
        }
        array_unshift( $audit, array(
            'level' => sanitize_text_field( $level ),
            'message' => sanitize_text_field( $message ),
            'context' => $context,
            'created_at' => current_time( 'mysql' ),
        ) );
        $audit = array_slice( $audit, 0, self::AUDIT_LIMIT );
        update_option( self::AUDIT_OPTION_KEY, $audit, false );
    }

    /**
     * Vacía la bitácora técnica cuando el provisioning ya quedó completado.
     */
    public static function clear_audit_log() {
        update_option( self::AUDIT_OPTION_KEY, array(), false );
    }

    /**
     * Añade una entrada a la bitácora local `shopia_provision_audit`.
     * Mantiene solo las últimas `AUDIT_LIMIT` entradas.
     */

    public static function get_audit_log() {
        // Helper de lectura para el panel de administración.
        $audit = get_option( self::AUDIT_OPTION_KEY, array() );
        return is_array( $audit ) ? $audit : array();
    }
}
