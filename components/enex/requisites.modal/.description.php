<?php if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$arComponentDescription = array(
    'NAME' => Loc::getMessage('ENEX_REQUISITES_MODAL_NAME'),
    'DESCRIPTION' => Loc::getMessage('ENEX_REQUISITES_MODAL_DESCRIPTION'),
    'SORT' => 10,
    'CACHE_PATH' => 'Y',
    'PATH' => array(
        'ID' => 'enex',
        'NAME' => Loc::getMessage('ENEX_NAME'),
    ),
);
