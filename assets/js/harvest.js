/**
 * Client Reach AI - National Business Harvest Manager
 * 
 * Frontend Controller for Automated 100% Comprehensive Indonesian Business Harvesting.
 * Powered by Overpass OpenStreetMap Grid, Nominatim Geocoding, Photon Komoot Spatial Crawler,
 * and Instant Leads/Archives Integration.
 */

const HarvestManager = {
    provinces: [],
    selectedProvince: null,
    cities: [],
    selectedCity: null,
    currentMode: 'city', // 'city' | 'province' | 'indonesia'
    isAutoRunning: false,
    isPaused: false,
    activeTab: 'queue',
    totalHarvestedPlaces: 0,
    pollTimer: null,

    // ─── INITIALIZATION ───
    init() {
        this.bindEvents();
        this.logTerminal('SYSTEM', 'Harvest Manager Module terinisialisasi.');
    },

    onPageEnter() {
        if (this.provinces.length === 0) {
            this.loadProvinces();
        }
        this.loadQueueStatus();
        this.loadHarvestHistory();
        this.checkBackgroundWorker();
    },

    // ─── EVENT BINDINGS ───
    bindEvents() {
        // Mode tabs
        document.querySelectorAll('.harvest-mode-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const mode = btn.getAttribute('data-mode');
                this.switchMode(mode);
            });
        });

        // Province select change
        const provSelect = document.getElementById('harvest-select-province');
        if (provSelect) {
            provSelect.addEventListener('change', (e) => {
                this.onProvinceSelected(e.target.value);
            });
        }

        // City select change
        const citySelect = document.getElementById('harvest-select-city');
        if (citySelect) {
            citySelect.addEventListener('change', (e) => {
                this.onCitySelected(e.target.value);
            });
        }

        // Category chips toggle
        document.querySelectorAll('.harvest-chip').forEach(chip => {
            chip.addEventListener('click', (e) => {
                // If clicked label/input
                const checkbox = chip.querySelector('input');
                if (e.target !== checkbox) {
                    checkbox.checked = !checkbox.checked;
                }
                chip.classList.toggle('active', checkbox.checked);
            });
        });

        // Toggle all categories
        const btnToggleCat = document.getElementById('btn-toggle-all-categories');
        if (btnToggleCat) {
            btnToggleCat.addEventListener('click', () => {
                this.toggleAllCategories();
            });
        }

        // Queue action buttons
        const btnAddQueue = document.getElementById('btn-harvest-add-queue');
        if (btnAddQueue) {
            btnAddQueue.addEventListener('click', () => {
                this.addToQueue();
            });
        }

        const btnRunDirect = document.getElementById('btn-harvest-run-direct');
        if (btnRunDirect) {
            btnRunDirect.addEventListener('click', () => {
                this.queueAndRunDirect();
            });
        }

        // Auto process controls
        const btnStartAuto = document.getElementById('btn-harvest-start-auto');
        if (btnStartAuto) {
            btnStartAuto.addEventListener('click', () => {
                this.startAutoHarvest();
            });
        }

        const btnPauseAuto = document.getElementById('btn-harvest-pause-auto');
        if (btnPauseAuto) {
            btnPauseAuto.addEventListener('click', () => {
                this.pauseAutoHarvest();
            });
        }

        const btnClearQueue = document.getElementById('btn-harvest-clear-queue');
        if (btnClearQueue) {
            btnClearQueue.addEventListener('click', () => {
                this.clearQueue();
            });
        }

        // Refresh buttons
        const btnRefreshHarvest = document.getElementById('btn-refresh-harvest');
        if (btnRefreshHarvest) {
            btnRefreshHarvest.addEventListener('click', () => {
                this.refreshAll();
            });
        }

        const btnRefreshTables = document.getElementById('btn-refresh-tables');
        if (btnRefreshTables) {
            btnRefreshTables.addEventListener('click', () => {
                this.loadQueueStatus();
                this.loadHarvestHistory();
            });
        }

        const btnSyncDb = document.getElementById('btn-sync-sqlite-db');
        if (btnSyncDb) {
            btnSyncDb.addEventListener('click', () => {
                this.syncToDatabase();
            });
        }

        // Table tabs
        const tabBtnQueue = document.getElementById('tab-btn-queue');
        const tabBtnHistory = document.getElementById('tab-btn-history');
        if (tabBtnQueue && tabBtnHistory) {
            tabBtnQueue.addEventListener('click', () => {
                this.switchTableTab('queue');
            });
            tabBtnHistory.addEventListener('click', () => {
                this.switchTableTab('history');
            });
        }

        // Terminal actions
        const btnTermClear = document.getElementById('btn-terminal-clear');
        if (btnTermClear) {
            btnTermClear.addEventListener('click', () => {
                this.clearTerminal();
            });
        }

        const btnTermCopy = document.getElementById('btn-terminal-copy');
        if (btnTermCopy) {
            btnTermCopy.addEventListener('click', () => {
                this.copyTerminal();
            });
        }

        // Guide modal toggle
        const btnGuide = document.getElementById('btn-harvest-guide-toggle');
        if (btnGuide) {
            btnGuide.addEventListener('click', () => {
                this.showHarvestGuide();
            });
        }
    },

    // ─── SWITCH MODE ───
    switchMode(mode) {
        this.currentMode = mode;
        document.querySelectorAll('.harvest-mode-btn').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
        });

        const groupCity = document.getElementById('harvest-group-city');
        const groupProv = document.getElementById('harvest-group-province');
        const btnAddQueue = document.getElementById('btn-harvest-add-queue');

        if (mode === 'city') {
            if (groupCity) groupCity.style.display = 'block';
            if (groupProv) groupProv.style.display = 'block';
            if (btnAddQueue) btnAddQueue.innerHTML = '<i class="fa-solid fa-plus"></i> Masukkan Kota ke Antrean';
            this.updateTargetPreview();
        } else if (mode === 'province') {
            if (groupCity) groupCity.style.display = 'none';
            if (groupProv) groupProv.style.display = 'block';
            if (btnAddQueue) btnAddQueue.innerHTML = '<i class="fa-solid fa-layer-group"></i> Antrekan 1 Provinsi Penuh';
            this.updateProvinceTargetPreview();
        } else if (mode === 'indonesia') {
            if (groupCity) groupCity.style.display = 'none';
            if (groupProv) groupProv.style.display = 'none';
            if (btnAddQueue) btnAddQueue.innerHTML = '<i class="fa-solid fa-globe"></i> Antrekan Seluruh Indonesia (34 Prov)';
            this.updateIndonesiaTargetPreview();
        }

        this.logTerminal('INFO', `Mode panen diubah ke: ${mode.toUpperCase()}`);
    },

    // ─── LOAD PROVINCES ───
    async loadProvinces() {
        const select = document.getElementById('harvest-select-province');
        if (!select) return;

        try {
            select.innerHTML = '<option value="">-- Mengambil daftar 34 provinsi... --</option>';
            const res = await fetch('api/harvest_queue.php?action=list_provinces');
            const data = await res.json();

            if (data.success && Array.isArray(data.provinces)) {
                this.provinces = data.provinces;
                let html = '<option value="">-- Pilih Provinsi Target --</option>';

                // Group by Island
                const islandNames = {
                    'jawa': 'Pulau Jawa',
                    'sumatera': 'Pulau Sumatera',
                    'kalimantan': 'Pulau Kalimantan',
                    'sulawesi': 'Pulau Sulawesi',
                    'bali_nusatenggara': 'Bali & Nusa Tenggara',
                    'maluku': 'Kepulauan Maluku',
                    'papua': 'Pulau Papua'
                };

                const grouped = {};
                this.provinces.forEach(p => {
                    const isl = p.island || 'lainnya';
                    if (!grouped[isl]) grouped[isl] = [];
                    grouped[isl].push(p);
                });

                Object.keys(grouped).forEach(islKey => {
                    const groupTitle = islandNames[islKey] || islKey.toUpperCase();
                    html += `<optgroup label="${groupTitle}">`;
                    grouped[islKey].forEach(p => {
                        const statusBadge = p.harvested_places > 0 ? ` (${p.harvested_places.toLocaleString()} data)` : '';
                        html += `<option value="${p.id}">${p.name}${statusBadge}</option>`;
                    });
                    html += `</optgroup>`;
                });

                select.innerHTML = html;

                // Auto select Jawa Tengah if present (contains Magelang)
                const jt = this.provinces.find(p => p.id === 'jawa_tengah');
                if (jt) {
                    select.value = 'jawa_tengah';
                    this.onProvinceSelected('jawa_tengah');
                } else if (this.provinces.length > 0) {
                    select.value = this.provinces[0].id;
                    this.onProvinceSelected(this.provinces[0].id);
                }

                this.logTerminal('OK', `34 Provinsi Indonesia berhasil dimuat ke selector.`);
            } else {
                select.innerHTML = '<option value="">Gagal memuat provinsi</option>';
                this.logTerminal('ERR', `Gagal memuat provinsi: ${data.error || 'Unknown'}`);
            }
        } catch (err) {
            console.error('loadProvinces error:', err);
            select.innerHTML = '<option value="">Koneksi error</option>';
            this.logTerminal('ERR', `Error koneksi memuat provinsi: ${err.message}`);
        }
    },

    // ─── ON PROVINCE SELECTED ───
    async onProvinceSelected(provinceId) {
        if (!provinceId) return;

        this.selectedProvince = this.provinces.find(p => p.id === provinceId) || null;
        if (!this.selectedProvince) return;

        if (this.currentMode === 'province') {
            this.updateProvinceTargetPreview();
            return;
        }

        // City selector loading
        const citySelect = document.getElementById('harvest-select-city');
        const loadingIndicator = document.getElementById('harvest-city-loading');

        if (citySelect) {
            citySelect.innerHTML = '<option value="">-- Memuat daftar kota... --</option>';
            citySelect.disabled = true;
        }
        if (loadingIndicator) loadingIndicator.style.display = 'inline';

        // Check if cities are already present in province or REGIONS_DATA for instant 0ms population
        let localCities = (this.selectedProvince.cities && this.selectedProvince.cities.length > 0) ? this.selectedProvince.cities : null;
        if (!localCities && window.REGIONS_DATA && window.REGIONS_DATA.provinces && window.REGIONS_DATA.regencies) {
            const normName = this.selectedProvince.name.toLowerCase().trim();
            const foundProv = window.REGIONS_DATA.provinces.find(p => 
                p.name.toLowerCase().trim() === normName ||
                normName.includes(p.name.toLowerCase().trim()) ||
                p.name.toLowerCase().trim().includes(normName)
            );
            if (foundProv && window.REGIONS_DATA.regencies[foundProv.id]) {
                const rawRegs = window.REGIONS_DATA.regencies[foundProv.id];
                localCities = rawRegs.map(r => ({
                    name: r.name,
                    type: r.name.toLowerCase().startsWith('kota') ? 'kota' : 'kabupaten',
                    center: { lat: r.lat, lng: r.lng },
                    bbox: Array.isArray(r.bbox) ? {
                        minLat: r.bbox[0],
                        minLng: r.bbox[1],
                        maxLat: r.bbox[2],
                        maxLng: r.bbox[3]
                    } : null
                }));
            }
        }

        if (localCities && localCities.length > 0) {
            this.cities = localCities;
            let html = '<option value="">-- Pilih Kota / Kabupaten --</option>';

            this.cities.forEach(c => {
                const badge = c.type === 'kota' ? '🏙️' : '🏞️';
                html += `<option value="${c.name}">${badge} ${c.name}</option>`;
            });

            if (citySelect) {
                citySelect.innerHTML = html;
                citySelect.disabled = false;

                // Prioritize Magelang if Jawa Tengah
                const magelangCity = this.cities.find(c => c.name.toLowerCase().includes('magelang'));
                if (magelangCity) {
                    citySelect.value = magelangCity.name;
                    this.onCitySelected(magelangCity.name);
                } else if (this.cities.length > 0) {
                    citySelect.value = this.cities[0].name;
                    this.onCitySelected(this.cities[0].name);
                }
            }

            this.logTerminal('OK', `Memuat ${this.cities.length} Kab/Kota resmi di ${this.selectedProvince.name} (Data Terverifikasi).`);
            return;
        }

        // Fallback: Query server discovery
        this.logTerminal('INFO', `Mendeteksi kota/kabupaten di ${this.selectedProvince.name}...`);

        try {
            const res = await fetch(`api/harvest_queue.php?action=discover_cities&province=${provinceId}`);
            const data = await res.json();

            if (data.success && Array.isArray(data.cities)) {
                this.cities = data.cities;
                let html = '<option value="">-- Pilih Kota / Kabupaten --</option>';

                this.cities.forEach(c => {
                    const badge = c.type === 'kota' ? '🏙️' : '🏞️';
                    html += `<option value="${c.name}">${badge} ${c.name}</option>`;
                });

                if (citySelect) {
                    citySelect.innerHTML = html;
                    citySelect.disabled = false;

                    // Prioritize Magelang if Jawa Tengah
                    const magelangCity = this.cities.find(c => c.name.toLowerCase().includes('magelang'));
                    if (magelangCity) {
                        citySelect.value = magelangCity.name;
                        this.onCitySelected(magelangCity.name);
                    } else if (this.cities.length > 0) {
                        citySelect.value = this.cities[0].name;
                        this.onCitySelected(this.cities[0].name);
                    }
                }

                this.logTerminal('OK', `Ditemukan ${this.cities.length} Kab/Kota di ${this.selectedProvince.name}.`);
            } else {
                if (citySelect) {
                    citySelect.innerHTML = `<option value="${this.selectedProvince.capital}">${this.selectedProvince.capital} (Ibu Kota)</option>`;
                    citySelect.disabled = false;
                    this.onCitySelected(this.selectedProvince.capital);
                }
            }
        } catch (err) {
            console.error('discover_cities error:', err);
            if (citySelect) {
                citySelect.innerHTML = `<option value="${this.selectedProvince.capital}">${this.selectedProvince.capital}</option>`;
                citySelect.disabled = false;
                this.onCitySelected(this.selectedProvince.capital);
            }
        } finally {
            if (loadingIndicator) loadingIndicator.style.display = 'none';
        }
    },

    // ─── ON CITY SELECTED ───
    onCitySelected(cityName) {
        if (!cityName) return;
        this.selectedCity = this.cities.find(c => c.name === cityName) || {
            name: cityName,
            type: cityName.toLowerCase().startsWith('kota') ? 'kota' : 'kabupaten'
        };
        this.updateTargetPreview();
    },

    // ─── UPDATE TARGET PREVIEWS ───
    updateTargetPreview() {
        const nameEl = document.getElementById('preview-target-name');
        const badgeEl = document.getElementById('preview-target-badge');
        const islandEl = document.getElementById('preview-island');
        const coordsEl = document.getElementById('preview-coords');

        if (this.selectedCity && this.selectedProvince) {
            if (nameEl) nameEl.textContent = this.selectedCity.name;
            if (badgeEl) badgeEl.textContent = this.selectedProvince.name;
            if (islandEl) islandEl.textContent = this.selectedProvince.island ? this.selectedProvince.island.toUpperCase() : 'Indonesia';
            if (coordsEl) {
                const c = this.selectedCity.center || this.selectedProvince.center;
                coordsEl.textContent = c ? `${c.lat.toFixed(2)}, ${c.lng.toFixed(2)}` : 'Auto Bbox';
            }
        }
    },

    updateProvinceTargetPreview() {
        const nameEl = document.getElementById('preview-target-name');
        const badgeEl = document.getElementById('preview-target-badge');
        const islandEl = document.getElementById('preview-island');
        const coordsEl = document.getElementById('preview-coords');

        if (this.selectedProvince) {
            if (nameEl) nameEl.textContent = `Seluruh ${this.selectedProvince.name}`;
            if (badgeEl) badgeEl.textContent = '1 Provinsi Penuh';
            if (islandEl) islandEl.textContent = this.selectedProvince.island ? this.selectedProvince.island.toUpperCase() : 'Indonesia';
            if (coordsEl) {
                coordsEl.textContent = `Ibukota: ${this.selectedProvince.capital}`;
            }
        }
    },

    updateIndonesiaTargetPreview() {
        const nameEl = document.getElementById('preview-target-name');
        const badgeEl = document.getElementById('preview-target-badge');
        const islandEl = document.getElementById('preview-island');
        const coordsEl = document.getElementById('preview-coords');

        if (nameEl) nameEl.textContent = 'Seluruh Indonesia (Sekuensial Otomatis)';
        if (badgeEl) badgeEl.textContent = '34 Provinsi Nasional';
        if (islandEl) islandEl.textContent = 'Semua Pulau (Jawa, Sumatera, Kalimantan, Sulawesi, dll)';
        if (coordsEl) coordsEl.textContent = '514 Kab/Kota Target';
    },

    // ─── TOGGLE CATEGORIES ───
    toggleAllCategories() {
        const chips = document.querySelectorAll('.harvest-chip');
        const anyUnchecked = Array.from(chips).some(c => !c.querySelector('input').checked);
        chips.forEach(chip => {
            const input = chip.querySelector('input');
            input.checked = anyUnchecked;
            chip.classList.toggle('active', anyUnchecked);
        });
        const btn = document.getElementById('btn-toggle-all-categories');
        if (btn) btn.textContent = anyUnchecked ? 'Batal Pilih Semua' : 'Pilih Semua';
    },

    // ─── ADD TO QUEUE ───
    async addToQueue() {
        if (this.currentMode === 'city') {
            if (!this.selectedCity || !this.selectedProvince) {
                this.toast('Silakan pilih provinsi dan kota terlebih dahulu.', 'warning');
                return;
            }

            try {
                this.logTerminal('QUEUE', `Menambahkan "${this.selectedCity.name}" ke antrean...`);
                const res = await fetch('api/harvest_queue.php?action=queue_city', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        name: this.selectedCity.name,
                        province_name: this.selectedProvince.name,
                        province_id: this.selectedProvince.id,
                        bbox: this.selectedCity.bbox || null
                    })
                });
                const data = await res.json();
                if (data.success) {
                    this.toast(data.message, 'success');
                    this.logTerminal('OK', `"${this.selectedCity.name}" berhasil masuk ke antrean. Total antrean: ${data.total_queue}`);
                    this.loadQueueStatus();
                } else {
                    this.toast(data.error || 'Gagal menambahkan ke antrean', 'error');
                    this.logTerminal('WARN', data.error);
                }
            } catch (err) {
                this.toast(`Error: ${err.message}`, 'error');
                this.logTerminal('ERR', `Error add queue: ${err.message}`);
            }
        } else if (this.currentMode === 'province') {
            if (!this.selectedProvince) {
                this.toast('Silakan pilih provinsi terlebih dahulu.', 'warning');
                return;
            }

            try {
                this.logTerminal('QUEUE', `Mengantrekan seluruh kab/kota di ${this.selectedProvince.name}...`);
                const res = await fetch(`api/harvest_queue.php?action=queue_province&province=${this.selectedProvince.id}`, {
                    method: 'POST'
                });
                const data = await res.json();
                if (data.success) {
                    this.toast(data.message, 'success');
                    this.logTerminal('OK', `Berhasil mengantrekan ${data.cities_queued} kota di ${this.selectedProvince.name}.`);
                    this.loadQueueStatus();
                } else {
                    this.toast(data.error || 'Gagal antre provinsi', 'error');
                    this.logTerminal('ERR', data.error);
                }
            } catch (err) {
                this.toast(`Error: ${err.message}`, 'error');
            }
        } else if (this.currentMode === 'indonesia') {
            if (!confirm('Anda akan mengantrekan seluruh 509 Kab/Kota se-Indonesia secara otomatis. Lanjutkan?')) {
                return;
            }

            this.logTerminal('QUEUE', 'Mengantrekan seluruh Indonesia (509 Kab/Kota)...');
            try {
                const res = await fetch('api/harvest_queue.php?action=queue_all_indonesia', { method: 'POST' });
                const d = await res.json();
                if (d.success) {
                    this.toast(d.message, 'success');
                    this.logTerminal('OK', `Antrean Nasional Siap: ${d.cities_queued} kab/kota baru masuk antrean. Total antrean: ${d.total_queue}`);
                    this.loadQueueStatus();
                } else {
                    this.toast(d.error || 'Gagal antre nasional', 'error');
                    this.logTerminal('ERR', d.error || 'Gagal antre nasional');
                }
            } catch (e) {
                this.toast(`Error: ${e.message}`, 'error');
                this.logTerminal('ERR', `Error antre nasional: ${e.message}`);
            }
        }
    },

    // ─── QUEUE & RUN DIRECT ───
    async queueAndRunDirect() {
        await this.addToQueue();
        setTimeout(() => {
            this.startAutoHarvest();
        }, 800);
    },

    // ─── AUTO HARVEST: LAUNCH BACKGROUND WORKER ───
    async startAutoHarvest() {
        if (this.isAutoRunning) return;

        this.logTerminal('START', '🚀 Meluncurkan worker latar belakang untuk panen otomatis...');

        try {
            const res = await fetch('api/harvest_queue.php?action=launch_worker', { method: 'POST' });
            const data = await res.json();

            if (!data.success) {
                this.toast(data.error || 'Gagal memulai background worker', 'error');
                this.logTerminal('ERR', data.error || 'Gagal meluncurkan worker');
                return;
            }

            this.isAutoRunning = true;
            this.isPaused = false;
            this.updateRunningUI(data.pid);
            this.logTerminal('OK', `Worker aktif di latar belakang (PID: ${data.pid || '-'}). Streaming status langsung...`);

            this.startWorkerPolling();
        } catch (err) {
            this.toast(`Error: ${err.message}`, 'error');
            this.logTerminal('ERR', `Error launching worker: ${err.message}`);
        }
    },

    // ─── CHECK IF BACKGROUND WORKER IS ALREADY RUNNING ───
    checkBackgroundWorker() {
        fetch('api/harvest_queue.php?action=worker_status')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.is_running) {
                    this.isAutoRunning = true;
                    this.isPaused = false;
                    this.updateRunningUI(data.pid);
                    this.logTerminal('INFO', `Tersambung kembali ke Background Worker (PID: ${data.pid})...`);
                    this.startWorkerPolling();
                }
            })
            .catch(e => console.warn('checkBackgroundWorker err:', e));
    },

    // ─── POLLING WORKER STATUS & LOGS ───
    startWorkerPolling() {
        if (this.pollTimer) clearInterval(this.pollTimer);

        let lastMessage = '';
        let lastProcessed = -1;

        this.pollTimer = setInterval(async () => {
            if (!this.isAutoRunning) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
                return;
            }

            try {
                const res = await fetch('api/harvest_queue.php?action=worker_status');
                const data = await res.json();

                if (!data.success) return;

                const status = data.status || {};
                
                // If message changed, log to terminal
                if (status.message && status.message !== lastMessage) {
                    lastMessage = status.message;
                    if (status.state === 'error') {
                        this.logTerminal('ERR', status.message);
                    } else if (status.message.startsWith('✓')) {
                        this.logTerminal('DATA', status.message);
                    } else if (status.message.startsWith('🎉')) {
                        this.logTerminal('OK', status.message);
                    } else {
                        this.logTerminal('HARVEST', status.message);
                    }
                }

                // Update active region badge
                const activeLabel = document.getElementById('harvest-active-label');
                if (activeLabel) {
                    if (status.current_region) {
                        activeLabel.textContent = `Memanen: ${status.current_region} (${status.current_province || ''})`;
                    } else if (status.state === 'completed') {
                        activeLabel.textContent = '🎉 Seluruh Antrean Selesai!';
                    } else {
                        activeLabel.textContent = status.message || 'Worker Aktif';
                    }
                }

                // If processed count changed, reload queue table
                if (status.processed !== lastProcessed) {
                    lastProcessed = status.processed;
                    this.loadQueueStatus();
                    this.loadHarvestHistory();
                }

                // Check completion or stoppage
                if (!data.is_running) {
                    if (status.state === 'completed') {
                        this.logTerminal('OK', '🎉 SELURUH ANTREAN WILAYAH BERHASIL DIPANEN 100%!');
                        this.toast('Seluruh antrean panen telah selesai diproses!', 'success');
                    } else if (status.state === 'stopped') {
                        this.logTerminal('WARN', 'Worker dihentikan.');
                    }
                    this.stopAutoHarvestUI();
                    clearInterval(this.pollTimer);
                    this.pollTimer = null;
                }

            } catch (err) {
                console.warn('Poll error:', err);
            }
        }, 2500);
    },

    updateRunningUI(pid) {
        const btnStart = document.getElementById('btn-harvest-start-auto');
        const btnPause = document.getElementById('btn-harvest-pause-auto');
        const pulse = document.getElementById('harvest-pulse-status');
        const statStatus = document.getElementById('stat-harvest-engine-status');
        const statHint = document.getElementById('stat-harvest-engine-hint');

        if (btnStart) {
            btnStart.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Mesin Berjalan (PID: ${pid || '-'})`;
            btnStart.disabled = true;
        }
        if (btnPause) {
            btnPause.style.display = 'inline-block';
            btnPause.disabled = false;
        }
        if (pulse) {
            pulse.className = 'pulse-indicator working';
        }
        if (statStatus) statStatus.textContent = 'Sedang Memanen';
        if (statHint) statHint.textContent = `Background worker aktif (PID: ${pid || '-'})`;
    },

    async pauseAutoHarvest() {
        this.isPaused = true;
        this.isAutoRunning = false;
        if (this.pollTimer) {
            clearInterval(this.pollTimer);
            this.pollTimer = null;
        }

        try {
            this.logTerminal('WARN', '⏸️ Mengirim sinyal jeda ke worker...');
            const res = await fetch('api/harvest_queue.php?action=stop_worker', { method: 'POST' });
            const data = await res.json();
            this.toast(data.message || 'Worker dihentikan.', 'info');
            this.logTerminal('OK', 'Worker latar belakang berhasil dihentikan.');
        } catch (e) {
            console.error('stop_worker error:', e);
        }

        this.stopAutoHarvestUI();
        this.loadQueueStatus();
    },

    stopAutoHarvestUI() {
        this.isAutoRunning = false;
        const btnStart = document.getElementById('btn-harvest-start-auto');
        const btnPause = document.getElementById('btn-harvest-pause-auto');
        const pulse = document.getElementById('harvest-pulse-status');
        const statStatus = document.getElementById('stat-harvest-engine-status');
        const statHint = document.getElementById('stat-harvest-engine-hint');
        const activeLabel = document.getElementById('harvest-active-label');

        if (btnStart) {
            btnStart.innerHTML = '<i class="fa-solid fa-play"></i> Mulai Panen Otomatis';
            btnStart.disabled = false;
        }
        if (btnPause) btnPause.style.display = 'none';
        if (pulse) pulse.className = 'pulse-indicator idle';
        if (statStatus) statStatus.textContent = 'Idle / Standby';
        if (statHint) statHint.textContent = 'Siap memanen data';
        if (activeLabel) activeLabel.textContent = 'Standby / Siap Memanen';
    },

    // ─── CLEAR QUEUE ───
    async clearQueue() {
        if (!confirm('Apakah Anda yakin ingin mengosongkan seluruh antrean wilayah?')) {
            return;
        }

        try {
            const res = await fetch('api/harvest_queue.php?action=clear_queue');
            const data = await res.json();
            if (data.success) {
                this.toast('Antrean berhasil dikosongkan.', 'info');
                this.logTerminal('INFO', 'Seluruh item antrean telah dibersihkan.');
                this.loadQueueStatus();
            }
        } catch (err) {
            this.toast(`Gagal membersihkan antrean: ${err.message}`, 'error');
        }
    },

    // ─── SYNC TO DATABASE (SQLite & cPanel MySQL) ───
    async syncToDatabase() {
        const btn = document.getElementById('btn-sync-sqlite-db');
        const originalHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyinkronkan...';
            btn.disabled = true;
        }

        this.toast('Menyinkronkan seluruh data hasil panen ke database SQLite dan cPanel dump...', 'info');
        this.logTerminal('INFO', 'Memulai sinkronisasi massal seluruh file hasil panen ke database...');

        try {
            const res = await fetch('api/harvest_queue.php?action=sync_to_db');
            const data = await res.json();

            if (data.success) {
                const totalPlaces = data.sync ? data.sync.total_places : 0;
                const totalFiles = data.sync ? data.sync.total_files : 0;
                this.toast(`Sinkronisasi sukses! ${totalPlaces.toLocaleString()} data tersimpan di database.`, 'success');
                this.logTerminal('OK', `✓ Berhasil menyinkronkan ${totalPlaces.toLocaleString()} data bisnis dari ${totalFiles} file ke database.`);
                this.loadHarvestHistory();
                this.loadQueueStatus();
            } else {
                this.toast(`Gagal sinkronisasi: ${data.error || 'Terjadi kesalahan'}`, 'error');
                this.logTerminal('ERR', `Sinkronisasi gagal: ${data.error || 'Unknown error'}`);
            }
        } catch (err) {
            this.toast(`Error koneksi: ${err.message}`, 'error');
            this.logTerminal('ERR', `Error jaringan saat sinkronisasi: ${err.message}`);
        } finally {
            if (btn) {
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            }
        }
    },

    // ─── REFRESH ALL ───
    refreshAll() {
        this.loadQueueStatus();
        this.loadHarvestHistory();
        this.toast('Status antrean dan database panen diperbarui.', 'info');
    },

    // ─── LOAD QUEUE STATUS ───
    async loadQueueStatus() {
        try {
            const res = await fetch('api/harvest_queue.php?action=queue_status');
            const data = await res.json();

            if (data.success) {
                const stats = data.stats || {};
                
                // Update quick stats cards
                const qTotal = document.getElementById('stat-harvest-queue-total');
                const qDone = document.getElementById('stat-harvest-done');
                const qPlaces = document.getElementById('stat-harvest-places');
                const progressFill = document.getElementById('harvest-progress-bar');
                const progressCounter = document.getElementById('harvest-progress-counter');
                const tabQueueCount = document.getElementById('tab-queue-count');

                if (qTotal) qTotal.textContent = stats.total || 0;
                if (qDone) qDone.textContent = stats.done || 0;
                if (qPlaces) qPlaces.textContent = (stats.total_places || 0).toLocaleString();
                if (tabQueueCount) tabQueueCount.textContent = stats.total || 0;

                const pct = stats.progress_pct || 0;
                if (progressFill) progressFill.style.width = `${pct}%`;
                if (progressCounter) progressCounter.textContent = `${pct}% (${stats.done || 0}/${stats.total || 0})`;

                // Render Queue Table
                this.renderQueueTable(data.items || []);
            }
        } catch (err) {
            console.error('loadQueueStatus error:', err);
        }
    },

    // ─── RENDER QUEUE TABLE ───
    renderQueueTable(items) {
        const tbody = document.getElementById('harvest-queue-tbody');
        if (!tbody) return;

        if (items.length === 0) {
            tbody.innerHTML = `
                <tr>
                  <td colspan="8" style="text-align: center; padding: 32px 12px; color: #94a3b8;">
                    <i class="fa-solid fa-inbox" style="font-size: 2rem; margin-bottom: 8px; display: block;"></i>
                    Belum ada wilayah dalam antrean. Pilih wilayah di atas dan klik "Masukkan ke Antrean".
                  </td>
                </tr>
            `;
            return;
        }

        let html = '';
        items.forEach((item, idx) => {
            let statusBadge = '';
            if (item.status === 'pending') {
                statusBadge = '<span class="badge" style="background:#f1f5f9; color:#475569;"><i class="fa-solid fa-clock"></i> Menunggu</span>';
            } else if (item.status === 'processing') {
                statusBadge = '<span class="badge badge-blue"><i class="fa-solid fa-spinner fa-spin"></i> Memanen...</span>';
            } else if (item.status === 'done') {
                statusBadge = '<span class="badge badge-green"><i class="fa-solid fa-check"></i> Selesai</span>';
            } else if (item.status === 'error') {
                statusBadge = `<span class="badge" style="background:#fee2e2; color:#ef4444;" title="${item.error || 'Error'}"><i class="fa-solid fa-triangle-exclamation"></i> Gagal</span>`;
            }

            const bboxStr = item.bbox 
                ? `${item.bbox.minLat.toFixed(2)},${item.bbox.minLng.toFixed(2)}` 
                : '<span style="color:#94a3b8;">Auto-detect</span>';

            const placesFound = item.places_found ? `<strong>${item.places_found.toLocaleString()}</strong> data` : '-';
            const completedAt = item.completed_at || item.queued_at || '-';

            html += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                  <td style="padding: 10px 12px; color: #94a3b8;">${idx + 1}</td>
                  <td style="padding: 10px 12px; font-weight: 600; color: #0f172a;">${item.name}</td>
                  <td style="padding: 10px 12px; color: #475569;">${item.province_name || '-'}</td>
                  <td style="padding: 10px 12px; font-family: monospace; font-size: 0.75rem; color: #64748b;">${bboxStr}</td>
                  <td style="padding: 10px 12px;">${statusBadge}</td>
                  <td style="padding: 10px 12px; color: #10b981;">${placesFound}</td>
                  <td style="padding: 10px 12px; font-size: 0.75rem; color: #64748b;">${completedAt}</td>
                  <td style="padding: 10px 12px; text-align: right;">
                    <button type="button" class="btn btn-outline btn-sm" onclick="HarvestManager.removeQueueItem('${item.id}')" style="padding: 4px 8px; color: #ef4444; border-color: #fee2e2;">
                      <i class="fa-solid fa-trash-can"></i>
                    </button>
                  </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    },

    // ─── REMOVE QUEUE ITEM ───
    async removeQueueItem(id) {
        try {
            const res = await fetch(`api/harvest_queue.php?action=remove_item&id=${id}`);
            const data = await res.json();
            if (data.success) {
                this.loadQueueStatus();
            }
        } catch (e) {
            console.error('removeQueueItem error:', e);
        }
    },

    // ─── LOAD HARVEST HISTORY ───
    async loadHarvestHistory() {
        try {
            const res = await fetch('api/harvest_queue.php?action=harvested_regions');
            const data = await res.json();

            if (data.success) {
                const countEl = document.getElementById('tab-history-count');
                if (countEl) countEl.textContent = data.total_harvests || 0;

                const statPlaces = document.getElementById('stat-harvest-places');
                if (statPlaces && (!statPlaces.textContent || statPlaces.textContent === '0')) {
                    statPlaces.textContent = (data.total_places || 0).toLocaleString();
                }

                const termStats = document.getElementById('terminal-stats-text');
                if (termStats) termStats.textContent = `Total Terpanen: ${(data.total_places || 0).toLocaleString()} data bisnis`;

                this.renderHistoryTable(data.harvests || []);
            }
        } catch (err) {
            console.error('loadHarvestHistory error:', err);
        }
    },

    // ─── RENDER HISTORY TABLE ───
    renderHistoryTable(harvests) {
        const tbody = document.getElementById('harvest-history-tbody');
        if (!tbody) return;

        if (harvests.length === 0) {
            tbody.innerHTML = `
                <tr>
                  <td colspan="6" style="text-align: center; padding: 32px 12px; color: #94a3b8;">
                    <i class="fa-solid fa-folder-open" style="font-size: 2rem; margin-bottom: 8px; display: block;"></i>
                    Belum ada file arsip panen. Jalankan proses panen untuk menghasilkan data.
                  </td>
                </tr>
            `;
            return;
        }

        let html = '';
        harvests.forEach((h, idx) => {
            const regionName = h.region || 'Wilayah';
            const totalRec = (h.total_records || 0).toLocaleString();
            const dateStr = h.date || '-';
            const jsonlFile = h.jsonl_file || '';

            html += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                  <td style="padding: 10px 12px; color: #94a3b8;">${idx + 1}</td>
                  <td style="padding: 10px 12px; font-weight: 600; color: #0f172a;">${regionName}</td>
                  <td style="padding: 10px 12px; color: #64748b; font-size: 0.78rem;">${dateStr}</td>
                  <td style="padding: 10px 12px; font-weight: 700; color: #16a34a;">${totalRec} data</td>
                  <td style="padding: 10px 12px; font-size: 0.75rem; color: #64748b;">${jsonlFile}</td>
                  <td style="padding: 10px 12px; text-align: right;">
                    <div style="display: inline-flex; gap: 6px;">
                      <a href="data/${jsonlFile}" download class="btn btn-outline btn-sm" style="padding: 4px 8px; font-size: 0.75rem;" title="Download JSONL">
                        <i class="fa-solid fa-download"></i> JSON
                      </a>
                      <button type="button" class="btn btn-primary btn-sm" onclick="HarvestManager.importToArchives('${jsonlFile}', '${regionName}')" style="padding: 4px 8px; font-size: 0.75rem;" title="Impor ke Koleksi Arsip">
                        <i class="fa-solid fa-folder-plus"></i> Impor
                      </button>
                    </div>
                  </td>
                </tr>
            `;
        });

        tbody.innerHTML = html;
    },

    // ─── IMPORT HARVEST FILE TO ARCHIVES ───
    async importToArchives(jsonlFile, regionName) {
        if (!jsonlFile) return;

        try {
            this.toast(`Mengimpor hasil panen ${regionName} ke Koleksi Arsip...`, 'info');
            this.logTerminal('INFO', `Mengunduh ${jsonlFile} untuk diimpor ke Koleksi Arsip...`);

            const res = await fetch(`data/${jsonlFile}`);
            const text = await res.text();
            const lines = text.trim().split('\n').filter(l => l.trim().length > 0);

            if (lines.length === 0) {
                this.toast('File hasil panen kosong.', 'warning');
                return;
            }

            const places = [];
            for (let i = 0; i < Math.min(lines.length, 500); i++) {
                try {
                    places.push(JSON.parse(lines[i]));
                } catch (e) {}
            }

            // Create collection in ArchiveManager if available
            if (window.ArchiveManager && typeof window.ArchiveManager.saveCollection === 'function') {
                const colName = `Hasil Panen - ${regionName} (${new Date().toLocaleDateString('id-ID')})`;
                await window.ArchiveManager.saveCollection(colName, places);
                this.toast(`Sukses mengimpor ${places.length} data ke Koleksi Arsip!`, 'success');
                this.logTerminal('OK', `Berhasil membuat koleksi: "${colName}" dengan ${places.length} data.`);
            } else {
                this.toast(`File ${jsonlFile} siap digunakan (${lines.length} data).`, 'success');
            }
        } catch (err) {
            console.error('importToArchives error:', err);
            this.toast(`Gagal impor: ${err.message}`, 'error');
        }
    },

    // ─── SWITCH TABLE TAB ───
    switchTableTab(tab) {
        this.activeTab = tab;
        const tabQueue = document.getElementById('tab-btn-queue');
        const tabHist = document.getElementById('tab-btn-history');
        const viewQueue = document.getElementById('harvest-tab-queue-view');
        const viewHist = document.getElementById('harvest-tab-history-view');

        if (tab === 'queue') {
            if (tabQueue) {
                tabQueue.style.color = '#2563eb';
                tabQueue.style.borderBottomColor = '#2563eb';
            }
            if (tabHist) {
                tabHist.style.color = '#64748b';
                tabHist.style.borderBottomColor = 'transparent';
            }
            if (viewQueue) viewQueue.style.display = 'block';
            if (viewHist) viewHist.style.display = 'none';
        } else {
            if (tabQueue) {
                tabQueue.style.color = '#64748b';
                tabQueue.style.borderBottomColor = 'transparent';
            }
            if (tabHist) {
                tabHist.style.color = '#2563eb';
                tabHist.style.borderBottomColor = '#2563eb';
            }
            if (viewQueue) viewQueue.style.display = 'none';
            if (viewHist) viewHist.style.display = 'block';
        }
    },

    // ─── TERMINAL CONSOLE LOGGING ───
    logTerminal(type, message) {
        const term = document.getElementById('harvest-terminal-log');
        if (!term) return;

        const timeStr = new Date().toLocaleTimeString('id-ID', { hour12: false });
        let typeClass = 't-dim';
        let typeTag = `[${type}]`;

        if (type === 'OK') typeClass = 't-ok';
        else if (type === 'INFO') typeClass = 't-info';
        else if (type === 'WARN') typeClass = 't-warn';
        else if (type === 'ERR') typeClass = 't-err';
        else if (type === 'START') typeClass = 't-accent';
        else if (type === 'DATA') typeClass = 't-place';
        else if (type === 'QUEUE') typeClass = 't-info';

        const line = document.createElement('div');
        line.className = 't-line';
        line.innerHTML = `<span class="t-dim">${timeStr}</span> <span class="${typeClass}">${typeTag}</span> ${escapeHtml(message)}`;

        term.appendChild(line);

        // Limit to 250 lines
        while (term.children.length > 250) {
            term.removeChild(term.firstChild);
        }

        // Auto scroll to bottom
        term.scrollTop = term.scrollHeight;
    },

    clearTerminal() {
        const term = document.getElementById('harvest-terminal-log');
        if (term) {
            term.innerHTML = '<div class="t-line t-dim">[TERMINAL CLEARED]</div>';
        }
    },

    copyTerminal() {
        const term = document.getElementById('harvest-terminal-log');
        if (!term) return;
        navigator.clipboard.writeText(term.innerText)
            .then(() => this.toast('Log terminal disalin ke clipboard!', 'success'))
            .catch(() => this.toast('Gagal menyalin log', 'error'));
    },

    // ─── HARVEST GUIDE MODAL ───
    showHarvestGuide() {
        alert(
            "PANDUAN MESIN PANEN BISNIS SE-INDONESIA:\n\n" +
            "1. Mode Per Kota/Kab: Pilih provinsi dan kab/kota tertentu (contoh: Kota Magelang). Cocok untuk panen lokal instan.\n" +
            "2. Mode 1 Provinsi: Mengantrekan seluruh kab/kota di satu provinsi (contoh: Jawa Tengah ada 35 kab/kota). Sistem akan menyisir satu per satu tanpa hang.\n" +
            "3. Mode Se-Indonesia: Mengantrekan seluruh 34 provinsi se-Indonesia secara otomatis sekuensial.\n" +
            "4. Multi-API: Menggunakan Overpass OSM grid + Nominatim geocoding + filter anti-kosong untuk menangkap semua jenis bisnis (kesehatan, kuliner, retail, kantor, sekolah, jasa, dll).\n" +
            "5. Auto-Resume: Bila koneksi terputus, Anda cukup klik 'Mulai Panen Otomatis' lagi dan sistem akan melanjutkan wilayah yang tersisa tanpa menduplikasi data."
        );
    },

    // ─── TOAST NOTIFICATION ───
    toast(message, type = 'info') {
        if (window.Auth && typeof window.Auth.toast === 'function') {
            window.Auth.toast(message, type);
        } else {
            console.log(`[TOAST-${type.toUpperCase()}] ${message}`);
        }
    }
};

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

window.HarvestManager = HarvestManager;
