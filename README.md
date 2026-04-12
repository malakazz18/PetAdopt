# 🐾 PetAdoption

> Une plateforme web de mise en adoption d'animaux en Tunisie, connectant propriétaires, vétérinaires et adoptants.



## ✨ Fonctionnalités

- **Parcourir les animaux** — filtrage par région, espèce et état de santé
- **Demande d'adoption** — les utilisateurs connectés peuvent soumettre une demande directement
- **Espace propriétaire** — gérer ses animaux, accepter ou refuser les demandes
- **Espace vétérinaire** — profil cabinet, horaires d'ouverture, localisation GPS
- **Signalement d'animaux errants** — avec position GPS sur carte interactive
- **Diagnostic santé IA** — analyse de l'état de santé de l'animal via photo
- **Panel d'administration** — validation des comptes vétérinaires, gestion des utilisateurs et animaux

---

## 🛠️ Stack technique

| Couche | Technologie |
|--------|------------|
| Backend | PHP 8+ |
| Base de données | MySQL / MariaDB |
| Frontend | HTML, CSS, JavaScript (vanilla) |
| Cartes | Leaflet.js + OpenStreetMap |
| Sécurité | CSRF, sessions sécurisées, upload filtré |

---

## 📁 Structure du projet

```
petadoption/
├── config.php               # Configuration DB, sessions, helpers
├── accueil.php              # Page d'accueil & liste des animaux/vétérinaires
├── animal-details.php       # Fiche détaillée d'un animal
├── ajouter-animal.php       # Formulaire d'ajout d'animal
├── demander-adoption.php    # Logique de demande d'adoption
├── mon-espace.php           # Espace utilisateur (animaux, demandes, cabinet)
├── espace-veterinaire.php   # Tableau de bord vétérinaire
├── veterinaire-details.php  # Profil public d'un vétérinaire
├── admin.php                # Panel administrateur
├── connexion.php            # Authentification
└── uploads/                 # Photos uploadées (animaux, vétérinaires)
```

---

 🚀 Installation locale

 Prérequis
- PHP 8.0+
- MySQL / MariaDB
- Serveur local : [XAMPP](https://www.apachefriends.org/) 

 👥 Rôles utilisateurs

| Rôle | Accès |
|------|-------|
| **Visiteur** | Parcourir les animaux et vétérinaires |
| **Utilisateur** | Adopter, gérer ses animaux, son espace |
| **Vétérinaire** | Espace cabinet, horaires, animaux liés |
| **Administrateur** | Validation vétérinaires, gestion complète |

---

🔒 Sécurité

- Protection CSRF sur tous les formulaires
- Sessions sécurisées avec régénération d'ID
- Upload d'images filtré (type MIME, dimensions, taille)
- Suppression des données EXIF des photos
- Requêtes préparées PDO (protection injection SQL)
- En-têtes de sécurité HTTP (CSP, X-Frame-Options…)


 📍 Contexte

Projet développé dans le cadre d'un cursus universitaire en Tunisie, avec pour objectif de faciliter l'adoption animale et de valoriser le rôle des vétérinaires locaux.

📄 Licence
MIT 




Ce projet est à usage éducatif. Tous droits réservés © 2026 PetAdoption.
