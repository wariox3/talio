<?php
namespace App\Controller;

use App\Utilidades\Carbono;
use App\Utilidades\Niquel;
use App\Utilidades\Tantalo;
use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class InicioController extends AbstractController
{

    #[Route('/', name: 'inicio')]
    public function inicio(Request $request, Wolframio $wolframio, Niquel $niquel, Tantalo $tantalo, Carbono $carbono): Response
    {
        #https://www.chartjs.org/chartjs-plugin-zoom/latest/samples/fetch-data.html
        $fecha = new \DateTime('now');
        $anio = $fecha->format('Y');
        $mes = $fecha->format('m');
        $labels = [];
        $data = [];
        $respuesta = $wolframio->consumoGet('api/documento/tiempo');
        if(!$respuesta['error']) {
            $datos = $respuesta['datos'];
            $arrDocumentos = $datos['documentos'];
            foreach ($arrDocumentos as $item) {
                $labels[] = $item['hora'];
                $data[] = $item['cantidad'];
            }
        }

        $arrEstados = [
            "enviar" => 0,
            "error" => 0,
            "respuesta" => 0
        ];
        $arrServiciosWolframio = [
            "colaEmitir" => false,
            "colaRespuesta" => false
        ];
        $arrServiciosTantalo = [
            "colaDecodificar" => false
        ];
        $respuesta = $wolframio->consumoPost("api/documento/estados", []);
        if(!$respuesta['error']) {
            $datos = $respuesta['datos'];
            $arrEstados = $datos['estados'];
        }
        $respuesta = $wolframio->consumoGet('api/servicio/estado');
        if(!$respuesta['error']) {
            $arrServiciosWolframio = $respuesta['datos'];
        }
        $respuesta = $tantalo->consumoGet('api/servicio/estado');
        if(!$respuesta['error']) {
            $arrServiciosTantalo = $respuesta['datos'];
        }
        $ultimosDocumentos = [];
        $datos = [
            'limiteRegistros' => 5
        ];
        $respuesta = $wolframio->consumoPost('api/documento/lista', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $ultimosDocumentos = $arrDatos['documentos'];
        }
        $cuentaPeriodo = [];
        $datos = [
            'anio' => $anio,
            'mes' => $mes
        ];
        $respuesta = $wolframio->consumoPost('api/documento/cuenta_periodo', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $cuentaPeriodo = $arrDatos['documentos'];
        }
        $resumenErrores = [
            'prod' => 0
        ];
        $respuesta = $niquel->consumoPost('api/error/resumen', []);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $resumenErrores = $arrDatos['resumen'];
        }
        $negocios = [];
        $respuesta = $carbono->consumoPost('api/negocio/pendiente', []);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $negocios = $arrDatos['negocios'];
        }
        $contactos = [];
        $respuesta = $carbono->consumoPost('api/contacto/pendiente', []);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $contactos = $arrDatos['contactos'];
        }
        return $this->render('inicio.html.twig', [
            'arrEstados' => $arrEstados,
            'arrServiciosWolframio' => $arrServiciosWolframio,
            'arrServiciosTantalo' => $arrServiciosTantalo,
            'arrErrores' => $resumenErrores,
            'labels' => $labels,
            'data' => $data,
            'ultimosDocumentos' => $ultimosDocumentos,
            'cuentaPeriodo' => $cuentaPeriodo,
            'negocios' => $negocios,
            'contactos' => $contactos
        ]);
    }
}