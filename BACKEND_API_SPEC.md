# Shopia Backend — API Specification

**Base URL:** `https://api.shopia.io/api`  
**Version:** 1.0.1  
**Authentication:** Bearer Token (API Key generada por el plugin en instalación)

---

## Contexto de la arquitectura

El plugin de WordPress **nunca habla con el MCP directamente**. Toda comunicación ocurre entre el plugin y el backend. El backend gestiona internamente el MCP, PostgreSQL, DynamoDB y la cola de mensajes.

```
Plugin WordPress (PHP)
        ↓ HTTPS — Bearer API Key
   Backend Node.js
        ↓ HTTP interno
       MCP Server
        ↓
   WooCommerce
```

**Credenciales de WooCommerce (CK/CS):**  
El plugin las **crea programáticamente** usando la API interna de WordPress al momento del onboarding. Las guarda en `wp_woocommerce_api_keys` (tabla nativa de WooCommerce) y las envía al backend en texto plano **una sola vez** por HTTPS. El backend las cifra con AES-256 y las guarda en PostgreSQL. El plugin nunca vuelve a necesitarlas.

**Tokens de WhatsApp:**  
El backend los obtiene directamente desde Meta via OAuth. El plugin nunca los ve ni los almacena.

---

## Autenticación

Todos los endpoints excepto `POST /tenants` requieren el header:

```
Authorization: Bearer <api_key>
```

La API Key es generada por el plugin al instalarse (UUID v4), guardada en `wp_options` en texto plano para uso local, y registrada en el backend **solo como hash SHA-256**. El backend verifica cada request hasheando el Bearer token recibido y comparando contra el hash almacenado — la key real nunca se guarda en ninguna base de datos.

---

## Flujo completo del onboarding

```
POST /tenants                          → Paso 1: registra tenant, recibe tenant_id
POST /tenants/{id}/wc/validate         → Paso 2: plugin crea CK/CS y las valida
POST /tenants/{id}/wa/signup           → Paso 3: Embedded Signup OAuth de Meta
PATCH /tenants/{id}/config             → Paso 4: personalización del bot
POST /tenants/{id}/activate            → Paso 5: activa bot (requiere wc + wa verificados)
```

**Transiciones de estado:**
```
pending → wc_verified → wa_verified → configured → active → suspended → active
```

El wizard puede retomarse — si el emprendedor cierra el browser, el `current_step` persiste en el backend y el plugin lo consulta al volver a abrir el panel.

---

## Endpoints

---

### 1. POST /tenants
**Paso 1 — Registro inicial del tenant**

El plugin genera la API Key localmente, la hashea y la envía junto con los datos básicos de la tienda. El backend crea el registro en PostgreSQL y devuelve el `tenant_id` que el plugin guardará en `wp_shopia_tenants` para todos los requests posteriores.

**Request:**
```json
{
  "api_key_hash": "sha256_del_api_key_generado_por_el_plugin",
  "site_url": "https://mitienda.com",
  "store_name": "Mi Tienda Online",
  "wp_version": "6.9.1",
  "wc_version": "8.2.1",
  "plugin_version": "1.0.0"
}
```

> ⚠ Único endpoint sin Authorization header — es el primer handshake entre plugin y backend.  
> El plugin envía `api_key_hash` en el body, no en el header.

**Response 201 Created:**
```json
{
  "success": true,
  "tenant_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "current_step": 1,
  "status": "pending",
  "message": "Tenant registrado correctamente"
}
```

**Errores:**
| Código | Error | Causa |
|--------|-------|-------|
| `400` | `MISSING_FIELDS` | Falta site_url, api_key_hash u otros campos requeridos |
| `409` | `TENANT_EXISTS` | Ya existe un tenant con ese api_key_hash |
| `500` | `DATABASE_ERROR` | Error interno de PostgreSQL |

---

### 2. POST /tenants/{tenant_id}/wc/validate
**Paso 2 — Creación y validación de credenciales WooCommerce**

El plugin crea las Consumer Key y Consumer Secret programáticamente usando la API interna de WordPress (`wp_woocommerce_api_keys`), las captura en texto plano en ese momento (única oportunidad — WooCommerce solo guarda el hash después) y las envía al backend en este request.

**Cómo el plugin crea las keys (PHP interno — no es parte del request HTTP):**
```php
$consumer_key    = 'ck_' . wc_rand_hash();
$consumer_secret = 'cs_' . wc_rand_hash();

$wpdb->insert($wpdb->prefix . 'woocommerce_api_keys', [
    'user_id'         => get_current_user_id(),
    'description'     => 'Shopia - Asistente de Ventas',
    'permissions'     => 'read_write',
    'consumer_key'    => wc_api_hash($consumer_key),  // hash — WC solo guarda esto
    'consumer_secret' => $consumer_secret,
    'truncated_key'   => substr($consumer_key, -7),
]);
// En este momento el plugin tiene CK/CS en texto plano → las envía al backend
// Después de este request, ya no puede recuperarlas (WC solo tiene el hash)
```

