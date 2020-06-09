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
    private $ar_files_paths = array();

    private $feed_ID;

    private $manufacturer_ID;

    private $logger;

    private $errors = [];

    private $test_mode = false;

    private $temp_folder;

    public function __construct()
    {
        echo 'Parser is created!' . "\n";
        $this->logger = new TableLogger(new FeedsImporterDebugLogTable());
        $this->temp_folder = $_SERVER['DOCUMENT_ROOT'] . '/upload/feeds_temp/';
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

        $this->feed_ID = $feedId;
        $this->manufacturer_ID = $manufacturerId;
        $this->test_mode = $testMode;

        // Получаем массив путей к файлам/файлу
        $this->handle_feed_load_type();

        if (empty($this->ar_files_paths)) {
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Не найдены файлы фида'];
            $this->logger->addToLog('parse feed', 'error', ['msg' => 'Пустой массив путей к файлам фида', 'feed_id' => $this->feed_ID]);
            return;
        }

        foreach ($this->ar_files_paths as $file_path => $file_type) {
            $this->parse_file($file_path, $file_type);
        }

        \Bitrix\Main\Diag\Debug::endTimeLabel('import_' . $this->manufacturer_ID);
        $ar_labels = \Bitrix\Main\Diag\Debug::getTimeLabels();
        print_r('Времени потрачено: ' . $ar_labels['import_' . $this->manufacturer_ID]['time'] . " сек \n");
    }

    /**
     * Парсим файл фида
     * @param string $file_path Путь к файлу фида
     * @param string $file_type Тип файла @var Xml @var Xlsx
     */
    private function parse_file($file_path, $file_type)
    {

        /**
         * Если xlsx - используется excel_parse
         * Если xml - используется xml_parse
         */
        switch ($file_type) {

            case 'Xlsx':
                $obj = new ExcelParse();
                $obj->run($this->manufacturer_ID, $this->feed_ID, $file_path, $this->test_mode);
                $parse_errors = $obj->getErrors();
                if (!empty($parse_errors)) {
                    $this->errors = array_merge($this->errors, $parse_errors);
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
    private function handle_feed_load_type()
    {
        $ar_feed = ImportHelper::getFeedInfo(( int )$this->feed_ID);

        switch ($ar_feed['load_type']) {

            // Если фид - это файл, работаем с ним
            case 'file':
                $this->feed_from_file($ar_feed);
                break;

            // Если фид - это ссылка на URL, то получаем инфу http-запросом
            case 'link':
                $this->feed_from_url($ar_feed);
                break;

        }
    }

    /**
     * Получение фида из файла
     */
    private function feed_from_file($feed)
    {
        $file_ID = ( int )$feed['file_id'];

        if ($file_ID <= 0) {
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Получен некорректный ID файла'];
            $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Некорректный ID файла', 'feed_id' => $this->feed_ID]);
            return;
        }

        $file_path = $_SERVER['DOCUMENT_ROOT'] . \CFile::GetPath($file_ID);

        if (!file_exists($file_path)) {
            $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Получен путь до несуществующего файла'];
            $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Файл отсутствует', 'feed_id' => $this->feed_ID, 'file_id' => $file_ID]);
            return;
        }

        $this->handle_file_type($file_path);
    }

    /**
     * Получение фида из URL
     */
    private function feed_from_url($feed)
    {
        $this->logger->addToLog('parser set files paths', '', ['msg' => 'Получаем файл по ссылке', 'link' => $feed['feed_url'], 'feed_id' => $this->feed_ID]);

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

        $file_content = curl_exec($curl);
        $curl_response_code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (!$file_content) {
            $this->logger->addToLog('parser set files paths', '', ['msg' => 'Ошибка получения файла', 'link' => $feed['feed_url'], 'feed_id' => $this->feed_ID]);
            $this->errors['Файл'][] = ['level' => 1, 'msg' => 'Ошибка получения файла - '. $feed['feed_url']];
            return;
        }

        if ($curl_response_code === 401) {
            $this->errors['Файл'][] = ['level' => 1, 'msg' => 'Ошибка авторизации'];
            return;
        }

        $file_name = $this->manufacturer_ID . '_feed_file';
        $file_path_full = $this->temp_folder . $file_name;
        file_put_contents($file_path_full, $file_content);

        $this->handle_file_type($file_path_full);
    }

    /**
     * Распаковка архива ZIP
     * @param string $file_path Путь к файлу фида
     */
    private function unpack_zip($file_path)
    {
        $zip = new \ZipArchive;
        $res = $zip->open($file_path);

        // Если фид - это архив, то распаковываем и возвращаем массив путей к файлам
        if ($res === true) {
            $ar_file_path = explode('/', $file_path);

            $unzip_path = $this->temp_folder . end($ar_file_path) . '_unzip/';
            $unzip_success = $zip->extractTo($unzip_path);

            if (!$unzip_success) {
                $this->logger->addToLog('parser set files paths', 'error', ['msg' => 'Ошибка распаковки архива', 'filePath' => $file_path]);
                $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Ошибка распаковки архива - '. end(explode('/', $file_path))];
                return;
            }

            $this->logger->addToLog('parser set files paths', '', ['msg' => 'Успешно распаковали архив', 'filePath' => $file_path]);

            $num_files = $zip->numFiles;

            for ($i = 0; $i < $num_files; $i++) {
                $ar_item = $zip->statIndex($i);
                $unzip_file_path = $unzip_path . $ar_item['name'];

                if (is_file($unzip_file_path)) {
                    $this->handle_file_type($unzip_file_path);
                }
            }
        }
    }

    /**
     * Обработчик типа файла
     * @param string $file_path Путь к файлу фида
     */
    private function handle_file_type($file_path)
    {
        $file_type = Files::getMimeType($file_path);

        switch ($file_type) {

            case 'application/zip':
                $this->logger->addToLog('parse feed', 'success', ['msg' => 'Определён архив - ' . $file_path, 'feed_id' => $this->feed_ID]);
                $this->unpack_zip($file_path);
                break;

            case 'application/vnd.ms-excel':
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Формат XLS не поддерживается!', 'feed_id' => $this->feed_ID]);
                $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Формат XLS не поддерживается! - '. end(explode('/', $file_path))];
                break;

            case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
                $this->logger->addToLog('parse feed', 'success', ['msg' => 'Определён файл xlsx - ' . end(explode('/', $file_path)), 'feed_id' => $this->feed_ID]);
                $this->ar_files_paths[$file_path] = 'Xlsx';
                break;

            case 'application/xml':
                $this->logger->addToLog('parse feed', 'success', ['msg' => 'Определён файл xml - ' . $file_path, 'feed_id' => $this->feed_ID]);
                $this->ar_files_paths[$file_path] = 'Xml';
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
                $this->logger->addToLog('parse feed', 'error', ['msg' => 'Тип файла не определен или не поддерживается - ' . $file_path, 'feed_id' => $this->feed_ID]);
                $this->errors['Парсер'][] = ['level' => 1, 'msg' => 'Тип файла не определен или не поддерживается - ' . end(explode('/', $file_path))];
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
