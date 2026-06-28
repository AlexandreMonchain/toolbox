# Toolbox — alexandremonchain.fr

Une boîte à outils web pour les RSI, sysadmins et curieux du numérique. Ces outils me servent au quotidien — je les rends publics et disponibles pour tous.

**[toolbox.alexandremonchain.fr](https://toolbox.alexandremonchain.fr)**

---

## À propos

Ce projet s'inscrit dans la continuité de **[Passphrase](https://passphrase.alexandremonchain.fr)**, un générateur de phrases de passe lancé il y a deux ans, stable, autonome, et utilisé quotidiennement. La Toolbox élargit l'idée : regrouper en un seul endroit les outils que j'utilise vraiment, sans tracker, sans compte, sans collecte de données.

---

## Outils confidentiels

Ces trois outils traitent des données sensibles. Aucune donnée n'est conservée au-delà de sa durée de vie explicite, aucune n'est revendue ou transmise.

### 🔥 BurnNote — Note à destruction automatique

Partagez un secret (mot de passe, clé API, information confidentielle) via un lien unique qui s'autodétruit après lecture.

- Chiffrement **AES-256-GCM** côté serveur avant stockage
- Le secret est **détruit immédiatement** après la première lecture (ou à expiration)
- Passphrase optionnelle pour une couche de protection supplémentaire
- Aucun contenu en clair en base de données

### 📦 DropText — Partage de texte sécurisé

Déposez un texte que votre destinataire récupère une seule fois, protégé par passphrase.

- Chiffrement **AES-256-GCM** avant stockage
- Lecture unique : le contenu est **supprimé à la première consultation**
- Passphrase hachée en **Argon2ID** — même en cas de fuite de la base, les contenus restent illisibles
- Destruction manuelle possible à tout moment

### 🔐 CSR — Générateur de Certificate Signing Request

Générez une CSR et une clé privée directement dans votre navigateur, sans que la clé privée ne transite par le serveur.

- La clé privée est générée **localement dans votre navigateur** via l'API WebCrypto
- Seule la CSR (données publiques) transite vers le serveur pour la mise en forme
- Téléchargement direct — rien n'est conservé

---

## Autres outils

Calculateur de sous-réseaux, diff de texte, encodeur Base64, générateur de mots de passe, générateur de QR codes, convertisseur d'unités, éditeur Cron, testeur de clavier, générateur de commandes, gestion d'incidents.

---

## Confidentialité & sécurité

- **Aucun tracker, aucune analytics, aucun cookie tiers**
- Les outils sensibles (BurnNote, DropText) chiffrent les données avant stockage — le serveur ne voit jamais les secrets en clair
- Headers de sécurité sur toutes les réponses : CSP stricte par nonce, HSTS, X-Frame-Options, Referrer-Policy
- Protection CSRF sur tous les formulaires à mutation d'état
- Rate limiting par IP sur toutes les routes d'écriture

**Le code est entièrement auditable.** Si vous utilisez BurnNote ou DropText pour des données sensibles et souhaitez vérifier les mécanismes de chiffrement, les sources sont là.

---

## Déploiement Docker

L'application est disponible en auto-hébergement via Docker.

```bash
git clone https://github.com/AlexandreMonchain/toolbox.git
cd toolbox
docker compose -f compose.prod.yaml --env-file .env.docker up --build -d
```

Variables d'environnement requises :

| Variable | Description |
|---|---|
| `APP_SECRET` | Secret Symfony (min. 32 caractères, unique) |
| `APP_ENCRYPTION_KEY` | Clé AES-256 (64 caractères hex) |
| `DATABASE_URL` | URL de connexion PostgreSQL |
| `POSTGRES_USER` | Utilisateur base de données |
| `POSTGRES_PASSWORD` | Mot de passe base de données |
| `POSTGRES_DB` | Nom de la base de données |

---

## Stack technique

- **PHP 8.4** + Symfony 7.4 LTS
- **PostgreSQL 17**
- **Apache** (mod_rewrite, mod_headers)
- **Docker** multi-stage, runtime non-root
- Symfony AssetMapper — pas de Node.js, pas de bundler
