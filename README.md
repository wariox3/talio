# talio

Panel interno (Symfony 7.4, PHP >= 8.2) para operar y vigilar los servicios de
Semántica. No tiene base de datos propia: todo lo que muestra lo pide a las APIs
de los microservicios, salvo la auditoría de servidores web, que lee de la base
de Lantano.

| Menú | Qué hace | Habla con |
|---|---|---|
| Servicios → Wolframio | Monitor, cuentas (suscriptor/empleador en Kiai, sets de pruebas) y documentos pendientes de enviar, con error o esperando respuesta | API de Wolframio, API de Kiai |
| Servicios → Tántalo | Monitor de la cola de decodificación | API de Tántalo |
| Servicios → Itrio | Movimientos y su facturación, consumos (Excel), contenedores y usuarios | API de Itrio |
| Servicios → Nobelio | Emisores (software, resoluciones, webhooks, pruebas), documentos y nóminas electrónicas | API de Nobelio |
| Servicios externos → Kiai | Consumos por aliado y mes (Excel) | API de Kiai |
| Auditoría → WebServer | Accesos de los servidores web por hora, IP, API key, host y ruta | PostgreSQL de Lantano |

## Requisitos

- PHP **8.2 o superior** (producción usa el 8.3 de Ubuntu 24.04). Composer está fijado a la
  plataforma PHP 8.3.0 (`config.platform.php`), así que el `composer.lock`
  siempre es instalable en el servidor aunque en desarrollo haya un PHP más
  nuevo.
- Extensiones: `ctype`, `iconv`, `curl`, `dom`, `xml`, `simplexml`,
  `xmlreader`, `zip`, `mbstring`, `intl` y **`pdo_pgsql`** (la usa el monitor
  de Auditoría).

## Puesta en marcha (desarrollo)

```bash
composer install
cp .env.example .env   # y rellenar los valores
php -S localhost:8000 -t public
```

## Pruebas

```bash
php bin/phpunit
```

Son pruebas unitarias de `src/Utilidades/` (clientes de API y Excel). Usan
`MockHttpClient`, así que no tocan ningún servicio real ni necesitan `.env`
(si existe se carga, pero no se usan sus valores).

## Despliegue en producción (Ubuntu 24.04, desde cero)

Ubuntu 24.04 trae PHP 8.3 de serie. Talio corre con **Apache + PHP-FPM 8.3**
(`mpm_event` + `proxy_fcgi`), sin `mod_php`. Todo como root salvo donde dice
`sudo -u www-data`.

### 1. Paquetes

```bash
apt update && apt upgrade -y
apt install -y apache2 git unzip curl \
  php8.3-fpm php8.3-cli php8.3-common php8.3-xml php8.3-zip php8.3-mbstring \
  php8.3-intl php8.3-curl php8.3-pgsql php8.3-opcache

# Composer (instalador oficial, verificando la firma)
EXPECTED="$(curl -s https://composer.github.io/installer.sig)"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
[ "$EXPECTED" = "$(php -r "echo hash_file('sha384', 'composer-setup.php');")" ] \
  && php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f composer-setup.php
```

### 2. PHP

```bash
cat > /etc/php/8.3/fpm/conf.d/99-semantica.ini <<'EOF'
date.timezone = America/Bogota
expose_php = Off
memory_limit = 256M
opcache.memory_consumption = 128
opcache.max_accelerated_files = 20000
EOF
cp /etc/php/8.3/fpm/conf.d/99-semantica.ini /etc/php/8.3/cli/conf.d/99-semantica.ini
systemctl enable --now php8.3-fpm && systemctl restart php8.3-fpm
```

### 3. Apache (común a todos los proyectos del servidor)

El servidor va a alojar varios proyectos, así que Apache se configura una vez
para todos y cada proyecto se declara con **una línea**:

- `conf-available/semantica.conf`: seguridad, TLS y cabeceras para todos los
  sitios.
- `conf-available/semantica-sitios.conf`: la plantilla `SitioPHP`
  (`mod_macro`) con todo lo que lleva un sitio: HTTP→HTTPS, SSL, PHP-FPM de la
  versión que se elija, HSTS y logs propios.
