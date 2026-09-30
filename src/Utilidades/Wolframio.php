<?php

namespace App\Utilidades;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente de la API de Wolframio (facturacion electronica via Softgic).
 *
 * Wolframio no pide autenticacion. Antes consumoGet() mandaba como Bearer el
 * token de Itrio guardado en sesion, que no le corresponde; ya no se envia.
 */
class Wolframio extends ClienteApi
{
    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'BASE_WOLFRAMIO')] string $urlBase,
    ) {
        parent::__construct($httpClient, $urlBase, 'Wolframio');
    }

    public function consumoGet(string $url): array
    {
        return $this->peticion('GET', $url);
    }

    public function consumoPost(string $url, array $datos = []): array
    {
        return $this->peticion('POST', $url, ['json' => $datos]);
    }
}
