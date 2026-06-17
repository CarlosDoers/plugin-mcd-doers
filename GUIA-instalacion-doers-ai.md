# Guía: WordPress nuevo + Doers AI Connector + Claude, desde cero

Objetivo: partir de cero con un sitio nuevo en Local WP y llegar a desarrollar la web hablando con Claude. Tiempo estimado: **10-15 minutos**.

Necesitas tener instalado: Local WP, la app de Claude para escritorio y Node.js (nodejs.org).

> Versión del plugin: **0.4.0**.

---

## Paso 1 — Crear el sitio en Local WP (2 min)

1. Local WP → **+ Create a new site** → nombre (p. ej. `mi-proyecto`) → entorno *Preferred* → crea el usuario admin.
2. Arranca el sitio (botón verde) y abre **WP Admin**.
3. En wp-admin: **Ajustes → Enlaces permanentes** → selecciona **"Nombre de la entrada"** → Guardar. *(Imprescindible: sin esto el endpoint REST del MCP no funciona.)*

## Paso 2 — Instalar Doers AI Connector (2 min)

1. **Plugins → Añadir nuevo → Subir plugin** → selecciona `doers-ai-connector.zip` → Instalar → **Activar**.
2. Ve a **Ajustes → Doers AI**. Verás que falta el MCP Adapter: pulsa **"Instalar automáticamente"**. El plugin descarga y activa la última versión oficial por ti.
3. Comprueba que la tabla de Estado muestra todo en ✅.

## Paso 3 — Crear las credenciales para la IA (1 min)

1. En la misma página, pulsa **"Crear usuario AI dedicado"**.
2. Copia la **contraseña de aplicación** que aparece en el aviso verde (solo se muestra una vez). El usuario es `doers-ai`.

> Si diera error de contraseñas de aplicación no disponibles: añade `define( 'WP_ENVIRONMENT_TYPE', 'local' );` al `wp-config.php` del sitio y repite.

## Paso 4 — Conectar Claude (3 min)

1. App de Claude → menú **Claude → Ajustes → Desarrollador → Editar configuración**. Se abre la carpeta con `claude_desktop_config.json`.
2. Añade tu sitio dentro de `mcpServers` (si la clave no existe, créala al mismo nivel que `preferences`; si ya tienes otros sitios, añade una entrada más separada por coma):

```json
"mcpServers": {
  "mi-proyecto": {
    "command": "npx",
    "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
    "env": {
      "WP_API_URL": "http://mi-proyecto.local/wp-json/doers-ai/mcp",
      "WP_API_USERNAME": "doers-ai",
      "WP_API_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx"
    }
  }
}
```

La URL exacta del endpoint la tienes siempre en **Ajustes → Doers AI** (sección Conexión), con este mismo snippet listo para copiar.

3. Guarda, **cierra Claude del todo (Cmd+Q) y vuelve a abrirlo**.
4. Verifica en *Ajustes → Desarrollador* que el servidor aparece como **running** (el sitio de Local debe estar arrancado).

## Paso 5 — Conectar la carpeta del sitio (opcional pero recomendado, 1 min)

En un chat de Cowork, conecta la carpeta `~/Local Sites/mi-proyecto/app/public`. Así Claude puede además escribir código directamente (temas a medida, CSS...), que es la vía más potente para desarrollo. Sin esto, Claude solo opera vía MCP (contenido, plantillas, plugins, medios, ajustes).

## Paso 6 — El primer prompt

El chat ya ve las herramientas del sitio y puede empezar a trabajar sin más, pero un poco de contexto inicial mejora mucho el resultado. Usa una de estas plantillas según el caso.

### Caso A — Sitio nuevo (web desde cero)

> Este es un WordPress conectado por MCP, con su carpeta del sitio conectada. Vamos a desarrollar una web corporativa para **[empresa]**, sector **[X]**, dirigida a **[público]**. Estilo **[minimalista / oscuro / clásico]**, idioma español. Trabaja así: el **tema** hazlo a medida como tema de bloques escribiendo los archivos en la carpeta; el **contenido y los ajustes**, vía MCP. Pídeme confirmación antes de operaciones destructivas. Antes de empezar, crea un archivo **`CLAUDE.md`** en la raíz de la carpeta con este contexto (proyecto, estilo, convenciones, "el tema se edita por archivos y el contenido por MCP") para que los chats futuros arranquen informados. Empieza por **la portada**.

