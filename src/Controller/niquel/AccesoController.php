<?php
namespace App\Controller\niquel;

use App\Utilidades\Niquel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AccesoController extends AbstractController
{
    #[Route('/niquel/acceso/lista', name: 'niquel_acceso_lista')]
    public function lista(Request $request, Niquel $niquel): Response
    {
        $form = $this->createFormBuilder()
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

        }
        $hosts = [];
        $respuesta = $niquel->consumoGet('api/acceso/host');
        if(!$respuesta['error']) {
            $arrDatos = $respuesta['datos'];
            $hosts = $arrDatos['accesoHost'];
        }
        return $this->render('niquel/acceso/lista.html.twig', [
            'hosts' => $hosts,
            'form' => $form->createView()]);
    }

}