<?php

namespace Enex\Core\Import\ParserUni\Excel;

use PhpOffice\PhpSpreadsheet\IOFactory;

use PhpOffice\PhpSpreadsheet\Calculation\Calculation;

use Enex\Core\Import\ParserUni\GrabDeWaltImages;
use Enex\Core\Import\ParserUni\GrabHikokiImages;

use Citfact\Sitecore\Logger\TableLogger;
use Citfact\Sitecore\Logger\FeedsImporterDebugLogTable;

use Citfact\Sitecore\Import\FeedsImportTempTable;

use Citfact\SiteCore\User\UserRepository;

use Citfact\SiteCore\Tools\HLBlock;

class ExcelParse
{

    //
    // Передаваемые данные
    //

    private $feedId;

    private $testMode;

    //
    // Данные парсера
    //

    /**
     * ID производителя в HL блоке
     */
    private $hlBlockManufId;

    /**
     * XML ID производителя
     */
    private $hlBlockManufXmlId;

    /**
     * Рут директория фида для формирования путей изображений
     */
    private $feedRootDir;

    //
    // SiteCore tools
    //

    private $logger;

    private $core;

    /**
     * Список свойств товаров с платформы
     */
    private $platformProps = array();

    //
    // Массивы индексов свойств товаров
    //

    /**
     * Массив индексов и имён основных свойств (индексы габаритов и изображений не включены).
     */
    private $mainPropsIndexes = array();

    /**
     * Массив индексов и имён габаритов товара (обязательные свойства).
     */
    private $dimPropsIndexes = array();

    /**
     * Массив индексов и имён дополнительных свойств.
     */
    private $goodsPropsIndexes = array();
    
    /**
     * Массив индексов строк, которые необходимо проверить на равенство 0.
     */
    private $checkPropsIndexes = array();
    
    /**
     * Массив путей изображений
     */
    private $imagesDirsIndexes = array();
    
    /**
     * Массив индексов секций каталога.
     */
    private $sectionsIndexes = array();

    //
    //
    //
    
    /**
     * Флаг, показывающий указана ли цена товара с ндс.
     * @var true
     * Цена с НДС.
     * @var false
     * Цена без НДС.
     */
    private $vat = true;

    /**
     * Таблица
     */
    private $worksheet;

    /**
     * Номер строки, с которой начинается обработка выгрузки (используется это значение, если в параметрах строка не указана).
     * Для PhpSpreadsheet индекс начальной строки = 1.
     */
    private $startRow = 1;
    
    /**
     * Индекс листа в выгрузке с товарами.
     */
    private $activeSheet = 0;

    /**
     * Размер чанка позиций, на которые делится выгрузка в фильтре.
     */
    private $chunk = 200;

    //
    //
    //

    /**
     * Ошибки
     */
    private $errors;

    //
    //
    //
    // Методы
    //
    //
    //

    public function __construct()
    {
        $this->core = \Citfact\SiteCore\Core::getInstance();
        $this->logger = new TableLogger(new FeedsImporterDebugLogTable());
    }