- `sites-available/000-rechazar.conf`: el sitio por defecto, que **rechaza**
  todo lo que no sea un dominio configurado. Sin él, el `000-default` de
  Ubuntu serviría `/var/www/html` entero y cualquiera podría pedir
  `http://<IP>/talio/.env`.

Todo por PHP-FPM (`mpm_event`), sin `mod_php`: así cada proyecto puede usar su
versión de PHP.

```bash
a2dismod php8.3 mpm_prefork 2>/dev/null     # por si algún paquete trajo mod_php
a2enmod mpm_event proxy_fcgi setenvif rewrite ssl headers macro
a2dissite 000-default
```

Los archivos están en el repositorio, en `despliegue/apache/`: se copian, no
se pegan (pegar heredocs desde la terminal rompe el `EOF`). Se clona primero
el proyecto (paso 4) y luego:

```bash
cp /var/www/html/talio/despliegue/apache/conf-available/*.conf /etc/apache2/conf-available/
cp /var/www/html/talio/despliegue/apache/sites-available/*.conf /etc/apache2/sites-available/
apt install -y ssl-cert
install -d /var/www/rechazar
a2enconf semantica semantica-sitios
a2ensite 000-rechazar
```

Certificado: el comodín de Semántica, copiado del servidor anterior con
`scp` a `/etc/ssl/certs/semantica/` (la `.key` con permisos `600` y dueño
root).

**Talio**: `despliegue/apache/sites-available/talio.conf`, ya copiado arriba, es una sola línea `Use SitioPHP ...`.

```bash
a2ensite talio
apache2ctl configtest && systemctl reload apache2
apache2ctl -S                                # lista los sitios y cuál es el default
```

**Otro proyecto** = otro archivo con su línea `Use` y `a2ensite`:

```apache
Use SitioPHP otro.semantica.com.co /var/www/html/otro/public 8.3 /etc/ssl/certs/semantica/semantica2026
```

Si un proyecto necesita otra versión de PHP (p. ej. 8.1): instalarla desde el
PPA de Ondřej Surý (`add-apt-repository ppa:ondrej/php`,
`apt install php8.1-fpm ...`) y poner `8.1` en su línea `Use`. Conviven sin
problema porque cada versión tiene su propio socket de FPM.

Esta configuración se probó con Apache 2.4.58 (el de Ubuntu 24.04): por IP o
dominio ajeno responde 403, HTTP redirige a HTTPS, `.env` y `*.sql` dan 403,
no lista carpetas, los `.php` van a FPM (nunca se sirve el código fuente) y
rechaza TLS 1.1.

### 4. Código

```bash
cd /var/www/html
git clone https://github.com/wariox3/talio.git   # repo privado: token o deploy key
chown -R www-data:www-data /var/www/html/talio
install -d -o www-data -g www-data /var/www/.cache   # caché de Composer de www-data
```

`.env` (se copia de `.env.example` y se rellena; ver "Variables de entorno"):

```bash
cd /var/www/html/talio
sudo -u www-data cp .env.example .env
sudo -u www-data nano .env      # APP_ENV=prod y todos los valores
chmod 640 .env
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # APP_SECRET nuevo
```

### 5. Instalar y arrancar

```bash
cd /var/www/html/talio
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console about | grep -iE 'environment|php'   # prod, 8.3
sudo -u www-data php bin/console debug:container --env-vars           # que no falte ninguna
```

### 6. Red

```bash
ufw allow OpenSSH && ufw allow 'Apache Full' && ufw enable
```

Fuera del servidor:

- **DNS:** `talio.semantica.com.co` (y `www.`) apuntando a la IP nueva, y
  lo mismo para cada proyecto que se agregue.
- **Base de Lantano:** permitir la IP nueva en el firewall y en el
  `pg_hba.conf` del PostgreSQL, o Auditoría → WebServer no conecta.
- **APIs:** si Wolframio, Tántalo, Itrio, Nobelio o Kiai filtran por IP,
  agregar la nueva.

