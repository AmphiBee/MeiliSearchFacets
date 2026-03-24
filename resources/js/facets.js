/**
 * MeilisearchFacets — Composant Alpine.js générique pour les listings facettés.
 *
 * Initialisation sur l'élément conteneur :
 *
 *   <div
 *     x-data="MeilisearchFacets()"
 *     data-ajax-action="tds_references_facets"
 *     data-hits-per-page="9"
 *   >
 *     ...filtres, grille, pagination...
 *   </div>
 *
 * Conventions de nommage des inputs dans le conteneur :
 *   - Taxonomies  : name="_search_{taxonomy}"     ex: name="_search_activity-sector"
 *   - Plages num. : name="{groupe}_range"          ex: name="price_range"
 *   - Recherche   : name="search_query"
 *   - Tri         : name="order"
 *
 * Éléments de structure attendus (via x-ref) :
 *   - x-ref="grid"        : conteneur de la grille de résultats
 *   - x-ref="pagination"  : conteneur de la pagination
 */
export default function MeilisearchFacets() {
    return {
        loading: false,

        // ----------------------------------------------------------------
        // Accesseurs de config (lus depuis les data-attributes du conteneur)
        // ----------------------------------------------------------------

        get ajaxUrl() {
            return this.$el.dataset.ajaxUrl
                ?? window.MeilisearchFacetsConfig?.ajaxUrl
                ?? window.AOGlobal?.ajaxUrl
                ?? '/wp-admin/admin-ajax.php';
        },

        get ajaxAction() {
            return this.$el.dataset.ajaxAction ?? '';
        },

        get hitsPerPage() {
            return parseInt(this.$el.dataset.hitsPerPage ?? '9', 10);
        },

        // ----------------------------------------------------------------
        // Cycle de vie Alpine
        // ----------------------------------------------------------------

        init() {
            this._restoreFromUrl();
            this._attachFilterListeners();
            this._attachPaginationListener();
            this.refresh(this._getCurrentPage());
        },

        // ----------------------------------------------------------------
        // API publique
        // ----------------------------------------------------------------

        /**
         * Déclenche une nouvelle recherche pour la page donnée.
         * Appelé automatiquement sur tout changement de filtre, et manuellement
         * pour la pagination.
         */
        async refresh(page = 1) {
            if (! this.ajaxAction) {
                console.warn('[MeilisearchFacets] data-ajax-action manquant sur le conteneur.');
                return;
            }

            this.loading = true;

            const facets = this._collectFacets();
            const extraDatas = {
                page,
                search: this._getSearchQuery(),
            };

            try {
                const body = new URLSearchParams();
                body.append('action', this.ajaxAction);

                for (const [key, value] of Object.entries(facets)) {
                    if (Array.isArray(value)) {
                        value.forEach(v => body.append(`facets[${key}][]`, v));
                    } else {
                        body.append(`facets[${key}]`, value);
                    }
                }

                for (const [key, value] of Object.entries(extraDatas)) {
                    body.append(`extra_datas[${key}]`, value);
                }

                const response = await fetch(this.ajaxUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                });

                const json = await response.json();

                if (json.success) {
                    this._updateGrid(json.data.grid ?? '');
                    this._updatePagination(json.data.pagination ?? '');
                    this._updateAvailableFacets(json.data.availableFacets ?? {});
                    this._updateAvailableRanges(json.data.availableRanges ?? {});
                    this._pushState(facets, page);
                } else {
                    console.error('[MeilisearchFacets] Erreur AJAX :', json);
                }
            } catch (error) {
                console.error('[MeilisearchFacets] Erreur réseau :', error);
            } finally {
                this.loading = false;
            }
        },

        // ----------------------------------------------------------------
        // Gestion du DOM
        // ----------------------------------------------------------------

        _updateGrid(html) {
            if (this.$refs.grid) {
                this.$refs.grid.innerHTML = html;
            }
        },

        _updatePagination(html) {
            if (this.$refs.pagination) {
                this.$refs.pagination.innerHTML = html;
                this._attachPaginationListener();
            }
        },

        /**
         * Active/désactive les options de select en fonction des facettes disponibles.
         *
         * @param {Object} availableFacets  { 'nom-taxonomie': ['slug1', 'slug2'] }
         */
        _updateAvailableFacets(availableFacets) {
            const selects = this.$el.querySelectorAll('select[name^="_search_"]');
            selects.forEach(select => {
                const taxonomy = select.name.replace('_search_', '');
                const available = availableFacets[taxonomy] ?? null;

                if (available === null) return;

                Array.from(select.options).forEach(option => {
                    if (option.value === '' || option.value === '*') return;
                    option.disabled = ! available.includes(option.value);
                });
            });
        },

        /**
         * Active/désactive les inputs de plages numériques selon les résultats disponibles.
         *
         * @param {Object} availableRanges  { 'price_range': { '1000-2000': true, '4000+': false } }
         */
        _updateAvailableRanges(availableRanges) {
            for (const [groupKey, ranges] of Object.entries(availableRanges)) {
                const inputs = this.$el.querySelectorAll(`input[name="${groupKey}"], input[name="${groupKey}[]"]`);
                inputs.forEach(input => {
                    const available = ranges[input.value] ?? true;
                    input.disabled = ! available;
                    input.closest('label')?.classList.toggle('opacity-50', ! available);
                });
            }
        },

        // ----------------------------------------------------------------
        // Collecte des filtres actifs
        // ----------------------------------------------------------------

        /**
         * Collecte tous les filtres actifs dans le conteneur.
         * Exclut les inputs de recherche (gérés séparément).
         *
         * @returns {Object}  { '_search_activity-sector': 'finance', 'price_range': ['1000-2000'] }
         */
        _collectFacets() {
            const facets = {};
            const inputs = this.$el.querySelectorAll(
                'select[name], input[type="checkbox"]:checked, input[type="radio"]:checked, select[name="order"]'
            );

            inputs.forEach(input => {
                if (input.name === 'search_query') return;
                if (! input.value || input.disabled) return;

                const name = input.name.replace(/\[\]$/, '');

                if (facets[name] !== undefined) {
                    if (! Array.isArray(facets[name])) {
                        facets[name] = [facets[name]];
                    }
                    facets[name].push(input.value);
                } else {
                    facets[name] = input.value;
                }
            });

            return facets;
        },

        _getSearchQuery() {
            const input = this.$el.querySelector('input[name="search_query"]');
            return input?.value ?? '';
        },

        // ----------------------------------------------------------------
        // Événements
        // ----------------------------------------------------------------

        _attachFilterListeners() {
            const inputs = this.$el.querySelectorAll(
                'select[name], input[type="checkbox"], input[type="radio"]'
            );

            inputs.forEach(input => {
                input.addEventListener('change', () => this.refresh(1));
            });

            const searchInput = this.$el.querySelector('input[name="search_query"]');
            if (searchInput) {
                searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        this.refresh(1);
                    }
                });
                // Déclenche aussi la recherche quand le champ est vidé via le bouton ✕
                searchInput.addEventListener('input', (e) => {
                    if (e.target.value === '') this.refresh(1);
                });
            }
        },

        _attachPaginationListener() {
            if (! this.$refs.pagination) return;

            this.$refs.pagination.querySelectorAll('[data-page]').forEach(link => {
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    const page = parseInt(link.dataset.page, 10);
                    if (! isNaN(page)) {
                        this.refresh(page);
                        this.$el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });
        },

        // ----------------------------------------------------------------
        // Gestion de l'URL (persistance des filtres entre recharges)
        // ----------------------------------------------------------------

        _pushState(facets, page) {
            const params = new URLSearchParams();

            for (const [key, value] of Object.entries(facets)) {
                if (Array.isArray(value)) {
                    value.forEach(v => params.append(key, v));
                } else {
                    params.set(key, value);
                }
            }

            if (page > 1) {
                params.set('page', page);
            }

            const url = params.toString()
                ? `${window.location.pathname}?${params.toString()}`
                : window.location.pathname;

            history.pushState({ facets, page }, '', url);
        },

        _restoreFromUrl() {
            const params = new URLSearchParams(window.location.search);

            params.forEach((value, key) => {
                const cleanKey = key.replace(/\[\]$/, '');
                const input = this.$el.querySelector(`[name="${cleanKey}"], [name="${cleanKey}[]"]`);

                if (! input) return;

                if (input.type === 'checkbox' || input.type === 'radio') {
                    // Recherche le bon input par valeur
                    const target = this.$el.querySelector(
                        `input[name="${cleanKey}"][value="${value}"],
                         input[name="${cleanKey}[]"][value="${value}"]`
                    );
                    if (target) target.checked = true;
                } else if (input.tagName === 'SELECT') {
                    input.value = value;
                } else {
                    input.value = value;
                }
            });
        },

        _getCurrentPage() {
            const params = new URLSearchParams(window.location.search);
            return parseInt(params.get('page') ?? '1', 10);
        },
    };
}
