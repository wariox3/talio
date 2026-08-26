<?php
namespace App\Controller\nobelio;

use App\Utilidades\Mensajes;
use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class DocumentoController extends AbstractController
{
    // Los estados son los de DocumentoEstado.Nombre en Nobelio, que es una
    // lista cerrada y sin endpoint de catalogo propio.
    private const ESTADOS = [
        'Todos' => '',
        'Borrador' => 'borrador',
        'XML generado' => 'generado',
        'Firmado' => 'firmado',
        'Enviado a la DIAN' => 'enviado',
        'Aceptado por la DIAN' => 'aceptado',
        'Rechazado por la DIAN' => 'rechazado',
    ];

    #[Route('/nobelio/documento/lista', name: 'nobelio_documento_lista')]
    public function lista(Request $request, Nobelio $nobelio): Response
    {
        $form = $this->createFormBuilder()
            ->add('estado', ChoiceType::class, ['required' => false, 'choices' => self::ESTADOS])
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->add('btnAnterior', SubmitType::class, ['label' => 'Anterior'])
            ->add('btnSiguiente', SubmitType::class, ['label' => 'Siguiente'])
            ->getForm();
        $form->handleRequest($request);

        // La pagina viaja en un hidden propio, fuera del form, para poder
        // recalcularla despues de handleRequest sin pelearse con el form.
        $pagina = max(1, (int) $request->request->get('pagina', 1));
        $estado = '';

        if ($form->isSubmitted() && $form->isValid()) {
            $estado = (string) $form->get('estado')->getData();
            if ($form->get('btnFiltrar')->isClicked()) {
                $pagina = 1;
            } elseif ($form->get('btnAnterior')->isClicked()) {
                $pagina = max(1, $pagina - 1);
            } elseif ($form->get('btnSiguiente')->isClicked()) {
                $pagina++;
            }
        }

        // Lo mas reciente primero. La hora desempata dentro del mismo dia, que
        // es lo normal en un lote de facturacion. Coincide con el orden por
        // defecto del modelo en Nobelio, pero se pide explicito para no quedar
        // a merced de que alla lo cambien.
        $parametros = ['page' => $pagina, 'ordering' => '-fecha_emision,-hora_emision'];
        if ($estado !== '') {
            $parametros['estado'] = $estado;
        }

        $documentos = [];
        $total = 0;
        $hayAnterior = false;
        $haySiguiente = false;

        $respuesta = $nobelio->consumoGet('api/documentos/documento/', $parametros);
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");
        } else {
            $datos = $respuesta['datos'];
            $documentos = $datos['results'] ?? [];
            $total = (int) ($datos['count'] ?? 0);
            $hayAnterior = !empty($datos['previous']);
            $haySiguiente = !empty($datos['next']);
        }

        return $this->render('nobelio/documento/lista.html.twig', [
            'form' => $form->createView(),
            'documentos' => $documentos,
            'total' => $total,
            'pagina' => $pagina,
            'hayAnterior' => $hayAnterior,
            'haySiguiente' => $haySiguiente,
        ]);
    }

    /**
     * Ficha del documento con todo lo que trae el API.
     *
     * El retrieve devuelve el serializer completo —adquiriente, lineas con sus
     * impuestos y errores, todo anidado—, asi que basta una sola peticion. El
     * id es un UUID, no un serial como el del emisor, y se concatena a la url
     * del API: el requirement lo acota a esa forma.
     */
    #[Route('/nobelio/documento/detalle/{id}', name: 'nobelio_documento_detalle', requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function detalle(Nobelio $nobelio, string $id): Response
    {
        $respuesta = $nobelio->consumoGet("api/documentos/documento/{$id}/");
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");

            return $this->redirectToRoute('nobelio_documento_lista');
        }

        $documento = $respuesta['datos'];

        return $this->render('nobelio/documento/detalle.html.twig', [
            'documento' => $documento,
            'adquiriente' => $documento['adquiriente'] ?? [],
            'detalles' => $documento['detalles'] ?? [],
            'errores' => $documento['errores'] ?? [],
        ]);
    }

    /**
     * Descarga un archivo del documento: el XML firmado o el AttachedDocument.
     *
     * Los dos endpoints devuelven archivo y no JSON, asi que van por
     * consumoArchivo(); el contenido se recibe entero en memoria —un XML UBL
     * son unas decenas de KB— y se reemite como descarga. Cual se pide va en la
     * ruta y acotado por el requirement, igual que en accion(): se concatena a
     * la url del API y uno libre dejaria construir cualquier ruta.
     */
    #[Route('/nobelio/documento/descargar/{archivo}/{id}', name: 'nobelio_documento_descargar',
        requirements: ['archivo' => 'xml|attached', 'id' => '[0-9a-fA-F-]{36}'])]
    public function descargar(Nobelio $nobelio, string $archivo, string $id): Response
    {
        // Ninguno de los dos existe antes de firmar —el attached envuelve el
        // XML firmado—; Nobelio responde 400 explicando por que y ese es el
        // mensaje que sale.
        $respuesta = $nobelio->consumoArchivo("api/documentos/documento/{$id}/{$archivo}/");
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");

            return $this->redirectToRoute('nobelio_documento_lista');
        }

        $descarga = new Response($respuesta['contenido'], Response::HTTP_OK, [
            'Content-Type' => $respuesta['tipo'],
        ]);
        $descarga->headers->set('Content-Disposition', $descarga->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            // El nombre lo pone Nobelio con el numero del documento ("FE1.xml",
            // "adFE1.xml"); el id solo es el respaldo por si no viniera.
            $respuesta['nombre'] !== '' ? $respuesta['nombre'] : "{$archivo}-{$id}.xml",
        ));

        return $descarga;
    }

    /**
     * Ejecuta sobre el documento la accion que toca en su estado.
     *
     * La accion va en la ruta y no en el cuerpo para que las unicas admitidas
     * sean las del requirement: el id se concatena a la url del API y una
     * accion libre dejaria construir cualquier ruta.
     */
    #[Route('/nobelio/documento/accion/{accion}', name: 'nobelio_documento_accion', methods: ['POST'], requirements: ['accion' => 'emitir|enviar|actualizar-estado'])]
    public function accion(Request $request, Nobelio $nobelio, string $accion): Response
    {
        $id = (string) $request->request->get('id', '');

        if (!$this->isCsrfTokenValid('acciones-documento', (string) $request->request->get('_token'))) {
            Mensajes::error('La petición no es válida.');
        } elseif ($id === '') {
            Mensajes::error('No se indicó sobre qué documento actuar.');
        } else {
            // Nobelio comprueba el estado y responde 400 explicando por que no
            // se puede ("El documento ya está firmado", etc.); ese es el
            // mensaje que se muestra.
            $respuesta = $nobelio->consumoPost("api/documentos/documento/{$id}/{$accion}/");
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                // Las tres acciones devuelven el estado en que queda; las que
                // hablan con la DIAN añaden su descripcion, que es lo que
                // explica un rechazo.
                $datos = $respuesta['datos'];
                $mensaje = "Documento en estado '" . ($datos['estado'] ?? '') . "'.";
                if (!empty($datos['descripcion'])) {
                    $mensaje .= " DIAN: {$datos['descripcion']}";
                }
                Mensajes::success($mensaje);
            }
        }

        return $this->redirectToRoute('nobelio_documento_lista');
    }

    /**
     * Arma la notificacion al adquiriente y marca el documento como notificado.
     *
     * No cabe en accion() aunque tambien sea un POST sobre el documento: aquella
     * construye su mensaje con el estado que devuelven las acciones del ciclo
     * DIAN, y esta responde otra cosa —a quien va, que archivo y de que tamaño—.
     *
     * El endpoint es multipart y acepta un PDF y adjuntos, todos opcionales;
     * aqui se llama sin ninguno, que es lo que hace viajar solo el
     * AttachedDocument. Un POST con cuerpo JSON vacio le sirve igual.
     */
    #[Route('/nobelio/documento/notificar', name: 'nobelio_documento_notificar', methods: ['POST'])]
    public function notificar(Request $request, Nobelio $nobelio): Response
    {
        $id = (string) $request->request->get('id', '');

        if (!$this->isCsrfTokenValid('acciones-documento', (string) $request->request->get('_token'))) {
            Mensajes::error('La petición no es válida.');
        } elseif ($id === '') {
            Mensajes::error('No se indicó qué documento notificar.');
        } else {
            // Nobelio solo notifica lo que la DIAN acepto y exige que el
            // adquiriente tenga correo; si no, responde 400 explicando cual de
            // las dos falta y ese es el mensaje que se muestra.
            $respuesta = $nobelio->consumoPost("api/documentos/documento/{$id}/notificar/");
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                // Con ?descargar=1 el endpoint devuelve el paquete en vez del
                // resumen, asi que las claves podrian no venir; se leen con
                // respaldo en vez de darlas por hechas.
                $datos = $respuesta['datos'];
                $destinatario = $datos['destinatario'] ?? '';
                // El codigo de envio es con lo que se rastrea el documento en
                // Nobelio; el nombre del archivo no identifica nada.
                $codigoEnvio = $datos['codigo_envio'] ?? '';

                $mensaje = $destinatario !== ''
                    ? "Paquete armado para {$destinatario}"
                    : 'Paquete armado';
                $mensaje .= $codigoEnvio !== '' ? " (envío {$codigoEnvio})." : '.';
                // El propio Nobelio avisa de que el correo todavia no sale, que
                // es justo lo que no se puede dar por hecho al ver "notificado".
                if (!empty($datos['detalle'])) {
                    $mensaje .= " {$datos['detalle']}";
                }
                Mensajes::warning($mensaje);
            }
        }

        return $this->redirectToRoute('nobelio_documento_lista');
    }

    #[Route('/nobelio/documento/eliminar', name: 'nobelio_documento_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Nobelio $nobelio): Response
    {
        $id = (string) $request->request->get('id', '');

        if (!$this->isCsrfTokenValid('acciones-documento', (string) $request->request->get('_token'))) {
            Mensajes::error('La petición de borrado no es válida.');
        } elseif ($id === '') {
            Mensajes::error('No se indicó qué documento eliminar.');
        } else {
            // Nobelio solo deja borrar lo que la DIAN no ha validado; si el
            // documento ya esta aceptado responde 400 explicando por que, y ese
            // es el mensaje que se muestra.
            $respuesta = $nobelio->consumoDelete("api/documentos/documento/{$id}/");
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                Mensajes::success('Documento eliminado.');
            }
        }

        return $this->redirectToRoute('nobelio_documento_lista');
    }

    #[Route('/nobelio/documento/errores/{id}', name: 'nobelio_documento_errores')]
    public function errores(Nobelio $nobelio, string $id): Response
    {
        // Se abre en ventana emergente desde la ficha del emisor, asi que un
        // fallo no redirige a ningun sitio: se muestra el mensaje en la propia
        // ventana y la tabla queda vacia.
        $documento = [];
        $errores = [];

        $respuesta = $nobelio->consumoGet("api/documentos/documento/{$id}/");
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");
        } else {
            $documento = $respuesta['datos'];
            $errores = $documento['errores'] ?? [];
        }

        return $this->render('nobelio/documento/errores.html.twig', [
            'documento' => $documento,
            'errores' => $errores,
        ]);
    }
}
