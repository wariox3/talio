<?php

namespace App\Tests\Utilidades;

use App\Utilidades\Itrio;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

class ItrioTest extends TestCase
{
    private Session $sesion;

    /** @var list<array{metodo: string, url: string, autorizacion: ?string}> */
    private array $peticiones = [];

    protected function setUp(): void
    {
        $this->sesion = new Session(new MockArraySessionStorage());
        $this->peticiones = [];
    }

    /**
     * @param list<MockResponse> $respuestas en el orden en que se piden
     */
    private function itrio(array $respuestas): Itrio
    {
        $cliente = new MockHttpClient(function (string $metodo, string $url, array $opciones) use (&$respuestas) {
            $this->peticiones[] = [
                'metodo' => $metodo,
                'url' => $url,
                'autorizacion' => $opciones['normalized_headers']['authorization'][0] ?? null,
            ];

            return array_shift($respuestas);
        });

        $request = new Request();
        $request->setSession($this->sesion);
        $pila = new RequestStack();
        $pila->push($request);

        return new Itrio($cliente, $pila, 'https://itrio.test/', 'usuario', 'clave');
    }

    public function testPideTokenLaPrimeraVezYLoGuarda(): void
    {
        $itrio = $this->itrio([
            new MockResponse('{"token": "t1"}'),
            new MockResponse('{"results": []}'),
        ]);

        $respuesta = $itrio->consumoGet('contenedor/contenedor/');

        $this->assertFalse($respuesta['error']);
        $this->assertSame('https://itrio.test/seguridad/login/', $this->peticiones[0]['url']);
        $this->assertSame('Authorization: Bearer t1', $this->peticiones[1]['autorizacion']);
        $this->assertSame('t1', $this->sesion->get('itrio_token'));
    }

    public function testReusaElTokenDeLaSesion(): void
    {
        $this->sesion->set('itrio_token', 'guardado');
        $itrio = $this->itrio([new MockResponse('{}')]);

        $itrio->consumoPost('contenedor/consumo/resumen/', ['fecha_desde' => '2026-01-01']);

        $this->assertCount(1, $this->peticiones);
        $this->assertSame('Authorization: Bearer guardado', $this->peticiones[0]['autorizacion']);
    }

    public function testRenuevaElTokenCaducadoYReintentaUnaVez(): void
    {
        $this->sesion->set('itrio_token', 'caducado');
        $itrio = $this->itrio([
            new MockResponse('{"detail": "Token expirado"}', ['http_code' => 401]),
            new MockResponse('{"token": "nuevo"}'),
            new MockResponse('{"id": 7}'),
        ]);

        $respuesta = $itrio->consumoPatch('contenedor/movimiento/7/', ['factura_id' => 3]);

        $this->assertFalse($respuesta['error']);
        $this->assertSame(['id' => 7], $respuesta['datos']);
        $this->assertCount(3, $this->peticiones);
        $this->assertSame('Authorization: Bearer nuevo', $this->peticiones[2]['autorizacion']);
        $this->assertSame('nuevo', $this->sesion->get('itrio_token'));
    }

    public function testNoReintentaMasDeUnaVez(): void
    {
        $this->sesion->set('itrio_token', 'caducado');
        $itrio = $this->itrio([
            new MockResponse('{}', ['http_code' => 401]),
            new MockResponse('{"token": "nuevo"}'),
            new MockResponse('{"detail": "Sin permiso"}', ['http_code' => 401]),
        ]);

        $respuesta = $itrio->consumoGet('seguridad/usuario/');

        $this->assertTrue($respuesta['error']);
        $this->assertSame('Sin permiso', $respuesta['mensaje']);
        $this->assertCount(3, $this->peticiones);
    }

    public function testFalloAlAutenticar(): void
    {
        $itrio = $this->itrio([new MockResponse('{"mensaje": "Credenciales inválidas"}', ['http_code' => 400])]);

        $respuesta = $itrio->consumoGet('seguridad/usuario/');

        $this->assertTrue($respuesta['error']);
        $this->assertSame('Error en la autenticación con Itrio: Credenciales inválidas', $respuesta['mensaje']);
        $this->assertNull($this->sesion->get('itrio_token'));
    }

    public function testArchivoDevuelveElContenidoCrudo(): void
    {
        $this->sesion->set('itrio_token', 't');
        $itrio = $this->itrio([new MockResponse('PK...xlsx', ['response_headers' => [
            'content-type' => 'application/vnd.ms-excel',
            'content-disposition' => 'attachment; filename="movimientos.xlsx"',
        ]])]);

        $respuesta = $itrio->consumoArchivo('contenedor/movimiento/?excel=true');

        $this->assertFalse($respuesta['error']);
        $this->assertSame('PK...xlsx', $respuesta['contenido']);
        $this->assertSame('application/vnd.ms-excel', $respuesta['tipo']);
        $this->assertSame('movimientos.xlsx', $respuesta['nombre']);
    }
}
