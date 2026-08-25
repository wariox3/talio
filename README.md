# talio

Aplicación Symfony 6.4 (PHP >= 8.1) que actúa como front-end de varios
microservicios internos (Itrio, Níquel, Wolframio, Carbono, Tántalo) y de
las APIs de Kiai/Softgic y DigitalOcean Spaces.

## Puesta en marcha

```bash
composer install
cp .env.example .env   # y rellenar los valores
php -S localhost:8000 -t public
```

## Variables de entorno

Todas las variables viven en `.env`, que **no se versiona** (está en
`.gitignore`). La plantilla versionada es `.env.example`: si agregas o quitas
una variable del código, actualízala ahí también.

### Cómo se consumen

Hay dos mecanismos distintos en este proyecto, y conviene tenerlos claros
porque fallan de forma muy distinta:

| Mecanismo | Dónde | Si falta la variable |
|---|---|---|
| `%env(VAR)%` en YAML | `config/packages/security.yaml` | Symfony aborta al arrancar con un error claro |
| `$_ENV['VAR']` en PHP | `src/Utilidades/*.php` | *Undefined array key* en tiempo de ejecución, solo al tocar esa ruta |

Por eso `php bin/console debug:container --env-vars` **solo reporta
`LOGIN_USERNAME` y `LOGIN_PASSWORD`**: las demás se leen directo de `$_ENV` y
el contenedor no las conoce. No confíes en ese comando para validar el `.env`
completo.

### Referencia

| Variable | Usada en | Descripción |
|---|---|---|
| `APP_ENV` | `public/index.php`, `bin/console` | `dev` o `prod`. `APP_DEBUG` se deriva de esta si no se define. |
| `APP_SECRET` | `config/packages/framework.yaml` | Cadena aleatoria de Symfony. |
| `LOGIN_USERNAME` | `config/packages/security.yaml` | Usuario del provider in-memory. |
| `LOGIN_PASSWORD` | `config/packages/security.yaml` | Clave del provider. El hasher es `plaintext`. |
| `BASE_ITRIO` | `Utilidades/Itrio.php` | URL base de la API de Itrio. |
| `ITRIO_USUARIO` | `Utilidades/Itrio.php` | Usuario de `Itrio::autenticar()`. |
| `ITRIO_CLAVE` | `Utilidades/Itrio.php` | Clave de `Itrio::autenticar()`. |
| `BASE_NIQUEL` | `Utilidades/Niquel.php` | URL base de la API de Níquel. |
| `BASE_WOLFRAMIO` | `Utilidades/Wolframio.php` | URL base de la API de Wolframio. |
| `BASE_CARBONO` | `Utilidades/Carbono.php` | URL base de la API de Carbono. |
| `BASE_TANTALO` | `Utilidades/Tantalo.php`, `Carbono.php` | URL base de la API de Tántalo. |
| `BASE_NOBELIO` | `Utilidades/Nobelio.php` | URL base de la API de Nobelio (facturación electrónica DIAN). |
| `NOBELIO_USUARIO` | `Utilidades/Nobelio.php` | Correo del usuario **de staff** de Nobelio. Ver nota abajo. |
| `NOBELIO_CLAVE` | `Utilidades/Nobelio.php` | Su contraseña. Se usa para pedir un JWT, no viaja en cada petición. |
| `KIAI_TOKEN` | `Utilidades/Softgic.php` | Va como `CURLOPT_USERPWD`, formato `usuario:clave`. |
| `DO_REGION` | `Utilidades/SpaceDO.php` | Región de Spaces. Arma el endpoint `https://{DO_REGION}.digitaloceanspaces.com`. |
| `DO_CLAVE_ACCESO` | `Utilidades/SpaceDO.php` | Access key de Spaces. |
| `DO_CLAVE_SECRETA` | `Utilidades/SpaceDO.php` | Secret key de Spaces. |
| `DO_BUCKET` | `Utilidades/SpaceDO.php` | Nombre del bucket. |

**Sobre las credenciales de Nobelio.** Nobelio (Django + DRF) tiene dos
mecanismos de autenticación y **solo uno alcanza todos los datos**:

| Mecanismo | Cabecera | Alcance |
|---|---|---|
| API Key | `Authorization: Api-Key <prefijo>.<secreto>` | Los emisores de **una sola cuenta** |
| JWT de usuario normal | `Authorization: Bearer <access>` | Los emisores **asignados** a esa persona |
| JWT de usuario **staff** | `Authorization: Bearer <access>` | **Todo, sin restricción** |

Talio usa el tercero. `apps/seguridad/alcance.py` devuelve `None` (sin filtro)
cuando `is_staff` o `is_superuser`, y los endpoints `/api/seguridad/usuario/` y
`/api/seguridad/llave-api/` son exclusivos de staff.

Una API Key **no puede** sustituirlo: `PrincipalLlaveApi` declara
`is_staff = False` fijo en el código, así que jamás pasa el filtro de staff por
mucho que se le den permisos en base de datos.

