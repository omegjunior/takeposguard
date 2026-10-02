# Recette HTTP avant déploiement

Utiliser une instance dédiée, des clients/produits de test, un compte bancaire de recette et les environnements sandbox des prestataires. Sauvegarder cette instance. Ne jamais tester un encaissement sur une vente réelle. Consigner version Dolibarr/PHP/DB, modules actifs, configuration par entité, terminal, facture, jetons, réponse et effets avant/après. Ne conserver ni cookies, ni tokens CSRF, ni identifiants prestataires dans le rapport.

## Préparation et concurrence réelle

1. Installer le module ; vérifier que 501117 et les droits 50111701/50111702 n'entrent pas en collision. Tester activation, désactivation et mise à jour avec conservation des historiques. Activer `TAKEPOSGUARD_ENABLE` seulement dans la recette.
2. Activer stock, puis répéter avec lots/séries. Configurer entrepôt, compte bancaire et terminal natifs. Préparer des factures brouillon TakePOS à deux unités avec quantités initiales connues. Préparer aussi un panier mixte et plusieurs lots.
3. Ouvrir deux sessions indépendantes, par exemple deux profils de navigateur avec deux comptes internes autorisés TakePOS/facture. **Deux requêtes portant le même cookie de session peuvent être sérialisées par PHP et ne prouvent pas une course concurrente.** Chaque session doit conserver son propre CSRF natif.
4. Capturer les requêtes natives `takepos/invoice.php?action=valid` dans les outils réseau. Reproduire uniquement ces requêtes sur la facture de recette en conservant paramètres, session et CSRF ; ajouter `takeposguard_token` UUID v4. Pour le premier essai employer le même UUID, pour le second deux UUID distincts. Lancer les deux requêtes depuis les sessions indépendantes au même signal. Ne pas modifier le cœur pour ralentir le traitement.
5. Observer audit et effets métier. Une réponse HTTP 200 ne suffit pas : contrôler `X-Takeposguard-Status`, le token corrélé et les écritures en base. Une tentative bloquée ne doit jamais entrer dans les mouvements natifs. La barrière CLI de `test/native_acceptance.php` complète cet essai en garantissant deux snapshots brouillon réellement simultanés.

## Matrice A–J

| Cas | Action | Résultat attendu |
| --- | --- | --- |
| A | Double clic rapide sur paiement total, puis rejeu du même UUID | Boutons immédiatement désactivés ; une validation, un paiement, une banque, une série de mouvements ; rejeu refusé |
| B | Deux requêtes parallèles, UUID identiques puis distincts ; répéter avec/sans lots | Une seule action native à la fois ; aucune seconde validation/déstockage ; facture rechargée sous verrou |
| C | Payer 40 sur 100, rejouer ce UUID, puis payer 60 volontairement avec un nouveau | Rejeu refusé ; nouveau paiement accepté ; deux paiements/banques, un déstockage initial |
| D | Deux unités sans lots, panier mixte | Nombre de mouvements et somme des quantités conformes, stock physique diminué une fois |
| E | Deux unités avec lots puis plusieurs lots | Quantités de chaque lot et entrepôt diminuées une fois, aucun mouvement de la requête bloquée |
| F | `DirectPayment()`, clavier, Stripe et SumUp sandbox, boutons dynamiques | Même protection visuelle et UUID stable ; requête finale protégée ; incertitude prestataire ne lance aucun nouveau débit automatique |
| G | Stock insuffisant, compte bancaire absent, erreur de paiement sandbox | Rollback natif complet, `FAILED`, verrou libéré/récupérable ; vérifier aussi les tables paiement/banque sans lien pour exclure des orphelins |
| H | Couper le réseau après envoi ; recharger l'onglet ; interrompre un worker de recette avant/après commit | UUID conservé ; consultation explicite sans nouveau paiement ; après expiration, réconciliation fondée sur les effets persistants ; ambiguïté `BLOCKED` sans libération forcée |
| I | Désactiver l'option et refaire un paiement natif de recette | Pas de protection JS ni de tentative créée ; comportement natif ; réactiver hors encaissement |
| J | Répéter dans deux entités avec le même UUID et leurs propres factures/comptes/entrepôts | Aucun accès croisé ni collision ; audit, maintenance et Cron limités à leur entité |

Tester une facture validée ancienne sans preuve de validation initiale : avec lots et déstockage actif, le refus conservateur est attendu. Ne pas fabriquer une tentative `SUCCESS` pour contourner ce contrôle.

## Sécurité, audit et maintenance

- Jeton absent/invalide, jeton d'une autre facture, utilisateur externe, absence des droits TakePOS/facture : refus avant paiement. Une intégration tierce doit fournir un UUID stable ; la politique de compatibilité n'est pas une permission de contourner la protection.
- Consultation `ajax/attempt.php` et formulaires maintenance : POST CSRF absent/invalide refusé, GET sans mutation, auteur tiers refusé sans droit de maintenance. Ne pas désactiver la protection CSRF native pour la recette.
- Audit : droits de lecture et de maintenance séparés, accès direct interdit sans droit, pagination et filtres, références avec caractères HTML échappées.
- Verrou actif même expiré : impossible à déplacer. Verrou réellement expiré : réconciliation avant libération ; propriétaire changé/ambigu/erreur DB : aucun effacement forcé.
- Purge : seules les anciennes tentatives confirmées perdent leurs détails ; UUID et preuve initiale conservés ; états récents/actifs/ambigus exclus. Rejouer un UUID purgé reste refusé. Vérifier le plafond de tentatives et les lots de 500.
- Cron : désactivé à l'installation ; autorisations du compte d'exécution et entité vérifiées ; aucun déverrouillage forcé.

## Preuves à conserver

Pour chaque facture, noter statut, reste à payer, paiements liés, écritures bancaires, mouvements `origintype='facture'`/`fk_origin`, quantités par entrepôt et lot, tentatives et verrous avant/après. Utiliser les écrans natifs et des requêtes **en lecture seule** avec le préfixe de l'instance et l'entité exacte. Vérifier l'absence d'orphelins après rollback. Le journal de test doit signaler chaque cas réussi, échoué ou non exécuté.

Un nouvel UUID n'est autorisé qu'après résultat confirmé ou paiement volontaire ultérieur. Un débit externe doit être vérifié chez le prestataire avant toute décision manuelle. Ne jamais supprimer des paiements/mouvements ou des clés d'idempotence pour faire passer un test. Faire une nouvelle recette lors d'une mise à jour Dolibarr, d'un changement de moteur DB ou d'une intégration TakePOS tierce.
