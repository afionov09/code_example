<?php
namespace Enex\Core\Library;

use Dompdf\Dompdf;
use \Bitrix\Main\Diag\Debug;
use Throwable;

class GetGosts
{
    /**
     * @var Enex\Core\Library\GostsHelper
     */
    private $gostHelper;

    /**
     * Использовать тор при обработке
     * @var bool
     */
    private $useTor = false;

    /**
     * Директория для файла лога
     * @var string
     */
    private $logDir;

    /**
     * Слип тайм для паузы обработки пром инструмента
     * @var int
     */
    private $sleepTime;

    public function __construct()
    {
        $this->sleepTime = 0;
        $this->gostHelper = new GostsHelper(true);
        $core = \Citfact\SiteCore\Core::getInstance();
        $this->logDir = $core::LOG_DIR_PATH.'gosts.log';
        unset($core);
    }

    /**
     * Получить все ГОСТы.
     * @param bool $useTor Использовать ли Tor при обработке
     * @return void
     */
    public function run($useTor = false)
    {
        Debug::startTimeLabel('get_gosts');
        Debug::writeToFile(date("Y-m-d H:i:s").' - START', '', $this->logDir);

        if ($useTor) {
            $this->useTor = true;
        }

        $parseMap = $this->gostHelper->getParseMap();
        $inpoGOSTsMap = $this->gostHelper->getInpoGostsMap();

        if ($inpoGOSTsMap === false) {
            $this->gostHelper->createInpoGostsMap();
            $inpoGOSTsMap = $this->gostHelper->getInpoGostsMap();
        }

        $inpoGOSTsNames = array_map('trim', array_column($inpoGOSTsMap, 'NAME'));
        $inpoGOSTsNums = array_column($inpoGOSTsMap, 'GOST');

        $ufManager = new \CUserTypeManager;
        
        foreach ($parseMap as $rootSectionName => $subSectionArray) {
            $rootSectionID = $this->getSectionID($rootSectionName);
            if ($rootSectionID === false) {
                continue;
            }

            foreach ($subSectionArray as $subSectionName => $innerSections) {
                $subSectionID = $rootSectionID;
                if ($subSectionName != '') {
                    $subSectionID = $this->getSectionID($subSectionName, $rootSectionID);
                    if ($subSectionID === false) {
                        continue;
                    }
                }


                foreach ($innerSections as $innerSection) {
                    $innerSectionName = $innerSection['NAME'];
                    $innerSectionURL = $innerSection['LINK'];
                    $innerSectionID = $this->getSectionID($innerSectionName, $subSectionID);
                    if ($innerSectionID === false) {
                        continue;
                    }

                    $linkSource = explode('/', $innerSectionURL)[2];

                    switch ($linkSource) {

                        case 'xn--e1aflbecbhjekmek.xn--p1ai':
                            $gostCode = explode(' ', $innerSectionName);
                            $key = array_search(end($gostCode), $inpoGOSTsNums);
                            if (!$key) {
                                $key = array_search($innerSectionName, $inpoGOSTsNames);
                                if (!$key) {
                                    Debug::writeToFile(array('MSG' => 'Не найден пдф - PROM', 'INNER_SECTION_NAME' => $innerSectionName, 'GOST_CODE' => end($gostCode)), '', $this->logDir);
                                }
                            }
                            $gostSrc = $inpoGOSTsMap[$key]['PATH'];
                            $file = \CFile::MakeFileArray($gostSrc);
                            $ufManager->Update('IBLOCK_'.$this->gostHelper->iblockID.'_SECTION', $innerSectionID, array(
                                'UF_FILE_PDF'  => $file
                            ));
                            break;
                            $fileNameJson = $this->gostHelper->handleName($innerSectionName);
                            // if ($this->gostHelper->checkFileProm($fileNameJson)) {
                            //     Debug::writeToFile(date("Y-m-d H:i:s").' - Найден json для раздела - '.$innerSectionName, '', $this->logDir);
                            //     break;
                            // };
                            $elements = $this->handleProm($innerSectionURL);
                            if (!$elements) {
                                break;
                            }

                            $elementsOutput = [];

                            foreach ($elements as $elementProps) {
                                $elementTemp = [];

                                foreach ($elementProps as $elementPropName => $elementPropValue) {
                                    if (strrpos($elementPropName, 'Наименование') !== false || strrpos($elementPropName, 'Обозначение') !== false) {
                                        $elementTemp['NAME'] = $elementPropValue;
                                    } else {
                                        $elementTemp['PROPS'][$elementPropName] = $elementPropValue;
                                    }
                                }
                                // Комментарий к задаче.
                                if ($innerSectionName == 'Ключи гаечные торцовые изогнутые (Г-образные) с внутренним шестигранником ГОСТ 25788') {
                                    $elementTemp['NAME'] = str_replace('6910-0', '7812-1', $elementTemp['NAME']);
                                }

                                $elementParams = $this->getElementParamsToOuput($elementTemp['NAME'], $innerSectionName, $elementTemp['PROPS']);
                                $elementsOutput[] = $elementParams;
                            }

                            $jsonOutput = json_encode($elementsOutput, JSON_UNESCAPED_UNICODE);
                            file_put_contents($this->gostHelper->docRoot.'/local/var/jsons/'.$fileNameJson.'.json', $jsonOutput);
                            unset($elements, $elementProps, $elementPropName, $elementPropValue, $elementParams, $jsonOutput);
                            Debug::writeToFile(date("Y-m-d H:i:s").' - Файл загружен - /local/var/jsons/'.$fileNameJson.'.json для раздела - '.$innerSectionName, '', $this->logDir);
                        break;

                        case 'www.inpo.ru':
                            $key = array_search($innerSectionName, $inpoGOSTsNames);
                            if (!$key) {
                                Debug::writeToFile(array('MSG' => 'Не найден пдф - INPO', 'INNER_SECTION_NAME' => $innerSectionName), '', $this->logDir);
                                break;
                            }
                            $gostSrc = $inpoGOSTsMap[$key]['PATH'];
                            $file = \CFile::MakeFileArray($gostSrc);
                            $ufManager->Update('IBLOCK_'.$this->gostHelper->iblockID.'_SECTION', $innerSectionID, array(
                                'UF_FILE_PDF'  => $file
                            ));
                        break;

                        case 'vsegost.com':
                            break;
                            $arImg = $this->handleVsegost($innerSectionURL);
                            $str = '<html><body>';
                            foreach ($arImg as $imgUrl) {
                                $imgAr = $this->gostHelper->saveImageByUrl($imgUrl, $innerSectionName);
                                $str .= '<img src="'.$imgAr['tmp_name'].'"><br>';
                            }
                            $str .= '</body></html>';
                            $dompdf = new Dompdf();
                            $dompdf->loadHtml($str);
                            $dompdf->setPaper('A4');
                            $dompdf->render();
                            $output = $dompdf->output();
                            $gostSrc = $this->gostHelper->saveFile($output, $innerSectionName, 'pdf', $subSectionName, $innerSectionName);
                            unset($output, $str, $dompdf, $arImg, $imgUrl);
                            if (!$gostSrc) {
                                Debug::writeToFile(array('MSG' => 'Не удалось сохранить файл - VSEGOST', 'INNER_SECTION_NAME' => $innerSectionName), '', $this->logDir);
                                break;
                            }
                            $file = \CFile::MakeFileArray($gostSrc);
                            $ufManager->Update('IBLOCK_'.$this->gostHelper->iblockID.'_SECTION', $innerSectionID, array(
                                'UF_FILE_PDF'  => $file
                            ));
                        break;

                        case 'standartgost.ru':
                            break;
                            $returnContent = $this->handleStandartgost($innerSectionName, $innerSectionURL);
                            $gostSrc = $this->gostHelper->saveFile($returnContent, $innerSectionName, 'pdf', $subSectionName, $innerSectionName);
                            unset($returnContent);
                            if (!$gostSrc) {
                                Debug::writeToFile(array('MSG' => 'Не удалось сохранить пдф - STANDARTGOST', 'INNER_SECTION_NAME' => $innerSectionName), '', $this->logDir);
                                break;
                            }
                            $file = \CFile::MakeFileArray($gostSrc);
                            $ufManager->Update('IBLOCK_'.$this->gostHelper->iblockID.'_SECTION', $innerSectionID, array(
                                'UF_FILE_PDF'  => $file
                            ));
                        break;

                        default:
                            Debug::writeToFile(array('MSG' => 'Неизвестная ссылка', 'URL' => $innerSectionURL), '', $this->logDir);
                        break;

                    }

                    unset($file, $gostSrc);
                }
            }
        }

        Debug::endTimeLabel('get_gosts');
        $ar_labels = Debug::getTimeLabels();
        echo 'Времени потрачено: ' . $ar_labels['get_gosts']['time'] . ' сек.' . PHP_EOL;
    }

