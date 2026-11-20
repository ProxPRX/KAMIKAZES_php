// ==========================================================================
// SCRIPT PRINCIPAL UNIFIÉ - LES KAMIKAZES AGATHOIS (JS/main.js)
// ==========================================================================

document.addEventListener("DOMContentLoaded", function() {

    // --- 1. BOUTON FLOTTANT "RETOUR EN HAUT" (TOUTES LES PAGES) ---
    const btnTop = document.getElementById("backToTop");

    if (btnTop) {
        window.addEventListener("scroll", function() {
            if (window.scrollY > 300) {
                btnTop.classList.add("show");
            } else {
                btnTop.classList.remove("show");
            }
        });

        btnTop.addEventListener("click", function() {
            window.scrollTo({
                top: 0,
                behavior: "smooth"
            });
        });
    }

    // --- 2. SUIVI AUTOMATIQUE DU SOUS-MENU COLLANT (CLUB, MEMBRES, PÊLE-MÊLE) ---
    const subnav = document.querySelector(".subnav");

    if (subnav) {
        const sections = document.querySelectorAll("main section");
        const navLinks = document.querySelectorAll(".subnav-link");

        function updateMenu() {
            let current = "";
            const scrollPosition = window.scrollY + 150;

            sections.forEach(section => {
                const sectionTop = section.offsetTop;
                const sectionHeight = section.clientHeight;
                if (scrollPosition >= sectionTop && scrollPosition < sectionTop + sectionHeight) {
                    current = section.getAttribute("id");
                }
            });

            if (sections.length > 0 && (window.innerHeight + window.scrollY) >= document.body.offsetHeight - 50) {
                current = sections[sections.length - 1].getAttribute("id");
            }

            navLinks.forEach(link => {
                link.classList.remove("active");
                if (current && link.getAttribute("href") === "#" + current) {
                    link.classList.add("active");
                }
            });

            if (!current && window.scrollY < 100 && navLinks.length > 0) {
                navLinks[0].classList.add("active");
            }
        }

        window.addEventListener("scroll", updateMenu);
        updateMenu();
    }

    // --- 3. AFFICHER / MASQUER LE MOT DE PASSE (PAGE CONNEXION) ---
    const passwordInput = document.getElementById("mot_de_passe");
    const togglePasswordBtn = document.getElementById("togglePasswordBtn");
    const eyeIcon = document.getElementById("eyeIcon");

    if (passwordInput && togglePasswordBtn && eyeIcon) {
        togglePasswordBtn.addEventListener("click", function() {
            const type = passwordInput.getAttribute("type") === "password" ? "text" : "password";
            passwordInput.setAttribute("type", type);

            if (type === "password") {
                eyeIcon.innerHTML = `
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                `;
            } else {
                eyeIcon.innerHTML = `
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                `;
            }
        });
    }

    // --- 4A. AJOUT DYNAMIQUE DE CHAMPS VIDÉO YOUTUBE (ADMINISTRATION D'ARTICLE & PUBLICATION MEMBRE) ---
    const addYoutubeBtn = document.getElementById("add-youtube-btn");
    const youtubeContainer = document.getElementById("youtube-inputs-container");

    if (addYoutubeBtn && youtubeContainer) {
        addYoutubeBtn.addEventListener("click", function() {
            const row = document.createElement("div");
            row.className = "youtube-input-row";
            row.style.cssText = "display:flex; gap:10px; margin-bottom:10px;";
            row.innerHTML = `
                <input type="url" name="videos_youtube[]" placeholder="Ex: https://youtu.be/...">
                <button type="button" class="btn-small btn-small-danger" onclick="this.parentElement.remove()">✖</button>
            `;
            youtubeContainer.appendChild(row);
        });
    }

    // --- 4B. AJOUT DYNAMIQUE DE PARAGRAPHES & MEMBRES DU BUREAU (MAINTENANCE SANS CODE) ---
    document.querySelectorAll(".btn-add-paragraph").forEach(function(btn) {
        btn.addEventListener("click", function() {
            const targetId = this.getAttribute("data-target");
            const container = document.getElementById(targetId);
            if (!container) return;

            const nbActuel = container.querySelectorAll(".cms-paragraph-item").length + 1;
            const bloc = document.createElement("div");
            bloc.className = "cms-field cms-paragraph-item";
            bloc.style.cssText = "background:#f8fafc; padding:12px; border:1px solid #e2e8f0; border-radius:6px;";
            bloc.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <label style="margin:0;">Paragraphe ${nbActuel}</label>
                    <button type="button" class="btn-cms-delete" style="padding:4px 10px; font-size:0.78rem;" onclick="this.closest('.cms-paragraph-item').remove()">🗑️ Supprimer ce paragraphe</button>
                </div>
                <textarea name="paragraphes[]" rows="3" required placeholder="Saisissez le texte de ce nouveau paragraphe..."></textarea>
            `;
            container.appendChild(bloc);
        });
    });

    const btnAddBureau = document.getElementById("btnAddMembreBureau");
    const containerBureau = document.getElementById("container-membres-bureau");
    if (btnAddBureau && containerBureau) {
        btnAddBureau.addEventListener("click", function() {
            const row = document.createElement("div");
            row.className = "cms-bureau-row";
            row.style.cssText = "display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:8px;";
            row.innerHTML = `
                <input type="text" name="bureau_poste[]" required placeholder="Poste (ex: Secrétaire adjoint)" style="flex:1; min-width:160px;">
                <input type="text" name="bureau_nom[]" required placeholder="Prénom NOM" style="flex:1; min-width:160px;">
                <input type="email" name="bureau_email[]" placeholder="E-mail (optionnel)" style="flex:1; min-width:180px;">
                <button type="button" class="btn-cms-delete" style="padding:8px 12px;" onclick="this.parentElement.remove()" title="Retirer ce poste">✖</button>
            `;
            containerBureau.appendChild(row);
        });
    }

    // --- 5. VISIONNEUSE PLEIN ÉCRAN LIGHTBOX UNIVERSELLE (PÊLE-MÊLE, MEMBRES, ARTICLES, ACCUEIL) ---
    const lightbox = document.getElementById("lightbox");
    const lightboxImg = document.getElementById("lightbox-img");

    if (lightbox && lightboxImg) {
        const selecteurPhotos = ".pelemele-item img, .gallery-item img, .pub-main-img, .article-main-image, .article-gallery-grid img, .photo-of-day-img, .event-image.has-photo img";
        let toutesLesPhotos = [];
        let indexPhotoActuelle = 0;

        function actualiserListePhotos() {
            toutesLesPhotos = Array.from(document.querySelectorAll(selecteurPhotos))
                .map(img => img.getAttribute("src"))
                .filter(Boolean);
        }

        window.ouvrirLightbox = function(src) {
            actualiserListePhotos();
            indexPhotoActuelle = toutesLesPhotos.indexOf(src);
            if (indexPhotoActuelle === -1) {
                toutesLesPhotos.unshift(src);
                indexPhotoActuelle = 0;
            }
            lightboxImg.src = toutesLesPhotos[indexPhotoActuelle];
            lightbox.classList.add("active");
        };

        window.fermerLightbox = function(e) {
            if (!e || e.target.id === "lightbox" || e.target.classList.contains("lightbox-close")) {
                lightbox.classList.remove("active");
            }
        };

        window.changerPhotoLightbox = function(direction, e) {
            if (e) e.stopPropagation();
            if (toutesLesPhotos.length === 0) actualiserListePhotos();
            if (toutesLesPhotos.length === 0) return;
            indexPhotoActuelle = (indexPhotoActuelle + direction + toutesLesPhotos.length) % toutesLesPhotos.length;
            lightboxImg.src = toutesLesPhotos[indexPhotoActuelle];
        };

        document.addEventListener("keydown", function(e) {
            if (!lightbox.classList.contains("active")) return;
            if (e.key === "Escape") lightbox.classList.remove("active");
            if (e.key === "ArrowRight") window.changerPhotoLightbox(1);
            if (e.key === "ArrowLeft") window.changerPhotoLightbox(-1);
        });
    }

    // --- 6. GESTIONNAIRE DE LA PAGE DES CARTES D'ADHÉRENTS (admin_cartes.php) ---
    const liveSaisonInput = document.getElementById("live-saison");
    if (liveSaisonInput) {
        liveSaisonInput.addEventListener("input", function() {
            const valeur = this.value;
            document.querySelectorAll(".saison-value").forEach(function(el) {
                el.textContent = valeur;
            });
            const pageTitle = document.getElementById("page-title");
            if (pageTitle) {
                pageTitle.textContent = "Cartes Adhérents Les Kamikazes Agathois - Saison " + valeur;
            }
        });
    }

    document.querySelectorAll(".toggle-card-cb").forEach(function(cb) {
        cb.addEventListener("change", function() {
            const cible = document.getElementById(this.getAttribute("data-target"));
            if (cible) {
                cible.classList.toggle("hidden-card", !this.checked);
            }
        });
    });

    const btnCheckAll = document.getElementById("btnCheckAll");
    if (btnCheckAll) {
        btnCheckAll.addEventListener("click", function() {
            document.querySelectorAll(".toggle-card-cb").forEach(function(cb) {
                cb.checked = true;
                const cible = document.getElementById(cb.getAttribute("data-target"));
                if (cible) cible.classList.remove("hidden-card");
            });
        });
    }

    const btnUncheckAll = document.getElementById("btnUncheckAll");
    if (btnUncheckAll) {
        btnUncheckAll.addEventListener("click", function() {
            document.querySelectorAll(".toggle-card-cb").forEach(function(cb) {
                cb.checked = false;
                const cible = document.getElementById(cb.getAttribute("data-target"));
                if (cible) cible.classList.add("hidden-card");
            });
        });
    }

    const btnPrintPdf = document.getElementById("btnPrintPdf");
    if (btnPrintPdf) {
        btnPrintPdf.addEventListener("click", function(e) {
            e.preventDefault();
            window.print();
        });
    }

    // --- 7. BANDEAUX PLIABLES & LISEUSE LECTURE SEULE DES COMPTES RENDUS (membres.php) ---
    if (typeof pdfjsLib !== "undefined") {
        pdfjsLib.GlobalWorkerOptions.workerSrc = "https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js";
    }

    function chargerPdfLectureSeule(viewerEl) {
        if (!viewerEl || viewerEl.getAttribute("data-loaded") === "1") return;
        viewerEl.setAttribute("data-loaded", "1");

        const pdfUrl = viewerEl.getAttribute("data-pdf-url");
        const pagesContainer = viewerEl.querySelector(".cr-canvas-pages");
        const pageCountEl = viewerEl.querySelector(".cr-page-count");

        if (typeof pdfjsLib === "undefined") {
            pagesContainer.innerHTML = `<p class="cr-loading-msg">⚠️ Impossible de charger la liseuse. Actualisez la page.</p>`;
            return;
        }

        pdfjsLib.getDocument({ url: pdfUrl, withCredentials: true }).promise.then(async function(pdf) {
            pagesContainer.innerHTML = "";
            if (pageCountEl) {
                pageCountEl.textContent = pdf.numPages + (pdf.numPages > 1 ? " pages" : " page");
            }

            const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
            const containerWidth = Math.max(pagesContainer.clientWidth - 32, 300);

            for (let pageNum = 1; pageNum <= pdf.numPages; pageNum++) {
                const page = await pdf.getPage(pageNum);
                const unscaledViewport = page.getViewport({ scale: 1 });
                const scale = Math.min(containerWidth / unscaledViewport.width, 1.5);
                const viewport = page.getViewport({ scale: scale });

                const canvas = document.createElement("canvas");
                const context = canvas.getContext("2d");
                canvas.width = Math.floor(viewport.width * pixelRatio);
                canvas.height = Math.floor(viewport.height * pixelRatio);
                canvas.style.width = Math.floor(viewport.width) + "px";
                canvas.style.height = Math.floor(viewport.height) + "px";

                context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
                pagesContainer.appendChild(canvas);

                await page.render({ canvasContext: context, viewport: viewport }).promise;
            }
        }).catch(function() {
            pagesContainer.innerHTML = `<p class="cr-loading-msg">⚠️ Erreur lors du chargement du compte rendu.</p>`;
        });
    }

    document.querySelectorAll(".cr-accordion-header").forEach(function(header) {
        function toggleAccordion(e) {
            if (e && e.target.closest(".cr-header-actions")) return;

            const item = header.closest(".cr-accordion-item");
            const isOpen = item.classList.toggle("open");
            header.setAttribute("aria-expanded", isOpen ? "true" : "false");

            const labelEl = header.querySelector(".cr-toggle-label");
            if (labelEl) {
                labelEl.textContent = isOpen ? "🔼 Fermer" : "👁️ Consulter";
            }

            if (isOpen) {
                // Mode Admin : chargement différé de l'iframe
                const lazyIframe = item.querySelector(".cr-lazy-iframe");
                if (lazyIframe && !lazyIframe.getAttribute("src")) {
                    lazyIframe.setAttribute("src", lazyIframe.getAttribute("data-src"));
                }
                // Mode Membre : chargement différé en lecture seule sur Canvas
                const readonlyViewer = item.querySelector(".cr-readonly-viewer");
                if (readonlyViewer) {
                    chargerPdfLectureSeule(readonlyViewer);
                }
            }
        }

        header.addEventListener("click", toggleAccordion);
        header.addEventListener("keydown", function(e) {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                toggleAccordion(e);
            }
        });
    });

    // Protection supplémentaire sur la liseuse lecture seule (clic droit et raccourcis Ctrl+S / Ctrl+P)
    const readonlyViewers = document.querySelectorAll(".cr-readonly-viewer");
    if (readonlyViewers.length > 0) {
        readonlyViewers.forEach(function(viewer) {
            viewer.addEventListener("contextmenu", function(e) {
                e.preventDefault();
            });
        });

        document.addEventListener("keydown", function(e) {
            const aUnCrOuvert = document.querySelector(".cr-accordion-item.open .cr-readonly-viewer");
            if (aUnCrOuvert && (e.ctrlKey || e.metaKey) && ["p", "s"].includes(e.key.toLowerCase())) {
                e.preventDefault();
            }
        });
    }

});
