<?php
namespace Enex\Core\Library;

use \Bitrix\Main\Diag\Debug;

class ImportGosts
{
    /**
     * @var \Enex\Core\Library\GostsHelper
     */
    private $gostHelper;

    /**
     * Свойства элементов с платформы
     * @var array
     */
    private $platformProps;

    /**
     * Директория для логов
     * @var string
     */
    private $logDir;

    /**
     * Объект класса CIBlockElement
     * @var \CIBlockElement;
     */
    private $ciBlockElementObj;

    public function __construct()
    {
        $this->gostHelper = new GostsHelper();
        $this->ciBlockElementObj = new \CIBlockElement;
        $this->platformProps = $this->getPlatformProps();
        $core = \Citfact\SiteCore\Core::getInstance();
        $this->logDir = $core::LOG_DIR_PATH.'import_gosts.log';
        unset($core);
    }

    /**
     * Импортировать элементы
     * @return void
     */
    public function import()
    {
        Debug::startTimeLabel('import_gosts');
        Debug::writeToFile(date("Y-m-d H:i:s").' - START', '', $this->logDir);
        $this->platformProps = $this->getPlatformProps();
        if (!in_array('MORE_PHOTO', $this->platformProps)) {
            $arFieldsMorePhoto = [
                "NAME" => 'Доп фото',
                "ACTIVE" => "Y",
                "SORT" => "500",
                "CODE" => 'MORE_PHOTO',
                "PROPERTY_TYPE" => 'F',
                "IBLOCK_ID" => $this->gostHelper->iblockID,
                'MULTIPLE' => 'Y'
            ];
            $ibp = new \CIBlockProperty;
            $propID = $ibp->add($arFieldsMorePhoto);
            if (!$propID) {
                Debug::writeToFile(array('MSG' => 'Ошибка создания свойства: ' . $propID->LAST_ERROR, 'PROP_NAME' => 'Доп фото'), '', $this->logDir);
            }
            unset($ibp);
            $this->platformProps = $this->getPlatformProps();
        }

        $map = $this->gostHelper->getParseMap();
        $fileArJson = \Bitrix\Main\IO\File::getFileContents(__DIR__. '/jsons/filemap.json');
        if (!$fileArJson) {
            die('Файл filemap не был получен.');
        }
        $fileMap = json_decode($fileArJson, true);
        unset($fileArJson);

        foreach ($map as $rootSectionName => $subSectionArray) {
            $rootSectionID = $this->gostHelper->checkSection($rootSectionName);
            if (!$rootSectionID) {
                $rootSectionID = $this->gostHelper->addSectionByName($rootSectionName);
            }

            if (is_array($rootSectionID)) {
                Debug::writeToFile(array('MSG' => 'Ошибка создания раздела: ' . $rootSectionID['ERROR'], 'SECTION_NAME' => $rootSectionName), '', $this->logDir);
                continue;
            }

            foreach ($subSectionArray as $subSectionName => $innerSections) {
                $subSectionID = $rootSectionID;
                if ($subSectionName != '') {
                    $subSectionID = $this->gostHelper->checkSection($subSectionName, $rootSectionID);
                    if (!$subSectionID) {
                        $subSectionID = $this->gostHelper->addSectionByName($subSectionName, $rootSectionID);
                    }
                    if (is_array($subSectionID)) {
                        Debug::writeToFile(array('MSG' => 'Ошибка создания раздела: ' . $subSectionID['ERROR'], 'SECTION_NAME' => $subSectionName), '', $this->logDir);
                        continue;
                    }
                }

                foreach ($innerSections as $innerSection) {
                    $innerSectionName = $innerSection['NAME'];
                    $innerSectionID = $this->gostHelper->checkSection($innerSectionName, $subSectionID);
                    if (!$innerSectionID) {
                        $innerSectionID = $this->gostHelper->addSectionByName($innerSectionName, $subSectionID);
                    }

                    if (is_array($innerSectionID)) {
                        Debug::writeToFile(array('MSG' => 'Ошибка создания раздела: ' . $innerSectionID['ERROR'], 'SECTION_NAME' => $innerSectionName), '', $this->logDir);
                        continue;
                    }

                    $linkSource = explode('/', $innerSection['LINK'])[2];

                    switch ($linkSource) {

                        case 'xn--e1aflbecbhjekmek.xn--p1ai':

                            $fileNameJson = array_search($innerSectionName, $fileMap);
                            if ($fileNameJson === false) {
                                Debug::writeToFile('Не найдено название файла для: ' . $innerSectionName, '', $this->logDir);
                                break;
                            }


                            $dir = $this->gostHelper->docRoot.'/local/var/jsons/'.$fileNameJson.'.json';
                            if (!file_exists($dir)) {
                                Debug::writeToFile(array('MSG' => 'Не найден файл: ' . $dir, 'SECTION_NAME' => $innerSectionName), '', $this->logDir);
                                break;
                            }
                            $json = \Bitrix\Main\IO\File::getFileContents($dir);
                            if (!$json) {
                                Debug::writeToFile(array('MSG' => 'Файл '.$dir.' не был получен.'), '', $this->logDir);
                                break;
                            }

                            $elements = json_decode($json, true);
                            unset($json);
                            foreach ($elements as $element) {
                                if (!$element['CODE']) {
                                    $translitParams = [
                                        "max_len" => "100", // обрезает символьный код до 100 символов
                                        "change_case" => "L", // буквы преобразуются к нижнему регистру
                                        "replace_space" => "_", // меняем пробелы на нижнее подчеркивание
                                        "replace_other" => "_", // меняем левые символы на нижнее подчеркивание
                                        "delete_repeat_replace" => "true", // удаляем повторяющиеся нижние подчеркивания
                                        "use_google" => "false", // отключаем использование google
                                    ];
                                    $code = \CUtil::translit($element['NAME'], "ru", $translitParams);
                                    $element['CODE'] = $code;
                                }

                                unset($element['IBLOCK_SECTION_NAME']);
                                $element['IBLOCK_ID'] = $this->gostHelper->iblockID;
                                $element['IBLOCK_SECTION_ID'] = $innerSectionID;
                                if (array_key_exists('PREVIEW_PICTURE', $element)) {
                                    $path = str_replace('http://проминструмент.рф', '', $element['PREVIEW_PICTURE']);
                                    $imgId = $this->gostHelper->saveImageByUrl($path, $innerSectionName);
                                    $element['PREVIEW_PICTURE'] = $imgId;
                                }
                                $props = [];
                                foreach ($element['PROPERTY_VALUES'] as $prop) {
                                    if ($prop['CODE'] == 'MORE_PHOTO' || $prop['NAME'] == '') {
                                        continue;
                                    }
                                    
                                    $value = $prop['VALUE'];
                                    unset($prop['VALUE']);
                                    $prop['IBLOCK_ID'] = $this->gostHelper->iblockID;

                                    if (!array_key_exists($prop['NAME'], $this->platformProps)) {
                                        $ibp = new \CIBlockProperty;
                                        $propID = $ibp->add($prop);
                                        if (!$propID) {
                                            Debug::writeToFile(array('MSG' => 'Ошибка создания свойства: ' . $ibp->LAST_ERROR, 'PROP' => print_r($prop, true)), '', $this->logDir);
                                            unset($ibp);
                                            continue;
                                        }
                                        $this->platformProps = $this->getPlatformProps();
                                    }

                                    $props[$this->platformProps[$prop['NAME']]] = $value;
                                }

                                $element['PROPERTY_VALUES'] = $props;

                                $elementID = $this->getElementIdByName($element['NAME'], $innerSectionID);
                                if (!$elementID) {
                                    $element['CODE'] = $this->checkElementCode($element['CODE']);
                                    $status = $this->addElement($element);
                                    if ($status === false) {
                                        Debug::writeToFile(array('MSG' => 'Ошибка создания элемента: ' . $element['NAME'], 'SECTION' => $innerSectionName, 'ERROR' => $this->ciBlockElementObj->LAST_ERROR), '', $this->logDir);
                                    } else {
                                        Debug::writeToFile(array('MSG' => 'Создан элемент: ' . $element['NAME'], 'SECTION' => $innerSectionName), '', $this->logDir);
                                    }
                                    continue;
                                }

                                $status = $this->updateElementByID($elementID, $element);
                                if ($status === false) {
                                    Debug::writeToFile(array('MSG' => 'Ошибка обновления элемента: ' . $element['NAME'], 'SECTION' => $innerSectionName, 'ERROR' => $this->ciBlockElementObj->LAST_ERROR), '', $this->logDir);
                                    continue;
                                }
                                Debug::writeToFile(array('MSG' => 'Обновлён элемент: ' . $element['NAME'], 'SECTION' => $innerSectionName), '', $this->logDir);
                            }
                        break;

                        default:
                        break;

                    }
                }
            }
        }

        Debug::endTimeLabel('import_gosts');
        $ar_labels = Debug::getTimeLabels();
        echo 'Времени потрачено: ' . $ar_labels['import_gosts']['time'] . ' сек.' . PHP_EOL;
    }

