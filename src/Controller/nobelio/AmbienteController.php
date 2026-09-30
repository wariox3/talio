<?php
namespace App\Controller\nobelio;

use App\Utilidades\Nobelio;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AmbienteController extends AbstractController
{
    /**
     * Seccion de Nobelio (prefijo de la ruta desde la que se cambio) => lista
     * a la que se vuelve. No se vuelve a la misma pagina: un detalle es de un
     * emisor o documento de otro ambiente y alli no existe.
     */
    private const LISTAS = [
        'nobelio_documento_' => 'nobelio_documento_lista',
        'nobelio_nomina_' => 'nobelio_nomina_lista',
    ];

    #[Route('/nobelio/ambiente', name: 'nobelio_ambiente', methods: ['POST'])]
    public function cambiar(Request $request, Nobelio $nobelio): Response
    {
        $ambiente = (string) $request->request->get('ambiente', '');

        if (!$this->isCsrfTokenValid('nobelio-ambiente', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'La petición no es válida.');
        } elseif (!$nobelio->cambiarAmbiente($ambiente)) {
            $this->addFlash('danger', 'Ese ambiente de Nobelio no existe o no está configurado en el .env.');
        } else {
            $this->addFlash('success', 'Trabajando con Nobelio en ' . $nobelio->nombreAmbiente() . '.');
        }

        $desde = (string) $request->request->get('ruta', '');
        foreach (self::LISTAS as $prefijo => $lista) {
            if (str_starts_with($desde, $prefijo)) {
                return $this->redirectToRoute($lista);
            }
        }

        return $this->redirectToRoute('nobelio_emisor_lista');
    }
}
