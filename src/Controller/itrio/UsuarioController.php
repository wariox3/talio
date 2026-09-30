<?php
namespace App\Controller\itrio;

use App\Utilidades\Itrio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UsuarioController extends AbstractController
{
    #[Route('/itrio/usuario/lista', name: 'itrio_usuario_lista')]
    public function lista(Request $request, Itrio $itrio): Response
    {
        $usuarios = [];
        $form = $this->createFormBuilder()
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $respuesta = $itrio->consumoGet('seguridad/usuario/');
            if ($respuesta['error']) {
                $this->addFlash('danger', $respuesta['mensaje']);
            } else {
                $usuarios = $respuesta['datos'];
            }
        }

        return $this->render('itrio/usuario/lista.html.twig', [
            'usuarios' => $usuarios,
            'form' => $form->createView(),
        ]);
    }
}
