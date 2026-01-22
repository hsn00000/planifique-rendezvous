# 📅 Planifique - Application de Prise de Rendez-vous

> Application web de gestion de rendez-vous type "Calendly" avec synchronisation automatique Microsoft Outlook

[![Symfony](https://img.shields.io/badge/Symfony-7.0-000000?style=flat&logo=symfony)](https://symfony.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat&logo=php)](https://www.php.net)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat&logo=mysql)](https://www.mysql.com)
[![License](https://img.shields.io/badge/License-Proprietary-red)](LICENSE)

---

## 📋 Table des matières

- [Présentation](#-présentation)
- [Contexte & Problématique](#-contexte--problématique)
- [Objectifs](#-objectifs)
- [Fonctionnalités](#-fonctionnalités)
- [Architecture Technique](#-architecture-technique)
- [Installation](#-installation)
- [Configuration](#-configuration)
- [Utilisation](#-utilisation)
- [Technologies](#-technologies)
- [Auteur](#-auteur)

---

## 🎯 Présentation

**Planifique** est une application web interne développée pour simplifier la gestion des rendez-vous entre conseillers et clients. L'application s'intègre nativement avec Microsoft 365 et synchronise automatiquement les créneaux avec Outlook.

### ✨ Points clés

- 🔐 **Authentification Microsoft** : Connexion SSO avec Azure AD
- 📅 **Synchronisation Outlook** : Création automatique des événements dans le calendrier
- 🏢 **Gestion des salles** : Réservation automatique des bureaux et salles de réunion
- 📧 **Notifications** : Emails de confirmation et rappels automatiques
- 🎨 **Interface moderne** : Design responsive type "Calendly"
- 👥 **Round Robin** : Attribution automatique des rendez-vous aux conseillers disponibles

---

## 📖 Contexte & Problématique

Dans une entreprise comptant environ **20 conseillers**, la prise de rendez-vous avec les clients posait plusieurs défis :

### Problèmes identifiés

- ⏱️ **Perte de temps** : Plusieurs échanges d'emails pour trouver un créneau disponible
- ❌ **Risque d'erreurs** : Créneaux déjà pris, double réservation
- 🏢 **Gestion des salles** : Difficulté à coordonner les réservations de bureaux
- 📱 **Absence de rappels** : Clients qui oublient leurs rendez-vous

### Solution apportée

**Planifique** centralise toute la gestion des rendez-vous :
- Les conseillers partagent un lien unique à leurs clients
- Les clients choisissent un créneau disponible en temps réel
- L'application vérifie automatiquement les disponibilités dans Outlook
- Les rendez-vous sont créés automatiquement dans le calendrier Microsoft
- Les salles sont réservées automatiquement si nécessaire

---

## 🎯 Objectifs

### Pour les Conseillers

- ✅ Se connecter avec leur compte Microsoft (pas de nouveau compte)
- ✅ Définir leurs heures disponibles (planning hebdomadaire récurrent)
- ✅ Créer des types de rendez-vous (durée, description, couleur)
- ✅ Visualiser leurs rendez-vous dans un tableau de bord
- ✅ Gérer leurs disponibilités facilement

### Pour les Clients

- ✅ Réserver un créneau en quelques clics via un lien partagé
- ✅ Voir uniquement les créneaux réellement disponibles
- ✅ Recevoir une confirmation par email avec les détails
- ✅ Annuler ou modifier leur rendez-vous via un lien sécurisé

### Pour le Système

- ✅ Vérifier les disponibilités en temps réel dans Outlook
- ✅ Créer automatiquement les événements dans le calendrier Microsoft
- ✅ Réserver les salles disponibles (éviter les doubles réservations)
- ✅ Envoyer les notifications (confirmation, rappel)
- ✅ Synchroniser les modifications (annulation, modification)

---

## 🚀 Fonctionnalités

### 🔐 Authentification

- **Connexion Microsoft** : SSO avec Azure AD (OAuth2)
- **Authentification classique** : Login/password pour les administrateurs
- **Gestion des tokens** : Refresh token automatique pour maintenir la session

### 📅 Gestion des Disponibilités

- **Planning hebdomadaire** : Définition de créneaux récurrents (ex: Lundi 9h-12h)
- **Blocage de créneaux** : Possibilité de bloquer certains créneaux (non supprimables)
- **Vérification Outlook** : Vérification automatique des périodes occupées dans Outlook
- **Gestion des tampons** : Tampons avant/après les rendez-vous pour éviter les chevauchements

### 🎫 Types de Rendez-vous

- **Création d'événements** : Durée, titre, description, couleur
- **Round Robin** : Attribution automatique aux conseillers disponibles
- **Limite de réservation** : Limite configurable en mois (ex: 12 mois max)
- **Délai minimum** : Délai minimum avant la réservation (ex: 2 heures)

### 🏢 Gestion des Salles

- **Création de bureaux** : Gestion des salles par lieu (Genève, Archamps)
- **Réservation automatique** : Attribution automatique d'une salle disponible
- **Vérification Outlook** : Vérification de la disponibilité des salles via l'API Microsoft Graph
- **Prévention des conflits** : Empêche la double réservation d'une même salle

### 📧 Notifications

- **Email de confirmation** : Envoi automatique au client après réservation
- **Email au conseiller** : Notification au conseiller d'un nouveau rendez-vous
- **Lien d'annulation/modification** : Token sécurisé pour gérer le rendez-vous
- **Synchronisation Outlook** : Suppression/modification dans Outlook lors des changements

### 👥 Gestion des Groupes

- **Création de groupes** : Organisation des conseillers par équipe
- **Round Robin par groupe** : Attribution automatique au sein d'un groupe
- **Tableau de bord** : Vue d'ensemble des rendez-vous par groupe

### 🎨 Interface Client

- **Calendrier mensuel** : Affichage des créneaux disponibles par mois
- **Chargement progressif** : Lazy loading des mois pour optimiser les performances
- **Sélection intuitive** : Choix de la date, de l'heure et du lieu
- **Formulaire simplifié** : Saisie des informations client
- **Récapitulatif** : Page de confirmation avant validation finale

### 🔒 Sécurité

- **Validation métier** : Contrainte personnalisée pour éviter les chevauchements
- **Tokens sécurisés** : Génération de tokens pour l'annulation/modification
- **Protection CSRF** : Protection contre les attaques CSRF
- **Gestion des sessions** : Stockage sécurisé des données temporaires

---

## 🏗️ Architecture Technique

### Stack Technique

```
Backend    : Symfony 7 (PHP 8.2+)
Database   : MySQL 8.0 (Doctrine ORM)
Frontend   : Twig, Bootstrap 5, Vanilla JavaScript
Admin      : EasyAdmin 4
Auth       : Symfony Security + OAuth2 (Microsoft Azure AD)
API        : Microsoft Graph API
Email      : Symfony Mailer (SMTP)
```

### Modèle de Données

```
User (Conseiller/Admin)
  ├── Groupe
  ├── DisponibiliteHebdomadaire[]
  └── RendezVous[]

Evenement (Type de RDV)
  ├── Groupe
  └── RendezVous[]

RendezVous
  ├── Evenement
  ├── User (Conseiller)
  ├── Bureau (Optionnel)
  └── MicrosoftAccount (Token OAuth)

Bureau (Salle)
  ├── Lieu (Genève/Archamps)
  └── Email Outlook
```

### Services Principaux

- **`OutlookService`** : Gestion de l'API Microsoft Graph (création, modification, suppression d'événements)
- **`BookingController`** : Logique métier de réservation (génération de créneaux, validation)
- **`MicrosoftAuthenticator`** : Authentification OAuth2 avec Azure AD

---

## 📦 Installation

### Prérequis

- PHP 8.2 ou supérieur
- Composer
- MySQL 8.0 ou supérieur
- Node.js (pour Asset Mapper)
- Compte Microsoft Azure AD (pour l'authentification)

### Étapes d'installation

1. **Cloner le repository**

```bash
git clone https://github.com/votre-username/planifique-rendezvous.git
cd planifique-rendezvous
```

2. **Installer les dépendances**

```bash
composer install
npm install
```

3. **Configurer l'environnement**

```bash
cp .env .env.local
```

Éditez `.env.local` et configurez :

```env
# Base de données
DATABASE_URL="mysql://user:password@127.0.0.1:3306/planifrdv?serverVersion=8.0.32&charset=utf8mb4"

# Microsoft Azure AD
OAUTH_AZURE_CLIENT_ID=your-client-id
OAUTH_AZURE_CLIENT_SECRET=your-client-secret
MICROSOFT_TENANT_ID=your-tenant-id

# Email (SMTP)
MAILER_DSN=smtp://user:password@smtp.example.com:587
```

4. **Créer la base de données**

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load
```

5. **Compiler les assets**

```bash
npm run build
```

6. **Lancer le serveur**

```bash
symfony server:start
# ou
php -S localhost:8000 -t public
```

L'application est accessible sur `http://localhost:8000`

---

## ⚙️ Configuration

### Configuration Microsoft Azure AD

1. Créer une application dans [Azure Portal](https://portal.azure.com)
2. Configurer les redirections d'URI : `http://localhost:8000/connect/microsoft/check`
3. Activer les permissions API :
   - `Calendars.ReadWrite`
   - `offline_access`
   - `User.Read`
4. Récupérer le `Client ID`, `Client Secret` et `Tenant ID`

### Configuration Email

Pour utiliser SMTP avec Microsoft 365, vous devez activer SMTP AUTH :

```powershell
# Via PowerShell Exchange Online
Connect-ExchangeOnline
Set-CASMailbox -Identity votre-email@domaine.com -SmtpClientAuthenticationEnabled $true
```

### Configuration des Salles

1. Accéder à l'interface d'administration (`/admin`)
2. Créer les bureaux avec :
   - Nom de la salle
   - Lieu (Genève/Archamps)
   - Email Outlook de la salle (pour la réservation automatique)

---

## 💻 Utilisation

### Pour les Administrateurs

1. **Créer un groupe** : `/admin` → Groupes → Nouveau
2. **Créer des conseillers** : Utilisateurs → Nouveau (ou connexion Microsoft)
3. **Créer des événements** : Événements → Nouveau (définir durée, groupe, round robin)
4. **Gérer les salles** : Bureaux → Nouveau (nom, lieu, email Outlook)

### Pour les Conseillers

1. **Se connecter** : `/login` ou `/connect/microsoft`
2. **Définir ses disponibilités** : `/mon-agenda` → Ajouter un créneau
3. **Partager le lien** : Copier le lien de réservation de l'événement
4. **Voir ses rendez-vous** : Tableau de bord avec les rendez-vous à venir

### Pour les Clients

1. **Ouvrir le lien** : Lien partagé par le conseiller
2. **Choisir un créneau** : Sélectionner la date et l'heure disponibles
3. **Remplir le formulaire** : Nom, prénom, email, téléphone, lieu
4. **Confirmer** : Vérifier le récapitulatif et valider
5. **Recevoir la confirmation** : Email avec les détails et liens d'annulation/modification

---

## 🛠️ Technologies

### Backend

- **Symfony 7** : Framework PHP moderne avec attributs
- **Doctrine ORM** : Mapping objet-relationnel
- **EasyAdmin 4** : Interface d'administration
- **Symfony Security** : Authentification et autorisation
- **OAuth2 Client Bundle** : Intégration Microsoft Azure AD

### Frontend

- **Twig** : Moteur de template
- **Bootstrap 5** : Framework CSS
- **Vanilla JavaScript** : Pas de framework JS lourd
- **Asset Mapper** : Gestion des assets modernes

### API & Services

- **Microsoft Graph API** : Synchronisation Outlook
- **Guzzle HTTP** : Client HTTP pour les requêtes API
- **Symfony Mailer** : Envoi d'emails transactionnels

### Outils de Développement

- **PHPUnit** : Tests unitaires
- **Doctrine Migrations** : Versioning de la base de données
- **Symfony Debug Toolbar** : Debugging en développement

---

## 📝 Compétences Mobilisées

Ce projet a permis de développer les compétences suivantes (Référentiel BTS SIO) :

- **SLAM 1** : Gérer le patrimoine informatique (Mise à jour entités, Migrations)
- **SLAM 2** : Développer la présence en ligne (Interface client responsive)
- **SLAM 3** : Développer une solution applicative (Architecture Symfony, Services)
- **SLAM 4** : Travailler en mode projet (Intégration continue, corrections de bugs)
- **SLAM 5** : Mettre à disposition des services informatiques (API Outlook, Service de disponibilité)

---

## 🎓 Auteur

**Étudiant BTS SIO (Option SLAM)**

- 📧 Email : kanicihasan90@gmail.com
- 💼 Entreprise : Planifique SA
- 📅 Période : Stage 2ème année

---

## 📄 License

Ce projet est propriétaire et appartient à **Planifique SA**. Tous droits réservés.

---

## 🙏 Remerciements

- **Planifique SA** pour l'accueil en stage
- **La communauté Symfony** pour la documentation et les ressources

---

## 📚 Documentation Complémentaire

- [Documentation Symfony](https://symfony.com/doc/current/index.html)
- [Microsoft Graph API](https://docs.microsoft.com/fr-fr/graph/overview)
- [EasyAdmin Documentation](https://symfony.com/bundles/EasyAdminBundle/current/index.html)

---