    /**
     * Получить ID для раздела, если не существует - создать
     * @param string $sectionName Название раздела
     * @param string|bool $subSectionId ID раздела родителя
     * @return int ID раздела
     * @return false Ошибка создания раздела
     */
    private function getSectionID($sectionName, $subSectionId = false)
    {
        $sectionID = $this->gostHelper->checkSection($sectionName, $subSectionId);
        if (!$sectionID) {
            $sectionID = $this->gostHelper->addSectionByName($sectionName, $subSectionId);
        }

        if (is_array($sectionID)) {
            Debug::writeToFile(array('MSG' => 'Ошибка создания раздела: ' . $sectionID['ERROR'], 'SECTION_NAME' => $sectionName), '', $this->logDir);
            return false;
        }

        return (int)$sectionID;
    }

    /**
     * Обработка гостов Vsegost
     * @param string $link
     * @return array массив картинок для pdf ГОСТ
     */
    private function handleVsegost($link)
    {
        $page = $this->gostHelper->getPage($link);
        $dom = $this->gostHelper->getDOMTree($page);
        $a_tags = $dom->getElementsByTagName('a');
        $arImg = [];
        foreach ($a_tags as $a_tag) {
            if ($a_tag->getAttribute('rel') == 'gb_imageset[g]') {
                $imgSrc = $a_tag->getAttribute('href');
                $imgSrc = str_replace('../../', 'http://vsegost.com/', $imgSrc);
                $arImg[] = $imgSrc;
            }
        }
        $arImg = array_unique($arImg);
        return $arImg;
    }

