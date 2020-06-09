<?php
namespace Enex\Core\CatalogHelper;

use \PhpOffice\PhpSpreadsheet\Spreadsheet;
use \PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use \Bitrix\Iblock\InheritedProperty\SectionValues;

class CatalogSEO
{
    /**
     * Дерево разделов
     * @var array
     */
    private $sectionsTree;

    /**
     * ID инфоблока каталог
     * @var int
     */
    private $iBlockID;
    
    /**
     * Разметка для сео свойств
     * @var array
     */
    private $templateSEO = array('SECTION_META_TITLE' => 2, 'SECTION_META_KEYWORDS' => 3, 'SECTION_META_DESCRIPTION' => 4);

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
        $section_start_column = 1;

        foreach ($this->templateSEO as $name => $column) {
            $worksheet->setCellValueByColumnAndRow($column, $row, $name);
        }

        unset($column);

        $row++;
        $worksheet->getColumnDimensionByColumn($section_start_column)->setWidth(60);

        foreach ($this->sectionsTree['NESTED'][$rootSection['ID']] as $nested_section) {
            $sectionId = $nested_section['ID'];
            $section_path = $nested_section['PATH'];
            $section_SEO_props = $this->getSEOPropsBySectionID($sectionId);

            $worksheet->setCellValueByColumnAndRow($section_start_column, $row, $section_path);

            foreach ($section_SEO_props as $key => $value) {
                $column = $this->templateSEO[$key];
                $worksheet->setCellValueByColumnAndRow($column, $row, $value);
            }

            $row++;
        }

        return $worksheet;
    }

    /**
     * Получить SEO свойства раздела
     * @param int $sectionId
     * @return array
     */
    private function getSEOPropsBySectionID($sectionId)
    {
        $ipropSectionValues = new SectionValues($this->iBlockID, $sectionId);
        $arSEO = $ipropSectionValues->getValues();
        return $arSEO;
    }

    /**
     * Инициировать создание xlsx
     * @param string $pathToFile
     * @return void
     */
    public function createCatalogSeoFile($pathToFile)
    {
        $this->sectionsTree = $this->getCatalogSections();

        if (!empty($this->sectionsTree)) {
            echo '[SUCCESS] - Секции каталога определены успешно! <br>';
        } else {
            echo '[ERROR] - Секции каталога не определены! <br>';
        }

        $spreadsheet = new Spreadsheet();

        foreach ($this->sectionsTree['ROOT'] as $rootSection) {
            $worksheet = $spreadsheet->createSheet();
            $this->getSheet($worksheet, $rootSection);
        }

        $spreadsheet->removeSheetByIndex(0);

        $writer = new Xlsx($spreadsheet);
        $writer->save($pathToFile);

        if (file_exists($pathToFile)) {
            echo '[SUCCESS] - Файл успешно создан! Dir: '.$pathToFile.' <br>';
        } else {
            echo '[ERROR] - Ошибка создания файла! <br>';
        }
    }
}
