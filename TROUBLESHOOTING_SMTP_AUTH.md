# 🔍 Diagnostic : Erreur d'authentification SMTP

## ✅ Bonne nouvelle

L'erreur a **changé** ! Cela signifie que **SMTP AUTH est probablement activé** maintenant.

**Avant** : `SmtpClientAuthentication is disabled for the Tenant`  
**Maintenant** : `the request did not meet the criteria to be authenticated successfully`

## ❌ Problème actuel

L'authentification échoue pour le compte `turgay.demirtas@planifique.com`. Plusieurs causes possibles :

### 1. SMTP AUTH non activé pour ce compte spécifique

Même si SMTP AUTH est activé au niveau du tenant, il faut aussi l'activer **pour chaque compte individuel**.

**Solution** : Via PowerShell ou Admin Center, activez SMTP AUTH pour `turgay.demirtas@planifique.com` :

```powershell
Connect-ExchangeOnline
Set-CASMailbox -Identity "turgay.demirtas@planifique.com" -SmtpClientAuthenticationDisabled $false
```

### 2. Mot de passe incorrect

Vérifiez que le mot de passe dans `.env.local` est correct :
- `Sinan.Sena7432*` (avec le `*` encodé en `%2A`)

### 3. Authentification multi-facteurs (MFA) activée

Si MFA est activé sur le compte, vous **ne pouvez pas** utiliser le mot de passe normal. Il faut créer un **"Mot de passe d'application"**.

**Comment créer un mot de passe d'application** :
1. Connectez-vous à https://account.microsoft.com/security
2. Allez dans **Sécurité** → **Options de sécurité supplémentaires**
3. Cliquez sur **Mots de passe d'application**
4. Créez un mot de passe d'application pour "Messagerie"
5. Utilisez ce mot de passe (16 caractères) dans `.env.local` au lieu du mot de passe normal

### 4. Restrictions de sécurité

Le compte peut avoir des restrictions qui bloquent l'authentification SMTP :
- Blocage des applications moins sécurisées
- Restrictions géographiques
- Politiques de sécurité du tenant

## 🔧 Solutions à essayer

### Solution 1 : Vérifier et activer SMTP AUTH pour le compte

```powershell
# Vérifier l'état actuel
Get-CASMailbox -Identity "turgay.demirtas@planifique.com" | Select-Object SmtpClientAuthenticationDisabled

# Si c'est True, l'activer
Set-CASMailbox -Identity "turgay.demirtas@planifique.com" -SmtpClientAuthenticationDisabled $false
```

### Solution 2 : Utiliser un compte avec SMTP AUTH confirmé

Si `automate@planifique.com` a SMTP AUTH activé et fonctionne, utilisez ce compte :

```bash
MAILER_DSN="smtp://automate@planifique.com:Turgay-Ydriss32%2A@smtp.office365.com:587"
```

### Solution 3 : Créer un mot de passe d'application (si MFA activé)

Si MFA est activé, créez un mot de passe d'application et utilisez-le dans `.env.local`.

### Solution 4 : Vérifier le mot de passe

Testez le mot de passe en vous connectant manuellement à Outlook avec ce compte pour confirmer qu'il est correct.

## 📋 Checklist de vérification

- [ ] SMTP AUTH activé au niveau du tenant ✅ (probablement fait)
- [ ] SMTP AUTH activé pour `turgay.demirtas@planifique.com` ❓ (à vérifier)
- [ ] Mot de passe correct dans `.env.local` ❓
- [ ] MFA désactivé OU mot de passe d'application créé ❓
- [ ] Pas de restrictions de sécurité bloquantes ❓

## 🎯 Prochaine étape recommandée

1. **Vérifiez que SMTP AUTH est activé pour le compte** `turgay.demirtas@planifique.com`
2. **Testez avec le compte** `automate@planifique.com` si celui-ci a SMTP AUTH activé
3. **Si MFA est activé**, créez un mot de passe d'application

## 💡 Astuce

Pour tester rapidement, vous pouvez temporairement utiliser `automate@planifique.com` si ce compte a SMTP AUTH activé et fonctionne.
