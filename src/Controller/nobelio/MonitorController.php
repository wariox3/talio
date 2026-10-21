<?php
namespace App\Controller\nobelio;

use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MonitorController extends AbstractController
{
    // Nobelio pagina de 10 en 10 y no tiene filtro por fecha, asi que las
    // ultimas 24 h se sacan recorriendo el listado hacia atras. El tope evita
    // que un dia de mucho volumen deje la pagina pidiendo sin fin: si se
    // alcanza, la grafica se marca como parcial.
    private const MAX_PAGINAS = 30;

    // "Ultimos documentos" muestra solo los de produccion, y Nobelio no filtra
    // por ambiente: se sacan de las paginas que ya se piden para la grafica y,
    // si no alcanzan, se siguen pidiendo hasta este tope adicional (en una
    // instalacion casi toda en habilitacion se quedaria pidiendo sin fin).
    private const ULTIMOS = 10;
    private const MAX_PAGINAS_ULTIMOS = 10;
    private const AMBIENTE_PRODUCCION = 1;

    // fecha_emision y hora_emision son la hora local de Nobelio (TIME_ZONE de
    // Django), no UTC. Se fija aqui para no depender del php.ini.
    private const ZONA = 'America/Bogota';

    #[Route('/nobelio/monitor', name: 'nobelio_monitor')]
    public function monitor(Nobelio $nobelio): Response
    {
        [$rechazados, $pendientes, $sinRespuestaValidado] = $this->resumen($nobelio, 'api/documentos/documento/resumen-monitor/');
        [$nominasRechazadas, $nominasPendientes] = $this->resumen($nobelio, 'api/nomina/nomina/resumen-monitor/');

        $softwareSinSet = $this->contar($nobelio, 'api/emisores/software/', ['set_pruebas_aceptado' => 'false']);

        // 24 franjas de una hora, de la de hace 23 h a la actual.
        $zona = new \DateTimeZone(self::ZONA);
        $horaActual = new \DateTimeImmutable('now', $zona);
        $horaActual = $horaActual->setTime((int) $horaActual->format('H'), 0);
        $inicio = $horaActual->modify('-23 hours');
        $franjas = [];
        for ($i = 0; $i < 24; $i++) {
            $franjas[$inicio->modify("+{$i} hours")->format('Y-m-d H')] = 0;
        }

        $ultimosDocumentos = [];
        $totalEmitidos = 0;
        $parcial = false;
        $errorGrafica = false;
        $hayMasPaginas = false;

        for ($pagina = 1; $pagina <= self::MAX_PAGINAS; $pagina++) {
            $respuesta = $nobelio->consumoGet('api/documentos/documento/', [
                'page' => $pagina,
                'ordering' => '-fecha_emision,-hora_emision',
            ]);
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
                $errorGrafica = true;
                break;
            }

            $documentos = $respuesta['datos']['results'] ?? [];
            $hayMasPaginas = !empty($respuesta['datos']['next']);
            $ultimosDocumentos = $this->agregarDeProduccion($ultimosDocumentos, $documentos);

            $fueraDeRango = false;
            foreach ($documentos as $documento) {
                $emision = $this->emision($documento, $zona);
                if ($emision === null) {
                    continue;
                }
                if ($emision < $inicio) {
                    // El orden es descendente: de aqui en adelante todo es
                    // anterior a la ventana.
                    $fueraDeRango = true;
                    break;
                }
                // Un borrador no se ha emitido: su fecha es la de creacion.
                if (($documento['estado_nombre'] ?? '') === 'borrador') {
                    continue;
                }
                $clave = $emision->format('Y-m-d H');
                if (isset($franjas[$clave])) {
                    $franjas[$clave]++;
                    $totalEmitidos++;
                }
            }

            if ($fueraDeRango || !$hayMasPaginas) {
                break;
            }
            if ($pagina === self::MAX_PAGINAS) {
                $parcial = true;
            }
        }

        // Si la ventana de la grafica no trajo 10 de produccion, se siguen las
        // paginas solo para la tabla.
        $tope = $pagina + self::MAX_PAGINAS_ULTIMOS;
        while (!$errorGrafica && $hayMasPaginas && count($ultimosDocumentos) < self::ULTIMOS && ++$pagina <= $tope) {
            $respuesta = $nobelio->consumoGet('api/documentos/documento/', [
                'page' => $pagina,
                'ordering' => '-fecha_emision,-hora_emision',
            ]);
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
                break;
            }
            $hayMasPaginas = !empty($respuesta['datos']['next']);
            $ultimosDocumentos = $this->agregarDeProduccion($ultimosDocumentos, $respuesta['datos']['results'] ?? []);
        }

        $labels = array_map(fn ($clave) => substr($clave, -2), array_keys($franjas));

        return $this->render('nobelio/monitor.html.twig', [
            'rechazados' => $rechazados,
            'pendientes' => $pendientes,
            'sinRespuestaValidado' => $sinRespuestaValidado,
            'nominasRechazadas' => $nominasRechazadas,
            'nominasPendientes' => $nominasPendientes,
            'softwareSinSet' => $softwareSinSet,
            'labels' => $labels,
            'data' => array_values($franjas),
            'totalEmitidos' => $totalEmitidos,
            'parcial' => $parcial,
            'errorGrafica' => $errorGrafica,
            'maxDocumentos' => self::MAX_PAGINAS * 10,
            'ultimosDocumentos' => $ultimosDocumentos,
        ]);
    }

    /**
     * Los conteos de las tarjetas en una sola llamada al resumen-monitor de
     * documentos o de nomina: [rechazados, pendientes por estado, aceptados
     * sin respuesta validado]. Si Nobelio no responde los tres van en null.
     */
    private function resumen(Nobelio $nobelio, string $url): array
    {
        $respuesta = $nobelio->consumoGet($url);
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            return [null, null, null];
        }

        $datos = $respuesta['datos'];

        return [
            (int) ($datos['rechazado'] ?? 0),
            [
                'borrador' => (int) ($datos['borrador'] ?? 0),
                'firmado' => (int) ($datos['firmado'] ?? 0),
                'enviado' => (int) ($datos['enviado'] ?? 0),
            ],
            (int) ($datos['aceptado_sin_respuesta_validado'] ?? 0),
        ];
    }

    /**
     * El count de un listado filtrado, o null si Nobelio no respondio.
     */
    private function contar(Nobelio $nobelio, string $url, array $filtros): ?int
    {
        $respuesta = $nobelio->consumoGet($url, $filtros);
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            return null;
        }

        return (int) ($respuesta['datos']['count'] ?? 0);
    }

    /**
     * Suma a $ultimos los documentos de produccion de $documentos, hasta
     * completar ULTIMOS. El ambiente llega como entero (1 produccion,
     * 2 habilitacion).
     */
    private function agregarDeProduccion(array $ultimos, array $documentos): array
    {
        foreach ($documentos as $documento) {
            if (count($ultimos) >= self::ULTIMOS) {
                break;
            }
            if ((int) ($documento['ambiente'] ?? 0) === self::AMBIENTE_PRODUCCION) {
                $ultimos[] = $documento;
            }
        }

        return $ultimos;
    }

    private function emision(array $documento, \DateTimeZone $zona): ?\DateTimeImmutable
    {
        $fecha = (string) ($documento['fecha_emision'] ?? '');
        $hora = substr((string) ($documento['hora_emision'] ?? '00:00:00'), 0, 8);
        $emision = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', "{$fecha} {$hora}", $zona)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$fecha} {$hora}", $zona);

        return $emision ?: null;
    }
}
