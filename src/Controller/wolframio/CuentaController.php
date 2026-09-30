<?php
namespace App\Controller\wolframio;

use App\Utilidades\Softgic;
use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class CuentaController extends AbstractController
{
    /**
     * Acciones de la lista: el nombre del boton pulsado dice que hacer y su
     * valor, sobre que cuenta. Cada una es una o varias llamadas a Wolframio,
     * en orden; si una falla no se sigue con las demas.
     */
    private const ACCIONES = [
        'OpCrear' => ['texto' => 'Suscriptor creado', 'endpoints' => ['api/cuenta/softgic/suscriptor/nuevo']],
        'OpHabilitar' => ['texto' => 'Set de pruebas del suscriptor enviado', 'endpoints' => ['api/cuenta/softgic/suscriptor/habilitar']],
        'OpCrearEmpleador' => ['texto' => 'Empleador creado', 'endpoints' => ['api/cuenta/softgic/empleador/nuevo']],
        'OpHabilitarNomina' => ['texto' => 'Set de pruebas de nómina enviado', 'endpoints' => [
            'api/cuenta/softgic/empleador/actualizar',
            'api/cuenta/softgic/empleador/habilitar',
        ]],
    ];

    #[Route('/wolframio/cuenta/lista', name: 'wolframio_cuenta_lista')]
    public function lista(Request $request, Wolframio $wolframio): Response
    {
        $form = $this->createFormBuilder()->getForm();
        $form->handleRequest($request);
        // Solo con el form enviado por POST y valido (lleva el token CSRF).
        // Antes la accion se leia con $request->get(), que tambien mira la
        // query, y bastaba abrir ?OpHabilitar=<id> para ejecutarla.
        if ($form->isSubmitted() && $form->isValid()) {
            foreach (self::ACCIONES as $boton => $accion) {
                $cuentaId = (string) $request->request->get($boton, '');
                if ($cuentaId !== '') {
                    $this->ejecutar($wolframio, $accion, $cuentaId);

                    return $this->redirectToRoute('wolframio_cuenta_lista');
                }
            }
        }

        $cuentas = [];
        $respuesta = $wolframio->consumoPost('api/cuenta/lista');
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $cuentas = $respuesta['datos']['cuentas'] ?? [];
        }

        return $this->render('wolframio/cuenta/lista.html.twig', [
            'cuentas' => $cuentas,
            'form' => $form->createView(),
        ]);
    }

    private function ejecutar(Wolframio $wolframio, array $accion, string $cuentaId): void
    {
        foreach ($accion['endpoints'] as $endpoint) {
            $respuesta = $wolframio->consumoPost($endpoint, ['cuentaId' => $cuentaId]);
            if ($respuesta['error']) {
                $this->addFlash('danger', "Cuenta {$cuentaId}: {$respuesta['mensaje']}");

                return;
            }
        }

        $this->addFlash('success', "Cuenta {$cuentaId}: {$accion['texto']}.");
    }

    #[Route('/wolframio/cuenta/editar/{id}', name: 'wolframio_cuenta_editar')]
    public function editar(Request $request, Wolframio $wolframio, string $id): Response
    {
        $datosCuenta = [];
        $respuesta = $wolframio->consumoPost('api/cuenta/detalle', ['cuentaId' => $id]);
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $datosCuenta = $respuesta['datos']['cuenta'] ?? [];
        }

        $form = $this->createFormBuilder()
            ->add('setPruebas', TextType::class, ['required' => false, 'data' => $datosCuenta['setPruebas'] ?? ''])
            ->add('setPruebasNomina', TextType::class, ['required' => false, 'data' => $datosCuenta['setPruebasNomina'] ?? ''])
            ->add('btnGuardar', SubmitType::class, ['label' => 'Guardar'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $respuesta = $wolframio->consumoPost('api/cuenta/actualizar/v2', [
                'cuentaId' => $id,
                'setPruebas' => $form->get('setPruebas')->getData(),
                'setPruebasNomina' => $form->get('setPruebasNomina')->getData(),
            ]);
            if ($respuesta['error']) {
                $this->addFlash('danger', $respuesta['mensaje']);
            } else {
                $this->addFlash('success', 'Cuenta actualizada.');
            }
        }

        return $this->render('wolframio/cuenta/editar.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/wolframio/cuenta/resolucion/{suscriptor}', name: 'wolframio_cuenta_resolucion')]
    public function resolucion(Softgic $softgic, string $suscriptor): Response
    {
        $resoluciones = [];
        $respuesta = $softgic->consultaSuscriptor($suscriptor);
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $resoluciones = $respuesta['resoluciones'];
        }

        return $this->render('wolframio/cuenta/resoluciones.html.twig', [
            'resoluciones' => $resoluciones,
        ]);
    }

    /**
     * Ficha de solo lectura del suscriptor en Kiai. Los datos se muestran en
     * campos de formulario, pero no hay nada que guardar.
     */
    #[Route('/wolframio/cuenta/suscriptor/{suscriptor}', name: 'wolframio_cuenta_suscriptor')]
    public function suscriptor(Softgic $softgic, string $suscriptor): Response
    {
        $datos = [];
        $respuesta = $softgic->consultaSuscriptor($suscriptor);
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $datos = $respuesta['suscriptor'];
        }

        $campos = [
            'Documento', 'Dv', 'RazonSocial', 'Direccion', 'CodigoPostal', 'CodigoCiudad', 'NombreCiudad',
            'CodigoDepartamento', 'NombreDepartamento', 'Obligaciones', 'Correo', 'Telefono', 'CodigoPersona',
            'TipoPersona', 'CodigoRegimen', 'NombreRegimen',
        ];
        $form = $this->fichaSoloLectura($datos, $campos + ['setPruebas' => 'TestPruebas']);

        return $this->render('wolframio/cuenta/suscriptor.html.twig', [
            'aliado' => $datos['DocumentoAliado'] ?? '',
            'aliadoNombre' => $datos['NombreAliado'] ?? '',
            'form' => $form->createView(),
        ]);
    }

    /** Ficha de solo lectura del empleador en Kiai. */
    #[Route('/wolframio/cuenta/empleador/{empleador}', name: 'wolframio_cuenta_empleador')]
    public function empleador(Softgic $softgic, string $empleador): Response
    {
        $datos = [];
        $respuesta = $softgic->consultaEmpleador($empleador);
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $datos = $respuesta['empleador'];
        }

        $form = $this->fichaSoloLectura($datos, ['NIT', 'DV', 'RazonSocial', 'Direccion', 'MunicipioCiudad', 'DepartamentoEstado', 'TestId']);

        return $this->render('wolframio/cuenta/empleador.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Form con un campo de texto deshabilitado por dato. Las claves numericas
     * de $campos usan el mismo nombre para el campo y el dato; las de texto
     * son "campo => dato".
     */
    private function fichaSoloLectura(array $datos, array $campos)
    {
        $builder = $this->createFormBuilder(null, ['csrf_protection' => false]);
        foreach ($campos as $campo => $dato) {
            $campo = is_int($campo) ? $dato : $campo;
            $valor = $datos[$dato] ?? '';
            $builder->add($campo, TextType::class, [
                'required' => false,
                'disabled' => true,
                'data' => is_scalar($valor) ? (string) $valor : '',
            ]);
        }

        return $builder->getForm();
    }
}
