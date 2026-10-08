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
- regroupement configurable des produits par catégorie la plus basse ou par catégorie par défaut ;
- ajout de l'image de couverture de chaque produit ;
- conversion et compression des images pour limiter le poids du PDF ;
- page de couverture avec logo, titre, contexte tarifaire, client et date ;
- sommaire paginé par catégorie ;
- en-têtes et pieds de page avec les informations de la boutique ;
- téléchargement immédiat d'un fichier PDF daté.
- export des tarifs en CSV ou Excel (XLSX) ;
- tarif principal calculé pour un groupe ou un client spécifique ;
- comparaison facultative avec le tarif d'un second groupe ;
- ajout facultatif du prix d'achat HT d'un fournisseur.
- gestion de **tarifs clients** : documents de tarification rattachés à un client ;
- organisation des produits en sections titrées, avec glisser-déposer ;
- réduction en pourcentage par ligne appliquée au prix actuel du client ;
- duplication d'un tarif vers un autre client ;
- logo et nom personnalisés par tarif ;
- export du tarif client en PDF, Excel et CSV (infos produit + prix final uniquement) ;
- validation d'un tarif verrouillant les prix sous forme de prix spécifiques PrestaShop.

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

L'installation crée la table `PREFIX_customcatalogonpdf_profile` et ajoute les entrées **Catalogue PDF** et **Export tarifs** au menu **Catalogue** du back-office.

Lors d'une mise à jour depuis la version 1.0.0, le script `upgrade/upgrade-1.1.0.php` installe automatiquement le nouvel onglet **Export tarifs**.
Le script `upgrade/upgrade-1.2.0.php` active par défaut le regroupement selon la catégorie associée la plus basse.
Le script `upgrade/upgrade-1.3.0.php` crée les tables des **Tarifs clients** et ajoute l'onglet **Tarifs clients** au menu **Catalogue**.
Le script `upgrade/upgrade-1.3.1.php` ajoute la colonne `group_reduction_percent` (remise de groupe) à la table des lignes de tarif.

## Utilisation

### Créer un profil

Dans **Catalogue > Catalogue PDF**, cliquer sur **Ajouter** puis renseigner :

| Champ | Description |
| --- | --- |
| Nom interne du profil | Nom utilisé dans le back-office et dans le nom du fichier téléchargé. |
| Titre visible sur la couverture | Titre principal du catalogue. Le nom interne est utilisé si ce champ est vide. |
| Filtrer par marques | Sélection multiple. Laisser vide pour inclure toutes les marques. |
| Utiliser la catégorie la plus basse | Classe chaque produit dans sa catégorie associée la plus profonde. Cette option est activée par défaut. |
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

### Exporter les tarifs

Dans **Catalogue > Export tarifs** :

1. sélectionner un groupe client ou rechercher un client spécifique ;
2. sélectionner facultativement un groupe de comparaison ;
3. activer facultativement le prix d'achat HT et sélectionner son fournisseur ;
4. prévisualiser les tarifs ou lancer directement l'export CSV ou Excel.

Le client spécifique est prioritaire sur le groupe principal. Le CSV utilise un point-virgule comme séparateur et contient un marqueur UTF-8. Le fichier Excel est généré au format XLSX avec filtres, ligne d'en-tête figée et colonnes de prix numériques.

## Tarifs clients

Le menu **Catalogue > Tarifs clients** permet de créer des documents de tarification rattachés à un client, à la manière d'un devis.

### Créer et composer un tarif

1. Cliquer sur **Ajouter**, saisir un **nom**, sélectionner un **client** et, si besoin, téléverser un **logo**.
2. Enregistrer : l'éditeur de contenu s'affiche sous le formulaire.
3. Rechercher un produit (nom, référence ou EAN), choisir une section cible puis **Ajouter**. Pour un produit à déclinaisons, la recherche propose chaque déclinaison individuellement **et** une entrée globale « Toutes les déclinaisons » qui les ajoute toutes d'un coup dans la section.
4. Créer des **sections** titrées et réorganiser sections et lignes par glisser-déposer.

Chaque ligne affiche l'image, le nom, la référence, l'EAN, le **prix catalogue HT**, la **remise de groupe** du client (non modifiable, pour information), un champ de **réduction client en pourcentage** et le **prix final HT**. La réduction client et le prix final sont liés en direct : saisir l'un recalcule l'autre instantanément (réduction et prix final sont exprimés par rapport au prix catalogue, arrondis à 2 décimales). Une pastille indique l'origine de la réduction affichée : **Remise groupe** (le pourcentage correspond à la remise du groupe), **Remise client** (le pourcentage est verrouillé sur le client via un prix spécifique) ou **Remise perso** (valeur négociée qui n'est encore ni sur le client ni issue du groupe, en attente de validation).

