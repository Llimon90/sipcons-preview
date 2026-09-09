# Despliegue del sitio SIPCONS a cPanel

Enfoque en dos fases: primero se sube el sitio nuevo a una carpeta de
pruebas sin tocar nada de lo que ya está en producción; después se
hace el cambio definitivo.

## Estructura final de `public_html`

| Ruta | Qué es | Estado |
|---|---|---|
| `public_html/app/` | Aplicación propia (sipcons.com/app/) | **No se toca nunca** |
| `public_html/` (raíz) | Sitio web nuevo (estático) | Se coloca en la Fase 2 |
| `public_html/tienda/` | WordPress actual (productos/tienda) | Se mueve en la Fase 2 |

Dominio canónico: **sipcons.com sin www**. Los `canonical`, el
`sitemap.xml` y el JSON-LD ya apuntan ahí.

---

## Fase 1 — Subir a pruebas (`sipcons.com/upgrade-site/`)

No se modifica WordPress ni la app. Solo se agrega una carpeta nueva.

1. **Respaldo** completo de cPanel (por si acaso) + export de la BD de WordPress.
2. En `public_html` crea la carpeta `upgrade-site`.
3. Sube ahí el contenido del repo (todo menos `images/`, `.vscode/`,
   `SKILL.md`, `deploy/`, `node_modules/`, `.git/`):
   - **cPanel → Git Version Control** clonando
     `https://github.com/Llimon90/sipcons-preview.git` y desplegando a
     `/home/USUARIO/public_html/upgrade-site`, **o**
   - descargar el ZIP de GitHub y extraerlo en `upgrade-site/`.
4. Copia `deploy/htaccess-staging.txt` como
   `public_html/upgrade-site/.htaccess` (trae `noindex` y el 404 propio).
5. cPanel → **Privacidad del directorio** → protege `upgrade-site`
   con usuario y contraseña.
6. Ejecuta **AutoSSL** si hiciera falta.
7. Revisa `https://sipcons.com/upgrade-site/` a fondo: todas las
   páginas, imágenes, menú móvil, formularios, enlaces a WhatsApp /
   Facebook / Instagram, el logo en scroll, la página 404
   (`/upgrade-site/algo-inventado`).

Todas las rutas del sitio son relativas, así que funciona igual en
`/upgrade-site/` que en la raíz — no hay que editar nada entre fases.

---

## Fase 2 — Cambio definitivo

Cuando el sitio de pruebas esté aprobado. Hazlo en un horario de bajo
tráfico; toma ~15–30 min.

1. **Respaldo** completo otra vez.
2. **Mover WordPress a `/tienda/`:**
   1. Crea `public_html/tienda/`.
   2. Mueve ahí SOLO los archivos de WordPress de la raíz: `wp-admin/`,
      `wp-includes/`, `wp-content/`, `wp-config.php`, `index.php`,
      `wp-*.php`, `xmlrpc.php`, `license.txt`, `readme.html` y el
      `.htaccess` de WordPress. **No muevas `app/`.**
   3. En `tienda/wp-config.php` agrega:
      ```php
      define('WP_HOME','https://sipcons.com/tienda');
      define('WP_SITEURL','https://sipcons.com/tienda');
      ```
   4. Plugin **Better Search Replace**: `https://sipcons.com` →
      `https://sipcons.com/tienda` en todas las tablas.
   5. Borra `tienda/.htaccess` viejo y en *Ajustes → Enlaces
      permanentes* pulsa **Guardar** para regenerarlo.
   6. Verifica `https://sipcons.com/tienda/wp-admin`.
3. **Publicar el sitio nuevo en la raíz:**
   1. Mueve todo el contenido de `public_html/upgrade-site/` a
      `public_html/` (incluye `assets/`, los `.html`, `robots.txt`,
      `sitemap.xml`, `404.html`).
   2. **Borra** el `.htaccess` de staging y sube en su lugar el
      `.htaccess` que está en la **raíz del repo** (el de producción:
      excluye `/app/` y `/tienda/`, fuerza HTTPS + sin www, etc.).
   3. Quita la protección con contraseña.
   4. Borra la carpeta `upgrade-site` vacía.
4. **Redirecciones 301:** en Search Console saca la lista de URLs
   viejas de WordPress y complétalas en el bloque comentado "3" del
   `.htaccess` de la raíz (→ página `.html` nueva o `→ /tienda/...`).
5. **Verificación final:**
   - `https://sipcons.com/` → sitio nuevo
   - `https://sipcons.com/app/` → app intacta
   - `https://sipcons.com/tienda/` → WordPress
   - `https://www.sipcons.com/` → redirige a sin www
   - URL inventada → `404.html`
   - `/robots.txt` y `/sitemap.xml` cargan
6. **Search Console:** reenvía `sitemap.xml`, pide reindexación de la
   home, agrega la propiedad `sipcons.com/tienda/` si quieres seguirla.

---

## Qué NO subir al servidor

`images/`, `.vscode/`, `SKILL.md`, `deploy/`, `node_modules/`, `.git/`.
(Si usas cPanel Git Version Control, `deploy/` y `.git` pueden quedar
pero no estorban; lo importante es no exponer `.git` — el `.htaccess`
ya lo bloquea.)

## Pendientes del dueño

- Conectar el formulario de contacto (backend desde cPanel).
- Confirmar el código postal (ahora 22504).
- Integrar el catálogo real desde WordPress.
- Fichas técnicas PDF para el centro de descargas (hoy oculto).
