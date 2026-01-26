# Guide des Logs - Vérification Outlook Conseillers

## 📋 Logs Ajoutés

Des logs détaillés ont été ajoutés pour suivre la vérification des calendriers Outlook de tous les conseillers.

## 🔍 Comment Voir les Logs

### Option 1 : Filtrer les logs en temps réel

```bash
tail -f var/log/dev.log | grep -E "OUTLOOK CONSEILLERS|GENERATE SLOTS"
```

### Option 2 : Voir tous les logs récents

```bash
tail -100 var/log/dev.log | grep -E "OUTLOOK CONSEILLERS|GENERATE SLOTS"
```

### Option 3 : Voir uniquement les logs de vérification Outlook

```bash
tail -f var/log/dev.log | grep "OUTLOOK CONSEILLERS"
```

## 📊 Types de Logs

### 1. Logs de Début de Vérification
```
🔍 [OUTLOOK CONSEILLERS] Vérification disponibilité pour X conseiller(s) - Créneau: YYYY-MM-DD HH:MM à HH:MM
```

### 2. Logs des Emails Trouvés
```
📧 [OUTLOOK CONSEILLERS] Conseiller ID X - Email Microsoft: email@planifique.com
📧 [OUTLOOK CONSEILLERS] Conseiller ID X - Email fallback: email@planifique.com
⚠️ [OUTLOOK CONSEILLERS] Conseiller ID X - Pas d'email Microsoft valide
```

### 3. Logs de la Requête API
```
📋 [OUTLOOK CONSEILLERS] Emails à vérifier: email1@planifique.com, email2@planifique.com
📊 [OUTLOOK CONSEILLERS] Réponse API: X calendrier(s) reçu(s)
```

### 4. Logs de Disponibilité
```
✅ [OUTLOOK CONSEILLERS] Conseiller email@planifique.com (index X) - DISPONIBLE
❌ [OUTLOOK CONSEILLERS] Conseiller email@planifique.com (index X) - OCCUPÉ (X créneaux occupés)
```

### 5. Logs de Résultat Final
```
✅ [OUTLOOK CONSEILLERS] Résultat: Au moins un conseiller disponible
❌ [OUTLOOK CONSEILLERS] Résultat: Aucun conseiller disponible (X disponible, Y occupé)
```

### 6. Logs dans la Génération des Créneaux
```
🔄 [GENERATE SLOTS] Vérification Outlook pour groupe - Date: YYYY-MM-DD, Créneau: HH:MM, Nombre de conseillers: X
✅ [GENERATE SLOTS] Au moins un conseiller disponible côté Outlook pour le créneau HH:MM
❌ [GENERATE SLOTS] Tous les conseillers occupés côté Outlook pour le créneau HH:MM
🚫 [GENERATE SLOTS] Créneau HH:MM masqué - Tous les conseillers occupés côté Outlook
💾 [GENERATE SLOTS] Utilisation du cache pour le créneau HH:MM (résultat: disponible/occupé)
⏭️ [GENERATE SLOTS] Créneau HH:MM au-delà de 7 jours - Vérification Outlook ignorée
```

## 🎯 Exemple de Logs Attendus

### Scénario 1 : Groupe avec conseillers disponibles
```
🔄 [GENERATE SLOTS] Vérification Outlook pour groupe - Date: 2026-04-06, Créneau: 10:00, Nombre de conseillers: 3
🔍 [OUTLOOK CONSEILLERS] Vérification disponibilité pour 3 conseiller(s) - Créneau: 2026-04-06 10:00 à 11:00
📧 [OUTLOOK CONSEILLERS] Conseiller ID 14 - Email Microsoft: conseiller1@planifique.com
📧 [OUTLOOK CONSEILLERS] Conseiller ID 15 - Email Microsoft: conseiller2@planifique.com
📧 [OUTLOOK CONSEILLERS] Conseiller ID 16 - Email Microsoft: conseiller3@planifique.com
📋 [OUTLOOK CONSEILLERS] Emails à vérifier: conseiller1@planifique.com, conseiller2@planifique.com, conseiller3@planifique.com
📊 [OUTLOOK CONSEILLERS] Réponse API: 3 calendrier(s) reçu(s)
✅ [OUTLOOK CONSEILLERS] Conseiller conseiller1@planifique.com (index 0) - DISPONIBLE
✅ [GENERATE SLOTS] Au moins un conseiller disponible côté Outlook pour le créneau 10:00
```

### Scénario 2 : Tous les conseillers occupés
```
🔄 [GENERATE SLOTS] Vérification Outlook pour groupe - Date: 2026-04-06, Créneau: 14:00, Nombre de conseillers: 3
🔍 [OUTLOOK CONSEILLERS] Vérification disponibilité pour 3 conseiller(s) - Créneau: 2026-04-06 14:00 à 15:00
📋 [OUTLOOK CONSEILLERS] Emails à vérifier: conseiller1@planifique.com, conseiller2@planifique.com, conseiller3@planifique.com
📊 [OUTLOOK CONSEILLERS] Réponse API: 3 calendrier(s) reçu(s)
❌ [OUTLOOK CONSEILLERS] Conseiller conseiller1@planifique.com (index 0) - OCCUPÉ (2 créneaux occupés)
❌ [OUTLOOK CONSEILLERS] Conseiller conseiller2@planifique.com (index 1) - OCCUPÉ (1 créneaux occupés)
❌ [OUTLOOK CONSEILLERS] Conseiller conseiller3@planifique.com (index 2) - OCCUPÉ (1 créneaux occupés)
❌ [OUTLOOK CONSEILLERS] Résultat: Aucun conseiller disponible (0 disponible, 3 occupé)
❌ [GENERATE SLOTS] Tous les conseillers occupés côté Outlook pour le créneau 14:00
🚫 [GENERATE SLOTS] Créneau 14:00 masqué - Tous les conseillers occupés côté Outlook
```

## ⚠️ Logs d'Erreur

### Erreur de Token
```
⚠️ [OUTLOOK CONSEILLERS] Pas de token pour l'utilisateur ID X - Considéré comme disponible
```

### Erreur API
```
❌ [OUTLOOK CONSEILLERS] Erreur API: [message d'erreur] - Considéré comme disponible
❌ [GENERATE SLOTS] Erreur vérification Outlook conseillers: [message d'erreur]
```

## 🔧 Dépannage

### Si vous ne voyez pas de logs
1. Vérifiez que vous testez avec un **groupe** (pas un conseiller spécifique)
2. Vérifiez que la date est dans les **7 prochains jours**
3. Vérifiez que les conseillers ont des **emails @planifique.com** ou des **microsoftEmail**

### Si tous les conseillers sont toujours "disponibles"
1. Vérifiez que les conseillers ont bien des rendez-vous dans Outlook
2. Vérifiez que les emails Microsoft sont corrects
3. Vérifiez les permissions de l'API Microsoft Graph
