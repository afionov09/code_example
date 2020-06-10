<?php

namespace Enex\Core\Import;

use Citfact\Sitecore\Import\FeedParsers\FeedParserInterface;

use Citfact\Sitecore\Import\ImportHelper;

use Citfact\Sitecore\Logger\TableLogger;
use Citfact\Sitecore\Logger\FeedsImporterDebugLogTable;

use Citfact\SiteCore\Tools\Files;

use Enex\Core\Import\ParserUni\Excel\ExcelParse;

class ParserUni implements FeedParserInterface
{

    //Пути к файлам фида
    private $arFilesPaths = array();

    private $feedId;

    private $manufacturerId;

    private $logger;

    private $errors = [];

    private $testMode = false;

    private $tempFolder;

    public function __construct()
    {
        echo 'Parser is created!' . "\n";
        $this->logger = new TableLogger(new FeedsImporterDebugLogTable());
        $this->tempFolder = $_SERVER['DOCUMENT_ROOT'] . '/upload/feeds_temp/';
    }

    /**
     * Считываем содержимое фида во временную таблицу
     * @param $feedId
     * @param $manufacturerId
     * @param $testMode
     * @return void
     * @throws \Bitrix\Main\ObjectException
     */
    public function parseFeedToTable($feedId, $manufacturerId, $testMode)
    {
        echo 'start parsing, feedId = ' . $feedId . "\n";
        \Bitrix\Main\Diag\Debug::startTimeLabel('import_' . $manufacturerId);

        $this->feedId = $feedId;
        $this->manufacturerId = $manufacturerId;
        $this->testMode = $testMode;

        // Получаем массив путей к файлам/файлу
        $this->handleFeedLoadType();

        if (empty($this->arFilesPaths)) {
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Не найдены файлы фида'];
            $this->logger->addToLog('parse feed', 'error', ['msg' => 'Пустой массив путей к файлам фида', 'feed_id' => $this->feedId]);
            return;
        }

        foreach ($this->arFilesPaths as $filePath => $fileType) {
            $this->parse_file($filePath, $fileType);
        }

        \Bitrix\Main\Diag\Debug::endTimeLabel('import_' . $this->manufacturerId);
        $arLabels = \Bitrix\Main\Diag\Debug::getTimeLabels();
        print_r('Времени потрачено: ' . $arLabels['import_' . $this->manufacturerId]['time'] . " сек \n");
    }

    /**
     * Парсим файл фида
     * @param string $filePath Путь к файлу фида
     * @param string $fileType Тип файла @var Xml @var Xlsx
     */
    private function parse_file($filePath, $fileType)
    {

        /**
         * Если xlsx - используется excel_parse
         * Если xml - используется xml_parse
         */
        switch ($fileType) {

            case 'Xlsx':
                $obj = new ExcelParse();
                $obj->run($this->manufacturerId, $this->feedId, $filePath, $this->testMode);
                $parseErrors = $obj->getErrors();
                if (!empty($parseErrors)) {
                    $this->errors = array_merge($this->errors, $parseErrors);
                }
                break;

            case 'Xml':
                //Пока не поддерживается
                //new XmlParse();
                break;

        }
    }

    /**
     * Определение метода получения фида
     * @throws \Bitrix\Main\ObjectException
     */
    private function handleFeedLoadType()
    {
        $arFeed = ImportHelper::getFeedInfo(( int )$this->feedId);

        switch ($arFeed['load_type']) {

            // Если фид - это файл, работаем с ним
            case 'file':
                $this->feedFromFile($arFeed);
                break;

            // Если фид - это ссылка на URL, то получаем инфу http-запросом
            case 'link':
                $this->feedFromUrl($arFeed);
                break;

        }
    }

    /**
     * Получение фида из файла
     */
    private function feedFromFile($feed)
    {
        $fileId = ( int )$feed['file_id'];

        if ($fileId <= 0) {
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Получен некорректный ID файла'];
            $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Некорректный ID файла', 'feed_id' => $this->feedId]);
            return;
        }

        $filePath = $_SERVER['DOCUMENT_ROOT'] . \CFile::GetPath($fileId);

        if (!file_exists($filePath)) {
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Получен путь до несуществующего файла'];
            $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Файл отсутствует', 'feed_id' => $this->feedId, 'file_id' => $fileId]);
            return;
        }

        $this->handleFileType($filePath);
    }

