# Recette de TakePOS Payment Guard 0.10.0

Exécution locale le 2 octobre 2026 sur Windows/XAMPP, Dolibarr **22.0.5**, PHP CLI **8.1.17**, Node.js **24.11.1**, connexion **DoliDB mysqli/MariaDB**. Branche `test/final-acceptance`. Aucune facture de l'instance ni aucun fichier natif modifié.

Commande : `C:\xampp\php\php.exe test/run.php --mysql` depuis le dossier du module. Code de sortie **0**.

| Vérification | Résultat | Portée |
| --- | --- | --- |
| `php -l` | 33 fichiers | Tous les fichiers PHP du module, y compris les nouveaux tests |
| Configuration | 22 cas + descripteur | Valeurs bornées et Cron désactivé |
| Audit | 10 contrôles | Droits et échappement avec doubles |
| Interception | 52 contrôles | Décisions avant traitement avec doubles |
| Finalisation | 23 contrôles | Preuves et échecs avec doubles |
| Dispatch du stock | 8 contrôles | Bloc natif, classes d'écriture remplacées par des spies |
| Verrous PostgreSQL | 11 contrôles | Pilote simulé ; aucun serveur PostgreSQL |
| SQL PostgreSQL | 9 instructions | Conversion native ; aucune exécution PostgreSQL |
| Contrat des sources | 25 contrôles | Cœur local 22.0.5 ; vérification structurelle |
| JavaScript | 45 contrôles + syntaxe | Doubles DOM/jQuery ; aucun navigateur ou débit externe |
| Stockage | 166 contrôles | Tables temporaires, schéma/contraintes/audit/maintenance/plafond |
| Verrouillage | 53 contrôles | Deux processus avec connexions distinctes et tables isolées |
| Recette native | 62 contrôles | Classes natives, effets réellement persistés, concurrence et rollback |

Empreinte SHA-256 du bloc natif `valid` testé : `ae9c4106dbdc098afc39e846e835555672cfe347f630319bc1fc42418363500d` (fins de ligne normalisées en LF).

## Couverture des scénarios demandés

| Cas | Preuve exécutée | Vérification complémentaire |
| --- | --- | --- |
| A. Double clic / paiement total | Rejeu serveur : une validation, un paiement, une banque, un mouvement ; double appel JS simulé | Double clic dans le navigateur authentifié |
| B. Concurrence | Quatre courses à deux processus : UUID identiques/distincts × avec/sans lots. Deux snapshots brouillon, une seule entrée native, quantités réelles diminuées une fois | Deux sessions HTTP distinctes |
| C. Paiement partiel | 40 puis 60 : ancien UUID refusé, nouveau accepté, deux paiements/banques, une seule sortie stock et lot | Réouverture du modal et solde affiché |
| D. Sans lots | Mouvement natif et stock d'entrepôt : quantité -2 une seule fois | Parcours HTTP et produits multiples |
| E. Avec lots | Stock physique et quantité du lot : -2 une seule fois ; paiement partiel et concurrence | Lots multiples et configuration réelle |
| F. Paiement direct | Wrapper/préfiltre `DirectPayment()` dans les doubles JS et fonction native présente | Clic direct, clavier et boutons natifs |
| G. Échec | Banque non configurée, liaison paiement défaillante, stock insuffisant, facture sans lignes : `FAILED`, brouillon conservé, aucune écriture métier ni paiement/banque orphelins, stock restauré, verrou libéré | Erreurs propres aux modules tiers et rendu natif |
| H. Interruption | Deux workers sortent avant/après commit ; `PROCESSING` conservé puis `FAILED`/`SUCCESS` après expiration, sans relance métier | Coupure réseau navigateur et interruption serveur HTTP |
| I. Option désactivée | Paiement natif réel accepté sans UUID, sans tentative du module | Chargement JS et parcours complet sans protection |
| J. Multientité | Même UUID accepté pour deux factures dans deux entités ; lectures croisées refusées ; stock de chaque entrepôt | Sessions multientité et tâche planifiée |

## Limites et décision de déploiement

Les scénarios métier locaux sont passés. La fixture CLI exécute le bloc natif installé et les classes natives ; elle fournit cependant elle-même la configuration, l'utilisateur, les paramètres et l'appel des hooks. Elle n'exécute pas `main.inc.php` ni le rendu intégral d'`invoice.php`. Les schémas métier reproduisent les colonnes natives sans toutes les clés étrangères. Les modules tiers, PDF et prestataires sont exclus.

**Non exécutés :** recette HTTP authentifiée/CSRF/rendu, activation des menus et Cron, terminaux Stripe/SumUp, serveur PostgreSQL, exécution complète sur Dolibarr 22.0.4 et autres versions, autres versions de PHP. Les tests de conversion et le contrat des sources ne valent pas qualification de ces environnements.

Avant production, exécuter la [recette HTTP](http-acceptance.md) sur la version et les modules effectivement déployés ; conserver les preuves par facture et faire valider les cas prestataires et les résultats ambigus. Ne pas déclarer le module qualifié en production sur la seule base de ce rapport. La fonctionnalité serveur n'est pas modifiée par cette étape de tests.
