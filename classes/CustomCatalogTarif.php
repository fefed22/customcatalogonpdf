<?php
/**
 * ObjectModel pour les tarifs clients (documents de tarification).
 *
 * Un tarif est un document de travail rattaché à un client : il regroupe des
 * sections et des lignes produits, peut être exporté (PDF / Excel / CSV) puis
 * validé pour verrouiller les prix sous forme de prix spécifiques PrestaShop.
 *
 * @author Créa2média
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CustomCatalogTarif extends ObjectModel
{
    public const STATUS_DRAFT = 0;
    public const STATUS_VALIDATED = 1;

    /** @var int Client rattaché au tarif */
    public $id_customer;

    /** @var string Nom du tarif */
    public $name;

    /** @var string Nom de fichier du logo (dans le répertoire uploads/tarif) */
    public $logo;

    /** @var int Statut : brouillon (0) ou validé (1) */
    public $status = self::STATUS_DRAFT;

    /** @var string|null Date de dernière validation */
    public $date_validated;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    public static $definition = [
        'table'   => 'customcatalogonpdf_tarif',
        'primary' => 'id_tarif',
        'fields'  => [
            'id_customer'    => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'name'           => ['type' => self::TYPE_STRING, 'required' => true, 'size' => 255],
            'logo'           => ['type' => self::TYPE_STRING, 'size' => 255],
            'status'         => ['type' => self::TYPE_INT],
            'date_validated' => ['type' => self::TYPE_DATE, 'validate' => 'isDateOrNull'],
            'date_add'       => ['type' => self::TYPE_DATE],
            'date_upd'       => ['type' => self::TYPE_DATE],
        ],
    ];

    /**
     * Répertoire absolu de stockage des logos de tarif.
     */
    public static function getLogoDir(): string
    {
        return _PS_MODULE_DIR_ . 'customcatalogonpdf/uploads/tarif/';
    }

    /**
     * Chemin absolu du logo, ou chaîne vide s'il n'existe pas.
     */
    public function getLogoPath(): string
    {
        if (empty($this->logo)) {
            return '';
        }
        $path = self::getLogoDir() . $this->logo;

        return file_exists($path) ? $path : '';
    }

    public function isValidated(): bool
    {
        return (int) $this->status === self::STATUS_VALIDATED;
    }
}
