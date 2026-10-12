<?php
namespace App\Controller\nobelio;

use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Las cuentas de Nobelio: agrupadores de emisores, p. ej. el ERP que los
 * integra. Solo agrupan —no dan ni quitan acceso—, y lo que tienen de propio
 * son sus webhooks: uno de cuenta vale para todos sus emisores y se suma a los
 * de cada uno.
 */
class CuentaController extends AbstractController
{
    #[Route('/nobelio/cuenta/lista', name: 'nobelio_cuenta_lista')]
    public function lista(Request $request, Nobelio $nobelio): Response
    {
        $form = $this->createFormBuilder()
            ->add('search', TextType::class, ['required' => false])
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->add('btnAnterior', SubmitType::class, ['label' => 'Anterior'])
            ->add('btnSiguiente', SubmitType::class, ['label' => 'Siguiente'])
            ->getForm();
        $form->handleRequest($request);

        // La pagina viaja en un hidden propio, fuera del form, igual que en
        // la lista de emisores.
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

        $cuentas = [];
        $total = 0;
        $hayAnterior = false;
        $haySiguiente = false;

        $respuesta = $nobelio->consumoGet('api/emisores/cuenta/', $parametros);
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
        } else {
            $datos = $respuesta['datos'];
            $cuentas = $datos['results'] ?? [];
            $total = (int) ($datos['count'] ?? 0);
            $hayAnterior = !empty($datos['previous']);
            $haySiguiente = !empty($datos['next']);
        }

