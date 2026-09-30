<?php

namespace App\Tests\Utilidades;

use App\Utilidades\Wolframio;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Comportamiento comun de ClienteApi, probado a traves de Wolframio, que no
 * agrega nada propio.
 */
class ClienteApiTest extends TestCase
{
    private function wolframio(MockResponse|callable $respuesta): Wolframio
    {
        return new Wolframio(new MockHttpClient($respuesta), 'https://wolframio.test/');
    }

    public function testExitoDevuelveLosDatos(): void
    {
        $respuesta = $this->wolframio(new MockResponse('{"cuentas": [1, 2]}'))->consumoPost('api/cuenta/lista');

        $this->assertFalse($respuesta['error']);
        $this->assertSame(200, $respuesta['status']);
        $this->assertSame(['cuentas' => [1, 2]], $respuesta['datos']);
    }

    public function testCuerpoVacioEsDatosVacios(): void
    {
        $respuesta = $this->wolframio(new MockResponse(''))->consumoGet('api/servicio/estado');

        $this->assertFalse($respuesta['error']);
        $this->assertSame([], $respuesta['datos']);
    }

    public function testErrorConMensajeDelServicio(): void
    {
        $respuesta = $this->wolframio(new MockResponse('{"mensaje": "La cuenta no existe"}', ['http_code' => 400]))
            ->consumoPost('api/cuenta/detalle', ['cuentaId' => 9]);

        $this->assertTrue($respuesta['error']);
        $this->assertSame(400, $respuesta['status']);
        $this->assertSame('La cuenta no existe', $respuesta['mensaje']);
    }

    public function testErrorConDetailDeDrf(): void
    {
        $respuesta = $this->wolframio(new MockResponse('{"detail": "No encontrado."}', ['http_code' => 404]))->consumoGet('x');

        $this->assertSame('No encontrado.', $respuesta['mensaje']);
    }

    public function testErrorSinCuerpoUsaElCodigo(): void
    {
        $respuesta = $this->wolframio(new MockResponse('<html>Bad gateway</html>', ['http_code' => 502]))->consumoGet('x');

        $this->assertTrue($respuesta['error']);
        $this->assertSame('Wolframio respondió con el código 502.', $respuesta['mensaje']);
    }

    public function testExitoQueNoEsJsonEsError(): void
    {
        $respuesta = $this->wolframio(new MockResponse('<html>login</html>'))->consumoGet('x');

        $this->assertTrue($respuesta['error']);
        $this->assertSame('Wolframio devolvió una respuesta que no es JSON.', $respuesta['mensaje']);
    }

    public function testFalloDeRedNoLanzaExcepcion(): void
    {
        $respuesta = $this->wolframio(fn () => throw new TransportException('Connection refused'))->consumoGet('x');

        $this->assertTrue($respuesta['error']);
        $this->assertSame(0, $respuesta['status']);
        $this->assertStringContainsString('Connection refused', $respuesta['mensaje']);
    }

    public function testArmaLaUrlYAplicaTiemposLimite(): void
    {
        $peticion = null;
        $cliente = $this->wolframio(function (string $metodo, string $url, array $opciones) use (&$peticion) {
            $peticion = compact('metodo', 'url', 'opciones');

            return new MockResponse('{}');
        });

        $cliente->consumoPost('api/documento/lista', ['limiteRegistros' => 5]);

        $this->assertSame('POST', $peticion['metodo']);
        $this->assertSame('https://wolframio.test/api/documento/lista', $peticion['url']);
        $this->assertSame('{"limiteRegistros":5}', $peticion['opciones']['body']);
        $this->assertEquals(10, $peticion['opciones']['timeout']);
        $this->assertEquals(30, $peticion['opciones']['max_duration']);
    }

    public function testNoEnviaAutorizacion(): void
    {
        $cabeceras = null;
        $cliente = $this->wolframio(function (string $metodo, string $url, array $opciones) use (&$cabeceras) {
            $cabeceras = $opciones['normalized_headers'];

            return new MockResponse('{}');
        });

        $cliente->consumoGet('api/servicio/estado');

        $this->assertArrayNotHasKey('authorization', $cabeceras);
    }
}
