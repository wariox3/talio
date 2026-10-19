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

    // fecha_emision y hora_emision son la hora local de Nobelio (TIME_ZONE de
    // Django), no UTC. Se fija aqui para no depender del php.ini.
    private const ZONA = 'America/Bogota';

    #[Route('/nobelio/monitor', name: 'nobelio_monitor')]
    public function monitor(Nobelio $nobelio): Response
    {
        // Rechazados: basta el count del listado filtrado.
        $rechazados = null;
        $respuesta = $nobelio->consumoGet('api/documentos/documento/', ['estado' => 'rechazado']);
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
        } else {
            $rechazados = (int) ($respuesta['datos']['count'] ?? 0);
        }

        // Pendientes: lo que aun no tiene veredicto de la DIAN. El filtro de
        // Nobelio admite un solo estado, asi que va un count por cada uno. Si
        // alguno falla la tarjeta queda sin dato: un total parcial engañaria.
        $pendientes = ['borrador' => 0, 'firmado' => 0, 'enviado' => 0];
        foreach (array_keys($pendientes) as $estado) {
            $respuesta = $nobelio->consumoGet('api/documentos/documento/', ['estado' => $estado]);
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
                $pendientes = null;
                break;
            }
            $pendientes[$estado] = (int) ($respuesta['datos']['count'] ?? 0);
        }

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
            // La primera pagina son justo los 10 mas recientes.
            if ($pagina === 1) {
                $ultimosDocumentos = $documentos;
            }

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

            if ($fueraDeRango || empty($respuesta['datos']['next'])) {
                break;
            }
            if ($pagina === self::MAX_PAGINAS) {
                $parcial = true;
            }
        }

        $labels = array_map(fn ($clave) => substr($clave, -2), array_keys($franjas));

        return $this->render('nobelio/monitor.html.twig', [
            'rechazados' => $rechazados,
            'pendientes' => $pendientes,
            'labels' => $labels,
            'data' => array_values($franjas),
            'totalEmitidos' => $totalEmitidos,
            'parcial' => $parcial,
            'errorGrafica' => $errorGrafica,
            'maxDocumentos' => self::MAX_PAGINAS * 10,
            'ultimosDocumentos' => $ultimosDocumentos,
        ]);
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
