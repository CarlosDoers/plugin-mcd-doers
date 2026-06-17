=== Doers AI Connector ===
Contributors: doers
Tags: ai, mcp, claude, abilities, automation
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Conecta tu WordPress a asistentes de IA (Claude y otros clientes MCP) para desarrollar y gestionar tu web hablando por chat.

== Description ==

Doers AI Connector registra un conjunto de abilities (Abilities API, core 6.9+) y las expone como servidor MCP propio mediante el MCP Adapter oficial de WordPress.

Capacidades por grupos (activables individualmente):

* Contenido: listar, leer, crear y actualizar páginas/entradas con markup de bloques; fijar portada.
* Archivos de tema: listar, leer y escribir con backup automático y restauración.
* Temas y FSE: listar/activar temas, plantillas FSE personalizadas, menús de navegación.
* Plugins: listar, activar/desactivar e instalar desde WordPress.org.
* Medios: listar y subir a la biblioteca.
* Ajustes del sitio: título, portada, permalinks (lista blanca).

Seguridad: capability mínima por ability, modo solo lectura, límite de escrituras por hora, confirmación en operaciones destructivas, backups automáticos, registro de auditoría y usuario dedicado con permisos acotados creado en un clic.

== Installation ==

1. Sube e instala el plugin.
2. En Ajustes → Doers AI, pulsa "Instalar automáticamente" si falta el MCP Adapter.
3. Crea el usuario AI dedicado y copia su contraseña de aplicación.
4. Añade la configuración mostrada a tu cliente MCP (p. ej. Claude Desktop).

== Changelog ==

= 0.3.0 =
* Servidor OAuth 2.1 integrado: descubrimiento (RFC 8414/9728), registro dinámico de clientes (RFC 7591), authorization code + PKCE S256 y rotación de refresh tokens.
* Conexión directa desde claude.ai como conector personalizado, sin proxy ni contraseñas de aplicación (requiere HTTPS).
* Pantalla de consentimiento con login de WordPress.
* Gestión de clientes autorizados y revocación de tokens desde el panel.


= 0.2.0 =
* Nuevos grupos de abilities: medios, plantillas FSE, navegación, instalación de plugins, ajustes del sitio.
* Panel de control: grupos activables, modo solo lectura, rate limit, retención de auditoría.
* Usuario AI dedicado con rol de permisos mínimos y contraseña de aplicación en un clic.
* Restauración de backups desde el panel y vía MCP.
* Instalador automático del MCP Adapter.
* Confirmación obligatoria en operaciones destructivas.

= 0.1.0 =
* Versión inicial: 11 abilities, servidor MCP propio, auditoría y backups.