### 7. Comprobar

1. `https://talio.semantica.com.co` muestra el login en español.
2. Entrar y abrir: monitor de Wolframio y de Tántalo, Auditoría → WebServer,
   una lista de Itrio, Nobelio en cada ambiente y un Excel de Kiai.
3. Errores: `var/log/prod.log` y `/var/log/apache2/talio_error.log`.

### En cada despliegue

```bash
cd /var/www/html/talio
sudo -u www-data git pull origin main
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data php bin/console cache:clear
```

`composer install`, nunca `composer update`: instala exactamente lo del
`composer.lock` probado en desarrollo. Siempre como `www-data`, nunca como
root, para que `var/` y `vendor/` no queden con dueño root.

Para volver atrás: `sudo -u www-data git checkout <commit anterior>` y repetir
el `composer install` y el `cache:clear`.

## Arquitectura

```
src/
  Controller/<servicio>/   Un directorio por entrada del menú; rutas /<servicio>/...
  Utilidades/
    ClienteApi.php         Base de los clientes HTTP (ver abajo)
    Wolframio.php  Tantalo.php  Itrio.php  Nobelio.php  Softgic.php (Kiai)
    BdWebServer.php        Consultas a la tabla acceso de Lantano (PDO pgsql)
    Excel.php              Genera un XLSX y lo devuelve como descarga
templates/<servicio>/      Vistas; base.html.twig (con menú) y base_sin_menu.html.twig (ventanas emergentes)
```

### Clientes de API

Todos heredan de `ClienteApi` y reciben su URL base y credenciales por
inyección (`#[Autowire(env: ...)]`), no leyendo `$_ENV`. Toda petición:

- tiene **tiempo límite**: 10 s sin recibir nada y 30 s en total. Antes no
  tenía, y un servicio caído dejaba la página colgada 60 s por cada llamada.
- **nunca lanza excepción** y devuelve siempre el mismo arreglo:

| Resultado | Arreglo |
|---|---|
| Éxito | `['error' => false, 'status' => 200, 'datos' => [...]]` |
| Error HTTP | `['error' => true, 'status' => 4xx/5xx, 'mensaje' => '...']` |
| Sin respuesta (caído, DNS, tiempo agotado) | `['error' => true, 'status' => 0, 'mensaje' => '...']` |
| Éxito que no es JSON | Error, con `mensaje` "... devolvió una respuesta que no es JSON." |
| Archivo (`consumoArchivo()`) | `['error' => false, 'contenido', 'tipo', 'nombre']` |

El `mensaje` sale del cuerpo del error (`mensaje`, `detail` o `error`; Nobelio
y Kiai tienen su propio formato) o, si no trae, del código HTTP.

| Cliente | Autenticación |
|---|---|
| Wolframio, Tántalo | Ninguna. (Antes se les mandaba por error el token de Itrio.) |
| Itrio | JWT. Se pide con `ITRIO_USUARIO`/`ITRIO_CLAVE` la primera vez y se guarda en la sesión (`itrio_token`). Va en **todas** las peticiones; si Itrio responde 401 se renueva y se reintenta una vez. |
| Nobelio | `Authorization: Api-Key` con el `NOBELIO_TOKEN_<AMBIENTE>` del ambiente elegido. |
| Kiai (`Softgic`) | Básica con `KIAI_TOKEN` (`usuario:clave`). Un cuerpo con `ExceptionType` es error aunque el status sea 200. |

### Avisos al usuario

Los controladores usan `$this->addFlash('success'|'warning'|'danger', ...)` y
`templates/_avisos.html.twig` los pinta con `app.flashes`. Toda acción que
llama a una API informa el resultado: si falla, sale el mensaje del servicio.

### Seguridad

- Un único usuario en memoria (`LOGIN_USERNAME`/`LOGIN_PASSWORD`); todo exige
  `ROLE_ADMIN` salvo `/login`.
- El login comprueba su token CSRF y se bloquea 15 minutos tras 5 intentos
  fallidos por usuario e IP (`login_throttling`, `symfony/rate-limiter`).
