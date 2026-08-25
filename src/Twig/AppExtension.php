<?php

namespace App\Twig;

use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    /**
     * Tipos de flash que Mensajes puede emitir y la clase de aviso que les corresponde.
     * Cualquier otro tipo cae en el aviso neutro.
     */
    private const CLASES = [
        'success' => 'success',
        'danger' => 'danger',
        'error' => 'error',
        'warning' => 'warning',
        'info' => '',
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notificar', [$this, 'getNotifies']),
        ];
    }

    /**
     * Imprime los mensajes pendientes para el usuario y vacía el flashbag.
     */
    public function getNotifies(): string
    {
        $session = new Session();
        $flashes = $session->getFlashBag()->all();

        $avisos = [];
        foreach ($flashes as $tipo => $mensajes) {
            $clase = self::CLASES[$tipo] ?? '';
            foreach ($mensajes as $mensaje) {
                $avisos[] = sprintf(
                    '<div class="aviso %s"><span class="dot"></span><span>%s</span>'
                    . '<button type="button" class="cerrar" aria-label="Cerrar aviso"'
                    . ' onclick="this.parentNode.remove()">&times;</button></div>',
                    htmlspecialchars($clase, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars((string) $mensaje, ENT_QUOTES, 'UTF-8')
                );
            }
        }

        if (!$avisos) {
            return '';
        }

        return '<div class="avisos">' . implode('', $avisos) . '</div>';
    }
}
