<?php
namespace App\Controller\itrio;

use App\Utilidades\Excel;
use App\Utilidades\Itrio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ConsumoController extends AbstractController
{
    /** Columna del Excel => campo del consumo en Itrio. */
    private const COLUMNAS_EXCEL = [
        'Id' => 'id',
        'Nombre' => 'nombre',
        'Numero_identificacion' => 'numero_identificacion',
        'Fecha' => 'fecha',
        'Usuarios' => 'usuarios',
        'Plan_id' => 'plan_id',
        'Plan_nombre' => 'plan_nombre',
        'Plan_precio' => 'plan_precio',
        'RedDoc' => 'reddoc',
        'Ruteo' => 'ruteo',
        'Usuario_id' => 'usuario_id',
        'Usuario_username' => 'usuario_username',
        'Fecha_ultima_conexion' => 'fecha_ultima_conexion',
        'Cortesia' => 'cortesia',
        'Precio' => 'precio',
        'Consumo' => 'vr_consumo',
    ];

    #[Route('/itrio/consumo/lista', name: 'itrio_consumo_lista')]
    public function lista(Request $request, Itrio $itrio, Excel $excel): Response
    {
        $consumos = [];
        $form = $this->createFormBuilder()
            ->add('fechaDesde', DateType::class)
            ->add('fechaHasta', DateType::class)
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->add('btnExcel', SubmitType::class, ['label' => 'Excel'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $respuesta = $itrio->consumoPost('contenedor/consumo/resumen/', $this->filtros($form));
            if ($respuesta['error']) {
                $this->addFlash('danger', $respuesta['mensaje']);
            } else {
                $consumos = $respuesta['datos']['consumos'] ?? [];
                if ($form->get('btnExcel')->isClicked()) {
                    if ($consumos) {
                        return $excel->descarga('consumos_itrio.xlsx', 'clientes', array_keys(self::COLUMNAS_EXCEL), $this->filasExcel($consumos));
                    }
                    $this->addFlash('danger', 'No existen registros para exportar');
                }
            }
        }

        return $this->render('itrio/consumo/lista.html.twig', [
            'consumos' => $consumos,
            'form' => $form->createView(),
        ]);
    }

    private function filtros(FormInterface $form): array
    {
        return [
            'fecha_desde' => $form->get('fechaDesde')->getData()->format('Y-m-d'),
            'fecha_hasta' => $form->get('fechaHasta')->getData()->format('Y-m-d'),
        ];
    }

    private function filasExcel(array $consumos): iterable
    {
        foreach ($consumos as $consumo) {
            yield array_map(fn (string $campo) => $consumo[$campo] ?? null, array_values(self::COLUMNAS_EXCEL));
        }
    }
}
