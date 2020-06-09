<?php

namespace Enex\Core\Import;

use Bitrix\Main\Entity;

class BahcoTranslateNewTable extends Entity\DataManager
{
    public static function getTableName()
    {
        return 'citfact_bahco_translate_v2';
    }

    /**
     * @return array
     */
    public static function getMap()
    {
        return array(
            new Entity\IntegerField('id', array(
                'primary' => true,
                'autocomplete' => true,
            )),
            new Entity\StringField('english', array(
                'required' => true,
            )),
            new Entity\StringField('russian', array(
                'required' => true,
            )),
            new Entity\StringField('type', array(
                'required' => true,
            )),
            new Entity\StringField('value_type', array(
                'required' => true,
            )),
        );
    }
}
