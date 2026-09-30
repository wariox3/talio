<?php
namespace App\Controller\wolframio;

use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MonitorController extends AbstractController
{
    #[Route('/wolframio/monitor', name: 'wolframio_monitor')]
    public function monitor(Wolframio $wolframio): Response
    {
        $fecha = new \DateTime('now');
        $anio = $fecha->format('Y');
        $mes = $fecha->format('m');
        $labels = [];
        $data = [];
        $respuesta = $wolframio->consumoGet('api/documento/tiempo');
        if(!$respuesta['error']) {
            $datos = $respuesta['datos'];
            $arrDocumentos = $datos['documentos'] ?? [];
            foreach ($arrDocumentos as $item) {
                $labels[] = $item['hora'] ?? '';
                $data[] = (int) ($item['cantidad'] ?? 0);
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
        $respuesta = $wolframio->consumoPost("api/documento/estados", []);
        if(!$respuesta['error']) {
            $datos = $respuesta['datos'];
            $arrEstados = $datos['estados'] ?? $arrEstados;
        }
        $respuesta = $wolframio->consumoGet('api/servicio/estado');
        if(!$respuesta['error']) {
            $arrServiciosWolframio = $respuesta['datos'] + $arrServiciosWolframio;
        }
        $ultimosDocumentos = [];
        $datos = [
            'limiteRegistros' => 5
        ];
        $respuesta = $wolframio->consumoPost('api/documento/lista', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $ultimosDocumentos = $arrDatos['documentos'] ?? [];
        }
        $cuentaPeriodo = [];
        $datos = [
            'anio' => $anio,
            'mes' => $mes
        ];
        $respuesta = $wolframio->consumoPost('api/documento/cuenta_periodo', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $cuentaPeriodo = $arrDatos['documentos'] ?? [];
        }
        return $this->render('wolframio/monitor.html.twig', [
            'arrEstados' => $arrEstados,
            'arrServiciosWolframio' => $arrServiciosWolframio,
            'labels' => $labels,
            'data' => $data,
            'ultimosDocumentos' => $ultimosDocumentos,
            'cuentaPeriodo' => $cuentaPeriodo
        ]);
    }
}
