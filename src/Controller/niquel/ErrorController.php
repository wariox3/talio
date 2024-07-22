<?php
namespace App\Controller\niquel;

use App\Utilidades\Niquel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ErrorController extends AbstractController
{
    #[Route('/niquel/error/lista', name: 'niquel_error_lista')]
    public function lista(Request $request, Niquel $niquel): Response
    {
        $form = $this->createFormBuilder()
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

        }
        $errores = [];
        $respuesta = $niquel->consumoPost('api/error/lista', []);
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $errores = $arrDatos['errores'];
        }
        return $this->render('niquel/error/lista.html.twig', [
            'errores' => $errores,
            'form' => $form->createView()]);
    }

}