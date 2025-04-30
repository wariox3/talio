<?php

namespace App\Utilidades;


class Softgic
{

    public function __construct()
    {

    }

    public function consultaSuscriptor($suscriptor): array
    {
        $respuesta = $this->consumirGet("ConValidacionPrevia/ResumenSuscriptor/{$suscriptor}", []);
        if($respuesta['error'] == false) {
            $datos = $respuesta['datos'];
            $arrRespuesta = [
                'error' => false,
                'suscriptor' => $datos['Suscriptor'],
                'resoluciones' => $datos['ResolucionesFacturas']['ResolucionesFacturacion']
            ];
        } else {
            $arrRespuesta = [
                'error' => true
            ];
        }
        return $arrRespuesta;
    }

    public function consultaEmpleador($empleador): array
    {
        $respuesta = $this->consumirGet("Empleadores/ObtenerPorId/{$empleador}", []);
        if($respuesta['error'] == false) {
            $datos = $respuesta['datos'];
            $arrRespuesta = [
                'error' => false,
                'empleador' => $datos['Data']
            ];
        } else {
            $arrRespuesta = [
                'error' => true
            ];
        }
        return $arrRespuesta;
    }

    public function consultaConsumo($aliado, $anio, $mes): array
    {
        $respuesta = $this->consumirGet("ConValidacionPrevia/ConsultarConsumosAliado/{$aliado}/{$anio}/{$mes}", []);
        if($respuesta['error'] == false) {
            $datos = $respuesta['datos'];
            $arrRespuesta = [
                'error' => false,
                'consumos' => $datos
            ];
        } else {
            $arrRespuesta = [
                'error' => true
            ];
        }
        return $arrRespuesta;
    }

    private function consumirPost($url, $arDatos)
    {
        $url = "https://apps.kiai.co/api/{$url}";
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERPWD, $_ENV['KIAI_TOKEN']);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $datosJSON = json_encode($arDatos);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $datosJSON);
        $respuestaCruda = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $respuesta = json_decode($respuestaCruda, true);
        if ($status == 500) {
            $mensaje = is_string($respuesta) ? $respuesta : "Status 500";
            return [
                "error" => true,
                "mensaje" => $mensaje
            ];
        } else {
            if (isset($respuesta['ExceptionType'])) {
                return [
                    "error" => true,
                    "mensaje" => $respuesta['ExceptionMessage']
                ];
            } else {
                return [
                    "error" => false,
                    "datos" => $respuesta
                ];
            }
        }
    }

    private function consumirGet($url, $arDatos)
    {
        $url = "https://apps.kiai.co/api/{$url}";
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERPWD, $_ENV['KIAI_TOKEN']);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        $datosJSON = json_encode($arDatos);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $datosJSON);

        $respuestaCruda = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $respuesta = json_decode($respuestaCruda, true);
        if ($status == 500) {
            $mensaje = is_string($respuesta) ? $respuesta : "Status 500";
            return [
                "error" => true,
                "mensaje" => $mensaje
            ];
        } else {
            if (isset($respuesta['ExceptionType'])) {
                return [
                    "error" => true,
                    "mensaje" => $respuesta['ExceptionMessage']
                ];
            } else {
                return [
                    "error" => false,
                    "datos" => $respuesta
                ];
            }
        }
    }
}