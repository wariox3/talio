<?php

namespace App\Utilidades;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Nobelio
{
    public function consumoGet(string $url, array $parametros = []): array
    {
        return $this->peticion('GET', $url, ['query' => $parametros]);
    }

    public function consumoPost(string $url, array $datos = []): array
    {
        // Un array vacio de PHP se serializa como [], y los endpoints que leen
        // el cuerpo esperan un objeto: DRF responde "Datos inválidos. Se
        // esperaba un diccionario pero es un list". Con stdClass sale {}.
        return $this->peticion('POST', $url, ['json' => $datos ?: new \stdClass()]);
    }

    public function consumoPatch(string $url, array $datos = []): array
    {
        // Mismo motivo que en consumoPost(): un array vacio saldria como [].
        return $this->peticion('PATCH', $url, ['json' => $datos ?: new \stdClass()]);
    }

    public function consumoDelete(string $url): array
    {
        return $this->peticion('DELETE', $url, []);
    }

    /**
     * GET de un endpoint que devuelve archivo en vez de JSON (xml/, pdf/).
     *
     * consumoGet() no sirve para estos: hace json_decode sobre los bytes, que
     * da null, y como el HTTP fue 200 devuelve 'datos' vacio sin marcar error.
     * En exito esta devuelve el contenido crudo en 'contenido', con el tipo y
     * el nombre que anuncien las cabeceras. El error sigue siendo JSON: los
     * endpoints binarios fallan con el mismo cuerpo que el resto del API.
     */
    public function consumoArchivo(string $url, array $parametros = []): array
    {
        return $this->peticion('GET', $url, ['query' => $parametros], true);
    }

    /**
     * Nobelio autentica con API Key, no con JWT: la llave va tal cual en cada
     * peticion, no caduca y no hay nada que guardar en sesion ni que renovar.
     */
    private function peticion(string $metodo, string $url, array $opciones, bool $archivo = false): array
    {
        $llave = $_ENV['NOBELIO_TOKEN'] ?? '';

        if ($llave === '') {
            return ['error' => true, 'mensaje' => 'Falta NOBELIO_TOKEN en el .env'];
        }

        try {
            $client = HttpClient::create();
            // El esquema "Api-Key" es el que espera djangorestframework-api-key.
            $opciones['headers'] = ['Authorization' => 'Api-Key ' . $llave];

            $response = $client->request($metodo, $this->rutaCompleta($url), $opciones);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                if (!$archivo) {
                    return ['error' => false, 'datos' => $this->decodificar($response)];
                }

                $cabeceras = $response->getHeaders(false);

                return [
                    'error' => false,
                    'contenido' => $response->getContent(false),
                    'tipo' => $cabeceras['content-type'][0] ?? 'application/octet-stream',
                    'nombre' => $this->nombreDeArchivo($cabeceras['content-disposition'][0] ?? ''),
                ];
            }

            return [
                'error' => true,
                'mensaje' => $status === 401
                    ? 'Nobelio rechazó la API Key de NOBELIO_TOKEN'
                    : $this->mensajeDeError($this->decodificar($response), $status),
            ];
        } catch (TransportExceptionInterface $e) {
            return ['error' => true, 'mensaje' => $e->getMessage()];
        }
    }

    private function rutaCompleta(string $url): string
    {
        $url = ltrim($url, '/');
        [$ruta, $query] = array_pad(explode('?', $url, 2), 2, null);

        if ($ruta !== '' && !str_ends_with($ruta, '/')) {
            $ruta .= '/';
        }

        return ($_ENV['BASE_NOBELIO'] ?? '') . $ruta . ($query !== null ? '?' . $query : '');
    }

    /**
     * Nombre que anuncia el Content-Disposition, o cadena vacia si no trae.
     *
     * Nobelio manda `attachment; filename="FE1.xml"` con el numero del
     * documento, que es mejor nombre que cualquiera que se arme aqui a partir
     * del id.
     */
    private function nombreDeArchivo(string $cabecera): string
    {
        if (!preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $cabecera, $coincidencias)) {
            return '';
        }

        // basename() corta cualquier ruta: el nombre llega de fuera y termina
        // en una cabecera de descarga.
        return basename(trim(urldecode($coincidencias[1])));
    }

    private function decodificar($response): array
    {
        try {
            $contenido = $response->getContent(false);
        } catch (\Throwable $e) {
            return [];
        }

        if ($contenido === '') {
            return [];
        }

        $datos = json_decode($contenido, true);

        return is_array($datos) ? $datos : [];
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
    private function mensajeDeError(array $cuerpo, int $status): string
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
