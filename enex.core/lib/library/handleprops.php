<?php
namespace Enex\Core\Library;

class HandleProps
{
    private $core;

    const GOST_PROPS = 2;

    const CATALOG_PROPS = 50;

    const CERTS_PROPS = 50;

    const PROPS = [
        'gosts' => [
            [
                'NAME' => 'Файл ГОСТ',
                'CODE' => 'GOST_FILE',
                'TYPE' => 'F',
                'NEED_ENUM' => 'Y'
            ],
            [
                'NAME' => 'Название ГОСТ',
                'CODE' => 'GOST_NAME',
                'TYPE' => 'S',
                'NEED_ENUM' => 'Y'
            ]
        ],
        'catalogs' => [
            [
                'NAME' => 'Файл каталога',
                'CODE' => 'CATALOG_FILE',
                'TYPE' => 'F',
                'NEED_ENUM' => 'Y'
            ],
            [
                'NAME' => 'Название каталога',
                'CODE' => 'CATALOG_NAME',
                'TYPE' => 'S',
                'NEED_ENUM' => 'Y'
            ],
            [
                'NAME' => 'Алиасы',
                'CODE' => 'ALIASES',
                'TYPE' => 'S',
                'ONLY_ONE' => 'Y'
            ]
        ],
        'certificates' => [
            [
                'NAME' => 'Файл сертификата',
                'CODE' => 'CERTIFICATE_FILE',
                'TYPE' => 'F',
                'NEED_ENUM' => 'Y'
            ],
            [
                'NAME' => 'Название сертификата',
                'CODE' => 'CERTIFICATE_NAME',
                'TYPE' => 'S',
                'NEED_ENUM' => 'Y'
            ],
        ]
    ];

    public function __construct()
    {
        $this->core = \Citfact\SiteCore\Core::getInstance();
    }

    public function run($block, $needOnlyClear = false)
    {
        switch ($block) {
            case 'gosts':
                $iblockID = $this->core->getIblockId($this->core::IBLOCK_CODE_LIBRARY_GOST);
                $propsCount = self::GOST_PROPS;
                break;

            case 'catalogs':
                $iblockID = $this->core->getIblockId($this->core::IBLOCK_CODE_LIBRARY_CATALOGS);
                $propsCount = self::CATALOG_PROPS;
                break;

            case 'certificates':
                $iblockID = $this->core->getIblockId($this->core::IBLOCK_CODE_LIBRARY_CERTIFICATES);
                $propsCount = self::CERTS_PROPS;
                break;

            default:
                print_r('Неопознанный режим'.PHP_EOL);
                return;
        }

        if ($needOnlyClear) {
            $this->clearPropsByIBlockID($iblockID);
            return;
        }

        $this->clearPropsByIBlockID($iblockID);
        $onlyOnePropAdded = [];
        for ($i = 1; $i <= $propsCount; $i++) {
            foreach (self::PROPS[$block] as $prop) {
                if ($prop['ONLY_ONE'] && in_array($prop['NAME'], $onlyOnePropAdded)) {
                    continue;
                }
                $prop['ID'] = $i;
                $this->addProp($iblockID, $prop);
                if ($prop['ONLY_ONE']) {
                    $onlyOnePropAdded[] = $prop['NAME'];
                }
            }
        }
    }

    private function clearPropsByIBlockID($iblockID)
    {
        $arFilter = [
            'IBLOCK_ID' => $iblockID,
        ];
        $rs = \CIBlockProperty::GetList([], $arFilter);
        while ($ob = $rs->GetNext()) {
            \CIBlockProperty::Delete($ob['ID']);
        }
    }

    private function getPropEnumByIBlockID($iblockID)
    {
        $arFilter = [
            'IBLOCK_ID' => $iblockID,
        ];

        $rs = \CIBlockProperty::GetList([], $arFilter);
        while ($ob = $rs->GetNext()) {
            echo $ob['NAME'].PHP_EOL;
        }
    }

    private function addProp($iblockID, $prop)
    {
        $params = [
            "NAME" => $prop['NAME'].(($prop['NEED_ENUM'])?' '.$prop['ID']:''),
            "ACTIVE" => "Y",
            "SORT" => "500",
            "CODE" => $prop['CODE'].(($prop['NEED_ENUM'])?'_'.$prop['ID']:''),
            "PROPERTY_TYPE" => $prop['TYPE'],
            "IBLOCK_ID" => $iblockID
        ];

        if ($prop['USER_TYPE']) {
            $params['USER_TYPE'] = $prop['USER_TYPE'];
        }

        $ibp = new \CIBlockProperty;
        $propID = $ibp->Add($params);
        if (!$propID) {
            echo $ibp->LAST_ERROR.PHP_EOL;
        }
    }
}