**Request:**
```json
{
  "consumer_key": "ck_abc123...",
  "consumer_secret": "cs_xyz789...",
  "wc_api_url": "https://mitienda.com/wp-json/wc/v3"
}
```

> 🔒 El plugin transmite CK/CS una sola vez aquí — inmediatamente después de crearlas.  
> El backend las cifra con AES-256 y las guarda en PostgreSQL.  
> El plugin nunca vuelve a tener acceso a ellas en texto plano.

**El backend al recibir este request:**
1. Verifica la conexión haciendo GET /products real a la tienda WooCommerce
2. Registra las credenciales en el MCP Server (registro en memoria)
3. Cifra CK/CS con AES-256 y las guarda en PostgreSQL
4. Elimina las credenciales en texto plano de memoria

**Response 200 OK:**
```json
{
  "success": true,
  "wc_verified": true,
  "mcp_registered": true,
  "product_count": 42,
  "current_step": 2,
  "verified_at": "2026-06-26T10:30:00Z"
}
```

**Errores:**
| Código | Error | Causa |
|--------|-------|-------|
| `400` | `MISSING_FIELDS` | Faltan consumer_key, consumer_secret o wc_api_url |
| `401` | `INVALID_API_KEY` | Bearer token inválido o no coincide con el tenant |
| `422` | `WC_AUTH_FAILED` | WooCommerce rechazó las credenciales (401/403 de WC) |
| `422` | `WC_CONNECTION_FAILED` | No se pudo conectar a la tienda (timeout, URL incorrecta) |
| `404` | `TENANT_NOT_FOUND` | El tenant_id no existe en PostgreSQL |
| `500` | `MCP_REGISTRATION_FAILED` | El backend no pudo registrar el tenant en el MCP |
| `500` | `DATABASE_ERROR` | Error al guardar credenciales cifradas |

---

### 3. GET /tenants/{tenant_id}/wa/signup-url
**Auxiliar — Genera la URL y el state_token para el popup de Meta**

Antes de abrir el popup de Embedded Signup, el plugin llama este endpoint para obtener la URL exacta con el `state_token` ya embebido. El backend genera el state_token, lo guarda en DynamoDB con TTL de 10 minutos, y lo embebe en la URL de Meta.

**Response 200 OK:**
```json
{
  "signup_url": "https://www.facebook.com/dialog/oauth?client_id=...&state=uuid...",
  "state_token": "uuid-del-state-para-validar-callback",
  "expires_in": 600
}
```

---

### 4. POST /tenants/{tenant_id}/wa/signup
**Paso 3 — Embedded Signup de Meta (WhatsApp)**

El popup de Meta Embedded Signup devuelve un `code` OAuth al frontend del plugin. El plugin lo reenvía al backend, que:
1. Valida el `state_token` para prevenir CSRF
2. Intercambia el `code` por un System User Token permanente (no expira)
3. Obtiene el Phone Number ID y WABA ID via Graph API de Meta
4. Registra el webhook del backend en Meta
5. Guarda el token cifrado en PostgreSQL — el plugin nunca ve el token real

**Request:**
```json
{
  "oauth_code": "AQD3xK9m...",
  "state_token": "uuid-generado-por-el-backend-antes-del-popup"
}
```

**Response 200 OK:**
```json
{
  "success": true,
  "wa_verified": true,
  "phone_number": "+506 8888-9999",
  "webhook_registered": true,
  "current_step": 3,
  "verified_at": "2026-06-26T10:35:00Z"
}
```

> 🔒 El System User Token nunca aparece en la respuesta al plugin.

**Errores:**
| Código | Error | Causa |
|--------|-------|-------|
| `400` | `MISSING_FIELDS` | Falta oauth_code o state_token |
| `400` | `INVALID_STATE_TOKEN` | El state_token no coincide o expiró (TTL 10 min) |
| `401` | `INVALID_API_KEY` | Bearer token inválido |
| `401` | `OAUTH_CODE_EXPIRED` | El código OAuth de Meta ya fue usado o expiró |
| `403` | `META_PERMISSION_DENIED` | Meta rechazó los permisos solicitados |
| `422` | `WEBHOOK_REGISTRATION_FAILED` | El backend no pudo registrar el webhook en Meta |
| `404` | `TENANT_NOT_FOUND` | El tenant_id no existe |
| `500` | `DATABASE_ERROR` | Error al guardar token cifrado |

---

### 5. PATCH /tenants/{tenant_id}/config
**Paso 4 — Personalización del asistente**

Guarda el nombre del bot, mensaje de bienvenida e idioma en PostgreSQL. Estos valores personalizan el system prompt del agente para esa tienda específica.

