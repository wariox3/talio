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
| `NOBELIO_TOKEN` | `Utilidades/Nobelio.php` | API Key de Nobelio, `<prefijo>.<secreto>`. Ver nota abajo. |
| `KIAI_TOKEN` | `Utilidades/Softgic.php` | Va como `CURLOPT_USERPWD`, formato `usuario:clave`. |
| `DO_REGION` | `Utilidades/SpaceDO.php` | Región de Spaces. Arma el endpoint `https://{DO_REGION}.digitaloceanspaces.com`. |
| `DO_CLAVE_ACCESO` | `Utilidades/SpaceDO.php` | Access key de Spaces. |
| `DO_CLAVE_SECRETA` | `Utilidades/SpaceDO.php` | Secret key de Spaces. |
| `DO_BUCKET` | `Utilidades/SpaceDO.php` | Nombre del bucket. |

**Sobre las credenciales de Nobelio.** Nobelio (Django + DRF) tiene dos
mecanismos de autenticación, y Talio usa el primero:

| Mecanismo | Cabecera | Alcance |
|---|---|---|
| API Key | `Authorization: Api-Key <prefijo>.<secreto>` | Los emisores y documentos de **una sola cuenta** |
| JWT de usuario | `Authorization: Bearer <access>` | Según el usuario: los emisores asignados, o todo si es staff |

La API Key va tal cual en cada petición (`Nobelio::peticion()`), **no caduca** y
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
inicial, porque `BASE_NOBELIO` ya termina en `/`.

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

**Alcance de `Nobelio`.** La clase expone `consumoGet()`, `consumoGetTodos()` (recorre las páginas),
`consumoPost()`, `consumoPatch()`, `consumoDelete()` y `consumoArchivo()`. `PUT` se añade
cuando haga falta: `peticion()` ya acepta cualquier método, así que un verbo
nuevo es una línea.

**Las descargas van por `consumoArchivo()`.** `xml/` y `pdf/` devuelven archivo,
y el camino normal de la clase pasa por `decodificar()`, que hace `json_decode`.
Sobre los bytes de un PDF eso da `null` → `datos` vacío → y como el HTTP fue
200, `error` queda en `false`: un éxito silencioso, sin archivo y sin aviso. Por
eso `consumoArchivo()` no decodifica y devuelve otras claves:

| Clave | Contenido |
|---|---|
| `contenido` | Los bytes crudos (**no** `datos`). |
| `tipo` | El `Content-Type` que anuncia Nobelio. |
| `nombre` | El nombre del `Content-Disposition` (`FE1.xml`), o `''` si no viene. |

El error sí sigue siendo JSON —los endpoints binarios fallan con el mismo cuerpo
que el resto del API—, así que `error`/`mensaje` funcionan igual que siempre.
Ejemplo de uso en `DocumentoController::descargar()`.

Las `BASE_*` **deben terminar en `/`**, porque el código concatena sin
separador: `$_ENV['BASE_X'] . $url`.

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

- **`Carbono::consumoGet()`** (`src/Utilidades/Carbono.php:58`) usa
  `BASE_TANTALO`, mientras que `Carbono::consumoPost()` (línea 18) usa
  `BASE_CARBONO`. Verificar si es intencional.
- **`APP_SECRET`** quedó commiteado en el historial (`5fad149`, `99cf641`).
  Si el proyecto ya está en producción, conviene rotarlo.
