<?php
namespace Enex\Core\CatalogHelper;

use \PhpOffice\PhpSpreadsheet\Spreadsheet;
use \PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Citfact\Sitecore\Manufacturer\ManufacturerManager;

class CatalogProps
{
    /**
     * Дерево разделов
     * @var array
     */
    private $tree;

    /**
     * ID инфоблока каталог
     * @var int
     */
    private $iBlockID;

    /**
     * Массив свойств исключений
     * @var array
     */
    private $arExc = [
        'MINIMUM_PRICE',
        'MAXIMUM_PRICE',
        'ADDITIONAL_PROPERTIES',
        'RECOMENDED_ACTIVE_FROM',
        'RECOMENDED_ACTIVE_TO',
        'KHIT',
        'PHOTO',
        'NA_ZAKAZ',
        'MORE_PHOTO',
        'RECOMENDED',
        'MIN_ORDER',
        'SKIDKA',
        'NOVINKA',
        'OBOZNACHENIYA_DLYA_TOVAROV',
        'CML2_ARTICLE',
        'MANUFACTURER'
    ];

    public function __construct()
    {
        $core = \Citfact\SiteCore\Core::getInstance();
        $this->iBlockID = $core->getIblockId($core::IBLOCK_CODE_CATALOG_NEW);
    }

    /**
     * Получить дерево разделов
     * @return array
     */
    private function getCatalogSections()
    {
        $arrFilter = [
            'IBLOCK_ID' => $this->iBlockID,
            'ACTIVE' => 'Y',
            'GLOBAL_ACTIVE' => 'Y',
        ];
        $arSelect = [
            'ID',
            'NAME',
            'DEPTH_LEVEL'
        ];

        $arSections = [];

        $dbSection = \CIBlockSection::GetList(array("left_margin"=>"asc"), $arrFilter, true, $arSelect);

        while ($obSection = $dbSection->GetNext()) {
            if ($obSection['ELEMENT_CNT'] == 0) {
                continue;
            }

            switch ($obSection['DEPTH_LEVEL']) {

                case 1:
                    $arSections['ROOT'][] = $obSection;
                    break;

                default:
                    $nav = \CIBlockSection::GetNavChain($this->iBlockID, $obSection['ID'], array('ID', 'NAME'));
                    $path = [];
                    $rootID = '';
                    while ($arSectionPath = $nav->GetNext()) {
                        if (empty($rootID)) {
                            $rootID = $arSectionPath['ID'];
                        }
                        $path[] = $arSectionPath['NAME'];
                    }
                    array_shift($path);
                    $path = implode('->', $path);
                    $obSection['PATH'] = $path;
                    $arSections['NESTED'][$rootID][] = $obSection;
                    break;
            }
        }

        return $arSections;
    }

    /**
     * Создать лист xlsx
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet
     * @param array $rootSection массив информации о разделе
     * @return \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
     */
    private function getSheet($worksheet, $rootSection)
    {
        echo '[PROCESS] - Обрабатываем секцию -> '.$rootSection['NAME'].PHP_EOL;

        $worksheet->setTitle(substr($rootSection['NAME'], 0, 30));

        $row = 1;

        foreach ($this->tree['NESTED'][$rootSection['ID']] as $nested_section) {
            $sectionId = $nested_section['ID'];
            $section_path = $nested_section['PATH'];
            $section_props = $this->getPropsBySectionID($sectionId);
            $section_manufacturers = array_values($this->getManufacturersBySectionID($sectionId));
            $section_start_column = $nested_section['DEPTH_LEVEL'] - 1;
            $section_manufs_columns = [];

            $worksheet->getColumnDimensionByColumn($section_start_column)->setWidth(60);

            $worksheet->setCellValueByColumnAndRow($section_start_column, $row, $section_path);
            $row++;

            foreach ($section_manufacturers as $index => $manufacturer) {
                $manuf_readable = ManufacturerManager::getInstance()->getByXMLIdDirectory($manufacturer)['NAME'];

                $column = $section_start_column;

                for ($n = 0; $n< $index+1; $n++) {
                    $column++;
                }

                $worksheet->setCellValueByColumnAndRow($column, $row, $manuf_readable);
                $section_manufs_columns[$manufacturer] = $column;
            }

            unset($manufacturer);

            $worksheet->getRowDimension($row)->setOutlineLevel(1);
            $worksheet->getRowDimension($row)->setCollapsed(true);
            $worksheet->getRowDimension($row)->setVisible(false);

            $row++;

            foreach ($section_props as $section_prop) {
                $status = [];
                foreach ($section_manufacturers as $section_manufacturer) {
                    $status_one = $this->getPropStatusByManuf($section_manufacturer, $section_prop['CODE'], $sectionId);
                    if ($status_one == 'V') {
                        $status[$section_manufacturer] = $status_one;
                    }
                }
                if (sizeof($status) == 0) {
                    continue;
                }
                $worksheet->setCellValueByColumnAndRow($section_start_column, $row, $section_prop['NAME']);
                foreach ($status as $manufacturer => $value) {
                    $worksheet->setCellValueByColumnAndRow($section_manufs_columns[$manufacturer], $row, $value);
                }
                unset($manufacturer);
                $worksheet->getRowDimension($row)->setOutlineLevel(1);
                $worksheet->getRowDimension($row)->setCollapsed(true);
                $worksheet->getRowDimension($row)->setVisible(false);
                $row++;
            }

            $row += 5;
        }

        return $worksheet;
    }

