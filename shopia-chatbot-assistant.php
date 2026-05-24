<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://https://portafolio-adrianquintanilla.vercel.app/
 * @since             1.0.0
 * @package           Shopia_Chatbot_Assistant
 *
 * @wordpress-plugin
 * Plugin Name:       Shopia Chatbot Assistant
 * Plugin URI:        https://https://github.com/QuintanillaAdrian/plugintest.git
 * Description:       This is a description of the plugin.
 * Version:           1.0.0
 * Author:            Quintanilla
 * Author URI:        https://https://portafolio-adrianquintanilla.vercel.app//
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       shopia-chatbot-assistant
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

// Punto de entrada del plugin: todo arranca en este archivo.
// Aquí solo registramos activación/desactivación y delegamos la lógica a clases.

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'SHOPIA_CHATBOT_ASSISTANT_VERSION', '1.0.0' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-shopia-chatbot-assistant-activator.php
 */
function activate_shopia_chatbot_assistant() {
	// Carga diferida de la clase de activación para mantener el bootstrap liviano.
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-shopia-chatbot-assistant-activator.php';
	Shopia_Chatbot_Assistant_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-shopia-chatbot-assistant-deactivator.php
 */
function deactivate_shopia_chatbot_assistant() {
	// Igual que en activación: solo ejecutamos tareas de limpieza/estado.
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-shopia-chatbot-assistant-deactivator.php';
	Shopia_Chatbot_Assistant_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_shopia_chatbot_assistant' );
register_deactivation_hook( __FILE__, 'deactivate_shopia_chatbot_assistant' );

// A partir de aquí cargamos el núcleo, que registra todos los hooks de admin/public/rest.

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-shopia-chatbot-assistant.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_shopia_chatbot_assistant() {

	// Instanciamos la clase central y ejecutamos su loader de hooks.
	$plugin = new Shopia_Chatbot_Assistant();
	$plugin->run();

}
run_shopia_chatbot_assistant();
