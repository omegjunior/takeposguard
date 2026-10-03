# TakePOS Payment Guard

Module externe indépendant installé dans `htdocs/custom/takeposguard`, sans modification du cœur Dolibarr.

## État de la version 0.10.1

Cette version implémente les points 1 à 9 et ajoute la recette automatisée du point 10 : configuration, stockage, verrou exclusif, interception serveur, protection du stock lors des paiements partiels, protection JavaScript, finalisation, récupération, audit et maintenance. Les résultats confirmés en base deviennent `SUCCESS` ou `FAILED` après la transaction native ; une situation ambiguë devient `BLOCKED`. La protection reste à valider sur une instance de recette avant production : les tests natifs CLI de paiement/banque/stock et concurrence sont exécutés, mais la recette HTTP authentifiée reste à réaliser. Voir le [rapport de recette](docs/acceptance-report.md). L’option désactivée conserve l’action native. Aucun trigger n’est ajouté.

## Installation

1. Copier le dossier dans `htdocs/custom/takeposguard`.
2. Utiliser Dolibarr 22.0.4 ou supérieur et PHP 7.2 ou supérieur, en respectant également les prérequis de Dolibarr.
3. Activer le module dans Configuration > Modules/Applications. La dépendance TakePOS est déclarée.
4. Ouvrir sa configuration avec un compte administrateur.

La version locale inspectée est 22.0.5. Le contrôle des sources et la recette native locale passent sur cette version ; 22.0.4 et les autres versions ne sont pas certifiées par ces résultats. Relancer les contrôles et la recette HTTP sur chaque version déployée.

Pour une installation déjà activée en 0.1.0, désactiver puis réactiver le module afin de charger les nouvelles tables. La configuration reste conservée. Les tables et l'historique ne sont pas supprimés lors de la désactivation.

## Interfaces d'administration (0.10.1)

Le module technique n'ajoute plus de menu supérieur. Ses liens **Historique des tentatives** et **Maintenance** sont rattachés à **Accueil > Outils d'administration**, selon les droits du module et l'accès au menu parent natif. Les URL directes restent soumises aux droits habituels. Pour migrer les entrées de menu depuis 0.10.0, désactiver puis réactiver le module hors encaissement ; sa configuration et son historique sont conservés.

Les deux listes utilisent les composants Dolibarr : filtres dans le tableau, boutons recherche/réinitialisation, tri, pagination bornée à 100 lignes et sélecteur de colonnes. La sélection est enregistrée dans les préférences de chaque utilisateur, séparément pour l'audit et la maintenance. Les dates filtrent une journée, les montants une valeur exacte (point ou virgule), les textes utilisent la recherche native. Masquer une colonne ne supprime pas son filtre actif ; le bouton de réinitialisation vide tous les filtres. La maintenance conserve ses POST CSRF et le propriétaire exact du verrou.

Dans la configuration, les curseurs enregistrent immédiatement les deux paramètres binaires via l'API native Dolibarr. Le bouton **Enregistrer** valide les autres paramètres et n'écrase pas les valeurs des curseurs avec celles chargées à l'ouverture de la page. Sans JavaScript AJAX, le sélecteur oui/non natif est enregistré avec le formulaire. Les liens audit et maintenance sont placés après Enregistrer.

`php test/ui.php --mysql` vérifie le rendu PHP des vrais composants Form, les préférences de colonnes et l'échappement des filtres masqués ; ce test n'écrit pas de configuration. Il est inclus dans `php test/run.php --mysql`. La recette dans un navigateur authentifié reste nécessaire pour confirmer le rendu du thème, les curseurs AJAX et la persistance des préférences via HTTP.

## Configuration par entité

| Constante | Défaut | Valeurs |
| --- | --- | --- |
| `TAKEPOSGUARD_ENABLE` | `0` | `0` ou `1` |
| `TAKEPOSGUARD_LOCK_TIMEOUT` | `120` | 10 à 3600 secondes |
| `TAKEPOSGUARD_HISTORY_DAYS` | `90` | 1 à 3650 jours |
| `TAKEPOSGUARD_MAX_ATTEMPTS` | `1000` | 10 à 9999 jetons par facture |
| `TAKEPOSGUARD_DEBUG_LOG` | `0` | `0` ou `1` |
| `TAKEPOSGUARD_MISSING_TOKEN_POLICY` | `reject` | Refus uniquement |

L’option commande maintenant l’interception serveur. Les intégrations tierces devront fournir un jeton stable par tentative ; aucun contournement silencieux ne sera proposé.

Les écritures utilisent les API natives, une transaction et l'entité courante. Le formulaire accepte uniquement POST et conserve la vérification CSRF native. Une valeur invalide empêche l'enregistrement de tout le formulaire. La configuration est conservée lors de la désactivation/réactivation.

## Identifiants et droits

Numéro local : `501117`. Droits : `50111701` (`audit/read`) et `50111702` (`maintenance/write`). Ils ne donnent aucun droit de paiement et ne conditionnent pas la protection serveur. La configuration reste réservée aux administrateurs.

Les identifiants ont été contrôlés dans les descripteurs locaux et en lecture seule dans `rights_def`. Cela ne constitue pas une réservation mondiale : vérifier les collisions sur chaque instance avant installation. Les pages d’audit et de maintenance sont maintenant disponibles dans le menu du module et depuis sa configuration.

## Vérification

