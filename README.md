# Custom Catalog on PDF

Module PrestaShop permettant de générer des catalogues produits PDF depuis le back-office à l'aide de profils réutilisables.

Chaque profil définit le contenu du catalogue, son titre et sa politique tarifaire. Le document est ensuite généré à la demande et téléchargé directement depuis la liste des profils.

## Fonctionnalités

- création, modification et suppression de profils de catalogue ;
- sélection d'une ou plusieurs marques, ou inclusion de toutes les marques ;
- génération avec ou sans prix ;
- affichage des prix catalogue HT ;
- prise en compte des tarifs et remises d'un groupe client ;
- génération de tarifs personnalisés pour un client précis ;
- priorité automatique du client sélectionné sur le groupe client ;
- prise en charge des produits simples et des déclinaisons ;
- affichage des références produit et déclinaison ;
- regroupement des produits par catégorie par défaut ;
- ajout de l'image de couverture de chaque produit ;
- conversion et compression des images pour limiter le poids du PDF ;
- page de couverture avec logo, titre, contexte tarifaire, client et date ;
- sommaire paginé par catégorie ;
- en-têtes et pieds de page avec les informations de la boutique ;
- téléchargement immédiat d'un fichier PDF daté.

## Exemple de nom de fichier

```text
catalogue_nom-du-profil_20260911.pdf
```

## Prérequis

- PrestaShop 8.0 ou version ultérieure ;
- une version de PHP compatible avec la version de PrestaShop utilisée ;
- TCPDF fourni par l'installation PrestaShop ;
- extension PHP GD recommandée pour redimensionner et convertir les images, notamment les fichiers WebP ;
- droits d'écriture dans le répertoire temporaire de PHP.

Aucune dépendance Composer supplémentaire n'est nécessaire au niveau du module.

## Installation

### Depuis une archive ZIP

1. Créer une archive contenant le dossier `customcatalogonpdf` à sa racine.
2. Dans le back-office PrestaShop, ouvrir **Gestionnaire de modules > Installer un module**.
3. Déposer l'archive ZIP puis lancer l'installation.
4. Ouvrir **Catalogue > Catalogue PDF**.

### Installation manuelle

1. Copier le dossier dans `modules/customcatalogonpdf`.
2. Depuis le gestionnaire de modules, rechercher **Catalogue produits PDF**.
3. Cliquer sur **Installer**.
4. Ouvrir **Catalogue > Catalogue PDF**.

L'installation crée la table `PREFIX_customcatalogonpdf_profile` et ajoute l'entrée **Catalogue PDF** au menu **Catalogue** du back-office.

## Utilisation

### Créer un profil

Dans **Catalogue > Catalogue PDF**, cliquer sur **Ajouter** puis renseigner :

| Champ | Description |
| --- | --- |
| Nom interne du profil | Nom utilisé dans le back-office et dans le nom du fichier téléchargé. |
| Titre visible sur la couverture | Titre principal du catalogue. Le nom interne est utilisé si ce champ est vide. |
| Filtrer par marques | Sélection multiple. Laisser vide pour inclure toutes les marques. |
| Afficher les prix | Active l'affichage des prix HT dans les fiches produits et déclinaisons. |
| Tenir compte des remises groupe / client | Calcule les prix dans le contexte tarifaire sélectionné. |
| Groupe client | Groupe utilisé pour le calcul lorsque aucun client précis n'est sélectionné. |
| Client spécifique | Client utilisé pour les tarifs spécifiques. Ce choix est prioritaire sur le groupe. |

Les champs de groupe et de client sont affichés uniquement lorsque les prix et les remises sont activés.

### Générer le catalogue

1. Revenir à la liste des profils.
2. Cliquer sur le bouton **PDF** du profil souhaité.
3. Le module construit le catalogue et lance son téléchargement.

Le document est généré dans la langue active du back-office et avec le contexte courant de boutique, devise et pays.

## Modes tarifaires

| Afficher les prix | Remises activées | Sélection | Résultat |
| --- | --- | --- | --- |
| Non | Indifférent | Indifférente | Catalogue sans prix. |
| Oui | Non | Aucune | Prix catalogue HT. |
| Oui | Oui | Client | Prix HT calculés pour ce client, tarifs spécifiques compris. |
| Oui | Oui | Groupe | Prix HT calculés pour ce groupe. |
| Oui | Oui | Aucune | Retour au prix catalogue HT. |