**Request:**
```json
{
  "bot_name": "Sofía",
  "welcome_message": "¡Hola! Soy Sofía, tu asistente de compras 👋 ¿En qué te ayudo?",
  "language": "es"
}
```

**Response 200 OK:**
```json
{
  "success": true,
  "bot_name": "Sofía",
  "welcome_message": "¡Hola! Soy Sofía...",
  "language": "es",
  "current_step": 4,
  "saved_at": "2026-06-26T10:40:00Z"
}
```

**Errores:**
| Código | Error | Causa |
|--------|-------|-------|
| `400` | `MISSING_FIELDS` | bot_name o welcome_message vacíos |
| `400` | `INVALID_LANGUAGE` | Idioma no soportado (solo "es" y "en" por ahora) |
| `401` | `INVALID_API_KEY` | Bearer token inválido |
| `404` | `TENANT_NOT_FOUND` | El tenant_id no existe |
| `500` | `DATABASE_ERROR` | Error al guardar configuración |

---

### 6. POST /tenants/{tenant_id}/activate
**Paso 5 — Activación del bot**

Activa el tenant. El backend verifica internamente que `wc_verified = true AND wa_verified = true` antes de cambiar el status a `active`.

**Request:** Body vacío `{}`

**Response 200 OK:**
```json
{
  "success": true,
  "status": "active",
  "bot_name": "Sofía",
  "phone_number": "+506 8888-9999",
  "activated_at": "2026-06-26T10:45:00Z",
  "bot_ready": true
}
```

**Error 400 con detalle de qué falta:**
```json
{
  "success": false,
  "error": "SETUP_INCOMPLETE",
  "message": "Faltan verificaciones para activar el bot",
  "missing": ["wa_verified"]
}
```

**Errores:**
| Código | Error | Causa |
|--------|-------|-------|
| `400` | `SETUP_INCOMPLETE` | wc_verified o wa_verified = false |
| `401` | `INVALID_API_KEY` | Bearer token inválido |
| `404` | `TENANT_NOT_FOUND` | El tenant_id no existe |
| `409` | `ALREADY_ACTIVE` | El tenant ya está en status active — devuelve 200 OK idempotente |
| `500` | `DATABASE_ERROR` | Error interno |

---

### 7. GET /tenants/{tenant_id}/status
**Estado del tenant — el plugin consulta esto al abrir el panel de WordPress**

**Response 200 OK:**
```json
{
  "tenant_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "status": "active",
  "current_step": 5,
  "bot_name": "Sofía",
  "phone_number": "+506 8888-9999",
  "wc_status": "ok",
  "wa_status": "ok",
  "mcp_status": "ok",
  "conversations_this_month": 143,
  "plan_limit": 300,
  "plan": "starter",
  "created_at": "2026-06-26T10:00:00Z",
  "updated_at": "2026-06-26T10:45:00Z"
}
```

**Errores:**
| Código | Error | Causa |
|--------|-------|-------|
| `401` | `INVALID_API_KEY` | Bearer token inválido |
| `404` | `TENANT_NOT_FOUND` | El tenant_id no existe |

---

### 8. PATCH /tenants/{tenant_id}/toggle
**Pausa o reactiva el bot**

**Request:**
```json
{ "action": "pause" }
```
> Valores válidos: `"pause"` o `"resume"`

**Response 200 OK:**
```json
{
  "success": true,
  "status": "suspended",
  "message": "Bot pausado correctamente"
}
```

---

### 9. POST /tenants/{tenant_id}/wc/reconnect
**Reconecta WooCommerce cuando las credenciales cambiaron**

El plugin crea nuevas CK/CS programáticamente (mismo proceso que el Paso 2), las captura y las envía. El backend actualiza las credenciales cifradas en PostgreSQL y el registro en el MCP.

**Request:**
```json
{
  "consumer_key": "ck_nuevas...",
  "consumer_secret": "cs_nuevas...",
  "wc_api_url": "https://mitienda.com/wp-json/wc/v3"
}
```

**Response 200 OK:**
```json
{
  "success": true,
  "wc_verified": true,
  "mcp_updated": true,
  "message": "WooCommerce reconectado correctamente",
  "verified_at": "2026-06-26T11:00:00Z"
}
```

---

### 10. POST /tenants/{tenant_id}/wa/reconnect
**Reconecta WhatsApp si el token fue revocado**

**Request:** Body vacío `{}`

**Response 200 OK:**
```json
{
  "success": true,
  "signup_url": "https://www.facebook.com/dialog/oauth?...",
  "state_token": "nuevo-uuid",
  "expires_in": 600,
  "message": "Inicia el flujo de reconexión de WhatsApp"
}
```

---

## Formato de errores

