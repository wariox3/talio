<?php

namespace App\Utilidades;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Base de los clientes de las APIs externas (Wolframio, Tántalo, Itrio,
 * Nobelio, Kiai).
 *
 * Todas las peticiones devuelven el mismo arreglo y nunca lanzan excepción:
 *
 *   exito:  ['error' => false, 'status' => 200, 'datos' => [...]]
 *   fallo:  ['error' => true,  'status' => 4xx|5xx|0, 'mensaje' => '...']
 *
 * status 0 es que no hubo respuesta (servicio caido, DNS, tiempo agotado).
 * Con $archivo = true el exito trae el contenido crudo en vez de 'datos'
 * (ver peticion()).
 *
 * Cada peticion tiene tiempo limite: sin el, un servicio caido dejaba la
 * pagina colgada el default_socket_timeout de PHP (60 s) por cada llamada, y
 * el monitor de Wolframio hace cinco seguidas.
 */
abstract class ClienteApi
{
    /** Segundos sin recibir nada antes de dar la peticion por perdida. */
    private const TIMEOUT = 10;

    /** Segundos maximos de la peticion completa, aunque siga llegando algo. */
    private const DURACION_MAXIMA = 30;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly string $urlBase,
        protected readonly string $servicio,
    ) {
    }

    /**
     * Hace la peticion y la traduce al arreglo comun.
     *
     * Con $archivo = true, para endpoints que devuelven un archivo en vez de
     * JSON, el exito es ['error' => false, 'status', 'contenido', 'tipo',
     * 'nombre'], con el tipo y el nombre que anuncien las cabeceras.
     */
    protected function peticion(string $metodo, string $url, array $opciones = [], bool $archivo = false): array
    {
        $opciones += [
            'timeout' => self::TIMEOUT,
            'max_duration' => self::DURACION_MAXIMA,
        ];
        $opciones['headers'] = ($opciones['headers'] ?? []) + $this->cabeceras();

        try {
            $response = $this->httpClient->request($metodo, $this->rutaCompleta($url), $opciones);
            $status = $response->getStatusCode();

            if ($status < 200 || $status >= 300) {
                return [
                    'error' => true,
                    'status' => $status,
                    'mensaje' => $this->mensajeDeError($this->decodificar($response) ?? [], $status),
                ];
            }

            if ($archivo) {
                $cabeceras = $response->getHeaders(false);

                return [
                    'error' => false,
                    'status' => $status,
                    'contenido' => $response->getContent(false),
                    'tipo' => $cabeceras['content-type'][0] ?? 'application/octet-stream',
                    'nombre' => $this->nombreDeArchivo($cabeceras['content-disposition'][0] ?? ''),
                ];
            }

            $datos = $this->decodificar($response);
            if ($datos === null) {
                return [
                    'error' => true,
                    'status' => $status,
                    'mensaje' => "{$this->servicio} devolvió una respuesta que no es JSON.",
                ];
            }

            return ['error' => false, 'status' => $status, 'datos' => $datos];
        } catch (TransportExceptionInterface $e) {
            return [
                'error' => true,
                'status' => 0,
                'mensaje' => "{$this->servicio} no responde: {$e->getMessage()}",
            ];
        }
    }

    /** Cabeceras que van en todas las peticiones del servicio (autenticacion). */
    protected function cabeceras(): array
    {
        return [];
    }

    /**
     * URL absoluta de la peticion. La URL base del .env termina en "/" y las
     * rutas del codigo van sin "/" inicial.
     */
    protected function rutaCompleta(string $url): string
    {
        return $this->urlBase . ltrim($url, '/');
    }

    /**
     * Texto legible a partir del cuerpo de error. Los servicios internos
     * responden {"mensaje": "..."}; los hechos con DRF, {"detail": "..."}.
     */
    protected function mensajeDeError(array $cuerpo, int $status): string
    {
        foreach (['mensaje', 'detail', 'error'] as $clave) {
            if (isset($cuerpo[$clave]) && is_string($cuerpo[$clave]) && trim($cuerpo[$clave]) !== '') {
                return trim($cuerpo[$clave]);
            }
        }

        return "{$this->servicio} respondió con el código {$status}.";
    }

    /**
     * Cuerpo JSON como arreglo: [] si viene vacio, null si no es JSON.
     */
    private function decodificar(ResponseInterface $response): ?array
    {
        $contenido = $response->getContent(false);
        if (trim($contenido) === '') {
            return [];
        }

        $datos = json_decode($contenido, true);

        return is_array($datos) ? $datos : null;
    }

    /**
     * Nombre que anuncia el Content-Disposition, o cadena vacia si no trae.
     * basename() corta cualquier ruta: el nombre llega de fuera y termina en
     * una cabecera de descarga.
     */
    private function nombreDeArchivo(string $cabecera): string
    {
        if (!preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $cabecera, $coincidencias)) {
            return '';
        }

        return basename(trim(urldecode($coincidencias[1])));
    }
}
