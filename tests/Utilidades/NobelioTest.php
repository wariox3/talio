<?php

namespace App\Tests\Utilidades;

use App\Utilidades\Nobelio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class NobelioTest extends TestCase
{
    private array $peticion = [];

    private Session $sesion;

    protected function setUp(): void
    {
        $this->sesion = new Session(new MockArraySessionStorage());
        $this->peticion = [];
    }

    /**
     * Nobelio con produccion y prueba configurados; desarrollo sin llave.
     * $llave reemplaza la de produccion ('' la deja sin configurar).
     */
    private function nobelio(MockResponse|array $respuesta, string $llave = 'pre.secreto'): Nobelio
    {
        $cliente = new MockHttpClient(function (string $metodo, string $url, array $opciones) use ($respuesta) {
            $this->peticion = compact('metodo', 'url', 'opciones');

            return $respuesta;
        });
        if (is_array($respuesta)) {
            $cliente = new MockHttpClient($respuesta);
        }

        $request = new Request();
        $request->setSession($this->sesion);
        $pila = new RequestStack();
        $pila->push($request);

        return new Nobelio(
            $cliente, $pila,
            'https://nobelio.test/', $llave,
            'https://prueba.nobelio.test/', 'pru.secreto',
            'https://desarrollo.nobelio.test/', null,
        );
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

    public function testPorDefectoUsaProduccion(): void
    {
        $nobelio = $this->nobelio(new MockResponse('{}'));

        $this->assertSame('produccion', $nobelio->ambiente());
        $this->assertSame(['produccion', 'prueba'], $nobelio->ambientesDisponibles());
    }

    public function testElAmbienteElegidoCambiaUrlYLlave(): void
    {
        $nobelio = $this->nobelio(new MockResponse('{}'));

        $this->assertTrue($nobelio->cambiarAmbiente('prueba'));
        $nobelio->consumoGet('api/emisores/emisor/');

        $this->assertSame('prueba', $this->sesion->get('nobelio_ambiente'));
        $this->assertSame('https://prueba.nobelio.test/api/emisores/emisor/', $this->peticion['url']);
        $this->assertSame(['Authorization: Api-Key pru.secreto'], $this->peticion['opciones']['normalized_headers']['authorization']);
    }

    public function testNoSePuedeElegirUnAmbienteSinConfigurar(): void
    {
        $nobelio = $this->nobelio(new MockResponse('{}'));

        $this->assertFalse($nobelio->cambiarAmbiente('desarrollo'));
        $this->assertFalse($nobelio->cambiarAmbiente('otro'));
        $this->assertSame('produccion', $nobelio->ambiente());
    }

    public function testSiElAmbienteDeLaSesionDejaDeEstarConfiguradoUsaElPrimero(): void
    {
        $this->sesion->set('nobelio_ambiente', 'desarrollo');

        $this->assertSame('produccion', $this->nobelio(new MockResponse('{}'))->ambiente());
    }

    public function testSinNingunAmbienteNoHacePeticion(): void
    {
        $nobelio = new Nobelio(new MockHttpClient(function () {
            $this->fail('No debia hacer ninguna peticion');
        }), new RequestStack(), null, null, null, null, null, null);

        $respuesta = $nobelio->consumoGet('api/emisores/emisor/');

        $this->assertTrue($respuesta['error']);
        $this->assertStringContainsString('No hay ningún ambiente de Nobelio configurado', $respuesta['mensaje']);
        $this->assertSame('Sin configurar', $nobelio->nombreAmbiente());
    }

    public function testLlaveRechazada(): void
    {
        $respuesta = $this->nobelio(new MockResponse('{"detail": "x"}', ['http_code' => 401]))->consumoGet('a');

        $this->assertSame('Nobelio (Producción) rechazó la API Key de NOBELIO_TOKEN_PRODUCCION', $respuesta['mensaje']);
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
