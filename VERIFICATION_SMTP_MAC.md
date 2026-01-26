# ✅ Vérification SMTP AUTH sur Mac

## 🍎 Sur Mac, tu as plusieurs options

### Option 1 : Via le portail web (LE PLUS SIMPLE - Pas besoin de PowerShell)

1. Va sur **https://admin.microsoft.com**
2. Connecte-toi avec un compte administrateur
3. Va dans **Utilisateurs actifs** → Trouve `automate@planifique.com`
4. Clique sur le compte → Onglet **Courrier**
5. Vérifie que **Authentification SMTP AUTH** est activé (case cochée)

### Option 2 : Installer PowerShell Core sur Mac (Si tu veux utiliser PowerShell)

#### Installation :
```bash
# Via Homebrew (si tu as Homebrew installé)
brew install --cask powershell

# Ou télécharge depuis : https://github.com/PowerShell/PowerShell/releases
```

#### Utilisation :
```powershell
# Se connecter
pwsh
Connect-ExchangeOnline

# Vérifier
Get-CASMailbox -Identity "automate@planifique.com" | Select-Object SmtpClientAuthenticationDisabled
```

### Option 3 : Tester directement dans l'application (RECOMMANDÉ)

C'est la méthode la plus simple ! Pas besoin de PowerShell.

#### Étape 1 : Surveiller les logs en temps réel

Ouvre le **Terminal** sur Mac (⌘ + Espace, tape "Terminal") :

```bash
cd ~/PhpstormProjects/planifique-rendezvous
tail -f var/log/dev.log | grep -E "email|smtp|ERREUR|✅|succès" -i
```

**Note** : Ces commandes fonctionnent exactement pareil sur Mac et Linux ! 🎉

#### Étape 2 : Créer un rendez-vous de test

1. Va sur ton application
2. Crée un nouveau rendez-vous
3. Observe le terminal

**Si ça fonctionne**, tu verras :
```
✅ Email de confirmation envoyé avec succès
```

**Si ça ne fonctionne pas**, tu verras encore :
```
❌ ERREUR EMAIL: 535 5.7.139 Authentication unsuccessful...
```

## 📋 Commandes Terminal sur Mac (identiques à Linux)

Toutes ces commandes fonctionnent exactement pareil sur Mac :

```bash
# Aller dans le projet
cd ~/PhpstormProjects/planifique-rendezvous

# Voir les dernières lignes du log
tail -20 var/log/dev.log

# Surveiller les logs en temps réel
tail -f var/log/dev.log

# Filtrer pour voir seulement les erreurs email
tail -f var/log/dev.log | grep -i "email\|smtp\|erreur"

# Voir les 50 dernières lignes avec erreurs
tail -100 var/log/dev.log | grep -i "email\|smtp\|erreur" | tail -20
```

## 🎯 Méthode recommandée pour Mac

**Utilise l'Option 3** (tester directement) :

1. **Ouvre Terminal** (⌘ + Espace → "Terminal")
2. **Lance la surveillance des logs** :
   ```bash
   cd ~/PhpstormProjects/planifique-rendezvous
   tail -f var/log/dev.log | grep -i "email\|smtp\|erreur\|succès"
   ```
3. **Crée un rendez-vous** dans ton application
4. **Observe le terminal** pour voir si l'email part ou s'il y a une erreur

C'est la méthode la plus simple et la plus fiable ! 🚀

## ⏱️ Rappel

Attends **15-30 minutes** après que ton collègue ait activé SMTP AUTH avant de tester.

## 💡 Astuce Mac

Si tu veux garder le terminal ouvert pendant que tu testes, tu peux :
- Utiliser **iTerm2** (terminal amélioré pour Mac)
- Ou simplement laisser le Terminal ouvert en arrière-plan
