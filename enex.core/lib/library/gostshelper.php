<?php
namespace Enex\Core\Library;

class GostsHelper
{
    /**
     * DOCUMENT_ROOT
     * @var string
     */
    protected $docRoot;

    /**
     * ID инфоблока ГОСТ
     * @var int
     */
    protected $iblockID;

    /**
     * Конструктор
     * @param bool $getGosts используется ли класс в получении гостов
     */
    protected function __construct($getGosts = false)
    {
        if ($getGosts) {
            $this->preparatoryWork();
        }
        $core = \Citfact\SiteCore\Core::getInstance();
        $this->iblockID = (int)$core->getIblockId($core::IBLOCK_CODE_LIBRARY_GOST);
        $this->docRoot = str_replace('/local/modules/enex.core/lib/library', '', __DIR__);
        unset($core);
    }

    /**
     * Подготовительные работы, если получаем госты
     * @return void
     */
    private function preparatoryWork()
    {
        $checkDir = $this->checkDir();
        if ($checkDir === false) {
            die('Ошибка создания директории для файлов библиотеки.');
        }
        $arFields = array(
            "ENTITY_ID" => "IBLOCK_".$this->iblockID."_SECTION",
            "FIELD_NAME" => "UF_FILE_PDF",
            "USER_TYPE_ID" => "file",
        );
        $uf = true;
        if (!$this->checkUserField($arFields)) {
            $uf = $this->addUserField($arFields);
        }
        if (!$uf) {
            die('Ошибка создания свойства у раздела.');
        }
    }

    /**
     * Получить контент страницы
     * #декоратор
     * @param string $link
     * @param bool $useTor
     * @return string
     */
    protected function getPage($link, $useTor = false)
    {
        $timeOut = 15;
        $realContent = false;

        while ($realContent === false) {
            $realContent = $this->tryGetPage($link, $timeOut, $useTor);
            if ($timeOut >= 45) {
                break;
            }
            $timeOut += 15;
        }

        return $realContent;
    }

    /**
     * Получить массив для обработки
     * @return array
     */
    protected function getParseMap()
    {
        $json = \Bitrix\Main\IO\File::getFileContents(__DIR__. '/jsons/parse_map.json');
        if (!$json) {
            die('Файл parse_map не был получен.');
        }
        $parseMap = json_decode($json, true);
        unset($json);
        return $parseMap;
    }

    /**
     * Получить массив с путями файлов ГОСТ
     * @return array
     */
    protected function getInpoGostsMap()
    {
        $jsonGosts = \Bitrix\Main\IO\File::getFileContents(__DIR__. '/jsons/inpo_gosts.json');
        if (!$jsonGosts) {
            return false;
        }
        $inpoGOSTs = json_decode($jsonGosts, true);
        unset($jsonGosts);
        return $inpoGOSTs;
    }

