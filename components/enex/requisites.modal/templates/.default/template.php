<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}
/** @var array $arParams */
/** @var array $arResult */
/** @global CMain $APPLICATION */
/** @global CUser $USER */
/** @global CDatabase $DB */
/** @var CBitrixComponentTemplate $this */
/** @var string $templateName */
/** @var string $templateFile */
/** @var string $templateFolder */
/** @var string $componentPath */
/** @var OrderCheckoutComponent $component */

use Bitrix\Main\Localization\Loc;
$this->setFrameMode(false);
?>

<div id="cond-mfr" class="b-modal b-modal--text-document-reqs">
    <div class="plus plus--cross b-modal__close" data-modal-close></div>
    <div class="b-modal__title"><?= Loc::getMessage('TITLE'); ?></div>
    <div class="b-modal__content b-static">
        <div class="requisites-modal">
            <div class="b-c-list">
                <? foreach ($arResult['REQUISITES'] as $reqName => $reqVal) { ?>
                    <? if ($reqVal) { ?>
                        <div class="b-c-list__line">
                            <div class="b-c-list__title">
                                <?= Loc::getMessage($reqName); ?>:
                            </div>
                            <div class="b-c-list__value">
                                <?switch ($reqName) {

                                    case 'EMAIL': ?>
                                        <a href="mailto:<?=$reqVal?>">
                                            <?=$reqVal?>
                                        </a>
                                    <?break;

                                    case 'PHONE': ?>
                                        <a href="tel:<?=$arResult['PHONE_HREF']?>">
                                            <?=$reqVal?>
                                        </a>
                                    <?break;

                                    default: ?>
                                        <?=$reqVal?>
                                    <?break;

                                }?>
                            </div>
                        </div>
                    <? } ?>
                <? } ?>
            </div>
        </div>
    </div>
</div>

