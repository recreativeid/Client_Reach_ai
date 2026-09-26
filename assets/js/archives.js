/**
 * Client Reach AI - Google Drive Style Nested Folders & Excel Data Explorer
 * Supports folders inside folders (e.g., Month -> Date -> Files),
 * interactive breadcrumbs navigation, direct Excel (.xlsx) downloads,
 * prospect status marking (Green = Prospek, Red = Ditolak, White = Belum),
 * and 1-click WhatsApp outreach.
 */

const ArchiveManager = {
    currentFolderId: null,
    currentFolderName: 'Arsip Utama',
    breadcrumbs: [{ id: null, name: 'Arsip Utama' }],
    currentArchiveId: null,
    currentArchiveName: '',
    allArchives: [],
    currentItems: [],
    activeFilter: 'all', // 'all', 'prospect', 'rejected', 'none'
    viewMode: 'cards',   // 'cards', 'table' for items
    viewLayout: 'grid',  // 'grid', 'row' for collections & files
    lastFolders: [],
    lastArchives: [],

    async init() {
        this.viewLayout = localStorage.getItem('client_reach_archive_layout') || 'grid';
        await this.loadCollectionsView(null);
        this.bindEvents();
    },

    /**
     * Load Google Drive folder contents (subfolders + archives/files)
     * @param {number|null} folderId 
     */
    async loadCollectionsView(folderId = null) {
        this.currentFolderId = folderId !== null && folderId !== undefined && folderId !== '' ? parseInt(folderId) : null;
        this.currentArchiveId = null;

        const collectionViewEl = document.getElementById('archive-collections-view');
        const detailViewEl = document.getElementById('archive-detail-view');
        if (collectionViewEl) collectionViewEl.style.display = 'block';
        if (detailViewEl) detailViewEl.style.display = 'none';

        try {
            let data = null;
            const isStaticHost = window.location.hostname.includes('github.io') || window.location.protocol === 'file:';

            if (!isStaticHost) {
                try {
                    const url = `api/archives.php?action=get_view${this.currentFolderId !== null ? `&folder_id=${this.currentFolderId}` : ''}`;
                    const res = await fetch(url);
                    if (res.ok) data = await res.json();
                } catch(netErr) {}
            }

            if (!data || !data.success) {
                const staticFolders = JSON.parse(localStorage.getItem('cliento_static_folders') || '[]');
                const staticArchives = JSON.parse(localStorage.getItem('cliento_static_archives') || '[]');

                const currentFolders = staticFolders.filter(f => {
                    if (this.currentFolderId === null) return !f.parent_id;
                    return String(f.parent_id) === String(this.currentFolderId);
                });

                const currentArchives = staticArchives.filter(a => {
                    if (this.currentFolderId === null) return !a.folder_id;
                    return String(a.folder_id) === String(this.currentFolderId);
                });

                data = {
                    success: true,
                    breadcrumbs: [{ id: null, name: 'Arsip Utama' }],
                    folders: currentFolders,
                    archives: currentArchives
                };
            }

            if (data.success) {
                this.breadcrumbs = data.breadcrumbs || [{ id: null, name: 'Arsip Utama' }];
                const lastCrumb = this.breadcrumbs[this.breadcrumbs.length - 1];
                this.currentFolderName = lastCrumb ? lastCrumb.name : 'Arsip Utama';

                this.renderBreadcrumbs(this.breadcrumbs);
                this.renderFolders(data.folders || []);
                this.renderFiles(data.archives || []);

                // Update up level button
                const btnUp = document.getElementById('btn-gdrive-up-level');
                if (btnUp) {
                    btnUp.style.display = this.currentFolderId !== null ? 'inline-flex' : 'none';
                }

                // Update modal parent name badge
                const parentBadge = document.getElementById('modal-folder-parent-name');
                if (parentBadge) {
                    parentBadge.textContent = `📁 ${this.currentFolderName}`;
                }

                // Toggle empty state
                const emptyEl = document.getElementById('gdrive-empty-state');
                const foldersCount = (data.folders || []).length;
                const filesCount = (data.archives || []).length;

                if (emptyEl) {
                    emptyEl.style.display = (foldersCount === 0 && filesCount === 0) ? 'block' : 'none';
                }
            }
        } catch (e) {
            console.error('Failed to load Google Drive archive view:', e);
        }
    },

    /**
     * Render clickable breadcrumb trail
     */
    renderBreadcrumbs(breadcrumbs) {
        const container = document.getElementById('gdrive-breadcrumbs-container');
        if (!container) return;

        container.innerHTML = '';
        breadcrumbs.forEach((crumb, index) => {
            const isLast = index === breadcrumbs.length - 1;

            const span = document.createElement('span');
            span.className = `gdrive-bc-item ${isLast ? 'active' : ''}`;
            span.innerHTML = index === 0 ? `<i class="fa-solid fa-house"></i> ${crumb.name}` : `<i class="fa-solid fa-layer-group"></i> ${crumb.name}`;

            if (!isLast) {
                span.addEventListener('click', () => {
                    this.loadCollectionsView(crumb.id);
                });
            }

            container.appendChild(span);

            if (!isLast) {
                const sep = document.createElement('span');
                sep.className = 'gdrive-bc-sep';
                sep.textContent = '/';
                container.appendChild(sep);
            }
        });
    },

    /**
     * Navigate up to parent folder
     */
    navigateUp() {
        if (this.breadcrumbs.length <= 1) {
            this.loadCollectionsView(null);
            return;
        }
        const parentCrumb = this.breadcrumbs[this.breadcrumbs.length - 2];
        this.loadCollectionsView(parentCrumb ? parentCrumb.id : null);
    },

    updateLayoutViewContainers() {
        const fGrid = document.getElementById('gdrive-folders-grid');
        const aGrid = document.getElementById('gdrive-files-grid');
        const isRow = (this.viewLayout === 'row');

        if (fGrid) {
            fGrid.classList.toggle('mode-row', isRow);
            fGrid.classList.toggle('mode-grid', !isRow);
        }
        if (aGrid) {
            aGrid.classList.toggle('mode-row', isRow);
            aGrid.classList.toggle('mode-grid', !isRow);
        }
        
        const btnLayoutGrid = document.getElementById('btn-layout-grid');
        const btnLayoutRow = document.getElementById('btn-layout-row');
        if (btnLayoutGrid && btnLayoutRow) {
            btnLayoutGrid.classList.toggle('active', !isRow);
            btnLayoutRow.classList.toggle('active', isRow);
            btnLayoutGrid.style.background = !isRow ? '#0f172a' : 'transparent';
            btnLayoutGrid.style.color = !isRow ? '#ffffff' : '#64748b';
            btnLayoutRow.style.background = isRow ? '#0f172a' : 'transparent';
            btnLayoutRow.style.color = isRow ? '#ffffff' : '#64748b';
        }
    },

    /**
     * Render Subfolders / Sub-Collections (Grid Cover vs Kotak Memanjang)
     */
    renderFolders(folders = []) {
        this.lastFolders = folders;
        const grid = document.getElementById('gdrive-folders-grid');
        const countEl = document.getElementById('gdrive-folder-count');
        const section = document.getElementById('gdrive-folders-section');
        if (!grid) return;

        this.updateLayoutViewContainers();

        if (countEl) countEl.textContent = folders.length;
        if (section) section.style.display = folders.length > 0 ? 'block' : 'none';

        grid.innerHTML = '';
        folders.forEach(f => {
            const card = document.createElement('div');
            
            if (this.viewLayout === 'row') {
                // Kotak Memanjang Mode
                card.className = 'glass-row-card';
                card.innerHTML = `
                    <div class="glass-row-left">
                        <div class="glass-row-thumb">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <div class="glass-row-info">
                            <div class="glass-row-title" title="${f.name}">${f.name}</div>
                            <div class="glass-row-meta">
                                <span>Sub-Koleksi</span> • <span>${f.subfolder_count || 0} Folder</span> • <span>${f.archive_count || 0} File Data Excel</span>
                            </div>
                        </div>
                    </div>
                    <div class="glass-row-actions">
                        <button class="btn btn-outline btn-sm btn-open-collection" style="font-size: 0.74rem; padding: 4px 10px;">
                            Buka
                        </button>
                        <button class="btn btn-outline btn-sm btn-del-folder" style="padding: 4px 8px; border:none; color: #64748b;" title="Hapus Koleksi">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                `;
            } else {
                // Grid Cover Mode (Glassmorphism Clean)
                card.className = 'glass-collection-card';
                card.innerHTML = `
                    <div class="glass-card-cover">
                        <i class="fa-solid fa-layer-group cover-icon-watermark"></i>
                        <span class="cover-badge-top">Sub-Koleksi</span>
                        <div class="cover-label-bottom">
                            <i class="fa-solid fa-box-archive"></i> Direktori Koleksi
                        </div>
                    </div>
                    <div class="glass-card-body">
                        <div>
                            <div class="glass-card-title" title="${f.name}">${f.name}</div>
                            <div class="glass-card-meta">
                                <span>${f.subfolder_count || 0} Folder</span> • <span>${f.archive_count || 0} File Data Excel</span>
                            </div>
                        </div>
                        <div class="glass-card-actions">
                            <button class="btn btn-outline btn-sm btn-open-collection" style="font-size: 0.72rem; padding: 4px 10px;">
                                Buka Koleksi
                            </button>
                            <button class="btn btn-outline btn-sm btn-del-folder" style="padding: 3px 8px; border:none; color: #64748b;" title="Hapus Koleksi">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            }

            // Open collection on card or button click
            card.addEventListener('click', (e) => {
                if (e.target.closest('.btn-del-folder')) return;
                this.loadCollectionsView(f.id);
            });

            // Delete folder button
            const delBtn = card.querySelector('.btn-del-folder');
            if (delBtn) {
                delBtn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    if (confirm(`Hapus koleksi "${f.name}" beserta seluruh isinya?`)) {
                        await this.deleteFolder(f.id);
                    }
                });
            }

            grid.appendChild(card);
        });
    },

    /**
     * Render Excel / Dataset Files (Grid Cover vs Kotak Memanjang)
     */
    renderFiles(archives = []) {
        this.lastArchives = archives;
        const grid = document.getElementById('gdrive-files-grid');
        const countEl = document.getElementById('gdrive-files-count');
        const section = document.getElementById('gdrive-files-section');
        if (!grid) return;

        this.updateLayoutViewContainers();

        if (countEl) countEl.textContent = archives.length;
        if (section) section.style.display = archives.length > 0 ? 'block' : 'none';

        grid.innerHTML = '';
        archives.forEach(a => {
            const card = document.createElement('div');
            const dateStr = a.created_at ? a.created_at.substring(0, 10) : '';

            if (this.viewLayout === 'row') {
                // Kotak Memanjang Mode
                card.className = 'glass-row-card';
                card.innerHTML = `
                    <div class="glass-row-left">
                        <div class="glass-row-thumb file-thumb">
                            <i class="fa-solid fa-file-excel"></i>
                        </div>
                        <div class="glass-row-info">
                            <div class="glass-row-title" title="${a.name}">${a.name}</div>
                            <div class="glass-row-meta">
                                <span>Dataset Leads</span> • <span><i class="fa-solid fa-users"></i> ${a.total_items} Kontak</span> • <span>${dateStr}</span>
                            </div>
                        </div>
                    </div>
                    <div class="glass-row-actions">
                        <button class="btn btn-primary btn-sm btn-open-data" style="font-size: 0.74rem; padding: 5px 12px; background: #0f172a; color:#fff;">
                            <i class="fa-solid fa-eye"></i> Buka Data
                        </button>
                        <button class="btn btn-outline btn-sm btn-download-excel" style="padding: 5px 10px; font-size: 0.74rem;" title="Download File Excel">
                            <i class="fa-solid fa-download"></i> Excel
                        </button>
                        <button class="btn btn-outline btn-sm btn-del-archive" style="padding: 4px 8px; border:none; color: #64748b;" title="Hapus File Data">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                `;
            } else {
                // Grid Cover Mode (Glassmorphism Clean)
                card.className = 'glass-collection-card';
                card.innerHTML = `
                    <div class="glass-card-cover file-cover">
                        <i class="fa-solid fa-file-excel cover-icon-watermark"></i>
                        <span class="cover-badge-top">Excel Dataset</span>
                        <div class="cover-label-bottom">
                            <i class="fa-solid fa-file-lines"></i> ${dateStr}
                        </div>
                    </div>
                    <div class="glass-card-body">
                        <div>
                            <div class="glass-card-title" title="${a.name}">${a.name}</div>
                            <div class="glass-card-meta">
                                <span><i class="fa-solid fa-users"></i> ${a.total_items} Kontak Leads</span> • <span>${dateStr}</span>
                            </div>
                        </div>
                        <div class="glass-card-actions">
                            <button class="btn btn-primary btn-sm btn-open-data" style="font-size: 0.72rem; padding: 4px 10px; background: #0f172a; color: #fff;">
                                <i class="fa-solid fa-eye"></i> Buka Data
                            </button>
                            <div style="display: flex; gap: 4px;">
                                <button class="btn btn-outline btn-sm btn-download-excel" style="padding: 4px 8px; font-size: 0.72rem;" title="Download File Excel">
                                    <i class="fa-solid fa-download"></i> Excel
                                </button>
                                <button class="btn btn-outline btn-sm btn-del-archive" style="padding: 4px 8px; border:none; color: #64748b;" title="Hapus File Data">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            }

            // Open leads view
            const openBtn = card.querySelector('.btn-open-data');
            if (openBtn) {
                openBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.openCollectionDetail(a.id, a.name);
                });
            }

            // Card click also opens
            card.addEventListener('click', (e) => {
                if (e.target.closest('.btn-download-excel') || e.target.closest('.btn-del-archive')) return;
                this.openCollectionDetail(a.id, a.name);
            });

            // Direct download Excel
            const dlBtn = card.querySelector('.btn-download-excel');
            if (dlBtn) {
                dlBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.downloadArchiveExcel(a.id, a.name);
                });
            }

            // Delete archive file
            const delBtn = card.querySelector('.btn-del-archive');
            if (delBtn) {
                delBtn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    if (confirm(`Hapus file data "${a.name}"?`)) {
                        await this.deleteCollection(a.id);
                    }
                });
            }

            grid.appendChild(card);
        });
    },

    /**
     * Download Excel file directly from file card
     */
    async downloadArchiveExcel(archiveId, archiveName) {
        try {
            let data = null;
            const isStaticHost = window.location.hostname.includes('github.io') || window.location.protocol === 'file:';
            if (!isStaticHost) {
                try {
                    const res = await fetch(`api/archives.php?action=get_archive_items&archive_id=${archiveId}`);
                    if (res.ok) data = await res.json();
                } catch(netErr) {}
            }
            if (!data || !data.success) {
                const staticArchives = JSON.parse(localStorage.getItem('cliento_static_archives') || '[]');
                const found = staticArchives.find(a => String(a.id) === String(archiveId));
                data = { success: true, items: found ? found.items : [] };
            }
            if (data.success && data.items && data.items.length > 0) {
                const excelRows = data.items.map((item, idx) => ({
                    'No': idx + 1,
                    'Nama Tempat': item.name,
                    'Kategori': item.category,
                    'Alamat Lengkap': item.address,
                    'Nomor HP (WhatsApp)': item.phone,
                    'Rating': item.rating,
                    'Jumlah Ulasan': item.reviews_count,
                    'Jam Operasional': item.opening_hours,
                    'Status Prospek': item.status === 'prospect' ? 'Prospek' : (item.status === 'rejected' ? 'Bukan Prospek' : 'Belum Dihubungi')
                }));

                const ws = XLSX.utils.json_to_sheet(excelRows);
                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Leads');
                XLSX.writeFile(wb, `${archiveName}.xlsx`);
            } else {
                alert('Tidak ada data leads pada file ini!');
            }
        } catch (e) {
            alert('Gagal mendownload Excel: ' + e.message);
        }
    },

    /**
     * Open New Folder Modal
     */
    openCreateFolderModal() {
        const modal = document.getElementById('modal-create-folder');
        const parentNameEl = document.getElementById('modal-folder-parent-name');
        const input = document.getElementById('create-folder-name-input');

        if (parentNameEl) {
            parentNameEl.textContent = `📁 ${this.currentFolderName}`;
        }
        if (input) {
            input.value = '';
            setTimeout(() => input.focus(), 150);
        }
        if (modal) modal.classList.add('active');
    },

    /**
     * Submit Create Folder to API
     */
    async submitCreateFolder() {
        const input = document.getElementById('create-folder-name-input');
        const name = input ? input.value.trim() : '';

        if (!name) {
            alert('Silakan masukkan nama folder!');
            return;
        }

        try {
            const res = await fetch('api/archives.php?action=create_folder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    name: name,
                    parent_id: this.currentFolderId
                })
            });

            if (res.ok) {
                const data = await res.json();
                if (data.success) {
                    document.getElementById('modal-create-folder')?.classList.remove('active');
                    await this.loadCollectionsView(this.currentFolderId);
                    this.showToast(`✓ Folder "${name}" berhasil dibuat!`);
                    return;
                }
            }
        } catch (e) {
            console.warn('Backend unavailable, saving folder locally:', e);
        }

        // Static host / offline fallback
        const folders = JSON.parse(localStorage.getItem('cliento_static_folders') || '[]');
        folders.push({
            id: 'folder_' + Date.now(),
            name: name,
            parent_id: this.currentFolderId || null
        });
        localStorage.setItem('cliento_static_folders', JSON.stringify(folders));
        document.getElementById('modal-create-folder')?.classList.remove('active');
        await this.loadCollectionsView(this.currentFolderId);
        this.showToast(`✓ Folder "${name}" berhasil dibuat!`);
    },

    /**
     * Delete Folder
     */
    async deleteFolder(folderId) {
        try {
            const res = await fetch('api/archives.php?action=delete_folder', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ folder_id: folderId })
            });
            if (res.ok) {
                const data = await res.json();
                if (data.success) {
                    await this.loadCollectionsView(this.currentFolderId);
                    if (window.App) window.App.refreshDashboardStats();
                    this.showToast('✓ Folder berhasil dihapus.');
                    return;
                }
            }
        } catch (e) {
            console.warn('Backend unavailable, deleting folder locally:', e);
        }

        let folders = JSON.parse(localStorage.getItem('cliento_static_folders') || '[]');
        folders = folders.filter(f => String(f.id) !== String(folderId));
        localStorage.setItem('cliento_static_folders', JSON.stringify(folders));
        await this.loadCollectionsView(this.currentFolderId);
        if (window.App) window.App.refreshDashboardStats();
        this.showToast('✓ Folder berhasil dihapus.');
    },

    /**
     * Open Leads View for an Archive
     */
    async openCollectionDetail(archiveId, archiveName) {
        this.currentArchiveId = archiveId;
        this.currentArchiveName = archiveName;

        const collectionViewEl = document.getElementById('archive-collections-view');
        const detailViewEl = document.getElementById('archive-detail-view');
        const titleEl = document.getElementById('collection-detail-title');

        if (collectionViewEl) collectionViewEl.style.display = 'none';
        if (detailViewEl) detailViewEl.style.display = 'block';
        if (titleEl) titleEl.textContent = archiveName;

        try {
            const isStaticHost = window.location.hostname.includes('github.io') || window.location.protocol === 'file:';
            if (!isStaticHost) {
                const res = await fetch(`api/archives.php?action=get_archive_items&archive_id=${archiveId}`);
                if (res.ok) {
                    const data = await res.json();
                    if (data.success) {
                        this.currentItems = data.items || [];
                        this.renderCollectionItems();
                        return;
                    }
                }
            }
        } catch (e) {
            console.warn('Backend unavailable, loading items from localStorage:', e);
        }

        // Static host / offline fallback
        const staticArchives = JSON.parse(localStorage.getItem('cliento_static_archives') || '[]');
        const target = staticArchives.find(a => String(a.id) === String(archiveId));
        this.currentItems = (target && target.items) ? target.items : [];
        this.renderCollectionItems();
    },

    /**
     * Render Leads inside an opened Archive File
     */
    renderCollectionItems() {
        const countBadge = document.getElementById('collection-count-badge');
        const cardsGrid = document.getElementById('collection-cards-grid');
        const tableBody = document.getElementById('collection-table-body');

        let filtered = this.currentItems;
        if (this.activeFilter === 'prospect') {
            filtered = this.currentItems.filter(i => i.status === 'prospect');
        } else if (this.activeFilter === 'rejected') {
            filtered = this.currentItems.filter(i => i.status === 'rejected');
        } else if (this.activeFilter === 'none') {
            filtered = this.currentItems.filter(i => i.status === 'none');
        }

        if (countBadge) countBadge.textContent = `${filtered.length} Klien Terfilter`;

        // Render Cards View
        if (cardsGrid) {
            cardsGrid.innerHTML = '';
            filtered.forEach(it => {
                const card = document.createElement('div');
                card.className = `prospect-visual-card status-${it.status}`;
                const waUrl = window.TemplateManager ? window.TemplateManager.getWhatsAppUrl(it) : '#';

                card.innerHTML = `
                    <div>
                        <div class="d-flex justify-between align-center" style="margin-bottom: 6px;">
                            <span class="badge badge-blue" style="font-size:0.65rem;">${it.category}</span>
                            <span style="font-weight: 700; color: #f59e0b; font-size: 0.78rem;">⭐ ${it.rating || '-'} (${it.reviews_count || 0})</span>
                        </div>
                        <div style="font-weight: 700; font-size: 0.9rem; color: #0f172a; margin-bottom: 4px;">${it.name}</div>
                        <div style="font-size: 0.74rem; color: #64748b; margin-bottom: 6px;"><i class="fa-solid fa-location-dot"></i> ${it.address}</div>
                        <div style="font-size: 0.74rem; font-weight: 600; color: #0f172a; margin-bottom: 10px;">
                            <i class="fa-solid fa-phone"></i> ${it.phone || '-'}
                        </div>
                    </div>

                    <div class="d-flex justify-between align-center" style="border-top: 1px solid #e2e8f0; padding-top: 10px; margin-top: 6px;">
                        <!-- Status Toggle (Hijau / Merah) -->
                        <div class="status-action-group">
                            <button class="btn-icon-toggle btn-check ${it.status === 'prospect' ? 'active' : ''}" 
                                    title="Tandai Prospek (Hijau)" data-id="${it.id}">
                                <i class="fa-solid fa-check"></i>
                            </button>
                            <button class="btn-icon-toggle btn-cross ${it.status === 'rejected' ? 'active' : ''}" 
                                    title="Tandai Bukan Prospek (Merah)" data-id="${it.id}">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>

                        <!-- WhatsApp Action -->
                        <div style="display: flex; gap: 4px;">
                            <button class="btn btn-outline btn-sm btn-open-ai-pitch-card" title="Buka AI Sales Outreach" style="font-size: 0.72rem; padding: 4px 8px;">
                                <i class="fa-solid fa-wand-magic-sparkles" style="color: #2563eb;"></i>
                            </button>
                            <a href="${waUrl}" target="_blank" class="btn btn-wa btn-sm">
                                <i class="fa-brands fa-whatsapp"></i> Chat WA
                            </a>
                        </div>
                    </div>
                `;

                // Status toggle listeners
                const btnCheck = card.querySelector('.btn-check');
                const btnCross = card.querySelector('.btn-cross');
                const btnAIPitch = card.querySelector('.btn-open-ai-pitch-card');

                if (btnAIPitch) {
                    btnAIPitch.addEventListener('click', () => {
                        if (window.ScraperClient) window.ScraperClient.openAIPitchModal(it, 'standard');
                    });
                }

                btnCheck.addEventListener('click', () => {
                    const next = it.status === 'prospect' ? 'none' : 'prospect';
                    this.updateItemStatus(it.id, next);
                    it.status = next;
                    card.className = `prospect-visual-card status-${next}`;
                    btnCheck.classList.toggle('active', next === 'prospect');
                    btnCross.classList.toggle('active', next === 'rejected');
                });

                btnCross.addEventListener('click', () => {
                    const next = it.status === 'rejected' ? 'none' : 'rejected';
                    this.updateItemStatus(it.id, next);
                    it.status = next;
                    card.className = `prospect-visual-card status-${next}`;
                    btnCheck.classList.toggle('active', next === 'prospect');
                    btnCross.classList.toggle('active', next === 'rejected');
                });

                cardsGrid.appendChild(card);
            });
        }

        // Render Table Body
        if (tableBody) {
            tableBody.innerHTML = '';
            filtered.forEach((it, idx) => {
                const tr = document.createElement('tr');
                tr.className = it.status === 'prospect' ? 'row-prospect' : (it.status === 'rejected' ? 'row-rejected' : 'row-white');
                const waUrl = window.TemplateManager ? window.TemplateManager.getWhatsAppUrl(it) : '#';

                tr.innerHTML = `
                    <td style="width: 30px; text-align: center;">${idx + 1}</td>
                    <td><strong>${it.name}</strong><br><small class="text-muted">${it.category}</small></td>
                    <td style="font-size: 0.74rem;">${it.address}</td>
                    <td style="font-weight: 600;">${it.phone || '-'}</td>
                    <td>⭐ ${it.rating || '-'}</td>
                    <td>
                        <div class="status-action-group">
                            <button class="btn-icon-toggle btn-check ${it.status === 'prospect' ? 'active' : ''}" data-id="${it.id}">
                                <i class="fa-solid fa-check"></i>
                            </button>
                            <button class="btn-icon-toggle btn-cross ${it.status === 'rejected' ? 'active' : ''}" data-id="${it.id}">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                    </td>
                    <td style="text-align: right;">
                        <a href="${waUrl}" target="_blank" class="btn btn-wa btn-sm">
                            <i class="fa-brands fa-whatsapp"></i> Chat WA
                        </a>
                    </td>
                `;

                const btnCheck = tr.querySelector('.btn-check');
                const btnCross = tr.querySelector('.btn-cross');

                btnCheck.addEventListener('click', () => {
                    const next = it.status === 'prospect' ? 'none' : 'prospect';
                    this.updateItemStatus(it.id, next);
                    it.status = next;
                    tr.className = next === 'prospect' ? 'row-prospect' : (next === 'rejected' ? 'row-rejected' : 'row-white');
                    btnCheck.classList.toggle('active', next === 'prospect');
                    btnCross.classList.toggle('active', next === 'rejected');
                });

                btnCross.addEventListener('click', () => {
                    const next = it.status === 'rejected' ? 'none' : 'rejected';
                    this.updateItemStatus(it.id, next);
                    it.status = next;
                    tr.className = next === 'prospect' ? 'row-prospect' : (next === 'rejected' ? 'row-rejected' : 'row-white');
                    btnCheck.classList.toggle('active', next === 'prospect');
                    btnCross.classList.toggle('active', next === 'rejected');
                });

                tableBody.appendChild(tr);
            });
        }
    },

    async updateItemStatus(itemId, newStatus) {
        try {
            const res = await fetch('api/archives.php?action=update_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ item_id: itemId, status: newStatus })
            });
            if (res.ok) {
                if (window.App) window.App.refreshDashboardStats();
                return;
            }
        } catch (e) {
            console.warn('Backend unavailable, updating status locally:', e);
        }

        // Static host / offline fallback
        let archives = JSON.parse(localStorage.getItem('cliento_static_archives') || '[]');
        archives.forEach(arch => {
            if (arch.items) {
                arch.items.forEach(it => {
                    if (String(it.id) === String(itemId)) {
                        it.status = newStatus;
                    }
                });
            }
        });
        localStorage.setItem('cliento_static_archives', JSON.stringify(archives));
        if (window.App) window.App.refreshDashboardStats();
    },

    async deleteCollection(archiveId) {
        try {
            const res = await fetch('api/archives.php?action=delete_archive', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ archive_id: archiveId })
            });
            if (res.ok) {
                const data = await res.json();
                if (data.success) {
                    await this.loadCollectionsView(this.currentFolderId);
                    if (window.App) window.App.refreshDashboardStats();
                    this.showToast('✓ File data berhasil dihapus.');
                    return;
                }
            }
        } catch (e) {
            console.warn('Backend unavailable, deleting collection locally:', e);
        }

        let archives = JSON.parse(localStorage.getItem('cliento_static_archives') || '[]');
        archives = archives.filter(a => String(a.id) !== String(archiveId));
        localStorage.setItem('cliento_static_archives', JSON.stringify(archives));
        await this.loadCollectionsView(this.currentFolderId);
        if (window.App) window.App.refreshDashboardStats();
        this.showToast('✓ File data berhasil dihapus.');
    },

    exportCollectionToExcel() {
        if (!this.currentItems || this.currentItems.length === 0) {
            alert('Tidak ada data untuk diekspor!');
            return;
        }

        const excelRows = this.currentItems.map((item, idx) => ({
            'No': idx + 1,
            'Nama Tempat': item.name,
            'Kategori': item.category,
            'Alamat Lengkap': item.address,
            'Nomor HP (WhatsApp)': item.phone,
            'Rating': item.rating,
            'Jumlah Ulasan': item.reviews_count,
            'Jam Operasional': item.opening_hours,
            'Status Prospek': item.status === 'prospect' ? 'Prospek' : (item.status === 'rejected' ? 'Bukan Prospek' : 'Belum Dihubungi')
        }));

        const ws = XLSX.utils.json_to_sheet(excelRows);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Koleksi Klien');

        const filename = `${this.currentArchiveName || 'Koleksi_ClientReach'}.xlsx`;
        XLSX.writeFile(wb, filename);
    },

    showToast(message) {
        if (window.ScraperClient && typeof window.ScraperClient.showToast === 'function') {
            window.ScraperClient.showToast(message);
        } else {
            alert(message);
        }
    },

    bindEvents() {
        // Layout switcher: Grid Cover vs Kotak Memanjang
        const btnLayoutGrid = document.getElementById('btn-layout-grid');
        const btnLayoutRow = document.getElementById('btn-layout-row');
        if (btnLayoutGrid && btnLayoutRow) {
            btnLayoutGrid.addEventListener('click', () => {
                this.viewLayout = 'grid';
                localStorage.setItem('client_reach_archive_layout', 'grid');
                this.updateLayoutViewContainers();
                this.renderFolders(this.lastFolders || []);
                this.renderFiles(this.lastArchives || []);
            });

            btnLayoutRow.addEventListener('click', () => {
                this.viewLayout = 'row';
                localStorage.setItem('client_reach_archive_layout', 'row');
                this.updateLayoutViewContainers();
                this.renderFolders(this.lastFolders || []);
                this.renderFiles(this.lastArchives || []);
            });
        }

        // Filter pills click
        document.querySelectorAll('.ig-filter-bar .ig-filter-pill').forEach(pill => {
            pill.addEventListener('click', () => {
                document.querySelectorAll('.ig-filter-bar .ig-filter-pill').forEach(p => p.classList.remove('active'));
                pill.classList.add('active');
                this.activeFilter = pill.getAttribute('data-filter') || 'all';
                this.renderCollectionItems();
            });
        });

        // View mode switcher: Cards or Table
        const btnViewCards = document.getElementById('btn-view-cards');
        const btnViewTable = document.getElementById('btn-view-table');
        const cardsGrid = document.getElementById('collection-cards-grid');
        const tableContainer = document.getElementById('collection-table-container');

        if (btnViewCards && btnViewTable) {
            btnViewCards.addEventListener('click', () => {
                btnViewCards.classList.add('btn-primary');
                btnViewCards.classList.remove('btn-outline');
                btnViewTable.classList.add('btn-outline');
                btnViewTable.classList.remove('btn-primary');
                if (cardsGrid) cardsGrid.style.display = 'grid';
                if (tableContainer) tableContainer.style.display = 'none';
            });

            btnViewTable.addEventListener('click', () => {
                btnViewTable.classList.add('btn-primary');
                btnViewTable.classList.remove('btn-outline');
                btnViewCards.classList.add('btn-outline');
                btnViewCards.classList.remove('btn-primary');
                if (cardsGrid) cardsGrid.style.display = 'none';
                if (tableContainer) tableContainer.style.display = 'block';
            });
        }

        // Back to folder view
        const btnBack = document.getElementById('btn-back-to-collections');
        if (btnBack) {
            btnBack.addEventListener('click', () => {
                this.loadCollectionsView(this.currentFolderId);
            });
        }

        // Export current leads view to Excel
        const btnExport = document.getElementById('btn-export-collection-excel');
        if (btnExport) {
            btnExport.addEventListener('click', () => this.exportCollectionToExcel());
        }

        // + Buat Folder Baru in GDrive Toolbar
        const btnNewFolder = document.getElementById('btn-gdrive-new-folder');
        if (btnNewFolder) {
            btnNewFolder.addEventListener('click', () => {
                this.openCreateFolderModal();
            });
        }

        // Folder Atas in GDrive Toolbar
        const btnUpLevel = document.getElementById('btn-gdrive-up-level');
        if (btnUpLevel) {
            btnUpLevel.addEventListener('click', () => {
                this.navigateUp();
            });
        }

        // Submit create folder
        const btnSubmitCreateFolder = document.getElementById('btn-submit-create-folder');
        if (btnSubmitCreateFolder) {
            btnSubmitCreateFolder.addEventListener('click', () => {
                this.submitCreateFolder();
            });
        }

        // Close modal buttons for create folder modal
        const modalCreateFolder = document.getElementById('modal-create-folder');
        if (modalCreateFolder) {
            modalCreateFolder.querySelectorAll('.btn-close-modal').forEach(b => {
                b.addEventListener('click', () => {
                    modalCreateFolder.classList.remove('active');
                });
            });
        }
    }
};

window.ArchiveManager = ArchiveManager;
