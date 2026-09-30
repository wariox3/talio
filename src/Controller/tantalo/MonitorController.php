<?php
namespace App\Controller\tantalo;

use App\Utilidades\Tantalo;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MonitorController extends AbstractController
{
    #[Route('/tantalo/monitor', name: 'tantalo_monitor')]
    public function monitor(Tantalo $tantalo): Response
    {
        $arrServicios = [
            "colaDecodificar" => false
        ];
        $respuesta = $tantalo->consumoGet('api/servicio/estado');
        if(!$respuesta['error']) {
            $arrServicios = $respuesta['datos'] + $arrServicios;
        }
        return $this->render('tantalo/monitor.html.twig', [
            'arrServicios' => $arrServicios
        ]);
    }
}