- La interfaz está en español (`config/packages/translation.yaml`,
  `default_locale: es`): los mensajes de Symfony, como los errores del login,
  salen de sus traducciones `.es.xlf`. Las propias van en `translations/`.
- Las acciones que modifican algo son POST con token CSRF: las de Nobelio
  con `isCsrfTokenValid()`, las demás con el token del formulario de Symfony.
  Nada se ejecuta por una URL con parámetros GET.
- Los ids que se concatenan a la URL de una API se validan (UUID en Nobelio,
  enteros en Itrio) para que no se pueda construir otra ruta.

## Variables de entorno

Todas viven en `.env`, que **no se versiona** (está en `.gitignore`). La
plantilla versionada es `.env.example`: si agregas o quitas una variable del
código, actualízala ahí también.

Todas se leen con `%env()%` o `#[Autowire(env: ...)]`, así que el contenedor
las conoce y `php bin/console debug:container --env-vars` sirve para ver cuáles
faltan. Si falta una, falla la primera página que use ese servicio, con un
error claro que nombra la variable. Excepción: las de Nobelio son opcionales
por ambiente (`default::`), así que ese comando no las lista; los ambientes
sin configurar salen deshabilitados en el selector del menú.

| Variable | Usada en | Descripción |
|---|---|---|
| `APP_ENV` | `public/index.php`, `bin/console` | `dev` o `prod`. `APP_DEBUG` se deriva de esta si no se define. |
| `APP_SECRET` | `config/packages/framework.yaml` | Cadena aleatoria de Symfony. |
| `LOGIN_USERNAME` | `config/packages/security.yaml` | Usuario del provider in-memory. |
| `LOGIN_PASSWORD` | `config/packages/security.yaml` | Clave del provider. El hasher es `plaintext`. |
| `BASE_ITRIO` | `Utilidades/Itrio.php` | URL base de la API de Itrio. |
| `ITRIO_USUARIO` | `Utilidades/Itrio.php` | Usuario del login de Itrio (proyecto RUTEOAPP). |
| `ITRIO_CLAVE` | `Utilidades/Itrio.php` | Clave del login de Itrio. |
| `BASE_WOLFRAMIO` | `Utilidades/Wolframio.php` | URL base de la API de Wolframio. |
| `BASE_TANTALO` | `Utilidades/Tantalo.php` | URL base de la API de Tántalo. |
| `BASE_NOBELIO_PRODUCCION`, `_PRUEBA`, `_DESARROLLO` | `Utilidades/Nobelio.php` | URL base de la API de Nobelio en cada ambiente. Ver "Ambientes de Nobelio". |
| `NOBELIO_TOKEN_PRODUCCION`, `_PRUEBA`, `_DESARROLLO` | `Utilidades/Nobelio.php` | API Key de Nobelio de cada ambiente, `<prefijo>.<secreto>`. |
| `KIAI_TOKEN` | `Utilidades/Softgic.php` | Autenticación básica de Kiai, formato `usuario:clave`. |
| `DATABASE_BDLANTANO_URL` | `Utilidades/BdWebServer.php` | PostgreSQL de Lantano: `postgresql://usuario:clave@host:5432/base`. |

Las `BASE_*` **deben terminar en `/`**: las rutas del código van sin `/`
inicial y se concatenan a ellas.

### Nobelio

#### Ambientes de Nobelio

Hay tres instancias de Nobelio: **producción**, **prueba** y **desarrollo**.
Cada una tiene su URL y su API Key en el `.env`
(`BASE_NOBELIO_<AMBIENTE>` y `NOBELIO_TOKEN_<AMBIENTE>`).

- **Elegir:** con el selector "Nobelio" de la cabecera, presente en todas
  las pantallas de Nobelio (`POST /nobelio/ambiente`, con token CSRF). Al
  cambiar se vuelve a la lista de la sección: un detalle abierto es de otro
  ambiente y allí no existe. Las ventanas emergentes (errores, webhook,
  pruebas) muestran el ambiente pero no dejan cambiarlo: actúan sobre algo de
  la pantalla que las abrió.