### Caso B — Sitio ya existente (plugin instalado en una web en marcha)

> Este es un WordPress **en producción** conectado por MCP. **No toques nada todavía.** Primero explora y hazme un resumen del estado actual: tema activo, plantillas, páginas y entradas principales, plugins y ajustes relevantes. Propón un plan de cambios y **espera mi visto bueno** antes de modificar nada.

> **Consejo:** para esa primera sesión de exploración en un sitio de cliente, activa el **Modo solo lectura** en *Ajustes → Doers AI*. Así Claude puede leerlo todo pero no puede escribir aunque se lo pidas por error. Lo desactivas cuando aprobéis el plan.

**Sobre `CLAUDE.md`:** Claude lee ese archivo automáticamente al trabajar en la carpeta. Es lo que da "memoria de proyecto": cada chat futuro sobre ese sitio arranca ya informado del contexto, el estilo y las convenciones. Conviene mantenerlo actualizado e incluirlo de plantilla en el onboarding de cada cliente.

## Paso 7 — Probar el flujo completo (2 min)

Pídele a Claude, por ejemplo:

- *"Dame la información de mi sitio WordPress"* → debe responder con nombre, versión y tema (lectura ✓).
- *"Crea una página llamada Servicios con un título y dos párrafos y publícala"* → comprueba que aparece en el sitio (escritura ✓).
- *"Crea un tema de bloques minimalista y actívalo"* → desarrollo completo (si conectaste la carpeta, lo hará por archivos; activarlo te pedirá confirmación porque es una operación destructiva).

Todo lo que haga la IA queda registrado en **Ajustes → Doers AI → Auditoría**, y cada archivo sobrescrito tiene backup restaurable en la misma página.

## Paso 8 — Personalizar el contexto del proyecto (opcional, recomendado)

En *Ajustes → Doers AI → **Contexto del proyecto*** puedes guardar la información que define esta web concreta, para que Claude trabaje desde el primer momento con su identidad y su sistema de diseño. Esa información se entrega a la IA mediante la ability `doers/get-project-context` y **viaja con el sitio** aunque te conectes por MCP sin la carpeta.

Puedes rellenar:

- **Marca:** nombre, tagline, público objetivo, voz y tono, notas.
- **Sistema de diseño (manual):** logo, colores primario/secundario/acento, tipografías.
- **Documentación en Markdown:** cómo funciona la web, convenciones, estructura de contenido… escrita a mano o subiendo un archivo `.md`.
- **Tokens del tema:** si el tema es **de bloques** y define tokens en su `theme.json`, se detectan **automáticamente** y se muestran en una tabla (no hay que copiarlos). En temas **clásicos** sin `theme.json` esa tabla no aparece: el diseño vive en el CSS o en Figma.
- **Figma:** introduce el *file key* (la cadena en `figma.com/design/`**`FILE_KEY`**`/...`) y un *token de acceso* y pulsa **Sincronizar** para traer colores y tipografías. Prueba tres vías en orden: API de variables (plan Enterprise), estilos publicados, y —si el sistema de diseño está dibujado como capas— un **escaneo del documento**. El token (con scope de lectura de archivos: `file_content:read` + los de *Design systems*) se guarda aparte y **nunca** se expone por MCP. Tras sincronizar verás los **colores importados como muestras** y las **tipografías** en una tabla, para revisarlos de un vistazo.

Es especialmente útil en sitios de cliente (Caso B): rellena el contexto antes de empezar a crear, y cada chat futuro arrancará ya alineado con la marca. Si no quieres exponerlo, puedes desactivar el grupo **"Contexto del proyecto"** en la misma página.

## Paso 9 — CLAUDE.md automático (opcional, recomendado)

En *Ajustes → Doers AI → Contexto del proyecto → **CLAUDE.md del sitio*** tienes un botón para **generar y mantener** un `CLAUDE.md` en la raíz del sitio (la carpeta que conectas). El plugin escribe un **bloque automático** —entre las marcas `<!-- DOERS:AUTO:START/END -->`— con el estado del sitio, plugins activos, contenido, sistema de diseño/Figma, capacidades MCP activas y las **reglas de trabajo** (incluida la directiva de llamar a `get-project-context` al empezar). Todo lo que escribas **fuera** de ese bloque (tus notas, el conocimiento del proyecto) se **conserva intacto** al regenerar.

