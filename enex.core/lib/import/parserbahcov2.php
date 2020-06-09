<?php

namespace Enex\Core\Import;

use Citfact\Sitecore\Import\FeedParsers\FeedParserInterface;
use Citfact\Sitecore\Import\FeedsImportTempTable;
use Citfact\Sitecore\Import\ImportHelper;
use Citfact\Sitecore\Logger\TableLogger;
use Citfact\Sitecore\Logger\FeedsImporterDebugLogTable;
use Citfact\SiteCore\Tools\Files;

use Box\Spout\Reader\ReaderFactory;
use Box\Spout\Common\Type;

// Парсер xml-файлов от компании Bahco


class ParserBahcoV2 implements FeedParserInterface
{
    private $feedId;
    private $manufacturerId;
    private $logger;
    private $core;
    private $errors = [];
    private $testMode = false;
    private $tempFolder;
    private $feedInfo;
    private $arFilesPaths;
    private $columnsTranslateMap;
    private $propsMap;
    private $columnsIndexes;

    public function __construct()
    {
        echo 'Parser is created!' . "\n";
        $this->logger = new TableLogger(new FeedsImporterDebugLogTable());
        $this->core = \Citfact\SiteCore\Core::getInstance();
        $this->tempFolder = $_SERVER['DOCUMENT_ROOT'].'/upload/feeds_temp/';
    }


    /**
     * Считываем содержимое фида во временную таблицу
     * @param $feedId
     * @param $manufacturerId
     * @param $testMode
     * @return array|void
     * @throws \Exception
     */
    public function parseFeedToTable($feedId, $manufacturerId, $testMode)
    {
        echo 'start parsing, feedId = ' . $feedId . "\n";

        $this->feedId = $feedId;
        $this->manufacturerId = $manufacturerId;

        if ($testMode === true) {
            $this->testMode = true;
        }

        $this->feedInfo = ImportHelper::getFeedInfo((int)$this->feedId);

        if ($this->setFilesPaths() !== false) {
            $this->setColumnsTranslateMap();
            $this->setPropsMap();

            \Bitrix\Main\Diag\Debug::startTimeLabel('import_bahco');

            $reader = ReaderFactory::create(Type::XLSX); // for XLSX files

            $reader->open($this->arFilesPaths[0]);

            $count = 0;
            /** @var \Box\Spout\Reader\XLSX\SheetIterator $sheet */
            /** @var \Box\Spout\Reader\XLSX\RowIterator $row */
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $count++;
                    if ($count === 1) {
                        // Если в первой строке содержатся наименования колонок,
                        // то собираем значения в массив для установки соответствия индексов
                        if (array_key_exists($row[0], $this->columnsTranslateMap)) {
                            foreach ($row as $columnName) {
                                $this->columnsIndexes[] = htmlspecialchars(trim($columnName));
                            }
                            continue;
                        }
                    }

                    // Если массив индексов колонок пустой, то прерываем с ошибкой
                    if (empty($this->columnsIndexes)) {
                        // TODO: лог ошибки
                        break;
                    }

                    $this->parseRow($row, $count);

                    // Ограничение количества строк для обработки. Закомментировать для полного импорта.
                    if ($count === 10) {
                        //break;  // TODO: закомментировать в боевом варианте
                    }
                }

                break; // только первый лист
            }

            $reader->close();

