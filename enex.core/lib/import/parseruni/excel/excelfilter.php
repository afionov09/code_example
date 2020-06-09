<?php

namespace Enex\Core\Import\ParserUni\Excel;

/**
 * Класс фильтра загрузки файла Excel
 */
class ExcelFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter
{

    /**
     * Номер стартовой строки для фильтра.
     */
    private $startRow;

    /**
     * Номер финальной строки для фильтра.
     */
    private $endRow;

    public function __construct($startRow, $endRow)
    {
        $this->startRow = $startRow;
        $this->endRow = $endRow;
    }

    //Функция IReadFilter
    public function readCell($column, $row, $worksheetName = '')
    {
        if ($row >= $this->startRow && $row <= $this->endRow) {
            return true;
        }
        return false;
    }
}
