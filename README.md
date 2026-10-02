# TakePOS Payment Guard

Module externe indépendant installé dans `htdocs/custom/takeposguard`, sans modification du cœur Dolibarr.

## État de la version 0.2.0

Cette version implémente les points 1 et 2 : configuration, droits, tables et bibliothèque de stockage. **Elle ne protège pas encore les paiements**, même si l'option est activée. Les hooks et le JavaScript déclarés sont sans traitement jusqu'aux étapes suivantes. Aucun trigger n'est ajouté.

## Installation

1. Copier le dossier dans `htdocs/custom/takeposguard`.
2. Utiliser Dolibarr 22.0.4 ou supérieur et PHP 7.2 ou supérieur, en respectant également les prérequis de Dolibarr.
3. Activer le module dans Configuration > Modules/Applications. La dépendance TakePOS est déclarée.
4. Ouvrir sa configuration avec un compte administrateur.

La version locale inspectée est 22.0.5. La compatibilité du traitement de paiement avec les versions ultérieures sera vérifiée aux étapes suivantes.

Pour une installation déjà activée en 0.1.0, désactiver puis réactiver le module afin de charger les nouvelles tables. La configuration reste conservée. Les tables et l'historique ne sont pas supprimés lors de la désactivation.

## Configuration par entité

| Constante | Défaut | Valeurs |
|---|---|---|
| `TAKEPOSGUARD_ENABLE` | `0` | `0` ou `1` |
| `TAKEPOSGUARD_LOCK_TIMEOUT` | `120` | 10 à 3600 secondes |
| `TAKEPOSGUARD_HISTORY_DAYS` | `90` | 1 à 3650 jours |
| `TAKEPOSGUARD_DEBUG_LOG` | `0` | `0` ou `1` |
| `TAKEPOSGUARD_MISSING_TOKEN_POLICY` | `reject` | Refus uniquement |

Ces paramètres préparent les étapes suivantes. Les intégrations tierces devront fournir un jeton stable par tentative ; aucun contournement silencieux ne sera proposé.

Les écritures utilisent les API natives, une transaction et l'entité courante. Le formulaire accepte uniquement POST et conserve la vérification CSRF native. Une valeur invalide empêche l'enregistrement de tout le formulaire. La configuration est conservée lors de la désactivation/réactivation.

## Identifiants et droits

Numéro local : `501117`. Droits : `50111701` (`audit/read`) et `50111702` (`maintenance/write`). Ils ne donnent aucun droit de paiement et ne conditionneront pas la protection serveur. La configuration reste réservée aux administrateurs.

Les identifiants ont été contrôlés dans les descripteurs locaux et en lecture seule dans `rights_def`. Cela ne constitue pas une réservation mondiale : vérifier les collisions sur chaque instance avant installation. Les pages d'audit et de maintenance viendront ultérieurement.

## Vérification

Exécuter `php -l` sur les fichiers PHP et `php test/configuration.php`. Sur une instance de test, vérifier également l'activation, les soumissions administrateur/non-administrateur, les jetons CSRF invalides, les valeurs invalides, la conservation après réactivation et l'isolation entre entités.

Pour le stockage, exécuter `php test/storage.php --mysql` depuis le dossier du module. Le test lit la configuration de connexion locale et utilise exclusivement des tables temporaires propres à la connexion, avec le préfixe `tpg_test_`. Il ne modifie pas les données Dolibarr. Le compte de base doit pouvoir créer des tables temporaires. La connexion fermée, ces tables disparaissent. L'hydratation complète de `Facture::fetch()` est remplacée par une fixture minimale ; le calcul natif du reste à payer et les requêtes du stockage sont réellement exécutés.

`php test/sql_portability.php` vérifie la conversion des scripts par le pilote PostgreSQL Dolibarr, sans connexion PostgreSQL. Ce contrôle ne remplace pas un test d'installation et d'exécution sur ce moteur.

Résultats exécutés localement : 48 contrôles de schéma/stockage sur MariaDB via le pilote `mysqli`, 18 cas de configuration et contrôles du descripteur, conversion de neuf instructions SQL par le pilote PostgreSQL. L'activation réelle dans l'interface n'a pas été exécutée.

Les tests de concurrence, paiement et stock appartiennent aux étapes suivantes. Cette version ne doit pas être utilisée comme protection en production.

## Stockage des tentatives et verrous

L'activation charge les fichiers `sql/llx_takeposguard*.sql` et leurs clés via `_load_tables()`. Les scripts suivent la syntaxe des modules externes Dolibarr ; le préfixe réel est substitué par l'installateur et PostgreSQL utilise la conversion native. Un échec de chargement SQL empêche l'activation.

- `takeposguard_payment_attempt` conserve le jeton UUID v4 canonique, la facture, l'entité, l'utilisateur, le terminal, les données demandées, les instantanés avant/après, les références de paiement, les dates et les erreurs bornées. Les états admis par la bibliothèque sont `PROCESSING`, `SUCCESS`, `FAILED`, `BLOCKED`.
- Une clé unique couvre `(entity, fk_invoice, operation_token)`. Une seconde couvre `(entity, operation_token)` : le même jeton ne peut pas être affecté à une autre facture dans la même entité. Il peut être réutilisé dans une autre entité.
- `takeposguard_invoice_lock` conserve le propriétaire logique et l'expiration, avec unicité `(entity, fk_invoice)`. La bibliothèque permet uniquement de lire ces métadonnées. Elle n'acquiert, ne supprime et ne récupère aucun verrou à cette étape.

`TakeposguardStorage` utilise la connexion DoliDB fournie et fixe son périmètre à l'entité courante lors de sa construction. `createProcessing()` recharge une facture TakePOS de cette entité et capture le reste à payer par les API natives. Le nombre et la dernière référence des paiements sont également mémorisés. `fetchAttempt()` distingue absence (`null`) et erreur (`false`, code technique dans `error`).

`completeAttempt()` stocke un résultat confirmé par son appelant et ne remplace qu'un état `PROCESSING`. Le reste et le statut sont rechargés ; le montant réel provient du lien de paiement en base, jamais du montant demandé. Un paiement référencé doit appartenir à la même entité et à la facture concernée. Une tentative finalisée ne peut pas être réécrite avec cette méthode.

La bibliothèque n'ouvre ni ne termine de transaction. Le code appelant devra vérifier les droits et détenir le verrou exclusif avant création/finalisation ; cette orchestration sera implémentée aux points suivants. Le statut `SUCCESS` n'est pas déduit automatiquement par la couche de stockage. Les modes et montants demandés sont des informations d'audit, sans pouvoir d'autoriser un paiement.

IP et user-agent sont facultatifs, validés et limités ; les messages d'erreur doivent être techniques et sans données sensibles. La bibliothèque n'écrit aucun log contenant les requêtes ou données de paiement. Les index couvrent la facture/date, l'état/date, la date de finalisation et l'expiration. Les références métier n'ont pas de suppression en cascade, pour préserver l'audit. Aucun nettoyage automatique ni limite de tentatives n'est activé à ce stade ; ces contrôles viendront avec l'interception et la maintenance.

## Licence

GPL v3 ou ultérieure ; voir COPYING.
