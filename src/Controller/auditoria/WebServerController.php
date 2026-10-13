<?php
namespace App\Controller\auditoria;

use App\Utilidades\BdWebServer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WebServerController extends AbstractController
{
    private const SERVIDOR_DEFECTO = 'ares';
    // Servidores que siempre salen en la lista, aunque todavía no tengan accesos ni errores registrados.
    private const SERVIDORES = [self::SERVIDOR_DEFECTO, 'hebe', 'jardin'];

    #[Route('/auditoria/webserver/monitor-acceso', name: 'auditoria_webserver_monitor_acceso')]
    public function monitorAcceso(Request $request, BdWebServer $bdWebServer): Response
    {
        $error = null;
        $labels = [];
        $data = [];
        [$servidores, $servidor, $horas] = $this->servidorYPeriodo($request, $bdWebServer);
        // Solo se acepta un servidor web conocido (nginx, apache); lo demás muestra todos.
        $origen = (string)$request->query->get('origen', '');
        if(!isset(BdWebServer::ORIGENES[$origen])) {
            $origen = '';
        }
        // Una IP mal escrita no se aplica: se avisa en la vista y se muestra todo.
        $ip = trim((string)$request->query->get('ip', ''));
        $ipInvalida = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false;
        $apiKey = trim((string)$request->query->get('api_key', ''));
        // Solo la ruta: si se pega con parámetros, se ignora lo que va después del "?".
        $ruta = trim(explode('?', (string)$request->query->get('ruta', ''), 2)[0]);
        $host = trim((string)$request->query->get('host', ''));
        // Solo se acepta un grupo conocido (4xx, errores...) o un código de tres cifras.
        $status = trim((string)$request->query->get('status', ''));
        if(!isset(BdWebServer::GRUPOS_STATUS[$status]) && !BdWebServer::esCodigoStatus($status)) {
            $status = '';
        }
        $filtros = [
            'origen' => $origen === '' ? null : $origen,
            'ip' => ($ip === '' || $ipInvalida) ? null : $ip,
            'api_key' => $apiKey === '' ? null : $apiKey,
            'ruta' => $ruta === '' ? null : $ruta,
            'host' => $host === '' ? null : $host,
            'status' => $status === '' ? null : $status,
        ];
        $apiKeys = [];
        $respuesta = $bdWebServer->apiKeys($servidor, $horas);
        if(!$respuesta['error']) {
            $apiKeys = $respuesta['datos'];
        }
        // La API key elegida se conserva en la lista aunque no tenga accesos en el periodo.
        if($apiKey !== '' && !in_array($apiKey, $apiKeys, true)) {
            $apiKeys[] = $apiKey;
            sort($apiKeys);
        }
        $hosts = [];
        $respuesta = $bdWebServer->hosts($servidor, $horas);
        if(!$respuesta['error']) {
            $hosts = $respuesta['datos'];
        }
        // El host elegido se conserva en la lista aunque no tenga accesos en el periodo.
        if($host !== '' && !in_array($host, $hosts, true)) {
            $hosts[] = $host;
            sort($hosts);
        }
        $statuses = [];
        $respuesta = $bdWebServer->statuses($servidor, $horas);
        if(!$respuesta['error']) {
            $statuses = $respuesta['datos'];
        }
        // El código elegido se conserva en la lista aunque no tenga accesos en el periodo.
        if(BdWebServer::esCodigoStatus($status) && !in_array($status, $statuses, true)) {
            $statuses[] = $status;
            sort($statuses);
        }
        $respuesta = $bdWebServer->accesosPorHora($servidor, $horas, $filtros);
        if(!$respuesta['error']) {
            foreach ($respuesta['datos'] as $item) {
                $labels[] = $item['hora'];
                $data[] = (int)$item['cantidad'];
            }
        } else {
            $error = $respuesta['mensaje'];
        }
        $ultimosAccesos = [];
        $respuesta = $bdWebServer->ultimosAccesos($servidor, $horas, 10, $filtros);
        if(!$respuesta['error']) {
            $ultimosAccesos = $respuesta['datos'];
        }
        $accesosPorHost = [];
        $respuesta = $bdWebServer->accesosPorHost($servidor, $horas, $filtros);
        if(!$respuesta['error']) {
            $accesosPorHost = $respuesta['datos'];
        }
        $accesosPorIp = [];
        $respuesta = $bdWebServer->accesosPorIp($servidor, $horas, 20, $filtros);
        if(!$respuesta['error']) {
            $accesosPorIp = $respuesta['datos'];
        }
        $accesosPorApiKey = [];
        $respuesta = $bdWebServer->accesosPorApiKey($servidor, $horas, $filtros);
        if(!$respuesta['error']) {
            $accesosPorApiKey = $respuesta['datos'];
        }
        $accesosPorRuta = [];
        $respuesta = $bdWebServer->accesosPorRuta($servidor, $horas, 20, $filtros);
        if(!$respuesta['error']) {
            $accesosPorRuta = $respuesta['datos'];
        }
        return $this->render('auditoria/webserver/monitor_acceso.html.twig', [
            'error' => $error,
            'servidores' => $servidores,
            'servidor' => $servidor,
            'periodos' => BdWebServer::PERIODOS,
            'horas' => $horas,
            'origenes' => BdWebServer::ORIGENES,
            'origen' => $origen,
            'ip' => $ip,
            'ipInvalida' => $ipInvalida,
            'apiKey' => $apiKey,
            'apiKeys' => $apiKeys,
            'host' => $host,
            'hosts' => $hosts,
            'status' => $status,
            'statuses' => $statuses,
            'gruposStatus' => BdWebServer::GRUPOS_STATUS,
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

    /**
     * Monitor de los error.log: cuántos errores hay, de qué nivel, en qué módulo y sitio, cuáles
     * se repiten más y desde qué IP llegan. Sin elegir nivel cuenta las líneas de todos los
     * niveles; el filtro deja quedarse solo con los errores o con errores y avisos.
     */
    #[Route('/auditoria/webserver/monitor-error', name: 'auditoria_webserver_monitor_error')]
    public function monitorError(Request $request, BdWebServer $bdWebServer): Response
    {
        $error = null;
        $labels = [];
        $data = [];
        [$servidores, $servidor, $horas] = $this->servidorYPeriodo($request, $bdWebServer);
        // Solo se acepta un servidor web conocido (nginx, apache); lo demás muestra todos.
        $origen = (string)$request->query->get('origen', '');
        if(!isset(BdWebServer::ORIGENES[$origen])) {
            $origen = '';
        }
        // Solo se acepta un grupo conocido o un nivel exacto; lo demás vuelve al de defecto.
        $nivel = (string)$request->query->get('nivel', BdWebServer::NIVEL_DEFECTO);
        if(!isset(BdWebServer::GRUPOS_NIVEL[$nivel]) && !isset(BdWebServer::NIVELES[$nivel])) {
            $nivel = BdWebServer::NIVEL_DEFECTO;
        }
        // Una IP mal escrita no se aplica: se avisa en la vista y se muestra todo.
        $ip = trim((string)$request->query->get('ip', ''));
        $ipInvalida = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false;
        $modulo = trim((string)$request->query->get('modulo', ''));
        $sitio = trim((string)$request->query->get('sitio', ''));
        $texto = trim((string)$request->query->get('texto', ''));
        $filtros = [
            'origen' => $origen === '' ? null : $origen,
            'nivel' => $nivel,
            'modulo' => $modulo === '' ? null : $modulo,
            'sitio' => $sitio === '' ? null : $sitio,
            'ip' => ($ip === '' || $ipInvalida) ? null : $ip,
            'texto' => $texto === '' ? null : $texto,
        ];
        $modulos = [];
        $respuesta = $bdWebServer->modulosError($servidor, $horas);
        if(!$respuesta['error']) {
            $modulos = $respuesta['datos'];
        }
        // El módulo elegido se conserva en la lista aunque no tenga errores en el periodo.
        if($modulo !== '' && !in_array($modulo, $modulos, true)) {
            $modulos[] = $modulo;
            sort($modulos);
        }
        $sitios = [];
        $respuesta = $bdWebServer->sitiosError($servidor, $horas);
        if(!$respuesta['error']) {
            $sitios = $respuesta['datos'];
        }
        // El sitio elegido se conserva en la lista aunque no tenga errores en el periodo.
        if($sitio !== '' && !in_array($sitio, $sitios, true)) {
            $sitios[] = $sitio;
            sort($sitios);
        }
        $respuesta = $bdWebServer->erroresPorHora($servidor, $horas, $filtros);
        if(!$respuesta['error']) {
            foreach ($respuesta['datos'] as $item) {
                $labels[] = $item['hora'];
                $data[] = (int)$item['cantidad'];
            }
        } else {
            $error = $respuesta['mensaje'];
        }
        $consultas = [
            'erroresPorNivel' => fn () => $bdWebServer->erroresPorNivel($servidor, $horas, $filtros),
            'erroresPorModulo' => fn () => $bdWebServer->erroresPorModulo($servidor, $horas, $filtros),
            'erroresPorSitio' => fn () => $bdWebServer->erroresPorSitio($servidor, $horas, 20, $filtros),
            'erroresPorIp' => fn () => $bdWebServer->erroresPorIp($servidor, $horas, 20, $filtros),
            'erroresPorMensaje' => fn () => $bdWebServer->erroresPorMensaje($servidor, $horas, 20, $filtros),
            'ultimosErrores' => fn () => $bdWebServer->ultimosErrores($servidor, $horas, 20, $filtros),
        ];
        $tablas = [];
        foreach ($consultas as $nombre => $consulta) {
            // Si la gráfica ya falló no hay conexión: no se insiste con las demás.
            $respuesta = $error === null ? $consulta() : ['error' => true];
            $tablas[$nombre] = $respuesta['error'] ? [] : $respuesta['datos'];
        }
        return $this->render('auditoria/webserver/monitor_error.html.twig', [
            'error' => $error,
            'servidores' => $servidores,
            'servidor' => $servidor,
            'periodos' => BdWebServer::PERIODOS,
            'horas' => $horas,
            'origenes' => BdWebServer::ORIGENES,
            'origen' => $origen,
            'niveles' => BdWebServer::NIVELES,
            'gruposNivel' => BdWebServer::GRUPOS_NIVEL,
            'nivel' => $nivel,
            'nivelDefecto' => BdWebServer::NIVEL_DEFECTO,
            'modulo' => $modulo,
            'modulos' => $modulos,
            'sitio' => $sitio,
            'sitios' => $sitios,
            'ip' => $ip,
            'ipInvalida' => $ipInvalida,
            'texto' => $texto,
            'labels' => $labels,
            'data' => $data,
            ...$tablas,
        ]);
    }

    /**
     * Servidores que se pueden elegir, y el servidor y el periodo pedidos en la URL.
     *
     * @return array{0: string[], 1: string, 2: int}
     */
    private function servidorYPeriodo(Request $request, BdWebServer $bdWebServer): array
    {
        // Solo se aceptan los servidores conocidos o que existan en las tablas; lo demás vuelve al de defecto.
        $servidores = self::SERVIDORES;
        $respuesta = $bdWebServer->servidores();
        if(!$respuesta['error']) {
            $servidores = array_values(array_unique([...self::SERVIDORES, ...$respuesta['datos']]));
        }
        sort($servidores);
        $servidor = (string)$request->query->get('servidor', self::SERVIDOR_DEFECTO);
        if(!in_array($servidor, $servidores, true)) {
            $servidor = self::SERVIDOR_DEFECTO;
        }
        $horas = $request->query->getInt('horas', BdWebServer::PERIODO_DEFECTO);
        if(!isset(BdWebServer::PERIODOS[$horas])) {
            $horas = BdWebServer::PERIODO_DEFECTO;
        }
        return [$servidores, $servidor, $horas];
    }
}
