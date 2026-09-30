<?php

namespace App\Tests\Utilidades;

use App\Utilidades\Excel;
use OpenSpout\Reader\XLSX\Reader;
use PHPUnit\Framework\TestCase;

class ExcelTest extends TestCase
{
    public function testGeneraElXlsxComoDescarga(): void
    {
        $respuesta = (new Excel())->descarga('consumos.xlsx', 'clientes', ['Id', 'Nombre'], [[1, 'Uno'], [2, null]]);
        $ruta = $respuesta->getFile()->getPathname();

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $respuesta->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename=consumos.xlsx', $respuesta->headers->get('Content-Disposition'));

        $reader = new Reader();
        $reader->open($ruta);
        $filas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            $this->assertSame('clientes', $hoja->getName());
            foreach ($hoja->getRowIterator() as $fila) {
                $filas[] = $fila->toArray();
            }
        }
        $reader->close();
        unlink($ruta);

        $this->assertSame([['Id', 'Nombre'], [1, 'Uno'], [2, '']], $filas);
    }
}
