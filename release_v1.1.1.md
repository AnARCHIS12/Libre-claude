## Nouveautés v1.1.1

### Recherche dans l'historique
- Recherche instantanée dans les titres de conversations
- Recherche dans le contenu des messages
- Résultats fusionnés et dédoublonnés
- Icône distinctive pour les correspondances dans le contenu

### Export de conversations
- Export en Markdown (format lisible avec métadonnées)
- Export en JSON (format structuré)
- Boutons d'export dans la barre de modèle
- Téléchargement direct du fichier

### Points forts

- Interface Libre Claude rouge/noir avec historique utilisateur
- Image Docker Hub multi-architecture linux/amd64 et linux/arm64 : liberchat/libre-claude:latest
- Installateurs interactifs Linux/macOS et Windows
- Workspace GitHub avec OAuth, création de dépôts, édition de fichiers et commits multi-fichiers
- Mémoire automatique utilisateur et contexte workspace
- **Recherche dans l'historique des conversations**
- **Export de conversations en Markdown et JSON**
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
