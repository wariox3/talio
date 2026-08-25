<?php

namespace App\Utilidades;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Nobelio
{
    private const CLAVE_TOKEN = 'nobelio_token';

    public function __construct(private RequestStack $requestStack)
    {
    }

    public function consumoGet(string $url, array $parametros = []): array
    {
        return $this->peticion('GET', $url, ['query' => $parametros]);
    }

    public function consumoPost(string $url, array $datos = []): array
    {
        return $this->peticion('POST', $url, ['json' => $datos]);
    }

    public function consumoDelete(string $url): array
    {
        return $this->peticion('DELETE', $url, []);
    }

    public function autenticar(): array
    {
        $usuario = $_ENV['NOBELIO_USUARIO'] ?? '';
        $clave = $_ENV['NOBELIO_CLAVE'] ?? '';

        if ($usuario === '' || $clave === '') {
            return [
                'error' => true,
                'mensaje' => 'Faltan NOBELIO_USUARIO o NOBELIO_CLAVE en el .env',
            ];
        }

        try {
            $client = HttpClient::create();
            $response = $client->request('POST', $this->rutaCompleta('api/seguridad/token/'), [
                // El USERNAME_FIELD del Usuario de Nobelio es "email", no "username".
                'json' => ['email' => $usuario, 'password' => $clave],
            ]);

            $status = $response->getStatusCode();
            $cuerpo = $this->decodificar($response);

            if ($status === 200 && isset($cuerpo['access'])) {
                $this->requestStack->getSession()->set(self::CLAVE_TOKEN, $cuerpo['access']);

                return ['error' => false, 'token' => $cuerpo['access']];
            }

            return [
                'error' => true,
                'mensaje' => $status === 401
                    ? 'Credenciales de Nobelio rechazadas'
                    : $this->mensajeDeError($cuerpo, $status),
            ];
        } catch (TransportExceptionInterface $e) {
            return ['error' => true, 'mensaje' => $e->getMessage()];
        }
    }

    private function peticion(string $metodo, string $url, array $opciones): array
    {
        $respuesta = $this->enviar($metodo, $url, $opciones);

        if ($respuesta['error'] && ($respuesta['estado'] ?? 0) === 401) {
            $autenticacion = $this->autenticar();
            if ($autenticacion['error']) {
                return $autenticacion;
            }
            $respuesta = $this->enviar($metodo, $url, $opciones);
        }

        unset($respuesta['estado']);

        return $respuesta;
    }

    private function enviar(string $metodo, string $url, array $opciones): array
    {
        try {
            $client = HttpClient::create();
            $opciones['headers'] = ['Authorization' => 'Bearer ' . $this->token()];

            $response = $client->request($metodo, $this->rutaCompleta($url), $opciones);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return ['error' => false, 'datos' => $this->decodificar($response)];
            }

            return [
                'error' => true,
                'estado' => $status,
                'mensaje' => $this->mensajeDeError($this->decodificar($response), $status),
            ];
        } catch (TransportExceptionInterface $e) {
            return ['error' => true, 'estado' => 0, 'mensaje' => $e->getMessage()];
        }
    }

    private function token(): string
    {
        return (string) $this->requestStack->getSession()->get(self::CLAVE_TOKEN, '');
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

    private function mensajeDeError(array $cuerpo, int $status): string
    {
        if (isset($cuerpo['detail']) && is_string($cuerpo['detail'])) {
            return $cuerpo['detail'];
        }

        $partes = [];
        foreach ($cuerpo as $campo => $valor) {
            $texto = is_array($valor) ? implode(' ', array_map('strval', $valor)) : (string) $valor;
            $partes[] = is_string($campo) ? "{$campo}: {$texto}" : $texto;
        }

        if ($partes) {
            return implode(' | ', $partes);
        }

        return "El servicio Nobelio respondió {$status}";
    }
}
