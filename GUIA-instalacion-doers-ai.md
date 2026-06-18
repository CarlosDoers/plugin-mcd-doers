# Guía — Doers AI Connector

**Versión del plugin: 0.9.0**

Plugin de WordPress que permite **crear y editar contenido desde el chat de Claude**, reutilizando los **bloques que ya existen** en la web. Está pensado para **webs ya hechas** (vuestras o de cliente): se instala el plugin y, sin entrar al wp-admin, el cliente o el equipo pueden pedir desde un chat cosas como "créame una página de servicios con el bloque hero y el de pasos" o "publica un post en la sección de noticias".

No edita el tema ni toca archivos: solo gestiona **contenido** (páginas y entradas), **bloques** (los descubre para componer) y **medios**.

---

## Cómo funciona (resumen)

- El plugin convierte el WordPress en un **servidor MCP**: expone acciones que Claude puede usar (listar/crear/editar contenido, listar bloques, subir medios).
- Se apoya en dos piezas oficiales: la **Abilities API** (del core de WordPress 6.9+) y el **MCP Adapter** (que el plugin instala con un clic).
- El **contexto del proyecto** (qué es la web, tono, convenciones, qué bloques usar) **no vive en el plugin**: se aporta como un archivo **`CLAUDE.md`** en la carpeta que se conecta en **Cowork**. Claude lo lee automáticamente al empezar cada chat.

## Requisitos

- WordPress **6.9 o superior** y PHP **7.4 o superior**.
- Acceso de administrador al sitio.
- **Cowork** (app de escritorio de Claude) para conectar la carpeta del proyecto.
- Para conexión en local: Node.js (el proxy se ejecuta con `npx`).

---

## Paso 1 — Instalar el plugin y el MCP Adapter

1. En wp-admin: **Plugins → Añadir nuevo → Subir plugin** → sube `doers-ai-connector.zip` → **Instalar** → **Activar**.
2. Ve a **Ajustes → Doers AI**. En la tabla **Estado**, junto a *MCP Adapter*, pulsa **Instalar automáticamente** (descarga y activa la última versión oficial).
3. Comprueba que *Abilities API* y *MCP Adapter* están en ✅.

## Paso 2 — Crear las credenciales para la IA

En *Ajustes → Doers AI → Conexión*, pulsa **Crear usuario AI dedicado**. Crea el usuario `doers-ai` con **permisos mínimos** (solo contenido y medios) y genera una **contraseña de aplicación** que se muestra **una sola vez** — cópiala en ese momento.

> Usa siempre este usuario dedicado, nunca tu cuenta de administrador.

## Paso 3 — Conectar Claude (Cowork)

Hay dos vías de conexión del conector MCP, según el sitio:

**En local** (dominios `.local`): añade el sitio al archivo de configuración de Claude (Claude → Ajustes → Desarrollador → Editar configuración), dentro de `mcpServers`, con el snippet que te muestra *Ajustes → Doers AI* (sección Conexión). Sustituye `CONTRASEÑA-DE-APLICACIÓN` por la del paso 2. Reinicia Claude.

```json
"mcpServers": {
  "mi-sitio": {
    "command": "npx",
    "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
    "env": {
      "WP_API_URL": "http://mi-sitio.local/wp-json/doers-ai/mcp",
      "WP_API_USERNAME": "doers-ai",
      "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
    }
  }
}
```

**En producción (HTTPS):** en *Ajustes → Conectores → Añadir conector personalizado*, pega el endpoint MCP del sitio y autoriza con OAuth (un clic, sin contraseñas).

**Además, conecta la carpeta del proyecto en el chat de Cowork.** Esta carpeta es donde vivirá el `CLAUDE.md` de contexto (paso 5). Puede ser una carpeta dedicada por cliente; no hace falta que sea la del WordPress.

## Paso 4 — Configurar abilities y seguridad

En *Ajustes → Doers AI → Abilities y seguridad*:

- **Grupos habilitados:** Contenido y bloques, Medios. Desactiva el que no quieras exponer.
- **Modo solo lectura:** bloquea toda escritura (útil para una primera sesión de exploración en un sitio de cliente).
- **Conexión OAuth 2.1:** activa/desactiva la conexión directa de clientes remotos.
- **Límite de escrituras/hora** y **entradas de auditoría**.

