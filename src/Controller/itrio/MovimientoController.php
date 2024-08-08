<?php
namespace App\Controller\itrio;

use App\Utilidades\Itrio;
use App\Utilidades\Niquel;
use App\Utilidades\SpaceDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MovimientoController extends AbstractController
{
    #[Route('/itrio/movimiento/lista', name: 'itrio_movimiento_lista')]
    public function lista(Request $request, Itrio $itrio): Response
    {
        $form = $this->createFormBuilder()
            ->add('entorno', ChoiceType::class, ['choices' => ['prod' => 'prod', 'test' => 'test'], 'data' => 'prod'])
            ->add('btnFiltrar', SubmitType::class, array('label' => 'Filtrar'))
            ->add('btnEliminar', SubmitType::class, array('label' => 'Eliminar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnEliminar')->isClicked()) {
                $arrSeleccionados = $request->get('ChkSeleccionar');
                if($arrSeleccionados) {
                    $erroresSeleccionados = [];
                    foreach ($arrSeleccionados as $codigo) {
                        $erroresSeleccionados[] = [
                            "id" => $codigo,
                        ];
                    }
                    $datos = [
                        "errores" => $erroresSeleccionados
                    ];
                    $itrio->consumoPost('api/error/eliminar', $datos);
                }
            }
        }
        $movimientos = [];
        $respuesta = $itrio->consumoPost('contenedor/movimiento/lista/', ['entorno' => $form->get('entorno')->getData()]);
        if(!$respuesta['error']) {
            $movimientos = $respuesta['datos'];
        }
        return $this->render('itrio/movimiento/lista.html.twig', [
            'movimientos' => $movimientos,
            'form' => $form->createView()]);
    }

    #[Route('/itrio/movimiento/subir/{id}', name: 'itrio_movimiento_subir')]
    public function detalle(Request $request, SpaceDO $spaceDO, Itrio $itrio, $id): Response
    {
        $form = $this->createFormBuilder()
            ->add('attachment', FileType::class)
            ->add('cargar', SubmitType::class, array('label' => 'Cagar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('cargar')->isClicked()) {
                $objArchivo = $form['attachment']->getData();
                $fileContent = file_get_contents($objArchivo->getPathname());
                $contentType = $objArchivo->getMimeType();
                $archivoDestino = "itrio/prod/movimiento/factura_{$id}.pdf";
                $spaceDO->subirB64($archivoDestino, $fileContent, $contentType);
                $itrio->consumoPost('contenedor/movimiento/marcar-adjunto/', ['id' => $id]);
                echo "<script type='text/javascript'>window.close();window.opener.location.reload();</script>";
            }

        }
        return $this->render('itrio/movimiento/subir.html.twig', [
            'form' => $form->createView()]);
    }
}