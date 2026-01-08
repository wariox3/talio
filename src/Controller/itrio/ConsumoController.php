<?php
namespace App\Controller\itrio;

use App\Utilidades\Itrio;
use App\Utilidades\Mensajes;
use App\Utilidades\Niquel;
use App\Utilidades\SpaceDO;
use Box\Spout\Writer\Common\Creator\Style\StyleBuilder;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ConsumoController extends AbstractController
{
    #[Route('/itrio/consumo/lista', name: 'itrio_consumo_lista')]
    public function lista(Request $request, Itrio $itrio): Response
    {
        $consumos = [];
        $form = $this->createFormBuilder()
            ->add('fechaDesde', DateType::class)
            ->add('fechaHasta', DateType::class)
            ->add('btnFiltrar', SubmitType::class, array('label' => 'Filtrar'))
            ->add('btnExcel', SubmitType::class, array('label' => 'Excel'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnFiltrar')->isClicked()) {
                $filtros = $this->filtros($form);
                $respuesta = $itrio->consumoPost('contenedor/consumo/resumen/', $filtros);
                if($respuesta['error']) {
                    Mensajes::error($respuesta['mensaje']);
                } else {
                    $consumos = $respuesta['datos']['consumos'];
                }
            }
            if ($form->get('btnExcel')->isClicked()) {
                $filtros = $this->filtros($form);
                $respuesta = $itrio->consumoPost('contenedor/consumo/resumen/', $filtros);
                if($respuesta['error']) {
                    Mensajes::error($respuesta['mensaje']);
                } else {
                    $consumos = $respuesta['datos']['consumos'];
                    $this->excel($consumos);
                }
            }
        }
        return $this->render('itrio/consumo/lista.html.twig', [
            'consumos' => $consumos,
            'form' => $form->createView()]);
    }

    private function filtros($form)
    {
        $filtros = [
            'fecha_desde' => $form->get('fechaDesde')->getData()->format('Y-m-d'),
            'fecha_hasta' => $form->get('fechaHasta')->getData()->format('Y-m-d')
        ];
        return $filtros;
    }

    public function excel($consumos)
    {
        if ($consumos) {
            $archivo = bin2hex(random_bytes((10 - (20 % 2)) / 2));
            $ruta = "/var/www/html/temporal/{$archivo}";
            $writer = WriterEntityFactory::createXLSXWriter();
            $writer->openToFile($ruta);
            $estiloEncabezado = (new StyleBuilder())
                ->setFontName('Arial')
                ->setFontBold()
                ->setFontSize(8)
                ->setShouldWrapText(false)
                ->build();
            $estiloDetalle = (new StyleBuilder())
                ->setFontName('Arial')
                ->setFontSize(8)
                ->setShouldWrapText(false)
                ->build();
            $estiloNumerico = (new StyleBuilder())
                ->setFormat('#,##0')
                ->build();
            $arrColumnas = ['Id','Nombre','Numero_identificacion','Fecha','Usuarios','Plan_id','Plan_nombre','Plan_precio','RedDoc',
                'Ruteo','Usuario_id','Usuario_username','Fecha_ultima_conexion','Cortesia','Precio','Consumo'];
            $rowFromValues = WriterEntityFactory::createRowFromArray($arrColumnas, $estiloEncabezado);
            $writer->addRow($rowFromValues);
            $writer->getCurrentSheet()->setName('clientes');
            foreach ($consumos as $consumo) {
                $cells = [
                    WriterEntityFactory::createCell($consumo['id']),
                    WriterEntityFactory::createCell($consumo['nombre']),
                    WriterEntityFactory::createCell($consumo['numero_identificacion']),
                    WriterEntityFactory::createCell($consumo['fecha']),
                    WriterEntityFactory::createCell($consumo['usuarios']),
                    WriterEntityFactory::createCell($consumo['plan_id']),
                    WriterEntityFactory::createCell($consumo['plan_nombre']),
                    WriterEntityFactory::createCell($consumo['plan_precio']),
                    WriterEntityFactory::createCell($consumo['reddoc']),
                    WriterEntityFactory::createCell($consumo['ruteo']),
                    WriterEntityFactory::createCell($consumo['usuario_id']),
                    WriterEntityFactory::createCell($consumo['usuario_username']),
                    WriterEntityFactory::createCell($consumo['fecha_ultima_conexion']),
                    WriterEntityFactory::createCell($consumo['cortesia']),
                    WriterEntityFactory::createCell($consumo['precio']),
                    WriterEntityFactory::createCell($consumo['vr_consumo']),
                ];
                $singleRow = WriterEntityFactory::createRow($cells, $estiloDetalle);
                $writer->addRow($singleRow);
            }
            $writer->close();
            if (file_exists($ruta)) {
                $nombreArchivo = "consumos_itrio.xlsx";
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment;filename='.$nombreArchivo);
                header('Cache-Control: max-age=0');
                header('Cache-Control: max-age=1');
                header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
                header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
                header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
                header('Pragma: public'); // HTTP/1.0
                readfile($ruta);
                unlink($ruta);
                exit;
            }
        } else {
            Mensajes::error("No existen registros para exportar");
        }
    }
}