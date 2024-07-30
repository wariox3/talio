<?php
namespace App\Controller\niquel;

use App\Utilidades\Niquel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ErrorController extends AbstractController
{
    #[Route('/niquel/error/lista', name: 'niquel_error_lista')]
    public function lista(Request $request, Niquel $niquel): Response
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
                    $niquel->consumoPost('api/error/eliminar', $datos);
                }
            }
        }
        $errores = [];
        $respuesta = $niquel->consumoPost('api/error/lista', ['entorno' => $form->get('entorno')->getData()]);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $errores = $arrDatos['errores'];
        }
        return $this->render('niquel/error/lista.html.twig', [
            'errores' => $errores,
            'form' => $form->createView()]);
    }

    #[Route('/niquel/error/detalle/{id}', name: 'niquel_error_detalle')]
    public function detalle(Request $request, Niquel $niquel, $id): Response
    {
        $form = $this->createFormBuilder()
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

        }
        $error = [];
        $datos = [
            'errorId' => $id
        ];
        $respuesta = $niquel->consumoPost('api/error/detalle', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $error = $arrDatos['error'];
        }
        return $this->render('niquel/error/detalle.html.twig', [
            'error' => $error,
            'form' => $form->createView()]);
    }
}