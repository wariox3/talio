<?php

namespace App\Utilidades;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente de la API de Nobelio (facturacion electronica DIAN).
 *
 * Nobelio autentica con API Key, no con JWT: la llave va tal cual en cada
 * peticion, no caduca y no hay nada que guardar en sesion ni que renovar.
 *
 * La URL y la llave salen del .env (BASE_NOBELIO y NOBELIO_TOKEN): cada
 * instalacion de Talio apunta a una sola instancia de Nobelio.
 */
class Nobelio extends ClienteApi
{
    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'BASE_NOBELIO')] string $urlBase,
        #[Autowire(env: 'NOBELIO_TOKEN')] private readonly string $llave,
    ) {
        parent::__construct($httpClient, $urlBase, 'Nobelio');
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
        $respuesta = $this->peticion($metodo, $url, $opciones, $archivo);
        if ($respuesta['status'] === 401) {
            $respuesta['mensaje'] = 'Nobelio rechazó la API Key de NOBELIO_TOKEN';
        }

        return $respuesta;
    }

    protected function cabeceras(): array
    {
        // El esquema "Api-Key" es el que espera djangorestframework-api-key.
        return ['Authorization' => 'Api-Key ' . $this->llave];
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

        return $this->urlBase . $ruta . ($query !== null ? '?' . $query : '');
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
