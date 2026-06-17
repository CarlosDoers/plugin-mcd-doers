# Doers AI Connector

Conecta cualquier WordPress (nuevo o existente) a asistentes de IA mediante MCP. Pensado para que un cliente instale el plugin, conecte su Claude y pueda desarrollar/gestionar su web hablando por chat.

## Arquitectura

- **Abilities API** (core WP 6.9+): cada capacidad se registra con `wp_register_ability()`, con schema tipado y `permission_callback` por capability.
- **MCP Adapter** (plugin oficial, dependencia): expone las abilities como servidor MCP propio en `/wp-json/doers-ai/mcp`.
- Este plugin solo aporta las abilities, la seguridad y la UI — la fontanería MCP es del adapter.

## Requisitos

1. WordPress 6.9 o superior.
2. Plugin [MCP Adapter](https://github.com/WordPress/mcp-adapter/releases) instalado y activo.

## Novedades v0.5.0 — Contexto del proyecto

- Nueva sección **Contexto del proyecto** en *Ajustes → Doers AI* y ability **`doers/get-project-context`** que entrega a la IA la identidad y el sistema de diseño de la web. Viaja con el sitio aunque se conecte por MCP sin carpeta.
- Fuentes de contexto: **marca y voz** (campos manuales), **sistema de diseño manual** (colores, tipografías, logo), **tokens automáticos del `theme.json`** del tema activo, **documentos en Markdown** (editables o subidos), y **sincronización con Figma**.
- **Sync con Figma:** intenta la API de variables (plan Enterprise) y, si no está disponible, cae a estilos publicados + nodos para extraer colores y tipografías. El token se guarda aparte y nunca se expone por MCP.

## Novedades v0.4.0 — Seguridad de archivos

- **Validación de PHP** con `php -l` antes de guardar y **auto-rollback** si una escritura deja el front con error 500.
- **Confirmación** obligatoria al sobrescribir archivos críticos (`functions.php`, `style.css`).
- Carpeta de **backups protegida** (`.htaccess`/`index.php`), retención de los últimos 10 por archivo y límite de 2 MB por escritura.

## Novedades v0.2.0

- **6 grupos de abilities activables** desde el panel: contenido, archivos, temas/FSE, plugins, medios y ajustes.
- **Seguridad avanzada:** modo solo lectura, límite de escrituras/hora, confirmación obligatoria (`confirm=true`) en operaciones destructivas (activar tema, desactivar plugin, resetear plantilla, restaurar backup).
- **Usuario AI dedicado:** rol `doers_ai_agent` con permisos acotados + contraseña de aplicación generada en un clic.
- **Backups gestionables:** listado y restauración desde el panel y vía MCP (`doers/list-backups`, `doers/restore-backup`).
- **Instalador automático del MCP Adapter** desde la última release oficial.
- **Nuevas abilities:** `upload-media`, `list-media`, `list-templates`, `reset-template`, `list-navigation`, `save-navigation`, `install-plugin`, `update-site-settings`, `list-backups`, `restore-backup` (21 en total).
- Distribución: `readme.txt` estándar y `uninstall.php` con limpieza.

## Abilities incluidas (v0.1.0)

| Ability | Qué hace | Capability mínima |
|---|---|---|
| `doers/site-info` | Info del sitio | `manage_options` |
| `doers/list-content` | Listar posts/páginas | `edit_posts` |
| `doers/get-content` | Leer contenido por ID | `edit_posts` |
| `doers/save-content` | Crear/actualizar contenido, fijar portada | `publish_pages` |
| `doers/list-theme-files` | Listar archivos de un tema | `edit_themes` |
| `doers/read-theme-file` | Leer archivo de tema | `edit_themes` |
| `doers/write-theme-file` | Escribir archivo de tema (con backup) | `edit_themes` |
| `doers/list-themes` | Listar temas | `switch_themes` |
| `doers/activate-theme` | Activar tema | `switch_themes` |
| `doers/list-plugins` | Listar plugins | `activate_plugins` |
| `doers/toggle-plugin` | Activar/desactivar plugin | `activate_plugins` |

## Seguridad

- Cada ability exige la capability mínima de WordPress; el cliente MCP actúa como un usuario logueado.
- Escrituras de archivos: solo dentro de `wp-content/themes`, extensiones permitidas, sin `..`, y **backup automático** previo en `uploads/doers-ai-backups/` (carpeta protegida con `.htaccess`/`index.php`; conserva los últimos 10 backups por archivo; límite de 2 MB por escritura).
- **Protección contra "romper la web":** los archivos PHP se validan con `php -l` antes de guardar; tras escribir un `.php` se comprueba que el front no devuelve error 500 y, si lo hace, **se revierte automáticamente** al estado anterior. Sobrescribir archivos críticos (`functions.php`, `style.css`) exige `confirm=true`.
- Respeta `DISALLOW_FILE_EDIT` / `DISALLOW_FILE_MODS`.
- El conector no puede desactivarse a sí mismo vía MCP.
- Auditoría de todas las ejecuciones en *Ajustes → Doers AI*.
- Recomendación: usuario dedicado con contraseña de aplicación, no el admin principal.

## Conexión

Ver *Ajustes → Doers AI* en wp-admin: muestra el endpoint y la configuración lista para copiar en Claude Desktop (vía proxy `@automattic/mcp-wordpress-remote`).

## Roadmap

- OAuth 2.1 para conexión un-clic desde claude.ai.
- Abilities de patrones de bloques, menús y plantillas FSE.
- Bundle del adapter vía Composer + Jetpack Autoloader para distribuir como un único zip.
