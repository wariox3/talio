<?php
namespace App\Controller\auditoria;

use App\Utilidades\BdLogNginx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NginxController extends AbstractController
{
    private const SERVIDOR_DEFECTO = 'ares';

    #[Route('/auditoria/nginx/monitor', name: 'auditoria_nginx_monitor')]
    public function monitor(Request $request, BdLogNginx $bdLogNginx): Response
    {
        $error = null;
        $labels = [];
        $data = [];
        // Solo se aceptan servidores que existan en la tabla; lo demás vuelve al de defecto.
        $servidores = [self::SERVIDOR_DEFECTO];
        $respuesta = $bdLogNginx->servidores();
        if(!$respuesta['error']) {
            $servidores = array_values(array_unique([self::SERVIDOR_DEFECTO, ...$respuesta['datos']]));
            sort($servidores);
        }
        $servidor = (string)$request->query->get('servidor', self::SERVIDOR_DEFECTO);
        if(!in_array($servidor, $servidores, true)) {
            $servidor = self::SERVIDOR_DEFECTO;
        }
        $respuesta = $bdLogNginx->accesosPorHora($servidor);
        if(!$respuesta['error']) {
            foreach ($respuesta['datos'] as $item) {
                $labels[] = $item['hora'];
                $data[] = (int)$item['cantidad'];
            }
        } else {
            $error = $respuesta['mensaje'];
        }
        $ultimosAccesos = [];
        $respuesta = $bdLogNginx->ultimosAccesos($servidor, 10);
        if(!$respuesta['error']) {
            $ultimosAccesos = $respuesta['datos'];
        }
        $accesosPorHost = [];
        $respuesta = $bdLogNginx->accesosPorHost($servidor);
        if(!$respuesta['error']) {
            $accesosPorHost = $respuesta['datos'];
        }
        $accesosPorUri = [];
        $respuesta = $bdLogNginx->accesosPorUri($servidor, 20);
        if(!$respuesta['error']) {
            $accesosPorUri = $respuesta['datos'];
        }
        return $this->render('auditoria/nginx/monitor.html.twig', [
            'error' => $error,
            'servidores' => $servidores,
            'servidor' => $servidor,
            'labels' => $labels,
            'data' => $data,
            'ultimosAccesos' => $ultimosAccesos,
            'accesosPorHost' => $accesosPorHost,
            'accesosPorUri' => $accesosPorUri
        ]);
    }
}
