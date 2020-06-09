<?php
namespace Enex\Core;

class Core
{
    // iBlock constants
    const IBLOCK_CODE_LIBRARY = 'library';
    const IBLOCK_CODE_LIBRARY_CATALOGS = 'library_catalogs';
    const IBLOCK_CODE_LIBRARY_GOST = 'library_gost_new';
    const IBLOCK_CODE_LIBRARY_TOOL_MARKING = 'library_tool_marking';
    const IBLOCK_CODE_LIBRARY_RECOMMENDATIONS = 'library_recommendations';
    const IBLOCK_CODE_LIBRARY_MARKET_REVIEWS = 'library_market_reviews';
    const IBLOCK_CODE_LIBRARY_CERTIFICATES = 'library_certificates';
    const IBLOCK_CODE_LIBRARY_LEGAL_DOCS = 'library_legal_docs';
    const IBLOCK_CODE_LIBRARY_USEFUL_LINKS = 'library_useful_links';
    
    // HLBlock constants
    const HLBLOCK_CODE_PARSER_PARAMS = 'ParserParams';

    /**
     * @var Core The reference to *Singleton* instance of this class
     */
    protected static $instance;

    /**
     * Returns the *Core* instance of this class.
     *
     * @return Core The *Core* instance.
     */
    public static function getInstance()
    {
        if (null === static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    protected function __construct()
    {
    }
}
