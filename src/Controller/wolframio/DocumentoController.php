<?php
namespace App\Controller\wolframio;

use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DocumentoController extends AbstractController
{
    #[Route('/wolframio/documento/enviar', name: 'wolframio_documento_enviar')]
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
        return $this->render('wolframio/documento/enviar.html.twig', [
            'documentos' => $documentos,
            'form' => $form->createView()]);
    }

    #[Route('/wolframio/documento/error', name: 'wolframio_documento_error')]
    public function error(Request $request, Wolframio $wolframio): Response
    {
        $form = $this->createFormBuilder()
            ->add('btnActivar', SubmitType::class, array('label' => 'Activar'))
            ->add('btnActivarEnviar', SubmitType::class, array('label' => 'Activar y enviar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnActivar')->isClicked()) {
                $arrSeleccionados = $request->get('ChkSeleccionar');
                if($arrSeleccionados) {
                    foreach ($arrSeleccionados as $codigo) {
                        $datos = [
                            "documentoId" => $codigo,
                            "emitir" => false
                        ];
                        $respuesta = $wolframio->consumoPost("api/documento/activar_reenvio", $datos);
                    }
                }
            }
            if ($form->get('btnActivarEnviar')->isClicked()) {
                $arrSeleccionados = $request->get('ChkSeleccionar');
                if($arrSeleccionados) {
                    foreach ($arrSeleccionados as $codigo) {
                        $datos = [
                            "documentoId" => $codigo,
                            "emitir" => true
                        ];
                        $respuesta = $wolframio->consumoPost("api/documento/activar_reenvio", $datos);
                    }
                }
            }
        }
        $documentos = [];
        $datos = [
            'estadoEnviado' => true,
            'estadoError' => true,
        ];
        $respuesta = $wolframio->consumoPost('api/documento/lista', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $documentos = $arrDatos['documentos'];
        }
        return $this->render('wolframio/documento/error.html.twig', [
            'documentos' => $documentos,
            'form' => $form->createView()]);
    }

    #[Route('/wolframio/documento/respuesta', name: 'wolframio_documento_respuesta')]
    public function respuesta(Request $request, Wolframio $wolframio): Response
    {
        $form = $this->createFormBuilder()
            ->add('btnRespuesta', SubmitType::class, array('label' => 'Activar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnRespuesta')->isClicked()) {
                $arrSeleccionados = $request->get('ChkSeleccionar');
                if($arrSeleccionados) {
                    foreach ($arrSeleccionados as $codigo) {
                        $datos = [
                            "documentoId" => $codigo
                        ];
                        $respuesta = $wolframio->consumoPost("api/documento/cliente/respuesta", $datos);
                    }
                }
            }
        }
        $documentos = [];
        $datos = [
            'estadoEnviado' => true,
            'estadoError' => false,
            'estadoValidado' => true,
            'estadoRespuestaValidadoCliente' => false
        ];
        $respuesta = $wolframio->consumoPost('api/documento/lista', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $documentos = $arrDatos['documentos'];
        }
        return $this->render('wolframio/documento/respuesta.html.twig', [
            'documentos' => $documentos,
            'form' => $form->createView()]);
    }

    #[Route('/wolframio/documento/detalle/{id}', name: 'wolframio_documento_detalle')]
    public function detalle(Request $request, Wolframio $wolframio, $id): Response
    {
        $form = $this->createFormBuilder()
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

        }
        $documento = [];
        $errores = [];
        $datos = [
            'documentoId' => $id
        ];
        $respuesta = $wolframio->consumoPost('api/documento/detalle', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $documento = $arrDatos['documento'];
            $errores = $documento['errores'];
        }
        return $this->render('wolframio/documento/detalle.html.twig', [
            'documento' => $documento,
            'errores' => $errores,
            'form' => $form->createView()]);
    }
}