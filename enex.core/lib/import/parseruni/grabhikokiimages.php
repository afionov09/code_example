<?php

namespace Enex\Core\Import\ParserUni;

use Citfact\Sitecore\Logger\TableLogger;
use Citfact\Sitecore\Logger\FeedsImporterDebugLogTable;

/**
 * Hikoki images grabber
 * Todo перенести под интерфейс
 */
class GrabHikokiImages
{
    /**
     * URL root
     * @var string
     */
    private $path = 'http://download.hikoki.ru/NoPasswd/Accessories_photo/';

    public function __construct()
    {
        $this->logger = new TableLogger(new FeedsImporterDebugLogTable());
    }

    /**
     * Инициировать сбор
     * @param string $article артикул элемента
     * @return array массив картинок
     */
    public function run($article)
    {
        $html = $this->path . $article . '/';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $html);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $content = curl_exec($ch);
        curl_close($ch);

        if (empty($content)) {
            $this->logger->addToLog('feed images', 'error', ['msg' => 'Страница не была получена для article=' . $article]);
            return;
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        libxml_use_internal_errors(false);
        
        $tags = $dom->getElementsByTagName('a');

        $imagesArray = array();

        foreach ($tags as $tag) {
            if (
                 !array_search($tag->getAttribute('href'), $imagesArray) && (strripos($tag->getAttribute('href'), '.png') !== false || strripos($tag->getAttribute('href'), '.jpg') !== false)
               ) {
                $imagesArray[] = $this->path.$article.'/'.$tag->getAttribute('href');
            }
        }

        return $imagesArray;
    }
}
