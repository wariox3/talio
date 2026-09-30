<?php

namespace App\Utilidades;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Genera un XLSX y lo devuelve como descarga.
 *
 * El archivo se escribe en el temporal del sistema y se borra despues de
 * enviarlo. Antes cada controlador escribia en /var/www/html/temporal (fuera
 * del proyecto, habia que crearla a mano en cada servidor) y respondia con
 * header()/readfile()/exit, saltandose Symfony.
 */
class Excel
{
    /**
     * @param list<string>           $encabezados
     * @param iterable<list<scalar|null>> $filas
     */
    public function descarga(string $nombreArchivo, string $hoja, array $encabezados, iterable $filas): BinaryFileResponse
    {
        $ruta = tempnam(sys_get_temp_dir(), 'talio_xlsx_');

        $writer = new Writer();
        $writer->openToFile($ruta);
        $writer->getCurrentSheet()->setName($hoja);

        $estiloEncabezado = (new Style())->setFontName('Arial')->setFontSize(8)->setFontBold()->setShouldWrapText(false);
        $estiloDetalle = (new Style())->setFontName('Arial')->setFontSize(8)->setShouldWrapText(false);

        $writer->addRow(Row::fromValues($encabezados, $estiloEncabezado));
        foreach ($filas as $fila) {
            $writer->addRow(Row::fromValues($fila, $estiloDetalle));
        }
        $writer->close();

        $respuesta = new BinaryFileResponse($ruta);
        $respuesta->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $respuesta->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $nombreArchivo);
        $respuesta->deleteFileAfterSend();

        return $respuesta;
    }
}
