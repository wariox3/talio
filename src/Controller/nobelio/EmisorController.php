<?php
namespace App\Controller\nobelio;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class EmisorController extends AbstractController
{
    /**
     * Pantalla vacía a la espera del servicio Nobelio.
     * No consume ninguna API: cuando exista App\Utilidades\Nobelio se inyecta
     * aquí y se llena la plantilla.
     */
    #[Route('/nobelio/emisor/lista', name: 'nobelio_emisor_lista')]
    public function lista(): Response
    {
        return $this->render('nobelio/emisor/lista.html.twig');
    }
}
