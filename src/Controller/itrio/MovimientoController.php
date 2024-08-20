<?php
namespace App\Controller\itrio;

use App\Utilidades\Itrio;
use App\Utilidades\Niquel;
use App\Utilidades\SpaceDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MovimientoController extends AbstractController
{
    #[Route('/itrio/movimiento/lista', name: 'itrio_movimiento_lista')]
    public function lista(Request $request, Itrio $itrio): Response
    {
        $filtros = [];
        $form = $this->createFormBuilder()
            ->add('tipo', ChoiceType::class, ['choices' => ['PEDIDO' => 'PEDIDO', 'RECIBO' => 'RECIBO', 'TODOS' => ''], 'data' => 'TODOS'])
            ->add('factura', ChoiceType::class, ['choices' => ['SI' => 'SI', 'NO' => 'NO', 'TODOS' => ''], 'data' => 'TODOS'])
            ->add('btnFiltrar', SubmitType::class, array('label' => 'Filtrar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnFiltrar')->isClicked()) {
                $filtros = $this->filtros($form);
            }
        }
        $movimientos = [];
        $respuesta = $itrio->consumoPost('contenedor/movimiento/lista/', [
            'filtros' => $filtros
        ]);
        if(!$respuesta['error']) {
            $movimientos = $respuesta['datos'];
        }
        return $this->render('itrio/movimiento/lista.html.twig', [
            'movimientos' => $movimientos,
            'form' => $form->createView()]);
    }

    #[Route('/itrio/movimiento/subir/{id}', name: 'itrio_movimiento_subir')]
    public function detalle(Request $request, SpaceDO $spaceDO, Itrio $itrio, $id): Response
    {
        $form = $this->createFormBuilder()
            ->add('attachment', FileType::class)
            ->add('cargar', SubmitType::class, array('label' => 'Cagar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('cargar')->isClicked()) {
                $objArchivo = $form['attachment']->getData();
                $fileContent = file_get_contents($objArchivo->getPathname());
                $contentType = $objArchivo->getMimeType();
                $archivoDestino = "itrio/prod/movimiento/factura_{$id}.pdf";
                $spaceDO->subirB64($archivoDestino, $fileContent, $contentType);
                $itrio->consumoPost('contenedor/movimiento/marcar-adjunto/', ['id' => $id]);
                echo "<script type='text/javascript'>window.close();window.opener.location.reload();</script>";
            }

        }
        return $this->render('itrio/movimiento/subir.html.twig', [
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
        $filtros = [];
        $tipo = $form->get('tipo')->getData();
        if($tipo) {
            $filtros[] = ["propiedad" => "tipo", "valor1"=> "PEDIDO"]
            ;
        }
        $factura = $form->get('factura')->getData();
        if($factura) {
            if($factura == 'SI') {
                $filtros[] = ["propiedad" => "documento_fisico", "valor1"=> true];
            }
            if($factura == 'NO') {
                $filtros[] = ["propiedad" => "documento_fisico", "valor1"=> false];
            }
        }
        return $filtros;
    }
}