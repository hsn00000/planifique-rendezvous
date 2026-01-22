# Comment activer SMTP AUTH dans Microsoft 365

## Option 1 : Via le Centre d'administration (pour un utilisateur spécifique)

1. Connectez-vous à https://admin.microsoft.com
2. Allez dans **Utilisateurs actifs**
3. Sélectionnez l'utilisateur `automate@planifique.com`
4. Cliquez sur l'onglet **Courrier**
5. Activez **"Authentification SMTP AUTH"**

## Option 2 : Via PowerShell (pour tout le tenant)

```powershell
# Connectez-vous à Exchange Online
Connect-ExchangeOnline

# Activez SMTP AUTH pour tout le tenant
Set-TransportConfig -SmtpClientAuthenticationDisabled $false

# Ou pour un utilisateur spécifique
Set-CASMailbox -Identity automate@planifique.com -SmtpClientAuthenticationDisabled $false
```

## Option 3 : Utiliser OAuth2 au lieu de SMTP AUTH

Si vous ne pouvez pas activer SMTP AUTH, vous pouvez utiliser OAuth2 avec Microsoft Graph API.

## Option 4 : Utiliser un service d'email tiers

- **SendGrid** : https://sendgrid.com
- **Mailgun** : https://www.mailgun.com
- **Amazon SES** : https://aws.amazon.com/ses/
- **Postmark** : https://postmarkapp.com

Ces services sont souvent plus fiables et plus faciles à configurer que SMTP direct.
