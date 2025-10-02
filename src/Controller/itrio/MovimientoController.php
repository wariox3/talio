<?php
namespace App\Controller\itrio;

use App\Utilidades\Itrio;
use App\Utilidades\Mensajes;
use App\Utilidades\Niquel;
use App\Utilidades\SpaceDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MovimientoController extends AbstractController
{
    #[Route('/itrio/movimiento/lista', name: 'itrio_movimiento_lista')]
    public function lista(Request $request, Itrio $itrio): Response
    {
        $filtros = ['cadena' => '?order=id'];
        $form = $this->createFormBuilder()
            ->add('factura', ChoiceType::class, ['choices' => ['SI' => 'SI', 'TODOS' => ''], 'data' => 'TODOS'])
            ->add('pendiente', ChoiceType::class, ['choices' => ['SI' => 'SI', 'TODOS' => ''], 'data' => 'TODOS'])
            ->add('id', TextType::class, ['required' => false])
            ->add('pagina', TextType::class, ['required' => false])
            ->add('btnFiltrar', SubmitType::class, array('label' => 'Filtrar'))
            ->add('btnExcel', SubmitType::class, array('label' => 'Excel'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnFiltrar')->isClicked()) {
                $filtros = $this->filtros($form);
                $pagina = $form->get('pagina')->getData();
                if($pagina) {
                    $filtros['cadena'] .= '&page='.$pagina;
                }
            }
            if ($form->get('btnExcel')->isClicked()) {
                $filtros = $this->filtros($form);
                $respuesta = $itrio->consumoArchivoGet('contenedor/movimiento/' . $filtros['cadena']."&excel=true");
                if($respuesta['error'] == false) {
                    $response = new Response($respuesta["content"]);
                    $contentType = 'application/vnd.ms-excel'; // valor por defecto
                    if (isset($respuesta['headers']['content-type'][0])) {
                        $contentType = $respuesta['headers']['content-type'][0];
                    }
                    $response->headers->set('Content-Type', $contentType);
                    $response->headers->set('Content-Disposition', 'attachment; filename="movimientos.xlsx"');
                    $response->headers->set('Pragma', 'no-cache');
                    $response->headers->set('Expires', '0');
                    return $response;
                }

            }
        }
        $registros = 0;
        $movimientos = [];
        $respuesta = $itrio->consumoGet('contenedor/movimiento/' . $filtros['cadena']);
        if($respuesta['error']) {
            Mensajes::error($respuesta['mensaje']);
        } else {
            $movimientos = $respuesta['datos']['results'];
            $registros = $respuesta['datos']['count'];
        }
        return $this->render('itrio/movimiento/lista.html.twig', [
            'movimientos' => $movimientos,
            'registros' => $registros,
            'form' => $form->createView()]);
    }

    #[Route('/itrio/movimiento/detalle/{id}', name: 'itrio_movimiento_detalle')]
    public function detalle(Request $request, SpaceDO $spaceDO, Itrio $itrio, $id): Response
    {
        $factura_id = null;
        $informacionFacturacion = [];
        $respuesta = $itrio->consumoGet("contenedor/movimiento/{$id}/");
        if($respuesta['error']) {
            Mensajes::error($respuesta['mensaje']);
        } else {
            $movimiento = $respuesta['datos'];
            $factura_id = $movimiento['factura_id'];
            if($movimiento['informacion_facturacion_id']) {
                $respuesta = $itrio->consumoGet("contenedor/informacion_facturacion/{$movimiento['informacion_facturacion_id']}/");
                if($respuesta['error'] == false) {
                    $informacionFacturacion = $respuesta['datos'];
                }
            }
        }
        $form = $this->createFormBuilder()
            ->add('factura_id', TextType::class, ['data' => $factura_id, 'empty_data' => null,  'required' => false ])
            ->add('guardar', SubmitType::class, array('label' => 'Guardar'))
            ->add('generar', SubmitType::class, array('label' => 'Generar factura'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('guardar')->isClicked()) {
                $datos = [
                    'factura_id' => $form->get('factura_id')->getData(),
                ];
                $respuesta = $itrio->consumoPath("contenedor/movimiento/{$id}/", $datos);
                echo "<script type='text/javascript'>window.close();</script>";
            }
            if ($form->get('generar')->isClicked()) {
                $datos = [
                    'id' => $id,
                ];
                $respuesta = $itrio->consumoPost("contenedor/movimiento/crear-factura/", $datos);
                if($respuesta['error']) {
                    Mensajes::error($respuesta['mensaje']);
                } else {
                    echo "<script type='text/javascript'>window.close();</script>";
                }
            }
        }
        return $this->render('itrio/movimiento/detalle.html.twig', [
            'informacionFacturacion' => $informacionFacturacion,
            'form' => $form->createView()]);
    }

    #[Route('/itrio/movimiento/usuario/{id}', name: 'itrio_movimiento_usuario')]
    public function usuario(Request $request, SpaceDO $spaceDO, Itrio $itrio, $id): Response
    {
        $form = $this->createFormBuilder()
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

        }
        $informacionesFacturacion = [];
        $respuesta = $itrio->consumoGet("seguridad/usuario/detalle/{$id}/");
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $informacionesFacturacion = $arrDatos['informaciones_facturaciones'];
        }
        return $this->render('itrio/movimiento/usuario.html.twig', [
            'informacionesFacturacion' => $informacionesFacturacion,
            'form' => $form->createView()]);
    }

    private function filtros($form)
    {
        $filtros = ['cadena' => '?order=id'];
        /*$tipo = $form->get('tipo')->getData();
        if($tipo) {
            if($filtros['cadena']) {
                $filtros['cadena'] .= '&tipo=' . $tipo;
            } else {
                $filtros['cadena'] .= '?tipo=' . $tipo;
            }
        }*/
        $pendiente = $form->get('pendiente')->getData();
        if($pendiente) {
            $filtros['cadena'] .= '&sin_factura=true';
        }
        $factura = $form->get('factura')->getData();
        if($factura) {
            $filtros['cadena'] .= '&genera_factura=true';
        }
        $id = $form->get('id')->getData();
        if($id) {
            $filtros['cadena'] .= '&id=' . $id;
        }
        return $filtros;
    }
}