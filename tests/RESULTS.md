# Vérifications exécutées — 27 octobre 2026

| Exécution | Réussis | Échecs |
|---|---:|---:|
| PHP 8.5.10, Mac ARM | 61 | 0 |
| PHP 8.5.12, Debian 13 ARM64 | 61 | 0 |
| PHP 8.5.12, Debian 13 x86_64 sous émulation | 61 | 0 |

Commande : `php tests/run.php`. Les conteneurs des deux dernières exécutions avaient un système de fichiers en lecture seule, `/tmp` temporaire et `--network none` ; seul le loopback interne permet les échanges du simulateur. Les avertissements PHP non masqués font échouer les tests.

Le serveur simulé est indépendant des classes du client : aucune réutilisation de son encodeur/décodeur. Les trames binaires couvrent les limites signées et les tailles définies dans `lib4d_sql` de l’archive PECL **PDO_4D 0.3**, SHA-256 `1ec2e604024de602ac00a2539b504fd1adeb6525814c14121743db56f1852476`. Les en-têtes et la pagination suivent `fourd_interne.c`, la réception des types `communication.c` et `fourd_type.c`. Les bornes d’entiers supplémentaires utilisent des vecteurs hexadécimaux fixes.

Couverture exécutée : paramètres littéraux et nommés, apostrophes/dollars/sentinelle historique, CR/LF et Unicode hors BMP ; NULL, booléens, 16/32/64 bits signés, REAL, UTF-16LE, BLOB vide et binaire, timestamps ; résultats vides ; lecture partielle puis fetchAll et modes alternés ; booléens faux en itération ; deux statements préparés et exécutés dans l’ordre inverse ; pagination sans nouvelle exécution SQL ; fermeture anticipée/idempotente ; corps binaire des UPDATE y compris zéro ; erreurs SQL puis reprise du canal ; commit refusé, rollback et état de transaction ; délais, EOF au milieu d’un champ, identifiant erroné, métadonnées malformées, limites d’allocation, erreur cellule, grande requête et écritures réseau partielles forcées.

**0 connexion à un vrai moteur 4D, 0 écriture métier.** PHP 8.2/8.3/8.4 et l’exécution native sur un serveur x86_64 restent à vérifier ; une matrice CI est fournie, sans prétendre qu’elle a déjà été exécutée. Les erreurs de contraintes, les transactions effectives, les déclencheurs, l’Unicode et les droits sur 4D v18 exigent toujours une qualification sur moteur réel.
