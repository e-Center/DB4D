# Remplacement de PDO_4D — état du 27 octobre 2026

Cette branche fournit une nouvelle implémentation du transport DB4D et le sous-ensemble PDO utilisé par Atelier du Livre. Elle vise PHP 8.2 à 8.5 sur machines 64 bits. Les tests exécutés sont décrits dans `tests/RESULTS.md`. **Aucun test n’a contacté le moteur 4D réel. La validation sur 4D v18 et la qualification métier restent nécessaires.**

## Changement dans les applications

Remplacer uniquement la création des connexions dont le DSN commence par `4D:` :

```php
// Avant : new PDO($dsn, $user, $password)
$db = DB4D\DB4DDriver::fromDsn($dsn, $user, $password);
$db->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
$db->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
$statement = $db->prepare('SELECT ID FROM EXAMPLE_RECORDS WHERE ID = ?');
$statement->execute([$id]);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC);
$statement->closeCursor();
```

Les DSN MySQL continuent à utiliser PDO MySQL. DB4D ne s’enregistre pas dans `PDO::getAvailableDrivers()` et ne peut pas satisfaire un typehint `PDO`/`PDOStatement` ou hériter de leurs classes natives. Utiliser les types DB4D ou une interface applicative si nécessaire.

## Contrat pris en charge

- Connexion DSN `4D:host=…;port=19812;charset=UTF-8`, ou constructeur historique hôte/port/utilisateur/mot de passe. Les identifiants doivent provenir de fichiers privés ou d’un gestionnaire de secrets.
- `prepare`, `query` retournant un statement, `exec` retournant le nombre affecté ; `execute` retourne **true même pour zéro ligne**. La propriété et la méthode `rowCount` restent disponibles.
- Paramètres `?` ou nommés, `bindValue`, références `bindParam`, NULL, booléens, entiers, réels finis, texte UTF-8 et LOB explicitement typés. Les clés numériques de `execute(array)` commencent à zéro ; celles de `bindValue`/`bindParam` commencent à un. Les types de `execute(array)` sont inférés de PHP ; utiliser les bindings pour imposer le type.
- Lecture avant uniquement, `fetch`, `fetchAll`, `fetchColumn`, `fetchRow` historique, itération, `columnCount`, casse des colonnes, objets `stdClass`, modes numériques/associatifs/mixtes et colonne. Les constantes PDO et historiques DB4D sont acceptées.
- Erreurs par exceptions dérivées de `PDOException`, `errorCode`/`errorInfo`. Le code natif 4D est conservé ; les erreurs SQL du serveur utilisent `42000`, l’authentification `28000`, les délais `HYT00`. Le texte renvoyé par le serveur n’est jamais recopié dans les messages d’exception.
- `START`/`COMMIT`/`ROLLBACK` via les méthodes de transaction. Pas de transactions imbriquées. L’état n’est changé qu’après une réponse complète et positive. Une interruption rend le résultat de l’écriture potentiellement **inconnu** : ne jamais relancer automatiquement une commande métier.

## Protocole et types

Les requêtes sont encodées dans `STATEMENT-BASE64`, comme le pilote C PDO_4D 0.3 : les CR/LF dans les paramètres ne deviennent pas des en-têtes réseau. Un analyseur lexical remplace les paramètres hors chaînes, identifiants et commentaires ; aucune substitution par expressions de remplacement ni sentinelle ne modifie les dollars, apostrophes ou points d’interrogation.

Les pages supplémentaires utilisent `FETCH-RESULT` avec les index de lignes et le curseur existant. **La requête initiale n’est exécutée qu’une fois.** Les réponses `Update-Count` consomment aussi le corps binaire de huit octets. Les lectures/écritures sont complètes, avec détection de fermeture, délais bornés par échange et limites de taille ; le décodeur interrompt la connexion après une réponse tronquée ou non interprétable.

Décodage : `VK_BOOLEAN`, `VK_BYTE`/`VK_WORD` signés 16 bits, `VK_LONG` signé 32 bits, `VK_LONG8`/`VK_DURATION` signés 64 bits exacts, `VK_REAL` IEEE-754 little-endian, `VK_STRING`/`VK_TEXT` UTF-16LE vers UTF-8, BLOB/image et timestamp. NULL, chaîne vide et BLOB vide restent distincts. Les identifiants internes de lignes ne masquent pas une colonne utilisateur `_ID`.

Les dates ont par défaut le format textuel PDO_4D `YYYY/MM/DD HH:MM:SS.mmm`. L’option `timestamp_format=legacy` conserve le format DB4D historique `DD.MM.YYYY[ HH:MM:SS]`. Les booléens sont décodés selon leur valeur, sans reproduire l’inversion présente dans l’ancien pilote C.

## Limites explicites

- `VK_FLOAT`, format numérique propriétaire 4D, est refusé plutôt que décodé incorrectement. Utiliser un `CAST` SQL explicitement choisi vers texte ou REAL pour les colonnes concernées ; ne pas convertir automatiquement des montants exacts en flottants.
- Pas de `lastInsertId` générique, de curseur défilant, de requêtes multiples, de modes `FETCH_CLASS`/groupés, de sortie de procédure, de connexion persistante ou de modes d’erreur silencieux. Les fonctions non implémentées échouent explicitement.
- Les paramètres sont émulés par échappement SQL standard. Les littéraux binaires `X'…'` demandent 4D 12.1 ou ultérieur. Le protocole réseau 4D n’est pas rendu confidentiel par Base64 : utiliser un réseau privé de confiance ou un tunnel sécurisé. Aucune négociation TLS n’est inventée par ce client.
- La bibliothèque n’adapte pas les vieux accès COM/ODBC, ne corrige pas les requêtes SQL métier et ne garantit pas l’atomicité d’un workflow complet. Les contraintes, transactions, déclencheurs et reprises métier restent à tester sur le moteur utilisé.
- Les en-têtes historiques indiquent AGPL-3.0-or-later tandis que le fichier LICENSE indique GPL-3.0. Les deux sont conservés sans changement ; la divergence n’est pas résolue par ce travail.

## Vérifier ensuite sur un moteur 4D

Commencer avec un compte SQL en lecture seule et les mêmes requêtes sur l’ancien pilote et ce client : noms/casse de colonnes, NULL, accents et caractères hors BMP, nombres négatifs et limites 64 bits, dates/heures, booléens, pages et BLOB. Comparer des résultats normalisés, sans journaliser les coordonnées clients ni les secrets.

Les écritures et transactions exigent un jeu de données restaurable hors production : insertion/relecture, rollback, commit, erreurs de contraintes, deux sessions concurrentes et coupures réseau. Rapprocher ensuite les commandes, quantités et identifiants GPAO avant d’autoriser une bascule. **Les tests du simulateur ne constituent pas cette qualification.**
