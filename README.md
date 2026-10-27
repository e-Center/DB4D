# DB4D — client SQL 4D en PHP

Implémentation PHP du protocole SQL 4D, destinée à remplacer les usages applicatifs de l’extension PDO_4D sur PHP récent. PHP **8.2 à 8.5**, environnement **64 bits**, extensions PDO et mbstring ; aucune extension PDO_4D, sockets ou bibliothèque native 4D requise.

**Branche 2.0 en cours de qualification :** transport et compatibilité PDO repris, avec tests réseau locaux. Aucun serveur 4D réel n’a encore été utilisé pour valider cette version. Voir [la migration et ses limites](MIGRATION.md) et [les résultats exécutés](tests/RESULTS.md). La version d’origine annonçait des essais sur 4D v11–v13 en ASCII ; cela ne qualifie pas cette nouvelle version ni 4D v18.

## Installation

Le dépôt fournit un paquet Composer `e-center/db4d` et son autoload PSR-4. Épingler un commit relu de ce dépôt dans votre configuration VCS Composer. Ne pas dépendre d’une branche mobile pour une livraison serveur. Sans Composer :

```php
require '/chemin/DB4D/autoload.php';
$db = DB4D\DB4DDriver::fromDsn($dsn, $username, $password);
$db->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
$stmt = $db->prepare('SELECT ID FROM EXAMPLE_RECORDS WHERE ID = ?');
$stmt->execute([$id]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    // Traitement métier.
}
$stmt->closeCursor();
$db->close();
```

Le constructeur historique `new DB4DDriver($host, $port, $user, $password, $options)` est également disponible. Fournir les secrets depuis des fichiers privés ou un gestionnaire de secrets. Conserver PDO MySQL pour les bases MySQL.

## Réglages

| Option | Valeur par défaut | Rôle |
|---|---:|---|
| `connect_timeout` | 5 s | Établissement de la connexion |
| `read_timeout` | 10 s | Budget complet d’un échange, lecture et écriture comprises |
| `page_size` | 100 | Lignes par page |
| `max_field_bytes` | 16 Mio | Taille maximale d’un champ reçu et d’une requête SQL rendue |
| `max_page_bytes` | 32 Mio | Octets binaires reçus par page |
| `timestamp_format` | `pdo` | `YYYY/MM/DD HH:MM:SS.mmm` ; `legacy` conserve le format DB4D précédent |

`execute()` renvoie un booléen de succès, `query()` un statement, `exec()` le nombre de lignes affectées. C’est un changement par rapport aux retours parfois numériques/faux de l’ancienne bibliothèque. Les méthodes PDO courantes et les anciens modes DB4D sont pris en charge ; les modes avancés non implémentés sont refusés. **Cette classe n’est pas un objet PDO natif.**

## Tests

```sh
composer test
# ou, sans dépendance de développement :
php tests/run.php
```

Le serveur de test écoute exclusivement sur `127.0.0.1` et utilise des données synthétiques. Il ne peut pas sélectionner une base métier. Les tests couvrent les octets échangés, les paramètres, types, curseurs, transactions, délais, réponses malformées et écritures partielles. Les Actions GitHub exécutent la même suite sur PHP 8.2–8.5, sans secret ni accès de déploiement.

## Origine et licence

Projet initial de Thomas Pulzer, adapté dans le dépôt e-Center/DB4D. Les avis de copyright sont conservés. Les en-têtes AGPL et le fichier LICENSE GPL historiques restent présents ; leur divergence doit être clarifiée avant redistribution. Les modifications ne constituent pas une nouvelle licence.
