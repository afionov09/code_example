<?php
namespace Enex\Core\CatalogHelper;

use Bitrix\Main\Diag\Debug;

/**
 * Класс для удаления пустых свойств
 */
class DeleteEmptyProps
{
    /**
     * ID инфоблока каталог товаров
     * @var int
     */    
    private $iblockID;

    /**
     * Core
     * @var \Citfact\SiteCore\Core
     */    
    private $core;

    public function __construct()
    {
        echo 'Start delete empty props' . "\n";
        $this->core = \Citfact\SiteCore\Core::getInstance();
        $this->iblockID = $this->core->getIblockId($this->core::IBLOCK_CODE_CATALOG_NEW);
    }

    /**
     * Удалить пустые свойства
     * @return void
     */    
    public function deleteEmptyProps()
    {
        Debug::writeToFile('Начинаем удаление пустых свойств.', date("H:i:s"), $this->core::LOG_DIR_PATH.__FUNCTION__.'.log');

        $arrPlatformProps = $this->getPlatformProps();
        if (empty($arrPlatformProps)) {
            Debug::writeToFile('Свойства с платформы не были получены!', '', $this->core::LOG_DIR_PATH.__FUNCTION__.'.log');
            return;
        }

        $countEmpty = 0;
        $countFilled = 0;
        $countEmptyDeleted = 0;
        $countEmptyNotDeleted = 0;

        $arExc = [
            'MINIMUM_PRICE',
            'MAXIMUM_PRICE',
            'ADDITIONAL_PROPERTIES',
            'RECOMENDED_ACTIVE_FROM',
            'RECOMENDED_ACTIVE_TO',
            'KHIT',
            'PHOTO',
            'NA_ZAKAZ',
            'MORE_PHOTO',
            'RECOMENDED',
            'MIN_ORDER',
            'SKIDKA',
            'NOVINKA',
            'PACKAGE_DIVISION',
            'NUMBER_IN_PACKAGE',
            'OBOZNACHENIYA_DLYA_TOVAROV'
        ];

        foreach ($arrPlatformProps as $id => $code) {
            if (in_array($code, $arExc)) {
                continue;
            }

            $arFilter = array(
                'IBLOCK_ID' => $this->iblockID,
                '!PROPERTY_'.$code => false
            );
        
            $goods = \CIBlockElement::GetList([], $arFilter, false, ['nTopCount' => 1]);
            $result = [];

            if ($ob = $goods->GetNext()) {
                $result = $ob['ID'];
            }

            if (empty($result)) {
                Debug::writeToFile(date("H:i:s") . ' - Найдено пустое свойство! CODE: '. $code, '', $this->core::LOG_DIR_PATH.__FUNCTION__.'.log');
                $countEmpty++;
                $delProp = \CIBlockProperty::Delete($id);
                if ($delProp) {
                    Debug::writeToFile(date("H:i:s") . ' - Успешно удалено! CODE: '. $code, '', $this->core::LOG_DIR_PATH.__FUNCTION__.'.log');
                    $countEmptyDeleted++;
                } else {
                    Debug::writeToFile(date("H:i:s") . ' - Ошибка удаления! CODE: '. $code, '', $this->core::LOG_DIR_PATH.__FUNCTION__.'.log');
                    $countEmptyNotDeleted++;
                }
                continue;
            }
            Debug::writeToFile(date("H:i:s") . ' Есть товар с заполненным свойством : '. $code, '', $this->core::LOG_DIR_PATH.__FUNCTION__.'.log');
            $countFilled++;
        }
        echo date("H:i:s") . ' - Заполненных свойств : '. $countFilled . ' | Пустых свойств : '. $countEmpty . ' Из них удалено успешно : '.$countEmptyDeleted. ' Не удалено : '. $countEmptyNotDeleted;
        Debug::writeToFile(date("H:i:s") . ' - Заполненных свойств : '. $countFilled . ' | Пустых свойств : '. $countEmpty . ' Из них удалено успешно : '.$countEmptyDeleted. ' Не удалено : '. $countEmptyNotDeleted, '', $this->core::LOG_DIR_PATH.__FUNCTION__.'.log');
    }

    /**
     * Получить свойства с платформы
     * @return array массив свойств ID => code
     */    
    private function getPlatformProps()
    {
        $rsProps = \CIBlockProperty::GetList(
            array( 'ID'   => 'ASC' ),
            array( 'IBLOCK_ID' => $this->iblockID )
        );

        $platformProps = [] ;

        while ($arProp = $rsProps->Fetch()) {
            $platformProps[$arProp['ID']] = trim($arProp['CODE']);
        }

        return $platformProps;
    }
}
