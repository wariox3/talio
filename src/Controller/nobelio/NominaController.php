<?php
namespace App\Controller\nobelio;

use App\Utilidades\Mensajes;
use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class NominaController extends AbstractController
{
    // La nomina reusa el catalogo DocumentoEstado, pero no todo el ciclo: su
    // emision firma de una (no hay paso intermedio), asi que nunca queda en
    // "generado" y ese estado no se ofrece como filtro.
    private const ESTADOS = [
        'Todos' => '',
        'Borrador' => 'borrador',
        'Firmado' => 'firmado',
        'Enviado a la DIAN' => 'enviado',
        'Aceptado por la DIAN' => 'aceptado',
        'Rechazado por la DIAN' => 'rechazado',
    ];

    // Los dos documentos que emite el servicio de nomina: el soporte de pago y
    // la nota que lo ajusta. Son los codigos DIAN del TipoXML de Nobelio.
    private const TIPOS = [
        'Todos' => '',
        'Nómina' => '102',
        'Nota de ajuste' => '103',
    ];

    #[Route('/nobelio/nomina/lista', name: 'nobelio_nomina_lista')]
    public function lista(Request $request, Nobelio $nobelio): Response
    {
        $form = $this->createFormBuilder()
            ->add('estado', ChoiceType::class, ['required' => false, 'choices' => self::ESTADOS])
            ->add('tipo', ChoiceType::class, ['required' => false, 'choices' => self::TIPOS])
            ->add('search', TextType::class, ['required' => false])
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->add('btnAnterior', SubmitType::class, ['label' => 'Anterior'])
            ->add('btnSiguiente', SubmitType::class, ['label' => 'Siguiente'])
            ->getForm();
        $form->handleRequest($request);

        // La pagina viaja en un hidden propio, fuera del form, para poder
        // recalcularla despues de handleRequest sin pelearse con el form.
        $pagina = max(1, (int) $request->request->get('pagina', 1));
        $estado = '';
        $tipo = '';
        $busqueda = '';

        if ($form->isSubmitted() && $form->isValid()) {
            $estado = (string) $form->get('estado')->getData();
            $tipo = (string) $form->get('tipo')->getData();
            $busqueda = trim((string) $form->get('search')->getData());
            if ($form->get('btnFiltrar')->isClicked()) {
                $pagina = 1;
            } elseif ($form->get('btnAnterior')->isClicked()) {
                $pagina = max(1, $pagina - 1);
            } elseif ($form->get('btnSiguiente')->isClicked()) {
                $pagina++;
            }
        }

        // Lo mas reciente primero. El consecutivo desempata dentro del mismo
        // dia, que es lo normal en un lote de nomina. Coincide con el orden por
        // defecto del modelo en Nobelio, pero se pide explicito para no quedar
        // a merced de que alla lo cambien.
        $parametros = ['page' => $pagina, 'ordering' => '-fecha_generacion,-consecutivo'];
        if ($estado !== '') {
            $parametros['estado'] = $estado;
        }
        if ($tipo !== '') {
            $parametros['tipo_xml'] = $tipo;
        }
        if ($busqueda !== '') {
            // El SearchFilter de la nomina mira el numero, el CUNE y el
            // documento del empleado.
            $parametros['search'] = $busqueda;
        }

        $nominas = [];
        $total = 0;
        $hayAnterior = false;
        $haySiguiente = false;

        $respuesta = $nobelio->consumoGet('api/nomina/nomina/', $parametros);
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");
        } else {
            $datos = $respuesta['datos'];
            $nominas = $datos['results'] ?? [];
            $total = (int) ($datos['count'] ?? 0);
            $hayAnterior = !empty($datos['previous']);
            $haySiguiente = !empty($datos['next']);
        }

        return $this->render('nobelio/nomina/lista.html.twig', [
            'form' => $form->createView(),
            'nominas' => $nominas,
            'total' => $total,
            'pagina' => $pagina,
            'hayAnterior' => $hayAnterior,
            'haySiguiente' => $haySiguiente,
        ]);
    }

    /**
     * Ficha de la nomina con todo lo que trae el API.
     *
     * El retrieve devuelve el serializer completo —conceptos y errores
     * anidados—, pero del empleado solo el id y el nombre, asi que la identidad
     * del trabajador se pide aparte a su endpoint. No es un dato repetido: la
     * nomina congela las condiciones con las que se emitio (sueldo, contrato,
     * lugar de trabajo) y el maestro guarda quien es y lo que vale hoy; la
     * ficha los enseña en dos bloques por eso mismo. El id es un UUID y se
     * concatena a la url del API: el requirement lo acota a esa forma.
     */
    #[Route('/nobelio/nomina/detalle/{id}', name: 'nobelio_nomina_detalle', requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function detalle(Nobelio $nobelio, string $id): Response
    {
        $respuesta = $nobelio->consumoGet("api/nomina/nomina/{$id}/");
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");

            return $this->redirectToRoute('nobelio_nomina_lista');
        }

        $nomina = $respuesta['datos'];

        // El empleado no viaja anidado, asi que va en su propia peticion. Si
        // falla, la ficha se pinta igual: lo suyo es un bloque de mas, no la
        // nomina.
        $empleado = [];
        if (!empty($nomina['empleado'])) {
            $respuestaEmpleado = $nobelio->consumoGet("api/nomina/empleado/{$nomina['empleado']}/");
            if ($respuestaEmpleado['error']) {
                Mensajes::error("Nobelio: {$respuestaEmpleado['mensaje']}");
            } else {
                $empleado = $respuestaEmpleado['datos'];
            }
        }

        return $this->render('nobelio/nomina/detalle.html.twig', [
            'nomina' => $nomina,
            'empleado' => $empleado,
            'conceptos' => $nomina['conceptos'] ?? [],
            'errores' => $nomina['errores'] ?? [],
        ]);
    }

    /**
     * Ejecuta sobre la nomina la accion que toca en su estado.
     *
     * Las dos que cambian el documento —firmar y enviar— devuelven el estado en
     * que queda, asi que comparten respuesta y salen por aqui; consultar() va
     * aparte porque no lo toca. La accion va en la ruta y no en el cuerpo para
     * que las unicas admitidas sean las del requirement: el id se concatena a
     * la url del API y una accion libre dejaria construir cualquier ruta.
     */
    #[Route('/nobelio/nomina/accion/{accion}', name: 'nobelio_nomina_accion', methods: ['POST'], requirements: ['accion' => 'emitir|enviar'])]
    public function accion(Request $request, Nobelio $nobelio, string $accion): Response
    {
        $id = (string) $request->request->get('id', '');

        if (!$this->isCsrfTokenValid('acciones-nomina', (string) $request->request->get('_token'))) {
            Mensajes::error('La petición no es válida.');
        } elseif ($id === '') {
            Mensajes::error('No se indicó sobre qué nómina actuar.');
        } else {
            // Nobelio comprueba el estado y responde 400 explicando por que no
            // se puede ("La nómina ya está firmada", etc.); ese es el mensaje
            // que se muestra.
            $respuesta = $nobelio->consumoPost("api/nomina/nomina/{$id}/{$accion}/");
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                $datos = $respuesta['datos'];
                $mensaje = "Nómina en estado '" . ($datos['estado'] ?? '') . "'.";
                // Emitir devuelve el CUNE que acaba de calcular; enviar, lo que
                // contesto la DIAN. El CUNE se recalcula en cada firma, asi que
                // el de un reintento no es el de antes.
                if (!empty($datos['cune'])) {
                    $mensaje .= " CUNE: {$datos['cune']}";
                }
                if (!empty($datos['descripcion'])) {
                    $mensaje .= " DIAN: {$datos['descripcion']}";
                }
                $mensaje .= $this->resumenDeErrores($datos);

                // Un envio con errores termina en rechazado y no es un exito
                // aunque la peticion haya ido bien.
                if (array_key_exists('es_valido', $datos) && !$datos['es_valido']) {
                    Mensajes::warning($mensaje);
                } else {
                    Mensajes::success($mensaje);
                }
            }
        }

        return $this->redirectToRoute('nobelio_nomina_lista');
    }

    /**
     * Pregunta a la DIAN por el CUNE y muestra lo que conteste.
     *
     * En Nobelio es un GET y de solo lectura: no cambia el estado de la nomina
     * —eso solo lo hace enviar()—, asi que sirve para saber que dice la DIAN,
     * no para refrescar el listado. Aqui llega por POST igual que las demas
     * acciones: viaja en el mismo formulario con su token y evita que un
     * enlace, al recorrerlo cualquier cosa que siga enlaces, dispare una
     * llamada a la DIAN.
     */
    #[Route('/nobelio/nomina/consultar', name: 'nobelio_nomina_consultar', methods: ['POST'])]
    public function consultar(Request $request, Nobelio $nobelio): Response
    {
        $id = (string) $request->request->get('id', '');

        if (!$this->isCsrfTokenValid('acciones-nomina', (string) $request->request->get('_token'))) {
            Mensajes::error('La petición no es válida.');
        } elseif ($id === '') {
            Mensajes::error('No se indicó qué nómina consultar.');
        } else {
            // Sin CUNE no hay por que preguntar y Nobelio responde 400
            // diciendolo; ese es el mensaje que se muestra.
            $respuesta = $nobelio->consumoGet("api/nomina/nomina/{$id}/consultar/");
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                $datos = $respuesta['datos'];
                $valida = !empty($datos['es_valido']);
                $mensaje = $valida
                    ? 'La DIAN da la nómina por válida.'
                    : 'La DIAN no da la nómina por válida.';
                if (!empty($datos['codigo_estado'])) {
                    $mensaje .= " Código {$datos['codigo_estado']}.";
                }
                if (!empty($datos['descripcion'])) {
                    $mensaje .= " {$datos['descripcion']}";
                }
                $mensaje .= $this->resumenDeErrores($datos);

                if ($valida) {
                    Mensajes::success($mensaje);
                } else {
                    Mensajes::warning($mensaje);
                }
            }
        }

        return $this->redirectToRoute('nobelio_nomina_lista');
    }

    /**
     * Descarga el XML firmado de la nomina.
     *
     * El endpoint devuelve archivo y no JSON, asi que va por consumoArchivo();
     * el contenido se recibe entero en memoria —un XML de nomina son unas
     * decenas de KB— y se reemite como descarga. No hay attached: la nomina no
     * es UBL y la DIAN no le devuelve ApplicationResponse que envolver.
     */
    #[Route('/nobelio/nomina/descargar/{id}', name: 'nobelio_nomina_descargar', requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function descargar(Nobelio $nobelio, string $id): Response
    {
        // Antes de firmar no existe; Nobelio responde 400 diciendo que la
        // emita primero y ese es el mensaje que sale.
        $respuesta = $nobelio->consumoArchivo("api/nomina/nomina/{$id}/xml/");
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");

            return $this->redirectToRoute('nobelio_nomina_lista');
        }

        $descarga = new Response($respuesta['contenido'], Response::HTTP_OK, [
            'Content-Type' => $respuesta['tipo'],
        ]);
        $descarga->headers->set('Content-Disposition', $descarga->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            // El nombre lo pone Nobelio con el numero de la nomina
            // ("NE1.xml"); el id solo es el respaldo por si no viniera.
            $respuesta['nombre'] !== '' ? $respuesta['nombre'] : "nomina-{$id}.xml",
        ));

        return $descarga;
    }

    #[Route('/nobelio/nomina/eliminar', name: 'nobelio_nomina_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Nobelio $nobelio): Response
    {
        $id = (string) $request->request->get('id', '');

        if (!$this->isCsrfTokenValid('acciones-nomina', (string) $request->request->get('_token'))) {
            Mensajes::error('La petición de borrado no es válida.');
        } elseif ($id === '') {
            Mensajes::error('No se indicó qué nómina eliminar.');
        } else {
            // Nobelio solo deja borrar lo que la DIAN no ha aceptado; una
            // aceptada responde 400 diciendo que se corrige con una nota de
            // ajuste, y ese es el mensaje que se muestra.
            $respuesta = $nobelio->consumoDelete("api/nomina/nomina/{$id}/");
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                Mensajes::success('Nómina eliminada.');
            }
        }

        return $this->redirectToRoute('nobelio_nomina_lista');
    }

    /**
     * Los errores con los que la DIAN rechazo la nomina.
     *
     * El listado solo trae `total_errores`: las filas hay que ir a buscarlas al
     * retrieve, que es el unico que las trae anidadas. Se abre en ventana
     * emergente desde el listado, asi que un fallo no redirige a ningun sitio:
     * se muestra el mensaje en la propia ventana y la tabla queda vacia.
     */
    #[Route('/nobelio/nomina/errores/{id}', name: 'nobelio_nomina_errores', requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function errores(Nobelio $nobelio, string $id): Response
    {
        $nomina = [];
        $errores = [];

        $respuesta = $nobelio->consumoGet("api/nomina/nomina/{$id}/");
        if ($respuesta['error']) {
            Mensajes::error("Nobelio: {$respuesta['mensaje']}");
        } else {
            $nomina = $respuesta['datos'];
            $errores = $nomina['errores'] ?? [];
        }

        return $this->render('nobelio/nomina/errores.html.twig', [
            'nomina' => $nomina,
            'errores' => $errores,
        ]);
    }

    /**
     * Los errores que devuelva la DIAN, listos para pegar al mensaje.
     *
     * Llegan como una lista de textos ya redactados por la DIAN —la regla y su
     * explicacion—, asi que se muestran tal cual: son lo unico que dice por que
     * rechazo la nomina. No son los mismos que los de errores(): aquellos son
     * las filas guardadas del ultimo envio y estos, lo que acaba de contestar
     * la DIAN en esta llamada.
     */
    private function resumenDeErrores(array $datos): string
    {
        $errores = $datos['errores'] ?? [];
        if (!is_array($errores) || !$errores) {
            return '';
        }

        return ' ' . implode(' ', array_map('strval', $errores));
    }
}
