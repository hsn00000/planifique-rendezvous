# ✅ Vérification après activation de SMTP AUTH

## ⏱️ Délai de propagation

Les changements SMTP AUTH peuvent prendre **15 à 30 minutes** (parfois jusqu'à 1 heure) pour être complètement propagés dans le système Microsoft 365.

## 🔍 Comment vérifier que c'est activé

### Méthode 1 : Via PowerShell (Le plus fiable)

```powershell
Connect-ExchangeOnline
Get-CASMailbox -Identity "automate@planifique.com" | Select-Object SmtpClientAuthenticationDisabled
```

**Résultat attendu** : `False` (signifie que SMTP AUTH est activé)

### Méthode 2 : Test direct dans l'application

1. Attendez **au moins 15-30 minutes** après l'activation
2. Créez un **nouveau rendez-vous de test**
3. Vérifiez les logs en temps réel :

```bash
tail -f var/log/dev.log | grep -E "email|smtp|ERREUR|✅|succès" -i
```

**Si ça fonctionne**, vous verrez :
```
✅ Email de confirmation envoyé avec succès
```

**Si ça ne fonctionne toujours pas**, vous verrez encore l'erreur 535.

## 📋 Checklist de vérification

- [ ] Attente de 15-30 minutes minimum
- [ ] Vérification PowerShell que SMTP AUTH est bien activé (`False`)
- [ ] Test avec un nouveau rendez-vous
- [ ] Vérification des logs
- [ ] Vérification du dossier **Spam** du destinataire (au cas où)

## 🎯 Prochaines étapes

1. **Attendez 15-30 minutes** (ou plus si nécessaire)
2. **Testez** en créant un nouveau rendez-vous
3. **Vérifiez les logs** pour voir si l'email part bien
4. **Vérifiez le dossier Spam** de l'adresse destinataire

## ⚠️ Si ça ne fonctionne toujours pas après 30 minutes

1. Vérifiez que SMTP AUTH est bien activé pour :
   - Le **tenant** (organisation)
   - Le **compte spécifique** `automate@planifique.com`

2. Vérifiez que le mot de passe dans `.env.local` est correct :
   ```bash
   MAILER_DSN="smtp://automate@planifique.com:Turgay-Ydriss32*@smtp.office365.com:587"
   ```
   
   **Note** : Le `*` doit être encodé en `%2A` ou la valeur entière entre guillemets.

3. Vérifiez que le compte `automate@planifique.com` :
   - Existe bien
   - A les permissions d'envoi d'email
   - N'a pas de restrictions de sécurité bloquantes

## 💡 Astuce

Vous pouvez vérifier les logs en temps réel pendant le test :

```bash
# Dans un terminal
cd ~/PhpstormProjects/planifique-rendezvous
tail -f var/log/dev.log
```

Puis créez un rendez-vous dans un autre onglet. Vous verrez en direct si l'email part ou s'il y a encore une erreur.