Exécuter `php -l` sur les fichiers PHP et `php test/configuration.php`. Sur une instance de test, vérifier également l'activation, les soumissions administrateur/non-administrateur, les jetons CSRF invalides, les valeurs invalides, la conservation après réactivation et l'isolation entre entités.

Pour le stockage, exécuter `php test/storage.php --mysql` depuis le dossier du module. Le test lit la configuration de connexion locale et utilise exclusivement des tables temporaires propres à la connexion, avec le préfixe `tpg_test_`. Il ne modifie pas les données Dolibarr. Le compte de base doit pouvoir créer des tables temporaires. La connexion fermée, ces tables disparaissent. L'hydratation complète de `Facture::fetch()` est remplacée par une fixture minimale ; le calcul natif du reste à payer et les requêtes du stockage sont réellement exécutés.

`php test/sql_portability.php` vérifie la conversion des scripts par le pilote PostgreSQL Dolibarr, sans connexion PostgreSQL. Ce contrôle ne remplace pas un test d'installation et d'exécution sur ce moteur.

Résultats exécutés localement : 166 contrôles de schéma/stockage sur MariaDB via le pilote `mysqli`, 22 cas de configuration et contrôles du descripteur, conversion de neuf instructions SQL par le pilote PostgreSQL. L'activation réelle dans l'interface n'a pas été exécutée.

Les tests de concurrence du verrou sont décrits ci-dessous. Les tests de paiement et stock après interception appartiennent aux étapes suivantes. Cette version ne doit pas être utilisée comme protection en production.

## Stockage des tentatives et verrous

L'activation charge les fichiers `sql/llx_takeposguard*.sql` et leurs clés via `_load_tables()`. Les scripts suivent la syntaxe des modules externes Dolibarr ; le préfixe réel est substitué par l'installateur et PostgreSQL utilise la conversion native. Un échec de chargement SQL empêche l'activation.

- `takeposguard_payment_attempt` conserve le jeton UUID v4 canonique, la facture, l'entité, l'utilisateur, le terminal, les données demandées, les instantanés avant/après, les références de paiement, les dates et les erreurs bornées. Les états admis par la bibliothèque sont `PROCESSING`, `SUCCESS`, `FAILED`, `BLOCKED`.
- Une clé unique couvre `(entity, fk_invoice, operation_token)`. Une seconde couvre `(entity, operation_token)` : le même jeton ne peut pas être affecté à une autre facture dans la même entité. Il peut être réutilisé dans une autre entité.
- `takeposguard_invoice_lock` conserve le propriétaire logique et l'expiration, avec unicité `(entity, fk_invoice)`. `TakeposguardStorage` permet de lire ces métadonnées ; `TakeposguardLock` assure l'exclusion décrite ci-dessous.

`TakeposguardStorage` utilise la connexion DoliDB fournie et fixe son périmètre à l'entité courante lors de sa construction. `createProcessing()` recharge une facture TakePOS de cette entité et capture le reste à payer par les API natives. Le nombre et la dernière référence des paiements sont également mémorisés. `fetchAttempt()` distingue absence (`null`) et erreur (`false`, code technique dans `error`).

`completeAttempt()` stocke un résultat confirmé par son appelant et ne remplace qu'un état `PROCESSING`. Le reste et le statut sont rechargés ; le montant réel provient du lien de paiement en base, jamais du montant demandé. Un paiement référencé doit appartenir à la même entité et à la facture concernée. Une tentative finalisée ne peut pas être réécrite avec cette méthode.

La bibliothèque n'ouvre ni ne termine de transaction. Le code appelant devra vérifier les droits et détenir le verrou exclusif avant création/finalisation ; l’interception applique ces conditions avant la création ; la finalisation applique également ces conditions. Le statut `SUCCESS` n'est pas déduit automatiquement par la couche de stockage. Les modes et montants demandés sont des informations d'audit, sans pouvoir d'autoriser un paiement.

IP et user-agent sont facultatifs, validés et limités ; les messages d'erreur doivent être techniques et sans données sensibles. La bibliothèque n'écrit aucun log contenant les requêtes ou données de paiement. Les index couvrent la facture/date, l'état/date, la date de finalisation et l'expiration. Les références métier n'ont pas de suppression en cascade, pour préserver l'audit. La maintenance permet un nettoyage limité des détails anciens, avec une tâche native facultative désactivée par défaut. Une limite de tentatives par facture borne les jetons persistants.

## Verrou exclusif par facture

`TakeposguardLock` combine un verrou consultatif de session et la ligne persistante. La clé inclut la base, le préfixe, l'entité et la facture. MySQL/MariaDB utilise `GET_LOCK(..., 0)` ; PostgreSQL utilise `pg_try_advisory_lock(int, int)`. Les opérations passent uniquement par DoliDB. Aucun `begin`, `commit` ou `rollback` n'est ajouté par le gestionnaire.

