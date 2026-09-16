<?php
namespace App\Controller\nobelio;

use App\Utilidades\Mensajes;
use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EmisorController extends AbstractController
{
    // Los `tipo` que acepta POST api/emisores/software/ (SoftwareDianTipoEnum).
    private const TIPOS_SOFTWARE = [
        'Facturación electrónica' => 'facturacion',
        'Nómina electrónica' => 'nomina',
        'Documento equivalente electrónico' => 'documento_equivalente',
    ];

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

        $webhooks = [];
        $listaWebhooks = $nobelio->consumoGet('api/emisores/webhook/', ['emisor' => $id]);
        if ($listaWebhooks['error']) {
            Mensajes::error("Nobelio: {$listaWebhooks['mensaje']}");
        } else {
            $webhooks = $listaWebhooks['datos']['results'] ?? [];
        }

        return $this->render('nobelio/emisor/detalle.html.twig', [
            'emisor' => $emisor,
            'certificados' => $certificados,
            'software' => $software,
            'resoluciones' => $resoluciones,
            'webhooks' => $webhooks,
        ]);
    }

    /**
     * Ventana para registrar un webhook del emisor.
     *
     * Nobelio pide nombre y URL, y las dos banderas dicen de que se le avisa:
     * de la validacion de la DIAN y de la notificacion al adquiriente. La URL
     * tiene que ser HTTPS —el aviso lleva datos fiscales—; eso lo valida
     * Nobelio y su mensaje es el que se muestra. El emisor sale del detalle
     * desde donde se abre.
     */
    #[Route('/nobelio/emisor/webhook-nuevo/{id}', name: 'nobelio_emisor_webhook_nuevo', requirements: ['id' => '\\d+'])]
    public function webhookNuevo(Request $request, Nobelio $nobelio, int $id): Response
    {
        $form = $this->formularioWebhook();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $respuesta = $nobelio->consumoPost('api/emisores/webhook/', ['emisor' => $id] + $this->datosWebhook($form));
            if ($respuesta['error']) {
                // Sin redirect: asi el form conserva lo digitado para corregir.
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                Mensajes::success(sprintf(
                    'Webhook %s registrado. Recargue el detalle del emisor para verlo.',
                    $respuesta['datos']['id'] ?? '',
                ));

                return $this->redirectToRoute('nobelio_emisor_webhook_nuevo', ['id' => $id]);
            }
        }

        $emisor = [];
        $respuestaEmisor = $nobelio->consumoGet("api/emisores/emisor/{$id}/");
        if ($respuestaEmisor['error']) {
            Mensajes::error("Nobelio: {$respuestaEmisor['mensaje']}");
        } else {
            $emisor = $respuestaEmisor['datos'];
        }

        return $this->render('nobelio/emisor/webhook.html.twig', [
            'form' => $form->createView(),
            'emisor' => $emisor,
            'webhook' => [],
        ]);
    }

    /**
     * Ventana para editar un webhook: nombre, URL y de que se le avisa.
     *
     * Va por PATCH y sin `emisor`: Nobelio no deja cambiar un webhook de
     * emisor, y mandarlo solo serviria para arriesgar ese 400. El form se llena
     * con lo que tiene Nobelio en el GET y, si el guardado falla, conserva lo
     * digitado. El id es un entero que se concatena a la url del API: el
     * requirement lo acota.
     */
    #[Route('/nobelio/emisor/webhook-editar/{id}', name: 'nobelio_emisor_webhook_editar', requirements: ['id' => '\\d+'])]
    public function webhookEditar(Request $request, Nobelio $nobelio, int $id): Response
    {
        $webhook = [];
        $respuestaWebhook = $nobelio->consumoGet("api/emisores/webhook/{$id}/");
        if ($respuestaWebhook['error']) {
            Mensajes::error("Nobelio: {$respuestaWebhook['mensaje']}");
        } else {
            $webhook = $respuestaWebhook['datos'];
        }

        $form = $this->formularioWebhook($webhook);
        $form->handleRequest($request);

        if ($webhook && $form->isSubmitted() && $form->isValid()) {
            $respuesta = $nobelio->consumoPatch("api/emisores/webhook/{$id}/", $this->datosWebhook($form));
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                Mensajes::success('Webhook actualizado. Recargue el detalle del emisor para ver los cambios.');

                return $this->redirectToRoute('nobelio_emisor_webhook_editar', ['id' => $id]);
            }
        }

        $emisor = [];
        if (!empty($webhook['emisor'])) {
            $respuestaEmisor = $nobelio->consumoGet("api/emisores/emisor/{$webhook['emisor']}/");
            if (!$respuestaEmisor['error']) {
                $emisor = $respuestaEmisor['datos'];
            }
        }

        return $this->render('nobelio/emisor/webhook.html.twig', [
            'form' => $form->createView(),
            'emisor' => $emisor,
            'webhook' => $webhook,
        ]);
    }

    /**
     * El form del webhook, vacio para el alta o con lo que tiene Nobelio para
     * editar.
     */
    private function formularioWebhook(array $webhook = []): FormInterface
    {
        return $this->createFormBuilder([
            'nombre' => $webhook['nombre'] ?? null,
            'url' => $webhook['url'] ?? null,
            'estadoValidado' => (bool) ($webhook['estado_validado'] ?? false),
            'estadoNotificado' => (bool) ($webhook['estado_notificado'] ?? false),
        ])
            ->add('nombre', TextType::class, ['attr' => ['maxlength' => 150]])
            // Sin protocolo por defecto: si no se escribe https:// que lo diga
            // Nobelio, en vez de completarlo aqui con http://.
            ->add('url', UrlType::class, ['default_protocol' => null, 'attr' => ['maxlength' => 500, 'placeholder' => 'https://']])
            ->add('estadoValidado', CheckboxType::class, ['required' => false])
            ->add('estadoNotificado', CheckboxType::class, ['required' => false])
            ->add('btnGuardar', SubmitType::class, ['label' => 'Guardar'])
            ->getForm();
    }

    /**
     * Lo que se manda a Nobelio, igual en el alta y en la edicion.
     */
    private function datosWebhook(FormInterface $form): array
    {
        return [
            'nombre' => trim((string) $form->get('nombre')->getData()),
            'url' => trim((string) $form->get('url')->getData()),
            'estado_validado' => (bool) $form->get('estadoValidado')->getData(),
            'estado_notificado' => (bool) $form->get('estadoNotificado')->getData(),
        ];
    }

    /**
     * Elimina un webhook y vuelve al detalle del emisor, en su pestaña.
     *
     * El id del webhook es un entero y se concatena a la url del API, asi que
     * se acota antes de usarlo. El emisor solo sirve para volver.
     */
    #[Route('/nobelio/emisor/webhook-eliminar/{id}', name: 'nobelio_emisor_webhook_eliminar', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function webhookEliminar(Request $request, Nobelio $nobelio, int $id): Response
    {
        $webhook = (string) $request->request->get('webhook', '');

        if (!$this->isCsrfTokenValid('webhooks-emisor', (string) $request->request->get('_token'))) {
            Mensajes::error('La petición de borrado no es válida.');
        } elseif (!ctype_digit($webhook)) {
            Mensajes::error('No se indicó qué webhook eliminar.');
        } else {
            $respuesta = $nobelio->consumoDelete("api/emisores/webhook/{$webhook}/");
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                Mensajes::success('Webhook eliminado.');
            }
        }

        return $this->redirectToRoute('nobelio_emisor_detalle', ['id' => $id, '_fragment' => 'webhooks']);
    }

    /**
     * Ventana para registrar un software del emisor.
     *
     * Pide lo que Nobelio exige (tipo, identificador y PIN) mas el TestSetId,
     * que es opcional pero sin el no se puede enviar el Set de Pruebas; el
     * emisor sale del detalle desde donde se abre. El resto —fabricante,
     * codigo del PT— tiene default en Nobelio.
     */
    #[Route('/nobelio/emisor/software-nuevo/{id}', name: 'nobelio_emisor_software_nuevo', requirements: ['id' => '\\d+'])]
    public function softwareNuevo(Request $request, Nobelio $nobelio, int $id): Response
    {
        $form = $this->createFormBuilder()
            ->add('tipo', ChoiceType::class, ['choices' => self::TIPOS_SOFTWARE])
            ->add('identificador', TextType::class, ['attr' => ['maxlength' => 100]])
            ->add('pin', TextType::class, ['attr' => ['maxlength' => 100]])
            ->add('testSetId', TextType::class, ['required' => false, 'attr' => ['maxlength' => 100]])
            ->add('btnGuardar', SubmitType::class, ['label' => 'Guardar'])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $respuesta = $nobelio->consumoPost('api/emisores/software/', [
                'emisor' => $id,
                'tipo' => $form->get('tipo')->getData(),
                'identificador' => trim((string) $form->get('identificador')->getData()),
                'pin' => trim((string) $form->get('pin')->getData()),
                'test_set_id' => trim((string) $form->get('testSetId')->getData()),
            ]);
            if ($respuesta['error']) {
                // Sin redirect: asi el form conserva lo digitado para corregir.
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                Mensajes::success(sprintf(
                    'Software %s registrado. Recargue el detalle del emisor para verlo.',
                    $respuesta['datos']['id'] ?? '',
                ));

                return $this->redirectToRoute('nobelio_emisor_software_nuevo', ['id' => $id]);
            }
        }

        $emisor = [];
        $respuestaEmisor = $nobelio->consumoGet("api/emisores/emisor/{$id}/");
        if ($respuestaEmisor['error']) {
            Mensajes::error("Nobelio: {$respuestaEmisor['mensaje']}");
        } else {
            $emisor = $respuestaEmisor['datos'];
        }

        return $this->render('nobelio/emisor/software_nuevo.html.twig', [
            'form' => $form->createView(),
            'emisor' => $emisor,
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

    /**
     * Ventana para sembrar una nomina de prueba sobre un software de nomina.
     *
     * Hermana de documentoPrueba(), pero cuelga del software y no de una
     * resolucion: la nomina no se numera con resolucion, asi que quien la
     * numera es el prefijo de pruebas del emisor. Nobelio solo la crea sobre
     * un software de tipo `nomina` —en uno de facturacion responde 400—, y por
     * eso la accion solo se ofrece en esas lineas.
     *
     * El consecutivo tampoco es requerido: sin el toma el siguiente libre.
     */
    #[Route('/nobelio/software/nomina-prueba/{id}', name: 'nobelio_software_nomina_prueba', requirements: ['id' => '\\d+'])]
    public function nominaPrueba(Request $request, Nobelio $nobelio, int $id): Response
    {
        $form = $this->createFormBuilder()
            ->add('consecutivo', IntegerType::class, ['required' => false, 'attr' => ['min' => 1]])
            ->add('btnCrear', SubmitType::class, ['label' => 'Crear'])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $consecutivo = $form->get('consecutivo')->getData();
            $datos = $consecutivo !== null ? ['consecutivo' => $consecutivo] : [];

            $respuesta = $nobelio->consumoPost("api/emisores/software/{$id}/crear-nomina-prueba/", $datos);
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                $creada = $respuesta['datos'];
                // El periodo se dice porque no se elige: Nobelio continua la
                // serie hacia atras para no repetir trabajador y periodo, que
                // es lo que la DIAN rechaza con la regla 90.
                $periodo = $creada['periodo'] ?? [];
                Mensajes::success(sprintf(
                    "Nómina %s creada en estado '%s'%s. Queda en borrador: se emite desde Nómina.",
                    $creada['numero'] ?? '',
                    $creada['estado'] ?? '',
                    count($periodo) === 2 ? " para el periodo {$periodo[0]} a {$periodo[1]}" : '',
                ));
            }

            return $this->redirectToRoute('nobelio_software_nomina_prueba', ['id' => $id]);
        }

        // Form aparte y con nombre propio: crear-nota-ajuste-prueba va sin
        // cuerpo, asi que no debe arrastrar el consecutivo del form de arriba.
        // Nobelio toma la nomina mas reciente aceptada por la DIAN y sin
        // errores y crea sobre ella once notas de reemplazo en borrador; si no
        // hay ninguna responde 400 y no crea nada.
        $formNotaAjuste = $this->container->get('form.factory')->createNamedBuilder('nota_ajuste')
            ->add('btnCrearNotaAjuste', SubmitType::class, ['label' => 'Crear notas de ajuste'])
            ->getForm();
        $formNotaAjuste->handleRequest($request);

        if ($formNotaAjuste->isSubmitted() && $formNotaAjuste->isValid()) {
            $respuesta = $nobelio->consumoPost("api/emisores/software/{$id}/crear-nota-ajuste-prueba/", []);
            if ($respuesta['error']) {
                Mensajes::error("Nobelio: {$respuesta['mensaje']}");
            } else {
                // El esquema de Nobelio no documenta la respuesta, asi que el
                // mensaje no depende de sus campos.
                Mensajes::success('Notas de ajuste creadas en borrador: se emiten desde Nómina.');
            }

            return $this->redirectToRoute('nobelio_software_nomina_prueba', ['id' => $id]);
        }

        $software = [];
        $respuestaSoftware = $nobelio->consumoGet("api/emisores/software/{$id}/");
        if ($respuestaSoftware['error']) {
            Mensajes::error("Nobelio: {$respuestaSoftware['mensaje']}");
        } else {
            $software = $respuestaSoftware['datos'];
        }

        return $this->render('nobelio/emisor/nomina_prueba.html.twig', [
            'form' => $form->createView(),
            'formNotaAjuste' => $formNotaAjuste->createView(),
            'software' => $software,
        ]);
    }
}