Con el toggle **"CLAUDE.md automático"** activo (en *Abilities y seguridad*), ese bloque se actualiza solo cada vez que guardas el contexto o sincronizas Figma, así que los datos siempre están al día.

Como Claude lee el `CLAUDE.md` automáticamente al trabajar en la carpeta conectada, esto sustituye a pedírselo a mano: con generarlo una vez, cada chat sobre el sitio arranca informado. Nota: el archivo se escribe en la raíz, así que si versionas el sitio en git y no lo quieres en el repo/producción, añádelo a `.gitignore` (es solo documentación).

---

## Seguridad y salvaguardas

El plugin está pensado para ser seguro incluso en sitios reales. Lo que conviene saber:

- **Permisos mínimos:** el usuario `doers-ai` tiene un rol con solo las capabilities que las abilities necesitan, separado de tu admin. Cada operación exige su capability.
- **Escrituras acotadas:** los archivos solo se escriben dentro de `wp-content/themes`, con extensiones permitidas, sin rutas `..` y con un límite de 2 MB por escritura.
- **Backup automático protegido:** antes de sobrescribir un archivo se guarda una copia en `uploads/doers-ai-backups/`, carpeta blindada con `.htaccess` e `index.php` para que un backup `.php` no se ejecute ni se exponga por URL. Se conservan los últimos 10 backups por archivo.
- **Protección contra "romper la web":** los archivos PHP se validan con `php -l` antes de guardarse (si hay error de sintaxis, no se escriben); y tras escribir un `.php` el plugin comprueba que la portada no devuelve un error fatal (HTTP ≥ 500) y, si lo detecta, **revierte solo** al estado anterior. Sobrescribir `functions.php` o `style.css` exige confirmación explícita.
- **Controles en *Ajustes → Doers AI*:** grupos de abilities activables, **modo solo lectura**, **rate limit** de escrituras por hora, lista de **backups con botón Restaurar** y **registro de auditoría** de todo.

> Nota: la verificación post-escritura comprueba la portada. Un fallo que solo aparezca al renderizar una plantilla concreta podría no detectarse ahí, pero la validación de sintaxis previa cubre los errores de PHP, que son la causa habitual de la pantalla blanca.

---

## Solución de problemas

| Síntoma | Causa probable | Solución |
|---|---|---|
| El servidor no aparece como *running* | JSON mal formado o Node.js ausente | Valida el JSON (comas), instala Node.js, reinicia Claude |
| Error 404 en el endpoint | Permalinks en "simple" | Paso 1.3: enlaces permanentes → "Nombre de la entrada" |
| Error 401/403 | Credenciales mal copiadas | La contraseña incluye los espacios; cópiala exacta. Regenera credenciales si la perdiste |
| Claude no ve las herramientas nuevas tras actualizar el plugin | Caché del proxy | Quita la entrada del config, reinicia Claude, vuelve a añadirla y reinicia otra vez |
| No aparece la sección de contraseñas de aplicación | Entorno no marcado como local | `define( 'WP_ENVIRONMENT_TYPE', 'local' );` en wp-config.php |
| "La edición de archivos está deshabilitada" | `DISALLOW_FILE_EDIT` en wp-config.php | Elimínalo en local; en producción es una decisión consciente |
| Una escritura se revirtió sola | El PHP rompía el sitio (error fatal) | Es la red de seguridad actuando; revisa el mensaje de error, corrige y reintenta. El estado anterior quedó intacto |

## Para un sitio en producción (cliente)

El flujo es idéntico salvo el paso 1 (el hosting ya existe) y con dos diferencias: la URL será HTTPS, y conviene revisar en *Ajustes → Doers AI* qué grupos de abilities dejas activos y si activas el modo solo lectura o un rate limit más bajo. El mismo zip sirve.

Además, en sitios con HTTPS público puedes saltarte el proxy: el plugin incluye un **conector OAuth 2.1**. En la app de Claude → *Ajustes → Conectores → Añadir conector personalizado*, pega el endpoint MCP y autoriza con tu sesión de WordPress, sin JSON ni contraseñas de aplicación. Los clientes autorizados y sus tokens se gestionan (y revocan) desde *Ajustes → Doers AI*.
