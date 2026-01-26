# 🧪 Guide de Test - Disponibilité Semaine 2-6 Février 2026

## Objectif

Tester que le système détecte correctement les rendez-vous Outlook pour la semaine du 2-6 février 2026 et calcule correctement la disponibilité des salles.

## Prérequis

1. Avoir un conseiller avec un compte Microsoft connecté
2. Avoir des bureaux configurés avec des emails Outlook (Geneve Bureau Client 1 à 6)
3. Les rendez-vous doivent être synchronisés dans Outlook

## Exécution du Test

### Commande Symfony (Recommandé)

```bash
php bin/console app:test-availability-week
```

Cette commande va :
- Lister tous les bureaux de Genève
- Sélectionner un conseiller avec compte Microsoft
- Tester la disponibilité pour chaque créneau de la semaine
- Afficher un résumé avec les créneaux disponibles/occupés

## Ce que le test vérifie

1. **Bureaux disponibles en BDD locale** : Vérifie que les salles ne sont pas déjà réservées dans la base de données
2. **Bureaux disponibles côté Outlook** : Vérifie via l'API Microsoft Graph que les salles sont libres
3. **Conseillers disponibles** : Vérifie qu'au moins un conseiller est disponible pour le créneau
4. **Logique des salles** : Si salle 1 et 2 occupées, salle 3 doit être disponible

## Créneaux testés

- **Lundi 2 février** : 10:00, 11:00, 12:30, 14:00, 15:00
- **Mardi 3 février** : 10:00, 11:30, 13:00, 14:30
- **Mercredi 4 février** : 09:30, 11:00, 11:30, 13:00, 14:00
- **Jeudi 5 février** : 09:30, 11:30, 12:30, 14:00, 15:00
- **Vendredi 6 février** : 09:30, 10:30, 11:30, 14:00, 15:00

## Interprétation des résultats

### ✅ Créneau DISPONIBLE
- Au moins une salle est libre côté Outlook
- Au moins un conseiller est disponible
- Le système devrait afficher ce créneau dans le calendrier

### ❌ Créneau OCCUPÉ
- Toutes les salles sont réservées côté Outlook
- OU tous les conseillers sont occupés
- Le système devrait masquer ce créneau

## Vérifications manuelles

Après avoir exécuté le test, vérifiez manuellement dans Outlook :

1. **Lundi 2 février 10:00** : D'après le calendrier, plusieurs rendez-vous sont prévus
   - Vérifiez que le système détecte correctement les salles occupées
   - Vérifiez qu'il reste au moins une salle libre

2. **Mercredi 4 février 11:30** : "Maël BRET" dans "Geneve Bureau Client 2"
   - Vérifiez que cette salle est détectée comme occupée
   - Vérifiez que les autres salles (1, 3, 4, 5, 6) sont disponibles

3. **Jeudi 5 février 14:00** : Plusieurs rendez-vous prévus
   - Vérifiez la logique : si 6 salles occupées, la 7ème doit être disponible

## Problèmes courants

### "Aucun conseiller avec compte Microsoft trouvé"
- Vérifiez qu'au moins un conseiller a un compte Microsoft connecté
- Connectez-vous via `/microsoft/auth`

### "Erreur vérification Outlook"
- Vérifiez que le token d'accès est valide
- Vérifiez les permissions de l'application Microsoft

### "Toutes les salles occupées" alors qu'il devrait y en avoir de libres
- Vérifiez que les emails des bureaux sont correctement configurés
- Vérifiez que les calendriers des salles sont bien partagés

## Commandes utiles

```bash
# Voir les logs en temps réel
tail -f var/log/dev.log | grep -i "outlook\|disponible\|occupé"

# Vérifier les bureaux en base
php bin/console doctrine:query:sql "SELECT * FROM bureau WHERE lieu = 'Cabinet-geneve'"

# Vérifier les rendez-vous d'une date
php bin/console doctrine:query:sql "SELECT * FROM rendez_vous WHERE DATE(date_debut) = '2026-02-04'"
```
