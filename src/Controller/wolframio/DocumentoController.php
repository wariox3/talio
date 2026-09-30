<?php
namespace App\Controller\wolframio;

use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DocumentoController extends AbstractController
{
    #[Route('/wolframio/documento/enviar', name: 'wolframio_documento_enviar')]
    public function enviar(Request $request, Wolframio $wolframio): Response
    {
        $form = $this->createFormBuilder()
            ->add('btnEnviar', SubmitType::class, ['label' => 'Enviar'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $form->get('btnEnviar')->isClicked()) {
            $this->procesarSeleccionados($request, $wolframio, 'api/documento/enviar', [], 'enviados');

            return $this->redirectToRoute('wolframio_documento_enviar');
        }

        return $this->lista($wolframio, $form, 'wolframio/documento/enviar.html.twig', [
            'estadoEnviado' => false,
        ]);
    }

    #[Route('/wolframio/documento/error', name: 'wolframio_documento_error')]
    public function error(Request $request, Wolframio $wolframio): Response
    {
        $form = $this->createFormBuilder()
            ->add('btnActivar', SubmitType::class, ['label' => 'Activar'])
            ->add('btnActivarEnviar', SubmitType::class, ['label' => 'Activar y enviar'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $emitir = $form->get('btnActivarEnviar')->isClicked();
            if ($emitir || $form->get('btnActivar')->isClicked()) {
                $this->procesarSeleccionados($request, $wolframio, 'api/documento/activar_reenvio', ['emitir' => $emitir],
                    $emitir ? 'activados y enviados' : 'activados');

                return $this->redirectToRoute('wolframio_documento_error');
            }
        }

        return $this->lista($wolframio, $form, 'wolframio/documento/error.html.twig', [
            'estadoEnviado' => true,
            'estadoError' => true,
        ]);
    }

    #[Route('/wolframio/documento/respuesta', name: 'wolframio_documento_respuesta')]
    public function respuesta(Request $request, Wolframio $wolframio): Response
    {
        $form = $this->createFormBuilder()
            ->add('btnRespuesta', SubmitType::class, ['label' => 'Activar'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $form->get('btnRespuesta')->isClicked()) {
            $this->procesarSeleccionados($request, $wolframio, 'api/documento/cliente/respuesta', [], 'activados');

            return $this->redirectToRoute('wolframio_documento_respuesta');
        }

        return $this->lista($wolframio, $form, 'wolframio/documento/respuesta.html.twig', [
            'estadoEnviado' => true,
            'estadoError' => false,
            'estadoValidado' => true,
            'estadoRespuestaValidadoCliente' => false,
        ]);
    }

    #[Route('/wolframio/documento/detalle/{id}', name: 'wolframio_documento_detalle')]
    public function detalle(Wolframio $wolframio, string $id): Response
    {
        $documento = [];
        $respuesta = $wolframio->consumoPost('api/documento/detalle', ['documentoId' => $id]);
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $documento = $respuesta['datos']['documento'] ?? [];
        }

        return $this->render('wolframio/documento/detalle.html.twig', [
            'documento' => $documento,
            'errores' => $documento['errores'] ?? [],
        ]);
    }

    /**
     * Llama $endpoint por cada documento marcado (ChkSeleccionar[]) y deja un
     * aviso con cuantos salieron bien y el error de cada uno que fallo. Antes
     * el resultado se descartaba y un fallo no se veia.
     */
    private function procesarSeleccionados(Request $request, Wolframio $wolframio, string $endpoint, array $datos, string $hecho): void
    {
        $seleccionados = $request->request->all('ChkSeleccionar');
        if (!$seleccionados) {
            $this->addFlash('warning', 'No se seleccionó ningún documento.');

            return;
        }

        $correctos = 0;
        foreach ($seleccionados as $documentoId) {
            $respuesta = $wolframio->consumoPost($endpoint, ['documentoId' => $documentoId] + $datos);
            if ($respuesta['error']) {
                $this->addFlash('danger', "Documento {$documentoId}: {$respuesta['mensaje']}");
            } else {
                $correctos++;
            }
        }

        if ($correctos > 0) {
            $this->addFlash('success', "{$correctos} de " . count($seleccionados) . " documentos {$hecho}.");
        }
    }

    private function lista(Wolframio $wolframio, FormInterface $form, string $plantilla, array $filtros): Response
    {
        $documentos = [];
        $respuesta = $wolframio->consumoPost('api/documento/lista', $filtros);
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $documentos = $respuesta['datos']['documentos'] ?? [];
        }

        return $this->render($plantilla, [
            'documentos' => $documentos,
            'form' => $form->createView(),
        ]);
    }
}
