<?php
namespace App\Controller\wolframio;

use App\Utilidades\Softgic;
use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CuentaController extends AbstractController
{
    #[Route('/wolframio/cuenta/lista', name: 'wolframio_cuenta_lista')]
    public function lista(Request $request, Wolframio $wolframio): Response
    {
        $form = $this->createFormBuilder()
            ->add('btnEnviar', SubmitType::class, array('label' => 'Enviar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnEnviar')->isClicked()) {
                $arrSeleccionados = $request->get('ChkSeleccionar');
                if($arrSeleccionados) {
                    foreach ($arrSeleccionados as $codigo) {
                        $datos = [
                            "documentoId" => $codigo
                        ];
                        $respuesta = $wolframio->consumoPost("api/documento/enviar", $datos);
                    }
                }
            }
        }
        $cuentas = [];
        $datos = [];
        $respuesta = $wolframio->consumoPost('api/cuenta/lista', $datos);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $cuentas = $arrDatos['cuentas'];
        }
        return $this->render('wolframio/cuenta/lista.html.twig', [
            'cuentas' => $cuentas,
            'form' => $form->createView()]);
    }

    #[Route('/wolframio/cuenta/resolucion/{suscriptor}', name: 'wolframio_cuenta_resolucion')]
    public function resolucion(Request $request, Softgic $softgic, $suscriptor): Response
    {
        $resoluciones = $softgic->resoluciones($suscriptor);
        return $this->render('wolframio/cuenta/resoluciones.html.twig', [
            'resoluciones' => $resoluciones]);
    }
}