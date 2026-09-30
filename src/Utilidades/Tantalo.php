<?php

namespace App\Utilidades;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente de la API de Tántalo (decodificacion de documentos).
 *
 * Tántalo no pide autenticacion. Igual que en Wolframio, ya no se le manda el
 * token de Itrio que antes salia de la sesion.
 */
class Tantalo extends ClienteApi
{
    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'BASE_TANTALO')] string $urlBase,
    ) {
        parent::__construct($httpClient, $urlBase, 'Tántalo');
    }

    public function consumoGet(string $url): array
    {
        return $this->peticion('GET', $url);
    }
}