---

## Paso 5 — El archivo de contexto (`CLAUDE.md`)

El contexto del proyecto se aporta como un **`CLAUDE.md`** en la raíz de la carpeta conectada. Claude lo lee solo en cada chat, así que el contexto está **siempre presente** sin repetir nada. Lo aporta el equipo (no lo genera ni lo mantiene el plugin); puedes redactarlo a mano o pedirle a Claude que lo prepare en la sesión de instalación y tú lo apruebas.

### Prompt de instalación (primera vez)

Con el conector y la carpeta ya conectados, pega esto (adjunta también el documento/notas de contexto que tengas):

> Primera vez en este sitio. Estás conectado por MCP al WordPress **[NOMBRE]** (plugin Doers AI Connector) y te he conectado la carpeta de este cliente. Te paso también **[documento/notas de contexto]**.
>
> Haz esto y no modifiques el contenido del sitio todavía:
> 1. Explora con `list-content` y `list-blocks` para conocer la web y sus bloques.
> 2. Crea un archivo **`CLAUDE.md`** en la raíz de la carpeta con: qué es la web y a quién va dirigida, tono de marca, secciones/estructura, y las reglas de trabajo — "crea y amplía páginas **reutilizando los bloques existentes**; consulta siempre `list-blocks` para el catálogo actual; pide confirmación antes de **publicar o sobrescribir**". Incorpora lo relevante del documento que te he pasado. **No** metas el listado de bloques a pelo: remite a `list-blocks`.
> 3. Enséñame el `CLAUDE.md` antes de darlo por bueno. Una vez aprobado, queda fijo; no lo regeneres en cada sesión.

### Prompt del día a día (siguientes chats)

> Sitio **[NOMBRE]** (Cowork + carpeta conectada + conector MCP). Lee el `CLAUDE.md` de la carpeta y consulta `list-blocks`. Tarea: **[describe qué crear o cambiar]**.

---

## Cómo se crea contenido con bloques

1. Claude consulta **`list-blocks`**, que devuelve: los **bloques a medida (ACF)** con sus campos (nombre, *field key*, tipo), los **block patterns** y los **bloques sincronizados**.
2. Compone el contenido con markup de bloques y lo guarda con **`save-content`** (crear o actualizar; permite fijar portada).
3. Para **ampliar** una página existente, Claude lee su contenido con `get-content`, añade los bloques y guarda.

Ejemplos de lo que puedes pedir:

- "Créame una página *Servicios* reutilizando el bloque hero y el de pasos, con este texto…"
- "Añade al final de la home el bloque de testimonios con estas tres reseñas."
- "Publica un post en Noticias titulado *…* con dos párrafos y una imagen."

---

## Seguridad

- Cada acción exige el **permiso mínimo de WordPress** y se ejecuta como el **usuario dedicado** `doers-ai` (solo contenido y medios), nunca el admin.
- **Modo solo lectura**, **límite de escrituras por hora** y **registro de auditoría** de todas las ejecuciones, en *Ajustes → Doers AI*.
- El plugin **no edita archivos del tema ni del sitio**: su alcance es contenido, bloques y medios.

---

## Resolución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| El conector no aparece en el chat | Sitio parado, JSON mal formado o Node ausente | Arranca el sitio en Local, valida el JSON, instala Node y reinicia Claude |
| "Falta el MCP Adapter" | Adapter no instalado | *Ajustes → Doers AI → Instalar automáticamente* |
| Error 401/403 al conectar | Credenciales mal copiadas | La contraseña de aplicación incluye los espacios; cópiala exacta o regénérala |
| Claude no respeta el contexto | No hay `CLAUDE.md` o no se conectó la carpeta | Conecta la carpeta y crea el `CLAUDE.md` (paso 5) |
| Claude no usa los bloques a medida | No consultó el catálogo | Pídele que ejecute `list-blocks` primero; recógelo en el `CLAUDE.md` |
| No deja escribir | Modo solo lectura activado | Desactívalo en *Ajustes → Doers AI* |

---

## Qué incluye el plugin (acciones MCP)

- **Contenido y bloques:** `list-blocks`, `list-content`, `get-content`, `save-content`.
- **Medios:** `list-media`, `upload-media`.
