<!-- ========================================================================== -->
<!-- VISIONNEUSE PLEIN ÉCRAN (LIGHTBOX), RETOUR EN HAUT, PIED DE PAGE & SCRIPT  -->
<!-- ========================================================================== -->

<!-- Visionneuse plein écran partagée (Articles, Espace Membres, Pêle-mêle & Accueil) -->
<div id="lightbox" class="lightbox-overlay" onclick="fermerLightbox(event)">
    <button type="button" class="lightbox-close" onclick="fermerLightbox(event)" title="Fermer">&times;</button>
    <button type="button" class="lightbox-nav lightbox-prev" onclick="changerPhotoLightbox(-1, event)" title="Photo précédente">&#10094;</button>
    <img id="lightbox-img" class="lightbox-img" src="" alt="Aperçu grand format Les Kamikazes Agathois">
    <button type="button" class="lightbox-nav lightbox-next" onclick="changerPhotoLightbox(1, event)" title="Photo suivante">&#10095;</button>
</div>

<!-- Bouton flottant Retour en haut -->
<button id="backToTop" class="back-to-top" aria-label="Remonter en haut">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="18 15 12 9 6 15"></polyline>
    </svg>
</button>

<footer>
    <p>&copy; Les Kamikazes Agathois - Club d'Aéromodélisme à Agde (34)</p>
    <p>Pour toute information : <a href="mailto:kamikazes34300@gmail.com">kamikazes34300@gmail.com</a></p>
</footer>

<script src="JS/main.js?v=4"></script>
