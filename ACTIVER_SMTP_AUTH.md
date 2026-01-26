# 🔧 Activer SMTP AUTH dans Microsoft 365

## ❌ Problème identifié

L'erreur `535 5.7.139 Authentication unsuccessful, SmtpClientAuthentication is disabled for the Tenant` signifie que **SMTP AUTH est désactivé** pour votre tenant Microsoft 365.

## ✅ Solution : Activer SMTP AUTH

### Méthode 1 : Via Microsoft 365 Admin Center (RECOMMANDÉ)

#### Étape 1 : Accéder au centre d'administration
1. Connectez-vous à **https://admin.microsoft.com**
2. Connectez-vous avec un compte **Administrateur global** ou **Administrateur Exchange**

#### Étape 2 : Activer SMTP AUTH pour le tenant
1. Dans le menu de gauche, allez dans **Paramètres** → **Paramètres d'organisation**
2. Cliquez sur l'onglet **Courrier**
3. Recherchez la section **Authentification SMTP AUTH**
4. **Cochez la case** pour activer SMTP AUTH pour votre organisation
5. Cliquez sur **Enregistrer**

**⏱️ Délai** : Les changements peuvent prendre **15-30 minutes** pour être appliqués.

#### Étape 3 : Activer SMTP AUTH pour le compte spécifique
1. Allez dans **Utilisateurs actifs** → Trouvez `automate@planifique.com`
2. Cliquez sur le compte → Onglet **Courrier**
3. Cliquez sur **Gérer les paramètres de messagerie**
4. Activez **Authentification SMTP AUTH** pour ce compte
5. Enregistrez

### Méthode 2 : Via PowerShell (Plus rapide)

#### Étape 1 : Installer le module Exchange Online
```powershell
Install-Module -Name ExchangeOnlineManagement -Force
```

#### Étape 2 : Se connecter
```powershell
Connect-ExchangeOnline
# Connectez-vous avec votre compte administrateur
```

#### Étape 3 : Activer SMTP AUTH pour le tenant
```powershell
Set-TransportConfig -SmtpClientAuthenticationDisabled $false
```

#### Étape 4 : Activer SMTP AUTH pour le compte spécifique
```powershell
Set-CASMailbox -Identity "automate@planifique.com" -SmtpClientAuthenticationDisabled $false
```

#### Étape 5 : Vérifier l'activation
```powershell
Get-CASMailbox -Identity "automate@planifique.com" | Select-Object SmtpClientAuthenticationDisabled
# Doit retourner : False
```

### Méthode 3 : Via Azure AD (Alternative)

Si vous avez accès à Azure AD :
1. Allez sur **https://portal.azure.com**
2. **Azure Active Directory** → **Utilisateurs**
3. Trouvez `automate@planifique.com`
4. **Paramètres de messagerie** → Activez **SMTP AUTH**

## 🔍 Vérification

Après activation, attendez **15-30 minutes**, puis testez :

1. Créez un nouveau rendez-vous
2. Vérifiez les logs : `tail -f var/log/dev.log | grep -i email`
3. Vous devriez voir : `✅ Email de confirmation envoyé avec succès`

## ⚠️ Si SMTP AUTH ne peut pas être activé

### Alternative 1 : Utiliser Microsoft Graph API
Au lieu de SMTP, utilisez l'API Microsoft Graph pour envoyer des emails. Cela nécessite une modification du code.

### Alternative 2 : Utiliser un service d'email tiers

#### SendGrid (Recommandé pour production)
1. Créez un compte sur https://sendgrid.com
2. Générez une clé API
3. Dans `.env.local` :
```bash
MAILER_DSN=smtp://apikey:VOTRE_API_KEY@smtp.sendgrid.net:587
```

#### Mailtrap (Pour les tests)
1. Créez un compte sur https://mailtrap.io
2. Récupérez les identifiants SMTP
3. Dans `.env.local` :
```bash
MAILER_DSN=smtp://USERNAME:PASSWORD@smtp.mailtrap.io:2525
```

#### Mailgun
```bash
MAILER_DSN=smtp://USERNAME:PASSWORD@smtp.mailgun.org:587
```

## 📋 Checklist

- [ ] SMTP AUTH activé au niveau du tenant
- [ ] SMTP AUTH activé pour le compte `automate@planifique.com`
- [ ] Attente de 15-30 minutes pour la propagation
- [ ] Test avec un nouveau rendez-vous
- [ ] Vérification des logs
- [ ] Vérification du dossier Spam du destinataire

## 🔗 Liens utiles

- Documentation Microsoft : https://aka.ms/smtp_auth_disabled
- Guide Exchange Online : https://docs.microsoft.com/en-us/exchange/clients-and-mobile-in-exchange-online/authenticated-client-smtp-submission

## 💡 Note importante

**SMTP AUTH est désactivé par défaut** dans les nouveaux tenants Microsoft 365 pour des raisons de sécurité. Vous devez l'activer explicitement si vous avez besoin d'envoyer des emails via SMTP depuis des applications.

Une fois activé, votre code fonctionnera immédiatement sans modification.