    /**
     * Получить все свойства элементов по id раздела
     * @param int $sectionId id раздела
     * @return array
     */
    private function getPropsBySectionID($sectionId)
    {
        $arFilter = [
            'sectionId' => $sectionId,
            'INCLUDE_SUBSECTIONS' => 'Y',
            'ACTIVE' => 'Y'
        ];

        $linkedProps = \CIBlockElement::GetPropertyValues($this->iBlockID, $arFilter, false);
        $propsArr = [];
        while ($row = $linkedProps->GetNext()) {
            foreach ($row as $key => $val) {
                if (gettype($key) === 'integer' && $val) {
                    $propsArr[] = $key;
                }
            }
        }

        $propsArr = array_unique($propsArr);
        sort($propsArr);

        $return_props_arr = [];
        foreach ($propsArr as $propID) {
            $prop = \CIBlockProperty::GetByID($propID, $this->iBlockID);
            if ($prop_info = $prop->GetNext()) {
                if (in_array($prop_info['CODE'], $this->arExc)) {
                    continue;
                }
                $prop_return_info['NAME'] = $prop_info['NAME'];
                $prop_return_info['CODE'] = $prop_info['CODE'];
                $return_props_arr[] = $prop_return_info;
            }
        }
        
        return $return_props_arr;
    }

    /**
     * Получить массив производителей по id раздела
     * @param int $sectionId id раздела
     * @return array
     */
    private function getManufacturersBySectionID($sectionId)
    {
        $arFilter = array(
            'sectionId' => $sectionId,
            'INCLUDE_SUBSECTIONS' => 'Y'
        );

        $props = \CIBlockElement::GetPropertyValues($this->iBlockID, $arFilter, false, array('ID' => 152));

        $manufacturers = [];
        while ($prop_var = $props->GetNext()) {
            $manufacturers[] = $prop_var['~152'];
        }

        $manufacturers = array_unique($manufacturers);
        return $manufacturers;
    }

    /**
     * Получить статус наличия свойства у товаров производителя
     * @param string $manufacturer код производителя
     * @param string $propCode код свойства
     * @param int $sectionId id раздела 
     * @return string V Свойство имеется у производителя
     * @return string N Свойства у производителя нет
     */
    private function getPropStatusByManuf($manufacturer, $propCode, $sectionId)
    {
        $arFilter = array(
            'IBLOCK_ID' => $this->iBlockID,
            '!PROPERTY_'.$propCode => false,
            '=PROPERTY_MANUFACTURER' => $manufacturer,
            'sectionId' => $sectionId,
            'INCLUDE_SUBSECTIONS' => 'Y'
        );

        $goods = \CIBlockElement::GetList([], $arFilter, false, ['nTopCount' => 1]);
        $result = '';

        unset($manufacturer);

        if ($ob = $goods->GetNext()) {
            $result = $ob['ID'];
        }

        if ($result !== '') {
            return 'V';
        }
        return 'N';
    }

    /**
     * Инициировать создание xlsx
     * @param string $pathToFile путь для сохранения файла
     * @return void
     */
    public function run($pathToFile)
    {
        $this->tree = $this->getCatalogSections();

        if (empty($this->tree)) {
            die('[ERROR] - Секции каталога не определены!'.PHP_EOL) ;
        }

        echo '[SUCCESS] - Секции каталога определены успешно!'.PHP_EOL;

        $spreadsheet = new Spreadsheet();

        foreach ($this->tree['ROOT'] as $rootSection) {
            $worksheet = $spreadsheet->createSheet();
            $this->getSheet($worksheet, $rootSection);
        }

        $spreadsheet->removeSheetByIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->save($pathToFile);

        if (file_exists($pathToFile)) {
            echo '[SUCCESS] - Файл успешно создан! Dir: '.$pathToFile.PHP_EOL;
        } else {
            echo '[ERROR] - Ошибка создания файла!'.PHP_EOL;
        }
    }
}
