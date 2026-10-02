# TakePOS Payment Guard

Module externe indépendant installé dans `htdocs/custom/takeposguard`, sans modification du cœur Dolibarr.

## État de la version 0.5.0

Cette version implémente les points 1 à 5 : configuration, stockage, verrou exclusif, interception serveur et protection du stock lors des paiements partiels. **Elle reste intermédiaire et ne doit pas être activée pour un usage normal.** L’option désactivée conserve l’action native. Si elle est activée, un UUID v4 est obligatoire : le JavaScript déclaré ne fournit pas encore de jeton, donc l’écran natif sans intégration adaptée est bloqué. Une tentative acceptée reste `PROCESSING` jusqu’à l’implémentation de la finalisation ; aucun succès n’est déduit en fin de requête. Aucun trigger n’est ajouté.

## Installation

1. Copier le dossier dans `htdocs/custom/takeposguard`.
2. Utiliser Dolibarr 22.0.4 ou supérieur et PHP 7.2 ou supérieur, en respectant également les prérequis de Dolibarr.
3. Activer le module dans Configuration > Modules/Applications. La dépendance TakePOS est déclarée.
4. Ouvrir sa configuration avec un compte administrateur.

La version locale inspectée est 22.0.5. La compatibilité du traitement de paiement avec les versions ultérieures sera vérifiée aux étapes suivantes.

Pour une installation déjà activée en 0.1.0, désactiver puis réactiver le module afin de charger les nouvelles tables. La configuration reste conservée. Les tables et l'historique ne sont pas supprimés lors de la désactivation.

## Configuration par entité

| Constante | Défaut | Valeurs |
| --- | --- | --- |
| `TAKEPOSGUARD_ENABLE` | `0` | `0` ou `1` |
| `TAKEPOSGUARD_LOCK_TIMEOUT` | `120` | 10 à 3600 secondes |
| `TAKEPOSGUARD_HISTORY_DAYS` | `90` | 1 à 3650 jours |
| `TAKEPOSGUARD_DEBUG_LOG` | `0` | `0` ou `1` |
| `TAKEPOSGUARD_MISSING_TOKEN_POLICY` | `reject` | Refus uniquement |

L’option commande maintenant l’interception serveur. Les intégrations tierces devront fournir un jeton stable par tentative ; aucun contournement silencieux ne sera proposé.

Les écritures utilisent les API natives, une transaction et l'entité courante. Le formulaire accepte uniquement POST et conserve la vérification CSRF native. Une valeur invalide empêche l'enregistrement de tout le formulaire. La configuration est conservée lors de la désactivation/réactivation.

## Identifiants et droits

Numéro local : `501117`. Droits : `50111701` (`audit/read`) et `50111702` (`maintenance/write`). Ils ne donnent aucun droit de paiement et ne conditionnent pas la protection serveur. La configuration reste réservée aux administrateurs.

Les identifiants ont été contrôlés dans les descripteurs locaux et en lecture seule dans `rights_def`. Cela ne constitue pas une réservation mondiale : vérifier les collisions sur chaque instance avant installation. Les pages d'audit et de maintenance viendront ultérieurement.

## Vérification

Exécuter `php -l` sur les fichiers PHP et `php test/configuration.php`. Sur une instance de test, vérifier également l'activation, les soumissions administrateur/non-administrateur, les jetons CSRF invalides, les valeurs invalides, la conservation après réactivation et l'isolation entre entités.

Pour le stockage, exécuter `php test/storage.php --mysql` depuis le dossier du module. Le test lit la configuration de connexion locale et utilise exclusivement des tables temporaires propres à la connexion, avec le préfixe `tpg_test_`. Il ne modifie pas les données Dolibarr. Le compte de base doit pouvoir créer des tables temporaires. La connexion fermée, ces tables disparaissent. L'hydratation complète de `Facture::fetch()` est remplacée par une fixture minimale ; le calcul natif du reste à payer et les requêtes du stockage sont réellement exécutés.

`php test/sql_portability.php` vérifie la conversion des scripts par le pilote PostgreSQL Dolibarr, sans connexion PostgreSQL. Ce contrôle ne remplace pas un test d'installation et d'exécution sur ce moteur.

Résultats exécutés localement : 64 contrôles de schéma/stockage sur MariaDB via le pilote `mysqli`, 18 cas de configuration et contrôles du descripteur, conversion de neuf instructions SQL par le pilote PostgreSQL. L'activation réelle dans l'interface n'a pas été exécutée.