Lorsqu'un client et un groupe sont tous les deux renseignés, le client est toujours prioritaire.

## Contenu du PDF

### Couverture

- logo configuré pour la boutique ;
- titre du profil ;
- mention du mode tarifaire ;
- identification du client lorsqu'il est sélectionné ;
- date de génération ;
- nom de la boutique.

### Sommaire

Le sommaire liste les catégories présentes et leur numéro de page. Il s'étend automatiquement sur plusieurs pages si nécessaire.

### Catalogue produits

Les produits sont regroupés selon leur catégorie par défaut et triés dans l'ordre de l'arbre des catégories. Chaque entrée peut contenir :

- le nom du produit ;
- son image de couverture ;
- sa référence ;
- son prix HT lorsque l'option est active ;
- ses déclinaisons avec attributs, références et prix propres.

Les pages internes affichent également le logo, le titre du catalogue, le contexte tarifaire, la date, les coordonnées de la boutique et une pagination hors couverture.

## Sélection des produits

Le catalogue inclut actuellement :

- les produits actifs ;
- les produits non virtuels ;
- toutes les marques lorsque le filtre est vide, ou uniquement les marques sélectionnées ;
- toutes les déclinaisons rattachées aux produits retenus.

La catégorie utilisée pour le regroupement est la catégorie par défaut du produit. L'image affichée est son image de couverture.

## Gestion des images

Le générateur recherche les images produit aux formats JPEG, PNG et WebP. Lorsque GD est disponible, les images sont redimensionnées à 120 pixels maximum, converties en JPEG et compressées avant leur insertion dans le document. Les fichiers temporaires sont supprimés après la génération.

Sans image exploitable, un emplacement neutre est affiché. Sans prise en charge WebP par GD, une image disponible uniquement dans ce format ne peut pas être intégrée.

## Informations de la boutique

Le module réutilise la configuration PrestaShop pour afficher :

- le nom de la boutique ;
- le logo ;
- l'adresse ;
- le téléphone ;
- l'adresse e-mail.

Ces données doivent être correctement renseignées dans PrestaShop avant de générer le catalogue.

## Architecture

| Fichier | Rôle |
| --- | --- |
| [`customcatalogonpdf.php`](customcatalogonpdf.php) | Installation du module, création du menu et redirection vers le contrôleur. |
| [`controllers/admin/AdminCustomCatalogOnPdfController.php`](controllers/admin/AdminCustomCatalogOnPdfController.php) | Liste des profils, formulaire de configuration et action de génération. |
| [`classes/CustomCatalogProfile.php`](classes/CustomCatalogProfile.php) | Modèle de données d'un profil. |
| [`classes/CatalogPdfGenerator.php`](classes/CatalogPdfGenerator.php) | Sélection des produits, calcul des prix et rendu TCPDF. |
| [`sql/install.php`](sql/install.php) | Création de la table des profils. |
| [`sql/uninstall.php`](sql/uninstall.php) | Suppression de la table des profils. |

## Désinstallation

La désinstallation retire l'entrée du menu et supprime la table du module.

> **Attention :** tous les profils enregistrés sont définitivement supprimés lors de la désinstallation.

## Dépannage

### Le PDF ne contient aucun produit

Vérifier que les produits sont actifs, non virtuels et associés aux marques sélectionnées dans le profil.

### Une image WebP n'apparaît pas

Vérifier que l'extension GD est installée et que PHP prend en charge WebP via `imagecreatefromwebp()`.

### Les coordonnées de la boutique sont incomplètes

Compléter le nom, l'adresse, le téléphone et l'adresse e-mail dans la configuration de la boutique PrestaShop.

### Le tarif ne correspond pas au résultat attendu

Contrôler le client ou le groupe choisi, la devise et le pays actifs dans le contexte du back-office, ainsi que les règles de prix spécifiques configurées dans PrestaShop.

## Compatibilité et limites actuelles

- le format de sortie est A4 portrait ;
- les montants sont affichés en euros avec deux décimales et la mention HT ;
- le contenu textuel du PDF est actuellement en français ;
- les produits virtuels sont exclus ;
- le catalogue est généré à la demande, sans planification automatique ;
- aucun fichier PDF n'est conservé par le module après le téléchargement.

## Auteur

Développé par **Créa2média**.