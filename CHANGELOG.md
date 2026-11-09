# DB4D 2.1-dev — 9 novembre 2026

Les travaux PHP 8.5 sont maintenant intégrés à `master`. Aucun test n’a contacté un moteur 4D réel.

- Paramètres binaires typés par défaut : types séparés du SQL, NULL, booléens, entiers 32/64 bits exacts, REAL, texte UTF-16LE, BLOB avec longueur en **octets**. Paramètres nommés répétés et flux LOB bornés. Aucun retry d’écriture.
- `PDO::ATTR_EMULATE_PREPARES => true` conserve explicitement les littéraux SQL de la version 2.0. `getRawSql()` reste un outil de diagnostic sensible, jamais une chaîne à journaliser.
- Nouveau défaut `timestamp_format=pdo4d` : `YYYY-MM-DD HH:MM:SS.mmm`, selon Faldon/pdo_4d `cfbc138`. Le réglage explicite `pdo` conserve les barres obliques de 2.0 ; `legacy` conserve l’ancien format DB4D. Revoir les consommateurs de dates avant de changer un réglage existant.
- Mapping de 147 codes natifs vers SQLSTATE. Une réponse ERROR complète laisse le canal synchronisé ; une erreur dans un corps de résultat conserve son code natif puis ferme la connexion, sans deviner la suite de la trame. Messages expurgés de SQL/données.
- `getColumnMeta()`, `bindColumn`, FETCH_BOUND, FETCH_NAMED, `fetchObject`, FETCH_CLASS et PROPS_LATE, FETCH_GROUP/UNIQUE, KEY_PAIR et FUNC. Groupement/paires/fonctions via `fetchAll` uniquement. Les classes à hydrater doivent être choisies par le code de confiance et déclarer leurs propriétés ou autoriser explicitement les propriétés dynamiques.
- `DB4DDriver::ATTR_PREFERRED_IMAGE_TYPES` : liste de formats validée au LOGIN et à l’exécution ; défaut `jpg`. `ATTR_CHARSET` expose UTF-8 ; les autres encodages restent refusés plutôt que convertis implicitement.
- `disconnect()` permet un LOGOUT/QUIT explicite et borné hors transaction. `close()` et les destructeurs ferment uniquement le socket.

## Limites conservées

DB4D n’est pas une extension PDO native. `lastInsertId`, paramètres de sortie, connexions persistantes, FETCH_LAZY, multi-résultats, curseurs défilants et modes d’erreur silencieux ne sont pas ajoutés.

VK_FLOAT reste refusé : le code C de référence ne fournit pas non plus de conversion opérationnelle, et nous n’avons ni spécification complète vérifiée de sa mantisse, ni vecteurs provenant du moteur. Ne pas inventer un décodage financier. Utiliser un CAST SQL explicite vers texte si ce type est rencontré, puis vérifier le résultat sur 4D réel ; VK_REAL est pris en charge séparément.

## Validation

`php tests/run.php` : 97 tests réussis sous PHP 8.5.10 local 64 bits, dont 35 nouveaux tests de compatibilité (vecteurs hexadécimaux fixes, requêtes binaires, erreurs, métadonnées, dates, classes et groupements). Le serveur synthétique écoute uniquement sur loopback. Les tests historiques conservent des options explicites d’émulation et de date pour couvrir le comportement 2.0 ; les nouveaux tests vérifient les défauts 2.1.

La qualification sur le moteur utilisé reste obligatoire pour les écritures, contraintes, transactions, formats d’images, Unicode et valeurs limites. Ce journal ne déclare ni équivalence universelle à PDO ni qualification de production.
