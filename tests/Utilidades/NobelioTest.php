<?php

namespace App\Tests\Utilidades;

use App\Utilidades\Nobelio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class NobelioTest extends TestCase
{
    private array $peticion = [];

    protected function setUp(): void
    {
        $this->peticion = [];
    }

    private function nobelio(MockResponse|array $respuesta): Nobelio
    {
        $cliente = new MockHttpClient(function (string $metodo, string $url, array $opciones) use ($respuesta) {
            $this->peticion = compact('metodo', 'url', 'opciones');

            return $respuesta;
        });
        if (is_array($respuesta)) {
            $cliente = new MockHttpClient($respuesta);
        }

        return new Nobelio($cliente, 'https://nobelio.test/', 'pre.secreto');
    }

    public function testAgregaBarraFinalYMandaLaApiKey(): void
    {
        $this->nobelio(new MockResponse('{}'))->consumoGet('api/emisores/emisor/5', ['page' => 2]);

        $this->assertSame('https://nobelio.test/api/emisores/emisor/5/?page=2', $this->peticion['url']);
        $this->assertSame(['Authorization: Api-Key pre.secreto'], $this->peticion['opciones']['normalized_headers']['authorization']);
    }

    public function testPostVacioViajaComoObjeto(): void
    {
        $this->nobelio(new MockResponse('{}'))->consumoPost('api/documentos/documento/x/emitir/');

        $this->assertSame('{}', $this->peticion['opciones']['body']);
    }

    public function testLlaveRechazada(): void
    {
        $respuesta = $this->nobelio(new MockResponse('{"detail": "x"}', ['http_code' => 401]))->consumoGet('a');

        $this->assertSame('Nobelio rechazó la API Key de NOBELIO_TOKEN', $respuesta['mensaje']);
    }

    public function testJuntaDetailConLosMensajesDeCampos(): void
    {
        $cuerpo = json_encode([
            'detail' => 'La solicitud no es válida.',
            'errores' => [
                ['codigo' => 'required', 'mensaje' => 'numero: Este campo es obligatorio.'],
                ['codigo' => 'invalid', 'mensaje' => 'fecha: Formato inválido.'],
            ],
        ]);

        $respuesta = $this->nobelio(new MockResponse($cuerpo, ['http_code' => 400]))->consumoPost('a', ['x' => 1]);

        $this->assertSame('La solicitud no es válida. numero: Este campo es obligatorio. | fecha: Formato inválido.', $respuesta['mensaje']);
    }

    public function testNoRepiteElDetail(): void
    {
        $cuerpo = '{"detail": "Ya está aceptado.", "errores": [{"codigo": "negocio", "mensaje": "Ya está aceptado."}]}';

        $respuesta = $this->nobelio(new MockResponse($cuerpo, ['http_code' => 400]))->consumoDelete('a');

        $this->assertSame('Ya está aceptado.', $respuesta['mensaje']);
    }

    public function testRecorreLasPaginas(): void
    {
        $nobelio = $this->nobelio([
            new MockResponse('{"results": [1, 2], "next": "p2"}'),
            new MockResponse('{"results": [3], "next": null}'),
        ]);

        $respuesta = $nobelio->consumoGetTodos('api/documentos/documento-evento/', ['documento' => 'x']);

        $this->assertSame([1, 2, 3], $respuesta['datos']);
    }

    #[DataProvider('ids')]
    public function testEsUuid(string $id, bool $esperado): void
    {
        $this->assertSame($esperado, Nobelio::esUuid($id));
    }

    public static function ids(): array
    {
        return [
            'uuid' => ['3f2b8c1e-9a4d-4e7b-8c2a-1d5e6f7a8b9c', true],
            'mayusculas' => ['3F2B8C1E-9A4D-4E7B-8C2A-1D5E6F7A8B9C', true],
            'vacio' => ['', false],
            'ruta' => ['../../emisores/emisor/5', false],
            'guiones' => ['------------------------------------', false],
        ];
    }
}