Le bouton **Rafraîchir les prix** réactualise le prix catalogue, la remise de groupe et le prix actuel de chaque ligne selon les tarifs en vigueur (la réduction client est conservée, le prix final suit).

### Détection des changements de prix

À l'ouverture d'un tarif, le module compare le prix catalogue et la remise de groupe enregistrés avec les valeurs actuelles. En cas d'écart, une pastille **« Prix modifiés »** s'affiche sur les lignes concernées (avec le détail au survol) et une bannière propose **« Mettre à jour en conservant les prix finaux »**. Cette mise à jour réactualise le prix catalogue et la remise de groupe, recalcule la réduction client de façon à **préserver le prix final**, et — si le tarif est validé — **adapte le prix spécifique du client** pour qu'il continue de payer ce même prix final malgré le changement de tarif catalogue.

### Dupliquer

Depuis l'éditeur, sélectionner un client cible puis **Dupliquer** : un nouveau tarif est créé pour ce client, les prix actuels étant recalculés pour lui.

### Exporter

Les boutons **PDF**, **Excel** et **CSV** produisent le document du tarif. Conformément aux règles du module, les exports ne contiennent que les informations produit et le **prix final** — jamais le prix d'origine ni la réduction.

### Valider

Le bouton **Valider** verrouille les prix du tarif sous forme de **prix spécifiques** PrestaShop pour le client, après une confirmation. Pour chaque ligne, un pourcentage de réduction est calculé par rapport au prix de base catalogue afin d'atteindre le prix final (arrondi à 2 décimales). Seules les règles propres au client sont créées ou écrasées — les **remises de groupe ne sont jamais modifiées**. Si le prix final correspond au prix de groupe du client (pas de dérogation), aucune règle client n'est créée et toute règle client antérieure est retirée, le client conservant simplement sa remise de groupe. Le tarif reste modifiable : une nouvelle validation réécrit les prix spécifiques.

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

Par défaut, les produits sont regroupés selon leur catégorie associée la plus basse et triés dans l'ordre de l'arbre des catégories. Le profil peut désactiver ce comportement pour utiliser leur catégorie par défaut. Chaque entrée peut contenir :

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

La catégorie utilisée pour le regroupement est, par défaut, la catégorie associée la plus profonde dans l'arbre. En cas d'égalité de profondeur, la première dans l'ordre de l'arbre est retenue. Si aucune catégorie associée n'est disponible, ou si l'option est désactivée dans le profil, la catégorie par défaut du produit est utilisée. L'image affichée est son image de couverture.

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
| [`classes/CatalogPriceExportService.php`](classes/CatalogPriceExportService.php) | Sélection des produits, calcul tarifaire et génération CSV/XLSX. |
| [`controllers/admin/AdminCustomCatalogPriceExportController.php`](controllers/admin/AdminCustomCatalogPriceExportController.php) | Routage du formulaire, des téléchargements et de la recherche client. |
| [`views/templates/admin/price_export.tpl`](views/templates/admin/price_export.tpl) | Interface générale de configuration et de prévisualisation de l'export. |
| [`controllers/admin/AdminCustomCatalogTarifController.php`](controllers/admin/AdminCustomCatalogTarifController.php) | Liste, formulaire, éditeur, AJAX, exports et validation des tarifs clients. |
| [`classes/CustomCatalogTarif.php`](classes/CustomCatalogTarif.php) | Modèle de données d'un tarif client. |
| [`classes/TarifService.php`](classes/TarifService.php) | Recherche, calcul des prix, sections/lignes, duplication, validation et exports. |
| [`classes/TarifPdfGenerator.php`](classes/TarifPdfGenerator.php) | Génération PDF d'un tarif client (prix final uniquement). |
| [`views/templates/admin/tarif_editor.tpl`](views/templates/admin/tarif_editor.tpl) | Éditeur de sections et de lignes d'un tarif. |
| [`sql/install.php`](sql/install.php) | Création de la table des profils. |
| [`sql/uninstall.php`](sql/uninstall.php) | Suppression de la table des profils. |
| [`upgrade/upgrade-1.1.0.php`](upgrade/upgrade-1.1.0.php) | Installation du nouvel onglet lors d'une mise à jour. |
| [`upgrade/upgrade-1.2.0.php`](upgrade/upgrade-1.2.0.php) | Ajout du réglage de catégorie la plus basse lors d'une mise à jour. |
| [`upgrade/upgrade-1.3.0.php`](upgrade/upgrade-1.3.0.php) | Création des tables de tarifs clients et de l'onglet dédié. |
| [`upgrade/upgrade-1.3.1.php`](upgrade/upgrade-1.3.1.php) | Ajout de la colonne de remise de groupe aux lignes de tarif. |

## Désinstallation

La désinstallation retire les deux entrées du menu et supprime la table du module.

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