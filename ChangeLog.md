# CHANGELOG MODULE TAKEPOSGUARD FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

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
