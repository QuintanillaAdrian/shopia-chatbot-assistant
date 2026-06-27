<?php

/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @link       https://https://portafolio-adrianquintanilla.vercel.app/
 * @since      1.0.0
 *
 * @package    Chatbot_Assistant
 * @subpackage Chatbot_Assistant/admin/partials
 */
?>

<!-- This file should primarily consist of HTML with a little bit of PHP. -->
<div class="wrap">
	<h1>Asistente Chatbot — Provisionamiento</h1>
	<!-- Esta pantalla muestra un resumen simple para terceros y deja el detalle técnico como opcional. -->
	<p>Conexión más reciente con el asistente virtual.</p>
	<?php if ( empty( $store ) ) : ?>
		<p><strong>Aún no hay datos de provisionamiento almacenados.</strong></p>
		<p>WordPress todavía no guardó una alta válida en la opción <code>chatbot_provision</code>.</p>
	<?php else: ?>
		<h2>Resumen de conexión</h2>
		<?php
			$last_status = isset( $store['mcp_status'] ) ? (int) $store['mcp_status'] : 0;
			$is_connected = ( $last_status >= 200 && $last_status < 300 );
		?>
			<p><strong>Estado:</strong>
				<span style="color:<?php echo $is_connected ? 'green' : 'red'; ?>;font-weight:600"><?php echo $is_connected ? 'Conexion activa' : 'Conexion fallida'; ?></span>
			</p>
		<!-- Mantenemos el detalle técnico oculto por defecto para no exponer demasiada información. -->
		
					<h3>Credenciales</h3>
		<!-- Compact container to avoid expanding the page; hide sensitive or irrelevant keys -->
		<div style="max-height:200px;overflow:auto;border:1px solid #ddd;padding:8px;background:#fff">
		<table class="widefat fixed" style="margin:0;border-collapse:collapse;">
			<tbody>
				<?php
					// Mapeo de claves internas a etiquetas en español.
					$labels = array(
						'siteUrl' => 'URL del sitio',
						'storeName' => 'Nombre de la tienda',
						'wordpressVersion' => 'Versión WP',
						'woocommerceVersion' => 'Versión WooCommerce',
						'consumerKey' => 'Clave pública',
						'consumerSecret_encrypted' => 'Secreto (cifrado)',
						'consumerSecret_last4' => 'Últimos 4 del secreto',
						'synced_at' => 'Sincronizado',
						'mcp_updated_at' => 'Última actualización',

					);
				?>
				<?php foreach ( $store as $k => $v ) : ?>
					<?php if ( in_array( $k, array( 'signatureBaseUrl', 'oauthSignatureBaseUrl', 'mcp_status', 'mcp_response', 'state', 'attempts', 'auto_attempted' ), true ) ) { continue; } ?>
					<tr>
						<th style="width:200px"><?php echo htmlspecialchars( isset( $labels[ $k ] ) ? $labels[ $k ] : ucwords( str_replace( array( '_', '-' ), array( ' ', ' ' ), $k ) ), ENT_QUOTES, 'UTF-8' ); ?></th>
						<td style="vertical-align:top;padding:6px 8px;">
							<?php
								// Never display secrets or full MCP responses in the admin UI raw.
								if ( in_array( $k, array( 'consumerSecret_encrypted', 'mcp_response' ), true ) ) {
									echo htmlspecialchars( '[redacted]', ENT_QUOTES, 'UTF-8' );
								} elseif ( 'consumerKey' === $k && is_string( $v ) ) {
									// show only prefix and last 6 chars
									$safe = substr( $v, 0, 3 ) . '...' . substr( $v, -6 );
									echo htmlspecialchars( $safe, ENT_QUOTES, 'UTF-8' );
								} elseif ( is_bool( $v ) ) {
									echo htmlspecialchars( $v ? 'Sí' : 'No', ENT_QUOTES, 'UTF-8' );
								} else {
									echo htmlspecialchars( is_string( $v ) ? $v : print_r( $v, true ), ENT_QUOTES, 'UTF-8' );
								}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p><strong>Recomendación:</strong> si el estado es "Conexión fallida", pulsa el botón <em>Actualizar conexión</em> para reintentar la conexión.</p>

		<h3 style="margin-top:18px">Actualizar credenciales manualmente</h3>
		<p>Si ya tienes un par de claves generadas en WooCommerce, puedes pegarlas aquí. El plugin validará la pareja y la guardará solo si es correcta.</p>
		<form id="chatbot-keys-form" style="max-width:720px;background:#fff;padding:12px;border:1px solid #eee;">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row"><label for="chatbot_consumerKey">Clave pública (consumer key)</label></th>
						<td><input id="chatbot_consumerKey" name="consumerKey" type="text" class="regular-text" value="<?php echo htmlspecialchars( isset( $store['consumerKey'] ) ? $store['consumerKey'] : '', ENT_QUOTES, 'UTF-8' ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="chatbot_consumerSecret">Secreto (consumer secret)</label></th>
						<td>
							<input id="chatbot_consumerSecret" name="consumerSecret" type="password" class="regular-text" placeholder="Pega el secreto generado en WooCommerce" required>
							<?php if ( ! empty( $store['consumerSecret_last4'] ) ) : ?>
								<p class="description">Últimos 4: <?php echo htmlspecialchars( $store['consumerSecret_last4'], ENT_QUOTES, 'UTF-8' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
			<p>
				<button id="chatbot-keys-submit" class="button button-secondary">Guardar credenciales</button>
				<span id="chatbot-keys-result" style="margin-left:12px"></span>
			</p>
		</form>

		<details style="margin-top:16px;">
			<summary>Mostrar detalle técnico</summary>

		<h3>Registro de auditoría</h3>
		<!-- Tabla con scroll para que no expanda la página. -->
		<div style="max-height:240px;overflow:auto;border:1px solid #ddd;padding:8px;background:#fff">
		<table class="widefat fixed" style="margin:0;border-collapse:collapse;">
			<thead>
				<tr>
					<th style="width:150px">Fecha</th>
					<th style="width:80px">Nivel</th>
					<th>Mensaje</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $audit ) ) : ?>
					<tr><td colspan="3">No hay entradas en el registro de auditoría.</td></tr>
				<?php else : ?>
					<?php foreach ( $audit as $entry ) : ?>
						<tr>
							<td><?php echo htmlspecialchars( $entry['created_at'] ?? '', ENT_QUOTES, 'UTF-8' ); ?></td>
							<td><?php echo htmlspecialchars( $entry['level'] ?? '', ENT_QUOTES, 'UTF-8' ); ?></td>
							<td><?php echo htmlspecialchars( $entry['message'] ?? '', ENT_QUOTES, 'UTF-8' ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div>
		</details>
		<p>
				<!-- Botón que reutiliza el AJAX del admin para reintentar el envío al MCP. -->
			<button id="chatbot-resend" class="button button-primary">Actualizar conexión</button>
			<span id="chatbot-result" style="margin-left:10px"></span>
		</p>
	<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
	var btn = document.getElementById('chatbot-resend');
	if (!btn) return;
	btn.addEventListener('click', function(){
		var result = document.getElementById('chatbot-result');
		result.textContent = 'Enviando...';
		var xhr = new XMLHttpRequest();
		var nonce = <?php echo json_encode( $nonce ?? '' ); ?>;
		var url = ajaxurl + '?action=chatbot_resend_provision&_ajax_nonce=' + encodeURIComponent(nonce);
		xhr.open('GET', url);
		xhr.onload = function(){
			try{
				var resp = JSON.parse(xhr.responseText);
				if ( resp.success && resp.data && resp.data.status ) {
					var code = resp.data.status;
					if ( code >= 200 && code < 300 ) {
						result.textContent = 'Conexión actualizada (' + code + ')';
						setTimeout(function(){ window.location.reload(); }, 800);
					} else {
						result.textContent = 'Error (' + code + ')';
					}
				} else {
					result.textContent = 'Respuesta inesperada';
				}
			} catch(e){ result.textContent = 'Respuesta no procesable'; }
		};
		xhr.onerror = function(){ result.textContent = 'Error de red'; };
		xhr.send();
	});
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function(){
	var form = document.getElementById('chatbot-keys-form');
	if (!form) return;
	form.addEventListener('submit', function(e){
		e.preventDefault();
		var btn = document.getElementById('chatbot-keys-submit');
		var result = document.getElementById('chatbot-keys-result');
		btn.disabled = true;
		result.textContent = 'Guardando...';

		var fd = new FormData();
		fd.append('action', 'chatbot_update_keys');
		fd.append('_ajax_nonce', <?php echo json_encode( $nonce_update ?? '' ); ?> );
		fd.append('consumerKey', document.getElementById('chatbot_consumerKey').value );
		fd.append('consumerSecret', document.getElementById('chatbot_consumerSecret').value );

		fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function(resp){ return resp.json().then(function(json){ return { status: resp.status, body: json }; }); })
			.then(function(obj){
				var status = obj.status;
				var json = obj.body;
				if ( status >= 200 && status < 300 && json && json.success ) {
					result.textContent = 'Guardado correctamente';
					setTimeout(function(){ window.location.reload(); }, 800);
				} else if ( json && json.data && json.data.error === 'validation_failed' ) {
					result.textContent = json.data.message || 'Validación fallida';
				} else if ( json && json.data && json.data.error ) {
					result.textContent = 'Error: ' + json.data.error;
				} else {
					result.textContent = 'Respuesta inesperada';
				}
			})
			.catch(function(){ result.textContent = 'Error de red'; })
			.finally(function(){ btn.disabled = false; setTimeout(function(){ result.textContent = ''; }, 4000); });
	});
});
</script>
