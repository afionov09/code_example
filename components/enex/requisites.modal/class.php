<?php if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Citfact\SiteCore\User\UserRepository;

class RequisitesModalComponent extends \CBitrixComponent
{
    public $siteId;

    /**
     * {@inheritdoc}
     */
    public function executeComponent()
    {
        $authorId = $this->arParams['AUTHOR_ID'];
        $this->arResult = [];
        $this->siteId = \Bitrix\Main\Context::getCurrent()->getSite();

        $this->arResult['REQUISITES'] = UserRepository::getRequisitesByUserId($authorId);
        $this->arResult['PHONE_HREF'] = '';

        $this->includeComponentTemplate();
    }
}
