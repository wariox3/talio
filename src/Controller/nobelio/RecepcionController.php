<?php
namespace App\Controller\nobelio;

use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Documentos recibidos: las facturas y notas que los proveedores les mandan a
 * los emisores por el buzon de recepcion. Solo lectura: los crea Nobelio al
 * procesar los correos, y aqui solo se consultan y se bajan sus archivos.
 */
class RecepcionController extends AbstractController
{
    // Los tipos que salen de un correo de proveedor: codigo de DocumentoTipo
    // en Nobelio (el del filtro documento_tipo) => nombre visible. Las
    // plantillas lo usan para pintar el tipo.
    public const TIPOS = [
        'factura_venta' => 'Factura de venta',
        'nota_credito' => 'Nota crédito',
        'nota_debito' => 'Nota débito',
    ];

    #[Route('/nobelio/recepcion/lista', name: 'nobelio_recepcion_lista')]
    public function lista(Request $request, Nobelio $nobelio): Response
    {
        // El correo puede llegar por GET desde la ficha del correo; despues
        // viaja en el form como los demas filtros.
        $form = $this->createFormBuilder(['correo' => $request->query->get('correo')])
            ->add('documento_tipo', ChoiceType::class, ['required' => false, 'choices' => ['Todos' => ''] + array_flip(self::TIPOS)])
            ->add('proveedor', TextType::class, ['required' => false])
            ->add('emisor', TextType::class, ['required' => false])
            ->add('correo', TextType::class, ['required' => false])
            ->add('desde', DateType::class, ['required' => false, 'widget' => 'single_text'])
            ->add('hasta', DateType::class, ['required' => false, 'widget' => 'single_text'])
            ->add('search', TextType::class, ['required' => false])
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->add('btnAnterior', SubmitType::class, ['label' => 'Anterior'])
            ->add('btnSiguiente', SubmitType::class, ['label' => 'Siguiente'])
            ->getForm();
        $form->handleRequest($request);

        $pagina = max(1, (int) $request->request->get('pagina', 1));
        $filtros = ['correo' => trim((string) $form->get('correo')->getData())];

        if ($form->isSubmitted() && $form->isValid()) {
            $desde = $form->get('desde')->getData();
            $hasta = $form->get('hasta')->getData();
            $filtros = [
                'documento_tipo' => (string) $form->get('documento_tipo')->getData(),
                'proveedor' => trim((string) $form->get('proveedor')->getData()),
                'emisor' => trim((string) $form->get('emisor')->getData()),
                'correo' => trim((string) $form->get('correo')->getData()),
                'desde' => $desde ? $desde->format('Y-m-d') : '',
                'hasta' => $hasta ? $hasta->format('Y-m-d') : '',
                'search' => trim((string) $form->get('search')->getData()),
            ];
            if ($form->get('btnFiltrar')->isClicked()) {
                $pagina = 1;
            } elseif ($form->get('btnAnterior')->isClicked()) {
                $pagina = max(1, $pagina - 1);
            } elseif ($form->get('btnSiguiente')->isClicked()) {
                $pagina++;
            }
        }

        // Lo emitido mas reciente primero; creado_en desempata los del mismo
        // dia. Es el orden del modelo, pedido explicito para no depender de el.
        // Los filtros vacios no se mandan. emisor y correo son ids enteros: si
        // llega otra cosa, Nobelio responde 400 explicandolo y ese mensaje sale.
        // proveedor es el NIT sin DV, exacto; desde/hasta van sobre la fecha
        // de emision, ambos inclusive. El SearchFilter mira el numero, el
        // CUFE/CUDE y el NIT y la razon social del proveedor.
        $parametros = ['page' => $pagina, 'ordering' => '-fecha_emision,-creado_en']
            + array_filter($filtros, fn (string $valor) => $valor !== '');

        $documentos = [];
        $total = 0;
        $hayAnterior = false;
        $haySiguiente = false;

        $respuesta = $nobelio->consumoGet('api/recepcion/documento/', $parametros);
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
        } else {
            $datos = $respuesta['datos'];
            $documentos = $datos['results'] ?? [];
            $total = (int) ($datos['count'] ?? 0);
            $hayAnterior = !empty($datos['previous']);
            $haySiguiente = !empty($datos['next']);
        }

