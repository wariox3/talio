<?php

namespace App\Utilidades;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cliente de Backblaze B2 (API nativa v2) para el bucket de backups.
 *
 * B2 no tiene URL fija: b2_authorize_account devuelve la apiUrl y la
 * downloadUrl de la cuenta, por eso la URL base es vacia y cada peticion va
 * con la URL completa. Se autoriza en cada peticion de pagina; es una
 * utilidad de uso ocasional y asi no hay token que caduque en sesion.
 *
 * Las descargas no pasan por Talio: se firma un token de descarga de corta
 * vida para un solo archivo y se redirige al navegador directo a B2. Los
 * backups pueden pesar gigas y no deben cargarse en la memoria de PHP.
 */
class Backblaze extends ClienteApi
{
    private const URL_AUTORIZAR = 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account';

    /** Segundos que vale el enlace de descarga firmado. */
    private const VIGENCIA_DESCARGA = 300;

    /** Tope de paginas de 1000 entradas al listar un directorio. */
    private const MAX_PAGINAS = 10;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(env: 'B2_KEY_ID')] private readonly string $keyId,
        #[Autowire(env: 'B2_APPLICATION_KEY')] private readonly string $applicationKey,
        #[Autowire(env: 'B2_BUCKET')] private readonly string $bucket,
    ) {
        parent::__construct($httpClient, '', 'Backblaze');
    }

    /**
     * Carpetas y archivos directamente bajo $prefijo ("" es la raiz; si no,
     * termina en "/"). Exito: ['error' => false, 'carpetas' => [...],
     * 'archivos' => [...]], cada archivo con nombre, tamano y fecha (ms).
     */
    public function listar(string $prefijo): array
    {
        $sesion = $this->autorizar();
        if ($sesion['error']) {
            return $sesion;
        }

        $carpetas = [];
        $archivos = [];
        $inicio = null;
        for ($pagina = 0; $pagina < self::MAX_PAGINAS; $pagina++) {
            $respuesta = $this->peticion('POST', $sesion['apiUrl'] . '/b2api/v2/b2_list_file_names', [
                'headers' => ['Authorization' => $sesion['token']],
                'json' => [
                    'bucketId' => $sesion['bucketId'],
                    'prefix' => $prefijo,
                    'delimiter' => '/',
                    'maxFileCount' => 1000,
                    'startFileName' => $inicio,
                ],
            ]);
            if ($respuesta['error']) {
                return $respuesta;
            }

            foreach ($respuesta['datos']['files'] ?? [] as $archivo) {
                if ($archivo['action'] === 'folder') {
                    $carpetas[] = $archivo['fileName'];
                } elseif ($archivo['action'] === 'upload') {
                    $archivos[] = [
                        'nombre' => $archivo['fileName'],
                        'tamano' => $archivo['contentLength'],
                        'fecha' => $archivo['uploadTimestamp'],
                    ];
                }
            }

            $inicio = $respuesta['datos']['nextFileName'] ?? null;
            if ($inicio === null) {
                break;
            }
        }

        return ['error' => false, 'carpetas' => $carpetas, 'archivos' => $archivos, 'incompleto' => $inicio !== null];
    }

    /**
     * URL de descarga directa a B2 de un archivo del bucket, firmada solo
     * para ese archivo y valida VIGENCIA_DESCARGA segundos.
     */
    public function urlDescarga(string $nombreArchivo): array
    {
        $sesion = $this->autorizar();
        if ($sesion['error']) {
            return $sesion;
        }

        $disposicion = 'attachment; filename="' . str_replace('"', '', basename($nombreArchivo)) . '"';
        $respuesta = $this->peticion('POST', $sesion['apiUrl'] . '/b2api/v2/b2_get_download_authorization', [
            'headers' => ['Authorization' => $sesion['token']],
            'json' => [
                'bucketId' => $sesion['bucketId'],
                'fileNamePrefix' => $nombreArchivo,
                'validDurationInSeconds' => self::VIGENCIA_DESCARGA,
                'b2ContentDisposition' => $disposicion,
            ],
        ]);
        if ($respuesta['error']) {
            return $respuesta;
        }

        // Cada segmento codificado, las "/" del nombre se conservan.
        $ruta = implode('/', array_map('rawurlencode', explode('/', $nombreArchivo)));
        $url = $sesion['downloadUrl'] . '/file/' . rawurlencode($this->bucket) . '/' . $ruta . '?' . http_build_query([
            'Authorization' => $respuesta['datos']['authorizationToken'],
            'b2ContentDisposition' => $disposicion,
        ], '', '&', PHP_QUERY_RFC3986);

        return ['error' => false, 'url' => $url];
    }

    /**
     * Token de la cuenta, URLs y bucketId del bucket configurado. Si la llave
     * esta restringida a ese bucket, el id ya viene en la autorizacion; si no,
     * se busca por nombre.
     */
    private function autorizar(): array
    {
        if ($this->keyId === '' || $this->applicationKey === '' || $this->bucket === '') {
            return ['error' => true, 'status' => 0, 'mensaje' => 'Falta configurar B2_KEY_ID, B2_APPLICATION_KEY o B2_BUCKET.'];
        }

        $respuesta = $this->peticion('GET', self::URL_AUTORIZAR, ['auth_basic' => [$this->keyId, $this->applicationKey]]);
        if ($respuesta['error']) {
            return $respuesta;
        }

        $datos = $respuesta['datos'];
        $sesion = [
            'error' => false,
            'token' => $datos['authorizationToken'],
            'apiUrl' => $datos['apiUrl'],
            'downloadUrl' => $datos['downloadUrl'],
            'bucketId' => null,
        ];

        if (($datos['allowed']['bucketName'] ?? null) === $this->bucket) {
            $sesion['bucketId'] = $datos['allowed']['bucketId'];

            return $sesion;
        }

        $buckets = $this->peticion('POST', $sesion['apiUrl'] . '/b2api/v2/b2_list_buckets', [
            'headers' => ['Authorization' => $sesion['token']],
            'json' => ['accountId' => $datos['accountId'], 'bucketName' => $this->bucket],
        ]);
        if ($buckets['error']) {
            return $buckets;
        }

        $sesion['bucketId'] = $buckets['datos']['buckets'][0]['bucketId'] ?? null;
        if ($sesion['bucketId'] === null) {
            return ['error' => true, 'status' => 404, 'mensaje' => "Backblaze: no existe el bucket {$this->bucket} o la llave no tiene acceso."];
        }

        return $sesion;
    }

    /** B2 responde los errores como {"status", "code", "message"}. */
    protected function mensajeDeError(array $cuerpo, int $status): string
    {
        if (isset($cuerpo['message']) && is_string($cuerpo['message']) && trim($cuerpo['message']) !== '') {
            return 'Backblaze: ' . trim($cuerpo['message']);
        }

        return parent::mensajeDeError($cuerpo, $status);
    }
}
