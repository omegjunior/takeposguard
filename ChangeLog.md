# CHANGELOG MODULE TAKEPOSGUARD FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## 0.4.0

- Interception `takeposinvoice/doActions` avant la transaction native : droits, entité, origine, UUID v4, exclusion et historique.
- Rechargement de la facture et persistance `PROCESSING` avant poursuite du traitement natif.
- Refus des jetons absents, rejoués ou liés à une autre facture, erreurs et récupération non réconciliée ; messages traduits et échappés.
- Tests ciblés de l’orchestration ; finalisation, JavaScript et récupération restent aux étapes suivantes.

## 0.3.0

- Verrous de session MySQL/MariaDB et PostgreSQL associés aux métadonnées persistantes.
- Acquisition non bloquante, expiration évaluée avec l'horloge de la base et récupération explicitement conditionnée à la réconciliation.
- Refus d'acquisition/libération dans une transaction native ; aucune transaction ajoutée.
- Tests de concurrence avec deux processus PHP, expiration pendant activité et sortie sans libération.

## 0.2.0

- Tables persistantes des tentatives et métadonnées de verrou, clés uniques et index multientité.
- Bibliothèque de stockage : jetons UUID v4, instantanés natifs et transitions finales conditionnelles.
- Montants demandés distincts des paiements constatés ; validation et bornage des données techniques.
- Chargement SQL à l'activation, conservation des tables à la désactivation.
- Tests sur tables temporaires MariaDB et conversion SQL PostgreSQL native.

## 0.1.0

- Configuration TakePOS Payment Guard, dépendance TakePOS et droits audit/maintenance.
- Validation des paramètres, conservation par entité, traductions français/anglais.
- Hooks et JavaScript déclarés sans interception des paiements à cette étape.

## 1.0 (squelette ModuleBuilder)

Initial version
