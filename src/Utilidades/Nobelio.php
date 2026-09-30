<?php

namespace App\Utilidades;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente de la API de Nobelio (facturacion electronica DIAN).
 *
 * Nobelio autentica con API Key, no con JWT: la llave va tal cual en cada
 * peticion, no caduca y no hay nada que guardar en sesion ni que renovar.
 *
 * Hay tres instancias de Nobelio (produccion, prueba y desarrollo), cada una
 * con su URL y su API Key en el .env (BASE_NOBELIO_<AMBIENTE> y
 * NOBELIO_TOKEN_<AMBIENTE>). El usuario elige contra cual trabaja desde el
 * menu; la eleccion se guarda en su sesion y vale para todas las peticiones
 * hasta que la cambie. Un ambiente sin sus dos variables no se puede elegir.
 */
class Nobelio extends ClienteApi
{
    /** Ambientes en el orden del selector: clave => nombre visible. */
    public const AMBIENTES = [
        'produccion' => 'Producción',
        'prueba' => 'Prueba',
        'desarrollo' => 'Desarrollo',
    ];

    private const CLAVE_SESION = 'nobelio_ambiente';

    /** @var array<string, array{url: string, llave: string}> */
    private readonly array $configuracion;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly RequestStack $requestStack,
        #[Autowire(env: 'default::BASE_NOBELIO_PRODUCCION')] ?string $urlProduccion,
        #[Autowire(env: 'default::NOBELIO_TOKEN_PRODUCCION')] ?string $llaveProduccion,
        #[Autowire(env: 'default::BASE_NOBELIO_PRUEBA')] ?string $urlPrueba,
        #[Autowire(env: 'default::NOBELIO_TOKEN_PRUEBA')] ?string $llavePrueba,
        #[Autowire(env: 'default::BASE_NOBELIO_DESARROLLO')] ?string $urlDesarrollo,
        #[Autowire(env: 'default::NOBELIO_TOKEN_DESARROLLO')] ?string $llaveDesarrollo,
    ) {
        // La URL base depende del ambiente elegido: se arma en rutaCompleta().
        parent::__construct($httpClient, '', 'Nobelio');

        $this->configuracion = [
            'produccion' => ['url' => (string) $urlProduccion, 'llave' => (string) $llaveProduccion],
            'prueba' => ['url' => (string) $urlPrueba, 'llave' => (string) $llavePrueba],
            'desarrollo' => ['url' => (string) $urlDesarrollo, 'llave' => (string) $llaveDesarrollo],
        ];
    }

    /**
     * Ambientes que tienen URL y llave en el .env, en el orden del selector.
     *
     * @return list<string>
     */
    public function ambientesDisponibles(): array
    {
        return array_keys(array_filter(
            $this->configuracion,
            fn (array $ambiente) => $ambiente['url'] !== '' && $ambiente['llave'] !== '',
        ));
    }

    /**
     * Ambiente con el que se trabaja: el elegido en la sesion si sigue
     * disponible, o el primero disponible (produccion si esta configurado).
     * null si no hay ninguno configurado.
     */
    public function ambiente(): ?string
    {
        $disponibles = $this->ambientesDisponibles();
        $elegido = $this->sesion()?->get(self::CLAVE_SESION);

        return in_array($elegido, $disponibles, true) ? $elegido : ($disponibles[0] ?? null);
    }

    public function nombreAmbiente(): string
    {
        $ambiente = $this->ambiente();

        return $ambiente !== null ? self::AMBIENTES[$ambiente] : 'Sin configurar';
    }

    /** Cambia el ambiente de la sesion. false si no existe o no esta configurado. */
    public function cambiarAmbiente(string $ambiente): bool
    {
        if (!in_array($ambiente, $this->ambientesDisponibles(), true)) {
            return false;
        }

        $this->sesion()?->set(self::CLAVE_SESION, $ambiente);

        return true;
    }

    public function consumoGet(string $url, array $parametros = []): array
    {
        return $this->llamar('GET', $url, ['query' => $parametros]);
    }

    /**
     * GET de un listado paginado, recorriendo todas sus paginas.
     *
     * Nobelio pagina de 10 en 10 y no deja pedir mas por pagina, asi que para
     * listas cortas que se muestran enteras —los eventos de un documento— se
     * siguen las paginas. En exito, 'datos' es la lista de resultados junta.
     * El tope evita un bucle largo si un listado resultara no ser corto.
     */
    public function consumoGetTodos(string $url, array $parametros = [], int $maxPaginas = 10): array
    {
        $resultados = [];

        for ($pagina = 1; $pagina <= $maxPaginas; $pagina++) {
            $respuesta = $this->consumoGet($url, ['page' => $pagina] + $parametros);
            if ($respuesta['error']) {
                return $respuesta;
            }

            array_push($resultados, ...($respuesta['datos']['results'] ?? []));
            if (empty($respuesta['datos']['next'])) {
                break;
            }
        }

        return ['error' => false, 'status' => 200, 'datos' => $resultados];
    }

    public function consumoPost(string $url, array $datos = []): array
    {
        // Un array vacio de PHP se serializa como [], y los endpoints que leen
        // el cuerpo esperan un objeto: DRF responde "Datos inválidos. Se
        // esperaba un diccionario pero es un list". Con stdClass sale {}.
        return $this->llamar('POST', $url, ['json' => $datos ?: new \stdClass()]);
    }

    public function consumoPatch(string $url, array $datos = []): array
    {
        // Mismo motivo que en consumoPost(): un array vacio saldria como [].
        return $this->llamar('PATCH', $url, ['json' => $datos ?: new \stdClass()]);
    }

    public function consumoDelete(string $url): array
    {
        return $this->llamar('DELETE', $url);
    }

    /**
     * GET de un endpoint que devuelve archivo en vez de JSON (xml/, pdf/).
     *
     * En exito devuelve el contenido crudo en 'contenido', con el tipo y el
     * nombre que anuncien las cabeceras. El error sigue siendo JSON: los
     * endpoints binarios fallan con el mismo cuerpo que el resto del API.
     */
    public function consumoArchivo(string $url, array $parametros = []): array
    {
        return $this->llamar('GET', $url, ['query' => $parametros], true);
    }

    /**
     * Los ids que llegan del formulario se concatenan a la URL del API: solo
     * se aceptan con forma de UUID para que no se pueda construir otra ruta.
     */
    public static function esUuid(string $id): bool
    {
        return preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $id) === 1;
    }

    private function llamar(string $metodo, string $url, array $opciones = [], bool $archivo = false): array
    {
        if ($this->ambiente() === null) {
            return ['error' => true, 'status' => 0, 'mensaje' => 'No hay ningún ambiente de Nobelio configurado: faltan BASE_NOBELIO_<AMBIENTE> y NOBELIO_TOKEN_<AMBIENTE> en el .env'];
        }

        $respuesta = $this->peticion($metodo, $url, $opciones, $archivo);
        if ($respuesta['status'] === 401) {
            $respuesta['mensaje'] = 'Nobelio (' . $this->nombreAmbiente() . ') rechazó la API Key de NOBELIO_TOKEN_' . strtoupper($this->ambiente());
        }

        return $respuesta;
    }

    protected function cabeceras(): array
    {
        // El esquema "Api-Key" es el que espera djangorestframework-api-key.
        return ['Authorization' => 'Api-Key ' . $this->configuracion[$this->ambiente()]['llave']];
    }

    /** La sesion del usuario, o null fuera de una peticion web (consola, pruebas). */
    private function sesion(): ?SessionInterface
    {
        try {
            return $this->requestStack->getSession();
        } catch (SessionNotFoundException) {
            return null;
        }
    }

    /**
     * DRF redirige las rutas sin "/" final, asi que se le agrega a la ruta
     * (no a la query).
     */
    protected function rutaCompleta(string $url): string
    {
        [$ruta, $query] = array_pad(explode('?', ltrim($url, '/'), 2), 2, null);

        if ($ruta !== '' && !str_ends_with($ruta, '/')) {
            $ruta .= '/';
        }

        return $this->configuracion[$this->ambiente()]['url'] . $ruta . ($query !== null ? '?' . $query : '');
    }

    /**
     * Arma un texto legible con el cuerpo de error de Nobelio.
     *
     * Nobelio responde siempre `{"detail": "...", "errores": [{"codigo": "...",
     * "mensaje": "..."}]}`, con la lista nunca vacia. En un error de negocio o
     * un 404 la lista trae un solo elemento con el mismo texto que `detail`;
     * en los de campos `detail` es el generico ("La solicitud no es válida.")
     * y lo que explica el fallo son los mensajes, que ya llevan la ruta del
     * campo delante (`detalles[0].impuestos[0].tributo: Este campo es
     * obligatorio.`). Por eso se junta `detail` con los mensajes que no lo
     * repitan.
     */
    protected function mensajeDeError(array $cuerpo, int $status): string
    {
        $detalle = isset($cuerpo['detail']) && is_string($cuerpo['detail']) ? trim($cuerpo['detail']) : '';

        $mensajes = [];
        foreach ((array) ($cuerpo['errores'] ?? []) as $error) {
            $mensaje = is_array($error) ? trim((string) ($error['mensaje'] ?? '')) : '';
            if ($mensaje !== '' && $mensaje !== $detalle && !in_array($mensaje, $mensajes, true)) {
                $mensajes[] = $mensaje;
            }
        }

        $texto = trim($detalle . ' ' . implode(' | ', $mensajes));

        return $texto !== '' ? $texto : "El servicio Nobelio respondió {$status}";
    }
}
