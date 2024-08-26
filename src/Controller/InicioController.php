<?php
namespace App\Controller;

use App\Utilidades\Niquel;
use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class InicioController extends AbstractController
{

    #[Route('/', name: 'inicio')]
    public function inicio(Request $request, Wolframio $wolframio, Niquel $niquel): Response
    {
        #https://www.chartjs.org/chartjs-plugin-zoom/latest/samples/fetch-data.html
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
        $arrServicios = [
            "colaEmitir" => false,
            "colaRespuesta" => false
        ];
        $respuesta = $wolframio->consumoPost("api/documento/estados", []);
        if(!$respuesta['error']) {
            $datos = $respuesta['datos'];
            $arrEstados = $datos['estados'];
        }
        $respuesta = $wolframio->consumoGet('api/servicio/estado');
        if(!$respuesta['error']) {
            $arrServicios = $respuesta['datos'];
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
        $resumenErrores = [
            'prod' => 0
        ];
        $respuesta = $niquel->consumoPost('api/error/resumen', []);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $resumenErrores = $arrDatos['resumen'];
        }
        return $this->render('inicio.html.twig', [
            'arrEstados' => $arrEstados,
            'arrServicios' => $arrServicios,
            'arrErrores' => $resumenErrores,
            'labels' => $labels,
            'data' => $data,
            'ultimosDocumentos' => $ultimosDocumentos
        ]);
    }
}