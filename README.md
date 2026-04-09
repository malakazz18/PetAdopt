# PetAdopt
🐾 PetAdoption — Plateforme d'adoption d'animaux en Tunisie

A full-stack PHP web platform connecting animal owners, adopters, and veterinarians across Tunisia.


📋 Overview
PetAdoption is a web application built for the Tunisian market that allows users to post animals for adoption, browse available pets by region, submit adoption requests, and connect with verified veterinarians. An admin panel provides full control over users, animals, and vet validation.

✨ Features
🐕 For Adopters

Browse animals available for adoption filtered by region, species, weight, age, vaccination status, and more
View detailed animal profiles with photos, health info, and owner contact
Submit adoption requests directly through the platform
Track request status (pending / accepted / refused) from a personal dashboard

🏠 For Animal Owners

Post animals for adoption with photos, health details, and description
Manage incoming adoption requests — accept or refuse per applicant
Mark animals as Adopted once the process is complete
Adopted animals remain visible on the platform with an "Adopté" overlay

🩺 For Veterinarians

Register with diploma upload for admin verification
Manage a public clinic profile (cabinet name, address, hours, map coordinates)
Animals posted by validated vets display a ⭐ verified badge
Dedicated vet dashboard showing linked animals and pending requests

🛡️ Admin Panel

Dashboard with live stats (users, validated vets, animals, pending validations)
Full CRUD on users, animals, and veterinarians via modal forms
Validate or refuse vet diploma registrations
Alert badge on sidebar when vets are pending validation
Cascade deletes — removing a user cleans up their animals, ads, and requests
