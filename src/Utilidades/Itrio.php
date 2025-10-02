<?php

namespace App\Utilidades;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class Itrio
{

    public function __construct(private RequestStack $requestStack)
    {

    }

    public function consumoPost($url, $datos) {
        $client = HttpClient::create();
        $urlCompleta = $_ENV['BASE_ITRIO'] .  $url;
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

    public function consumoPath($url, $datos) {
        $client = HttpClient::create();
        $urlCompleta = $_ENV['BASE_ITRIO'] .  $url;
        try {
            $headers = [
                'Content-Type' => 'application/json',
            ];
            $response = $client->request('PATCH', $urlCompleta, [
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
        $urlCompleta = $_ENV['BASE_ITRIO'] .  $url;
        try {
            $token = $session->get('token');
            if (empty($token)) {
                $respuesta = $this->autenticar();
                if($respuesta["error"]) {
                    return [
                        "error" => true,
                        "mensaje" => $respuesta["mensaje"]
                    ];
                } else {
                    $token = $respuesta["token"];
                    $session->set('token', $token);
                }

            }
            $headers = ['Authorization' => 'Bearer ' . $token];
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

    public function consumoArchivoGet($url) {
        $session = $this->requestStack->getSession();
        $client = HttpClient::create();
        $urlCompleta = $_ENV['BASE_ITRIO'] .  $url;
        try {
            $token = $session->get('token');
            if (empty($token)) {
                $respuesta = $this->autenticar();
                if($respuesta["error"]) {
                    return [
                        "error" => true,
                        "mensaje" => $respuesta["mensaje"]
                    ];
                } else {
                    $token = $respuesta["token"];
                    $session->set('token', $token);
                }

            }
            $headers = ['Authorization' => 'Bearer ' . $token];
            $response = $client->request('GET', $urlCompleta, [
                'headers' => $headers,
            ]);
            $status = $response->getStatusCode();
            if($status == 200) {
                $content = $response->getContent();
                $responseHeaders = $response->getHeaders();
                return [
                    "error" => false,
                    "content" => $content,
                    "headers" => $responseHeaders,
                    "status" => $status
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

    public function autenticar()
    {
        $client = HttpClient::create();
        $urlAutenticacion = $_ENV['BASE_ITRIO'] . 'seguridad/login/';
        try {
            $response = $client->request('POST', $urlAutenticacion, [
                'json' => [
                    'username' => $_ENV['ITRIO_USUARIO'],
                    'password' => $_ENV['ITRIO_CLAVE'],
                    'proyecto' => 'RUTEOAPP'
                ]
            ]);

            $status = $response->getStatusCode();
            if ($status == 200) {
                $data = $response->toArray();
                return [
                    'error' => false,
                    'token' => $data['token'],
                ];
            } else {
                return [
                    "error" => true,
                    "mensaje" => "Error en la autenticacion"
                ];
            }
        } catch (TransportExceptionInterface $e) {
            return [
                "error" => true,
                "mensaje" => "Error en la autenticacion {$e->getMessage()}"
            ];
        }
    }
}