<?php if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) {
    die();
}

use Citfact\SiteCore\Tools\DataAlteration;

foreach ($arResult['REQUISITES'] as $key => $val) {
    if ($key === 'PHONE') {
        $arResult['REQUISITES'][$key] = DataAlteration::getStyledPhone($val);
        $arResult['PHONE_HREF'] = DataAlteration::clearPhone($val);
    }
    if ($key === 'EMAIL') {
        $arResult['REQUISITES'][$key] = DataAlteration::validateEmail($val);
    }
}