        return $this->render('nobelio/cuenta/lista.html.twig', [
            'form' => $form->createView(),
            'cuentas' => $cuentas,
            'total' => $total,
            'pagina' => $pagina,
            'hayAnterior' => $hayAnterior,
            'haySiguiente' => $haySiguiente,
        ]);
    }

    /**
     * Ventana para crear una cuenta. Solo tiene nombre, que es unico en
     * Nobelio: si ya existe responde 400 y ese es el mensaje que se muestra.
     */
    #[Route('/nobelio/cuenta/nuevo', name: 'nobelio_cuenta_nuevo')]
    public function nuevo(Request $request, Nobelio $nobelio): Response
    {
        $form = $this->createFormBuilder()
            ->add('nombre', TextType::class, ['attr' => ['maxlength' => 150]])
            ->add('btnGuardar', SubmitType::class, ['label' => 'Guardar'])
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $respuesta = $nobelio->consumoPost('api/emisores/cuenta/', [
                'nombre' => trim((string) $form->get('nombre')->getData()),
            ]);
            if ($respuesta['error']) {
                // Sin redirect: asi el form conserva lo digitado para corregir.
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $this->addFlash('success', sprintf(
                    'Cuenta %s creada. Recargue la lista de cuentas para verla.',
                    $respuesta['datos']['id'] ?? '',
                ));

                return $this->redirectToRoute('nobelio_cuenta_nuevo');
            }
        }

        return $this->render('nobelio/cuenta/nuevo.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Elimina una cuenta y vuelve a la lista.
     *
     * Nobelio no deja borrar una cuenta con emisores: responde 400 diciendo
     * que hay que sacarlos antes, y ese es el mensaje que se muestra. Sus
     * webhooks si se van con ella. El id se concatena a la url del API, asi
     * que se acota antes de usarlo.
     */
    #[Route('/nobelio/cuenta/eliminar', name: 'nobelio_cuenta_eliminar', methods: ['POST'])]
    public function eliminar(Request $request, Nobelio $nobelio): Response
    {
        $cuenta = (string) $request->request->get('cuenta', '');

        if (!$this->isCsrfTokenValid('cuentas', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La petición de borrado no es válida.');
        } elseif (!ctype_digit($cuenta)) {
            $this->addFlash('danger', 'No se indicó qué cuenta eliminar.');
        } else {
            $respuesta = $nobelio->consumoDelete("api/emisores/cuenta/{$cuenta}/");
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $this->addFlash('success', 'Cuenta eliminada.');
            }
        }

        return $this->redirectToRoute('nobelio_cuenta_lista');
    }

    #[Route('/nobelio/cuenta/detalle/{id}', name: 'nobelio_cuenta_detalle', requirements: ['id' => '\\d+'])]
    public function detalle(Nobelio $nobelio, int $id): Response
    {
        $respuesta = $nobelio->consumoGet("api/emisores/cuenta/{$id}/");
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");

            return $this->redirectToRoute('nobelio_cuenta_lista');
        }

        // Los webhooks propios de la cuenta, no los de sus emisores: esos se
        // ven en el detalle de cada emisor.
        $webhooks = [];
        $listaWebhooks = $nobelio->consumoGetTodos('api/emisores/webhook/', ['cuenta' => $id]);
        if ($listaWebhooks['error']) {
            $this->addFlash('danger', "Nobelio: {$listaWebhooks['mensaje']}");
        } else {
            $webhooks = $listaWebhooks['datos'];
        }

        // Los emisores que agrupa. Se pide con ?cuenta= pero el listado de
        // Nobelio todavia no filtra por cuenta —ignora el parametro y los
        // devuelve todos—, asi que se recorren las paginas y se filtra aqui
        // por el `cuenta` que trae cada emisor. Cuando Nobelio filtre, este
        // filtro sobra pero no estorba.
        $emisores = [];
        $listaEmisores = $nobelio->consumoGetTodos('api/emisores/emisor/', ['cuenta' => $id], 30);
        if ($listaEmisores['error']) {
            $this->addFlash('danger', "Nobelio: {$listaEmisores['mensaje']}");
        } else {
            $emisores = array_values(array_filter(
                $listaEmisores['datos'],
                fn ($emisor) => (int) ($emisor['cuenta'] ?? 0) === $id,
            ));
        }

        return $this->render('nobelio/cuenta/detalle.html.twig', [
            'cuenta' => $respuesta['datos'],
            'emisores' => $emisores,
            'webhooks' => $webhooks,
        ]);
    }

    /**
     * Ventana para registrar un webhook de la cuenta.
     *
     * Es el mismo recurso que el webhook de un emisor, pero con `cuenta` en
     * vez de `emisor`: Nobelio exige uno de los dos y nunca ambos. El secreto
     * es con lo que Nobelio firma los avisos; es opcional al guardar, pero sin
     * el no se manda ninguno. Es de solo escritura: nunca vuelve en el GET.
     */
    #[Route('/nobelio/cuenta/webhook-nuevo/{id}', name: 'nobelio_cuenta_webhook_nuevo', requirements: ['id' => '\\d+'])]
    public function webhookNuevo(Request $request, Nobelio $nobelio, int $id): Response
    {
        $form = $this->formularioWebhook();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $datos = ['cuenta' => $id] + $this->datosWebhook($form);

            $respuesta = $nobelio->consumoPost('api/emisores/webhook/', $datos);
            if ($respuesta['error']) {
                // Sin redirect: asi el form conserva lo digitado para corregir.
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $this->addFlash('success', sprintf(
                    'Webhook %s registrado. Recargue el detalle de la cuenta para verlo.',
                    $respuesta['datos']['id'] ?? '',
                ));

                return $this->redirectToRoute('nobelio_cuenta_webhook_nuevo', ['id' => $id]);
            }
        }

        // Solo para encabezar la ventana.
        $cuenta = [];
        $respuestaCuenta = $nobelio->consumoGet("api/emisores/cuenta/{$id}/");
        if ($respuestaCuenta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuestaCuenta['mensaje']}");
        } else {
            $cuenta = $respuestaCuenta['datos'];
        }

        return $this->render('nobelio/cuenta/webhook.html.twig', [
            'form' => $form->createView(),
            'cuenta' => $cuenta,
            'webhook' => [],
        ]);
    }

    /**
     * Ventana para editar un webhook de la cuenta: nombre, URL, secreto y de
     * que se le avisa.
     *
     * Va por PATCH y sin `cuenta`: Nobelio no deja cambiar un webhook de
     * cuenta. El secreto es de solo escritura —nunca vuelve en el GET—, asi
     * que el campo sale vacio y solo se manda si se digita: en blanco se
     * conserva el que hay. El id se concatena a la url del API: el
     * requirement lo acota.
     */
    #[Route('/nobelio/cuenta/webhook-editar/{id}', name: 'nobelio_cuenta_webhook_editar', requirements: ['id' => '\\d+'])]
    public function webhookEditar(Request $request, Nobelio $nobelio, int $id): Response
    {
        $webhook = [];
        $respuestaWebhook = $nobelio->consumoGet("api/emisores/webhook/{$id}/");
        if ($respuestaWebhook['error']) {
            $this->addFlash('danger', "Nobelio: {$respuestaWebhook['mensaje']}");
        } else {
            $webhook = $respuestaWebhook['datos'];
        }

        $form = $this->formularioWebhook($webhook);
        $form->handleRequest($request);

        if ($webhook && $form->isSubmitted() && $form->isValid()) {
            $respuesta = $nobelio->consumoPatch("api/emisores/webhook/{$id}/", $this->datosWebhook($form));
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $this->addFlash('success', 'Webhook actualizado. Recargue el detalle de la cuenta para ver los cambios.');

                return $this->redirectToRoute('nobelio_cuenta_webhook_editar', ['id' => $id]);
            }
        }

        $cuenta = [];
        if (!empty($webhook['cuenta'])) {
            $respuestaCuenta = $nobelio->consumoGet("api/emisores/cuenta/{$webhook['cuenta']}/");
            if (!$respuestaCuenta['error']) {
                $cuenta = $respuestaCuenta['datos'];
            }
        }

        return $this->render('nobelio/cuenta/webhook.html.twig', [
            'form' => $form->createView(),
            'cuenta' => $cuenta,
            'webhook' => $webhook,
        ]);
    }

    /**
     * Elimina un webhook de la cuenta y vuelve a su detalle, en su pestaña.
     *
     * El id del webhook se concatena a la url del API, asi que se acota antes
     * de usarlo. La cuenta solo sirve para volver.
     */
    #[Route('/nobelio/cuenta/webhook-eliminar/{id}', name: 'nobelio_cuenta_webhook_eliminar', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function webhookEliminar(Request $request, Nobelio $nobelio, int $id): Response
    {
        $webhook = (string) $request->request->get('webhook', '');

        if (!$this->isCsrfTokenValid('webhooks-cuenta', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La petición de borrado no es válida.');
        } elseif (!ctype_digit($webhook)) {
            $this->addFlash('danger', 'No se indicó qué webhook eliminar.');
        } else {
            $respuesta = $nobelio->consumoDelete("api/emisores/webhook/{$webhook}/");
            if ($respuesta['error']) {
                $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
            } else {
                $this->addFlash('success', 'Webhook eliminado.');
            }
        }

        return $this->redirectToRoute('nobelio_cuenta_detalle', ['id' => $id, '_fragment' => 'webhooks']);
    }

    /**
     * El form del webhook, vacio para el alta o con lo que tiene Nobelio para
     * editar. El secreto siempre sale vacio: Nobelio no lo devuelve.
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
            // Sin protocolo por defecto: si falta, que lo diga Nobelio en vez
            // de completarlo aqui.
            ->add('url', UrlType::class, ['default_protocol' => null, 'attr' => ['maxlength' => 500, 'placeholder' => 'https://']])
            ->add('secreto', TextType::class, ['required' => false, 'attr' => ['maxlength' => 255, 'autocomplete' => 'off']])
            ->add('estadoValidado', CheckboxType::class, ['required' => false])
            ->add('estadoNotificado', CheckboxType::class, ['required' => false])
            ->add('btnGuardar', SubmitType::class, ['label' => 'Guardar'])
            ->getForm();
    }

    /**
     * Lo que se manda a Nobelio, igual en el alta y en la edicion. El secreto
     * solo va si se digito: mandarlo vacio lo borraria.
     */
    private function datosWebhook(FormInterface $form): array
    {
        $datos = [
            'nombre' => trim((string) $form->get('nombre')->getData()),
            'url' => trim((string) $form->get('url')->getData()),
            'estado_validado' => (bool) $form->get('estadoValidado')->getData(),
            'estado_notificado' => (bool) $form->get('estadoNotificado')->getData(),
        ];
        $secreto = trim((string) $form->get('secreto')->getData());
        if ($secreto !== '') {
            $datos['secreto'] = $secreto;
        }

        return $datos;
    }
}
