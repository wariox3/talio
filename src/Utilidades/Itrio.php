<?php

namespace App\Utilidades;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente de la API de Itrio (proyecto RUTEOAPP), autenticado con JWT.
 *
 * El token se pide con ITRIO_USUARIO / ITRIO_CLAVE la primera vez que hace
 * falta y se guarda en la sesion del usuario de Talio. Todas las peticiones lo
 * llevan —antes POST y PATCH salian sin el— y, si Itrio responde 401 porque
 * caduco, se pide uno nuevo y se reintenta una sola vez. Antes el token
 * caducado se quedaba en sesion y todo Itrio fallaba hasta cerrar sesion.
 */
class Itrio extends ClienteApi
{
    private const CLAVE_SESION = 'itrio_token';

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly RequestStack $requestStack,
        #[Autowire(env: 'BASE_ITRIO')] string $urlBase,
        #[Autowire(env: 'ITRIO_USUARIO')] private readonly string $usuario,
        #[Autowire(env: 'ITRIO_CLAVE')] private readonly string $clave,
    ) {
        parent::__construct($httpClient, $urlBase, 'Itrio');
    }

    public function consumoGet(string $url): array
    {
        return $this->autenticada('GET', $url);
    }

    public function consumoPost(string $url, array $datos = []): array
    {
        return $this->autenticada('POST', $url, ['json' => $datos]);
    }

    public function consumoPatch(string $url, array $datos = []): array
    {
        return $this->autenticada('PATCH', $url, ['json' => $datos]);
    }

    /** GET de un endpoint que devuelve archivo (ver ClienteApi::peticion()). */
    public function consumoArchivo(string $url): array
    {
        return $this->autenticada('GET', $url, [], true);
    }

    private function autenticada(string $metodo, string $url, array $opciones = [], bool $archivo = false): array
    {
        $respuesta = $this->token();
        if ($respuesta['error']) {
            return $respuesta;
        }

        $resultado = $this->peticion($metodo, $url, $this->conToken($opciones, $respuesta['token']), $archivo);
        if ($resultado['status'] !== 401) {
            return $resultado;
        }

        // Token caducado o revocado: uno nuevo y un solo reintento.
        $respuesta = $this->token(true);
        if ($respuesta['error']) {
            return $respuesta;
        }

        return $this->peticion($metodo, $url, $this->conToken($opciones, $respuesta['token']), $archivo);
    }

    private function conToken(array $opciones, string $token): array
    {
        $opciones['headers'] = ['Authorization' => 'Bearer ' . $token] + ($opciones['headers'] ?? []);

        return $opciones;
    }

    /**
     * Token de la sesion, o uno recien pedido si no hay o si $renovar.
     *
     * @return array{error: bool, token?: string, status?: int, mensaje?: string}
     */
    private function token(bool $renovar = false): array
    {
        $sesion = $this->requestStack->getSession();
        $token = $renovar ? null : $sesion->get(self::CLAVE_SESION);
        if (is_string($token) && $token !== '') {
            return ['error' => false, 'token' => $token];
        }

        $respuesta = $this->peticion('POST', 'seguridad/login/', ['json' => [
            'username' => $this->usuario,
            'password' => $this->clave,
            'proyecto' => 'RUTEOAPP',
        ]]);
        if ($respuesta['error']) {
            $sesion->remove(self::CLAVE_SESION);

            return ['error' => true, 'status' => $respuesta['status'], 'mensaje' => "Error en la autenticación con Itrio: {$respuesta['mensaje']}"];
        }

        $token = $respuesta['datos']['token'] ?? '';
        if (!is_string($token) || $token === '') {
            return ['error' => true, 'status' => $respuesta['status'], 'mensaje' => 'Itrio no devolvió token al autenticar.'];
        }

        $sesion->set(self::CLAVE_SESION, $token);

        return ['error' => false, 'token' => $token];
    }
}
