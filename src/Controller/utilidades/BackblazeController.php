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
     * Explorador por directorios del bucket de backups del servidor elegido
     * en ?servidor=. El directorio actual va en ?ruta= ("" la raiz, si no
     * termina en "/").
     */
    #[Route('/utilidades/backblaze/backups', name: 'utilidades_backblaze_backups')]
    public function backups(Request $request, Backblaze $backblaze): Response
    {
        $servidor = $this->servidor($request);

        return $this->render('utilidades/backblaze/backups.html.twig', [
            'servidores' => array_keys(Backblaze::BUCKETS),
            'servidor' => $servidor,
            ...$this->explorar($request, $backblaze, Backblaze::BUCKETS[$servidor]),
        ]);
    }

    #[Route('/utilidades/backblaze/descargar', name: 'utilidades_backblaze_descargar')]
    public function descargar(Request $request, Backblaze $backblaze): Response
    {
        $servidor = $this->servidor($request);

        return $this->redirigirDescarga($request, $backblaze, Backblaze::BUCKETS[$servidor], 'utilidades_backblaze_backups', ['servidor' => $servidor]);
    }

    /** Explorador por directorios del bucket de archivos (Backblaze::BUCKET_ARCHIVOS). */
    #[Route('/utilidades/backblaze/archivos', name: 'utilidades_backblaze_archivos')]
    public function archivos(Request $request, Backblaze $backblaze): Response
    {
        return $this->render('utilidades/backblaze/archivos.html.twig', [
            'bucket' => Backblaze::BUCKET_ARCHIVOS,
            ...$this->explorar($request, $backblaze, Backblaze::BUCKET_ARCHIVOS),
        ]);
    }

    #[Route('/utilidades/backblaze/archivos/descargar', name: 'utilidades_backblaze_archivos_descargar')]
    public function descargarArchivo(Request $request, Backblaze $backblaze): Response
    {
        return $this->redirigirDescarga($request, $backblaze, Backblaze::BUCKET_ARCHIVOS, 'utilidades_backblaze_archivos', []);
    }

    /**
     * Carpetas, archivos y migas del directorio ?ruta= de $bucket. Los errores
     * y avisos van como flash.
     */
    private function explorar(Request $request, Backblaze $backblaze, string $bucket): array
    {
        $ruta = ltrim($request->query->getString('ruta'), '/');
        if ($ruta !== '' && !str_ends_with($ruta, '/')) {
            $ruta .= '/';
        }

        $carpetas = [];
        $archivos = [];
        $respuesta = $backblaze->listar($bucket, $ruta);
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

        return [
            'directorio' => $ruta,
            'migas' => $migas,
            'carpetas' => $carpetas,
            'archivos' => $archivos,
        ];
    }

    /**
     * Redirige a la URL firmada de ?archivo= en $bucket; si falla, vuelve al
     * directorio del archivo en $rutaListado con $parametros.
     */
    private function redirigirDescarga(Request $request, Backblaze $backblaze, string $bucket, string $rutaListado, array $parametros): Response
    {
        $archivo = $request->query->getString('archivo');
        $respuesta = $archivo === '' ? ['error' => true, 'mensaje' => 'No se indicó el archivo.'] : $backblaze->urlDescarga($bucket, $archivo);
        if ($respuesta['error']) {
            $this->addFlash('danger', $respuesta['mensaje']);
            $carpeta = str_contains($archivo, '/') ? substr($archivo, 0, strrpos($archivo, '/') + 1) : '';

            return $this->redirectToRoute($rutaListado, [...$parametros, 'ruta' => $carpeta]);
        }

        return $this->redirect($respuesta['url']);
    }

    /** Servidor de ?servidor=; si falta o no existe, el primero de Backblaze::BUCKETS. */
    private function servidor(Request $request): string
    {
        $servidor = $request->query->getString('servidor');

        return isset(Backblaze::BUCKETS[$servidor]) ? $servidor : array_key_first(Backblaze::BUCKETS);
    }
}
