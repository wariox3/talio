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
| `KIAI_TOKEN` | `Utilidades/Softgic.php` | Va como `CURLOPT_USERPWD`, formato `usuario:clave`. |
| `DO_REGION` | `Utilidades/SpaceDO.php` | Región de Spaces. Arma el endpoint `https://{DO_REGION}.digitaloceanspaces.com`. |
| `DO_CLAVE_ACCESO` | `Utilidades/SpaceDO.php` | Access key de Spaces. |
| `DO_CLAVE_SECRETA` | `Utilidades/SpaceDO.php` | Secret key de Spaces. |
| `DO_BUCKET` | `Utilidades/SpaceDO.php` | Nombre del bucket. |

Las `BASE_*` **deben terminar en `/`**, porque el código concatena sin
separador: `$_ENV['BASE_X'] . $url`.

## Pendientes conocidos

- **`Carbono::consumoGet()`** (`src/Utilidades/Carbono.php:58`) usa
  `BASE_TANTALO`, mientras que `Carbono::consumoPost()` (línea 18) usa
  `BASE_CARBONO`. Verificar si es intencional.
- **`APP_SECRET`** quedó commiteado en el historial (`5fad149`, `99cf641`).
  Si el proyecto ya está en producción, conviene rotarlo.
