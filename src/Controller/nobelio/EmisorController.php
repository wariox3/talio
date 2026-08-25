<?php
namespace App\Controller\nobelio;

use App\Utilidades\Mensajes;
use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EmisorController extends AbstractController
{
    #[Route('/nobelio/emisor/lista', name: 'nobelio_emisor_lista')]
    public function lista(Request $request, Nobelio $nobelio): Response
    {
        $form = $this->createFormBuilder()
            ->add('search', TextType::class, ['required' => false])
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->add('btnAnterior', SubmitType::class, ['label' => 'Anterior'])
            ->add('btnSiguiente', SubmitType::class, ['label' => 'Siguiente'])
            ->getForm();
        $form->handleRequest($request);

        // La pagina viaja en un hidden propio, fuera del form, para poder
        // recalcularla despues de handleRequest sin pelearse con el form.
        $pagina = max(1, (int) $request->request->get('pagina', 1));
        $busqueda = '';

        if ($form->isSubmitted() && $form->isValid()) {
            $busqueda = trim((string) $form->get('search')->getData());
            if ($form->get('btnFiltrar')->isClicked()) {
                $pagina = 1;
            } elseif ($form->get('btnAnterior')->isClicked()) {
                $pagina = max(1, $pagina - 1);
            } elseif ($form->get('btnSiguiente')->isClicked()) {
                $pagina++;
            }
        }

        $parametros = ['page' => $pagina];
        if ($busqueda !== '') {
            $parametros['search'] = $busqueda;
        }

        $emisores = [];
        $total = 0;
        $hayAnterior = false;
        $haySiguiente = false;

        $respuesta = $nobelio->consumoGet('api/emisores/emisor/', $parametros);
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");
        } else {
            // DRF pagina con PageNumberPagination: {count, next, previous, results}.
            $datos = $respuesta['datos'];
            $emisores = $datos['results'] ?? [];
            $total = (int) ($datos['count'] ?? 0);
            $hayAnterior = !empty($datos['previous']);
            $haySiguiente = !empty($datos['next']);
        }

        return $this->render('nobelio/emisor/lista.html.twig', [
            'form' => $form->createView(),
            'emisores' => $emisores,
            'total' => $total,
            'pagina' => $pagina,
            'hayAnterior' => $hayAnterior,
            'haySiguiente' => $haySiguiente,
        ]);
    }

    #[Route('/nobelio/emisor/detalle/{id}', name: 'nobelio_emisor_detalle', requirements: ['id' => '\\d+'])]
    public function detalle(Nobelio $nobelio, int $id): Response
    {
        $respuesta = $nobelio->consumoGet("api/emisores/emisor/{$id}/");
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            return $this->redirectToRoute('nobelio_emisor_lista');
        }

        $emisor = $respuesta['datos'];

        $certificados = [];
        $listaCertificados = $nobelio->consumoGet('api/emisores/certificado/', ['emisor' => $id]);
        if ($listaCertificados['error']) {
            Mensajes::error("Nobelio: {$listaCertificados['mensaje']}");
        } else {
            $certificados = $listaCertificados['datos']['results'] ?? [];
        }

        $software = [];
        $listaSoftware = $nobelio->consumoGet('api/emisores/software/', ['emisor' => $id]);
        if ($listaSoftware['error']) {
            Mensajes::error("Nobelio: {$listaSoftware['mensaje']}");
        } else {
            $software = $listaSoftware['datos']['results'] ?? [];
        }

        $resoluciones = [];
        $listaResoluciones = $nobelio->consumoGet('api/emisores/resolucion/', ['emisor' => $id]);
        if ($listaResoluciones['error']) {
            Mensajes::error("Nobelio: {$listaResoluciones['mensaje']}");
        } else {
            $resoluciones = $listaResoluciones['datos']['results'] ?? [];
        }

        $documentos = [];
        $listaDocumentos = $nobelio->consumoGet('api/documentos/documento/', ['emisor' => $id]);
        if ($listaDocumentos['error']) {
            Mensajes::error("Nobelio: {$listaDocumentos['mensaje']}");
        } else {
            $documentos = $listaDocumentos['datos']['results'] ?? [];
        }

        return $this->render('nobelio/emisor/detalle.html.twig', [
            'emisor' => $emisor,
            'certificados' => $certificados,
            'software' => $software,
            'resoluciones' => $resoluciones,
            'documentos' => $documentos,
        ]);
    }
}
