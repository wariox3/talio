<?php
namespace App\Controller\itrio;

use App\Utilidades\Itrio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ContenedorController extends AbstractController
{
    #[Route('/itrio/contenedor/lista', name: 'itrio_contenedor_lista')]
    public function lista(Request $request, Itrio $itrio): Response
    {
        $contenedores = [];
        $form = $this->createFormBuilder()
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $respuesta = $itrio->consumoGet('contenedor/contenedor/?ordering=-id');
            if ($respuesta['error']) {
                $this->addFlash('danger', $respuesta['mensaje']);
            } else {
                $contenedores = $respuesta['datos']['results'] ?? [];
            }
        }

        return $this->render('itrio/contenedor/lista.html.twig', [
            'contenedores' => $contenedores,
            'form' => $form->createView(),
        ]);
    }
}