    /**
     * Получить контент страницы.
     * @param string $link
     * @return array|bool
     */
    private function tryGetPage($link, $timeout, $useTor)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $link);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        if ($useTor) {
            curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:9050');
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
        $content = curl_exec($ch);
        curl_close($ch);

        return $content;
    }

    /**
     * Сохранить картинку по url
     * @param string $url ссылка
     * @param string $subSection имя раздела для директории
     * @return array информация по файлу
     */
    protected function saveImageByUrl($url, $subSection)
    {
        $content = $this->getPage($url);
        $typePrep = explode('.', $url);
        $typeImg = end($typePrep);
        $imgPrep = explode('/', $typePrep[count($typePrep)-2]);
        $imageName = end($imgPrep);
        $imgSrc = $this->saveFile($content, $imageName, $typeImg, 'images', $subSection);
        unset($content);
        $file = \CFile::MakeFileArray($imgSrc);
        return $file;
    }

    /**
     * Создать массив путей файлов ГОСТ инпо
     * @return void
     */
    protected function createInpoGostsMap()
    {
        $libraryUrl = 'https://www.inpo.ru/library/';
        $mainPage = $this->getPage($libraryUrl);
        $mainLinks = $this->getLinkToGOSTCategories($this->getDOMTree($mainPage));
        $linksToGosts = [];

        foreach ($mainLinks as $mainInfo) {
            $page = $this->getPage($libraryUrl.$mainInfo['LINK']);
            $linksToGosts[$mainInfo['NAME']] = $this->getLinksToGostsPage($this->getDOMTree($page));
        }

        $arFiles = [];

        foreach ($linksToGosts as $categoryName => $nested) {
            foreach ($nested as $link_info) {
                $page = $this->getPage($link_info['LINK']);
                $linkToFile = $this->getLinkToGostFileInpo($link_info['LINK'], $this->getDOMTree($page));
                $fileContent = $this->getPage($linkToFile);
                $tempGOST['PATH'] = $this->saveFile($fileContent, $link_info['NAME'], 'pdf', $categoryName);
                if ($tempGOST['PATH'] === false) {
                    continue;
                }
                $tempGOST['NAME'] = $link_info['NAME'];
                $temp_gost = explode(' ', $link_info['NAME']);
                $tempGOST['GOST'] = end($temp_gost);
                $arFiles[] = $tempGOST;
            }
        }

        $inpoJson = json_encode($arFiles, JSON_UNESCAPED_UNICODE);
        file_put_contents(__DIR__. '/jsons/inpo_gosts.json', $inpoJson);
        unset($inpoGOSTs, $inpoJson, $arFiles);
    }

    /**
     * Проверить наличие файла пром для импорта
     * @param string $name имя файла
     * @return bool
     */
    protected function checkFileProm($name)
    {
        $dir = $this->docRoot.'/local/var/jsons/'.$name.'.json';
        return file_exists($dir);
    }

    /**
     * Проверить наличие директории docRoot/upload/library/
     * @param string $rootName Название для вложенной папки 1 уровня
     * @param string $subName Название для вложенной папки 2 уровня
     * @return bool|string
     */
    private function checkDir($rootName = '', $subName='')
    {
        $rootNameReady = '';
        $subNameReady = '';
        if ($rootName) {
            $rootNameReady = $this->handleName($rootName, true);
        }
        if ($subName) {
            $subNameReady = $this->handleName($subName, true);
        }
        $path = $this->docRoot.'/upload/library/'.($rootNameReady?$rootNameReady.'/':'').($subNameReady?$subNameReady.'/':'');
        if (!file_exists($path)) {
            $status = mkdir($path, 0755, true);
        }
        if ($status === false) {
            return false;
        }
        if ($rootName || $subName) {
            return $rootNameReady.'/'.($subNameReady?$subNameReady.'/':'');
        }
        return true;
    }

    /**
     * Сократить название ГОСТа.
     * @param string $name
     * Название ГОСТа.
     * @return string
     */
    protected function handleName($name, $isDirName = false)
    {
        $arName = explode(' ', $name);
        $newArName = [];
        foreach ($arName as $namePart) {
            $newArName[] = substr($namePart, 0, 3);
        }
        $name = implode(' ', $newArName);
        $maxLen = '';
        if ($isDirName) {
            $maxLen = '15';
        } else {
            $maxLen = '20';
        }
        $params = [
            "max_len" => $maxLen, // обрезает символьный код до 100 символов
            "change_case" => "L", // буквы преобразуются к нижнему регистру
            "replace_space" => "_", // меняем пробелы на нижнее подчеркивание
            "replace_other" => "_", // меняем левые символы на нижнее подчеркивание
            "delete_repeat_replace" => "true", // удаляем повторяющиеся нижние подчеркивания
            "use_google" => "false", // отключаем использование google
        ];
        $handled_name = \CUtil::translit($name, "ru", $params);
        return $handled_name;
    }

    /**
     * Сохранить файл
     * @param string $content Контент файла
     * @param string $fileName Название файла
     * @param string $type Расширение файла
     * @param string $rootName Название для вложенной папки 1 уровня
     * @param string $subName Название для вложенной папки 2 уровня
     * @return bool|string
     */
    protected function saveFile($content, $fileName, $type, $rootName, $subName = '')
    {
        $dir = $this->checkDir($rootName, $subName);
        if ($dir === false) {
            return false;
        }

        $translitName =  $this->handleName($fileName);

        $filePath = $this->docRoot.'/upload/library/' . $dir . $translitName.'.'.$type;

        file_put_contents($filePath, $content);

        unset($content, $fileName, $rootName, $subName, $translitName, $dir);

        if (file_exists($filePath)) {
            return $filePath;
        }
        return false;
    }

    /**
     * Получить ссылки на документы ГОСТ
     * @param DOMDocument $dom
     * @return array
     */
    private function getLinksToGostsPage($dom)
    {
        $libraryUrl = 'https://www.inpo.ru/library/';
        $ulTags = $dom->getElementsByTagName('ul');
        $ulInnerTags = $ulTags[1]->childNodes;
        $result = [];

        foreach ($ulInnerTags as $ulInnerTag) {
            if ($ulInnerTag->nodeType == 1) {
                $a = $ulInnerTag->getElementsByTagName('a');
                $result_temp['NAME'] = $a[0]->nodeValue;
                $result_temp['LINK'] = $libraryUrl.'GOST/'.$a[0]->getAttribute('href');
                $result[] = $result_temp;
            }
        }

        return $result;
    }

    /**
     * Получить ссылки на категории ГОСТов - инпо
     * @param DOMDocument $dom
     * @return array
     */
    private function getLinkToGOSTCategories($dom)
    {
        $arElementsInpo = [
            'Инструмент абразивный',
            'Инструмент алмазный',
            'Инструмент измерительный',
            'Инструмент кузнечный',
            'Инструмент режущий',
            'Инструмент слесарно-монтажный',
            'Инструмент строительный',
            'Оснастка и приспособления',
            'Станки'
        ];

        $tags = $dom->getElementsByTagName('a');
        $result = [];

        foreach ($tags as $tag) {
            if (in_array($tag->nodeValue, $arElementsInpo)) {
                $result_temp['NAME'] = $tag->nodeValue;
                $result_temp['LINK'] = $tag->getAttribute('href');
                $result[] = $result_temp;
            }
        }

        unset($tag);

        return $result;
    }

    /**
     * Проверить наличие раздела
     * @param string $name имя раздела
     * @param string $rootCode ID родительского раздела
     * @return bool|int
     */
    protected function checkSection($name, $rootCode = '')
    {
        $arFilter = [
            'NAME' => $name,
            'SECTION_ID' => ($rootCode ? $rootCode : false),
            'IBLOCK_ID' => $this->iblockID
        ];
        $rs = \CIBlockSection::GetList([], $arFilter, false, ['ID']);
        $sectionCode = false;
        if ($ob = $rs->GetNext()) {
            $sectionCode = $ob['ID'];
        }
        return $sectionCode;
    }

    /**
     * Проверить на валидность символьный код раздела
     * @param string $code символьный код раздела
     * @return string валидный символьный код
     */
    private function checkSectionCode($code)
    {
        $arCodes = array();
        $rsCodeLike = \CIBlockSection::GetList(array(), array(
            "IBLOCK_ID" => $this->iblockID,
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
        } else {
            return $code;
        }
    }

    /**
     * Добавить раздел по имени
     * @param string $name имя раздела
     * @param string $rootCode ID родительского раздела
     * @return array|int
     */
    protected function addSectionByName($name, $rootCode = '')
    {
        $obSection = new \CIBlockSection();
        $sectionCode = $this->checkSectionCode(\Cutil::translit($name, "ru", ["replace_space"=>"_","replace_other"=>"_"]));
        $arFields = array(
            "ACTIVE" => 'Y',
            "IBLOCK_ID" => $this->iblockID,
            "NAME" => $name,
            "CODE" => $sectionCode,
            "XML_ID" => $sectionCode,
            "SORT" => '500',
        );
        if ($rootCode) {
            $arFields["IBLOCK_SECTION_ID"] = $rootCode;
        }
        $createdSectionId = $obSection->Add($arFields);
        if ($createdSectionId === false) {
            return [
                                    'ERROR' => $obSection->LAST_ERROR
                                ];
        }
        return $createdSectionId;
    }

    /**
     * Проверить наличие доп поля разделов
     * @param array $ufFields доп поле
     * @return bool|string
     */
    private function checkUserField($ufFields)
    {
        $obUserField  = new \CUserTypeEntity;
        $rs = $obUserField->GetList([], $ufFields);
        $id = '';
        if ($ob = $rs->GetNext()) {
            $id = $ob['ID'];
        }
        return $id;
    }
    
    /**
     * Добавить доп поле разделам
     * @param array $ufFields параметры свойства
     * @return bool|int
     */
    private function addUserField($ufFields)
    {
        $ufFields['EDIT_FORM_LABEL'] = array("ru"=>"ГОСТ файл", "en"=>"GOST file");
        $obUserField  = new \CUserTypeEntity;
        $id = $obUserField->Add($ufFields);
        return $id;
    }

    /**
     * Получить DOM дерево
     * @param string $page страница
     * @return DOMDocument
     */
    protected function getDOMTree($page)
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $page);
        libxml_use_internal_errors(false);
        return $dom;
    }

    /**
     * Получить ссылку на файлы ГОСТов инпо
     * @param string $link
     * @param \DOMDocument $dom
     * @return string
     */
    private function getLinkToGostFileInpo($link, $dom)
    {
        $tags = $dom->getElementsByTagName('a');
        $word = explode('/', $link);
        $word = $word[sizeof($word)-2];
        $final_link = '';
        foreach ($tags as $tag) {
            if (strripos($tag->nodeValue, $word)) {
                $final_link = $link.$tag->getAttribute('href');
                break;
            }
        }
        return $final_link;
    }
}
