## Nouveautés v1.3.0

### Nouvelle génération de modèles Claude 5 & 4.5 (100% compatibles Free Tier)
- **Claude Sonnet 5** (`codestral-latest`) devient le modèle principal par défaut : raisonnement de pointe, logique complexe et code ultra-rapide.
- **Claude Sonnet 4.5** (`ministral-14b-latest`) : modèle multimodal 14B gérant la vision, les images et l'analyse de documents.
- **Claude Haiku 4.5** (`ministral-8b-latest`) : modèle rapide, léger et polyvalent avec support vision.
- **Claude Haiku Mini** (`ministral-3b-latest`) : modèle compact réactif pour commandes courtes.
- **Claude Code Max / Sonnet / Haiku** : suite de modèles dédiés au développement logiciel, à l'architecture et aux tests unitaires.
- **Claude Vision 5 & Lite** : modèles optimisés pour l'analyse de plans, schémas et OCR.
- **Suppression définitive des modèles Opus 4.6 / 4.5 bloqués** : élimination des erreurs `HTTP 403 Forbidden` sur les comptes gratuits, avec redirection automatique de tous les anciens alias.

### Fallback Vision OCR intelligent
- Si le endpoint OCR dédié (`/v1/ocr`) est limité sur un compte gratuit (`HTTP 429`), l'application bascule automatiquement sur la vision multimodale (`ministral-14b-latest`) pour transcrire les documents et images sans interruption.

### Recherche Web fiabilisée
- Routage automatique des requêtes avec recherche web vers les modèles compatibles avec les connecteurs Mistral (`mistral-small-latest`).

### Libre Claude Coder fiabilisé (Workspace)
- L'agent de code du workspace utilise désormais directement le modèle dédié `codestral-2508` pour des modifications et des commits multi-fichiers instantanés.

### Installateur Windows interactif amélioré (`install.ps1`)
- Détection et installation automatisée de WSL 2 sans blocage.
- Timeout sur les commandes natives pour éviter les gels du terminal.
- Polling du daemon Docker avec journalisation de l'état.
- Anonymisation automatique des chemins utilisateur à l'écran (`~` au lieu de `C:\Users\nom`).
- Gestion de la fermeture de fenêtre PowerShell pour afficher les instructions finales.

### Gestion transparente des erreurs API
- Affichage explicite des messages retournés par l'API au lieu du message opaque générique.

---

## Installation rapide

### Linux / macOS :
```bash
curl -fsSL https://raw.githubusercontent.com/AnARCHIS12/Libre-claude/main/install.sh | sh
```

### Windows PowerShell :
```powershell
irm https://raw.githubusercontent.com/AnARCHIS12/Libre-claude/main/install.ps1 | iex
```

### Docker :
```bash
docker run -d \
  --name libre-claude \
  -p 8173:80 \
  -v "$PWD/data:/var/www/html/data" \
  -v "$PWD/sandbox:/var/www/html/sandbox" \
  liberchat/libre-claude:latest
```
