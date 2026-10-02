<?php
namespace App\Controller\utilidades;

use App\Utilidades\Backblaze;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BackblazeController extends AbstractController
{
    /**
     * Explorador del bucket de backups por directorios. El directorio actual
     * va en ?ruta= ("" la raiz, si no termina en "/").
     */
    #[Route('/utilidades/backblaze/backups', name: 'utilidades_backblaze_backups')]
    public function backups(Request $request, Backblaze $backblaze): Response
    {
        $ruta = ltrim($request->query->getString('ruta'), '/');
        if ($ruta !== '' && !str_ends_with($ruta, '/')) {
            $ruta .= '/';
        }

        $carpetas = [];
        $archivos = [];
        $respuesta = $backblaze->listar($ruta);
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
        } else {
            $carpetas = $respuesta['carpetas'];
            $archivos = $respuesta['archivos'];
            if ($respuesta['incompleto']) {
                $this->addFlash('warning', 'El directorio tiene demasiadas entradas, solo se muestran las primeras.');
            }
        }

        // Migas: cada segmento con la ruta hasta el.
        $migas = [];
        $acumulado = '';
        foreach (array_filter(explode('/', $ruta), 'strlen') as $segmento) {
            $acumulado .= $segmento . '/';
            $migas[] = ['nombre' => $segmento, 'ruta' => $acumulado];
        }

        return $this->render('utilidades/backblaze/backups.html.twig', [
            'directorio' => $ruta,
            'migas' => $migas,
            'carpetas' => $carpetas,
            'archivos' => $archivos,
        ]);
    }

    #[Route('/utilidades/backblaze/descargar', name: 'utilidades_backblaze_descargar')]
    public function descargar(Request $request, Backblaze $backblaze): Response
    {
        $archivo = $request->query->getString('archivo');
        $respuesta = $archivo === '' ? ['error' => true, 'mensaje' => 'No se indicó el archivo.'] : $backblaze->urlDescarga($archivo);
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
            $carpeta = str_contains($archivo, '/') ? substr($archivo, 0, strrpos($archivo, '/') + 1) : '';

            return $this->redirectToRoute('utilidades_backblaze_backups', ['ruta' => $carpeta]);
        }

        return $this->redirect($respuesta['url']);
    }
}