Les tests de concurrence du verrou sont décrits ci-dessous. Les tests de paiement et stock après interception appartiennent aux étapes suivantes. Cette version ne doit pas être utilisée comme protection en production.

## Stockage des tentatives et verrous

L'activation charge les fichiers `sql/llx_takeposguard*.sql` et leurs clés via `_load_tables()`. Les scripts suivent la syntaxe des modules externes Dolibarr ; le préfixe réel est substitué par l'installateur et PostgreSQL utilise la conversion native. Un échec de chargement SQL empêche l'activation.

- `takeposguard_payment_attempt` conserve le jeton UUID v4 canonique, la facture, l'entité, l'utilisateur, le terminal, les données demandées, les instantanés avant/après, les références de paiement, les dates et les erreurs bornées. Les états admis par la bibliothèque sont `PROCESSING`, `SUCCESS`, `FAILED`, `BLOCKED`.
- Une clé unique couvre `(entity, fk_invoice, operation_token)`. Une seconde couvre `(entity, operation_token)` : le même jeton ne peut pas être affecté à une autre facture dans la même entité. Il peut être réutilisé dans une autre entité.
- `takeposguard_invoice_lock` conserve le propriétaire logique et l'expiration, avec unicité `(entity, fk_invoice)`. `TakeposguardStorage` permet de lire ces métadonnées ; `TakeposguardLock` assure l'exclusion décrite ci-dessous.

`TakeposguardStorage` utilise la connexion DoliDB fournie et fixe son périmètre à l'entité courante lors de sa construction. `createProcessing()` recharge une facture TakePOS de cette entité et capture le reste à payer par les API natives. Le nombre et la dernière référence des paiements sont également mémorisés. `fetchAttempt()` distingue absence (`null`) et erreur (`false`, code technique dans `error`).

`completeAttempt()` stocke un résultat confirmé par son appelant et ne remplace qu'un état `PROCESSING`. Le reste et le statut sont rechargés ; le montant réel provient du lien de paiement en base, jamais du montant demandé. Un paiement référencé doit appartenir à la même entité et à la facture concernée. Une tentative finalisée ne peut pas être réécrite avec cette méthode.

La bibliothèque n'ouvre ni ne termine de transaction. Le code appelant devra vérifier les droits et détenir le verrou exclusif avant création/finalisation ; l’interception applique ces conditions avant la création ; la finalisation reste à implémenter. Le statut `SUCCESS` n'est pas déduit automatiquement par la couche de stockage. Les modes et montants demandés sont des informations d'audit, sans pouvoir d'autoriser un paiement.

IP et user-agent sont facultatifs, validés et limités ; les messages d'erreur doivent être techniques et sans données sensibles. La bibliothèque n'écrit aucun log contenant les requêtes ou données de paiement. Les index couvrent la facture/date, l'état/date, la date de finalisation et l'expiration. Les références métier n'ont pas de suppression en cascade, pour préserver l'audit. Aucun nettoyage automatique ni limite de tentatives n'est activé à ce stade ; ces contrôles viendront avec l'interception et la maintenance.

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

Après une interruption, la fermeture de la connexion libère le verrou consultatif ; les métadonnées persistent. Tant qu'elles ne sont pas expirées, les nouvelles tentatives restent bloquées. Après expiration, `RECOVERY_REQUIRED` expose le jeton précédent via `previousToken`. L'appelant devra réconcilier la tentative et les effets natifs sous le verrou avant `confirmRecovery($expectedToken)`. Cette méthode remplace uniquement le propriétaire attendu encore expiré. La réconciliation automatique et le secours shutdown pour les tentatives/verrous ne sont pas implémentés à cette étape. Le seul callback shutdown ajouté restaure la configuration locale du stock.

`release()` supprime uniquement la ligne du jeton détenu puis libère le verrou consultatif. `abandon()` garde les métadonnées et libère seulement le verrou consultatif pour une réconciliation ultérieure. Ces deux méthodes refusent de libérer une session dont la transaction native est encore ouverte. Aucun destructeur ne libère automatiquement le verrou pendant un traitement. Si la transaction reste ouverte en fin de requête, conserver le verrou jusqu'à la fermeture de connexion évite d'autoriser une seconde requête avant son rollback.

### Moteurs et limites

Les variantes `mysqli` (MySQL/MariaDB) et `pgsql` sont explicites. Les autres moteurs refusent l'acquisition ; il n'existe aucun repli silencieux sur une simple ligne expirée. Un éventuel support supplémentaire devra apporter une garantie d'exclusion équivalente et des tests de concurrence.

