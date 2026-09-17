<?php
namespace App\Controller\servidores;

use App\Utilidades\BdLogNginx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AresController extends AbstractController
{
    #[Route('/servidores/ares/monitor', name: 'servidores_ares_monitor')]
    public function monitor(Request $request, BdLogNginx $bdLogNginx): Response
    {
        $error = null;
        $labels = [];
        $data = [];
        $respuesta = $bdLogNginx->accesosPorHora();
        if(!$respuesta['error']) {
            foreach ($respuesta['datos'] as $item) {
                $labels[] = $item['hora'];
                $data[] = (int)$item['cantidad'];
            }
        } else {
            $error = $respuesta['mensaje'];
        }
        $ultimosAccesos = [];
        $respuesta = $bdLogNginx->ultimosAccesos(10);
        if(!$respuesta['error']) {
            $ultimosAccesos = $respuesta['datos'];
        }
        $accesosPorHost = [];
        $respuesta = $bdLogNginx->accesosPorHost();
        if(!$respuesta['error']) {
            $accesosPorHost = $respuesta['datos'];
        }
        $accesosPorUri = [];
        $respuesta = $bdLogNginx->accesosPorUri(20);
        if(!$respuesta['error']) {
            $accesosPorUri = $respuesta['datos'];
        }
        return $this->render('servidores/ares/monitor.html.twig', [
            'error' => $error,
            'labels' => $labels,
            'data' => $data,
            'ultimosAccesos' => $ultimosAccesos,
            'accesosPorHost' => $accesosPorHost,
            'accesosPorUri' => $accesosPorUri
        ]);
    }
}