- **Dónde se guarda:** en la sesión del usuario (`nobelio_ambiente`), así que
  vale para todas las pantallas de Nobelio hasta que se cambie o se cierre la
  sesión. Cada usuario tiene la suya.
- **Por defecto:** producción, o el primero configurado si producción no lo
  está.
- **Sin configurar:** un ambiente al que le falta la URL o la llave sale
  deshabilitado en el selector. Si no hay ninguno, las pantallas de Nobelio
  muestran el error en vez de llamar a la API.
- **A la vista:** el selector va en rojo si es producción y en ámbar si es
  prueba o desarrollo.

El código está en `Nobelio::ambiente()`, `ambientesDisponibles()` y
`cambiarAmbiente()`. La clase se expone a Twig como la variable global
`nobelio` (`config/packages/twig.yaml`); el selector está en
`templates/nobelio/_ambiente.html.twig`.

**Credenciales.** Nobelio (Django + DRF) tiene dos
mecanismos de autenticación, y Talio usa el primero:

| Mecanismo | Cabecera | Alcance |
|---|---|---|
| API Key | `Authorization: Api-Key <prefijo>.<secreto>` | Los emisores y documentos de **una sola cuenta** |
| JWT de usuario | `Authorization: Bearer <access>` | Según el usuario: los emisores asignados, o todo si es staff |

La API Key va tal cual en cada petición (`Nobelio::cabeceras()`), **no caduca** y
no se guarda nada en sesión: no hay login ni renovación que manejar. Se genera
en Nobelio y solo se ve completa al crearla.

Lo que **no** alcanza una API Key es `/api/seguridad/` (usuarios, llaves): esos
endpoints siguen siendo exclusivos de staff y responden 403. Talio no los
consume; si algún día los necesita, tocaría volver a un JWT de staff para esa
parte.

#### Endpoints de Nobelio

Inventario tomado de los `urls.py` y del `schema.yml` de Nobelio el 2026-09-16. **Puede quedar
desactualizado**: la fuente de verdad son
`/home/desarrollo/proyectos/nobelio/apps/*/urls.py`, los `@action` de sus
ViewSets y su `schema.yml`. Las rutas se pasan a `Nobelio::consumoGet()` y compañía sin barra
inicial, porque las `BASE_NOBELIO_*` ya terminan en `/`.

Cada recurso registrado en un router de DRF expone el juego REST completo:
`GET` (lista), `POST` (alta) y `GET`/`PUT`/`PATCH`/`DELETE` sobre `{id}/`.

