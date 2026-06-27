<?php
/**
 * WP-CLI command wrapper for provisioning actions.
 */
if ( ! defined( 'WPINC' ) ) {
    return;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {

    /**
     * WP-CLI wrapper class.
     *
     * Exposes a small command surface to invoke the provisioning flow from
     * the command line (useful for CI, automation and debugging). The command
     * constructs a minimal parameter set and calls the provisioning handler
     * in-process to avoid doing an HTTP self-request.
     */
    class Chatbot_Assistant_CLI {

        /**
         * Provision command entrypoint.
         *
         * Supported flags (assoc args):
         *  --generate_keys : boolean (create WooCommerce keys locally)
         *  --persist_secret : boolean (store consumerSecret encrypted locally)
         *
         * The function builds the parameters array and calls the same
         * handler the REST endpoint would use. We prefer an in-process call
         * so it's fast and works inside containers/CI without relying on
         * networking.
         */
        public function provision( $args, $assoc_args ) {
            $params = array();
            if ( isset( $assoc_args['generate_keys'] ) && filter_var( $assoc_args['generate_keys'], FILTER_VALIDATE_BOOLEAN ) ) {
                $params['generate_keys'] = true;
            }
            if ( isset( $assoc_args['persist_secret'] ) && filter_var( $assoc_args['persist_secret'], FILTER_VALIDATE_BOOLEAN ) ) {
                $params['persist_secret'] = true;
            }

            if ( ! class_exists( 'WP_REST_Request' ) ) {
                WP_CLI::error( 'WP REST classes not available in this context.' );
            }

            // Try to construct a WP_REST_Request so the provisioning handler receives
            // the same shape it expects when called over HTTP. In some CLI contexts
            // WP internals don't populate JSON params correctly, so we provide a
            // tiny fallback object that implements get_json_params().
            $request = new WP_REST_Request( 'POST', '/chatbot/v1/provision' );
            $request->set_body_params( $params );

            if ( ! empty( $request->get_json_params() ) ) {
                $response = Chatbot_Assistant_Provision::handle_provision( $request );
            } else {
                $fallback = new class( $params ) {
                    private $p;
                    public function __construct( $p ) { $this->p = $p; }
                    public function get_json_params() { return $this->p; }
                };
                $response = Chatbot_Assistant_Provision::handle_provision( $fallback );
            }

            if ( is_wp_error( $response ) ) {
                WP_CLI::error( $response->get_error_message() );
            }

            if ( is_a( $response, 'WP_REST_Response' ) ) {
                $status = method_exists( $response, 'get_status' ) ? $response->get_status() : 0;
                $data = method_exists( $response, 'get_data' ) ? $response->get_data() : null;
                WP_CLI::line( 'Provision finished with HTTP status: ' . $status );
                if ( $data ) {
                    WP_CLI::print_value( $data );
                }
            } else {
                WP_CLI::line( 'Provision completed.' );
            }
        }
    }

    // Register new, clearer command name: `wp mcp request provision`
    WP_CLI::add_command( 'mcp request', 'Chatbot_Assistant_CLI' );
    // Keep backward-compatible alias for existing scripts: `wp shopia provision`
    WP_CLI::add_command( 'chatbot', 'Chatbot_Assistant_CLI' );
}
