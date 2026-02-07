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

class ContenedorController extends AbstractController
{
    #[Route('/itrio/contenedor/lista', name: 'itrio_contenedor_lista')]
    public function lista(Request $request, Itrio $itrio): Response
    {
        $contenedores = [];
        $form = $this->createFormBuilder()
            ->add('btnFiltrar', SubmitType::class, array('label' => 'Filtrar'))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            if ($form->get('btnFiltrar')->isClicked()) {
                //$filtros = $this->filtros($form);
                $respuesta = $itrio->consumoGet('contenedor/contenedor/?ordering=-id');
                if($respuesta['error']) {
                    Mensajes::error($respuesta['mensaje']);
                } else {
                    $contenedores = $respuesta['datos']['results'];
                }
            }
        }
        return $this->render('itrio/contenedor/lista.html.twig', [
            'contenedores' => $contenedores,
            'form' => $form->createView()]);
    }

    private function filtros($form)
    {
        $filtros = [
            'fecha_desde' => $form->get('fechaDesde')->getData()->format('Y-m-d'),
            'fecha_hasta' => $form->get('fechaHasta')->getData()->format('Y-m-d')
        ];
        return $filtros;
    }

}