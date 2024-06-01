<?php

namespace App\Utilidades;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Wolframio
{

    public function __construct(private RequestStack $requestStack)
    {

    }

    public function consumoPost($url, $datos) {
        $client = HttpClient::create();
        $urlCompleta = $_ENV['BASE_WOLFRAMIO'] .  $url;
        try {
            $headers = [
                'Content-Type' => 'application/json',
            ];
            $response = $client->request('POST', $urlCompleta, [
                'headers' => $headers,
                'json' => $datos,
            ]);
            $status = $response->getStatusCode();
            if($status == 200) {
                $responseData = $response->toArray();
                return [
                    "error" => false,
                    "datos" => $responseData

                ];
            } elseif($status == 400) {
                $responseData = $response->toArray(false);
                return [
                    "error" => true,
                    "mensaje" => $responseData['mensaje']
                ];
            } else {
                return [
                    "error" => true,
                    "mensaje" => "El servidor no responde correctamente"
                ];
            }
        } catch (TransportExceptionInterface $e) {
            return [
                "error" => true,
                "mensaje" => $e->getMessage()
            ];
        }
    }

    public function consumoGet($url) {
        $session = $this->requestStack->getSession();
        $client = HttpClient::create();
        $urlCompleta = $_ENV['BASE_WOLFRAMIO'] .  $url;
        try {
            $headers = ['Authorization' => 'Bearer ' . $session->get('token')];
            $response = $client->request('GET', $urlCompleta, [
                'headers' => $headers,
            ]);
            $status = $response->getStatusCode();
            if($status == 200) {
                $responseData = $response->toArray();
                return [
                    "error" => false,
                    "datos" => $responseData
                ];
            } else {
                return [
                    "error" => true,
                    "mensaje" => "El servidor no resopnde correctamente"
                ];
            }
        } catch (TransportExceptionInterface $e) {
            return [
                "error" => true,
                "mensaje" => $e->getMessage()
            ];
        }
    }

    public function consumoDelete($url) {
        $session = $this->requestStack->getSession();
        $client = HttpClient::create();
        $urlCompleta = $_ENV['BASE'] .  $url;
        try {
            $headers = ['Authorization' => 'Bearer ' . $session->get('token')];
            $response = $client->request('DELETE', $urlCompleta, [
                'headers' => $headers,
            ]);
            $status = $response->getStatusCode();
            if($status == 200) {
                return ["error" => false];
            } else {
                return [
                    "error" => true,
                    "mensaje" => "El servidor no resopnde correctamente"
                ];
            }
        } catch (TransportExceptionInterface $e) {
            return [
                "error" => true,
                "mensaje" => $e->getMessage()
            ];
        }
    }
}