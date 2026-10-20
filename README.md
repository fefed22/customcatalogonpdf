# Custom Catalog on PDF

Module PrestaShop permettant de générer des catalogues produits PDF depuis le back-office à l'aide de profils réutilisables.

Chaque profil définit le contenu du catalogue, son titre et sa politique tarifaire. Le document est ensuite généré à la demande et téléchargé directement depuis la liste des profils.

## Fonctionnalités

- création, modification et suppression de profils de catalogue ;
- sélection d'une ou plusieurs marques, ou inclusion de toutes les marques ;
- sélection facultative d'un tarif client comme source de la liste de produits et de déclinaisons, cumulable avec le filtre de marques ;
- génération avec ou sans prix ;
- affichage des prix catalogue HT ;
- prise en compte des tarifs et remises d'un groupe client ;
- génération de tarifs personnalisés pour un client précis ;
- priorité automatique du client sélectionné sur le groupe client ;
- prise en charge des produits simples et des déclinaisons ;
- affichage des références produit et déclinaison ;
- regroupement configurable des produits par catégorie la plus basse ou par catégorie par défaut ;
- ajout de l'image de couverture de chaque produit ;
- badge **NOUVEAU** orange à texte blanc sur les fiches des nouveaux produits, selon la règle native PrestaShop (date d'ajout dans la boutique et durée de nouveauté configurée), sans réglage supplémentaire dans le module ;
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
- remise supplémentaire successive, par ligne ou par section, avec prix intermédiaire, prix net et remise totale calculée ;
- duplication d'un tarif vers un autre client ;
- logo et nom personnalisés par tarif ;
- export du tarif client en PDF, Excel et CSV (infos produit + prix final uniquement) ;
- validation d'un tarif sur le client ou son groupe par défaut, avec deux confirmations et un historique complet.

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
3. Rechercher un produit (nom, référence, EAN ou marque), éventuellement filtrer par **Marque**, choisir une section cible puis **Ajouter**. Le select exclut les produits et déclinaisons déjà présents dans le tarif. Pour un produit à déclinaisons, il propose chaque déclinaison restante individuellement **et** une entrée globale « Déclinaisons restantes » qui les ajoute toutes d'un coup dans la section.
4. Pour un ajout en masse, cliquer sur **Réf. en masse** : une fenêtre propose une section cible et une zone de texte où l'on colle une référence (produit ou déclinaison) par ligne. Toutes les références reconnues sont ajoutées dans la section choisie ; les références introuvables et celles déjà présentes dans le tarif sont listées.
5. Créer des **sections** titrées et réorganiser sections et lignes par glisser-déposer. Chaque en-tête propose **Théorique %** et **Supplémentaire %**, avec leurs boutons **Appliquer** : après confirmation, seule la remise choisie est remplacée sur les lignes de la section.

Le bouton **Replier / Déplier** dans chaque en-tête masque ou affiche les produits de la section, y compris **Sans section** et les sections nouvellement créées. L'en-tête reste visible et les lignes restent dans le tarif : leur ordre, leurs prix et les exports ne changent pas. Les sections sont dépliées au chargement de la page.

La colonne **Traité** permet de cocher ou décocher chaque ligne pour suivre sa vérification, même si sa réduction reste à 0 %. La coche est enregistrée immédiatement dans le tarif et conservée au rechargement. Elle est indépendante des prix, des réductions, de la validation et des exports. Les nouvelles lignes sont non traitées ; lors d'une duplication vers un autre client, les coches sont réinitialisées pour permettre une nouvelle vérification. Une erreur d'enregistrement est signalée et la case reprend son état précédent.

À l'édition d'un tarif existant, le bloc **Tarif client** (nom, client, logo) est replié par défaut et le bouton **Enregistrer** de la barre d'outils supérieure est retiré. Déplier le bloc permet de modifier ces informations et d'utiliser son bouton d'enregistrement interne. Le bloc reste ouvert en cas d'erreur de formulaire. À la création, le formulaire et son enregistrement restent visibles.

La version **1.3.9** ajoute la colonne de suivi `is_processed`. Sur une installation existante, lancer la **mise à jour du module** dans le gestionnaire de modules avant d'utiliser cette version ; les lignes existantes démarrent non traitées.

Un même produit (couple produit / déclinaison) ne peut être ajouté qu'une seule fois dans un tarif : les tentatives de doublon sont bloquées en ajout unitaire et ignorées en ajout en masse.

Le bouton **Parcourir les produits** ouvre une modal complémentaire au select. Elle permet de parcourir tous les produits de la boutique courante, par pages de 24 produits parents, et de filtrer par marque ou de rechercher par nom, référence, EAN ou marque. Chaque produit simple ou déclinaison dispose d'une fiche avec photo (si disponible), référence, nom et prix catalogue HT dans la devise courante. La **section cible** se choisit sur chaque fiche avant de cliquer sur **Ajouter**, sans fermer la modal. Les fiches déjà présentes dans le tarif restent visibles, grisées et non ajoutables ; une fiche ajoutée devient immédiatement grisée. Les produits partiellement ajoutés conservent leurs déclinaisons restantes disponibles.

Les produits **inactifs** dans la boutique courante et leurs déclinaisons restent ajoutables dans le select et dans la modal. Ils sont signalés par la mention **Inactif**, y compris dans l'entrée globale des déclinaisons restantes. Ce statut ne les grise pas et ne bloque pas leur ajout ; seule leur présence dans le tarif empêche un doublon.

Les filtres **Marque** du select et de la modal proposent **Sans marque** pour rechercher les produits sans fabricant associé. Dans la modal, la case **Afficher uniquement les produits absents du tarif** masque les produits et déclinaisons déjà ajoutés, tout en conservant les déclinaisons manquantes des produits partiellement présents. Le filtre est appliqué avant la pagination et se combine avec la marque et la recherche. Après un ajout avec cette case cochée, la liste revient à la première page et s'actualise. Case décochée, les produits déjà présents restent visibles et grisés ; le select exclut toujours les doublons.

Sur une fiche de déclinaison disponible dans la modal, **Ajouter toutes les déclinaisons restantes** ajoute le produit déclinable entier dans la section choisie sur cette fiche. Les déclinaisons déjà présentes sont ignorées et restent dans leur section actuelle. Toutes les fiches du produit deviennent ensuite grisées et non ajoutables, sans fermer la modal ; le bouton **Ajouter** conserve l'ajout individuel.

Chaque ligne affiche l'image, le nom, la référence, l'EAN, le **prix catalogue HT**, la **remise de groupe** du client (non modifiable, pour information), la **remise théorique %** client/groupe, une **remise supplémentaire %** et une **remise totale %** calculée en lecture seule.

Les deux remises sont **successives**, jamais additionnées : pour un prix catalogue de 100 €, une remise théorique de 20 % donne 80 €, puis une remise supplémentaire de 10 % donne **72 €**, soit **28 %** de remise totale. Les montants sont arrondis au centime à chaque étape ; le pourcentage total affiché correspond au prix net par rapport au prix catalogue. La remise supplémentaire est comprise entre 0 et 100 % et vaut 0 sur les anciennes et nouvelles lignes par défaut.

La colonne **Prix final HT** distingue **« Après remise théorique »** (intermédiaire) et **« Net après les deux remises »** (prix final éditable). La saisie du prix net **recalcule toujours la première remise et conserve la deuxième** : avec une remise supplémentaire de 10 %, saisir 63 € donne 70 € avant cette deuxième remise, soit une première remise de 30 % sur un catalogue à 100 €. Avec une deuxième remise de 100 %, seul un prix net nul est possible. Les deux champs de remise sont éditables séparément ; la remise totale n'a pas de saisie directe.

Une flèche **→** à droite de la remise de groupe permet de la réappliquer en un clic à la première remise, sans effacer la remise supplémentaire. Une pastille indique l'origine de la première remise affichée : **Remise groupe**, **Remise client** ou **Remise perso**.

Chaque section, y compris **Sans section** et les sections ajoutées sans recharger la page, propose deux actions distinctes **Théorique %** et **Supplémentaire %**. Chaque action remplace uniquement la remise correspondante sur les lignes de cette section et conserve l'autre. Le rafraîchissement et la duplication conservent les deux pourcentages. La synchronisation qui préserve les prix nets conserve aussi la remise supplémentaire et ajuste uniquement la première remise.

Les validations client/groupe appliquent la remise globale issue du prix net après les deux remises. Les exports PDF, Excel et CSV continuent de présenter uniquement le prix net final, sans exposer les remises internes.

La mise à jour du module vers **1.6.0** ajoute la seconde remise à zéro sans modifier les prix ni les premières remises des tarifs existants.

Le bouton **Rafraîchir les prix** réactualise le prix catalogue, la remise de groupe et le prix actuel de chaque ligne selon les tarifs en vigueur (les deux remises saisies sont conservées, le prix net final est recalculé).

### Détection des changements de prix

À l'ouverture d'un tarif, le module compare le prix catalogue et la remise de groupe enregistrés avec les valeurs actuelles. En cas d'écart, une pastille **« Prix modifiés »** s'affiche sur les lignes concernées (avec le détail au survol) et une bannière propose **« Mettre à jour en conservant les prix finaux »**. Cette mise à jour réactualise le prix catalogue et la remise de groupe, recalcule la réduction client de façon à **préserver le prix final**, et — si le tarif a été validé sur ce client — **adapte son prix spécifique existant**. Une validation sur le groupe seul ne provoque aucune écriture sur les prix spécifiques du client. Les prix du groupe ne sont pas réécrits par cette synchronisation : il faut les appliquer avec une nouvelle double validation.

### Dupliquer

Depuis l'éditeur, sélectionner un client cible puis **Dupliquer** : un nouveau tarif est créé pour ce client, les prix actuels étant recalculés pour lui.

### Exporter

Les boutons **PDF**, **Excel** et **CSV** produisent le document du tarif. Conformément aux règles du module, les exports ne contiennent que les informations produit et le **prix final** — jamais le prix d'origine ni la réduction.

### Valider

Deux boutons sont proposés dans l'éditeur :

- **Valider le tarif sur le client** : applique les prix spécifiques uniquement au client enregistré sur le tarif.
- **Valider le tarif sur le groupe** : applique les prix spécifiques au **groupe de clients par défaut** de ce client. Le nom et l'identifiant du groupe sont affichés avant validation. Cette action concerne tous les clients de ce groupe ; leurs prix spécifiques individuels ne sont pas supprimés.

Chaque action impose **deux confirmations successives** : la première indique la cible et le remplacement des anciennes remises ; la seconde reprend la cible vérifiée par le serveur et le nombre de lignes. Un changement des lignes ou de la cible entre les confirmations oblige à recommencer. Un tarif vide ou sans client valide ne peut pas être validé. Depuis la liste des tarifs, l'action **Valider** ouvre cette zone de l'éditeur : elle ne modifie jamais les prix directement.

Pour chaque ligne, la remise est calculée par rapport au prix catalogue, avec un pourcentage arrondi à deux décimales, comme pour la validation client existante. Les anciens prix spécifiques manuels hors panier sont remplacés **pour la cible choisie et les couples produit/déclinaison présents dans le tarif uniquement**. Les produits absents, les autres déclinaisons, les autres clients/groupes, les règles de prix catalogue et les prix propres à un panier sont conservés. Les règles générales et les priorités natives de PrestaShop restent applicables. En mode client, si le prix final correspond déjà au prix du groupe, l'ancienne dérogation client est retirée sans en créer une autre.

Les prix, le statut et l'historique sont enregistrés dans une même transaction : une erreur annule l'ensemble de la validation. Chaque validation réussie ajoute une entrée d'historique indiquant **la date et l'heure, la cible client/groupe (nom et ID au moment de l'action), l'employé et le nombre de lignes**. L'historique complet est visible dans l'éditeur ; la liste indique la dernière cible et la dernière date de validation. Une nouvelle validation n'efface pas les précédentes.

La version **1.5.0** ajoute la table d'historique lors de la mise à jour du module. Les anciennes validations conservent leur dernière date connue et sont signalées comme **« cible non historisée »** : les cibles et les validations plus anciennes ne peuvent pas être reconstituées.

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
| [`upgrade/upgrade-1.4.0.php`](upgrade/upgrade-1.4.0.php) | Ajout du tarif source optionnel aux profils de catalogue. |
| [`upgrade/upgrade-1.5.0.php`](upgrade/upgrade-1.5.0.php) | Création de l'historique des validations et reprise des dates de validation connues. |
| [`upgrade/upgrade-1.6.0.php`](upgrade/upgrade-1.6.0.php) | Ajout de la remise supplémentaire, initialisée à zéro sans modification des prix existants. |

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

## Tests de recherche produit

Exécuter `php tests/catalog-new-badge.php` pour vérifier l'utilisation de la règle native de nouveauté PrestaShop, sa propagation aux déclinaisons et le rendu du badge PDF (couleurs, position et saut de page).

Exécuter `php tests/product-search.php` depuis le dossier du module (extension PHP PDO SQLite requise). Ces tests autonomes utilisent une base en mémoire, sans modifier la boutique, et couvrent les marques, l'exclusion des doublons, les déclinaisons restantes, les fiches de la modal, la pagination et les erreurs.

Exécuter également `node tests/product-modal.cjs` pour vérifier le rendu du bouton d'ajout de toutes les déclinaisons, la section cible, le grisement des fiches après ajout, la gestion des erreurs et le maintien de l'ajout individuel.

Exécuter `node tests/section-collapse.cjs` pour vérifier le repli/dépli indépendant des sections, les états d'accessibilité et les contrôles des sections existantes ou créées dynamiquement.

Exécuter `node tests/line-processed.cjs` pour vérifier la coche de suivi, son rendu et le rétablissement de son état en cas d'erreur ; les tests PHP vérifient également sa persistance et son isolation par tarif.

## Auteur

Développé par **Créa2média**.