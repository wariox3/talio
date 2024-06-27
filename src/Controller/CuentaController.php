<?php
namespace App\Controller;

use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CuentaController extends AbstractController
{
    #[Route('/cuenta/lista', name: 'cuenta_lista')]
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
        return $this->render('cuenta/lista.html.twig', [
            'cuentas' => $cuentas,
            'form' => $form->createView()]);
    }
}