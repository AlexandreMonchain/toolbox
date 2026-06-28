# Toolbox — alexandremonchain.fr

Une boîte à outils web pour les RSI, sysadmins et curieux du numérique. Ces outils me servent au quotidien — je les rends publics et disponibles pour tous.

**[toolbox.alexandremonchain.fr](https://toolbox.alexandremonchain.fr)**

---

## À propos

Ce projet s'inscrit dans la continuité de **[Passphrase](https://passphrase.fr)**, un générateur de phrases de passe lancé il y a deux ans, autonome, stable et utilisé quotidiennement. La Toolbox élargit l'idée : regrouper en un seul endroit les outils que j'utilise vraiment, et les rendre disponibles à tous.

---

## Outils

La Toolbox regroupe une quinzaine d'outils : calculateur de sous-réseaux, diff de texte, encodeur Base64, générateur de secrets, générateur de QR codes, convertisseur d'unités, éditeur Cron, testeur de clavier, générateur de commandes, gestion d'incidents, et d'autres.

Trois d'entre eux traitent des données sensibles et méritent une attention particulière.

### 🔥 BurnNote — Note à destruction automatique

Partagez un secret (mot de passe, clé API, information confidentielle) via un lien unique qui s'autodétruit.

Largement inspiré du projet open source [pwpush](https://github.com/pglombardo/PasswordPusher), avec en plus un système de **rotation de clé de chiffrement** : en cas de changement de clé maître, les anciens secrets restent lisibles via `APP_ENCRYPTION_KEY_PREVIOUS`. Les deux clés coexistent le temps que tous les secrets actifs expirent naturellement.

- Chiffrement **AES-256-GCM** avant stockage en base
- Passphrase optionnelle, hachée en **Argon2ID** côté serveur — le destinataire doit la saisir pour accéder au secret
- Nombre de vues configurable (1 à 100, ou illimité) et expiration de 1 heure à 30 jours
- Destruction manuelle possible avant expiration
- Aucun contenu en clair en base de données

### 📦 DropText — Partage de contenu chiffré

Déposez un texte ou un bloc de code que votre destinataire récupère via un lien, avec coloration syntaxique et protection optionnelle par passphrase.

- Chiffrement **AES-256-GCM** avant stockage
- Passphrase optionnelle, hachée en **Argon2ID** côté serveur
- Nombre de lectures configurable (1, 5, 10, 25, 50 ou illimité)
- Expiration : 1 heure, 24 heures ou 7 jours
- Les aperçus de lien (Slack, Teams, Outlook…) ne consomment pas une lecture
- Destruction manuelle possible à tout moment
- Coloration syntaxique pour une quinzaine de langages (Bash, Python, SQL, YAML, JSON…)

### 🔐 CSR — Générateur de Certificate Signing Request

Renseignez vos informations (CN, organisation, SANs…), le serveur génère la paire de clés RSA 2048 bits et la CSR via OpenSSL. La clé privée vous est retournée directement et n'est pas conservée.

- Génération côté serveur via **OpenSSL** (RSA 2048, SHA-256)
- Support des **Subject Alternative Names** (multi-domaines, wildcard)
- La clé privée **n'est pas stockée** — elle transite uniquement le temps de la requête HTTPS
- Téléchargement direct de la CSR et de la clé privée

---

## Confidentialité

Les données sensibles (BurnNote, DropText) sont chiffrées en **AES-256-GCM** avant stockage. Le serveur accède au contenu en clair uniquement le temps de la requête, pour chiffrer à l'écriture ou déchiffrer à la lecture — jamais en base.

**Le code est entièrement auditable.** Les mécanismes de chiffrement (BurnNote, DropText) et de génération CSR sont lisibles dans les sources :
- [`src/Service/BurnNote/EncryptionService.php`](src/Service/BurnNote/EncryptionService.php)
- [`src/Service/DropText/EncryptionService.php`](src/Service/DropText/EncryptionService.php)
- [`src/Controller/Csr/CsrController.php`](src/Controller/Csr/CsrController.php)

---

## Déploiement Docker

```bash
git clone https://github.com/AlexandreMonchain/toolbox.git
cd toolbox
docker compose -f compose.prod.yaml --env-file .env.docker up --build -d
```

### Variables d'environnement

| Variable | Requis | Défaut | Description |
|---|---|---|---|
| `APP_SECRET` | ✅ | — | Secret Symfony (min. 32 caractères, unique par instance) |
| `APP_ENCRYPTION_KEY` | ✅ | — | Clé AES-256 pour BurnNote/DropText (64 caractères hex) |
| `APP_ENCRYPTION_KEY_PREVIOUS` | — | — | Ancienne(s) clé(s) séparées par des virgules — voir procédure de rotation ci-dessous |
| `POSTGRES_DB` | ✅ | — | Nom de la base de données PostgreSQL |
| `POSTGRES_USER` | ✅ | — | Utilisateur PostgreSQL |
| `POSTGRES_PASSWORD` | ✅ | — | Mot de passe PostgreSQL |
| `PORT` | — | `8080` | Port exposé sur l'hôte |
| `DEFAULT_URI` | — | `https://toolbox.alexandremonchain.fr` | URL publique de l'instance (utilisée pour les URLs absolues) |
| `TRUSTED_PROXIES` | — | `REMOTE_ADDR` | IP du reverse proxy (ex : `10.0.0.1`). `REMOTE_ADDR` = faire confiance à la connexion directe |
| `GIT_REPO` | ✅ | — | URL du dépôt Git à cloner au build |
| `GIT_BRANCH` | — | `main` | Branche à cloner au build |
| `GIT_USERNAME` | — | — | Nom d'utilisateur Git (si dépôt privé) |
| `GIT_TOKEN` | — | — | Token d'accès Git (si dépôt privé) |
| `CACHE_BUST` | — | `1` | Incrémenter pour invalider le cache Docker et forcer un nouveau clone |

### Rotation de la clé de chiffrement

La durée de vie maximale d'un BurnNote est de 30 jours. La procédure de rotation est donc :

**J1 — Changement de clé**
```bash
# Générer une nouvelle clé
APP_ENCRYPTION_KEY=<nouvelle_clé>
APP_ENCRYPTION_KEY_PREVIOUS=<ancienne_clé>   # les secrets existants restent lisibles
```

**J31 — Nettoyage** (tous les secrets chiffrés avec l'ancienne clé ont expiré)
```bash
APP_ENCRYPTION_KEY=<nouvelle_clé>
# APP_ENCRYPTION_KEY_PREVIOUS peut être supprimée
```

Si plusieurs rotations se sont succédé sans nettoyage, `APP_ENCRYPTION_KEY_PREVIOUS` accepte plusieurs clés séparées par des virgules.

---

### Génération des secrets

```bash
# APP_SECRET (32 octets)
openssl rand -hex 32

# APP_ENCRYPTION_KEY (32 octets = 64 hex)
openssl rand -hex 32
```

---

## Stack technique

- **PHP 8.4** + Symfony 7.4 LTS
- **PostgreSQL 17**
- **Apache** (mod_rewrite, mod_headers)
- **Docker** multi-stage, runtime non-root (www-data)
- Symfony AssetMapper — pas de Node.js, pas de bundler
