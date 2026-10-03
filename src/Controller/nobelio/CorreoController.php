<?php
namespace App\Controller\nobelio;

use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Correos que llegan al buzon de recepcion de los emisores
 * (<nit>@recepcion.rededoc.co): las facturas de proveedores. Solo lectura:
 * los crea el Email Worker y los procesa el worker de Celery en Nobelio.
 */
class CorreoController extends AbstractController
{
    // Los de Correo.Estado en Nobelio.
    private const ESTADOS = [
        'Todos' => '',
        'Pendiente' => 'pendiente',
        'Procesado' => 'procesado',
        'Sin documentos' => 'sin_documentos',
        'Error' => 'error',
        'Empresa desconocida' => 'empresa_desconocida',
        'Confirmación de reenvío' => 'confirmacion_reenvio',
    ];

    #[Route('/nobelio/correo/lista', name: 'nobelio_correo_lista')]
    public function lista(Request $request, Nobelio $nobelio): Response
    {
        $form = $this->createFormBuilder()
            ->add('estado', ChoiceType::class, ['required' => false, 'choices' => self::ESTADOS])
            ->add('search', TextType::class, ['required' => false])
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->add('btnAnterior', SubmitType::class, ['label' => 'Anterior'])
            ->add('btnSiguiente', SubmitType::class, ['label' => 'Siguiente'])
            ->getForm();
        $form->handleRequest($request);

        // La pagina viaja en un hidden propio, fuera del form, igual que en
        // la nomina.
        $pagina = max(1, (int) $request->request->get('pagina', 1));
        $estado = '';
        $busqueda = '';

        if ($form->isSubmitted() && $form->isValid()) {
            $estado = (string) $form->get('estado')->getData();
            $busqueda = trim((string) $form->get('search')->getData());
            if ($form->get('btnFiltrar')->isClicked()) {
                $pagina = 1;
            } elseif ($form->get('btnAnterior')->isClicked()) {
                $pagina = max(1, $pagina - 1);
            } elseif ($form->get('btnSiguiente')->isClicked()) {
                $pagina++;
            }
        }

        // Lo ultimo que llego primero; el id desempata. Es el orden del modelo,
        // pedido explicito para no depender de el.
        $parametros = ['page' => $pagina, 'ordering' => '-recibido_en,-id'];
        if ($estado !== '') {
            $parametros['estado'] = $estado;
        }
        if ($busqueda !== '') {
            // El SearchFilter mira el remitente, el asunto y el Message-ID.
            $parametros['search'] = $busqueda;
        }

        $correos = [];
        $total = 0;
        $hayAnterior = false;
        $haySiguiente = false;

        $respuesta = $nobelio->consumoGet('api/recepcion/correo/', $parametros);
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
        } else {
            $datos = $respuesta['datos'];
            $correos = $datos['results'] ?? [];
            $total = (int) ($datos['count'] ?? 0);
            $hayAnterior = !empty($datos['previous']);
            $haySiguiente = !empty($datos['next']);
        }

        return $this->render('nobelio/correo/lista.html.twig', [
            'form' => $form->createView(),
            'correos' => $correos,
            'total' => $total,
            'pagina' => $pagina,
            'hayAnterior' => $hayAnterior,
            'haySiguiente' => $haySiguiente,
        ]);
    }

    /**
     * Ficha del correo. A diferencia de documentos y nominas, el id del correo
     * es un entero (BigAutoField), no un UUID: el requirement lo acota a
     * digitos porque se concatena a la url del API.
     */
    #[Route('/nobelio/correo/detalle/{id}', name: 'nobelio_correo_detalle', requirements: ['id' => '\d+'])]
    public function detalle(Nobelio $nobelio, string $id): Response
    {
        $respuesta = $nobelio->consumoGet("api/recepcion/correo/{$id}/");
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            return $this->redirectToRoute('nobelio_correo_lista');
        }

        return $this->render('nobelio/correo/detalle.html.twig', [
            'correo' => $respuesta['datos'],
            'documentos' => $respuesta['datos']['documentos'] ?? [],
        ]);
    }

    /**
     * Borra el correo y su MIME en R2. Nobelio solo deja borrar los que no
     * tienen emisor —los de un emisor son informacion fiscal suya— y responde
     * 400 diciendolo; ese es el mensaje que se muestra. Igual que el detalle,
     * el id es un entero y se concatena a la url del API.
     */
    #[Route('/nobelio/correo/eliminar', name: 'nobelio_correo_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Nobelio $nobelio): Response
    {
        $id = (string) $request->request->get('id', '');

        if (!$this->isCsrfTokenValid('acciones-correo', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La petición de borrado no es válida.');
        } elseif (!ctype_digit($id)) {
            $this->addFlash('danger', 'No se indicó qué correo eliminar.');
        } else {
            $respuesta = $nobelio->consumoDelete("api/recepcion/correo/{$id}/");
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $this->addFlash('success', 'Correo eliminado.');
            }
        }

        return $this->redirectToRoute('nobelio_correo_lista');
    }
}
