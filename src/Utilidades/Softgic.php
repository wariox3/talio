<?php

namespace App\Utilidades;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente de la API de Kiai/Softgic (apps.kiai.co), con autenticacion basica.
 *
 * Kiai informa sus errores con {"ExceptionType", "ExceptionMessage"} y a veces
 * lo hace con status 200, asi que eso tambien cuenta como error.
 */
class Softgic extends ClienteApi
{
    private const URL_BASE = 'https://apps.kiai.co/api/';

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'KIAI_TOKEN')] private readonly string $credenciales,
    ) {
        parent::__construct($httpClient, self::URL_BASE, 'Kiai');
    }

    public function consultaSuscriptor(string $suscriptor): array
    {
        $respuesta = $this->consumoGet('ConValidacionPrevia/ResumenSuscriptor/' . rawurlencode($suscriptor));
        if ($respuesta['error']) {
            return $respuesta;
        }

        $datos = $respuesta['datos'];

        return [
            'error' => false,
            'suscriptor' => $datos['Suscriptor'] ?? [],
            'resoluciones' => $datos['ResolucionesFacturas']['ResolucionesFacturacion'] ?? [],
        ];
    }

    public function consultaEmpleador(string $empleador): array
    {
        $respuesta = $this->consumoGet('Empleadores/ObtenerPorId/' . rawurlencode($empleador));
        if ($respuesta['error']) {
            return $respuesta;
        }

        return ['error' => false, 'empleador' => $respuesta['datos']['Data'] ?? []];
    }

    public function consultaConsumo(string $aliado, int $anio, int $mes): array
    {
        $respuesta = $this->consumoGet(sprintf('ConValidacionPrevia/ConsultarConsumosAliado/%s/%d/%02d', rawurlencode($aliado), $anio, $mes));
        if ($respuesta['error']) {
            return $respuesta;
        }

        return ['error' => false, 'consumos' => $respuesta['datos']];
    }

    private function consumoGet(string $url): array
    {
        $respuesta = $this->peticion('GET', $url);
        if (!$respuesta['error'] && isset($respuesta['datos']['ExceptionType'])) {
            return ['error' => true, 'status' => $respuesta['status'], 'mensaje' => $this->mensajeDeError($respuesta['datos'], $respuesta['status'])];
        }

        return $respuesta;
    }

    protected function cabeceras(): array
    {
        // KIAI_TOKEN es "usuario:clave", lo que curl recibia en CURLOPT_USERPWD.
        return ['Authorization' => 'Basic ' . base64_encode($this->credenciales)];
    }

    protected function mensajeDeError(array $cuerpo, int $status): string
    {
        foreach (['ExceptionMessage', 'Message'] as $clave) {
            if (isset($cuerpo[$clave]) && is_string($cuerpo[$clave]) && trim($cuerpo[$clave]) !== '') {
                return trim($cuerpo[$clave]);
            }
        }

        return parent::mensajeDeError($cuerpo, $status);
    }
}