    /**
     * Обработка гостов Standartgost
     * @param string $name название ГОСТа
     * @return string контент пдфа ГОСТа
     */
    private function handleStandartgost($name)
    {
        $gost = explode('.', $name);
        $gost = trim(end($gost));
        $gost = str_replace(' ', '_', $gost);
        if ($gost == 'МИ_2240-98') {
            $gost = 'pkey-14294845759/МИ_2240-98';
        }
        $link =  'https://standartgost.ru/g/'.$gost;
        $standartDOMTree = $this->gostHelper->getDOMTree($this->gostHelper->getPage($link));
        $divs = $standartDOMTree->getElementsByTagName('div');
        $imgToHandle = '';
        foreach ($divs as $div) {
            if ($div->getAttribute('class') == 'page' && $div->getAttribute('itemtype') == 'http://schema.org/ImageObject') {
                $imgStandartGost = $div->getElementsByTagName('img');
                $imgToHandle = $imgStandartGost->item(0)->getAttribute('src');
            }
        }
        $imgToHandle = str_replace(['img', 'images', '/g'], ['pdf', 'catalog', ''], $imgToHandle);
        $startPos = stripos($imgToHandle, '.files');
        $endPos = strlen($imgToHandle);
        $pdfLink = substr_replace($imgToHandle, '.pdf', $startPos, $endPos);
        $pdfContent = $this->gostHelper->getPage($pdfLink);
        return $pdfContent;
    }

    /**
     * Попробовать получить tr элементы
     * @param \DOMDocument $dom
     * @return \DOMNodeList
     * @throws Throwable getElementsByTagName on null
     */
    private function tryGetTableTrs($dom)
    {
        try {
            $table = $dom->getElementsByTagName('table')->item(0);
            $tableTbody = $table->getElementsByTagName('tbody')->item(0);
            $tableRows = $tableTbody->getElementsByTagName('tr');
        } catch (Throwable $e) {
            return false;
        }
        return $tableRows;
    }

    /**
     * Выполнить команду killall для Tor на сервере и запустить sleep на 60 секунд
     * @return void
     */
    private function execKillall()
    {
        $torExecStatus = system('killall -HUP tor');
        if ($torExecStatus === false) {
            Debug::writeToFile(date("Y-m-d H:i:s").' Не получилось перезапустить тор', '', $this->logDir);
        }
        sleep(60);
    }

    /**
     * Получить tr из table по ссылке
     * #декоратор
     * @param string $link Ссылка
     * @return \DOMNodeList
     */
    private function getTableTrs($link)
    {
        $domElements = $this->gostHelper->getDOMTree($this->gostHelper->getPage($link, $this->useTor));
        $trs = $this->tryGetTableTrs($domElements);

        while ($trs === false) {
            if ($this->useTor) {
                Debug::writeToFile(date("Y-m-d H:i:s").' Не получили необходимые элементы, рестартим тор', '', $this->logDir);
                $this->execKillall();
            } else {
                sleep($this->sleepTime);
            }
            $domElements = $this->gostHelper->getDOMTree($this->gostHelper->getPage($link, $this->useTor));
            $trs = $this->tryGetTableTrs($domElements);
        }

        return $trs;
    }

