<?php

namespace App\Utilidades;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;

class SpaceDO
{
    //https://docs.digitalocean.com/products/spaces/how-to/use-aws-sdks/
    private $cliente;

    public function __construct()
    {
        $this->cliente = new S3Client([
            'version' => 'latest',
            'region'  => $_ENV['DO_REGION'],
            'endpoint' => "https://{$_ENV['DO_REGION']}.digitaloceanspaces.com",
            'use_path_style_endpoint' => false,
            'credentials' => [
                'key'    => $_ENV['DO_CLAVE_ACCESO'],
                'secret' => $_ENV['DO_CLAVE_SECRETA'],
            ],
        ]);
    }

    public function subirB64($rutaDestino, $data, $contentType) {
        try {
            $result = $this->cliente->putObject([
                'Bucket' => $_ENV['DO_BUCKET'],
                'Key' => $rutaDestino,
                'Body' => $data,
                'ContentType' => $contentType,
                'ACL' => "public-read",
            ]);
        } catch (AwsException $e) {
            echo $e->getMessage();
        }
    }

    public function descargar($rutaDestino) {
        try {
            $result = $this->cliente->getObject([
                'Bucket' => $_ENV['DO_BUCKET'],
                'Key' => $rutaDestino,
            ]);
            $b64 = base64_encode($result['Body']);
            return [
                "error" => false,
                "b64" => $b64
            ];
        } catch (AwsException $e) {
            return [
                "error" => true,
                "mensaje" => $e->getMessage()
            ];
        }
    }

}