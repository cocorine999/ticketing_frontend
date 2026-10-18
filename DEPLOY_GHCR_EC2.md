# Déploiement Docker + GHCR sur EC2 (api.ticketing.corinebocoga.com)

Ce mode construit l'image Docker dans GitHub Actions, la pousse sur GHCR, puis `docker compose pull + up` sur l'EC2 via SSH.

## Architecture (mode Docker)

```
Internet :443/:80
      │
   [ Caddy ]  ← HTTPS Let's Encrypt auto (remplace Nginx + Certbot de l'hôte)
      │
      ├── /app/*  ──► [ broadcasting ]  Reverb :8080  (websockets)
      └── /*      ──► [ web ]  Nginx + PHP-FPM :8080  (dans l'image)
                        │
                     [ postgres:16 ]
                     [ worker ] [ scheduler ]
```

- **Nginx + PHP-FPM** sont **dans l'image** Docker (voir `Dockerfile` : `FROM php:8.2-fpm` + `nginx`), servis sur `:8080` en interne.
- **Caddy** est le seul conteneur exposé (80/443) : il termine le TLS et route vers `web` / `broadcasting`.
- En mode **natif**, c'est **Nginx (hôte) + Certbot** qui jouent ce rôle (pas de Caddy).

## Différence vs natif
- Natif : SSH + `git pull` + `composer install` + systemd (`php-fpm`, `queue:work`, `reverb:start`).
- Docker+GHCR : build image immuable dans la CI → `docker compose pull + up` (rollback = redéployer un ancien tag).

## Étape 1 - Préparer l'EC2 (Docker + clone du repo)
```bash
ssh -i ticketing-ec2.pem ubuntu@<ELASTIC_IP>

curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker ubuntu
newgrp docker

# Cloner le monorepo (le compose vit dans ticketing-api-rest-app/)
# NB: repo Git = github.com/cocorine999/ticketing_backend (remote "origin-github")
mkdir -p ~/app && cd ~/app
git clone https://github.com/cocorine999/ticketing_backend.git
cd ticketing_backend/ticketing-api-rest-app
```

## Étape 2 - `.env` de production
```bash
cp .env.production.example .env
nano .env
```
Valeurs **obligatoires dans ce mode** :
- `DB_HOST=postgres` `DB_PORT=5432` `DB_DATABASE=ticketing_db` `DB_USERNAME=ticketing` `DB_PASSWORD=<fort>`
- `APP_KEY=` (généré à l'étape 4), `APP_ENV=production`, `APP_DEBUG=false`
- `APP_URL=https://api.ticketing.corinebocoga.com`, `FRONTEND_URL=https://ticketing.corinebocoga.com`
- `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID/KEY/SECRET`, `REVERB_HOST=api.ticketing.corinebocoga.com`, `REVERB_PORT=443`, `REVERB_SCHEME=https`
- `FEDAPAY_*`, `MAIL_*`, `SESSION_DRIVER=database`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`

> `.env` est lu par `env_file` (variables des conteneurs) **et** par Compose pour substituer `${DB_PASSWORD}`. Il ne doit **jamais** être committé.

## Étape 3 - DNS + Security Group
- DNS (Hostinger) : `A  api.ticketing → <ELASTIC_IP>`.
- SG EC2 : ouvrir **80** et `443` (0.0.0.0/0), garder 22 restreint à ton IP.

## Étape 3bis - Premier lancement + clé
```bash
cd ~/app/ticketing_backend/ticketing-api-rest-app
docker compose up -d postgres
docker compose run --rm web php artisan key:generate
docker compose up -d
docker compose ps
```

## Étape 4 - GitHub Secrets
`Settings → Secrets and variables → Actions` :
- `EC2_HOST` = Elastic IP
- `EC2_SSH_KEY` = contenu du `.pem`
- `EC2_USER` = `ubuntu` (optionnel)

`GITHUB_TOKEN` (fourni automatiquement) sert à pousser/tirer l'image GHCR.

## Étape 5 - Déclencher le déploiement
Push sur `main` touchant `ticketing-api-rest-app/**` → workflow `Build and Deploy to EC2 (GHCR + Docker Compose)` :
1. build + push de l'image `ghcr.io/<owner>/ticketing-api:<sha>` et `:latest`
2. SSH : `docker compose pull` + `up -d` + caches Laravel

## Rollback
```bash
cd ~/app/ticketing_backend/ticketing-api-rest-app
export IMAGE_TAG=<sha_précédent>
docker compose pull web worker broadcasting scheduler
docker compose up -d
```

## Notes
- `postgres`, `worker`, `scheduler` sont internes (non exposés).
- Le volume partagé `storage_app` est monté sur `web` **et** `worker` (QR codes, pièces jointes).
- Ne pas lancer en parallèle avec le mode natif sur le même hôte (conflit ports 80/443). Choisir **un** mode.
- Images **alpine** (`postgres:16-alpine`, `caddy:2-alpine`) pour limiter l'empreinte disque.
- Auth serveur : **deploy key** (git, lecture seule) + `docker login ghcr.io` (persistant dans `~/.docker/config.json`).

## Pré-requis / limites (vérifiés en prod)
- **RAM** : `t3.micro` (911 Mo) suffit tout juste → ajouter **1–2 Go de swap** et préférer `t3.small` (2 Go) pour la stabilité.
- **Disque** : l'image backend fait **~1,5 Go**. Sur un volume de 8 Go il ne reste que ~1,5 Go libres → **agrandir l'EBS à 20 Go** (Console → Volume → Modify, puis `sudo growpart /dev/nvme0n1 1 && sudo resize2fs /dev/nvme0n1p1`).
- **Security Group** : ouvrir **80** et **443** à `0.0.0.0/0` (sinon Caddy ne peut pas obtenir le certificat Let's Encrypt).
- **DNS** : `A api.ticketing.corinebocoga.com → <Elastic IP>`.

## Vérification
```bash
curl -sS https://api.ticketing.corinebocoga.com/api/health
# {"status":"healthy","database":"connected",...}
```