```json
{
  "success": false,
  "error": "ERROR_CODE",
  "message": "Descripción legible para el usuario final",
  "missing": ["campo_que_falta"],
  "detail": "Información técnica adicional (solo en desarrollo)"
}
```

### Códigos de error completos

| Código | Descripción |
|--------|-------------|
| `MISSING_FIELDS` | Campos requeridos ausentes en el body |
| `INVALID_API_KEY` | Bearer token no válido o no coincide con el tenant |
| `TENANT_EXISTS` | Ya existe un tenant con ese api_key_hash |
| `TENANT_NOT_FOUND` | El tenant_id no existe en la base de datos |
| `WC_AUTH_FAILED` | WooCommerce rechazó las credenciales |
| `WC_CONNECTION_FAILED` | No se pudo conectar a la tienda WooCommerce |
| `INVALID_STATE_TOKEN` | El state_token OAuth no es válido o expiró |
| `OAUTH_CODE_EXPIRED` | El código OAuth de Meta expiró o ya fue usado |
| `META_PERMISSION_DENIED` | Meta rechazó los permisos de la app |
| `WEBHOOK_REGISTRATION_FAILED` | Error al registrar el webhook en Meta |
| `MCP_REGISTRATION_FAILED` | Error al registrar el tenant en el MCP Server |
| `SETUP_INCOMPLETE` | No se completaron todos los pasos del wizard |
| `ALREADY_ACTIVE` | El tenant ya está activo |
| `INVALID_LANGUAGE` | Idioma no soportado |
| `DATABASE_ERROR` | Error interno de base de datos |
| `UNKNOWN_ERROR` | Error no categorizado |

---

## Rate Limiting

- **Límite:** 100 requests por minuto por API Key
- **Headers de respuesta:**
```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 87
X-RateLimit-Reset: 1719396060
```

---

## Webhooks del backend al plugin

El backend notifica al plugin de cambios de estado vía webhook. El plugin expone un endpoint interno en WordPress.

**POST {site_url}/wp-json/shopia/v1/webhook**

```json
{
  "event": "tenant.activated",
  "tenant_id": "a1b2c3d4-...",
  "timestamp": "2026-06-26T10:45:00Z",
  "data": {
    "status": "active",
    "bot_name": "Sofía",
    "phone_number": "+506 8888-9999"
  }
}
```

**Eventos disponibles:**

| Evento | Cuándo se dispara |
|--------|-------------------|
| `tenant.activated` | El bot quedó activo |
| `tenant.suspended` | El bot fue pausado |
| `tenant.error` | Error de credenciales o conexión |
| `wc.credentials_invalid` | Las CK/CS de WooCommerce dejaron de funcionar |
| `wa.token_revoked` | El token de WhatsApp fue revocado desde Meta |
| `plan.limit_warning` | El tenant llegó al 80% del límite de su plan |
| `plan.limit_reached` | El tenant llegó al 100% del límite |

---

## Notas de implementación

### Plugin (PHP)
- Generar API Key con `wp_generate_uuid4()` al activar el plugin
- Guardar API Key en texto plano en `wp_options` (solo acceso local)
- Guardar `tenant_id` y `api_key_hash` en `wp_shopia_tenants`
- Crear CK/CS de WooCommerce programáticamente con `wc_rand_hash()` en el Paso 2
- Capturar CK/CS inmediatamente después de crearlas — WooCommerce solo guarda el hash después
- Enviar CK/CS al backend en texto plano por HTTPS — nunca guardarlas localmente
- En reconexión: crear CK/CS nuevas (mismo proceso), invalidar las anteriores desde WC
- Siempre enviar `Authorization: Bearer {api_key}` en texto plano — el backend hace el hash internamente

### Backend (Node.js)
- Verificar API Key hasheando el Bearer recibido y comparando contra PostgreSQL
- Cifrar CK/CS y tokens de WhatsApp con AES-256 antes de persistir
- El `tenant_id` es generado en el backend (UUID v4), no en el plugin
- Las transiciones de `current_step` son unidireccionales: 1→2→3→4→5
- El endpoint `/activate` es idempotente — si ya está activo, devolver 200 OK
- Al arrancar el MCP Server: cargar todos los tenants activos desde PostgreSQL al registro en memoria
- Fallback lazy: si llega un request de un tenant no en memoria, consultarlo desde PostgreSQL

### Seguridad
- Todo el tráfico por HTTPS — nunca HTTP
- Los tokens de WhatsApp nunca se loguean ni aparecen en respuestas al plugin
- El MCP Server no es accesible públicamente — solo desde la red interna de Docker
- La API Key en texto plano vive solo en `wp_options` — nunca en ninguna BD en texto plano
- Las CK/CS de WooCommerce existen en texto plano solo durante el Paso 2, en memoria, mientras se transmiten

---

*Shopia Backend API v1.0.1 — Junio 2026*