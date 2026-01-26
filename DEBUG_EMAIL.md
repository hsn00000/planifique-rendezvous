# Guide de débogage pour les problèmes d'email

## 🔍 Problème identifié

D'après les logs, l'erreur est :
```
535 5.7.139 Authentication unsuccessful, SmtpClientAuthentication is disabled for the Tenant.
```

**Cela signifie que SMTP AUTH est désactivé dans votre tenant Microsoft 365.**

## ✅ Solutions

### Solution 1 : Activer SMTP AUTH dans Microsoft 365 (RECOMMANDÉ)

#### Via Microsoft 365 Admin Center :
1. Connectez-vous à https://admin.microsoft.com
2. Allez dans **Paramètres** > **Paramètres d'organisation** > **Courrier**
3. Activez **Authentification SMTP AUTH**

#### Via PowerShell :
```powershell
Connect-ExchangeOnline
Set-TransportConfig -SmtpClientAuthenticationDisabled $false
```

### Solution 2 : Utiliser un service d'email alternatif

Si vous ne pouvez pas activer SMTP AUTH, utilisez un service alternatif :

#### Mailtrap (pour les tests) :
```bash
MAILER_DSN=smtp://USERNAME:PASSWORD@smtp.mailtrap.io:2525
```

#### SendGrid :
```bash
MAILER_DSN=smtp://apikey:VOTRE_API_KEY@smtp.sendgrid.net:587
```

## 🐛 Mode débogage activé

Le code a été amélioré pour afficher les erreurs en mode développement :

1. **Message Flash** : Un message d'avertissement s'affiche sur la page de succès
2. **Logs détaillés** : Toutes les erreurs sont loggées dans `var/log/dev.log`
3. **Option dd()** : Décommentez la ligne dans `BookingController.php` (ligne ~673) pour voir l'erreur immédiatement

### Pour activer le mode dd() (arrêt immédiat) :

Dans `src/Controller/BookingController.php`, ligne ~673, décommentez :
```php
dd('❌ ERREUR EMAIL', $errorMessage, $e);
```

Cela affichera l'erreur complète directement dans le navigateur.

## 📋 Vérifications

### 1. Configuration Messenger
Les emails sont en mode **sync** en développement (voir `config/packages/dev/messenger.yaml`), donc ils sont envoyés immédiatement. Pas besoin de lancer un worker.

### 2. Vérifier les logs
```bash
tail -f var/log/dev.log | grep -i "email\|smtp\|erreur"
```

### 3. Vérifier la configuration SMTP
Vérifiez que votre `.env.local` contient bien :
```bash
MAILER_DSN="smtp://automate@planifique.com:Turgay-Ydriss32*@smtp.office365.com:587"
```

**Note** : Le caractère `*` doit être encodé en `%2A` ou la valeur entière entre guillemets.

## 🎯 Prochaines étapes

1. **Activez SMTP AUTH** dans Microsoft 365 (Solution 1)
2. **Testez** en créant un nouveau rendez-vous
3. **Vérifiez les logs** pour confirmer que l'email part bien
4. **Vérifiez le dossier Spam** de l'adresse destinataire

## 📝 Note importante

Le code fonctionne correctement. Le problème vient uniquement de la configuration Microsoft 365 qui bloque l'authentification SMTP. Une fois SMTP AUTH activé, les emails partiront automatiquement.
