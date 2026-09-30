<?php

namespace App\Tests\Utilidades;

use App\Utilidades\Softgic;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SoftgicTest extends TestCase
{
    private array $peticion = [];

    private function softgic(MockResponse $respuesta): Softgic
    {
        $cliente = new MockHttpClient(function (string $metodo, string $url, array $opciones) use ($respuesta) {
            $this->peticion = compact('metodo', 'url', 'opciones');

            return $respuesta;
        });

        return new Softgic($cliente, 'usuario:clave');
    }

    public function testConsumoConAutenticacionBasicaYMesDeDosDigitos(): void
    {
        $respuesta = $this->softgic(new MockResponse('[{"Suscriptor": "A"}]'))->consultaConsumo('ALIADO-1', 2026, 9);

        $this->assertFalse($respuesta['error']);
        $this->assertSame([['Suscriptor' => 'A']], $respuesta['consumos']);
        $this->assertSame('https://apps.kiai.co/api/ConValidacionPrevia/ConsultarConsumosAliado/ALIADO-1/2026/09', $this->peticion['url']);
        $this->assertSame(
            ['Authorization: Basic ' . base64_encode('usuario:clave')],
            $this->peticion['opciones']['normalized_headers']['authorization'],
        );
    }

    public function testExcepcionConStatus200EsError(): void
    {
        $cuerpo = '{"ExceptionType": "System.Exception", "ExceptionMessage": "Suscriptor no encontrado"}';

        $respuesta = $this->softgic(new MockResponse($cuerpo))->consultaSuscriptor('X');

        $this->assertTrue($respuesta['error']);
        $this->assertSame('Suscriptor no encontrado', $respuesta['mensaje']);
    }

    public function testSuscriptorSinResolucionesNoFalla(): void
    {
        $respuesta = $this->softgic(new MockResponse('{"Suscriptor": {"Documento": "900"}}'))->consultaSuscriptor('X');

        $this->assertFalse($respuesta['error']);
        $this->assertSame(['Documento' => '900'], $respuesta['suscriptor']);
        $this->assertSame([], $respuesta['resoluciones']);
    }

    public function testIdQueViajaEnLaRutaSeCodifica(): void
    {
        $this->softgic(new MockResponse('{"Data": {}}'))->consultaEmpleador('a/b');

        $this->assertSame('https://apps.kiai.co/api/Empleadores/ObtenerPorId/a%2Fb', $this->peticion['url']);
    }
}
