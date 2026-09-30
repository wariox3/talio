<?php
namespace App\Controller\itrio;

use App\Utilidades\Itrio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class MovimientoController extends AbstractController
{
    #[Route('/itrio/movimiento/lista', name: 'itrio_movimiento_lista')]
    public function lista(Request $request, Itrio $itrio): Response
    {
        $filtros = ['order' => 'id'];
        $form = $this->createFormBuilder()
            ->add('factura', ChoiceType::class, ['choices' => ['SI' => 'SI', 'TODOS' => ''], 'data' => ''])
            ->add('pendiente', ChoiceType::class, ['choices' => ['SI' => 'SI', 'TODOS' => ''], 'data' => ''])
            ->add('id', TextType::class, ['required' => false])
            ->add('pagina', TextType::class, ['required' => false])
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->add('btnExcel', SubmitType::class, ['label' => 'Excel'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $filtros = $this->filtros($form);
            if ($form->get('btnExcel')->isClicked()) {
                unset($filtros['page']);
                $respuesta = $itrio->consumoArchivo('contenedor/movimiento/?' . http_build_query($filtros + ['excel' => 'true']));
                if (!$respuesta['error']) {
                    $descarga = new Response($respuesta['contenido'], Response::HTTP_OK, ['Content-Type' => $respuesta['tipo']]);
                    $descarga->headers->set('Content-Disposition', $descarga->headers->makeDisposition(
                        ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'movimientos.xlsx'));

                    return $descarga;
                }
                $this->addFlash('danger', $respuesta['mensaje']);
            }
        }

        $registros = 0;
        $movimientos = [];
        $respuesta = $itrio->consumoGet('contenedor/movimiento/?' . http_build_query($filtros));
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $movimientos = $respuesta['datos']['results'] ?? [];
            $registros = $respuesta['datos']['count'] ?? 0;
        }

        return $this->render('itrio/movimiento/lista.html.twig', [
            'movimientos' => $movimientos,
            'registros' => $registros,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Ventana emergente para asignar o generar la factura de un movimiento.
     * Si la accion sale bien la ventana se cierra sola (cerrarVentana).
     */
    #[Route('/itrio/movimiento/detalle/{id}', name: 'itrio_movimiento_detalle', requirements: ['id' => '\d+'])]
    public function detalle(Request $request, Itrio $itrio, string $id): Response
    {
        $facturaId = null;
        $informacionFacturacion = [];
        $respuesta = $itrio->consumoGet("contenedor/movimiento/{$id}/");
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $movimiento = $respuesta['datos'];
            $facturaId = $movimiento['factura_id'] ?? null;
            if (!empty($movimiento['informacion_facturacion_id'])) {
                $respuesta = $itrio->consumoGet("contenedor/informacion_facturacion/{$movimiento['informacion_facturacion_id']}/");
                if ($respuesta['error']) {
                    $this->addFlash('danger', $respuesta['mensaje']);
                } else {
                    $informacionFacturacion = $respuesta['datos'];
                }
            }
        }

        $form = $this->createFormBuilder()
            ->add('factura_id', TextType::class, ['data' => $facturaId, 'empty_data' => null, 'required' => false])
            ->add('guardar', SubmitType::class, ['label' => 'Guardar'])
            ->add('generar', SubmitType::class, ['label' => 'Generar factura'])
            ->getForm();
        $form->handleRequest($request);
        $cerrarVentana = false;
        if ($form->isSubmitted() && $form->isValid()) {
            $respuesta = null;
            if ($form->get('guardar')->isClicked()) {
                $respuesta = $itrio->consumoPatch("contenedor/movimiento/{$id}/", [
                    'factura_id' => $form->get('factura_id')->getData(),
                ]);
            } elseif ($form->get('generar')->isClicked()) {
                $respuesta = $itrio->consumoPost('contenedor/movimiento/crear-factura/', ['id' => $id]);
            }
            if ($respuesta !== null) {
                if ($respuesta['error']) {
                    $this->addFlash('danger', $respuesta['mensaje']);
                } else {
                    $cerrarVentana = true;
                }
            }
        }

        return $this->render('itrio/movimiento/detalle.html.twig', [
            'informacionFacturacion' => $informacionFacturacion,
            'form' => $form->createView(),
            'cerrarVentana' => $cerrarVentana,
        ]);
    }

    #[Route('/itrio/movimiento/usuario/{id}', name: 'itrio_movimiento_usuario', requirements: ['id' => '\d+'])]
    public function usuario(Itrio $itrio, string $id): Response
    {
        $informacionesFacturacion = [];
        $respuesta = $itrio->consumoGet("seguridad/usuario/detalle/{$id}/");
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $informacionesFacturacion = $respuesta['datos']['informaciones_facturaciones'] ?? [];
        }

        return $this->render('itrio/movimiento/usuario.html.twig', [
            'informacionesFacturacion' => $informacionesFacturacion,
        ]);
    }

    /**
     * Parametros de la query de movimientos. Van por http_build_query: antes
     * el id y la pagina se pegaban tal cual a la URL y un "&" en el campo
     * metia parametros extra en la peticion a Itrio.
     */
    private function filtros(FormInterface $form): array
    {
        $filtros = ['order' => 'id'];
        if ($form->get('pendiente')->getData()) {
            $filtros['sin_factura'] = 'true';
        }
        if ($form->get('factura')->getData()) {
            $filtros['genera_factura'] = 'true';
        }
        $id = trim((string) $form->get('id')->getData());
        if ($id !== '') {
            $filtros['id'] = $id;
        }
        $pagina = (int) $form->get('pagina')->getData();
        if ($pagina > 1) {
            $filtros['page'] = $pagina;
        }

        return $filtros;
    }
}
