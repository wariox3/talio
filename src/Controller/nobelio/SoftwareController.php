<?php
namespace App\Controller\nobelio;

use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SoftwareController extends AbstractController
{
    // Filtro por set_pruebas_aceptado. Nobelio lo recibe como true/false; el
    // vacio no manda el parametro y trae los dos.
    private const SET_PRUEBAS = [
        'Todos' => '',
        'Aceptado' => 'true',
        'Sin aceptar' => 'false',
    ];

    /**
     * Software DIAN de todos los emisores.
     *
     * El filtro inicial puede llegar por la query (?set_pruebas_aceptado=false),
     * que es como enlaza la tarjeta del monitor.
     */
    #[Route('/nobelio/software/lista', name: 'nobelio_software_lista')]
    public function lista(Request $request, Nobelio $nobelio): Response
    {
        $form = $this->createFormBuilder()
            ->add('setPruebas', ChoiceType::class, ['required' => false, 'choices' => self::SET_PRUEBAS])
            ->add('emisor', TextType::class, ['required' => false, 'attr' => ['inputmode' => 'numeric']])
            ->add('btnFiltrar', SubmitType::class, ['label' => 'Filtrar'])
            ->add('btnAnterior', SubmitType::class, ['label' => 'Anterior'])
            ->add('btnSiguiente', SubmitType::class, ['label' => 'Siguiente'])
            ->getForm();
        $form->handleRequest($request);

        // La pagina viaja en un hidden propio, fuera del form, para poder
        // recalcularla despues de handleRequest sin pelearse con el form.
        $pagina = max(1, (int) $request->request->get('pagina', 1));

        if ($form->isSubmitted() && $form->isValid()) {
            $setPruebas = (string) $form->get('setPruebas')->getData();
            $emisor = trim((string) $form->get('emisor')->getData());
            if ($form->get('btnFiltrar')->isClicked()) {
                $pagina = 1;
            } elseif ($form->get('btnAnterior')->isClicked()) {
                $pagina = max(1, $pagina - 1);
            } elseif ($form->get('btnSiguiente')->isClicked()) {
                $pagina++;
            }

            // El filtro y la pagina pasan a la query con un redirect: asi la
            // url los lleva y, al volver de la ficha del emisor, la lista sale
            // igual en vez de pedir reenviar el formulario.
            $query = array_filter([
                'set_pruebas_aceptado' => $setPruebas,
                'emisor' => $emisor,
                'pagina' => $pagina > 1 ? $pagina : '',
            ], fn ($valor) => $valor !== '');

            return $this->redirectToRoute('nobelio_software_lista', $query);
        }

        $setPruebas = (string) $request->query->get('set_pruebas_aceptado', '');
        if (!in_array($setPruebas, self::SET_PRUEBAS, true)) {
            $setPruebas = '';
        }
        $emisor = trim((string) $request->query->get('emisor', ''));
        $pagina = max(1, (int) $request->query->get('pagina', 1));
        // Un form enviado pero invalido (p. ej. token vencido) ya no admite
        // setData: se pinta con lo que trajo.
        if (!$form->isSubmitted()) {
            $form->get('setPruebas')->setData($setPruebas);
            $form->get('emisor')->setData($emisor);
        }

        $parametros = ['page' => $pagina];
        if ($setPruebas !== '') {
            $parametros['set_pruebas_aceptado'] = $setPruebas;
        }
        // Nobelio ignora un emisor que no sea entero; mejor no mandarlo.
        if (ctype_digit($emisor)) {
            $parametros['emisor'] = $emisor;
        }

        $software = [];
        $total = 0;
        $hayAnterior = false;
        $haySiguiente = false;

        $respuesta = $nobelio->consumoGet('api/emisores/software/', $parametros);
        if ($respuesta['error']) {
            $this->addFlash('danger', "Nobelio: {$respuesta['mensaje']}");
        } else {
            $datos = $respuesta['datos'];
            $software = $datos['results'] ?? [];
            $total = (int) ($datos['count'] ?? 0);
            $hayAnterior = !empty($datos['previous']);
            $haySiguiente = !empty($datos['next']);
        }

        return $this->render('nobelio/software/lista.html.twig', [
            'form' => $form->createView(),
            'software' => $software,
            'total' => $total,
            'pagina' => $pagina,
            'hayAnterior' => $hayAnterior,
            'haySiguiente' => $haySiguiente,
        ]);
    }
}