`Nobelio::autenticar()` cambia usuario y contraseña por un access token
(`POST /api/seguridad/token/`, campo `email` porque ese es el `USERNAME_FIELD`),
lo guarda en sesión y lo renueva solo cuando la API responde 401. El token dura
12 h por defecto.

#### Endpoints de Nobelio

Inventario tomado de los `urls.py` de Nobelio el 2026-08-25. **Puede quedar
desactualizado**: la fuente de verdad son
`/home/desarrollo/proyectos/nobelio/apps/*/urls.py` y los `@action` de sus
ViewSets. Las rutas se pasan a `Nobelio::consumoGet()` y compañía sin barra
inicial, porque `BASE_NOBELIO` ya termina en `/`.

Cada recurso registrado en un router de DRF expone el juego REST completo:
`GET` (lista), `POST` (alta) y `GET`/`PUT`/`PATCH`/`DELETE` sobre `{id}/`.

| Ruta | Notas |
|---|---|
| `estado/` | Estado del servicio. Único endpoint **sin autenticación**. |
| `api/seguridad/token/` | Login. Devuelve `{access, refresh}`. |
| `api/seguridad/token/refresh/` · `token/verify/` | Renovar y verificar. |
| `api/seguridad/usuario/` | **Solo staff.** |
| `api/seguridad/llave-api/` | **Solo staff.** Gestión de API Keys. |
| `api/cuentas/cuenta/` | Cuentas, propietarias de los emisores. |
| `api/catalogos/…` | 12 catálogos de solo lectura: `tipo-factura`, `tipo-identificacion`, `tipo-organizacion`, `responsabilidad-fiscal`, `tributo`, `unidad-medida`, `forma-pago`, `medio-pago`, `moneda`, `pais`, `departamento`, `municipio`. Aceptan `?search=`. |
| `api/emisores/emisor/` | Emisores (OFE). |
| `api/emisores/emisor/validar-nit/` | `GET ?nit=<NIT>` |
| `api/emisores/emisor/crear-habilitacion/` | `POST` · software DIAN de habilitación. |
| `api/emisores/software/` · `certificado/` · `resolucion/` | Recursos del emisor. |
| `api/emisores/certificado/cargar/` | `POST` multipart · sube el `.p12`. |
| `api/emisores/resolucion/consulta-dian/` · `importar-dian/` | Consulta e importa resoluciones desde la DIAN. |
| `api/documentos/documento/` | Documentos electrónicos. |
| `api/documentos/documento/{id}/emitir/` | `POST` · XML UBL + CUFE + firma. |
| `api/documentos/documento/{id}/enviar/` | `POST` · envía al WS de la DIAN. |
| `api/documentos/documento/{id}/consultar/` | `GET` · consulta sin efectos sobre el documento. |
| `api/documentos/documento/{id}/consultar-zip/` | `GET` · **devuelve JSON, no un ZIP**. Consulta el estado del *envío* contra la DIAN (`GetStatusZip` por `track_id`). Usar `consumoGet()`. |
| `api/documentos/documento/{id}/actualizar-estado/` | `POST` · aplica el resultado al documento. |
| `api/documentos/documento/{id}/xml/` · `pdf/` | **Únicas descargas binarias** (`FileResponse` / `HttpResponse`). ⚠️ `Nobelio` **no las cubre**: son JSON para la clase, así que `consumoGet()` devuelve `datos` vacío sin marcar error. Ver nota abajo. |

**Alcance de `Nobelio`.** La clase expone solo `consumoGet()`, `consumoPost()` y
`autenticar()`, que es lo que necesitan las pantallas de hoy. Los verbos
`PUT`/`PATCH`/`DELETE` y la descarga binaria se añaden cuando haga falta —
`peticion()` ya acepta cualquier método, así que un verbo nuevo es una línea.

Lo que **no** es una línea es la descarga: `xml/` y `pdf/` devuelven archivo, y
todo el camino interno de la clase pasa por `decodificar()`, que hace
`json_decode`. Sobre los bytes de un PDF eso da `null` → `datos` vacío → y
como el HTTP fue 200, `error` queda en `false`. Un éxito silencioso. Para
bajarlos hay que añadir una vía que retorne `$response->getContent(false)` sin
decodificar, no reutilizar `consumoGet()`.

Las `BASE_*` **deben terminar en `/`**, porque el código concatena sin
separador: `$_ENV['BASE_X'] . $url`.

## Pendientes conocidos

- **`Carbono::consumoGet()`** (`src/Utilidades/Carbono.php:58`) usa
  `BASE_TANTALO`, mientras que `Carbono::consumoPost()` (línea 18) usa
  `BASE_CARBONO`. Verificar si es intencional.
- **`APP_SECRET`** quedó commiteado en el historial (`5fad149`, `99cf641`).
  Si el proyecto ya está en producción, conviene rotarlo.
