<?php
namespace App\Controller\kiai;

use App\Utilidades\Mensajes;
use App\Utilidades\Softgic;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Box\Spout\Writer\Common\Creator\Style\StyleBuilder;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;

class ConsumoController extends AbstractController
{
    #[Route('/kiai/consumo/lista', name: 'kiai_consumo_lista')]
    public function lista(Request $request, Softgic $softgic): Response
    {
        $fecha = new \DateTime('now');
        $aliados = [
            'semantica' => 'A7AF0233-946E-42CA-A42F-7B6574B9A8D8',
            'reddoc' => '4670CF98-F217-4B6A-A558-ADCCBED2E980'
        ];
        $form = $this->createFormBuilder()
            ->add('anio', TextType::class, array('required' => false, 'data' => $fecha->format('Y')))
            ->add('mes', TextType::class, array('required' => false, 'data' => $fecha->format('m')))
            ->add('aliado', ChoiceType::class, ['choices' => $aliados, 'required' => true])
            ->add('btnGenerar', SubmitType::class, array('label' => 'Generar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnGenerar')->isClicked()) {
                $anio = $form->get('anio')->getData();
                $mes = $form->get('mes')->getData();
                $aliado = $form->get('aliado')->getData();
                $aliadoNombre = array_search($aliado, $aliados);
                $respuesta = $softgic->consultaConsumo($aliado, $anio, $mes);
                if(!$respuesta['error']) {
                    $consumos = $respuesta['consumos'];
                    $this->excel($consumos, $aliadoNombre, $anio, $mes);
                }
            }
        }

        return $this->render('kiai/consumo/lista.html.twig', [
            'form' => $form->createView()]);
    }

    public function excel($arRegistros, $aliado, $anio, $mes)
    {
        if ($arRegistros) {
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
            $arrColumnas = ['Aliado','Anio','Mes','Suscriptor','Documento','Dv','RazonSocial','Direccion','CodigoPostal','CodigoCiudad','NombreCiudad','CodigoDepartamento',
                'NombreDepartamento','Obligaciones','Correo','CorreoDian','Estado','RegistroHabilitacion','EstadoDescripcion',
                'TestSetIdDian','Facturas','DocSoporte','Eventos','Nominas','DocEquivalente','Total'];
            $rowFromValues = WriterEntityFactory::createRowFromArray($arrColumnas, $estiloEncabezado);
            $writer->addRow($rowFromValues);
            $writer->getCurrentSheet()->setName('clientes');
            foreach ($arRegistros as $arRegistro) {
                $total = $arRegistro['Facturas'] + $arRegistro['DocSoporte'] + $arRegistro['Eventos'] + $arRegistro['Nominas']+$arRegistro['DocEquivalente'];
                $cells = [
                    WriterEntityFactory::createCell($aliado),
                    WriterEntityFactory::createCell($anio),
                    WriterEntityFactory::createCell($mes),
                    WriterEntityFactory::createCell($arRegistro['Suscriptor']),
                    WriterEntityFactory::createCell($arRegistro['Documento']),
                    WriterEntityFactory::createCell($arRegistro['Dv']),
                    WriterEntityFactory::createCell($arRegistro['RazonSocial']),
                    WriterEntityFactory::createCell($arRegistro['Direccion']),
                    WriterEntityFactory::createCell($arRegistro['CodigoPostal']),
                    WriterEntityFactory::createCell($arRegistro['CodigoCiudad']),
                    WriterEntityFactory::createCell($arRegistro['NombreCiudad']),
                    WriterEntityFactory::createCell($arRegistro['CodigoDepartamento']),
                    WriterEntityFactory::createCell($arRegistro['NombreDepartamento']),
                    WriterEntityFactory::createCell($arRegistro['Obligaciones']),
                    WriterEntityFactory::createCell($arRegistro['Correo']),
                    WriterEntityFactory::createCell($arRegistro['CorreoDian']),
                    WriterEntityFactory::createCell($arRegistro['Estado']),
                    WriterEntityFactory::createCell($arRegistro['RegistroHabilitacion']),
                    WriterEntityFactory::createCell($arRegistro['EstadoDescripcion']),
                    WriterEntityFactory::createCell($arRegistro['TestSetIdDian']),
                    WriterEntityFactory::createCell($arRegistro['Facturas']),
                    WriterEntityFactory::createCell($arRegistro['DocSoporte']),
                    WriterEntityFactory::createCell($arRegistro['Eventos']),
                    WriterEntityFactory::createCell($arRegistro['Nominas']),
                    WriterEntityFactory::createCell($arRegistro['DocEquivalente']),
                    WriterEntityFactory::createCell($total),
                ];
                $singleRow = WriterEntityFactory::createRow($cells, $estiloDetalle);
                $writer->addRow($singleRow);
            }
            $writer->close();
            if (file_exists($ruta)) {
                $nombreArchivo = "consumos_{$aliado}{$anio}{$mes}.xlsx";
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