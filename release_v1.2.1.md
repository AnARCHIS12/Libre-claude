## Nouveautés v1.2.1

### Fix : rotation automatique des clés épuisées
- La rotation de clé API se déclenche maintenant aussi sur l'erreur **402** (crédits épuisés)
- Auparavant seules les erreurs 429 (rate limit) et 401 (clé invalide) déclenchaient le passage à la clé suivante
- Fix appliqué sur les 6 points de rotation dans `claude.php` (chat, transcription, web search, stream, OCR, image)

### Points forts

- Interface Libre Claude rouge/noir avec historique utilisateur
- Image Docker Hub multi-architecture linux/amd64 et linux/arm64 : `liberchat/libre-claude:latest`
- Installateurs interactifs Linux/macOS et Windows
- Workspace GitHub avec OAuth, création de dépôts, édition de fichiers et commits multi-fichiers
- Mémoire automatique utilisateur et contexte workspace
- Recherche dans l'historique des conversations
- Export de conversations en Markdown et JSON
- 3 modèles Claude 4.6 (Opus, Sonnet, Haiku) avec vrais IDs Mistral
- Tableau de bord admin : utilisateurs connectés en temps réel
- **Rotation de clé sur quota épuisé (402) sans interruption**
- Dictée vocale Voxtral
- Interface multilingue : français, anglais, espagnol, allemand et italien
- Licence MIT et README complet avec aperçu de l'interface

## Installation rapide

### Linux/macOS :
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