| Ruta | Notas |
|---|---|
| `estado/` | Estado del servicio. Único endpoint **sin autenticación**. |
| `api/seguridad/token/` | Login. Devuelve `{access, refresh}`. Con MFA sigue en `token/mfa/`. |
| `api/seguridad/token/refresh/` · `token/cerrar/` | Renovar y cerrar sesión. También `token/recuperar/` y `token/restablecer/`. |
| `api/seguridad/registro/` · `me/` · `mfa/…` | Registro, usuario actual y segundo factor. Talio no los consume. |
| `api/seguridad/usuario/` | **Solo staff.** |
| `api/seguridad/llave-api/` | **Solo staff.** Gestión de API Keys. |
| `api/catalogos/…` | 16 catálogos de solo lectura: `tipo-factura`, `tipo-identificacion`, `tipo-organizacion`, `responsabilidad-fiscal`, `tributo`, `unidad-medida`, `forma-pago`, `medio-pago`, `moneda`, `pais`, `departamento`, `municipio` y, de nómina, `periodo-nomina`, `tipo-contrato`, `tipo-trabajador`, `subtipo-trabajador`. Aceptan `?search=`. |
| `api/emisores/emisor/` | Emisores (OFE). |
| `api/emisores/emisor/validar-nit/` | `GET ?nit=<NIT>` |
| `api/emisores/software/` · `certificado/` · `resolucion/` | Recursos del emisor. |
| `api/emisores/webhook/` | Webhooks del emisor, CRUD completo. Filtra por `?emisor=<id>`. Campos: `emisor`, `nombre`, `url` (**solo HTTPS**), `estado_validado` y `estado_notificado` (de qué se avisa). El `emisor` no se puede cambiar. Nobelio aún no envía los avisos: solo los guarda. |
| `api/emisores/software/{id}/crear-nomina-prueba/` | `POST` · siembra una nómina de prueba en borrador, con `{"consecutivo": <n>}` opcional (sin él toma el siguiente libre). **Solo sobre software de nómina.** El periodo lo continúa Nobelio. |
| `api/emisores/software/{id}/crear-nota-ajuste-prueba/` | `POST` · clona la última nómina aceptada en notas de ajuste de prueba, en borrador. **Solo sobre software de nómina** y con el emisor en pruebas. |
| `api/emisores/certificado/cargar/` | `POST` multipart · sube el `.p12`. |
| `api/emisores/resolucion/consulta-dian/` · `importar-dian/` | Consulta e importa resoluciones desde la DIAN. |
| `api/emisores/resolucion/{id}/crear-documento-prueba/` | `POST` · siembra un documento de prueba en borrador sobre la resolución, con `{"consecutivo": <n>}` opcional (sin él toma el siguiente libre). El tipo lo decide el `tipo_factura` de la resolución. |
| `api/nomina/empleado/` | Empleados. |
| `api/nomina/nomina/` | Nóminas. **No se editan** (sin `PUT`/`PATCH`). Filtra por `emisor`, `empleado`, `estado` y `tipo_xml` (`102` nómina, `103` nota de ajuste); `?search=` mira número, CUNE y documento del empleado. |
| `api/nomina/nomina/{id}/emitir/` | `POST` sin cuerpo · lleva la nómina a su estado final: `borrador` se firma y se envía; `firmado` solo reenvía el mismo CUNE; `enviado` se consulta y se aplica el resultado, sin reenviar. `aceptado` y `rechazado` responden 400. Devuelve `accion` (`enviado` o `consultado`), `estado`, `cune`, `track_id`, `fecha_validacion`, `es_valido`, `codigo_estado`, `descripcion` y `errores`. |
| `api/nomina/nomina/{id}/consultar/` | `GET` · consulta la DIAN **sin modificar** la nómina, en cualquier estado. |
| `api/nomina/nomina/{id}/xml/` | **Descarga** del XML firmado. Usar `consumoArchivo()`. |
| `api/nomina/nomina-evento/` | Solo lectura · cambios de estado de la nómina ante la DIAN (`firmado`, `enviado`, `validado`, `rechazado`). Filtra por `?nomina=<uuid>` y `?tipo=`. Cada uno trae `tipo`, `fecha` y `datos`, que depende del tipo (CUNE; origen, operación, `track_id`, `codigo_estado`, `fecha_validacion` o `errores`). |
| `api/documentos/documento/` | Documentos electrónicos. Filtra por `emisor` (id entero; otra cosa responde 400), `estado`, `documento_tipo` y `notificado`; `?search=` busca por contenido en número, CUFE y NIT o razón social del adquiriente (no hay filtro exacto por número). Ordena con `?ordering=` (campos permitidos en `ordering_fields` del ViewSet; el `-` invierte). |
| `api/documentos/documento-evento/` | Solo lectura · lo mismo para documentos: filtra por `?documento=<uuid>` y `?tipo=`; al firmar `datos` trae `cufe_cude`. |
| `api/documentos/documento/{id}/emitir/` | `POST` sin cuerpo · lleva el documento a su estado final: `borrador` se firma y se envía; `firmado` —envío anterior fallido, p. ej. un 502— solo reenvía el mismo CUFE; `enviado` se consulta y se aplica el resultado, sin reenviar (sustituye a `actualizar-estado/`, que ya no existe). `aceptado` y `rechazado` responden 400: el rechazado se borra y se crea de nuevo. Devuelve `accion` (`enviado` o `consultado`), `estado`, `cufe_cude`, `track_id`, `fecha_validacion`, `es_valido`, `codigo_estado`, `descripcion` y `errores`. |
| `api/documentos/documento/{id}/consultar/` | `GET` · consulta la DIAN **sin modificar** el documento, en cualquier estado. (`consultar-zip/` ya no existe.) |
| `api/documentos/documento/{id}/xml/` · `pdf/` · `attached/` | **Descargas** (`FileResponse` / `HttpResponse`). ⚠️ Usar `consumoArchivo()`, **nunca `consumoGet()`**. Ver nota abajo. |
| `api/documentos/documento/{id}/notificar/` | `POST` multipart (`pdf` y `adjuntos` opcionales) · arma el zip con el AttachedDocument y **lo envía por correo** al adquiriente (Zinc). Devuelve `destinatario`, `codigo_envio`, `notificado`… Si falla el correo responde 502 y el documento no queda notificado. Con `?descargar=1` no envía: devuelve el zip. |