    /**
     * Обработать элементы ГОСТа пром инструмента
     * @param string $link Ссылка для обработки
     * @return array Массив элементов
     */
    private function handleProm($link)
    {
        Debug::writeToFile('Обрабатываем '.$link, '', $this->logDir);
        $rootUrl = 'https://xn--e1aflbecbhjekmek.xn--p1ai';
        $elementsTableTbodyRows = $this->getTableTrs($link);
        $elementsLink = [];
        foreach ($elementsTableTbodyRows as $elementRow) {
            $elementLink = $elementRow->getElementsByTagName('a')->item(0)->getAttribute('href');
            $elementLink = str_replace('http://проминструмент.рф', '', $elementLink);
            $elementsLink[] = $rootUrl . $elementLink;
        }
        $elements = [];
        foreach ($elementsLink as $elementLink) {
            Debug::writeToFile(date("Y-m-d H:i:s").' Обрабатываем '.$elementLink, '', $this->logDir);
            $elementTbodyRows = $this->getTableTrs($elementLink);
            $props = [];
            foreach ($elementTbodyRows as $elementTbodyRow) {
                $elementTbodyTds = $elementTbodyRow->getElementsByTagName('td');
                $propsTemp = [];
                foreach ($elementTbodyTds as $elementTbodyTd) {
                    $class = $elementTbodyTd->getAttribute('class');
                    $classes = explode(' ', $class);
                    switch ($classes[0]) {

                        case 'sectiontableheader':
                            if (strpos($elementTbodyTd->textContent, 'Эскиз') === false) {
                                $propsTemp['NAME'] = $elementTbodyTd->textContent;
                            }
                        break;

                        case 'sectiontablerow':
                            if ($elementTbodyTd->textContent != '') {
                                $propsTemp['VALUE'] = $elementTbodyTd->textContent;
                                break;
                            }
                            $imgs = $elementTbodyTd->getElementsByTagName('img');
                            foreach ($imgs as $img) {
                                $propsTemp['IMAGES'][] = $rootUrl.$img->getAttribute('src');
                            }
                        break;

                        default:
                        break;

                    }
                }
                if (!array_key_exists('VALUE', $propsTemp) && !array_key_exists('IMAGES', $propsTemp)) {
                    continue;
                }
                if (array_key_exists('IMAGES', $propsTemp)) {
                    $props['IMAGES'] = $propsTemp['IMAGES'];
                    continue;
                }
                $props[$propsTemp['NAME']] = $propsTemp['VALUE'];
            }
            if (empty($props)) {
                continue;
            }
            $elements[] = $props;
        }
        return $elements;
    }

    /**
     * Получить параметры элемента для вывода
     * @param string $elementName Имя элемента
     * @param string $sectionName Имя раздела элемента
     * @param array $arProps Массив свойств элемента
     * @return array
     */
    private function getElementParamsToOuput($elementName, $sectionName, $arProps)
    {
        $translitParams = [
            "max_len" => "100", // обрезает символьный код до 100 символов
            "change_case" => "L", // буквы преобразуются к нижнему регистру
            "replace_space" => "_", // меняем пробелы на нижнее подчеркивание
            "replace_other" => "_", // меняем левые символы на нижнее подчеркивание
            "delete_repeat_replace" => "true", // удаляем повторяющиеся нижние подчеркивания
            "use_google" => "false", // отключаем использование google
        ];

        $props = [];

        foreach ($arProps as $propName => $propValue) {
            if ($propName == 'IMAGES' || $propName == '' || $propValue == '') {
                continue;
            }

            $propName = trim($propName);

            $type = 'S';
            if (strripos('-', trim($propValue) === false) && !preg_match('/[a-zA-Zа-яёА-ЯЁ ]+/g', trim($propValue))) {
                $propValue = str_replace(',', '.', $propValue);
                $propValue = floatval($propValue);
                $type = 'N';
            };

            $propCode = \CUtil::translit($propName, "ru", $translitParams);

            $arFields = [
                "NAME" => $propName,
                "ACTIVE" => "Y",
                "SORT" => "500",
                "CODE" => $propCode,
                "PROPERTY_TYPE" => $type,
                'SMART_FILTER' => 'Y',
                "IBLOCK_ID" => $this->gostHelper->iblockID,
                'VALUE' => $propValue
            ];

            $props[] = $arFields;
        }

        unset($propName, $propValue);

        $elementCode = \CUtil::translit($elementName, "ru", $translitParams);

        $params = [
            'ACTIVE' => 'Y',
            'IBLOCK_ID' => 77,
            'NAME' => $elementName,
            'CODE' => $elementCode,
            'PROPERTY_VALUES' => $props,
            'IBLOCK_SECTION_NAME' => $sectionName,
        ];

        if (array_key_exists('IMAGES', $arProps)) {
            foreach ($arProps['IMAGES'] as $key => $path) {
                $path = str_replace('http://проминструмент.рф', '', $path);
                if ($key === 0) {
                    $params['PREVIEW_PICTURE'] = $path;
                    continue;
                }
                $params['PROPERTY_VALUES']['MORE_PHOTO'][] = $path;
            }
        }

        return $params;
    }
}