        return $this->render('nobelio/recepcion/lista.html.twig', [
            'form' => $form->createView(),
            'documentos' => $documentos,
            'total' => $total,
            'pagina' => $pagina,
            'hayAnterior' => $hayAnterior,
            'haySiguiente' => $haySiguiente,
        ]);
    }

    /**
     * Ficha del documento recibido. El id es un UUID y se concatena a la url
     * del API: el requirement lo acota a esa forma.
     */
    #[Route('/nobelio/recepcion/detalle/{id}', name: 'nobelio_recepcion_detalle', requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function detalle(Nobelio $nobelio, string $id): Response
    {
        $respuesta = $nobelio->consumoGet("api/recepcion/documento/{$id}/");
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            return $this->redirectToRoute('nobelio_recepcion_lista');
        }

        // Los adjuntos del documento salen del listado general filtrado: son
        // tres como mucho (XML, XML del documento y PDF), pero el listado
        // pagina y se recorre entero. Si falla, la ficha se pinta igual.
        $adjuntos = [];
        $respuestaAdjuntos = $nobelio->consumoGetTodos('api/recepcion/adjunto/', ['documento' => $id]);
        if ($respuestaAdjuntos['error']) {
            $this->addFlash('danger', "Nobelio: {$respuestaAdjuntos['mensaje']}");
        } else {
            $adjuntos = $respuestaAdjuntos['datos'];
        }

        return $this->render('nobelio/recepcion/detalle.html.twig', [
            'documento' => $respuesta['datos'],
            'adjuntos' => $adjuntos,
        ]);
    }

    /**
     * Baja un adjunto con el nombre que le puso el proveedor. Nobelio lo manda
     * siempre como descarga, nunca para mostrar: un HTML o un SVG de un tercero
     * no debe abrirse en el navegador, y aqui se reemite igual (attachment).
     * Los adjuntos se piden desde la ficha del correo o del documento: si
     * falla se vuelve a la pagina de la que vino.
     */
    #[Route('/nobelio/recepcion/adjunto/descargar/{id}', name: 'nobelio_recepcion_adjunto_descargar', requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function descargarAdjunto(Request $request, Nobelio $nobelio, string $id): Response
    {
        $respuesta = $nobelio->consumoArchivo("api/recepcion/adjunto/{$id}/descargar/");
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            // Solo se vuelve al Referer si es de Talio; si no, a la lista.
            $origen = (string) $request->headers->get('referer', '');
            if (str_starts_with($origen, $request->getSchemeAndHttpHost() . '/')) {
                return $this->redirect($origen);
            }

            return $this->redirectToRoute('nobelio_recepcion_lista');
        }

        $descarga = new Response($respuesta['contenido'], Response::HTTP_OK, [
            'Content-Type' => $respuesta['tipo'],
            // Que el navegador no adivine otro tipo y lo ejecute.
            'X-Content-Type-Options' => 'nosniff',
        ]);
        // El nombre lo puso el proveedor y puede traer tildes o '%':
        // makeDisposition() exige un respaldo ASCII sin '%', '/' ni barras, o
        // lanza una excepcion.
        $nombre = str_replace(['/', '\\'], '_', $respuesta['nombre']) ?: "adjunto-{$id}";
        $respaldo = preg_replace('/[^\x20-\x7e]|[%"]/', '_', $nombre);
        $descarga->headers->set('Content-Disposition', $descarga->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nombre, $respaldo,
        ));

        return $descarga;
    }

    /**
     * Baja uno de los archivos del documento: xml (tal como llego, el
     * AttachedDocument o el documento suelto), xml-factura (el documento sin
     * el AttachedDocument; si llego suelto es el mismo) o pdf (la
     * representacion grafica del proveedor, que puede no haberla mandado:
     * Nobelio responde 400 diciendolo y ese mensaje sale). El archivo va en la
     * ruta acotado por el requirement, porque se concatena a la url del API.
     */
    #[Route('/nobelio/recepcion/descargar/{archivo}/{id}', name: 'nobelio_recepcion_descargar', requirements: ['archivo' => 'xml|xml-factura|pdf', 'id' => '[0-9a-fA-F-]{36}'])]
    public function descargar(Nobelio $nobelio, string $archivo, string $id): Response
    {
        $respuesta = $nobelio->consumoArchivo("api/recepcion/documento/{$id}/{$archivo}/");
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            return $this->redirectToRoute('nobelio_recepcion_detalle', ['id' => $id]);
        }

        $descarga = new Response($respuesta['contenido'], Response::HTTP_OK, [
            'Content-Type' => $respuesta['tipo'],
        ]);
        $descarga->headers->set('Content-Disposition', $descarga->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            // El nombre lo pone Nobelio con el numero del documento
            // ("FE1.xml", "FE1-documento.xml", "FE1.pdf"); el id es el respaldo.
            $respuesta['nombre'] !== ''
                ? $respuesta['nombre']
                : "{$id}-{$archivo}." . ($archivo === 'pdf' ? 'pdf' : 'xml'),
        ));

        return $descarga;
    }
}
