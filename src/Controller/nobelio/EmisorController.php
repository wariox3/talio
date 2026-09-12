<?php
namespace App\Controller\nobelio;

use App\Utilidades\Mensajes;
use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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

        return $this->render('nobelio/emisor/detalle.html.twig', [
            'emisor' => $emisor,
            'certificados' => $certificados,
            'software' => $software,
            'resoluciones' => $resoluciones,
        ]);
    }

    /**
     * Ventana para sembrar un documento de prueba sobre una resolucion.
     *
     * Se abre con abrirVentana() desde la linea de la resolucion, asi que un
     * fallo no redirige a ningun sitio: el mensaje sale en la propia ventana.
     *
     * El consecutivo es opcional —sin el, Nobelio toma el siguiente libre de
     * la resolucion—, y por eso el campo no es `required` y el cuerpo va vacio
     * cuando no se digita. El tipo de documento no se pide: lo decide el
     * `tipo_factura` de la resolucion.
     */
    #[Route('/nobelio/resolucion/documento-prueba/{id}', name: 'nobelio_resolucion_documento_prueba', requirements: ['id' => '\\d+'])]
    public function documentoPrueba(Request $request, Nobelio $nobelio, int $id): Response
    {
        $form = $this->createFormBuilder()
            ->add('consecutivo', IntegerType::class, ['required' => false, 'attr' => ['min' => 1]])
            ->add('btnCrear', SubmitType::class, ['label' => 'Crear'])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $consecutivo = $form->get('consecutivo')->getData();
            $datos = $consecutivo !== null ? ['consecutivo' => $consecutivo] : [];

            // Nobelio comprueba el rango y los consecutivos ya usados, y
            // responde 400 explicando por que no se puede ("El consecutivo N
            // esta fuera del rango", etc.); ese es el mensaje que se muestra.
            $respuesta = $nobelio->consumoPost("api/emisores/resolucion/{$id}/crear-documento-prueba/", $datos);
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                $creado = $respuesta['datos'];
                Mensajes::success(sprintf(
                    "Documento %s creado en estado '%s'. Queda en borrador: se emite desde Documentos.",
                    $creado['numero'] ?? '',
                    $creado['estado'] ?? '',
                ));
            }

            // Redirect despues del POST: la ventana se queda abierta para
            // sembrar el siguiente, y un F5 no vuelve a crear el documento.
            return $this->redirectToRoute('nobelio_resolucion_documento_prueba', ['id' => $id]);
        }

        // Solo para encabezar la ventana: sin esto no se ve sobre que
        // resolucion se esta creando, que es lo unico que distingue a una de
        // otra cuando hay varias en el emisor.
        $resolucion = [];
        $respuestaResolucion = $nobelio->consumoGet("api/emisores/resolucion/{$id}/");
        if ($respuestaResolucion['error']) {
            Mensajes::error("Nobelio: {$respuestaResolucion['mensaje']}");
        } else {
            $resolucion = $respuestaResolucion['datos'];
        }

        return $this->render('nobelio/emisor/documento_prueba.html.twig', [
            'form' => $form->createView(),
            'resolucion' => $resolucion,
        ]);
    }
}
