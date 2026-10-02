# TakePOS Payment Guard

Module externe indépendant installé dans `htdocs/custom/takeposguard`, sans modification du cœur Dolibarr.

## État de la version 0.1.0

Cette version implémente uniquement le point 1 : descripteur, configuration et droits réservés à l'audit et à la maintenance. **Elle ne protège pas encore les paiements**, même si l'option est activée. Les hooks et le JavaScript déclarés sont sans traitement jusqu'aux étapes suivantes. Aucun trigger ni table métier n'est ajouté ici.

## Installation

1. Copier le dossier dans `htdocs/custom/takeposguard`.
2. Utiliser Dolibarr 22.0.4 ou supérieur et PHP 7.2 ou supérieur, en respectant également les prérequis de Dolibarr.
3. Activer le module dans Configuration > Modules/Applications. La dépendance TakePOS est déclarée.
4. Ouvrir sa configuration avec un compte administrateur.

La version locale inspectée est 22.0.5. La compatibilité du traitement de paiement avec les versions ultérieures sera vérifiée aux étapes suivantes.

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

Les tests de concurrence, paiement et stock appartiennent aux étapes suivantes. Cette version ne doit pas être utilisée comme protection en production.

## Licence

GPL v3 ou ultérieure ; voir COPYING.
