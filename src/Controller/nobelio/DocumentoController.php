<?php
namespace App\Controller\nobelio;

use App\Utilidades\Mensajes;
use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

        $parametros = ['page' => $pagina];
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

    #[Route('/nobelio/documento/eliminar', name: 'nobelio_documento_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Nobelio $nobelio): Response
    {
        $id = (string) $request->request->get('id', '');

        if (!$this->isCsrfTokenValid('eliminar-documento', (string) $request->request->get('_token'))) {
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
