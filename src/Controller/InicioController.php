<?php
namespace App\Controller;

use App\Utilidades\Wolframio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class InicioController extends AbstractController
{

    #[Route('/', name: 'inicio')]
    public function inicio(Request $request, Wolframio $wolframio): Response
    {
        $arrEstados = [
            "enviar" => 0,
            "error" => 0,
            "respuesta" => 0
        ];
        $arrServicios = [
            "colaEmitir" => false,
            "colaRespuesta" => false
        ];
        $respuesta = $wolframio->consumoPost("api/documento/estados", []);
        if(!$respuesta['error']) {
            $datos = $respuesta['datos'];
            $arrEstados = $datos['estados'];

        }
        $respuesta = $wolframio->consumoGet('api/documento/servicios');
        if(!$respuesta['error']) {
            $arrServicios = $respuesta['datos'];
        }
        return $this->render('inicio.html.twig', [
            'arrEstados' => $arrEstados,
            'arrServicios' => $arrServicios
        ]);
    }
}