    /**
     * Получение фида из URL
     */
    private function feedFromUrl($feed)
    {
        $this->logger->addToLog('parser set files paths', '', ['msg' => 'Получаем файл по ссылке', 'link' => $feed['feed_url'], 'feed_id' => $this->feedId]);

        $username = $feed['link_login'];
        $password = $feed['link_password'];

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
            $this->logger->addToLog('parser set files paths', '', ['msg' => 'Ошибка получения файла', 'link' => $feed['feed_url'], 'feed_id' => $this->feedId]);
            $this->errors['Файл'][] = ['level' => 1, 'msg' => 'Ошибка получения файла - '. $feed['feed_url']];
            return;
        }

        if ($curlResponseCode === 401) {
            $this->errors['Файл'][] = ['level' => 1, 'msg' => 'Ошибка авторизации'];
            return;
        }

        $fileName = $this->manufacturerId . '_feed_file';
        $filePathFull = $this->tempFolder . $fileName;
        file_put_contents($filePathFull, $fileContent);

        $this->handleFileType($filePathFull);
    }

    /**
     * Распаковка архива ZIP
     * @param string $filePath Путь к файлу фида
     */
    private function unpackZip($filePath)
    {
        $zip = new \ZipArchive;
        $res = $zip->open($filePath);

        // Если фид - это архив, то распаковываем и возвращаем массив путей к файлам
        if ($res === true) {
            $arFilePath = explode('/', $filePath);

            $unzipPath = $this->tempFolder . end($arFilePath) . '_unzip/';
            $unzipSuccess = $zip->extractTo($unzipPath);

            if (!$unzipSuccess) {
                $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Ошибка распаковки архива', 'filePath' => $filePath]);
                $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Ошибка распаковки архива - '. end(explode('/', $filePath))];
                return;
            }

            $this->logger->addToLog('parser set files paths', '', ['msg' => 'Успешно распаковали архив', 'filePath' => $filePath]);

            $numFiles = $zip->numFiles;

            for ($i = 0; $i < $numFiles; $i++) {
                $arItem = $zip->statIndex($i);
                $unzipFilePath = $unzipPath . $arItem['name'];

                if (is_file($unzipFilePath)) {
                    $this->handleFileType($unzipFilePath);
                }
            }
        }
    }

    /**
     * Обработчик типа файла
     * @param string $filePath Путь к файлу фида
     */
    private function handleFileType($filePath)
    {
        $fileType = Files::getMimeType($filePath);

        switch ($fileType) {

            case 'application/zip':
                $this->logger->addToLog('parse feed', 'success', ['msg' => 'Определён архив - ' . $filePath, 'feed_id' => $this->feedId]);
                $this->unpackZip($filePath);
                break;

            case 'application/vnd.ms-excel':
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Формат XLS не поддерживается!', 'feed_id' => $this->feedId]);
                $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Формат XLS не поддерживается! - '. end(explode('/', $filePath))];
                break;

            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                $this->logger->addToLog('parse feed', 'success', ['msg' => 'Определён файл xlsx - ' . end(explode('/', $filePath)), 'feed_id' => $this->feedId]);
                $this->arFilesPaths[$filePath] = 'Xlsx';
                break;

            case 'application/xml':
                $this->logger->addToLog('parse feed', 'success', ['msg' => 'Определён файл xml - ' . $filePath, 'feed_id' => $this->feedId]);
                $this->arFilesPaths[$filePath] = 'Xml';
                break;

            case 'application/pdf':
                //Не обрабатывать как ошибку.
                break;

            case 'image/gif':
                //Не обрабатывать как ошибку.
                break;

            case 'image/jpeg':
                //Не обрабатывать как ошибку.
                break;

            case 'image/pjpeg':
                //Не обрабатывать как ошибку.
                break;

            case 'image/png':
                //Не обрабатывать как ошибку.
                break;

            case 'image/svg+xml':
                //Не обрабатывать как ошибку.
                break;

            case 'image/tiff':
                //Не обрабатывать как ошибку.
                break;

            case 'image/vnd.microsoft.icon':
                //Не обрабатывать как ошибку.
                break;

            case 'image/vnd.wap.wbmp':
                //Не обрабатывать как ошибку.
                break;

            case 'image/webp':
                //Не обрабатывать как ошибку.
                break;

            default:
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Тип файла не определен или не поддерживается - ' . $filePath, 'feed_id' => $this->feedId]);
                $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Тип файла не определен или не поддерживается - ' . end(explode('/', $filePath))];
                break;

        }
    }

    /**
     * @return array
     */
    public function getErrors()
    {
        return $this->errors;
    }
}
