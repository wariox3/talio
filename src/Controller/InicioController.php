<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class InicioController extends AbstractController
{

    #[Route('/', name: 'inicio')]
    public function inicio(Request $request): Response
    {
        return $this->render('inicio.html.twig');
    }
}