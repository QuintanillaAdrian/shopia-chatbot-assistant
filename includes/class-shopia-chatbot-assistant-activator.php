<?php

/**
 * Fired during plugin activation
 *
 * @link       https://https://portafolio-adrianquintanilla.vercel.app/
 * @since      1.0.0
 *
 * @package    Shopia_Chatbot_Assistant
 * @subpackage Shopia_Chatbot_Assistant/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      1.0.0
 * @package    Shopia_Chatbot_Assistant
 * @subpackage Shopia_Chatbot_Assistant/includes
 * @author     Quintanilla <adrianq1299@gmail.com>
 */
class Shopia_Chatbot_Assistant_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		// Ensure a baseline provision option exists so the init hook can decide
		// whether to attempt the one-shot automatic provisioning.
		if ( ! get_option( 'shopia_provision' ) ) {
			update_option( 'shopia_provision', array( 'state' => 'not_attempted', 'created_at' => current_time( 'mysql' ) ), false );
		}
	}

}
