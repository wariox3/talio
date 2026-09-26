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
        $horas = $request->query->getInt('horas', BdLogNginx::PERIODO_DEFECTO);
        if(!isset(BdLogNginx::PERIODOS[$horas])) {
            $horas = BdLogNginx::PERIODO_DEFECTO;
        }
        // Una IP mal escrita no se aplica: se avisa en la vista y se muestra todo.
        $ip = trim((string)$request->query->get('ip', ''));
        $ipInvalida = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false;
        $apiKey = trim((string)$request->query->get('api_key', ''));
        // Solo la ruta: si se pega con parámetros, se ignora lo que va después del "?".
        $ruta = trim(explode('?', (string)$request->query->get('ruta', ''), 2)[0]);
        $filtros = [
            'ip' => ($ip === '' || $ipInvalida) ? null : $ip,
            'api_key' => $apiKey === '' ? null : $apiKey,
            'ruta' => $ruta === '' ? null : $ruta,
        ];
        $apiKeys = [];
        $respuesta = $bdLogNginx->apiKeys($servidor, $horas);
        if(!$respuesta['error']) {
            $apiKeys = $respuesta['datos'];
        }
        // La API key elegida se conserva en la lista aunque no tenga accesos en el periodo.
        if($apiKey !== '' && !in_array($apiKey, $apiKeys, true)) {
            $apiKeys[] = $apiKey;
            sort($apiKeys);
        }
        $respuesta = $bdLogNginx->accesosPorHora($servidor, $horas, $filtros);
        if(!$respuesta['error']) {
            foreach ($respuesta['datos'] as $item) {
                $labels[] = $item['hora'];
                $data[] = (int)$item['cantidad'];
            }
        } else {
            $error = $respuesta['mensaje'];
        }
        $ultimosAccesos = [];
        $respuesta = $bdLogNginx->ultimosAccesos($servidor, 10, $filtros);
        if(!$respuesta['error']) {
            $ultimosAccesos = $respuesta['datos'];
        }
        $accesosPorHost = [];
        $respuesta = $bdLogNginx->accesosPorHost($servidor, $horas, $filtros);
        if(!$respuesta['error']) {
            $accesosPorHost = $respuesta['datos'];
        }
        $accesosPorIp = [];
        $respuesta = $bdLogNginx->accesosPorIp($servidor, $horas, 20, $filtros);
        if(!$respuesta['error']) {
            $accesosPorIp = $respuesta['datos'];
        }
        $accesosPorApiKey = [];
        $respuesta = $bdLogNginx->accesosPorApiKey($servidor, $horas, $filtros);
        if(!$respuesta['error']) {
            $accesosPorApiKey = $respuesta['datos'];
        }
        $accesosPorRuta = [];
        $respuesta = $bdLogNginx->accesosPorRuta($servidor, $horas, 20, $filtros);
        if(!$respuesta['error']) {
            $accesosPorRuta = $respuesta['datos'];
        }
        return $this->render('auditoria/nginx/monitor.html.twig', [
            'error' => $error,
            'servidores' => $servidores,
            'servidor' => $servidor,
            'periodos' => BdLogNginx::PERIODOS,
            'horas' => $horas,
            'ip' => $ip,
            'ipInvalida' => $ipInvalida,
            'apiKey' => $apiKey,
            'apiKeys' => $apiKeys,
            // No se llama 'ruta': base.html.twig usa esa variable para el nombre de la ruta de Symfony.
            'filtroRuta' => $ruta,
            'labels' => $labels,
            'data' => $data,
            'ultimosAccesos' => $ultimosAccesos,
            'accesosPorHost' => $accesosPorHost,
            'accesosPorIp' => $accesosPorIp,
            'accesosPorApiKey' => $accesosPorApiKey,
            'accesosPorRuta' => $accesosPorRuta
        ]);
    }
}
