<?php
namespace App\Controller\kiai;

use App\Utilidades\Excel;
use App\Utilidades\Softgic;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ConsumoController extends AbstractController
{
    /** Aliados de Kiai de los que se consultan consumos: nombre => id en Kiai. */
    private const ALIADOS = [
        'semantica' => 'A7AF0233-946E-42CA-A42F-7B6574B9A8D8',
        'reddoc' => '4670CF98-F217-4B6A-A558-ADCCBED2E980',
    ];

    /** Columnas del Excel que salen tal cual del consumo de Kiai. */
    private const COLUMNAS_KIAI = [
        'Suscriptor', 'Documento', 'Dv', 'RazonSocial', 'Direccion', 'CodigoPostal', 'CodigoCiudad', 'NombreCiudad',
        'CodigoDepartamento', 'NombreDepartamento', 'Obligaciones', 'Correo', 'CorreoDian', 'Estado',
        'RegistroHabilitacion', 'EstadoDescripcion', 'TestSetIdDian', 'Facturas', 'DocSoporte', 'Eventos', 'Nominas',
        'DocEquivalente',
    ];

    /** Columnas que suman el Total. */
    private const COLUMNAS_TOTAL = ['Facturas', 'DocSoporte', 'Eventos', 'Nominas', 'DocEquivalente'];

    #[Route('/kiai/consumo/lista', name: 'kiai_consumo_lista')]
    public function lista(Request $request, Softgic $softgic, Excel $excel): Response
    {
        $fecha = new \DateTime('now');
        $form = $this->createFormBuilder()
            ->add('anio', IntegerType::class, ['data' => (int) $fecha->format('Y')])
            ->add('mes', IntegerType::class, ['data' => (int) $fecha->format('m')])
            ->add('aliado', ChoiceType::class, ['choices' => self::ALIADOS])
            ->add('btnGenerar', SubmitType::class, ['label' => 'Generar'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $anio = $form->get('anio')->getData();
            $mes = $form->get('mes')->getData();
            $aliado = $form->get('aliado')->getData();
            $aliadoNombre = array_search($aliado, self::ALIADOS, true);

            $respuesta = $mes >= 1 && $mes <= 12 && $anio >= 2000
                ? $softgic->consultaConsumo($aliado, $anio, $mes)
                : ['error' => true, 'mensaje' => 'El año o el mes no son válidos.'];
            if ($respuesta['error']) {
                $this->addFlash('danger', $respuesta['mensaje']);
            } elseif (!$respuesta['consumos']) {
                $this->addFlash('danger', 'No existen registros para exportar');
            } else {
                return $excel->descarga(
                    sprintf('consumos_%s%d%02d.xlsx', $aliadoNombre, $anio, $mes),
                    'clientes',
                    ['Aliado', 'Anio', 'Mes', ...self::COLUMNAS_KIAI, 'Total'],
                    $this->filasExcel($respuesta['consumos'], $aliadoNombre, $anio, $mes),
                );
            }
        }

        return $this->render('kiai/consumo/lista.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    private function filasExcel(array $consumos, string $aliado, int $anio, int $mes): iterable
    {
        foreach ($consumos as $consumo) {
            $total = array_sum(array_map(fn (string $columna) => (int) ($consumo[$columna] ?? 0), self::COLUMNAS_TOTAL));
            yield [
                $aliado,
                $anio,
                $mes,
                ...array_map(fn (string $columna) => $consumo[$columna] ?? null, self::COLUMNAS_KIAI),
                $total,
            ];
        }
    }
}
