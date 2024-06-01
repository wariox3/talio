<?php
namespace App\Controller;

use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DocumentoController extends AbstractController
{

    #[Route('/documento/enviar', name: 'documento_enviar')]
    public function enviar(Request $request, Wolframio $wolframio): Response
    {
        $form = $this->createFormBuilder()
            ->add('btnEnviar', SubmitType::class, array('label' => 'Enviar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnEnviar')->isClicked()) {
                $arrSeleccionados = $request->get('ChkSeleccionar');
                if($arrSeleccionados) {
                    foreach ($arrSeleccionados as $codigo) {
                        $datos = [
                            "documentoId" => $codigo
                        ];
                        $respuesta = $wolframio->consumoPost("api/documento/enviar", $datos);
                    }
                }
            }
        }
        $documentos = [];
        $datos = [
            'estadoEnviado' => false
        ];
        $respuesta = $wolframio->consumoPost('api/documento/lista', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $documentos = $arrDatos['documentos'];
        }
        return $this->render('documento/enviar.html.twig', [
            'documentos' => $documentos,
            'form' => $form->createView()]);
    }
}