Les verrous de session ne sont pas libérés par un commit/rollback natif, mais par une libération explicite ou la fin de la session de base. Voir les documentations [MySQL](https://dev.mysql.com/doc/refman/8.0/en/locking-functions.html) et [PostgreSQL](https://www.postgresql.org/docs/current/functions-admin.html#FUNCTIONS-ADVISORY-LOCKS). Une seule facture peut être gardée par session native, afin d'éviter les acquisitions récursives et les différences des anciennes versions MySQL.

L'approche initiale par verrou de ligne sur une connexion séparée a été adaptée : le pilote PostgreSQL Dolibarr utilise `pg_connect()` sans garantir une nouvelle connexion physique. Les verrous consultatifs permettent d'utiliser la connexion native sans interférer avec ses transactions. La table est maintenue pour la visibilité et la récupération après interruption ; elle n'est jamais considérée seule comme une preuve d'exclusion.

### Acquisition et résultats

`acquire(int $invoiceId, string $token, int $ttl)` valide une facture TakePOS de l'entité courante, un UUID v4 et une durée de 10 à 3600 secondes. L'acquisition doit précéder la transaction native : elle est refusée si une transaction est déjà ouverte. L'entité et le préfixe sont fixés à la construction de l'objet.

Après le verrou consultatif, un `INSERT` atomique crée les métadonnées. Il est autocommitté et visible avant tout traitement natif. En cas de doublon, le gestionnaire contrôle la ligne existante sous exclusion. L'expiration et les dates utilisent l'horloge de la base, pas celle du serveur PHP.

| Résultat | Signification |
| --- | --- |
| `ACQUIRED` (`1`) | Verrou détenu et métadonnées préparées pour le jeton demandé. |
| `BUSY` (`0`) | Session concurrente ou métadonnées non expirées : aucun traitement natif. |
| `RECOVERY_REQUIRED` (`2`) | Verrou consultatif détenu, ancien jeton expiré à réconcilier ; aucun traitement natif avant récupération confirmée. |
| `ERROR` (`-1`) | Erreur technique ou entrée non prise en charge : aucun traitement natif. |

**Comparer explicitement le résultat à `ACQUIRED` : tester seulement sa valeur booléenne serait incorrect.** Le hook vérifie les droits, le jeton et l’historique avant de poursuivre TakePOS. Le gestionnaire ne permet pas à lui seul de rejouer un jeton finalisé.

### Expiration, libération et récupération

Un traitement encore actif garde son verrou consultatif même lorsque l'expiration est dépassée. Une seconde session ne peut pas le déloger. La durée configurée est donc un délai minimal avant récupération d'une session interrompue, et non une autorisation d'interrompre un paiement actif.

Après une interruption, la fermeture de la connexion libère le verrou consultatif ; les métadonnées persistent. Tant qu'elles ne sont pas expirées, les nouvelles tentatives restent bloquées. Après expiration, `RECOVERY_REQUIRED` expose le jeton précédent via `previousToken`. L’interception réconcilie la tentative et les effets natifs sous le verrou avant `confirmRecovery($expectedToken)`. Cette méthode remplace uniquement le propriétaire attendu encore expiré. La récupération de l’étape 7 distingue les effets confirmés et le rollback. Un résultat ambigu conserve son propriétaire et exige une vérification administrative.

`release()` supprime uniquement la ligne du jeton détenu puis libère le verrou consultatif. `abandon()` garde les métadonnées et libère seulement le verrou consultatif pour une réconciliation ultérieure. Ces deux méthodes refusent de libérer une session dont la transaction native est encore ouverte. Aucun destructeur ne libère automatiquement le verrou pendant un traitement. Si la transaction reste ouverte en fin de requête, conserver le verrou jusqu'à la fermeture de connexion évite d'autoriser une seconde requête avant son rollback.

### Moteurs et limites

Les variantes `mysqli` (MySQL/MariaDB) et `pgsql` sont explicites. Les autres moteurs refusent l'acquisition ; il n'existe aucun repli silencieux sur une simple ligne expirée. Un éventuel support supplémentaire devra apporter une garantie d'exclusion équivalente et des tests de concurrence.

Les connexions MySQL persistantes (`p:`) sont refusées. Les pools PostgreSQL en mode transaction/statement et toute réaffectation de session pendant la requête sont incompatibles : utiliser une session physique stable pendant toute la requête. Aucun autre module ne doit libérer les verrous consultatifs du module. Les architectures MySQL multi-primaires où les requêtes d'une même instance arrivent sur des serveurs différents ne sont pas couvertes par un verrou consultatif local au serveur.

### Tests exécutés

`php test/locks.php --mysql` exécute 53 contrôles sur MariaDB, dont deux processus PHP concurrents avec jetons identiques puis différents, visibilité des métadonnées, commit/rollback natifs, expiration pendant activité, isolation d'entité, sortie sans libération, récupération conditionnelle et perte de propriété. Le test crée des tables partagées sous un préfixe aléatoire `tpg_locktest_<12 caractères hexadécimaux>_`, et les supprime à la fin. Il exige des droits CREATE/DROP sur ce namespace ; aucune table native ni donnée de paiement n'est modifiée. Si le processus principal du test est tué brutalement, vérifier puis supprimer uniquement les trois tables de ce préfixe précis (facture de fixture, verrou et historique).

`php test/lock_dialects.php` exécute 11 contrôles de la branche PostgreSQL et des refus de moteurs avec un double DoliDB. Ce n'est pas un test sur un serveur PostgreSQL ; l'intégration et la concurrence sur PostgreSQL restent à exécuter avant d'annoncer cette variante comme certifiée.

## Interception serveur (étape 4)

`ActionsTakeposguard::doActions()` intervient uniquement dans le contexte `takeposinvoice`, pour `action=valid` et lorsque `TAKEPOSGUARD_ENABLE=1`. Les autres contextes/actions et l’option désactivée retournent `0` sans lecture métier ni acquisition de verrou. Le hook conserve les contrôles CSRF de `main.inc.php` ; le jeton d’idempotence ne remplace jamais le jeton CSRF natif.

L’utilisateur doit être interne, authentifié et disposer de `takepos/run` et `facture/creer`, droits utilisés par le chemin natif. Les droits du module restent réservés à l’audit et à la maintenance ; ils ne permettent jamais de contourner la protection. La facture doit exister, appartenir exactement à l’entité courante et avoir `module_source=takepos`.

Après validation stricte du UUID v4, le hook acquiert le verrou avant la transaction native. Seul `ACQUIRED` autorise la suite. Sous exclusion, il recherche le propriétaire du jeton dans l’entité, recharge l’objet facture et crée la tentative `PROCESSING` avec l’instantané natif du statut, du reste et des paiements. Les modes et montants reçus restent des données d’audit, sans autoriser le paiement. Le hook retourne alors `0` et conserve son verrou pendant toute l’action native, sans ouvrir ni terminer de transaction.

Tout jeton déjà enregistré est refusé, y compris `FAILED` et `BLOCKED` : une nouvelle tentative volontaire exige un nouveau jeton. Un jeton lié à une autre facture dans l’entité est refusé sans divulguer cette facture. La contrainte unique reste la dernière protection contre deux insertions concurrentes d’un même jeton sur des factures différentes. Aucun paiement, mouvement bancaire ou mouvement de stock n’est créé par le module.

Un refus retourne `1`, remplace l’action native, publie un message traduit et affiche un fragment HTML échappé dans la réponse AJAX. Les refus avant insertion ne créent pas de ligne d’historique, pour éviter une accumulation de tentatives invalides. Les logs détaillés contiennent uniquement des codes stables, jamais jeton, montant, utilisateur, SQL ou exception brute.

Une tentative acceptée reste `PROCESSING` pendant le traitement natif. Le hook après transaction confirme son résultat et libère le verrou. Une interruption utilise la récupération décrite à l’étape 7 ; aucun nettoyage manuel des lignes `PROCESSING` ne doit servir à contourner la protection.

`php test/interception.php` vérifie l’orchestration avec doubles : option désactivée, autres contextes/actions, droits, facture/entité/origine, jetons invalides et rejoués, refus d’un jeton d’une autre facture, verrou occupé/erreur/récupération, rechargement natif, erreurs de stockage et maintien du verrou avant retour au cœur. Les tests de stockage et de concurrence vérifient séparément les primitives SQL réelles. Ces contrôles ne constituent pas un test HTTP de paiement ni une preuve du nombre de mouvements bancaires/stock ; ceux-ci restent à exécuter après les étapes suivantes.

## Paiements partiels et stock (étape 5)

Après acquisition du verrou et rechargement de la facture, `TakeposguardPaymentPolicy` n’admet que les états natifs brouillon (`0`) ou validé (`1`). Une facture validée doit avoir un reste positif, ou négatif pour un avoir, calculé par `getRemainToPay()` ; le montant JavaScript ne participe jamais à cette décision. Les états payé/clôturé, abandonné, inconnu et les erreurs de calcul sont refusés avant la création d’une tentative. Un nouveau jeton distingue un paiement volontaire du rejeu ; le verrou et la contrainte unique restent obligatoires.

Sans gestion des lots, TakePOS ne déstocke que dans sa branche de validation du brouillon : le module conserve ce traitement natif. Avec `stock` et `productbatch` actifs, le cœur 22.x exécute sa boucle manuelle même sur une facture déjà validée. Le module évite cette répétition en positionnant uniquement dans la mémoire de la requête `CASHDESK_NO_DECREASE_STOCK<terminal>=1` pour un paiement ultérieur. Cela protège aussi les lignes sans lot lorsque le module lots est actif. Le brouillon initial n’est jamais concerné par cette surcharge.

La surcharge exige une preuve en base, dans l’entité et pour la facture : une tentative `SUCCESS`, avec statut initial brouillon, statut final validé/payé et date de finalisation renseignée. Une tentative `PROCESSING`, `FAILED`, `BLOCKED` ou un paiement partiel réussi sur une facture déjà validée ne suffit pas. L’erreur SQL bloque également la requête. Le réglage natif qui désactive déjà le déstockage est respecté sans demander cette preuve.

**Factures anciennes et historique :** en mode lots avec déstockage TakePOS actif, une facture validée sans preuve de validation initiale par le module est refusée. Ni un paiement existant ni la présence isolée d’un mouvement ne prouvent que toutes les lignes ont été traitées. Le module ne supprime aucun mouvement et ne tente pas de réparer des historiques modifiés hors de son contrôle. Une procédure de réconciliation pour les factures anciennes reste à définir ; ne pas fabriquer de lignes `SUCCESS`. La maintenance conserve cette preuve initiale, y compris après purge des détails.

Le hook `completeTakePosInvoiceHeader`, exécuté après le commit/rollback natif dans le cœur local 22.0.5 inspecté, restaure la valeur d’origine pour la facture protégée. Une sortie anticipée utilise un callback shutdown qui restaure seulement cette configuration en mémoire, y compris si la propriété était absente ou `null`. Le callback de finalisation ne travaille que si la connexion est encore ouverte, la transaction terminée et le verrou détenu. Il ne déduit jamais un succès de la seule fin de requête. Aucune constante persistante ni configuration d’une autre requête n’est modifiée.

La finalisation de l’étape 7 produit maintenant cette preuve de validation initiale et permet un nouveau paiement partiel volontaire avec un nouveau jeton. Les fixtures de stockage vérifient aussi cette transition.

Tests exécutés : `php test/interception.php` (51 contrôles), `php test/storage.php --mysql` (64 contrôles), `php test/partial_stock.php` (8 contrôles). Le test MariaDB utilise le hook, le verrou, l’historique et le calcul natif du solde sur tables temporaires. Le dernier test extrait le bloc de stock de `takepos/invoice.php` installé et l’exécute avec des doubles des classes d’écriture : deux appels initiaux, quantités des lignes conservées, zéro appel pour le paiement partiel protégé, restauration après sortie PHP. Il échoue si les bornes du bloc natif changent et exige alors une nouvelle inspection du cœur. Il ne crée aucun mouvement réel et ne vérifie pas les effets internes de `MouvementStock::livraison()`.

Les tests HTTP complets de paiement, banque, stock avec/sans lots et rollback restent à exécuter après finalisation sur une instance de recette. Le cœur local est 22.0.5 ; les constantes, la boucle native et l’ordre des hooks doivent être revérifiés lors d’une montée de version.

## Protection JavaScript (étape 6)

Le descripteur conserve son fichier déclaré `js/takeposguard.js.php`, chargé par `top_htmlhead()` sur les pages TakePOS. Ce point d’entrée authentifié lit la configuration et les traductions de l’entité courante, sans renouveler le jeton CSRF. Sa réponse est privée et non mise en cache. Si le module/l’option est désactivé ou si le droit TakePOS manque, il ne livre aucun code de protection. Le fichier JavaScript statique associé reste inerte sans cette configuration et ne s’installe que sur `takepos/index.php` et `takepos/pay.php`.

Le script enveloppe les fonctions natives `Validate`, `DirectPayment`, `ValidateStripeTerminal` et `ValidateSumup`, en conservant leurs arguments, leur contexte et leur retour. Le premier appel crée la tentative puis désactive les commandes de paiement ; les appels suivants sont ignorés. La page et sa fenêtre de paiement partagent le même état dans la page parente. Les boutons ajoutés dynamiquement, notamment le terminal Stripe, sont également désactivés. Un message traduit et accessible indique le traitement.

Le préfiltre est installé une seule fois par instance jQuery, y compris le jQuery parent utilisé par `parent.$('#poslines').load(...)`. Il ne modifie que les requêtes de même origine vers le chemin exact `takepos/invoice.php` avec `action=valid`, transmis dans l’URL ou les données GET/POST. Les requêtes de produits, de lignes, de banque ou de prestataire ne reçoivent aucun jeton du module. La seule observation supplémentaire porte sur la réponse native `smpcb.php?status` pour afficher un état à vérifier quand SumUp rapporte un échec.

Un UUID v4 provient de `crypto.randomUUID()` ou de `crypto.getRandomValues()` avec les bits de version/variante requis. Aucun repli `Math.random()` n’est utilisé. Sans génération cryptographique, le clic est refusé. Un UUID valide fourni par une intégration est conservé ; les doublons du paramètre sont éliminés et le jeton CSRF natif reste intact. La facture provisoire peut être identifiée par sa place avant que PHP fournisse son identifiant ; cette résolution conserve le même jeton.

Le navigateur conserve uniquement le jeton, l’identité facture/place et le type de prestataire dans `sessionStorage`, sous une clé liée au chemin d’installation, à l’entité et au terminal. Cela préserve l’incertitude après rechargement dans le même onglet. Si le stockage navigateur est indisponible, la coordination en mémoire continue mais ne survit pas au rechargement ; le serveur reste la protection indispensable contre le rejeu et la concurrence. Les onglets distincts ne partagent pas ce verrou JavaScript.

Un statut HTTP 200, l’absence d’un message d’erreur ou l’expiration d’un délai ne prouvent pas le commit. Le serveur émet une réponse portant `X-Takeposguard-Status: SUCCESS|FAILED` et `X-Takeposguard-Token` égal au jeton envoyé, après constat serveur du résultat. `SUCCESS` autorise une nouvelle tentative avec un nouveau jeton ; `FAILED` fait de même uniquement pour un paiement ordinaire. Un délai de 600 ms après ce résultat bloque le deuxième clic d’une réponse très rapide. La capture des clics multiples protège aussi les commandes sous forme de liens.

En cas de réponse non confirmée ou de panne réseau, le jeton reste conservé. Pour un paiement ordinaire et tant que la requête reste disponible en mémoire, une commande explicite permet de retenter exactement ses paramètres avec le même jeton. Aucun rejeu automatique n’est effectué. Après un rechargement complet, la requête n’est pas persistée : la commande « Vérifier le résultat » consulte et, après expiration si nécessaire, réconcilie la tentative sans relancer de paiement. Un jeton inconnu, un traitement actif ou un résultat ambigu conserve le blocage.

### Stripe et SumUp

L’enveloppe empêche les doubles appels avant le lancement du prestataire et ajoute le jeton à leur requête finale vers TakePOS. Un échec confirmé de collecte Stripe, avant le traitement du paiement et avant tout appel de facture, permet une nouvelle tentative. Le callback natif SumUp utilise un statut partagé dans la session PHP, sans rattachement au UUID ou à la facture : même `FAILED` conserve donc un état à vérifier et n’autorise pas automatiquement un nouveau débit. Une erreur de traitement/capture Stripe, une interruption ou un échec natif après un débit prestataire garde l’état incertain : aucun nouveau débit prestataire ni rejeu de son lancement n’est autorisé automatiquement. Le délai visuel ne libère jamais cette protection.

Le module ne transmet pas de clé d’idempotence aux API Stripe/SumUp et ne garantit pas l’unicité du débit externe, notamment entre onglets, appareils ou après perte du stockage navigateur. Ces garanties exigent une intégration côté prestataire. Vérifier le paiement chez le prestataire avant toute réconciliation manuelle ; ne pas effacer un jeton incertain pour relancer un débit.

### Vérification 2

`node --check js/takeposguard.js` et `node test/javascript.cjs` : 45 contrôles avec doubles DOM/jQuery, sans dépendance supplémentaire. Ils couvrent les doubles appels, le paiement direct, le jQuery parent, les GET/POST, les URL exclues, le CSRF, le UUID cryptographique de repli, les jetons fournis, la facture provisoire, les réponses incertaines, le rejeu contrôlé, le rechargement, les résultats corrélés et les prestataires. Les en-têtes et réponses JSON sont simulés dans ces tests ; la récupération après rechargement, le CSRF du POST et les refus de résultats non corrélés sont couverts.

Les contrôles PHP, stockage et concurrence restent exécutés séparément. Aucun navigateur authentifié, terminal Stripe réel, application SumUp ou paiement HTTP réel n’a été testé à cette étape. La recette visuelle devra vérifier les boutons natifs et dynamiques, le clavier, le modal après paiement partiel, une coupure réseau et le rétablissement après finalisation. Les intégrations qui remplacent les fonctions natives ou utilisent `fetch`/XHR sans jQuery doivent ajouter leur propre jeton stable ; la vérification serveur refuse tout jeton absent.

## Finalisation et récupération (étape 7)

`completeTakePosInvoiceHeader` est exécuté après le commit/rollback de l’action `valid` dans le cœur inspecté. La finalisation vérifie la connexion, une profondeur de transaction nulle et la propriété du verrou pour la facture et le jeton exacts. Elle recharge le statut, le reste à payer et les paiements liés de l’entité. Elle n’ouvre aucune transaction, ne crée aucun paiement et ne modifie aucun mouvement.

Pour une facture initialement brouillon, le passage au statut validé/payé confirme la validation. Pour une facture déjà validée, une diminution du reste à payer ou un nouveau paiement lié de signe cohérent confirme le paiement partiel, y compris un avoir. L’absence de changement de statut, de solde et de paiement correspond à `FAILED` avec le code `NativeNoCommittedEffect`. Les paiements multiples/supprimés, un changement de statut inattendu ou des effets contradictoires deviennent `BLOCKED` (`ReconciliationRequired`). Une erreur de lecture/écriture laisse la tentative récupérable, sans annoncer de résultat au navigateur. L’erreur native détaillée n’est pas déduite de son HTML.

Le résultat persistant et le reste après traitement sont enregistrés avant la libération du verrou. Un fragment natif est mis en tampon lorsque PHP le permet pour émettre `X-Takeposguard-Status` et `X-Takeposguard-Token` après constat du résultat. Si les en-têtes ont déjà été envoyés par un autre module, le résultat reste en base et peut être consulté avec « Vérifier le résultat ». Le rejeu d’un jeton finalisé reste refusé mais transmet son résultat confirmé, sans exécuter l’action native une deuxième fois.

**Ordre shutdown :** `main.inc.php` enregistre `dol_shutdown` avant les callbacks des modules. Cette fonction ferme la connexion native et une transaction ouverte est alors annulée par le moteur. Le callback du module restaure la configuration locale du stock, mais ne tente pas de rouvrir la connexion ni de confirmer un résultat sur une session fermée. L’historique et le propriétaire restent présents jusqu’à récupération après expiration. Un processus qui détient encore le verrou consultatif ne peut jamais être remplacé, même si le délai est dépassé.

Après expiration, l’interception ou le service de récupération reprend le verrou consultatif, constate les effets de l’ancien propriétaire et finalise son historique. Seuls `SUCCESS` et `FAILED` permettent de remplacer le propriétaire expiré ; un verrou acquis avant l’insertion de toute tentative est également récupérable. `BLOCKED` interdit la reprise automatique. Les jetons anciens restent inutilisables pour exécuter un paiement. Ne pas supprimer l’historique ou le verrou pour forcer une reprise ambiguë : vérifier facture, paiements, banque, stock et prestataire ; la page de maintenance permet uniquement une récupération confirmée, sans libération forcée.

`ajax/attempt.php` accepte uniquement un POST authentifié `action=recover`, avec le jeton CSRF natif et le UUID de tentative. Il vérifie l’activation, les droits TakePOS/création de facture, l’entité et l’auteur de la tentative ; un administrateur ou un utilisateur avec `maintenance/write` peut consulter/récupérer celle d’un autre utilisateur. Aucun droit d’audit n’est requis au caissier pour sa propre tentative. Aucun identifiant de facture fourni par le navigateur n’est utilisé. La réponse privée ne contient que le UUID et un statut. `UNKNOWN` ne permet pas une nouvelle tentative : la requête initiale pourrait être retardée avant même son arrivée au serveur.

Une récupération ne débite jamais Stripe/SumUp. `FAILED` côté Dolibarr laisse le blocage du navigateur pour un prestataire, puisque le débit externe peut avoir réussi. Les opérations provenant d’autres endpoints ou des modifications manuelles qui ignorent le verrou ne peuvent pas être attribuées avec certitude à cette tentative. Tous les accès concurrents au paiement TakePOS doivent passer par l’action protégée ; les tables natives doivent être transactionnelles.

### Tests exécutés et recette restante

- `php test/finalization.php` : 23 décisions et refus, notamment paiement partiel/avoir, effets ambigus, session fermée, transaction ouverte, propriété perdue et erreurs de stockage.
- `php test/storage.php --mysql` : 166 contrôles sur tables temporaires, avec commit, rollback, finalisation au hook, attribution du paiement, expiration, résultat ambigu, récupération par statut et droits auteur/maintenance.
- `php test/locks.php --mysql` : 53 contrôles avec processus réellement concurrents et fermeture PHP après commit ou avec transaction ouverte, dans un préfixe de tables de test aléatoire. Le traitement de validation est simulé par SQL dans ces scénarios d’interruption ; aucun paiement/stock Dolibarr réel n’est exécuté.
- `node test/javascript.cjs` : 45 contrôles, notamment récupération explicite après rechargement sans rejeu, POST CSRF, jeton corrélé et maintien du blocage prestataire après `FAILED` natif.

Sur une instance de recette, activer la protection et exécuter deux appels HTTP parallèles à `invoice.php?action=valid` pour la même facture, avec jeton identique puis distinct. Vérifier une seule validation/paiement/écriture bancaire/série de mouvements, puis rejouer le premier jeton et payer volontairement le solde avec un autre. Répéter sans lots et avec lots. Provoquer une erreur stock/banque/paiement et vérifier le rollback natif, l’historique `FAILED` et le verrou libéré ; interrompre avant/après commit et vérifier la récupération après expiration. Tester l’option désactivée et deux entités. Vérifier également le POST de récupération avec CSRF absent/invalide et utilisateur tiers. Aucun test HTTP authentifié, prestataire réel ni serveur PostgreSQL n’a été exécuté localement ; ces essais ne sont pas remplacés par les fixtures.

## Audit et maintenance (étapes 8 et 9)

Après mise à jour, désactiver puis réactiver **le module** depuis Configuration > Modules/Applications pour installer les menus, la nouvelle constante et la tâche planifiée. Faire cette opération hors encaissement. La constante `TAKEPOSGUARD_ENABLE` et les historiques sont conservés ; aucun changement de schéma SQL n’est requis pour passer de 0.7 à 0.9.

Les outils d’administration proposent `audit.php` pour un utilisateur avec `audit/read` et la maintenance pour un utilisateur avec `maintenance/write`. Les administrateurs ont accès aux deux. Les utilisateurs externes sont refusés. Ces droits restent distincts des droits de paiement : un caissier peut bénéficier de la protection sans lire l’historique général ni administrer les verrous. Les contrôles sont exécutés sur chaque page, même lors d’un accès direct par URL.

L’audit affiche date, facture, terminal, utilisateur, mode, montant demandé et constaté, statut traduit, UUID tronqué, soldes avant/après et code/message d’erreur. Les filtres portent sur le statut et l’identifiant de facture ; la pagination est limitée à 50 lignes par page, avec requête SQL bornée. Les références de facture et comptes utilisateur sont chargés par jointure ; toutes les valeurs affichées sont échappées. Un montant demandé est une information d’audit, sans preuve de paiement. Les montants utilisent la devise principale de la facture.

`admin/maintenance.php` liste les verrous de l’entité, leur date d’expiration et l’état de la tentative. « Réconcilier et libérer » exige un POST avec CSRF et le propriétaire exact affiché. Une requête encore active, un propriétaire changé, un verrou non expiré, un résultat `BLOCKED` ou une lecture impossible ne peut pas être forcé. Après expiration et seulement en absence de propriétaire actif, les effets sont réconciliés avant suppression du verrou. Un verrou sans tentative est récupérable parce que l’action native n’avait pas été autorisée. La protection doit être activée pour effectuer cette récupération : sinon les paiements natifs concurrents ne respecteraient pas le verrou. Les paiements externes doivent être vérifiés séparément. Les accès n’exécutent aucun paiement ni mouvement de stock.

« Purger les détails anciens » traite au maximum 500 tentatives par appel, selon `TAKEPOSGUARD_HISTORY_DAYS`. Seuls les résultats `SUCCESS`/`FAILED` dont la création et la finalisation sont anciennes sont éligibles. Les factures ayant encore un verrou sont exclues, ainsi que toutes les tentatives récentes, `PROCESSING` et `BLOCKED`. Le terminal, le mode, les montants demandé/constaté, l’IP, l’agent navigateur et le message d’erreur sont effacés. Les champs techniques minimaux, notamment UUID, entité/facture/auteur, résultat, dates, soldes et preuves de validation initiale, **restent persistants**. Il s’agit donc d’une purge de détails, pas d’une suppression de lignes : effacer la clé d’idempotence permettrait de rejouer une ancienne requête. La preuve conservée permet les paiements partiels avec lots. Répéter la commande pour un historique dépassant 500 résultats.

Une tâche **CronJob Dolibarr native**, quotidienne et désactivée par défaut, appelle le même nettoyage borné. Activer le module Tâches planifiées, choisir un compte interne administrateur ou disposant de `maintenance/write`, puis activer la tâche « Purger les détails anciens TakePOS Payment Guard ». Chaque exécution traite uniquement l’entité de la tâche. Elle ne libère aucun verrou et ne résout aucune ambiguïté ; les reprises expirées sont traitées par l’interception, la commande caissier ou la maintenance contrôlée. Sans module Cron actif, le nettoyage manuel reste disponible. Ne pas appeler une méthode de purge par une commande SQL générale.

`TAKEPOSGUARD_MAX_ATTEMPTS` limite les jetons persistants par facture, défaut 1000 (10 à 9999). L’interception vérifie ce plafond sous le verrou avant d’autoriser le traitement natif ; une erreur de lecture bloque aussi l’insertion. Les jetons purgés de leurs détails restent comptés. Le réglage ne s’applique pas quand la protection est désactivée. Il ne constitue pas un quota global contre un utilisateur autorisé à créer un grand nombre de factures. Une augmentation se fait dans la configuration, après examen de la facture et de l’historique ; ne pas effacer des jetons pour contourner le plafond.

### Vérifications exécutées

`php test/audit.php` : 10 contrôles des droits distincts, des comptes externes/non authentifiés, de la navigation et de l’échappement. `php test/storage.php --mysql` : 166 contrôles, dont audit paginé, filtre invalide, récupération expirée, verrou actif malgré expiration, propriétaire modifié, résultat ambigu, nettoyage sélectif, conservation des clés et du stock, multientité, plafond de tentatives, lots de 500 et droits de la tâche planifiée. Les contrôles de configuration comprennent 22 cas et le statut désactivé de la tâche. Les tests de concurrence, de finalisation, de JavaScript, de stock natif avec doubles et de conversion SQL PostgreSQL restent exécutés.

La recette HTTP authentifiée doit encore vérifier l’activation/réactivation des menus et de la tâche, les deux droits séparément, l’accès direct interdit, les POST avec CSRF invalide, la pagination, les références contenant du HTML, les confirmations et refus de maintenance, et l’exécution d’une tâche dans deux entités. Aucun navigateur authentifié ni serveur PostgreSQL n’a été utilisé pour cette étape. L’étape 10 ajoute désormais les paiements, écritures bancaires et mouvements de stock natifs en CLI, ainsi que le contrôle des sources. La qualification HTTP et prestataires reste nécessaire avant production.

## Recette automatisée (étape 10)

Depuis le dossier du module, avec PHP CLI et Node.js disponibles :

```sh
php test/run.php
php test/run.php --mysql
```

La première commande vérifie la syntaxe PHP/JavaScript, les doubles de test et le contrat des sources du cœur installé. Elle annonce explicitement que les effets en base n'ont pas été testés. La seconde ajoute les tests de stockage, de verrouillage concurrent et les **effets natifs réels**. Un échec arrête la commande avec un code non nul. Elle ne débite aucun prestataire et n'appelle pas les pages HTTP de paiement.

`--mysql` utilise la connexion Dolibarr de `conf/conf.php`, via DoliDB, et exige le pilote `mysqli` ainsi que les droits de création/suppression de tables. Utiliser une instance de recette : les fixtures créent leurs propres tables à préfixe aléatoire, puis les suppriment dans un `finally`. Elles ne changent ni les ventes ni les constantes persistantes. La fixture native refuse les références SQL à des tables applicatives hors de son préfixe et désactive la génération PDF. Une interruption brutale du processus principal peut laisser des tables `tpg_native_<12 caractères hexadécimaux>_*` ou `tpg_locktest_*` : vérifier le préfixe exact et l'absence de processus de test avant leur nettoyage, sans toucher aux tables métier.

`test/native_acceptance.php` extrait sans réécriture le bloc `valid` du fichier `takepos/invoice.php` installé. Il l'exécute entre les hooks du module avec les classes natives de facture, paiement, banque et stock sur les schémas natifs isolés. Les workers utilisent deux connexions et une barrière : les deux factures sont chargées brouillon avant que le processus autorisé ne termine. Les quantités d'entrepôt et de lot sont vérifiées, en plus du nombre d'écritures. Les erreurs de banque/stock sont natives ; l'erreur de liaison paiement est injectée après l'insertion native pour contrôler son rollback. Les interruptions PHP avant et après commit reproduisent l'ordre du shutdown Dolibarr.

Ce scénario ne remplace pas le dispatch HTTP, l'authentification, le CSRF de bout en bout, le rendu ni les modules tiers : le bootstrap fournit un utilisateur et une configuration de test. Les schémas métier utilisent leurs colonnes natives, sans installer toutes leurs clés étrangères. Le runner ne lance aucun encaissement sur les factures de l'instance.

```sh
php test/compatibility.php /chemin/vers/une/autre/version/htdocs
```

Ce contrôle en lecture seule vérifie les points d'accroche, leur ordre, les bornes du bloc de paiement, les branches stock, les fonctions JavaScript, les statuts de facture et les compteurs transactionnels. Il affiche l'empreinte du bloc natif pour tracer une mise à jour. Il échoue si son contrat change et impose alors une nouvelle inspection ; réussir ce contrôle structurel ne certifie pas une version.

Le [rapport exécuté](docs/acceptance-report.md) indique les résultats et les limites. La [procédure HTTP A–J](docs/http-acceptance.md) complète la qualification sur une instance dédiée, avec deux sessions PHP distinctes pour tester une concurrence réelle. La mise à jour 0.9.0 → 0.10.0 ne change ni les tables, ni les menus, ni les droits ; copier les fichiers suffit. La protection reste désactivée par défaut sur une nouvelle installation.

## Licence

GPL v3 ou ultérieure ; voir COPYING.