            //print_r($this->columnsIndexes);
        }

        \Bitrix\Main\Diag\Debug::endTimeLabel('import_bahco');
        $arLabels = \Bitrix\Main\Diag\Debug::getTimeLabels();
        print_r('Времени потрачено: '.$arLabels['import_bahco']['time'] . " сек \n");
        print_r('Обработано строк: ' . $count . "\n");
    }


    private function setFilesPaths()
    {
        // Если фид - это файл, то возвращаем путь к файлу
        if ($this->feedInfo['load_type'] == 'file') {
            $fileId = (int)$this->feedInfo['file_id'];

            if ($fileId <= 0) {
                $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Некорректный ID файла', 'feed_id' => $this->feedId]);
                return false;
            }

            $filePath = $_SERVER['DOCUMENT_ROOT'] . \CFile::GetPath($fileId);

            if (!file_exists($filePath)) {
                $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Файл отсутствует', 'feed_id' => $this->feedId, 'file_id' => $fileId]);
                return false;
            }

            $detectedType = Files::getMimeType($filePath);
            if ($detectedType === 'application/zip') {
                $this->arFilesPaths = $this->unpackZip($filePath);
            } else {
                $this->arFilesPaths = [$filePath];
            }
        }


        // Если фид - это ссылка на URL, то получаем инфу http-запросом
        if ($this->feedInfo['load_type'] == 'link') {
            $this->logger->addToLog('parser set files paths', '', ['msg' => 'Получаем файл по ссылке', 'link' => $this->feedInfo['feed_url'], 'feed_id' => $this->feedId]);

            $username = $this->feedInfo['link_login'];
            $password = $this->feedInfo['link_password'];

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $this->feedInfo['feed_url']);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, false);

            // Если заполнены логин и пароль, то включаем режим HTTP-авторизации
            if ($username != '' && $password != '') {
                curl_setopt($curl, CURLOPT_USERPWD, $username . ":" . $password);
            }

            $fileContent = curl_exec($curl);
            $curlResponseCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);

            if (!$fileContent) {
                $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Пустое содержимое файла', 'link' => $this->feedInfo['feed_url'], 'feed_id' => $this->feedId]);
                $this->errors['Файл'][] = ['level' => 1, 'msg' => 'Пустое содержимое файла'];
                return false;
            }

            if ($curlResponseCode === 401) {
                $this->errors['Файл'][] = ['level' => 1, 'msg' => 'Ошибка авторизации'];
                return false;
            }

            $tempFileName = 'bahco_feed_file';
            $filePathFull = $this->tempFolder.$tempFileName;
            file_put_contents($filePathFull, $fileContent);

            $detectedType = Files::getMimeType($filePathFull);

            if ($detectedType === 'application/zip') {
                $this->arFilesPaths = $this->unpackZip($filePathFull);
            }
            // Если фид - это xml (а xlsx по внутренней структуре - это xml), то возвращаем путь к файлу
            elseif ($detectedType == 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
                $this->arFilesPaths = [$filePathFull];
            } else {
                $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Неправильный тип файла', 'link' => $this->feedInfo['feed_url'], 'feed_id' => $this->feedId]);
                $this->errors['Файл'][] = ['level' => 1, 'msg' => 'Некорректный тип файла'];
                return false;
            }
        }

        return true;
    }


    private function setColumnsTranslateMap()
    {
        $resTable = BahcoTranslateNewTable::getList([
            'select' => ['*'],
            'filter' => [],
        ]);

        while ($arRow = $resTable->fetch()) {
            $this->columnsTranslateMap[$arRow['english']]['RUSSIAN'] = $arRow['russian'];
            $this->columnsTranslateMap[$arRow['english']]['TYPE'] = $arRow['type'];
            $this->columnsTranslateMap[$arRow['english']]['VALUE_TYPE'] = $arRow['value_type'];
        }
    }


    private function setPropsMap()
    {
        $rsProps = \CIBlockProperty::GetList(
            array('SORT' => 'ASC', 'ID' => 'ASC'),
            array('IBLOCK_ID' => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW))
        );
        while ($arProp = $rsProps->Fetch()) {
            $this->propsMap[trim($arProp['NAME'])] = trim($arProp['CODE']);
        }
    }


    /**
     * Записываем данные строки во временную таблицу
     * @param \Box\Spout\Reader\XLSX\RowIterator $row
     * @param $currentStringNumber
     * @throws \Bitrix\Main\ObjectException
     */
    private function parseRow($row, $currentStringNumber)
    {
        //print_r('====================================================================================');
        //print_r($row);

        // Собираем непустые значения колонок в массив
        $arColumnsValues = [];
        foreach ($row as $key => $columnValue) {
            $columnValue = htmlspecialchars(trim($columnValue));
            if ($columnValue != '') {
                $arColumnsValues[$this->columnsIndexes[$key]] = $columnValue;
            }
        }
        //print_r($arColumnsValues);

        if (empty($arColumnsValues)) {
            return;
        }

        // Соответствие колонок из файлам полям товара во временной таблице:
        // Наименование - Наименование товара
        // Product Code - Артикул

        $productName = $arColumnsValues['Наименование'];
        unset($arColumnsValues['Наименование']);

        $productDescription = $this->selectDescription($arColumnsValues);

        // Т.к. цена без НДС, то прибавляем к цене НДС
        $productPrice = (float)$arColumnsValues['Цена за единицу, РУБ, без НДС'] * 1.2;


        // Изображения лежат в папке /upload/feed_bahco_images/
        // Product_Images = колонка ET
        // Application_Images = EV
        // Drawings = EW
        // additional_pictures = IX
        $arImages = [];
        $imagePath = $arColumnsValues['Product_Images']
            .'|'.$arColumnsValues['Application_Images']
            .'|'.$arColumnsValues['Drawings']
            .'|'.$arColumnsValues['additional_pictures'];
        if ($imagePath != '') {
            // Меняем расширение файла на jpg
            $arExploded = explode('|', $imagePath);
            foreach ($arExploded as $key => $filename) {
                if (!$filename) {
                    continue;
                }

                $basename = basename(trim($filename), '.tif');
                $basename = str_replace(['.eps', '.tiff', '.TIF', '.jpg'], '', $basename);
                $filePath = $_SERVER['DOCUMENT_ROOT'] . '/upload/feed_bahco_images/' . $basename . '.jpg';
                if ($key === 0) {
                    $arImages['preview'] = $filePath;
                } else {
                    $arImages['additional'][] = $filePath;
                }
            }
        }

        // Weight_g_pictwt.png_Metric - колонка веса в граммах
        // Weight_kg_WEIGHT.png_Generic - колонка веса в кг
        // Вес вносим в граммах
        $productWeight = '';
        if ($arColumnsValues['Weight_g_pictwt.png_Metric'] != '') {
            $productWeight = (float) str_replace('g', '', $arColumnsValues['Weight_g_pictwt.png_Metric']);
        } else {
            if ($arColumnsValues['Weight_kg_WEIGHT.png_Generic'] != '') {
                $productWeight = (float) str_replace('kg', '', $arColumnsValues['Weight_kg_WEIGHT.png_Generic']) * 1000;
            }
        }

        $productMeasure = ''; // Единица измерения (для Bahco штуки по-умолчанию)

        $productLength = (float) str_replace('mm', '', $arColumnsValues['PACKLENG']);
        $productWidth = (float) str_replace('mm', '', $arColumnsValues['PACKWIDT']);
        $productHeight = (float) str_replace('mm', '', $arColumnsValues['PACKHEIG']);

        $propsValues = $this->selectPropsValues($arColumnsValues);

        //print_r($propsValues);

        $productEkn = $arColumnsValues['Product Code'];

        // Создаем или присваиваем товару раздел каталога товаров
        $sectionId = $this->selectSection($arColumnsValues, $productEkn);
        // print_r("\n" . 'Выбрали раздел: ' . $sectionId . "\n");

        $arItem = [
            'manufacturer_id' => $this->manufacturerId,
            'feed_id' => $this->feedId,
            'active' => 'Y',
            'name' => $productName,
            'ekn' => $productEkn,
            'section' => $sectionId,
            'props_values' => json_encode($propsValues['props'], JSON_UNESCAPED_UNICODE),
            'additional_props_values' => json_encode($propsValues['additional_props'], JSON_UNESCAPED_UNICODE),
            'description' => $productDescription,
            'price' => $productPrice,
            'images' => json_encode($arImages, JSON_UNESCAPED_UNICODE),
            'currency' => 'RUB',
            'weight' => $productWeight,  // Вес в базу вносим в граммах
            'measure' => $productMeasure,
            'length' => $productLength,
            'width' => $productWidth,
            'height' => $productHeight,
            'currentStringNumber' => $currentStringNumber,
        ];

        // Проверяем целостность данных
        $arErrors = $this->checkItemData($row, $arItem);
        $arItem['errors'] = !empty($arErrors) ? json_encode($arErrors, JSON_UNESCAPED_UNICODE) : '';
        if (!empty($arErrors)) {
            return;
        }

        /** @var \Bitrix\Main\Result $result */
        $result = FeedsImportTempTable::add($arItem);
        if (!$result->isSuccess()) {
            $this->logger->addToLog('parse feed', 'error', ['msg' => 'Ошибка создания товара во временной таблице', 'errors' => $result->getErrorMessages()]);
        }
    }


    /**
     * Собираем описание товара из нескольких колонок
     * @param $arColumnsValues
     * @return string
     */
    private function selectDescription($arColumnsValues)
    {
        $description = '';
        $arColumnsNames = [
            'Technical_Description',
            'Technical_Description_local',
            'Marketing_Text',
            'Marketing_Text_local',
            'Additional_Text',
            'Assortment_Text',
        ];

        foreach ($arColumnsNames as $columnName) {
            $columnValue = htmlspecialchars(trim($arColumnsValues[$columnName]));
            if ($columnValue != '') {
                $description .= $columnValue . '|';
            }
        }

        return $description;
    }


    /**
     * Собираем значения свойств товара
     * @param $arColumnsValues
     * @return array
     */
    private function selectPropsValues($arColumnsValues)
    {
        $arPropsValues = ['props' => [], 'additional_props' => []];

        foreach ($arColumnsValues as $columnNameEng => $columnValue) {
            if ($this->columnsTranslateMap[$columnNameEng] && $this->columnsTranslateMap[$columnNameEng]['TYPE'] == 'Доп') {
                if ($this->columnsTranslateMap[$columnNameEng]['RUSSIAN'] == 'Сварной шов' && $columnValue !== 'Y') {
                    continue;
                }
                $arPropsValues['additional_props'][ $this->columnsTranslateMap[$columnNameEng]['RUSSIAN'] ] = $columnValue;
            }
        }

        // Заполняем основные свойства
        $arPropsValues['props']['MANUFACTURER'] = self::MANUF_ID_BAHCO;
        $arPropsValues['props']['CML2_ARTICLE'] = $arColumnsValues['Product Code'];
        $arPropsValues['props']['MIN_ORDER'] = $arColumnsValues['Quantity_Min'];
        $arPropsValues['props']['NUMBER_IN_PACKAGE'] = $arColumnsValues['Количество единиц  в упаковке'];
        $arPropsValues['props']['PACKAGE_DIVISION'] = $arColumnsValues['Делимость упаковки'];
        $arPropsValues['props']['STRANA_PROIZVODSTVA'] = $arColumnsValues['ORIGNAME'];

        if (in_array(null, $arPropsValues['props'])) {
            return;
        }

        $arPropsValues['props']['EAN_CODE'] = $arColumnsValues['EAN-код'];

        // Ищем русское название колонки в массиве с переведенными названиями колонок
        // Если нашли, то записываем значение в массив основных свойств
        foreach ($arColumnsValues as $columnNameEng => $columnValue) {
            if (!array_key_exists($columnNameEng, $this->columnsTranslateMap) || $this->columnsTranslateMap[$columnNameEng]['TYPE'] == 'Доп') {
                continue;
            }

            $value = $columnValue;
            $nameRu =  $this->columnsTranslateMap[$columnNameEng]['RUSSIAN'];
            $value_type = $this->columnsTranslateMap[$columnNameEng]['VALUE_TYPE'];

            switch ($value_type) {
                case 'N':
                    $value = floatval(str_replace(',', '.', $value));
                    break;

                default:
                    $value = strval($value);
                    break;
            }


            $arPropsValues['props'][ $this->propsMap[ $nameRu ] ] =  $value;
        }

        if (isset($arPropsValues['props']['VES_G']) && !isset($arPropsValues['props']['VES_KG']) && $arPropsValues['props']['VES_G'] >= 1000) {
            $arPropsValues['props']['VES_KG'] = $arPropsValues['props']['VES_G'] * 0.001;
        }

        if (!isset($arPropsValues['props']['VES_G']) && isset($arPropsValues['props']['VES_KG'])) {
            $arPropsValues['props']['VES_G'] = $arPropsValues['props']['VES_KG'] * 1000;
        }

        return $arPropsValues;
    }


    /**
     * Проверяем массив элемента на ошибки данных
     * @param $row
     * @param $arItem
     * @return array
     */
    private function checkItemData($row, $arItem)
    {
        $arErrors = [];

        if ($arItem['name'] == '') {
            $arErrors[] = ['level' => 1, 'msg' => 'Не заполнено имя товара'];
        }

        if ($arItem['ekn'] == '') {
            $arErrors[] = ['level' => 1, 'msg' => 'Не заполнен артикул товара'];
        }

        if ($arItem['section'] == '') {
            $arErrors[] = ['level' => 1, 'msg' => 'Не заполнен ID раздела'];
        }

        if ($arItem['price'] == '') {
            $arErrors[] = ['level' => 1, 'msg' => 'Не заполнена цена товара'];
        }

        if ($arItem['weight'] == '') {
            $arErrors[] = ['level' => 1, 'msg' => 'Не заполнен вес товара'];
        }

        if (empty($arItem['props_values'])) {
            $arErrors[] = ['level' => 1, 'msg' => 'Не получены свойства товара'];
        }

        /*if ($arItem['measure'] == ''){
            $arErrors[] = ['level' => 1, 'msg' => 'Не заполнена единица измерения товара'];
        }*/

        if (!empty($arErrors)) {
            $this->errors['Строка ' . $arItem['currentStringNumber']] = $arErrors;
        }

        return $arErrors;
    }


    /**
     * Раздел для товара: выбираем существующий или создаем новый
     * @param array $arColumnsValues
     * @return string
     * @throws \Exception
     */
    private function selectSection(array $arColumnsValues, string $article)
    {
        $setSectionFor = [
            'BH1A1500',
            'BH12000',
            'BH15000A',
            'BH1M1000',
            'BH13000',
            'BH8AC3-1500',
            'BH1EU3000',
            'BH13000QA',
            'BH11500'
        ];

        if (in_array($article, $setSectionFor)) {
            return 1417;
        }
        // print_r("\n".__METHOD__."\n");
        $sectionId = false;

        $sectionNameLvl1 = htmlspecialcharsbx(trim($arColumnsValues['Основные категории (1 уровень)']));
        $sectionNameLvl2 = htmlspecialcharsbx(trim($arColumnsValues['Подкатегории (2 уровень)']));
        $sectionNameLvl3 = htmlspecialcharsbx(trim($arColumnsValues['Подкатегории (3 уровень)']));
        $sectionNameLvl4 = htmlspecialcharsbx(trim($arColumnsValues['Подкатегории (4 уровень)']));

        // Выбираем раздел по имени или создаем новый
        if ($sectionNameLvl1 != '') {
            $arSectionsNames = [
                1 => $sectionNameLvl1,
                2 => $sectionNameLvl2,
                3 => $sectionNameLvl3,
                4 => $sectionNameLvl4,
            ];

            $obSection = new \CIBlockSection();
            foreach ($arSectionsNames as $sectionName) {
                if ($sectionName != '') {
                    // Ищем раздел по имени и ID родительского раздела
                    // Если не нашли, то создаем новый раздел
                    // В $sectionId присваиваем ID найденного или созданного раздела
                    // И следующая итерация цикла будет уже с новым значением $sectionId

                    // print_r("\n Ищем раздел: " . $sectionName . "\n");

                    $arFilter = array(
                        "IBLOCK_ID" => $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW),
                        'SECTION_ID' => $sectionId,
                        'NAME' => $sectionName,
                    );
                    $rsSections = \CIBlockSection::GetList([], $arFilter, false, array('ID', 'IBLOCK_SECTION_ID', 'NAME'));
                    if ($arSection = $rsSections->GetNext()) {
                        $sectionId = $arSection['ID'];
                    // print_r("\n Нашли раздел: " . $sectionId . "\n");
                    } else {
                        // Создаем новый раздел
                        $sectionCode = $this->CheckSectionCode(
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
            }
        }

        return $sectionId;
    }


    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }

    private function CheckSectionCode($IBLOCK_ID, $CODE)
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
        } else {
            return $CODE;
        }
    }
}