Les connexions MySQL persistantes (`p:`) sont refusées. Les pools PostgreSQL en mode transaction/statement et toute réaffectation de session pendant la requête sont incompatibles : utiliser une session physique stable pendant toute la requête. Aucun autre module ne doit libérer les verrous consultatifs du module. Les architectures MySQL multi-primaires où les requêtes d'une même instance arrivent sur des serveurs différents ne sont pas couvertes par un verrou consultatif local au serveur.

### Tests exécutés

`php test/locks.php --mysql` exécute 39 contrôles sur MariaDB, dont deux processus PHP concurrents avec jetons identiques puis différents, visibilité des métadonnées, commit/rollback natifs, expiration pendant activité, isolation d'entité, sortie sans libération, récupération conditionnelle et perte de propriété. Le test crée des tables partagées sous un préfixe aléatoire `tpg_locktest_<12 caractères hexadécimaux>_`, et les supprime à la fin. Il exige des droits CREATE/DROP sur ce namespace ; aucune table native ni donnée de paiement n'est modifiée. Si le processus principal du test est tué brutalement, vérifier puis supprimer uniquement les deux tables de ce préfixe précis.

`php test/lock_dialects.php` exécute 11 contrôles de la branche PostgreSQL et des refus de moteurs avec un double DoliDB. Ce n'est pas un test sur un serveur PostgreSQL ; l'intégration et la concurrence sur PostgreSQL restent à exécuter avant d'annoncer cette variante comme certifiée.

## Interception serveur (étape 4)

`ActionsTakeposguard::doActions()` intervient uniquement dans le contexte `takeposinvoice`, pour `action=valid` et lorsque `TAKEPOSGUARD_ENABLE=1`. Les autres contextes/actions et l’option désactivée retournent `0` sans lecture métier ni acquisition de verrou. Le hook conserve les contrôles CSRF de `main.inc.php` ; le jeton d’idempotence ne remplace jamais le jeton CSRF natif.

L’utilisateur doit être interne, authentifié et disposer de `takepos/run` et `facture/creer`, droits utilisés par le chemin natif. Les droits du module restent réservés à l’audit et à la maintenance ; ils ne permettent jamais de contourner la protection. La facture doit exister, appartenir exactement à l’entité courante et avoir `module_source=takepos`.

Après validation stricte du UUID v4, le hook acquiert le verrou avant la transaction native. Seul `ACQUIRED` autorise la suite. Sous exclusion, il recherche le propriétaire du jeton dans l’entité, recharge l’objet facture et crée la tentative `PROCESSING` avec l’instantané natif du statut, du reste et des paiements. Les modes et montants reçus restent des données d’audit, sans autoriser le paiement. Le hook retourne alors `0` et conserve son verrou pendant toute l’action native, sans ouvrir ni terminer de transaction.

Tout jeton déjà enregistré est refusé, y compris `FAILED` et `BLOCKED` : une nouvelle tentative volontaire exige un nouveau jeton. Un jeton lié à une autre facture dans l’entité est refusé sans divulguer cette facture. La contrainte unique reste la dernière protection contre deux insertions concurrentes d’un même jeton sur des factures différentes. Aucun paiement, mouvement bancaire ou mouvement de stock n’est créé par le module.

Un refus retourne `1`, remplace l’action native, publie un message traduit et affiche un fragment HTML échappé dans la réponse AJAX. Les refus avant insertion ne créent pas de ligne d’historique, pour éviter une accumulation de tentatives invalides. Les logs détaillés contiennent uniquement des codes stables, jamais jeton, montant, utilisateur, SQL ou exception brute.

**Limite volontaire de cette étape :** une tentative acceptée reste `PROCESSING` et ses métadonnées de verrou persistent à la fermeture de la session SQL. Une expiration retourne une demande de réconciliation et bloque le paiement ; elle ne supprime ni ne remplace automatiquement le propriétaire. Les étapes de finalisation et récupération permettront les paiements ultérieurs. Aucun nettoyage manuel des lignes `PROCESSING` ne doit servir à contourner cette restriction. La politique des paiements partiels avec lots est décrite ci-dessous ; le JavaScript et le secours de finalisation shutdown restent aux étapes prévues.