    /**
     * Создать элемент
     * @param array $arParams параметры элемента
     * @return bool
     */
    private function addElement($arParams)
    {
        return $this->ciBlockElementObj->Add($arParams);
    }

    /**
     * Обновить элемент по ID
     * @param string|int $elementID ID элемента
     * @param array $arParams параметры элемента
     * @return bool
     */
    private function updateElementByID($elementID, $arParams)
    {
        return $this->ciBlockElementObj->Update($elementID, $arParams);
    }

    /**
     * Получить свойства с сайта
     * @return array
     */
    private function getPlatformProps()
    {
        $rsProps = \CIBlockProperty::GetList(
            array( 'SORT' => 'ASC',
                   'ID'   => 'ASC' ),
            array( 'IBLOCK_ID' => $this->gostHelper->iblockID )
        );

        $arrPlatformProps = [];

        while ($arProp = $rsProps->Fetch()) {
            $arrPlatformProps[trim($arProp['NAME'])] = trim($arProp['CODE']);
        }

        return $arrPlatformProps;
    }

    /**
     * Получить ID элемента по имени.
     * @param string $elementName имя элемента.
     * @param int $sectionID id раздела
     * @return bool|string
     */
    private function getElementIdByName($elementName, $sectionID)
    {
        $arFilter = [
            'IBLOCK_SECTION_ID' => $sectionID,
            'IBLOCK_ID' => $this->gostHelper->iblockID,
            'NAME' => $elementName,
        ];

        $element = \CIBlockElement::GetList([], $arFilter, false, false, ['ID']);

        $id = false;
        if ($res = $element->GetNext()) {
            $id = $res['ID'];
        }
        return $id;
    }

    /**
     * Получить валидный код элемента
     * @param string $code код элемента
     * @return string
     */
    private function checkElementCode($code)
    {
        $arCodes = [];
        $rsCodeLike = \CIBlockElement::GetList(array(), array(
            "IBLOCK_ID" => $this->gostHelper->iblockID,
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
}
