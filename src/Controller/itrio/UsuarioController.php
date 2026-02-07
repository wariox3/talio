<?php
namespace App\Controller\itrio;

use App\Utilidades\Itrio;
use App\Utilidades\Mensajes;
use App\Utilidades\Niquel;
use App\Utilidades\SpaceDO;
use Box\Spout\Writer\Common\Creator\Style\StyleBuilder;
use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class UsuarioController extends AbstractController
{
    #[Route('/itrio/usuario/lista', name: 'itrio_usuario_lista')]
    public function lista(Request $request, Itrio $itrio): Response
    {
        $usuarios = [];
        $form = $this->createFormBuilder()
            ->add('btnFiltrar', SubmitType::class, array('label' => 'Filtrar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnFiltrar')->isClicked()) {
                $respuesta = $itrio->consumoGet('seguridad/usuario/');
                if($respuesta['error']) {
                    Mensajes::error($respuesta['mensaje']);
                } else {
                    $usuarios = $respuesta['datos'];
                }
            }
        }
        return $this->render('itrio/usuario/lista.html.twig', [
            'usuarios' => $usuarios,
            'form' => $form->createView()]);
    }


}