`php test/interception.php` vérifie l’orchestration avec doubles : option désactivée, autres contextes/actions, droits, facture/entité/origine, jetons invalides et rejoués, refus d’un jeton d’une autre facture, verrou occupé/erreur/récupération, rechargement natif, erreurs de stockage et maintien du verrou avant retour au cœur. Les tests de stockage et de concurrence vérifient séparément les primitives SQL réelles. Ces contrôles ne constituent pas un test HTTP de paiement ni une preuve du nombre de mouvements bancaires/stock ; ceux-ci restent à exécuter après les étapes suivantes.

## Paiements partiels et stock (étape 5)

Après acquisition du verrou et rechargement de la facture, `TakeposguardPaymentPolicy` n’admet que les états natifs brouillon (`0`) ou validé (`1`). Une facture validée doit avoir un reste positif, ou négatif pour un avoir, calculé par `getRemainToPay()` ; le montant JavaScript ne participe jamais à cette décision. Les états payé/clôturé, abandonné, inconnu et les erreurs de calcul sont refusés avant la création d’une tentative. Un nouveau jeton distingue un paiement volontaire du rejeu ; le verrou et la contrainte unique restent obligatoires.

Sans gestion des lots, TakePOS ne déstocke que dans sa branche de validation du brouillon : le module conserve ce traitement natif. Avec `stock` et `productbatch` actifs, le cœur 22.x exécute sa boucle manuelle même sur une facture déjà validée. Le module évite cette répétition en positionnant uniquement dans la mémoire de la requête `CASHDESK_NO_DECREASE_STOCK<terminal>=1` pour un paiement ultérieur. Cela protège aussi les lignes sans lot lorsque le module lots est actif. Le brouillon initial n’est jamais concerné par cette surcharge.

La surcharge exige une preuve en base, dans l’entité et pour la facture : une tentative `SUCCESS`, avec statut initial brouillon, statut final validé/payé et date de finalisation renseignée. Une tentative `PROCESSING`, `FAILED`, `BLOCKED` ou un paiement partiel réussi sur une facture déjà validée ne suffit pas. L’erreur SQL bloque également la requête. Le réglage natif qui désactive déjà le déstockage est respecté sans demander cette preuve.

**Factures anciennes et historique :** en mode lots avec déstockage TakePOS actif, une facture validée sans preuve de validation initiale par le module est refusée. Ni un paiement existant ni la présence isolée d’un mouvement ne prouvent que toutes les lignes ont été traitées. Le module ne supprime aucun mouvement et ne tente pas de réparer des historiques modifiés hors de son contrôle. Une procédure de réconciliation pour les factures anciennes reste à définir ; ne pas fabriquer de lignes `SUCCESS`. La maintenance future devra conserver la preuve initiale tant qu’une facture peut recevoir un paiement partiel.

Le hook `completeTakePosInvoiceHeader`, exécuté après le commit/rollback natif dans le cœur local 22.0.5 inspecté, restaure la valeur d’origine pour la facture protégée. Une sortie anticipée utilise un callback shutdown qui restaure seulement cette configuration en mémoire, y compris si la propriété était absente ou `null`. Ce callback ne finalise pas la tentative, ne déduit aucun succès et ne libère pas de verrou. Aucune constante persistante ni configuration d’une autre requête n’est modifiée.

Cette étape prépare le paiement partiel volontaire mais la version reste intermédiaire : les tentatives d’une vraie requête restent `PROCESSING` tant que l’étape de finalisation n’est pas implémentée. Les tests fournissent explicitement un résultat confirmé et libèrent leurs seuls verrous de fixture ; aucun nettoyage de tentative réelle ne doit contourner cette limite.

Tests exécutés : `php test/interception.php` (51 contrôles), `php test/storage.php --mysql` (64 contrôles), `php test/partial_stock.php` (8 contrôles). Le test MariaDB utilise le hook, le verrou, l’historique et le calcul natif du solde sur tables temporaires. Le dernier test extrait le bloc de stock de `takepos/invoice.php` installé et l’exécute avec des doubles des classes d’écriture : deux appels initiaux, quantités des lignes conservées, zéro appel pour le paiement partiel protégé, restauration après sortie PHP. Il échoue si les bornes du bloc natif changent et exige alors une nouvelle inspection du cœur. Il ne crée aucun mouvement réel et ne vérifie pas les effets internes de `MouvementStock::livraison()`.

Les tests HTTP complets de paiement, banque, stock avec/sans lots et rollback restent à exécuter après JavaScript/finalisation sur une instance de recette. Le cœur local est 22.0.5 ; les constantes, la boucle native et l’ordre des hooks doivent être revérifiés lors d’une montée de version.

## Licence

GPL v3 ou ultérieure ; voir COPYING.
