<?php

namespace App\Utilidades;

use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Class Mensajes
 * Esta clase permite escribir mensajes para el usuario utilizando el sistema de flashbags de symfony.
 * @package App\Util
 */
final class Mensajes
{
    const TYPES = [
        'error' => 'danger',
        'ok' => 'success',
        'information' => 'info',
        'warning' => 'warning',
    ];

    private $session = null;

    private function __construct()
    {
        $this->session = new Session();
    }

    /**
     * Método para obtener la instancia única de mensajería.
     * @return Mensajes|null
     */
    private static function getInstance()
    {
        static $instance = null;
        if($instance === null) {
            $instance = new Mensajes();
        }
        return $instance;
    }

    /**
     * Esta función permite enviar un flash personalizado.
     * @param $message
     * @param $type
     */
    public static function notify($message, $type)
    {
        self::getInstance()->setFlash($message, $type);
    }

    /**
     * Esta función permite enviar un flash de tipo success como mensaje al flashbag.
     * @param $message
     */
    public static function success($message)
    {
        self::getInstance()->notify($message, self::TYPES['ok']);
    }

    /**
     * Esta función permite enviar un flash de tipo error con el mensaje al flashbag.
     * @param $message
     */
    public static function error($message)
    {
        self::getInstance()->notify($message, self::TYPES['error']);
    }

    /**
     * Esta función permite enviar un flash de tipo warning con el mensaje al flashbag.
     * @param $message
     */
    public static function warning($message)
    {
        self::getInstance()->notify($message, self::TYPES['warning']);
    }

    /**
     * Esta función permite enviar un flash de tipo info con el mensaje al flashbag.
     * @param $message
     */
    public static function info($message)
    {
        self::getInstance()->notify($message, self::TYPES['information']);
    }

    /**
     * Esta función escribe directamente en el flashbag de symfony.
     * @param $message
     * @param $type
     */
    private function setFlash($message, $type)
    {
        $this->session->getFlashBag()->add($type, $message);
    }

}