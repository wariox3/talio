<?php
namespace App\Controller\nobelio;

use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Documentos recibidos: las facturas y notas que los proveedores les mandan a
 * los emisores por el buzon de recepcion. Los crea Nobelio al procesar los
 * correos; aqui se consultan, se bajan sus archivos y se les registran los
 * eventos RADIAN del adquiriente (030 a 033).
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

    // EstadoRadian en Nobelio: el ultimo evento que la DIAN registro sobre la
    // factura. Es el filtro radian_estado de la lista.
    public const ESTADOS_RADIAN = [
        'sin_eventos' => 'Sin eventos',
        'acuse' => 'Acuse de recibo (030)',
        'recibo' => 'Recibo del bien o servicio (032)',
        'aceptada' => 'Aceptación expresa (033)',
        'reclamada' => 'Reclamo (031)',
    ];

    // Filtro estado_recepcion. Nobelio lo recibe como true/false; el vacio no
    // manda el parametro y trae los dos. La lista arranca en false.
    public const ESTADO_RECEPCION = [
        'Todos' => '',
        'Sí' => 'true',
        'No' => 'false',
    ];

    // Codigo DIAN de la forma de pago (cac:PaymentMeans/cbc:ID), como lo
    // devuelve Nobelio. RADIAN solo admite eventos sobre facturas a credito.
    public const FORMAS_PAGO = [
        '1' => 'Contado',
        '2' => 'Crédito',
    ];

    // EstadoVerificacion en Nobelio: que dijo la DIAN al consultar el CUFE.
    public const ESTADOS_VERIFICACION = [
        'pendiente' => 'Pendiente',
        'valido' => 'Válido',
        'invalido' => 'No válido',
        'no_verificable' => 'No verificable',
        'error' => 'Error al consultar',
    ];

    // Los eventos del adquiriente que se pueden pedir: codigo de EventoRadian
    // => nombre visible. Nobelio exige el orden 030 -> 032 -> 033 o 031.
    public const EVENTOS = [
        '030' => '030 · Acuse de recibo de la factura',
        '031' => '031 · Reclamo de la factura',
        '032' => '032 · Recibo del bien o prestación del servicio',
        '033' => '033 · Aceptación expresa',
    ];

    // El siguiente evento que se puede pedir segun radian_estado (el ultimo
    // registrado): codigo => nombre corto de la accion. Nobelio exige el orden
    // 030 -> 032 -> 033 o 031; aceptada y reclamada ya no admiten mas. Lo usan
    // la ventana del evento y las acciones de la lista y la ficha.
    public const EVENTOS_SIGUIENTES = [
        'sin_eventos' => ['030' => 'Acuse 030'],
        'acuse' => ['032' => 'Recibo 032'],
        'recibo' => ['033' => 'Aceptación 033', '031' => 'Reclamo 031'],
    ];

    // Tope de la carga manual, el mismo de Nobelio (MAXIMO_CARGA).
    private const MAXIMO_CARGA = 10 * 1024 * 1024;

    // Los que llevan la persona que recibio la factura o la mercancia.
    private const EVENTOS_CON_PERSONA = ['030', '032'];

    #[Route('/nobelio/recepcion/lista', name: 'nobelio_recepcion_lista')]
    public function lista(Request $request, Nobelio $nobelio): Response
    {
        // El correo puede llegar por GET desde la ficha del correo; despues
        // viaja en el form como los demas filtros.
        $form = $this->createFormBuilder(['correo' => $request->query->get('correo'), 'estado_recepcion' => 'false'])
            ->add('estado_recepcion', ChoiceType::class, ['required' => false, 'choices' => self::ESTADO_RECEPCION])
            ->add('documento_tipo', ChoiceType::class, ['required' => false, 'choices' => ['Todos' => ''] + array_flip(self::TIPOS)])
            ->add('radian_estado', ChoiceType::class, ['required' => false, 'choices' => ['Todos' => ''] + array_flip(self::ESTADOS_RADIAN)])
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
        $filtros = [
            'estado_recepcion' => 'false',
            'correo' => trim((string) $form->get('correo')->getData()),
        ];

        if ($form->isSubmitted() && $form->isValid()) {
            $desde = $form->get('desde')->getData();
            $hasta = $form->get('hasta')->getData();
            $filtros = [
                'estado_recepcion' => (string) $form->get('estado_recepcion')->getData(),
                'documento_tipo' => (string) $form->get('documento_tipo')->getData(),
                'radian_estado' => (string) $form->get('radian_estado')->getData(),
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
     * Ventana para cargar a mano el ZIP que mando un proveedor (AttachedDocument
     * y PDF) o el XML suelto, sin pasar por el correo.
     *
     * Nobelio lo procesa en el momento con la misma lectura que los correos y
     * responde que paso con cada documento: creados, repetidos (el CUFE ya
     * estaba registrado; no se duplica) y rechazados (de otro NIT receptor
     * que el del emisor elegido). 400 si el archivo no trae documentos o
     * todos son de otro receptor, y ese es el mensaje que sale. La carga
     * queda como un correo con origen "carga".
     *
     * Sin redirect al terminar: el resultado se pinta en la misma ventana.
     */
    #[Route('/nobelio/recepcion/cargar', name: 'nobelio_recepcion_cargar')]
    public function cargar(Request $request, Nobelio $nobelio): Response
    {
        $emisores = [];
        $respuestaEmisores = $nobelio->consumoGetTodos('api/emisores/emisor/');
        if ($respuestaEmisores['error']) {
            $this->addFlash('danger', "Nobelio: {$respuestaEmisores['mensaje']}");
        } else {
            foreach ($respuestaEmisores['datos'] as $emisor) {
                $emisores["{$emisor['razon_social']} ({$emisor['numero_identificacion']})"] = (string) $emisor['id'];
            }
        }

        // Con un solo emisor —lo normal con una API Key de cliente— ya va
        // elegido.
        $form = $this->createFormBuilder(['emisor' => count($emisores) === 1 ? reset($emisores) : null])
            ->add('emisor', ChoiceType::class, ['choices' => $emisores, 'placeholder' => count($emisores) === 1 ? false : ''])
            ->add('archivo', FileType::class, ['attr' => ['accept' => '.zip,.xml']])
            ->add('btnCargar', SubmitType::class, ['label' => 'Cargar'])
            ->getForm();
        $form->handleRequest($request);

        $resultado = null;
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile|null $archivo */
            $archivo = $form->get('archivo')->getData();
            // Los mismos limites que Nobelio, para no subir de balde lo que va
            // a rechazar. Por extension y no por tipo MIME: un ZIP llega con
            // tipos distintos segun el navegador.
            $extension = strtolower(pathinfo((string) $archivo?->getClientOriginalName(), PATHINFO_EXTENSION));
            if ($archivo === null || !$archivo->isValid()) {
                $this->addFlash('danger', 'No se recibió el archivo.' . ($archivo ? ' ' . $archivo->getErrorMessage() : ''));
            } elseif (!in_array($extension, ['zip', 'xml'], true)) {
                $this->addFlash('danger', 'Solo se aceptan archivos .zip o .xml.');
            } elseif ($archivo->getSize() > self::MAXIMO_CARGA) {
                $this->addFlash('danger', 'El archivo pesa más de 10 MB.');
            } else {
                $respuesta = $nobelio->consumoMultipart(
                    'api/recepcion/documento/cargar/',
                    ['emisor' => $form->get('emisor')->getData()],
                    ['archivo' => [$archivo->getPathname(), $archivo->getClientOriginalName()]],
                );
                if ($respuesta['error']) {
                    $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
                } else {
                    $resultado = $respuesta['datos'];
                    $creados = count($resultado['creados'] ?? []);
                    $repetidos = count($resultado['repetidos'] ?? []);
                    $rechazados = count($resultado['rechazados'] ?? []);
                    $this->addFlash($creados > 0 ? 'success' : 'warning', sprintf(
                        '%d creado%s, %d repetido%s y %d rechazado%s.%s',
                        $creados, $creados === 1 ? '' : 's',
                        $repetidos, $repetidos === 1 ? '' : 's',
                        $rechazados, $rechazados === 1 ? '' : 's',
                        $creados > 0 ? ' Recargue la lista de recepción para verlos.' : '',
                    ));
                }
            }
        } elseif ($form->isSubmitted()) {
            // ui.campo() no pinta los errores del form: salen como aviso.
            foreach ($form->getErrors(true) as $error) {
                $this->addFlash('danger', $error->getMessage());
            }
        }

        return $this->render('nobelio/recepcion/cargar.html.twig', [
            'form' => $form->createView(),
            'resultado' => $resultado,
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

        // Los eventos RADIAN del documento, lo mas reciente primero. Son
        // pocos (uno por codigo, salvo los rechazados que se repiten), pero
        // el listado pagina y se recorre entero. Si falla, la ficha se pinta
        // igual.
        $eventos = [];
        $respuestaEventos = $nobelio->consumoGetTodos('api/recepcion/evento/', ['documento' => $id, 'ordering' => '-creado_en']);
        if ($respuestaEventos['error']) {
            $this->addFlash('danger', "Nobelio: {$respuestaEventos['mensaje']}");
        } else {
            $eventos = $respuestaEventos['datos'];
        }

        return $this->render('nobelio/recepcion/detalle.html.twig', [
            'documento' => $respuesta['datos'],
            'adjuntos' => $adjuntos,
            'eventos' => $eventos,
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

            return $this->volverAlOrigen($request);
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

    /**
     * Consulta el CUFE del documento en la DIAN en ese momento: para repetir
     * una verificacion que quedo en error o no verificable (por ejemplo,
     * despues de cargar el certificado del emisor) o para confirmar un estado.
     * Sin "valido" no se le pueden registrar eventos RADIAN.
     *
     * Nobelio responde 200 aunque la DIAN no conteste: el documento queda en
     * error con el detalle, y lo que dice como quedo es verificacion_estado.
     * Se pide desde la ficha y vuelve a ella; el id va en la ruta, acotado a
     * UUID, porque se concatena a la url del API.
     */
    #[Route('/nobelio/recepcion/verificar/{id}', name: 'nobelio_recepcion_verificar', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function verificar(Request $request, Nobelio $nobelio, string $id): Response
    {
        if (!$this->isCsrfTokenValid('verificar-documento', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La petición no es válida.');
        } else {
            $respuesta = $nobelio->consumoPost("api/recepcion/documento/{$id}/verificar/");
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $datos = $respuesta['datos'];
                $estado = (string) ($datos['verificacion_estado'] ?? '');
                $mensaje = 'Verificación: ' . (self::ESTADOS_VERIFICACION[$estado] ?? $estado) . '.';
                if (!empty($datos['verificacion_descripcion'])) {
                    $mensaje .= " DIAN: {$datos['verificacion_descripcion']}";
                }
                $this->addFlash($estado === 'valido' ? 'success' : 'warning', $mensaje);
            }
        }

        return $this->redirectToRoute('nobelio_recepcion_detalle', ['id' => $id]);
    }

    /**
     * Elimina el documento recibido con sus archivos.
     *
     * Solo sin eventos RADIAN, en ningun estado: uno registrado esta en RADIAN
     * y uno rechazado guarda la respuesta de la DIAN. Nobelio lo comprueba y
     * responde 400 diciendolo. El correo se queda; si era una carga manual y
     * este su unico documento, Nobelio la borra tambien. Si el almacenamiento
     * falla responde 502 y no borra nada, asi que se puede repetir.
     *
     * Se pide desde la ficha o desde la lista (con volver=lista): si sale
     * bien se va a la lista, porque el documento ya no existe; si no, se
     * vuelve a donde se pidio. El id va en la ruta, acotado a UUID, porque se
     * concatena a la url del API.
     */
    #[Route('/nobelio/recepcion/eliminar/{id}', name: 'nobelio_recepcion_eliminar', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function eliminar(Request $request, Nobelio $nobelio, string $id): Response
    {
        $volver = $request->request->get('volver') === 'lista'
            ? $this->redirectToRoute('nobelio_recepcion_lista')
            : $this->redirectToRoute('nobelio_recepcion_detalle', ['id' => $id]);

        if (!$this->isCsrfTokenValid('eliminar-documento', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La petición no es válida.');

            return $volver;
        }

        $respuesta = $nobelio->consumoDelete("api/recepcion/documento/{$id}/");
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            return $volver;
        }

        $this->addFlash('success', 'Documento eliminado.');

        return $this->redirectToRoute('nobelio_recepcion_lista');
    }

    /**
     * Ventana para pedir un evento RADIAN sobre el documento.
     *
     * Nobelio responde 201 con el evento pendiente y lo envia a la DIAN en
     * segundo plano: el resultado se ve despues en la pestaña de eventos de la
     * ficha. Las reglas —solo facturas de venta verificadas como validas, el
     * orden 030 -> 032 -> 033 o 031, el plazo de 3 dias habiles desde el 032—
     * las comprueba Nobelio, y su 400 es el mensaje que sale.
     *
     * Solo se ofrece el evento siguiente segun radian_estado
     * (EVENTOS_SIGUIENTES), y nada en facturas de contado o que no son de
     * venta. Con ?codigo= el form abre con ese evento elegido (las acciones de
     * la lista y la ficha lo usan). La persona solo viaja en el 030 y el 032, y sin ella
     * Nobelio usa la configurada en el emisor; el concepto, solo en el 031,
     * donde es obligatorio. El id es el UUID del documento y se concatena a la
     * url del API: el requirement lo acota.
     */
    #[Route('/nobelio/recepcion/evento-nuevo/{id}', name: 'nobelio_recepcion_evento_nuevo', requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function eventoNuevo(Request $request, Nobelio $nobelio, string $id): Response
    {
        $documento = [];
        $respuestaDocumento = $nobelio->consumoGet("api/recepcion/documento/{$id}/");
        if ($respuestaDocumento['error']) {
            $this->addFlash('danger', "Nobelio: {$respuestaDocumento['mensaje']}");
        } else {
            $documento = $respuestaDocumento['datos'];
        }

        // Los dos catalogos llenan las listas del form. Si no cargan, el form
        // se pinta igual: el 030, el 032 y el 033 no los necesitan.
        $conceptos = $this->opcionesCatalogo($nobelio, 'api/catalogos/concepto-reclamo/', 'codigo');
        $tiposIdentificacion = $this->opcionesCatalogo($nobelio, 'api/catalogos/tipo-identificacion/', 'id');

        // Solo el evento siguiente. RADIAN no admite eventos sobre facturas de
        // contado ni sobre lo que no es factura de venta (LGC62).
        $permitidos = [];
        if (($documento['documento_tipo'] ?? '') === 'factura_venta' && (string) ($documento['forma_pago'] ?? '') !== '1') {
            $siguientes = self::EVENTOS_SIGUIENTES[$documento['radian_estado'] ?? ''] ?? [];
            $permitidos = array_intersect_key(self::EVENTOS, $siguientes);
        }

        // El evento puede llegar elegido por GET, como desde las acciones de
        // la lista. Si solo hay uno, ese.
        $codigo = (string) $request->query->get('codigo', '');
        if (!isset($permitidos[$codigo])) {
            $codigo = count($permitidos) === 1 ? (string) array_key_first($permitidos) : null;
        }

        $form = $this->createFormBuilder(['codigo' => $codigo])
            ->add('codigo', ChoiceType::class, ['choices' => array_flip($permitidos)])
            ->add('concepto_reclamo', ChoiceType::class, ['required' => false, 'choices' => $conceptos, 'placeholder' => ''])
            ->add('persona_tipo_identificacion', ChoiceType::class, ['required' => false, 'choices' => $tiposIdentificacion, 'placeholder' => ''])
            ->add('persona_numero_identificacion', TextType::class, ['required' => false, 'attr' => ['maxlength' => 20]])
            ->add('persona_nombres', TextType::class, ['required' => false, 'attr' => ['maxlength' => 100]])
            ->add('persona_apellidos', TextType::class, ['required' => false, 'attr' => ['maxlength' => 100]])
            ->add('persona_cargo', TextType::class, ['required' => false, 'attr' => ['maxlength' => 100]])
            ->add('persona_area', TextType::class, ['required' => false, 'attr' => ['maxlength' => 100]])
            ->add('btnSolicitar', SubmitType::class, ['label' => 'Solicitar'])
            ->getForm();
        $form->handleRequest($request);

        if ($permitidos && $form->isSubmitted() && $form->isValid()) {
            $codigo = (string) $form->get('codigo')->getData();
            $datos = ['codigo' => $codigo];

            if ($codigo === '031') {
                // Se manda aunque venga vacio: que sea Nobelio quien diga que
                // el reclamo lo exige.
                $datos['concepto_reclamo'] = (string) $form->get('concepto_reclamo')->getData();
            }

            if (in_array($codigo, self::EVENTOS_CON_PERSONA, true)) {
                $persona = [
                    'tipo_identificacion' => $form->get('persona_tipo_identificacion')->getData(),
                    'numero_identificacion' => trim((string) $form->get('persona_numero_identificacion')->getData()),
                    'nombres' => trim((string) $form->get('persona_nombres')->getData()),
                    'apellidos' => trim((string) $form->get('persona_apellidos')->getData()),
                    'cargo' => trim((string) $form->get('persona_cargo')->getData()),
                    'area' => trim((string) $form->get('persona_area')->getData()),
                ];
                // Toda vacia es usar la del emisor. A medias se manda tal cual
                // y Nobelio responde que campo falta.
                if (array_filter($persona, fn ($valor) => $valor !== null && $valor !== '')) {
                    $persona['tipo_identificacion'] = $persona['tipo_identificacion'] !== null
                        ? (int) $persona['tipo_identificacion']
                        : null;
                    $datos['persona'] = $persona;
                }
            }

            $respuesta = $nobelio->consumoPost("api/recepcion/documento/{$id}/evento/", $datos);
            if ($respuesta['error']) {
                // Sin redirect: asi el form conserva lo digitado para corregir.
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $this->addFlash('success', sprintf(
                    'Evento %s solicitado; se envía a la DIAN en segundo plano.',
                    $respuesta['datos']['numero'] ?? '',
                ));

                // La ventana se cierra y recarga la ficha que la abrio, que es
                // donde sale el aviso. No pasa por base_sin_menu para no
                // consumir el aviso aqui.
                return $this->render('nobelio/recepcion/evento_solicitado.html.twig', ['id' => $id]);
            }
        }

        return $this->render('nobelio/recepcion/evento_nuevo.html.twig', [
            'form' => $form->createView(),
            'documento' => $documento,
            'permitidos' => $permitidos,
        ]);
    }

    /**
     * Reenvia a la DIAN un evento que quedo en error, con el mismo XML y el
     * mismo CUDE. Nobelio solo lo acepta en error (si no, 400 explicandolo) y
     * responde 200 aunque vuelva a fallar: lo que dice como quedo es el estado
     * del evento. Se pide desde la ficha del documento, que llega en un hidden
     * para volver a ella; el id del evento va en la ruta, acotado a UUID.
     */
    #[Route('/nobelio/recepcion/evento/enviar/{id}', name: 'nobelio_recepcion_evento_enviar', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function eventoEnviar(Request $request, Nobelio $nobelio, string $id): Response
    {
        $documento = (string) $request->request->get('documento', '');

        if (!$this->isCsrfTokenValid('enviar-evento', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La petición no es válida.');
        } else {
            $respuesta = $nobelio->consumoPost("api/recepcion/evento/{$id}/enviar/");
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $datos = $respuesta['datos'];
                $mensaje = "Evento {$datos['numero']} reenviado. Estado '" . ($datos['estado'] ?? '') . "'.";
                if (!empty($datos['respuesta_descripcion'])) {
                    $mensaje .= " DIAN: {$datos['respuesta_descripcion']}";
                }
                $this->addFlash(($datos['estado'] ?? '') === 'registrado' ? 'success' : 'warning', $mensaje);
            }
        }

        return $this->volverAlDocumento($documento);
    }

    /**
     * Elimina un evento rechazado con sus archivos. Solo los rechazados: la
     * DIAN no los registro, asi que no existen en RADIAN; uno registrado esta
     * alla y uno pendiente o en error puede estar en camino. Nobelio lo
     * comprueba y responde 400 diciendolo. Su numero no se libera.
     *
     * Se pide desde la pestaña de eventos de la ficha, que llega en un hidden
     * para volver a ella; el id del evento va en la ruta, acotado a UUID.
     */
    #[Route('/nobelio/recepcion/evento/eliminar/{id}', name: 'nobelio_recepcion_evento_eliminar', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function eventoEliminar(Request $request, Nobelio $nobelio, string $id): Response
    {
        $documento = (string) $request->request->get('documento', '');

        if (!$this->isCsrfTokenValid('eliminar-evento', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La petición no es válida.');
        } else {
            $respuesta = $nobelio->consumoDelete("api/recepcion/evento/{$id}/");
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $this->addFlash('success', 'Evento eliminado.');
            }
        }

        return $this->volverAlDocumento($documento);
    }

    /**
     * Baja el XML de un evento: xml (el evento firmado, como se envio) o
     * respuesta (el ApplicationResponse de la DIAN). Si aun no lo hay, Nobelio
     * responde 400 diciendolo y se vuelve a la ficha. El archivo va en la ruta
     * acotado por el requirement, porque se concatena a la url del API.
     */
    #[Route('/nobelio/recepcion/evento/descargar/{archivo}/{id}', name: 'nobelio_recepcion_evento_descargar', requirements: ['archivo' => 'xml|respuesta', 'id' => '[0-9a-fA-F-]{36}'])]
    public function eventoDescargar(Request $request, Nobelio $nobelio, string $archivo, string $id): Response
    {
        $respuesta = $nobelio->consumoArchivo("api/recepcion/evento/{$id}/{$archivo}/");
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            return $this->volverAlOrigen($request);
        }

        $descarga = new Response($respuesta['contenido'], Response::HTTP_OK, [
            'Content-Type' => $respuesta['tipo'],
        ]);
        $descarga->headers->set('Content-Disposition', $descarga->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            // Nobelio lo nombra con el numero del evento ("ACR1.xml",
            // "ACR1-dian.xml"); el id es el respaldo.
            $respuesta['nombre'] !== '' ? $respuesta['nombre'] : "{$id}-{$archivo}.xml",
        ));

        return $descarga;
    }

    /**
     * Opciones de un catalogo de Nobelio para un ChoiceType: "codigo - nombre"
     * => el valor que espera el API (el codigo o el id). Solo los activos.
     */
    private function opcionesCatalogo(Nobelio $nobelio, string $url, string $valor): array
    {
        $respuesta = $nobelio->consumoGetTodos($url);
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            return [];
        }

        $opciones = [];
        foreach ($respuesta['datos'] as $elemento) {
            if ($elemento['activo'] ?? true) {
                $opciones["{$elemento['codigo']} - {$elemento['nombre']}"] = (string) $elemento[$valor];
            }
        }

        return $opciones;
    }

    /**
     * Vuelve a la ficha del documento que llego en el form; si no tiene forma
     * de UUID, a la lista.
     */
    private function volverAlDocumento(string $documento): Response
    {
        if (Nobelio::esUuid($documento)) {
            return $this->redirectToRoute('nobelio_recepcion_detalle', ['id' => $documento]);
        }

        return $this->redirectToRoute('nobelio_recepcion_lista');
    }

    /**
     * Vuelve a la pagina de la que vino la peticion, solo si es de Talio; si
     * no, a la lista.
     */
    private function volverAlOrigen(Request $request): Response
    {
        $origen = (string) $request->headers->get('referer', '');
        if (str_starts_with($origen, $request->getSchemeAndHttpHost() . '/')) {
            return $this->redirect($origen);
        }

        return $this->redirectToRoute('nobelio_recepcion_lista');
    }
}
