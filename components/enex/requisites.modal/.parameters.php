<?php if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$arComponentParameters = array(
    'PARAMETERS' => array(
        'PARAM' => array(
            'PARENT' => 'BASE',
            'NAME' => Loc::getMessage('PARAM'),
            'TYPE' => 'LIST',
            'ADDITIONAL_VALUES' => 'Y',
            'VALUES' => '',
            'REFRESH' => 'Y',
        ),
    )
);
