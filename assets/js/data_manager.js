/**
 * cliento - AI Sales Intelligence
 * Kelola Data Scraping (Indonesian 38 Provinces Hierarchical Explorer)
 * 3-Level Folder Hierarchy: Provinsi -> Kota/Kabupaten -> Kecamatan
 */

window.DataManager = {
    provinces: [],
    totalAllPlaces: 0,
    activeProvince: null,
    activeCity: null,
    activeSubdistrict: null,
    activeLevel: 'province', // 'province' | 'city' | 'subdistrict'
    expandedProvinces: new Set(),
    expandedCities: new Set(),
    activeSector: '',
    activePhoneFilter: 'wa', // default 'wa' (Khusus WhatsApp Siap Chat) | '' (Semua Data)
    searchKeyword: '',
    currentPage: 1,
    perPage: 25,
    totalPages: 1,
    totalRecords: 0,
    currentRecords: [],
    pageCache: {},
    map: null,
    markersGroup: null,
    markersMap: {},
    isMapVisible: true,
    isInitialized: false,

    async init() {
        if (!this.isInitialized) {
            this.bindEvents();
            this.isInitialized = true;
        }
        await this.loadTreeData();
    },

    bindEvents() {
        const searchProv = document.getElementById('dm-tree-search');
        if (searchProv) {
            searchProv.addEventListener('input', (e) => {
                this.filterTree(e.target.value.trim().toLowerCase());
            });
        }

        const tableSearch = document.getElementById('dm-table-search');
        if (tableSearch) {
            let debounceTimer;
            tableSearch.addEventListener('input', (e) => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    this.searchKeyword = e.target.value.trim();
                    this.currentPage = 1;
                    this.pageCache = {};
                    this.loadData();
                }, 350);
            });
        }

        // Export Excel
        const btnExcel = document.getElementById('dm-btn-export-excel');
        if (btnExcel) {
            btnExcel.onclick = () => this.exportExcel();
        }

        // Export CSV
        const btnCsv = document.getElementById('dm-btn-export-csv');
        if (btnCsv) {
            btnCsv.onclick = () => this.exportCsv();
        }

        // Open in Map
        const btnMap = document.getElementById('dm-btn-open-map');
        if (btnMap) {
            btnMap.onclick = () => this.openInMap();
        }

        // Save to Archive (Google Drive style)
        const btnSaveArchive = document.getElementById('dm-btn-save-archive');
        if (btnSaveArchive) {
            btnSaveArchive.onclick = () => this.openSaveArchiveModal();
        }

        // Pagination
        const btnPrev = document.getElementById('dm-btn-prev');
        const btnNext = document.getElementById('dm-btn-next');
        if (btnPrev) {
            btnPrev.onclick = () => {
                if (this.currentPage > 1) {
                    this.currentPage--;
                    this.loadData();
                }
            };
        }
        if (btnNext) {
            btnNext.onclick = () => {
                if (this.currentPage < this.totalPages) {
                    this.currentPage++;
                    this.loadData();
                }
            };
        }
    },

    async loadTreeData() {
        const treeContainer = document.getElementById('dm-folder-tree');
        if (!treeContainer) return;

        treeContainer.innerHTML = `
            <div style="padding: 24px; text-align: center; color: #64748b; font-size: 0.8rem;">
                <i class="fa-solid fa-spinner fa-spin" style="font-size: 1.3rem; color: #2563eb; margin-bottom: 8px; display: block;"></i>
                Memuat folder seluruh wilayah Indonesia...
            </div>
        `;

        try {
            const res = await fetch('api/master_db.php?action=tree_stats');
            const data = await res.json();
            if (data.success && data.provinces) {
                this.provinces = data.provinces;
                this.totalAllPlaces = data.total_all_places || 0;

                const totalBadge = document.getElementById('dm-total-all-badge');
                if (totalBadge) totalBadge.textContent = `${this.totalAllPlaces.toLocaleString()} Bisnis Terpanen`;

                // Auto-expand provinces that have data
                this.provinces.forEach(p => {
                    if (p.total_places > 0) {
                        this.expandedProvinces.add(p.province);
                        if (p.cities && p.cities.length) {
                            p.cities.forEach(c => {
                                if (c.total_places > 0) {
                                    this.expandedCities.add(`${p.province}_${c.name}`);
                                }
                            });
                        }
                    }
                });

                this.renderTree();

                // Auto select first city with data or first province
                const firstWithData = this.provinces.find(p => p.total_places > 0);
                if (firstWithData) {
                    if (firstWithData.cities && firstWithData.cities.length && firstWithData.cities[0].total_places > 0) {
                        const topCity = firstWithData.cities[0];
                        if (topCity.subdistricts && topCity.subdistricts.length && topCity.subdistricts[0].total_places > 0) {
                            this.selectSubdistrict(firstWithData.province, topCity.name, topCity.subdistricts[0].name);
                        } else {
                            this.selectCity(firstWithData.province, topCity.name);
                        }
                    } else {
                        this.selectProvince(firstWithData.province);
                    }
                } else if (this.provinces.length) {
                    this.selectProvince(this.provinces[0].province);
                }
            }
        } catch (e) {
            console.error('Gagal memuat struktur folder wilayah:', e);
            treeContainer.innerHTML = `<div style="padding: 15px; color: #ef4444; font-size: 0.78rem;">Gagal memuat data wilayah. Periksa koneksi server.</div>`;
        }
    },

    renderTree() {
        const treeContainer = document.getElementById('dm-folder-tree');
        if (!treeContainer) return;

        let html = '';
        this.provinces.forEach((p, pIdx) => {
            const hasData = p.total_places > 0;
            const isProvExpanded = this.expandedProvinces.has(p.province);
            const provIcon = isProvExpanded ? 'fa-folder-open' : 'fa-folder';
            const badgeBg = hasData ? '#ecfdf5' : '#f1f5f9';
            const badgeColor = hasData ? '#059669' : '#94a3b8';
            const badgeBorder = hasData ? '#a7f3d0' : '#e2e8f0';

            const isSelectedProv = this.activeLevel === 'province' && this.activeProvince === p.province;

            html += `
                <div class="dm-tree-province-item" data-prov="${this.esc(p.province)}" style="margin-bottom: 4px;">
                    <!-- Level 1: Provinsi Folder Header -->
                    <div class="dm-prov-header" onclick="window.DataManager.toggleProvince('${this.esc(p.province)}')" 
                         style="display: flex; align-items: center; justify-content: space-between; padding: 7px 10px; border-radius: 6px; cursor: pointer; transition: background 0.15s; background: ${isSelectedProv ? '#e0e7ff' : '#ffffff'}; border: 1px solid ${isSelectedProv ? '#818cf8' : '#f1f5f9'};">
                        <div style="display: flex; align-items: center; gap: 7px; overflow: hidden;">
                            <i class="fa-solid fa-chevron-right dm-chevron-p-${pIdx}" style="font-size: 0.65rem; color: #94a3b8; transition: transform 0.2s; width: 10px; transform: ${isProvExpanded ? 'rotate(90deg)' : 'none'};"></i>
                            <i class="fa-solid ${provIcon}" style="color: ${hasData ? '#2563eb' : '#94a3b8'}; font-size: 0.85rem;"></i>
                            <span style="font-size: 0.78rem; font-weight: ${hasData ? '700' : '400'}; color: ${hasData ? '#0f172a' : '#64748b'}; white-space: nowrap; text-overflow: ellipsis; overflow: hidden;">
                                ${this.esc(p.province)}
                            </span>
                        </div>
                        <span style="background: ${badgeBg}; color: ${badgeColor}; border: 1px solid ${badgeBorder}; border-radius: 12px; padding: 1px 7px; font-size: 0.65rem; font-weight: 700; white-space: nowrap;">
                            ${p.total_places.toLocaleString()}
                        </span>
                    </div>

                    <!-- Level 2: Cities Container -->
                    <div class="dm-cities-container dm-cities-p-${pIdx}" style="display: ${isProvExpanded ? 'block' : 'none'}; padding-left: 14px; margin-top: 2px;">
                        ${this.renderCityItems(p.province, p.cities || [])}
                    </div>
                </div>
            `;
        });

        treeContainer.innerHTML = html;
    },

    renderCityItems(provinceName, cities = []) {
        if (!cities.length) {
            return `<div style="padding: 4px 8px; font-size: 0.70rem; color: #94a3b8; font-style: italic;">Belum ada kota terdaftar</div>`;
        }

        return cities.map((c) => {
            const hasCityData = c.total_places > 0;
            const cityKey = `${provinceName}_${c.name}`;
            const isCityExpanded = this.expandedCities.has(cityKey);
            const isSelectedCity = this.activeLevel === 'city' && this.activeCity === c.name && this.activeProvince === provinceName;
            const subdistricts = c.subdistricts || [];
            const hasSubdistricts = subdistricts.length > 0;

            const badgeBg = hasCityData ? '#eff6ff' : '#f8fafc';
            const badgeColor = hasCityData ? '#2563eb' : '#94a3b8';
            const badgeBorder = hasCityData ? '#bfdbfe' : '#e2e8f0';

            return `
                <div class="dm-tree-city-item" data-city="${this.esc(c.name)}" style="margin-bottom: 2px;">
                    <!-- Level 2: Kota / Kabupaten Row Header -->
                    <div class="dm-city-row" onclick="window.DataManager.toggleCity('${this.esc(provinceName)}', '${this.esc(c.name)}')" 
                         style="display: flex; align-items: center; justify-content: space-between; padding: 5px 8px; border-radius: 5px; cursor: pointer; transition: background 0.15s; background: ${isSelectedCity ? '#dbeafe' : 'transparent'}; border: 1px solid ${isSelectedCity ? '#93c5fd' : 'transparent'}; font-size: 0.74rem;">
                        <div style="display: flex; align-items: center; gap: 6px; overflow: hidden;">
                            <i class="fa-solid fa-chevron-right dm-chevron-c-${this.sanitizeId(cityKey)}" style="font-size: 0.60rem; color: ${hasSubdistricts ? '#64748b' : '#cbd5e1'}; transition: transform 0.2s; width: 8px; transform: ${isCityExpanded ? 'rotate(90deg)' : 'none'};"></i>
                            <i class="fa-solid fa-city" style="font-size: 0.70rem; color: ${hasCityData ? '#3b82f6' : '#94a3b8'};"></i>
                            <span style="color: ${hasCityData ? '#1e293b' : '#64748b'}; font-weight: ${hasCityData ? '600' : '400'}; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                                ${this.esc(c.name)}
                            </span>
                        </div>
                        <span style="background: ${badgeBg}; color: ${badgeColor}; border: 1px solid ${badgeBorder}; border-radius: 10px; padding: 1px 6px; font-size: 0.62rem; font-weight: 700;">
                            ${c.total_places.toLocaleString()}
                        </span>
                    </div>

                    <!-- Level 3: Kecamatan Container -->
                    <div class="dm-subdist-container dm-subdist-c-${this.sanitizeId(cityKey)}" style="display: ${isCityExpanded ? 'block' : 'none'}; padding-left: 18px; margin-top: 2px;">
                        ${this.renderSubdistrictItems(provinceName, c.name, subdistricts)}
                    </div>
                </div>
            `;
        }).join('');
    },

    renderSubdistrictItems(provinceName, cityName, subdistricts = []) {
        if (!subdistricts.length) {
            return `<div style="padding: 3px 6px; font-size: 0.68rem; color: #94a3b8; font-style: italic;">Belum ada data kecamatan</div>`;
        }

        return subdistricts.map(s => {
            const hasSubData = s.total_places > 0;
            const isSelectedSub = this.activeLevel === 'subdistrict' && this.activeSubdistrict === s.name && this.activeCity === cityName;
            const badgeBg = hasSubData ? '#f0fdf4' : '#f8fafc';
            const badgeColor = hasSubData ? '#16a34a' : '#94a3b8';
            const badgeBorder = hasSubData ? '#bbf7d0' : '#e2e8f0';

            return `
                <div class="dm-subdistrict-row" onclick="window.DataManager.selectSubdistrict('${this.esc(provinceName)}', '${this.esc(cityName)}', '${this.esc(s.name)}')" 
                     style="display: flex; align-items: center; justify-content: space-between; padding: 4px 6px; border-radius: 4px; cursor: pointer; transition: background 0.15s; background: ${isSelectedSub ? '#c7d2fe' : 'transparent'}; border: 1px solid ${isSelectedSub ? '#818cf8' : 'transparent'}; font-size: 0.72rem; margin-bottom: 2px;">
                    <div style="display: flex; align-items: center; gap: 5px; overflow: hidden;">
                        <i class="fa-solid fa-map-pin" style="font-size: 0.65rem; color: ${hasSubData ? '#10b981' : '#94a3b8'};"></i>
                        <span style="color: ${hasSubData ? '#0f172a' : '#64748b'}; font-weight: ${isSelectedSub ? '700' : (hasSubData ? '500' : '400')}; text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                            ${this.esc(s.name)}
                        </span>
                    </div>
                    <span style="background: ${badgeBg}; color: ${badgeColor}; border: 1px solid ${badgeBorder}; border-radius: 8px; padding: 0 5px; font-size: 0.60rem; font-weight: 700;">
                        ${s.total_places.toLocaleString()}
                    </span>
                </div>
            `;
        }).join('');
    },

    toggleProvince(provinceName) {
        if (this.expandedProvinces.has(provinceName)) {
            this.expandedProvinces.delete(provinceName);
        } else {
            this.expandedProvinces.add(provinceName);
        }
        this.selectProvince(provinceName);
        this.renderTree();
    },

    toggleCity(provinceName, cityName) {
        const cityKey = `${provinceName}_${cityName}`;
        if (this.expandedCities.has(cityKey)) {
            this.expandedCities.delete(cityKey);
        } else {
            this.expandedCities.add(cityKey);
        }
        this.selectCity(provinceName, cityName);
        this.renderTree();
    },

    selectProvince(provinceName) {
        this.activeProvince = provinceName;
        this.activeCity = null;
        this.activeSubdistrict = null;
        this.activeLevel = 'province';
        this.currentPage = 1;
        this.activeSector = '';
        this.searchKeyword = '';
        this.pageCache = {};

        // Update Breadcrumb & Header
        const breadcrumb = document.getElementById('dm-breadcrumb');
        if (breadcrumb) {
            breadcrumb.innerHTML = `
                <span>Indonesia</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem; color: #94a3b8;"></i>
                <strong style="color: #1e293b;">Provinsi ${this.esc(provinceName)}</strong>
            `;
        }

        const titleEl = document.getElementById('dm-view-title');
        if (titleEl) titleEl.textContent = `Provinsi ${provinceName}`;

        // Populate sector filter chips for this province
        const pData = this.provinces.find(p => p.province === provinceName);
        const provSectors = {};
        if (pData && pData.cities) {
            pData.cities.forEach(c => {
                if (c.sectors) {
                    Object.entries(c.sectors).forEach(([sec, cnt]) => {
                        provSectors[sec] = (provSectors[sec] || 0) + cnt;
                    });
                }
            });
        }
        this.renderSectorChips(provSectors);
        this.loadData();
    },

    selectCity(provinceName, cityName) {
        this.activeProvince = provinceName;
        this.activeCity = cityName;
        this.activeSubdistrict = null;
        this.activeLevel = 'city';
        this.currentPage = 1;
        this.activeSector = '';
        this.searchKeyword = '';
        this.pageCache = {};

        // Update Breadcrumb & Header
        const breadcrumb = document.getElementById('dm-breadcrumb');
        if (breadcrumb) {
            breadcrumb.innerHTML = `
                <span>Indonesia</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem; color: #94a3b8;"></i>
                <span onclick="window.DataManager.selectProvince('${this.esc(provinceName)}')" style="cursor: pointer; text-decoration: underline;">${this.esc(provinceName)}</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem; color: #94a3b8;"></i>
                <strong style="color: #1e293b;">${this.esc(cityName)}</strong>
            `;
        }

        const titleEl = document.getElementById('dm-view-title');
        if (titleEl) titleEl.textContent = cityName;

        // Render sector filter chips for this city
        const pData = this.provinces.find(p => p.province === provinceName);
        const cData = pData?.cities?.find(c => c.name === cityName);
        this.renderSectorChips(cData?.sectors || {});

        this.loadData();
    },

    selectSubdistrict(provinceName, cityName, subdistrictName) {
        this.activeProvince = provinceName;
        this.activeCity = cityName;
        this.activeSubdistrict = subdistrictName;
        this.activeLevel = 'subdistrict';
        this.currentPage = 1;
        this.activeSector = '';
        this.searchKeyword = '';
        this.pageCache = {};

        // Update Breadcrumb & Header
        const breadcrumb = document.getElementById('dm-breadcrumb');
        if (breadcrumb) {
            breadcrumb.innerHTML = `
                <span>Indonesia</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem; color: #94a3b8;"></i>
                <span onclick="window.DataManager.selectProvince('${this.esc(provinceName)}')" style="cursor: pointer; text-decoration: underline;">${this.esc(provinceName)}</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem; color: #94a3b8;"></i>
                <span onclick="window.DataManager.selectCity('${this.esc(provinceName)}', '${this.esc(cityName)}')" style="cursor: pointer; text-decoration: underline;">${this.esc(cityName)}</span>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.65rem; color: #94a3b8;"></i>
                <strong style="color: #1e293b;">Kec. ${this.esc(subdistrictName)}</strong>
            `;
        }

        const titleEl = document.getElementById('dm-view-title');
        if (titleEl) titleEl.textContent = `Kecamatan ${subdistrictName}, ${cityName}`;

        const pData = this.provinces.find(p => p.province === provinceName);
        const cData = pData?.cities?.find(c => c.name === cityName);
        this.renderSectorChips(cData?.sectors || {});

        this.renderTree();
        this.loadData();
    },

    renderSectorChips(sectors = {}) {
        const chipsContainer = document.getElementById('dm-sector-chips');
        if (!chipsContainer) return;

        let total = 0;
        Object.values(sectors).forEach(v => total += v);

        let html = `
            <button type="button" class="dm-sector-chip active" onclick="window.DataManager.filterSector('')" style="padding: 4px 10px; font-size: 0.72rem; border-radius: 6px; border: 1px solid #cbd5e1; background: #0f172a; color: #ffffff; cursor: pointer; font-weight: 600;">
                Semua Sektor (${total.toLocaleString()})
            </button>
        `;

        Object.entries(sectors).forEach(([sec, cnt]) => {
            html += `
                <button type="button" class="dm-sector-chip" data-sec="${this.esc(sec)}" onclick="window.DataManager.filterSector('${this.esc(sec)}')" style="padding: 4px 10px; font-size: 0.72rem; border-radius: 6px; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; cursor: pointer; font-weight: 500;">
                    ${this.formatSectorLabel(sec)} (${cnt.toLocaleString()})
                </button>
            `;
        });

        chipsContainer.innerHTML = html;
    },

    filterSector(sectorName) {
        this.activeSector = sectorName;
        this.currentPage = 1;
        this.pageCache = {};

        document.querySelectorAll('.dm-sector-chip').forEach(btn => {
            const isMatch = (btn.getAttribute('data-sec') || '') === sectorName;
            btn.style.background = isMatch ? '#0f172a' : '#ffffff';
            btn.style.color = isMatch ? '#ffffff' : '#334155';
            btn.style.fontWeight = isMatch ? '600' : '500';
        });

        this.loadData();
    },

    filterPhone(phoneFilter) {
        this.activePhoneFilter = phoneFilter || '';
        this.currentPage = 1;
        this.pageCache = {};

        document.querySelectorAll('.dm-filter-phone').forEach(btn => {
            const isMatch = (btn.getAttribute('data-phone') || '') === this.activePhoneFilter;
            btn.classList.toggle('active', isMatch);
            if (btn.getAttribute('data-phone') === 'wa') {
                btn.style.background = isMatch ? '#059669' : '#ffffff';
                btn.style.color = isMatch ? '#ffffff' : '#059669';
                btn.style.borderColor = isMatch ? '#059669' : '#a7f3d0';
                btn.style.fontWeight = isMatch ? '700' : '500';
            } else {
                btn.style.background = isMatch ? '#0f172a' : '#ffffff';
                btn.style.color = isMatch ? '#ffffff' : '#475569';
                btn.style.borderColor = isMatch ? '#0f172a' : '#cbd5e1';
                btn.style.fontWeight = isMatch ? '700' : '500';
            }
        });

        this.loadData();
    },

    filterTree(query) {
        document.querySelectorAll('.dm-tree-province-item').forEach(item => {
            const provName = (item.getAttribute('data-prov') || '').toLowerCase();
            const fullText = item.textContent.toLowerCase();
            if (!query || provName.includes(query) || fullText.includes(query)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    },

    async loadData() {
        const tableBody = document.getElementById('dm-table-body');
        const scopeKey = `${this.activeLevel}_${this.activeProvince}_${this.activeCity}_${this.activeSubdistrict}_${this.activeSector}_${this.activePhoneFilter}_${this.searchKeyword}_${this.currentPage}`;

        if (this.pageCache[scopeKey]) {
            this.renderTable(this.pageCache[scopeKey]);
            return;
        }

        const targetLabel = this.activeSubdistrict || this.activeCity || this.activeProvince || 'Wilayah';
        if (tableBody) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align: center; padding: 25px; color: #64748b;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size: 1.2rem; color: #2563eb; margin-bottom: 6px; display: block;"></i>
                        Memuat data bisnis di ${this.esc(targetLabel)}...
                    </td>
                </tr>
            `;
        }

        try {
            let url = `api/master_db.php?action=query&page=${this.currentPage}&per_page=${this.perPage}`;
            if (this.activeCity) {
                url += `&region=${encodeURIComponent(this.activeCity)}`;
            } else if (this.activeProvince) {
                url += `&province=${encodeURIComponent(this.activeProvince)}`;
            }
            if (this.activeSubdistrict) {
                url += `&subdistrict=${encodeURIComponent(this.activeSubdistrict)}`;
            }
            if (this.activeSector) {
                url += `&category=${encodeURIComponent(this.activeSector)}`;
            }
            if (this.activePhoneFilter) {
                url += `&has_phone=${encodeURIComponent(this.activePhoneFilter)}`;
            }
            if (this.searchKeyword) {
                url += `&keyword=${encodeURIComponent(this.searchKeyword)}`;
            }
            if (this.currentPage > 1 && this.totalRecords > 0) {
                url += `&skip_meta=1&known_total=${this.totalRecords}`;
            }

            const res = await fetch(url);
            const data = await res.json();

            this.pageCache[scopeKey] = data;
            this.renderTable(data);
        } catch (e) {
            console.error('Gagal mengambil data wilayah:', e);
            if (tableBody) {
                tableBody.innerHTML = `<tr><td colspan="7" style="text-align:center; color:#ef4444; padding:20px;">Gagal memuat data dari database.</td></tr>`;
            }
        }
    },

    renderTable(data) {
        const tableBody = document.getElementById('dm-table-body');
        const paginationInfo = document.getElementById('dm-pagination-info');
        const btnPrev = document.getElementById('dm-btn-prev');
        const btnNext = document.getElementById('dm-btn-next');

        this.currentRecords = data.data || [];
        this.totalRecords = data.total || 0;
        this.totalPages = data.total_pages || Math.max(1, Math.ceil(this.totalRecords / this.perPage));

        // Update Stat Badges
        const statTotal = document.getElementById('dm-stat-total');
        const statPhone = document.getElementById('dm-stat-phone');
        const statWeb = document.getElementById('dm-stat-web');
        if (statTotal) statTotal.textContent = this.totalRecords.toLocaleString();

        const phoneCount = this.currentRecords.filter(r => r.phone && r.phone !== '-' && r.phone !== 'null').length;
        const webCount = this.currentRecords.filter(r => r.website && r.website !== '-' && r.website !== 'null').length;
        if (statPhone) statPhone.textContent = `${phoneCount} kontak terverifikasi (halaman ini)`;
        if (statWeb) statWeb.textContent = `${webCount} website aktif (halaman ini)`;

        if (paginationInfo) {
            paginationInfo.textContent = `Halaman ${this.currentPage} dari ${this.totalPages} (Total ${this.totalRecords.toLocaleString()} Tempat)`;
        }
        if (btnPrev) btnPrev.disabled = this.currentPage <= 1;
        if (btnNext) btnNext.disabled = this.currentPage >= this.totalPages;

        if (!this.currentRecords.length) {
            if (tableBody) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 35px; color: #94a3b8;">
                            <i class="fa-solid fa-folder-open" style="font-size: 2rem; color: #cbd5e1; margin-bottom: 8px; display: block;"></i>
                            Belum ada data bisnis hasil scraping di folder ini.
                        </td>
                    </tr>
                `;
            }
            return;
        }

        let html = '';
        this.currentRecords.forEach((r, idx) => {
            const num = (this.currentPage - 1) * this.perPage + idx + 1;
            const itemKey = String(r.id || r.osm_id || `${r.lat}_${r.lng}` || (idx + 1));
            const rawPhone = (r.phone || '').trim();
            const hasPhone = rawPhone && rawPhone !== '-' && rawPhone !== 'null' && rawPhone.length >= 6;

            let phoneBadge = `<span style="color: #94a3b8; font-size: 0.72rem;">-</span>`;
            if (hasPhone) {
                const cleanDigits = rawPhone.replace(/[^0-9]/g, '');
                let intlNumber = cleanDigits;
                if (intlNumber.startsWith('0')) intlNumber = '62' + intlNumber.substring(1);

                // Identify if it is an Indonesian Mobile Phone (compatible with WhatsApp: 08xx / 628xx)
                const isMobile = intlNumber.startsWith('628') && intlNumber.length >= 10 && intlNumber.length <= 14;

                if (isMobile) {
                    const waUrl = `https://wa.me/${intlNumber}`;
                    phoneBadge = `
                        <a href="${waUrl}" target="_blank" style="color: #059669; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 2px 7px; border-radius: 6px; font-size: 0.72rem;" title="Hubungi WhatsApp Bisnis">
                            <i class="fa-brands fa-whatsapp" style="font-size: 0.85rem;"></i> ${this.esc(rawPhone)}
                        </a>
                    `;
                } else {
                    // Landline / Telepon Kantor PSTN
                    phoneBadge = `
                        <a href="tel:${this.esc(rawPhone)}" style="color: #1e40af; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; background: #eff6ff; border: 1px solid #bfdbfe; padding: 2px 7px; border-radius: 6px; font-size: 0.72rem;" title="Telepon Kantor / PSTN">
                            <i class="fa-solid fa-phone" style="font-size: 0.75rem;"></i> ${this.esc(rawPhone)}
                        </a>
                    `;
                }
            }

            const webLink = (r.website && r.website !== '-' && r.website !== 'null')
                ? `<a href="${r.website.startsWith('http') ? r.website : 'https://' + r.website}" target="_blank" style="color: #2563eb; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;" title="${this.esc(r.website)}"><i class="fa-solid fa-arrow-up-right-from-square"></i> Kunjungi</a>`
                : `<span style="color: #94a3b8;">-</span>`;

            html += `
                <tr id="dm-row-${itemKey}" class="dm-row-clickable" onclick="window.DataManager.onRowClick(event, '${itemKey}')" style="border-bottom: 1px solid #f1f5f9; transition: background 0.15s;" title="Klik baris untuk melihat titik di peta">
                    <td style="padding: 10px; font-size: 0.76rem; color: #64748b; text-align: center;">${num}</td>
                    <td style="padding: 10px; font-size: 0.82rem; font-weight: 600; color: #0f172a;">
                        ${this.esc(r.name)}
                    </td>
                    <td style="padding: 10px; font-size: 0.74rem;">
                        <span style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 2px 6px; color: #334155; font-weight: 500;">
                            ${this.esc(r.category || r.category_name || 'Lainnya')}
                        </span>
                    </td>
                    <td style="padding: 10px; font-size: 0.74rem; color: #475569; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${this.esc(r.address || '-')}">
                        ${this.esc(r.address || '-')}
                    </td>
                    <td style="padding: 10px; font-size: 0.74rem;">${phoneBadge}</td>
                    <td style="padding: 10px; font-size: 0.74rem;">${webLink}</td>
                    <td style="padding: 10px; font-size: 0.74rem; text-align: center;">
                        <button type="button" class="btn btn-outline btn-sm" onclick="event.stopPropagation(); window.DataManager.focusMapMarker('${itemKey}');" style="font-size: 0.68rem; padding: 3px 8px; color: #2563eb; border-color: #bfdbfe; font-weight: 600; border-radius: 4px;" title="Pusatkan Titik di Peta">
                            <i class="fa-solid fa-location-dot"></i> Peta
                        </button>
                    </td>
                </tr>
            `;
        });

        if (tableBody) tableBody.innerHTML = html;
        this.plotMapMarkers();
    },

    exportExcel() {
        if (!this.currentRecords.length) {
            alert('Tidak ada data untuk diekspor!');
            return;
        }
        if (typeof XLSX === 'undefined') {
            alert('Modul Excel (SheetJS) belum termuat.');
            return;
        }

        // HANYA ekspor yang memiliki nomor telepon/kontak (sesuai kebutuhan Sales Outreach)
        let exportRecords = this.currentRecords.filter(r => r.phone && r.phone !== '-' && r.phone !== 'null' && r.phone.trim().length >= 6);
        if (!exportRecords.length) {
            alert('Tidak ditemukan data yang memiliki nomor kontak pada tampilan ini.');
            return;
        }

        const sheetData = exportRecords.map((r, idx) => {
            const rawPhone = (r.phone || '').trim();
            const cleanDigits = rawPhone.replace(/[^0-9]/g, '');
            let intl = cleanDigits;
            if (intl.startsWith('0')) intl = '62' + intl.substring(1);
            const isWa = intl.startsWith('628') && intl.length >= 10 && intl.length <= 14;

            return {
                'No': idx + 1,
                'Nama Tempat / Bisnis': r.name,
                'Kategori': r.category || r.category_name || '',
                'Nomor Telepon Asli': rawPhone,
                'Format WhatsApp (Internasional)': isWa ? intl : '-',
                'Tipe Kontak': isWa ? 'WhatsApp Seluler' : 'Telepon Kantor (PSTN)',
                'Alamat Lengkap': r.address || '',
                'Kecamatan': r.subdistrict || this.activeSubdistrict || '',
                'Kota / Kabupaten': r.city || this.activeCity || '',
                'Provinsi': r.province || this.activeProvince || '',
                'Website': r.website || '',
                'Latitude': r.lat,
                'Longitude': r.lng
            };
        });

        const ws = XLSX.utils.json_to_sheet(sheetData);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Leads WhatsApp');

        const label = (this.activeSubdistrict || this.activeCity || this.activeProvince || 'Indonesia').replace(/[^a-zA-Z0-9_-]/g, '_');
        XLSX.writeFile(wb, `cliento_leads_kontak_${label}_${Date.now()}.xlsx`);
    },

    exportCsv() {
        if (!this.currentRecords.length) {
            alert('Tidak ada data untuk diekspor!');
            return;
        }

        // HANYA ekspor yang memiliki nomor kontak
        let exportRecords = this.currentRecords.filter(r => r.phone && r.phone !== '-' && r.phone !== 'null' && r.phone.trim().length >= 6);
        if (!exportRecords.length) {
            alert('Tidak ditemukan data yang memiliki nomor kontak pada tampilan ini.');
            return;
        }

        const headers = ['No', 'Nama Bisnis', 'Kategori', 'Nomor Telepon', 'WhatsApp Intl', 'Tipe Kontak', 'Alamat', 'Kecamatan', 'Kota/Kabupaten', 'Provinsi', 'Website', 'Lat', 'Lng'];
        const rows = exportRecords.map((r, idx) => {
            const rawPhone = (r.phone || '').trim();
            const cleanDigits = rawPhone.replace(/[^0-9]/g, '');
            let intl = cleanDigits;
            if (intl.startsWith('0')) intl = '62' + intl.substring(1);
            const isWa = intl.startsWith('628') && intl.length >= 10 && intl.length <= 14;

            return [
                idx + 1,
                `"${(r.name || '').replace(/"/g, '""')}"`,
                `"${(r.category || r.category_name || '').replace(/"/g, '""')}"`,
                `"${rawPhone.replace(/"/g, '""')}"`,
                `"${isWa ? intl : '-'}"`,
                `"${isWa ? 'WhatsApp Seluler' : 'Telepon Kantor'}"`,
                `"${(r.address || '').replace(/"/g, '""')}"`,
                `"${(r.subdistrict || this.activeSubdistrict || '').replace(/"/g, '""')}"`,
                `"${(r.city || this.activeCity || '').replace(/"/g, '""')}"`,
                `"${(r.province || this.activeProvince || '').replace(/"/g, '""')}"`,
                `"${(r.website || '').replace(/"/g, '""')}"`,
                r.lat || 0,
                r.lng || 0
            ];
        });

        const csvContent = "data:text/csv;charset=utf-8,\uFEFF" + [headers.join(','), ...rows.map(e => e.join(','))].join('\n');
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement('a');
        const label = (this.activeSubdistrict || this.activeCity || this.activeProvince || 'Indonesia').replace(/[^a-zA-Z0-9_-]/g, '_');
        link.setAttribute('href', encodedUri);
        link.setAttribute('download', `cliento_leads_kontak_${label}_${Date.now()}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    },

    initMap() {
        if (this.map) return;
        const container = document.getElementById('dm-map-container');
        if (!container || typeof L === 'undefined') return;

        this.map = L.map('dm-map-container', {
            center: [-2.5489, 118.0149],
            zoom: 5,
            zoomControl: true
        });

        // Layer OpenStreetMap Segar HD
        L.tileLayer('https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', {
            maxZoom: 20,
            subdomains: ['a', 'b', 'c'],
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(this.map);

        this.markersGroup = L.featureGroup().addTo(this.map);
    },

    plotMapMarkers() {
        this.initMap();
        if (!this.map || !this.markersGroup) return;

        this.markersGroup.clearLayers();
        this.markersMap = {};

        const pinBadge = document.getElementById('dm-map-pin-count');
        let validPointCount = 0;

        this.currentRecords.forEach((r, idx) => {
            const lat = parseFloat(r.lat);
            const lng = parseFloat(r.lng);
            if (isNaN(lat) || isNaN(lng) || (lat === 0 && lng === 0)) return;

            validPointCount++;
            const itemKey = String(r.id || r.osm_id || `${lat}_${lng}` || (idx + 1));
            const rawPhone = (r.phone || '').trim();
            const cleanDigits = rawPhone.replace(/[^0-9]/g, '');
            let intl = cleanDigits;
            if (intl.startsWith('0')) intl = '62' + intl.substring(1);
            const isWa = intl.startsWith('628') && intl.length >= 10 && intl.length <= 14;
            const hasPhone = rawPhone && rawPhone !== '-' && rawPhone !== 'null' && rawPhone.length >= 6;

            const markerColor = isWa ? '#059669' : (hasPhone ? '#2563eb' : '#64748b');
            const markerRadius = isWa ? 7 : (hasPhone ? 6 : 5);

            const marker = L.circleMarker([lat, lng], {
                radius: markerRadius,
                fillColor: markerColor,
                color: '#ffffff',
                weight: 2,
                opacity: 1,
                fillOpacity: 0.95
            });

            let contactStatusHtml = `<span style="color: #94a3b8; font-size: 10px;">- Belum Ada Kontak -</span>`;
            if (isWa) {
                contactStatusHtml = `<span style="color: #059669; font-weight: 700; font-size: 10px;"><i class="fa-brands fa-whatsapp"></i> WA: ${this.esc(rawPhone)}</span>`;
            } else if (hasPhone) {
                contactStatusHtml = `<span style="color: #1e40af; font-weight: 600; font-size: 10px;"><i class="fa-solid fa-phone"></i> Telp: ${this.esc(rawPhone)}</span>`;
            }

            marker.bindPopup(`
                <div style="font-family: 'Poppins', sans-serif; font-size: 11px; min-width: 195px; padding: 2px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; gap: 4px;">
                        <span style="font-size: 9px; font-weight: 700; background: #f1f5f9; padding: 2px 6px; border-radius: 4px; color: #334155; text-transform: uppercase;">
                            ${this.esc(r.category || r.category_name || 'Bisnis')}
                        </span>
                        ${isWa ? '<span style="background: #ecfdf5; color: #059669; font-size: 9px; font-weight: 700; padding: 1px 5px; border-radius: 4px; border: 1px solid #a7f3d0;"><i class="fa-brands fa-whatsapp"></i> Siap Chat</span>' : ''}
                    </div>
                    <strong style="color: #0f172a; font-size: 12px; display: block; margin-bottom: 2px; line-height: 1.3;">${this.esc(r.name)}</strong>
                    <div style="color: #64748b; font-size: 10px; margin-bottom: 6px; line-height: 1.3;">${this.esc(r.address || '-')}</div>
                    <div style="margin-bottom: 8px;">${contactStatusHtml}</div>
                    <button type="button" onclick="window.DataManager.focusTableRow('${itemKey}')" style="background: #2563eb; color: #ffffff; border: none; padding: 5px 8px; border-radius: 5px; font-size: 10px; cursor: pointer; font-weight: 600; width: 100%; display: flex; align-items: center; justify-content: center; gap: 5px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                        <i class="fa-solid fa-arrow-down"></i> Sorot ke Baris Tabel
                    </button>
                </div>
            `);

            marker.on('click', () => {
                this.focusTableRow(itemKey);
            });

            this.markersGroup.addLayer(marker);
            this.markersMap[itemKey] = marker;
        });

        if (pinBadge) pinBadge.textContent = `${validPointCount} Titik di Peta`;

        if (validPointCount > 0) {
            try {
                this.map.fitBounds(this.markersGroup.getBounds(), { padding: [35, 35], maxZoom: 16 });
            } catch (err) {}
            setTimeout(() => {
                if (this.map) this.map.invalidateSize();
            }, 250);
        }
    },

    onRowClick(event, itemKey) {
        if (event.target.closest('a') || event.target.closest('button')) return;
        this.focusMapMarker(itemKey);
    },

    focusTableRow(itemKey) {
        const row = document.getElementById('dm-row-' + itemKey);
        if (row) {
            row.scrollIntoView({ behavior: 'smooth', block: 'center' });
            row.classList.remove('dm-row-active-pulse');
            void row.offsetWidth; // force DOM reflow
            row.classList.add('dm-row-active-pulse');
        }
    },

    focusMapMarker(itemKey) {
        const marker = this.markersMap[itemKey];
        if (marker && this.map) {
            if (!this.isMapVisible) this.toggleMap();
            const mapWrapper = document.getElementById('dm-map-wrapper');
            if (mapWrapper) {
                const rect = mapWrapper.getBoundingClientRect();
                if (rect.top < 0 || rect.bottom < 120) {
                    mapWrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            }
            const latLng = marker.getLatLng();
            this.map.flyTo(latLng, Math.max(this.map.getZoom(), 16), { duration: 0.6 });
            marker.openPopup();
        }
    },

    fitMapBounds() {
        if (this.map && this.markersGroup && this.markersGroup.getLayers().length > 0) {
            if (!this.isMapVisible) this.toggleMap();
            this.map.fitBounds(this.markersGroup.getBounds(), { padding: [35, 35], maxZoom: 16 });
        }
    },

    toggleMap() {
        const mapContainer = document.getElementById('dm-map-container');
        const icon = document.getElementById('dm-toggle-map-icon');
        const text = document.getElementById('dm-toggle-map-text');
        if (!mapContainer) return;

        if (this.isMapVisible) {
            mapContainer.style.display = 'none';
            this.isMapVisible = false;
            if (icon) icon.className = 'fa-solid fa-eye';
            if (text) text.textContent = 'Tampilkan';
        } else {
            mapContainer.style.display = 'block';
            this.isMapVisible = true;
            if (icon) icon.className = 'fa-solid fa-eye-slash';
            if (text) text.textContent = 'Sembunyikan';
            if (this.map) {
                setTimeout(() => this.map.invalidateSize(), 150);
            }
        }
    },

    openSaveArchiveModal() {
        if (!this.currentRecords || !this.currentRecords.length) {
            alert('Tidak ada data prospek pada tabel untuk disimpan ke arsip.');
            return;
        }

        const locName = this.activeSubdistrict || this.activeCity || this.activeProvince || 'Wilayah';
        const secName = this.activeSector ? ` - ${this.formatSectorLabel(this.activeSector)}` : '';
        const waTag = this.activePhoneFilter === 'wa' ? ' (Khusus WA)' : '';
        const defaultName = `Data ${locName}${secName}${waTag} - ${new Date().toLocaleDateString('id-ID')}`;

        if (window.ScraperClient) {
            window.ScraperClient.targetSaveSource = {
                type: 'data_manager',
                items: this.currentRecords.map(r => ({
                    name: r.name,
                    address: r.address || '-',
                    phone: r.phone || '',
                    lat: r.lat || 0,
                    lng: r.lng || 0,
                    category: r.category || r.category_name || r.sector || 'Umum',
                    social_media: r.website || '',
                    opening_hours: r.opening_hours || '',
                    rating: 4.5,
                    reviews_count: 25,
                    status: 'none'
                }))
            };

            const nameInput = document.getElementById('save-archive-name-input');
            const titleEl = document.getElementById('save-archive-modal-title');
            if (nameInput) nameInput.value = defaultName;
            if (titleEl) titleEl.textContent = 'Simpan Data Wilayah ke Koleksi Arsip';

            const quickContainer = document.getElementById('quick-folder-input-container');
            if (quickContainer) quickContainer.style.display = 'none';

            window.ScraperClient.populateArchiveFoldersSelect();

            const modal = document.getElementById('modal-save-archive');
            if (modal) modal.classList.add('active');
        } else {
            alert('Modul Arsip belum siap.');
        }
    },

    openInMap() {
        if (!this.currentRecords.length) {
            alert('Pilih folder wilayah yang memiliki data terlebih dahulu.');
            return;
        }
        if (!this.isMapVisible) this.toggleMap();
        const mapWrapper = document.getElementById('dm-map-wrapper');
        if (mapWrapper) mapWrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
        this.fitMapBounds();
    },

    formatSectorLabel(sector) {
        const labels = {
            retail: 'Toko & Retail',
            kuliner: 'Kuliner & Kafe',
            kesehatan: 'Kesehatan & Medis',
            perusahaan: 'Kantor & PT/CV',
            jasa: 'Jasa & Pelayanan',
            pendidikan: 'Pendidikan',
            otomotif: 'Otomotif & Bengkel',
            akomodasi: 'Hotel & Wisata',
            kecantikan: 'Salon & Spa',
            keuangan: 'Bank & Keuangan',
            pertanian: 'Pertanian & Ternak',
            ibadah: 'Tempat Ibadah',
            pemerintah: 'Instansi Pemerintah',
            it: 'Teknologi & IT',
            konstruksi: 'Konstruksi & Properti',
            lainnya: 'Lainnya'
        };
        return labels[sector] || sector.charAt(0).toUpperCase() + sector.slice(1);
    },

    sanitizeId(str) {
        return (str || '').replace(/[^a-zA-Z0-9_-]/g, '_');
    },

    esc(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        if (window.location.hash === '#data-manager') window.DataManager.init();
    });
} else {
    if (window.location.hash === '#data-manager') window.DataManager.init();
}