**Alcance de `Nobelio`.** La clase expone `consumoGet()`, `consumoGetTodos()`
(recorre las páginas), `consumoPost()`, `consumoPatch()`, `consumoDelete()` y
`consumoArchivo()`. `PUT` se añade cuando haga falta: es una línea que llama a
`llamar('PUT', ...)`. `Nobelio::esUuid()` valida los ids que llegan de un
formulario antes de ponerlos en la URL.

**Las descargas van por `consumoArchivo()`.** `xml/`, `pdf/` y `attached/`
devuelven archivo, no JSON: por `consumoGet()` salen como error ("no es JSON").
`consumoArchivo()` devuelve `contenido` (los bytes), `tipo` (el
`Content-Type`) y `nombre` (el del `Content-Disposition`, p. ej. `FE1.xml`, o
`''`). El error sí es JSON, así que `error`/`mensaje` funcionan igual. Ejemplo
en `nobelio/DocumentoController::descargar()`.

## Sesión

Configurada en `config/packages/framework.yaml`. Dura **8 horas** y guarda sus
archivos en `var/sesiones/`, no en el directorio compartido del sistema
(`/var/lib/php/sessions`).

| Opción | Valor | Por qué |
|---|---|---|
| `cookie_lifetime` | `28800` | Lo que el navegador guarda la cookie. Es vida **absoluta**: se fija al entrar y no se renueva en cada petición. |
| `gc_maxlifetime` | `28800` | Lo que el servidor conserva los datos. Es **inactividad**. Sin esto mandaría el `php.ini` con los 1440 s de PHP (24 min). |
| `save_path` | `var/sesiones` | Directorio propio: en el compartido, el `gc_maxlifetime` efectivo es el mayor de todas las apps del servidor. |
| `gc_probability` / `gc_divisor` | `1` / `100` | El recolector de PHP, reactivado. Ver abajo. |

**Sobre la limpieza.** En Debian/Ubuntu el recolector de PHP viene apagado
(`session.gc_probability = 0`) porque quien borra las sesiones viejas es un
cron: `/etc/cron.d/php` → `/usr/lib/php/sessionclean`. Ese script lee el
`save_path` de los `php.ini` de cada SAPI, así que **no conoce** el que Talio
fija en tiempo de ejecución y nunca tocaría `var/sesiones/`. Por eso, junto con
el `save_path` propio hay que reactivar el recolector de PHP: van juntos, y
quitar uno sin el otro deja los archivos acumulándose sin caducar.

## Pendientes conocidos

- **`APP_SECRET`** quedó commiteado en el historial (`5fad149`, `99cf641`).
  El `.env` local ya no usa esos valores, y el servidor nuevo genera uno
  propio (paso 4 del despliegue), así que basta con no reutilizar el de un
  servidor anterior.
- **La clave del login está en texto plano** en el `.env` (hasher
  `plaintext`). Es una decisión consciente: se mantiene así. Si algún día se
  quiere hashear: cambiar el hasher a `auto` en `security.yaml`, generar el
  hash con `php bin/console security:hash-password` y poner ese hash en
  `LOGIN_PASSWORD` **en el mismo despliegue** (si no, nadie puede entrar).
