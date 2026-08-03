<?php
/**
 * ObjectModel pour les profils de génération de catalogue PDF.
 *
 * @author Créa2média
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CustomCatalogProfile extends ObjectModel
{
    /** @var string Nom interne du profil */
    public $name;

    /** @var string Titre affiché sur la couverture */
    public $title;

    /** @var string JSON-encoded array d'id_manufacturer */
    public $id_manufacturers;

    /** @var int Groupe client pour le calcul de prix */
    public $id_group;

    /** @var int Client spécifique pour le calcul de prix */
    public $id_customer;

    /** @var bool Afficher les prix dans le catalogue */
    public $show_prices;

    /** @var bool Tenir compte des remises groupe / client */
    public $use_discounts;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = [
        'table'   => 'customcatalogonpdf_profile',
        'primary' => 'id_profile',
        'fields'  => [
            'name'             => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 255],
            'title'            => ['type' => self::TYPE_STRING, 'size' => 255],
            'id_manufacturers' => ['type' => self::TYPE_STRING],
            'id_group'         => ['type' => self::TYPE_INT],
            'id_customer'      => ['type' => self::TYPE_INT],
            'show_prices'      => ['type' => self::TYPE_BOOL],
            'use_discounts'    => ['type' => self::TYPE_BOOL],
            'date_add'         => ['type' => self::TYPE_DATE],
            'date_upd'         => ['type' => self::TYPE_DATE],
        ],
    ];

    /**
     * Retourne le tableau des id_manufacturer sélectionnés.
     *
     * @return int[]
     */
    public function getManufacturerIds(): array
    {
        if (empty($this->id_manufacturers)) {
            return [];
        }
        $ids = json_decode($this->id_manufacturers, true);
        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /**
     * Définit les id_manufacturer depuis un tableau.
     *
     * @param int[] $ids
     */
    public function setManufacturerIds(array $ids): void
    {
        $this->id_manufacturers = json_encode(array_map('intval', $ids));
    }

    /**
     * Retourne tous les profils pour la liste admin.
     *
     * @return array
     */
    public static function getAllProfiles(): array
    {
        return Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_profile` ORDER BY `name` ASC'
        ) ?: [];
    }
}
