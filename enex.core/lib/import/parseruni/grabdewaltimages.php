<?php

namespace Enex\Core\Import\ParserUni;

use Citfact\Sitecore\Logger\TableLogger;
use Citfact\Sitecore\Logger\FeedsImporterDebugLogTable;

/**
 * DeWalt images grabber
 * Todo перенести под интерфейс
 */
class GrabDeWaltImages
{
    /**
     * URL root
     * @var string
     */
    private $path = 'https://toolbank.brandquad.ru/sbd/33f4568668c71fbbb98f352ec7e02031/';

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
            $this->logger->addToLog('feed images', 'error', ['msg' => 'Страница не была получена для article : ' . $article]);
            return;
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($content);
        libxml_use_internal_errors(false);
        
        $tags = $dom->getElementsByTagName('div');
        $imagesArray = array();

        foreach ($tags as $tag) {
            if ($tag->getAttribute('class') === 'assetitem' &&
                (
                    $tag->getAttribute('data-mimetype') === 'image/jpeg' ||
                    $tag->getAttribute('data-mimetype') === 'image/png'
                ) &&
                 $tag->getAttribute('data-link') !== 'https://storage.yandexcloud.net/accounts-media/SBD/DAM/origin/9d90c374-d37b-11e8-a035-0242c0a81004.png' &&
                 !array_search($tag->getAttribute('data-link'), $imagesArray)
               ) {
                $imagesArray[] = $tag->getAttribute('data-link');
            }
        }

        return $imagesArray;
    }
}