    /**
     * Основной метод парсера
     *
     * @param int $userManufacturerId
     * ID пользователя - производителя
     * @param int $feedId
     * ID фида
     * @param string $filePath
     * Путь к обрабатываемому файлу
     * @param bool $testMode
     * Флаг тестового режима
     */
    public function run($userManufacturerId, $feedId, $filePath, $testMode)
    {
        $this->testMode = $testMode;
        $this->feedId = $feedId;
        $this->hlBlockManufId = UserRepository::getManufacturerHlIdByUserId($userManufacturerId);
        $this->hlBlockManufXmlId = UserRepository::getManufacturerXmlIdByUserId($userManufacturerId);
        unset($testMode, $feedId);

        $this->feedRootDir = substr($filePath, 0, strripos($filePath, '/', -1)) . '/';
        if ($this->feedRootDir == '') {
            $this->logger->addToLog('excel parse', 'error', ['msg' => 'Не определена директория фида', 'feed_id' => $this->feedId]);
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Не определена директория фида'];
            return;
        }
        $this->logger->addToLog('excel parse', 'success', ['msg' => 'Определена директория фида', 'feed_id' => $this->feedId, 'path' => $this->feedRootDir]);

        $this->platformProps = $this->getPlatformPropsArr();
        $handleParams = $this->getPropsIndexesArrs();
        if (!$handleParams) {
            return;
        }
        
        $startRow = $this->startRow;
        $endRow = $this->chunk;
        $this->logger->addToLog('excel parse', '', ['msg' => 'Начинаем обработку '. end(explode('/', $filePath)), 'feed_id' => $this->feedId ]);

        while (true) {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new ExcelFilter($startRow, $endRow));
            $spreadsheet = $reader->load($filePath);
            $this->worksheet = $spreadsheet->setActiveSheetIndex($this->activeSheet);

            foreach ($this->worksheet->getRowIterator($startRow) as $row) {
                $rowIndex = $row->getRowIndex();

                if (!$this->worksheet->getRowDimension($row->getRowIndex())->getVisible()) {
                    echo 'Not visible - row : '. $rowIndex;
                }

                if ($this->checkProductImportantValues($rowIndex)) {
                    $mainPropsValues = $this->getMainProps($rowIndex);

                    $dimPropsValues  = $this->getDimProps($rowIndex);

                    $arImages        = $this->getImages($rowIndex, $mainPropsValues['Article']);

                    $sectionCode     = $this->getSectionCode($rowIndex);

                    $propsValues     = $this->getPropsValues($rowIndex, $mainPropsValues, $dimPropsValues);

                    $arItem = [
                        'manufacturer_id' => $userManufacturerId,
                        'feed_id'         => $this->feedId,
                        'active'          => 'Y',

                        'name'            => $mainPropsValues['Name'],
                        'ekn'             => $mainPropsValues['Article'],  //артикул
                        'description'     => $mainPropsValues['Description'],
                        'price'           => $mainPropsValues['Price'],
                        'currency'        => $mainPropsValues['Currency'],

                        'section'         => $sectionCode,

                        'images'          => json_encode($arImages, JSON_UNESCAPED_UNICODE),

                        'length'          => $dimPropsValues['Length'],
                        'width'           => $dimPropsValues['Width'],
                        'height'          => $dimPropsValues['Height'],
                        'weight'          => $dimPropsValues['Weight'], //В граммах

                        'measure'         => '', //Битрикс код ЕИ !ТОВАРА!

                        'props_values'    => json_encode($propsValues['props'], JSON_UNESCAPED_UNICODE), //Основные свойства : Магазин -> Каталог товаров Enex -> Свойства товаров.
                        'additional_props_values'=> json_encode($propsValues['additional_props'], JSON_UNESCAPED_UNICODE), //Доп свойства - дописываются как информация.
                    ];

                    if ($this->testMode === false) {
                        $this->import($arItem);
                    }
                    $this->logger->addToLog('excel parse', 'success', ['msg' => 'Обработана строка : ' . $row->getRowIndex(), 'feed_id' => $this->feedId ]);
                    continue;
                }

                $this->logger->addToLog('excel parse', 'error', ['msg' => 'Ошибка в обработке строки : ' . $row->getRowIndex(), 'feed_id' => $this->feedId]);
            }

            Calculation::getInstance($spreadsheet)->clearCalculationCache();

            if ($endRow > $this->worksheet->getHighestRow($this->mainPropsIndexes['Article'])) {
                break;
            }

            unset($reader, $spreadsheet);
            $this->worksheet = '';

            $startRow = ++$endRow;
            $endRow += $this->chunk;
        }
    }

    //
    // Методы подготовки к обработке
    //

    /**
     * Получить массив свойств с платформы
     *
     * @return array $arrPlatformProps
     */
    private function getPlatformPropsArr()
    {
        $rsProps = \CIBlockProperty::GetList(
            array( 'SORT' => 'ASC',
                   'ID'   => 'ASC' ),
            array( 'IBLOCK_ID' => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW) )
        );

        $arrPlatformProps = [];

        while ($arProp = $rsProps->Fetch()) {
            $arrPlatformProps[trim($arProp['NAME'])] = trim($arProp['CODE']);
        }

        return $arrPlatformProps;
    }

    /**
     * Получить путь до файла индексов выгрузки данного пользователя-производителя
     * @return false
     * Файл параметров не найден
     * @return $params_file_path
     * Путь до найденного файла параметров
     */
    private function getParamsFilePath()
    {
        $HLBlock = new HLBlock();
        $paramsEntity = $HLBlock->getHlEntityByName($this->core::HLBLOCK_CODE_PARSER_PARAMS);
        $params = $paramsEntity::getList([
            'select' => ['UF_PARAMS_FILE'],
            'filter' => ['UF_MANUF_XML_ID' => $this->hlBlockManufId],
            'limit' => 1
        ])->fetch();

        if ($params === false) {
            $this->logger->addToLog('excel feed params', 'error', ['msg' => 'Не найден файл параметров', 'feed_id' => $this->feedId ]);
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Не найден файл параметров обработки фида'];
            return false;
        }

        $docRoot = str_replace('/local/modules/enex.core/lib/import/parseruni/excel', '', __DIR__);
        $path = $docRoot.\CFile::GetPath($params['UF_PARAMS_FILE']);
        return $path;
    }

    /**
     * Сформировать массивы индексов для обработки
     *
     * @return false
     * Не найден файл параметров, обработка прекращена
     * @return true
     * Массивы индексов для обработки сформированы
     */
    private function getPropsIndexesArrs()
    {
        $path = $this->getParamsFilePath();
        if (!$path) {
            return false;
        }

        $json = \Bitrix\Main\IO\File::getFileContents($path);
        $props = json_decode($json, true);

        $this->logger->addToLog('excel feed params', '', ['msg' => 'Запоминаем индексы свойств', 'feed_id' => $this->feedId]);

        foreach ($props as $propName => $propCell) {
            switch ($propName) {

                // Заполнение массива индексов изображений.
                case 'Images':
                    $images_arr = array_keys($propCell);
                    foreach ($images_arr as $imageDir) {
                        $this->imagesDirsIndexes[] = $propCell[$imageDir];
                    }

                    break;
                    
                //Заполнение массива индексов столбцов с уровнями дерева каталога.
                case 'Catalogs':
                    $catalogArray = array_keys($propCell);
                    foreach ($catalogArray as $catalogId) {
                        $this->sectionsIndexes[$catalogId] = $propCell[$catalogId];
                    }
                    
                    break;
                
                //Заполнение массива индексов размеров товара, веса и ЕИ.
                case 'Dimensions':
                    $dim_props_array = array_keys($propCell);
                    foreach ($dim_props_array as $dimPropName) {
                        $this->dimPropsIndexes[$dimPropName] = $propCell[$dimPropName];
                    }
                    
                    break;
                    
                //Заполнение массива имён и индексов свойств товара.
                case 'Properties':
                
                    foreach ($props['Properties'] as $property) {
                        if (!is_null($property['Column'])) {
                            $this->goodsPropsIndexes[$property['Name']]['Column'] = $property['Column'];
                        }
                        $this->goodsPropsIndexes[$property['Name']]['PropType'] = $property['PropType'];
                        $this->goodsPropsIndexes[$property['Name']]['ValueType'] = $property['ValueType'];
                    }
                    
                    break;
                    
                //Заполнение массива индексов столбцов выгрузки для проверки на != 0
                case 'Check':
                    $checkList = array_keys($propCell);
                    foreach ($checkList as $checkCell) {
                        if (!is_null($propCell[$checkCell])) {
                            $this->checkPropsIndexes[] = $propCell[$checkCell];
                        }
                    }
                    
                    break;
                    
                //Заполнение стартовой строки для обработки выгрузки
                case 'Start':
                    if (!is_null($propCell)) {
                        $this->startRow = $propCell;
                    }

                    break;
                
                //Заполнение индекса листа для обработки
                case 'Content_Sheet':
                    if (!is_null($propCell)) {
                        $this->activeSheet = $propCell;
                    }

                    break;
                    
                //Заполнение флага НДС
                case 'VAT_Price':
                    $this->vat = $propCell;
                    
                    break;
                
                //Заполнение оставшихся свойств
                //Article || Name || Description || Country || Price || Currency || MinumumOrder
                default:
                    if (is_null($propCell) && $propName === 'Description') {
                        $this->mainPropsIndexes[$propName] = '';
                        break;
                    }
                    $this->mainPropsIndexes[$propName] = $propCell;
                    
                    break;
                    
            }
        }

        unset($propName, $propCell);

        $this->logger->addToLog('excel feed params', 'success', ['msg' => 'Свойства распределены', 'feed_id' => $this->feedId]);

        return true;
    }

    //
    //
    //

    /**
     * Проверка строки на незаполненность необходимых ячеек и на равенство 0 значений ячеек из @var $checkPropsIndexes.
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     *
     * @return false
     * Строка не прошла проверку
     * @return true
     * Строка прошла проверку
     */
    private function checkProductImportantValues($rowIndex)
    {
        foreach ($this->mainPropsIndexes as $name => $column) {
            if (!preg_match('@[A-z]@u', $column) || $name === 'Currency') {
                continue;
            }
            $value = $this->worksheet->getCell($column.$rowIndex)->getCalculatedValue();
            if (($value === 0 || $value === '' || is_null($value)) && $name !== 'Description') {
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Не найдено или равно 0 => Column : ' . $column. ' Свойство : '. $name, 'feed_id' => $this->feedId]);
                $this->errors['Строка'][] = ['level' => 1, 'msg' => 'Не найдено или равно 0 => Column : ' . $column . ' - Row : '. $rowIndex . ' - Prop name : ' . $name];
                return false;
            }
        }

        unset($name, $column);

        foreach ($this->dimPropsIndexes as $name => $column) {
            if ($name === 'WeightUnit' || $name === 'Unit') {
                continue;
            }
            $value = $this->worksheet->getCell($column.$rowIndex)->getCalculatedValue();
            if ($value === 0 || $value === '' || is_null($value)) {
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Не найдено или равно 0 => Column : ' . $column. ' Свойство : '. $name, 'feed_id' => $this->feedId]);
                $this->errors['Строка'][] = ['level' => 1, 'msg' => 'Не найдено или равно 0 => Column : ' . $column . ' - Row : '. $rowIndex . ' - Prop name : ' . $name];
                return false;
            }
        }

        unset($name, $column);

        foreach ($this->sectionsIndexes as $name => $column) {
            $value = $this->worksheet->getCell($column . $rowIndex)->getCalculatedValue();
            if (($value === 0 || $value === '' || is_null($value)) && ($name !== 'Catalog3' && $name !== 'Catalog4')) {
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Не найдено или равно 0 => Column : ' . $column. ' Свойство : '. $name, 'feed_id' => $this->feedId]);
                $this->errors['Строка'][] = ['level' => 1, 'msg' => 'Не найдено или равно 0 => Column : ' . $column . ' - Row : '. $rowIndex . ' - Prop name : ' . $name];
                return false;
            }
        }

        unset($name, $column);

        foreach ($this->checkPropsIndexes as $name => $column) {
            $value = $this->worksheet->getCell($column . $rowIndex)->getCalculatedValue();
            if ($value === 0 || $value === '' || is_null($value)) {
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Не найдено или равно 0 => Column : ' . $column. ' Свойство : '. $name, 'feed_id' => $this->feedId]);
                $this->errors['Строка'][] = ['level' => 1, 'msg' => 'Не найдено или равно 0 => Column : ' . $column . ' - Row : '. $rowIndex . ' - Prop name : ' . $name];
                return false;
            }
        }

        unset($name, $column);

        return true;
    }

    //
    // Методы получения значений свойств товаров
    //

    /**
     * Получить основные свойства товара
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     *
     * @return array $mainPropsValuesTemp
     * Массив имён и значение основных свойств товара
     */
    private function getMainProps($rowIndex)
    {
        $mainPropsValuesTemp = [];

        foreach ($this->mainPropsIndexes as $name => $column) {
            if ($name === 'Currency' || ($name === 'Country' && !preg_match('@[A-z]@u', $column)) || $column === '') {
                continue;
            }
            $value = $this->worksheet->getCell($column . $rowIndex)->getCalculatedValue();
            $mainPropsValuesTemp[$name] = $value;
        }

        unset($name, $column);

        if (!isset($mainPropsValuesTemp['Country']) || $mainPropsValuesTemp['Country'] == '') {
            $mainPropsValuesTemp['Country'] = $this->mainPropsIndexes['Country'];
        }
        if (!isset($mainPropsValuesTemp['Description'])) {
            $mainPropsValuesTemp['Description'] = '';
        }
        
        $mainPropsValuesTemp['Currency'] = $this->mainPropsIndexes['Currency'];

        if (!$this->vat) {
            $price = str_replace(',', '.', $mainPropsValuesTemp['Price']);
            $price = floatval($price) * 1.2;
            $price = number_format($price, 2, '.', '');
            $mainPropsValuesTemp['Price'] = $price;
        } else {
            $price = str_replace(',', '.', $mainPropsValuesTemp['Price']);
            $price = floatval($price);
            $mainPropsValuesTemp['Price'] = $price;
        }

        return $mainPropsValuesTemp;
    }
    
    /**
     * Получить размеры и вес товара
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     *
     * @return array $dim_prop_values_temp
     * Массив имён и значений размеров товара и веса
     */
    private function getDimProps($rowIndex)
    {
        $dimPropsValuesTemp = [];

        foreach ($this->dimPropsIndexes as $name => $column) {
            if ($name === 'Unit' || $name === 'WeightUnit') {
                continue;
            }
            $value = $this->worksheet->getCell($column . $rowIndex)->getCalculatedValue();
            $dimPropsValuesTemp[$name] = $value;
        }

        unset($name, $column);

        foreach ($dimPropsValuesTemp as $dimPropNameTemp => $dimPropValueTemp) {
            switch ($dimPropNameTemp) {
                case 'Weight':
                    $dimPropsValuesTemp[$dimPropNameTemp] = $this->getDimensions($dimPropValueTemp, $this->dimPropsIndexes['WeightUnit']);
                    break;

                default:
                    $dimPropsValuesTemp[$dimPropNameTemp] = $this->getDimensions($dimPropValueTemp, $this->dimPropsIndexes['Unit']);
                    break;
            }
        }

        return $dimPropsValuesTemp;
    }

    /**
     * Получить пути до изображений
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     *
     * @return array $imagesDirsTemp
     * Массив путей к изображениям
     */
    private function getImages($rowIndex, $article)
    {
        $arrEmptyImgArrExc = [25];

        if (empty($this->imagesDirsIndexes) && !in_array($this->hlBlockManufId, $arrEmptyImgArrExc)) {
            return false;
        }

        if ($this->hlBlockManufId == 25) {
            $grab = new GrabDeWaltImages();
            $imagesDewalt = $grab->run($article);

            $imagesDirsTemp = array();

            foreach ($imagesDewalt as $key => $url) {
                if ($key === 0) {
                    $imagesDirsTemp['preview'] = $url;
                } else {
                    $imagesDirsTemp['additional'][] = $url;
                }
            }

            return $imagesDirsTemp;
        }

        $imagesDirsTemp = array();

        foreach ($this->imagesDirsIndexes as $key => $column) {
            $path = trim($this->worksheet->getCell($column . $rowIndex)->getCalculatedValue());
            if ($path != '') {
                $finalPath = $path;
                if (!filter_var($path, FILTER_VALIDATE_URL)) {
                    $finalPath = $this->feedRootDir . $path;
                }
                if ($this->hlBlockManufId == 97) {
                    $path = strtolower($path);
                    $finalPath = $_SERVER['DOCUMENT_ROOT'] . '/upload/feed_metabo_images/'.$path;
                }
                if (empty($imagesDirsTemp)) {
                    $imagesDirsTemp['preview'] = $finalPath;
                    continue;
                }
                $imagesDirsTemp['additional'][] = $finalPath;
            } elseif ($this->hlBlockManufId == 28) {
                $grabHikoki = new GrabHikokiImages;
                $hikokiImages = $grabHikoki->run($article);
                if (empty($hikokiImages)) {
                    return;
                }
                $imagesHikokiTemp = [];
                foreach ($hikokiImages as $key => $url) {
                    if ($key === 0) {
                        $imagesHikokiTemp['preview'] = $url;
                    } else {
                        $imagesHikokiTemp['additional'][] = $url;
                    }
                }
                return $imagesHikokiTemp;
            }
        }

        return $imagesDirsTemp;
    }

    /**
     * Получить свойства товара для отображения на платформе
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     */
    private function getPropsValues($rowIndex, $mainPropsPre, $dimPropsPre)
    {
        $propsArrTemp = [];
        $dimPropsArrTemp = [];
        $propsArrToShow = [];

        $country = $mainPropsPre['Country'];
        $article = $mainPropsPre['Article'];
        $minOrder = $mainPropsPre['MinimumOrder'];

        unset($mainPropsPre);

        $propsArrToShow['props']['MANUFACTURER'] = $this->hlBlockManufXmlId;
        $propsArrToShow['props']['STRANA_PROIZVODSTVA'] = $this->getCountryOuterCode($country);
        $propsArrToShow['props']['CML2_ARTICLE'] = $article;
        $propsArrToShow['props']['MIN_ORDER'] = $minOrder;

        foreach ($this->goodsPropsIndexes as $name => $infoArr) {
            $column = $infoArr['Column'];
            $value = $this->worksheet->getCell($column . $rowIndex)->getCalculatedValue();
            if ($value != '') {
                $propsArrTemp[$name]['Value'] = $value;
                $propsArrTemp[$name]['PropType'] = $infoArr['PropType'];
                $propsArrTemp[$name]['ValueType'] = $infoArr['ValueType'];
            }
        }

        unset($name, $infoArr);

        foreach ($propsArrTemp as $name => $infoArr) {
            switch ($infoArr['PropType']) {
                case 'main':
                    $name = trim($name);
                    if ($infoArr['ValueType'] == 'N') {
                        $infoArr['Value'] = floatval(str_replace(',', '.', $infoArr['Value']));
                    } else {
                        $infoArr['Value'] = strval($infoArr['Value']);
                    }

                    if (array_key_exists($name, $this->platformProps)) {
                        $propsArrToShow['props'][$this->platformProps[$name]] = $infoArr['Value'];
                        break;
                    }
                    $this->logger->addToLog('excel parse', '', ['msg' => 'Обнаружено новое основное свойство : ' . $name, 'feed_id' => $this->feedId]);

                    $code = strtoupper(\CUtil::translit($name, 'ru', ['replace_space' => '_', 'replace_other' => '_']));
                    /**
                     * ValueTypes
                     * S: Строка
                     * N: Число
                     */
                    $arFields = [
                        "NAME" => $name,
                        "ACTIVE" => "Y",
                        "SORT" => "500",
                        "CODE" => $code,
                        "PROPERTY_TYPE" => $infoArr['ValueType'],
                        'SMART_FILTER' => 'Y',
                        "IBLOCK_ID" => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW),
                    ];
                    $ibp = new \CIBlockProperty;
                    $propId = $ibp->add($arFields);
                    if (!$propId) {
                        $this->logger->addToLog('excel parse', 'error', ['msg' => 'Ошибка при добавлении свойства : ' . $name, 'error' => var_dump($ibp->LAST_ERROR) ,'feed_id' => $this->feedId]);
                    } else {
                        $propsArrToShow['props'][$code] = $infoArr['Value'];
                        $this->logger->addToLog('excel parse', 'success', ['msg' => 'Свойство ' . $name. ' успешно добавлено', 'feed_id' => $this->feedId]);
                        $this->platformProps = $this->getPlatformPropsArr();
                    }

                    break;

                case 'additional':
                    $propsArrToShow['additional_props'][$name] = $infoArr['Value'];
                    break;
            }
        }

        unset($name, $infoArr);

        $dimPropsArrTemp = $this->getDimsToShow($dimPropsPre);
        unset($dimPropsPre);
        foreach ($dimPropsArrTemp as $name => $value) {
            $propsArrToShow['props'][$this->platformProps[$name]] = $value;
        }

        return $propsArrToShow;
    }

    //
    // Вспомогательные методы
    //

    /**
     * Получить корректные для базы значения размеров и веса товара
     *
     * @param float|int $dimValue
     * Текущее значение размера или веса
     * @param string $dimUnit
     * ЕИ размера или веса
     */
    private function getDimensions($dimValue, $dimUnit)
    {
        $dimValue = str_replace(',', '.', $dimValue);
        $arUnits = [
            'м' => 1000,
            'мм' => 1,

            'm' => 1000,
            'mm' => 1,

            'см' => 10,

            'кг' => 1000,
            'г' => 1,
            'kg' => 1000,
            'g' => 1
        ];

        $dimValueTemp = floatval($dimValue) * $arUnits[$dimUnit];  // Размеры в базу вносим в мм
        return $dimValueTemp;
    }

    /**
     * Получить внешний код страны производства
     *
     * @param string $country
     * Текущее название страны
     *
     * @return string
     * Внешний код страны
     * @return bool
     * false - Страна не найдена
     */
    private function getCountryOuterCode($country)
    {
        $arCountries = [
            'Австрия' => 'АВСТРИЯ',

            'BY' => 'БЕЛАРУСЬ',
            'Белоруссия' => 'БЕЛАРУСЬ',

            'Великобритания' => 'ВЕЛИКОБРИТАНИЯ',

            'Европейский союз' => 'СТРАНА ЕС',

            'Венгрия' => 'ВЕНГРИЯ',

            'Германия' => 'ГЕРМАНИЯ',

            'Ирландия' => 'ИРЛАНДИЯ',

            'Испания' => 'ИСПАНИЯ',

            'Китай' => 'КИТАЙ',
            'КИТАЙ' => 'КИТАЙ',

            'Чехия' => 'ЧЕШСКАЯ РЕСПУБЛИКА',

            'Италия' => 'ИТАЛИЯ',

            'Малайзия' => 'МАЛАЙЗИЯ',

            'Марокко' => 'МАРОККО',
            
            'Мексика' => 'МЕКСИКА',

            'Нидерланды' => 'НИДЕРЛАНДЫ',

            'ПОЛЬША' => 'ПОЛЬША',

            'Россия' => 'Россия',
            'RU' => 'Россия',
            'РОССИЯ' => 'Россия',

            'Словения' => 'СЛОВЕНИЯ',

            'Соединенные штаты' => 'СОЕДИНЕННЫЕ ШТАТЫ',
            'США' => 'СОЕДИНЕННЫЕ ШТАТЫ',

            'Тайланд' => 'ТАЙЛАНД',

            'Тайвань' => 'ТАЙВАНЬ',

            'Турция' => 'ТУРЦИЯ',

            'Франция' => 'ФРАНЦИЯ',

            'Чехия' => 'ЧЕШСКАЯ РЕСПУБЛИКА',

            'Швейцария' => 'ШВЕЙЦАРИЯ',

            'Япония' => 'ЯПОНИЯ'
        ];

        if (isset($arCountries[$country])) {
            return $arCountries[$country];
        }
        return false;
    }

    /**
     * Получить внешний код секции каталога
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     *
     * @return $sectionId
     */
    private function getSectionCode($rowIndex)
    {
        $sectionId = false;

        $arSectionsNames = [];

        foreach ($this->sectionsIndexes as $column) {
            $sectionName = $this->worksheet->getCell($column . $rowIndex)->getCalculatedValue();
            if ($sectionName == '') {
                break;
            }
            $arSectionsNames[] = trim($sectionName);
        }

        $obSection = new \CIBlockSection();
        foreach ($arSectionsNames as $sectionName) {

                // Ищем раздел по имени и ID родительского раздела
            // В $sectionId присваиваем ID найденного
            // И следующая итерация цикла будет уже с новым значением $sectionId
            // print_r("\n Ищем раздел: " . $sectionName . "\n");

            $arFilter = array(
                    "IBLOCK_ID" => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW),
                    'SECTION_ID' => $sectionId,
                    'NAME' => $sectionName,
                );

            $rsSections = \CIBlockSection::GetList([], $arFilter, false, array( 'ID', 'IBLOCK_SECTION_ID', 'NAME' ));

            if ($arSection = $rsSections->GetNext()) {
                $sectionId = $arSection['ID'];
            } else {
                // Создаем новый раздел
                $sectionCode = $this->checkSectionCode(
                    $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW),
                    \Cutil::translit($sectionName, "ru", ["replace_space"=>"_","replace_other"=>"_"])
                );
                $arFields = array(
                        "ACTIVE" => 'Y',
                        "IBLOCK_SECTION_ID" => $sectionId,
                        "IBLOCK_ID" => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW),
                        "NAME" => $sectionName,
                        "CODE" => $sectionCode,
                        "XML_ID" => $sectionCode,
                        "SORT" => '500',
                    );
                $createdSectionId = $obSection->Add($arFields);
                print_r("\n Создаем раздел: " . $arFields['NAME'] . "\n");
                if (!$createdSectionId) {
                    $this->logger->addToLog('parser set catalog section', 'error', ['msg' => 'Ошибка создания раздела: ' . $obSection->LAST_ERROR, 'feed_id' => $this->feedId]);
                } else {
                    $sectionId = $createdSectionId;
                    print_r("\n Создали раздел: " . $createdSectionId . "\n");
                }
            }
        }

        return $sectionId;
    }

    private function checkSectionCode($iblockId, $code)
    {
        $arCodes = array();
        $rsCodeLike = \CIBlockSection::GetList(array(), array(
            "IBLOCK_ID" => $iblockId,
            "CODE" => $code."%",
        ), false, false, array("ID", "CODE"));
        while ($ar = $rsCodeLike->Fetch()) {
            $arCodes[$ar["CODE"]] = $ar["ID"];
        }

        if (array_key_exists($code, $arCodes)) {
            $i = 1;
            while (array_key_exists($code."_".$i, $arCodes)) {
                $i++;
            }
            return $code."_".$i;
        }
        return $code;
    }
    
    /**
     * Загрузить информацию о товаре
     *
     * @param array $productPropsArr
     * Массив свойств товара
     */
    private function import($productPropsArr)
    {
        $result = FeedsImportTempTable::add($productPropsArr);

        if (!$result->isSuccess()) {
            $this->logger->addToLog('parse feed', 'error', ['msg' => 'Ошибка создания товара', 'errors' => $result->getErrorMessages()]);
            $this->errors[$productPropsArr['ekn']][] = ['level' => 1, 'msg' => 'Ошибка создания товара : '. $result->getErrorMessages()];
        }
    }

    /**
     * Получить габариты и вес товара как свойства
     *
     * @param array $dimProps
     * Массив габаритов и веса товара
     */
    private function getDimsToShow($dimProps)
    {
        $dimPropsArrToShow = [];
        $translate = [
            'Length' => 'Длина',
            'Width' => 'Ширина',
            'Height' => 'Высота'
        ];

        foreach ($dimProps as $name => $value) {
            switch ($name) {
                case 'Weight':

                    if ($value >= 1000) {
                        $weight = $value * 0.001;
                        $dimPropsArrToShow['Вес, кг'] = round($weight, 2, PHP_ROUND_HALF_UP);
                    }

                    $dimPropsArrToShow['Вес, г'] = ceil(floatval($value));
                    break;
                
                default:
                    //Раскомментировать, если необходимо загрузить товар без габаритов (со значением -)
                    //if ($value === '-') continue;

                    //Если больше 10м -> добавляем метры и не меняем имя свойства
                    if ($value >= 10000) {
                        $newName = $translate[$name];
                        $newValue = $value * 0.001;
                        $dimPropsArrToShow[$newName] = strval($newValue) . ' m';

                        break;
                    }

                    $newName = $translate[$name] . ', мм';
                    $dimPropsArrToShow[$newName] = floatval($value);
                    break;
            }
        }

        return $dimPropsArrToShow;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
