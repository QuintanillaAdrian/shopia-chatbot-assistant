=== Plugin Name ===
Contributors: (this should be a list of wordpress.org userid's)
Donate link: https://portafolio-adrianquintanilla.vercel.app/
Tags: comments, spam
Requires at least: 3.0.1
Tested up to: 3.4
Stable tag: 4.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Automatiza el provisioning de WooCommerce y el envío de credenciales al MCP.

== Description ==

Plugin para generar credenciales de WooCommerce y enviar el resultado al MCP.
Está pensado para que el instalador solo tenga que montar WordPress, activar WooCommerce y activar este plugin.

El plugin hace lo siguiente:

* En la activación intenta un único provisioning automático.
* Genera `consumerKey` y `consumerSecret` de WooCommerce.
* Guarda el secreto cifrado.
* Envía el payload al MCP.
* Permite reenvío manual desde el panel de administración.

== Provisioning Flow ==

Flujo de provisioning:

* Al activar el plugin se ejecuta un intento automático único.
* Si WooCommerce está disponible, se generan las credenciales de API.
* El payload se envía al MCP.
* El estado local se puede revisar en el panel de administración.

Endpoint REST del plugin:

El prefijo y host dependen del entorno y de la configuración de URLs del sitio

* `POST /wp-json/shopia/v1/provision` 
* `POST /index.php?rest_route=/shopia/v1/provision`
* `POST */shopia/v1/provision`

Ejemplo de body:

```json
{
    "generate_keys": true,
    "persist_secret": true
}
```

Para pruebas locales se puede usar `test-provisioning.ps1` después de instalar y activar WooCommerce.

== Installation ==

Instrucciones para el instalador:

1. Instalar y activar WordPress.
2. Instalar y activar WooCommerce.
3. Copiar esta carpeta del plugin en `wp-content/plugins/shopia-chatbot-assistant`.
4. Activar el plugin desde el admin o con WP-CLI.
5. Verificar que el servidor permite salidas HTTPS y que PHP tiene OpenSSL habilitado.
6. Confirmar permisos de base de datos sobre `wp_woocommerce_api_keys` o el prefijo que use la instalación.
7. El MCP requiere autenticación, agregar la variable de entorno: `MCP_BEARER_TOKEN`.

Comando opcional con WP-CLI:

```bash
wp plugin activate shopia-chatbot-assistant
```

== Frequently Asked Questions ==

= ¿Qué hacer ? =

Solo instalar WordPress, WooCommerce y este plugin, activarlo y comprobar que el provisioning automático se ejecutó una vez.

== Screenshots ==

1. Panel de administración del plugin.
2. Resultado del provisioning.

Información útil para integración:

* Ruta REST principal: `/shopia/v1/provision`
* Método: `POST`
* Cuerpo sugerido: `generate_keys=true` y `persist_secret=true`

`<?php code(); // va entre comillas invertidas ?>`