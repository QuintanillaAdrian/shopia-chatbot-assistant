<?php
// Usage: wp eval-file create_wc_key.php --allow-root
if ( ! defined( 'ABSPATH' ) ) {
    require_once __DIR__ . '/../../../../wp-load.php';
}

// Genera claves directamente usando la función del plugin (inserción en BD).
$result = null;
if ( class_exists( 'Shopia_Chatbot_Assistant_Provision' ) && method_exists( 'Shopia_Chatbot_Assistant_Provision', 'generate_wc_keys_direct' ) ) {
    $result = Shopia_Chatbot_Assistant_Provision::generate_wc_keys_direct();
} else {
    // Intentar cargar el archivo include si por alguna razón la clase no está presente.
    if ( file_exists( __DIR__ . '/includes/class-shopia-chatbot-assistant-provision.php' ) ) {
        require_once __DIR__ . '/includes/class-shopia-chatbot-assistant-provision.php';
    }
    if ( class_exists( 'Shopia_Chatbot_Assistant_Provision' ) && method_exists( 'Shopia_Chatbot_Assistant_Provision', 'generate_wc_keys_direct' ) ) {
        $result = Shopia_Chatbot_Assistant_Provision::generate_wc_keys_direct();
    }
}

if ( empty( $result ) ) {
    echo "Key generation failed or not available\n";
    return;
}

echo "consumer_key: " . $result['key'] . "\n";
echo "consumer_secret: " . $result['secret'] . "\n";
