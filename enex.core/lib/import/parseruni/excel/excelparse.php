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

    private $feed_ID;

    private $test_mode;

    //
    // Данные парсера
    //

    /**
     * ID производителя в HL блоке
     */
    private $hl_block_manuf_id;

    /**
     * XML ID производителя
     */
    private $hl_block_manuf_xml_id;

    /**
     * Рут директория фида для формирования путей изображений
     */
    private $feed_root_dir;

    //
    // SiteCore tools
    //

    private $logger;

    private $core;

    /**
     * Список свойств товаров с платформы
     */
    private $platform_props = array();

    //
    // Массивы индексов свойств товаров
    //

    /**
     * Массив индексов и имён основных свойств (индексы габаритов и изображений не включены).
     */
    private $main_props_indexes = array();

    /**
     * Массив индексов и имён габаритов товара (обязательные свойства).
     */
    private $dim_props_indexes = array();

    /**
     * Массив индексов и имён дополнительных свойств.
     */
    private $goods_props_indexes = array();
    
    /**
     * Массив индексов строк, которые необходимо проверить на равенство 0.
     */
    private $check_props_indexes = array();
    
    /**
     * Массив путей изображений
     */
    private $images_dirs_indexes = array();
    
    /**
     * Массив индексов секций каталога.
     */
    private $sections_indexes = array();

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
    private $start_row = 1;
    
    /**
     * Индекс листа в выгрузке с товарами.
     */
    private $active_sheet = 0;

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
     * @param int $user_manufacturer_ID
     * ID пользователя - производителя
     * @param int $feed_ID
     * ID фида
     * @param string $file_path
     * Путь к обрабатываемому файлу
     * @param bool $test_mode
     * Флаг тестового режима
     */
    public function run($user_manufacturer_ID, $feed_ID, $file_path, $test_mode)
    {
        $this->test_mode = $test_mode;
        $this->feed_ID = $feed_ID;
        $this->hl_block_manuf_id = UserRepository::getManufacturerHlIdByUserId($user_manufacturer_ID);
        $this->hl_block_manuf_xml_id = UserRepository::getManufacturerXmlIdByUserId($user_manufacturer_ID);
        unset($test_mode, $feed_ID);

        $this->feed_root_dir = substr($file_path, 0, strripos($file_path, '/', -1)) . '/';
        if ($this->feed_root_dir == '') {
            $this->logger->addToLog('excel parse', 'error', ['msg' => 'Не определена директория фида', 'feed_id' => $this->feed_ID]);
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Не определена директория фида'];
            return;
        }
        $this->logger->addToLog('excel parse', 'success', ['msg' => 'Определена директория фида', 'feed_id' => $this->feed_ID, 'path' => $this->feed_root_dir]);

        $this->platform_props = $this->get_platform_props_arr();
        $handle_params = $this->get_props_indexes_arrs();
        if (!$handle_params) {
            return;
        }
        
        $start_row = $this->start_row;
        $end_row = $this->chunk;
        $this->logger->addToLog('excel parse', '', ['msg' => 'Начинаем обработку '. end(explode('/', $file_path)), 'feed_id' => $this->feed_ID ]);

        while (true) {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new ExcelFilter($start_row, $end_row));
            $spreadsheet = $reader->load($file_path);
            $this->worksheet = $spreadsheet->setActiveSheetIndex($this->active_sheet);

            foreach ($this->worksheet->getRowIterator($start_row) as $row) {
                $row_index = $row->getRowIndex();

                if (!$this->worksheet->getRowDimension($row->getRowIndex())->getVisible()) {
                    echo 'Not visible - row : '. $row_index;
                }

                if ($this->check_product_important_values($row_index)) {
                    $main_props_values = $this->get_main_props($row_index);

                    $dim_props_values  = $this->get_dim_props($row_index);

                    $ar_images         = $this->get_images($row_index, $main_props_values['Article']);

                    $section_code      = $this->get_section_code($row_index);

                    $props_values      = $this->get_props_values($row_index, $main_props_values, $dim_props_values);

                    $ar_item = [
                        'manufacturer_id' => $user_manufacturer_ID,
                        'feed_id'         => $this->feed_ID,
                        'active'          => 'Y',

                        'name'            => $main_props_values['Name'],
                        'ekn'             => $main_props_values['Article'],  //артикул
                        'description'     => $main_props_values['Description'],
                        'price'           => $main_props_values['Price'],
                        'currency'        => $main_props_values['Currency'],

                        'section'         => $section_code,

                        'images'          => json_encode($ar_images, JSON_UNESCAPED_UNICODE),

                        'length'          => $dim_props_values['Length'],
                        'width'           => $dim_props_values['Width'],
                        'height'          => $dim_props_values['Height'],
                        'weight'          => $dim_props_values['Weight'], //В граммах

                        'measure'         => '', //Битрикс код ЕИ !ТОВАРА!

                        'props_values'    => json_encode($props_values['props'], JSON_UNESCAPED_UNICODE), //Основные свойства : Магазин -> Каталог товаров Enex -> Свойства товаров.
                        'additional_props_values'=> json_encode($props_values['additional_props'], JSON_UNESCAPED_UNICODE), //Доп свойства - дописываются как информация.
                    ];

                    if ($this->test_mode === false) {
                        $this->import($ar_item);
                    }
                    $this->logger->addToLog('excel parse', 'success', ['msg' => 'Обработана строка : ' . $row->getRowIndex(), 'feed_id' => $this->feed_ID ]);
                    continue;
                }

                $this->logger->addToLog('excel parse', 'error', ['msg' => 'Ошибка в обработке строки : ' . $row->getRowIndex(), 'feed_id' => $this->feed_ID]);
            }

            Calculation::getInstance($spreadsheet)->clearCalculationCache();

            if ($end_row > $this->worksheet->getHighestRow($this->main_props_indexes['Article'])) {
                break;
            }

            unset($reader, $spreadsheet);
            $this->worksheet = '';

            $start_row = ++$end_row;
            $end_row += $this->chunk;
        }
    }

    //
    // Методы подготовки к обработке
    //

    /**
     * Получить массив свойств с платформы
     *
     * @return array $arr_platform_props
     */
    private function get_platform_props_arr()
    {
        $rsProps = \CIBlockProperty::GetList(
            array( 'SORT' => 'ASC',
                   'ID'   => 'ASC' ),
            array( 'IBLOCK_ID' => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW) )
        );

        $arr_platform_props = [];

        while ($arProp = $rsProps->Fetch()) {
            $arr_platform_props[trim($arProp['NAME'])] = trim($arProp['CODE']);
        }

        return $arr_platform_props;
    }

    /**
     * Получить путь до файла индексов выгрузки данного пользователя-производителя
     * @return false
     * Файл параметров не найден
     * @return $params_file_path
     * Путь до найденного файла параметров
     */
    private function get_params_file_path()
    {
        $HLBlock = new HLBlock();
        $paramsEntity = $HLBlock->getHlEntityByName($this->core::HLBLOCK_CODE_PARSER_PARAMS);
        $params = $paramsEntity::getList([
            'select' => ['UF_PARAMS_FILE'],
            'filter' => ['UF_MANUF_XML_ID' => $this->hl_block_manuf_id],
            'limit' => 1
        ])->fetch();

        if ($params === false) {
            $this->logger->addToLog('excel feed params', 'error', ['msg' => 'Не найден файл параметров', 'feed_id' => $this->feed_ID ]);
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Не найден файл параметров обработки фида'];
            return false;
        }

        $doc_root = str_replace('/local/modules/citfact.sitecore/lib/import/feedparsers/parseruni/excel', '', __DIR__);
        $path = $doc_root.\CFile::GetPath($params['UF_PARAMS_FILE']);
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
    private function get_props_indexes_arrs()
    {
        $path = $this->get_params_file_path();
        if (!$path) {
            return false;
        }

        $json = \Bitrix\Main\IO\File::getFileContents($path);
        $props = json_decode($json, true);

        $this->logger->addToLog('excel feed params', '', ['msg' => 'Запоминаем индексы свойств', 'feed_id' => $this->feed_ID]);

        foreach ($props as $prop_name => $prop_cell) {
            switch ($prop_name) {

                // Заполнение массива индексов изображений.
                case 'Images':
                    $images_arr = array_keys($prop_cell);
                    foreach ($images_arr as $image_dir) {
                        $this->images_dirs_indexes[] = $prop_cell[$image_dir];
                    }

                    break;
                    
                //Заполнение массива индексов столбцов с уровнями дерева каталога.
                case 'Catalogs':
                    $catalog_array = array_keys($prop_cell);
                    foreach ($catalog_array as $catalog_ID) {
                        $this->sections_indexes[$catalog_ID] = $prop_cell[$catalog_ID];
                    }
                    
                    break;
                
                //Заполнение массива индексов размеров товара, веса и ЕИ.
                case 'Dimensions':
                    $dim_props_array = array_keys($prop_cell);
                    foreach ($dim_props_array as $dim_prop_name) {
                        $this->dim_props_indexes[$dim_prop_name] = $prop_cell[$dim_prop_name];
                    }
                    
                    break;
                    
                //Заполнение массива имён и индексов свойств товара.
                case 'Properties':
                
                    foreach ($props['Properties'] as $property) {
                        if (!is_null($property['Column'])) {
                            $this->goods_props_indexes[$property['Name']]['Column'] = $property['Column'];
                        }
                        $this->goods_props_indexes[$property['Name']]['PropType'] = $property['PropType'];
                        $this->goods_props_indexes[$property['Name']]['ValueType'] = $property['ValueType'];
                    }
                    
                    break;
                    
                //Заполнение массива индексов столбцов выгрузки для проверки на != 0
                case 'Check':
                    $check_list = array_keys($prop_cell);
                    foreach ($check_list as $check_cell) {
                        if (!is_null($prop_cell[$check_cell])) {
                            $this->check_props_indexes[] = $prop_cell[$check_cell];
                        }
                    }
                    
                    break;
                    
                //Заполнение стартовой строки для обработки выгрузки
                case 'Start':
                    if (!is_null($prop_cell)) {
                        $this->start_row = $prop_cell;
                    }

                    break;
                
                //Заполнение индекса листа для обработки
                case 'Content_Sheet':
                    if (!is_null($prop_cell)) {
                        $this->active_sheet = $prop_cell;
                    }

                    break;
                    
                //Заполнение флага НДС
                case 'VAT_Price':
                    $this->vat = $prop_cell;
                    
                    break;
                
                //Заполнение оставшихся свойств
                //Article || Name || Description || Country || Price || Currency || MinumumOrder
                default:
                    if (is_null($prop_cell) && $prop_name === 'Description') {
                        $this->main_props_indexes[$prop_name] = '';
                        break;
                    }
                    $this->main_props_indexes[$prop_name] = $prop_cell;
                    
                    break;
                    
            }
        }

        unset($prop_name, $prop_cell);

        $this->logger->addToLog('excel feed params', 'success', ['msg' => 'Свойства распределены', 'feed_id' => $this->feed_ID]);

        return true;
    }

    //
    //
    //

    /**
     * Проверка строки на незаполненность необходимых ячеек и на равенство 0 значений ячеек из @var $check_props_indexes.
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     *
     * @return false
     * Строка не прошла проверку
     * @return true
     * Строка прошла проверку
     */
    private function check_product_important_values($row_index)
    {
        foreach ($this->main_props_indexes as $name => $column) {
            if (!preg_match('@[A-z]@u', $column) || $name === 'Currency') {
                continue;
            }
            $value = $this->worksheet->getCell($column.$row_index)->getCalculatedValue();
            if (($value === 0 || $value === '' || is_null($value)) && $name !== 'Description') {
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Не найдено или равно 0 => Column : ' . $column. ' Свойство : '. $name, 'feed_id' => $this->feed_ID]);
                $this->errors['Строка'][] = ['level' => 1, 'msg' => 'Не найдено или равно 0 => Column : ' . $column . ' - Row : '. $row_index . ' - Prop name : ' . $name];
                return false;
            }
        }

        unset($name, $column);

        foreach ($this->dim_props_indexes as $name => $column) {
            if ($name === 'WeightUnit' || $name === 'Unit') {
                continue;
            }
            $value = $this->worksheet->getCell($column.$row_index)->getCalculatedValue();
            if ($value === 0 || $value === '' || is_null($value)) {
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Не найдено или равно 0 => Column : ' . $column. ' Свойство : '. $name, 'feed_id' => $this->feed_ID]);
                $this->errors['Строка'][] = ['level' => 1, 'msg' => 'Не найдено или равно 0 => Column : ' . $column . ' - Row : '. $row_index . ' - Prop name : ' . $name];
                return false;
            }
        }

        unset($name, $column);

        foreach ($this->sections_indexes as $name => $column) {
            $value = $this->worksheet->getCell($column . $row_index)->getCalculatedValue();
            if (($value === 0 || $value === '' || is_null($value)) && ($name !== 'Catalog3' && $name !== 'Catalog4')) {
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Не найдено или равно 0 => Column : ' . $column. ' Свойство : '. $name, 'feed_id' => $this->feed_ID]);
                $this->errors['Строка'][] = ['level' => 1, 'msg' => 'Не найдено или равно 0 => Column : ' . $column . ' - Row : '. $row_index . ' - Prop name : ' . $name];
                return false;
            }
        }

        unset($name, $column);

        foreach ($this->check_props_indexes as $name => $column) {
            $value = $this->worksheet->getCell($column . $row_index)->getCalculatedValue();
            if ($value === 0 || $value === '' || is_null($value)) {
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Не найдено или равно 0 => Column : ' . $column. ' Свойство : '. $name, 'feed_id' => $this->feed_ID]);
                $this->errors['Строка'][] = ['level' => 1, 'msg' => 'Не найдено или равно 0 => Column : ' . $column . ' - Row : '. $row_index . ' - Prop name : ' . $name];
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
     * @return array $main_props_values_temp
     * Массив имён и значение основных свойств товара
     */
    private function get_main_props($row_index)
    {
        $main_props_values_temp = [];

        foreach ($this->main_props_indexes as $name => $column) {
            if ($name === 'Currency' || ($name === 'Country' && !preg_match('@[A-z]@u', $column)) || $column === '') {
                continue;
            }
            $value = $this->worksheet->getCell($column . $row_index)->getCalculatedValue();
            $main_props_values_temp[$name] = $value;
        }

        unset($name, $column);

        if (!isset($main_props_values_temp['Country']) || $main_props_values_temp['Country'] == '') {
            $main_props_values_temp['Country'] = $this->main_props_indexes['Country'];
        }
        if (!isset($main_props_values_temp['Description'])) {
            $main_props_values_temp['Description'] = '';
        }
        
        $main_props_values_temp['Currency'] = $this->main_props_indexes['Currency'];

        if (!$this->vat) {
            $price = str_replace(',', '.', $main_props_values_temp['Price']);
            $price = floatval($price) * 1.2;
            $price = number_format($price, 2, '.', '');
            $main_props_values_temp['Price'] = $price;
        } else {
            $price = str_replace(',', '.', $main_props_values_temp['Price']);
            $price = floatval($price);
            $main_props_values_temp['Price'] = $price;
        }

        return $main_props_values_temp;
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
    private function get_dim_props($row_index)
    {
        $dim_props_values_temp = [];

        foreach ($this->dim_props_indexes as $name => $column) {
            if ($name === 'Unit' || $name === 'WeightUnit') {
                continue;
            }
            $value = $this->worksheet->getCell($column . $row_index)->getCalculatedValue();
            $dim_props_values_temp[$name] = $value;
        }

        unset($name, $column);

        foreach ($dim_props_values_temp as $dim_prop_name_temp => $dim_prop_value_temp) {
            switch ($dim_prop_name_temp) {
                case 'Weight':
                    $dim_props_values_temp[$dim_prop_name_temp] = $this->get_dimensions($dim_prop_value_temp, $this->dim_props_indexes['WeightUnit']);
                    break;

                default:
                    $dim_props_values_temp[$dim_prop_name_temp] = $this->get_dimensions($dim_prop_value_temp, $this->dim_props_indexes['Unit']);
                    break;
            }
        }

        return $dim_props_values_temp;
    }

    /**
     * Получить пути до изображений
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     *
     * @return array $images_dirs_temp
     * Массив путей к изображениям
     */
    private function get_images($row_index, $article)
    {
        $arr_empty_img_arr_exc = [25];

        if (empty($this->images_dirs_indexes) && !in_array($this->hl_block_manuf_id, $arr_empty_img_arr_exc)) {
            return false;
        }

        if ($this->hl_block_manuf_id == 25) {
            $grab = new GrabDeWaltImages();
            $images_dewalt = $grab->run($article);

            $images_dirs_temp = array();

            foreach ($images_dewalt as $key => $url) {
                if ($key === 0) {
                    $images_dirs_temp['preview'] = $url;
                } else {
                    $images_dirs_temp['additional'][] = $url;
                }
            }

            return $images_dirs_temp;
        }

        $images_dirs_temp = array();

        foreach ($this->images_dirs_indexes as $key => $column) {
            $path = trim($this->worksheet->getCell($column . $row_index)->getCalculatedValue());
            if ($path != '') {
                $final_path = $path;
                if (!filter_var($path, FILTER_VALIDATE_URL)) {
                    $final_path = $this->feed_root_dir . $path;
                }
                if ($this->hl_block_manuf_id == 97) {
                    $path = strtolower($path);
                    $final_path = $_SERVER['DOCUMENT_ROOT'] . '/upload/feed_metabo_images/'.$path;
                }
                if (empty($images_dirs_temp)) {
                    $images_dirs_temp['preview'] = $final_path;
                    continue;
                }
                $images_dirs_temp['additional'][] = $final_path;
            } elseif ($this->hl_block_manuf_id == 28) {
                $grabHikoki = new GrabHikokiImages;
                $hikokiImages = $grabHikoki->run($article);
                if (empty($hikokiImages)) {
                    return;
                }
                $images_hikoki_temp = [];
                foreach ($hikokiImages as $key => $url) {
                    if ($key === 0) {
                        $images_hikoki_temp['preview'] = $url;
                    } else {
                        $images_hikoki_temp['additional'][] = $url;
                    }
                }
                return $images_hikoki_temp;
            }
        }

        return $images_dirs_temp;
    }

    /**
     * Получить свойства товара для отображения на платформе
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     */
    private function get_props_values($row_index, $main_props_pre, $dim_props_pre)
    {
        $props_arr_temp = [];
        $dim_props_arr_temp = [];
        $props_arr_to_show = [];

        $country = $main_props_pre['Country'];
        $article = $main_props_pre['Article'];
        $min_order = $main_props_pre['MinimumOrder'];

        unset($main_props_pre);

        $props_arr_to_show['props']['MANUFACTURER'] = $this->hl_block_manuf_xml_id;
        $props_arr_to_show['props']['STRANA_PROIZVODSTVA'] = $this->get_country_outer_code($country);
        $props_arr_to_show['props']['CML2_ARTICLE'] = $article;
        $props_arr_to_show['props']['MIN_ORDER'] = $min_order;

        foreach ($this->goods_props_indexes as $name => $info_arr) {
            $column = $info_arr['Column'];
            $value = $this->worksheet->getCell($column . $row_index)->getCalculatedValue();
            if ($value != '') {
                $props_arr_temp[$name]['Value'] = $value;
                $props_arr_temp[$name]['PropType'] = $info_arr['PropType'];
                $props_arr_temp[$name]['ValueType'] = $info_arr['ValueType'];
            }
        }

        unset($name, $info_arr);

        foreach ($props_arr_temp as $name => $info_arr) {
            switch ($info_arr['PropType']) {
                case 'main':
                    $name = trim($name);
                    if ($info_arr['ValueType'] == 'N') {
                        $info_arr['Value'] = floatval(str_replace(',', '.', $info_arr['Value']));
                    } else {
                        $info_arr['Value'] = strval($info_arr['Value']);
                    }

                    if (array_key_exists($name, $this->platform_props)) {
                        $props_arr_to_show['props'][$this->platform_props[$name]] = $info_arr['Value'];
                        break;
                    }
                    $this->logger->addToLog('excel parse', '', ['msg' => 'Обнаружено новое основное свойство : ' . $name, 'feed_id' => $this->feed_ID]);

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
                        "PROPERTY_TYPE" => $info_arr['ValueType'],
                        'SMART_FILTER' => 'Y',
                        "IBLOCK_ID" => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW),
                    ];
                    $ibp = new \CIBlockProperty;
                    $propId = $ibp->add($arFields);
                    if (!$propId) {
                        $this->logger->addToLog('excel parse', 'error', ['msg' => 'Ошибка при добавлении свойства : ' . $name, 'error' => var_dump($ibp->LAST_ERROR) ,'feed_id' => $this->feed_ID]);
                    } else {
                        $props_arr_to_show['props'][$code] = $info_arr['Value'];
                        $this->logger->addToLog('excel parse', 'success', ['msg' => 'Свойство ' . $name. ' успешно добавлено', 'feed_id' => $this->feed_ID]);
                        $this->platform_props = $this->get_platform_props_arr();
                    }

                    break;

                case 'additional':
                    $props_arr_to_show['additional_props'][$name] = $info_arr['Value'];
                    break;
            }
        }

        unset($name, $info_arr);

        $dim_props_arr_temp = $this->get_dims_to_show($dim_props_pre);
        unset($dim_props_pre);
        foreach ($dim_props_arr_temp as $name => $value) {
            $props_arr_to_show['props'][$this->platform_props[$name]] = $value;
        }

        return $props_arr_to_show;
    }

    //
    // Вспомогательные методы
    //

    /**
     * Получить корректные для базы значения размеров и веса товара
     *
     * @param float|int $dim_value
     * Текущее значение размера или веса
     * @param string $dim_unit
     * ЕИ размера или веса
     */
    private function get_dimensions($dim_value, $dim_unit)
    {
        $dim_value = str_replace(',', '.', $dim_value);
        $ar_units = [
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

        $dim_value_temp = floatval($dim_value) * $ar_units[$dim_unit];  // Размеры в базу вносим в мм
        return $dim_value_temp;
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
    private function get_country_outer_code($country)
    {
        $ar_countries = [
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

        if (isset($ar_countries[$country])) {
            return $ar_countries[$country];
        }
        return false;
    }

    /**
     * Получить внешний код секции каталога
     *
     * @param PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator $cells
     * Итератор ячеек строки.
     *
     * @return $section_ID
     */
    private function get_section_code($row_index)
    {
        $section_ID = false;

        $ar_sections_names = [];

        foreach ($this->sections_indexes as $column) {
            $section_name = $this->worksheet->getCell($column . $row_index)->getCalculatedValue();
            if ($section_name == '') {
                break;
            }
            $ar_sections_names[] = trim($section_name);
        }

        $obSection = new \CIBlockSection();
        foreach ($ar_sections_names as $section_name) {

                // Ищем раздел по имени и ID родительского раздела
            // В $sectionId присваиваем ID найденного
            // И следующая итерация цикла будет уже с новым значением $sectionId
            // print_r("\n Ищем раздел: " . $sectionName . "\n");

            $ar_filter = array(
                    "IBLOCK_ID" => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW),
                    'SECTION_ID' => $section_ID,
                    'NAME' => $section_name,
                );

            $rs_sections = \CIBlockSection::GetList([], $ar_filter, false, array( 'ID', 'IBLOCK_SECTION_ID', 'NAME' ));

            if ($ar_section = $rs_sections->GetNext()) {
                $section_ID = $ar_section['ID'];
            } else {
                // Создаем новый раздел
                $section_code = $this->check_section_code(
                    $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW),
                    \Cutil::translit($section_name, "ru", ["replace_space"=>"_","replace_other"=>"_"])
                );
                $arFields = array(
                        "ACTIVE" => 'Y',
                        "IBLOCK_SECTION_ID" => $section_ID,
                        "IBLOCK_ID" => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW),
                        "NAME" => $section_name,
                        "CODE" => $section_code,
                        "XML_ID" => $section_code,
                        "SORT" => '500',
                    );
                $created_section_id = $obSection->Add($arFields);
                print_r("\n Создаем раздел: " . $arFields['NAME'] . "\n");
                if (!$created_section_id) {
                    $this->logger->addToLog('parser set catalog section', 'error', ['msg' => 'Ошибка создания раздела: ' . $obSection->LAST_ERROR, 'feed_id' => $this->feedId]);
                } else {
                    $section_ID = $created_section_id;
                    print_r("\n Создали раздел: " . $created_section_id . "\n");
                }
            }
        }

        return $section_ID;
    }

    private function check_section_code($IBLOCK_ID, $CODE)
    {
        $arCodes = array();
        $rsCodeLike = \CIBlockSection::GetList(array(), array(
            "IBLOCK_ID" => $IBLOCK_ID,
            "CODE" => $CODE."%",
        ), false, false, array("ID", "CODE"));
        while ($ar = $rsCodeLike->Fetch()) {
            $arCodes[$ar["CODE"]] = $ar["ID"];
        }

        if (array_key_exists($CODE, $arCodes)) {
            $i = 1;
            while (array_key_exists($CODE."_".$i, $arCodes)) {
                $i++;
            }
            return $CODE."_".$i;
        }
        return $CODE;
    }
    
    /**
     * Загрузить информацию о товаре
     *
     * @param array $product_props_arr
     * Массив свойств товара
     */
    private function import($product_props_arr)
    {
        $result = FeedsImportTempTable::add($product_props_arr);

        if (!$result->isSuccess()) {
            $this->logger->addToLog('parse feed', 'error', ['msg' => 'Ошибка создания товара', 'errors' => $result->getErrorMessages()]);
            $this->errors[$product_props_arr['ekn']][] = ['level' => 1, 'msg' => 'Ошибка создания товара : '. $result->getErrorMessages()];
        }
    }

    /**
     * Получить габариты и вес товара как свойства
     *
     * @param array $dim_props
     * Массив габаритов и веса товара
     */
    private function get_dims_to_show($dim_props)
    {
        $dim_props_arr_to_show = [];
        $translate = [
            'Length' => 'Длина',
            'Width' => 'Ширина',
            'Height' => 'Высота'
        ];

        foreach ($dim_props as $name => $value) {
            switch ($name) {
                case 'Weight':

                    if ($value >= 1000) {
                        $weight = $value * 0.001;
                        $dim_props_arr_to_show['Вес, кг'] = round($weight, 2, PHP_ROUND_HALF_UP);
                    }

                    $dim_props_arr_to_show['Вес, г'] = ceil(floatval($value));
                    break;
                
                default:
                    //Раскомментировать, если необходимо загрузить товар без габаритов (со значением -)
                    //if ($value === '-') continue;

                    //Если больше 10м -> добавляем метры и не меняем имя свойства
                    if ($value >= 10000) {
                        $new_name = $translate[$name];
                        $new_value = $value * 0.001;
                        $dim_props_arr_to_show[$new_name] = strval($new_value) . ' m';

                        break;
                    }

                    $new_name = $translate[$name] . ', мм';
                    $dim_props_arr_to_show[$new_name] = floatval($value);
                    break;
            }
        }

        return $dim_props_arr_to_show;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}
