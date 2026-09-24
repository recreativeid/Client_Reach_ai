/**
 * Client Reach AI - WhatsApp Message Template Manager
 * Manage message templates per category, handle dynamic tag variables,
 * live preview in WhatsApp mockup, and WhatsApp link generation.
 */

const TemplateManager = {
    categories: [],
    activeCategory: 'Cafe',
    templatesMap: {},

    async init() {
        await this.loadTemplates();
        this.renderCategorySelect();
        this.bindEvents();
    },

    async loadTemplates() {
        try {
            const res = await fetch('api/templates.php?action=list');
            const data = await res.json();
            if (data.success && data.categories) {
                this.categories = data.categories;
                data.categories.forEach(c => {
                    this.templatesMap[c.category_name] = c;
                });
            }
        } catch (e) {
            console.error('Failed to load templates:', e);
        }
    },

    renderCategorySelect() {
        const selectEl = document.getElementById('template-category-select');
        if (!selectEl) return;

        selectEl.innerHTML = '';
        this.categories.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.category_name;
            opt.textContent = c.category_name;
            if (c.category_name === this.activeCategory) opt.selected = true;
            selectEl.appendChild(opt);
        });

        this.displayActiveTemplate();
    },

    displayActiveTemplate() {
        const tpl = this.templatesMap[this.activeCategory] || {
            category_name: this.activeCategory,
            greeting_type: 'formal',
            message_body: 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? Kami dari Client Reach AI melihat potensi bisnis kakak di {alamat} sangat bagus. Boleh kami sharing solusi singkat untuk optimasi sales? Terima kasih!'
        };

        const bodyInput = document.getElementById('template-body-input');
        const greetingSelect = document.getElementById('template-greeting-select');

        if (bodyInput) bodyInput.value = tpl.message_body || '';
        if (greetingSelect) greetingSelect.value = tpl.greeting_type || 'formal';

        this.updateMockupPreview();
    },

    updateMockupPreview() {
        const bodyInput = document.getElementById('template-body-input');
        const previewEl = document.getElementById('wa-preview-text');
        if (!bodyInput || !previewEl) return;

        let rawText = bodyInput.value || '';
        
        // Mock sample replacements
        const sampleText = rawText
            .replace(/\{nama_tempat\}/g, 'Kopi Senja Utama')
            .replace(/\{kategori\}/g, this.activeCategory)
            .replace(/\{alamat\}/g, 'Jl. Pemuda No. 45, Magelang')
            .replace(/\{rating\}/g, '4.8')
            .replace(/\{telepon\}/g, '+62 812-3456-7890');

        previewEl.innerHTML = sampleText.replace(/\n/g, '<br>');
    },

    insertVariable(tag) {
        const bodyInput = document.getElementById('template-body-input');
        if (!bodyInput) return;

        const start = bodyInput.selectionStart;
        const end = bodyInput.selectionEnd;
        const text = bodyInput.value;
        const before = text.substring(0, start);
        const after = text.substring(end, text.length);

        bodyInput.value = before + tag + after;
        bodyInput.selectionStart = bodyInput.selectionEnd = start + tag.length;
        bodyInput.focus();
        this.updateMockupPreview();
    },

    async saveCurrentTemplate() {
        const bodyInput = document.getElementById('template-body-input');
        const greetingSelect = document.getElementById('template-greeting-select');
        const statusEl = document.getElementById('template-save-status');

        if (!bodyInput) return;

        const payload = {
            category_name: this.activeCategory,
            greeting_type: greetingSelect ? greetingSelect.value : 'formal',
            message_body: bodyInput.value.trim()
        };

        try {
            const res = await fetch('api/templates.php?action=save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data.success) {
                this.templatesMap[this.activeCategory] = payload;
                if (statusEl) {
                    statusEl.textContent = '✓ Template tersimpan!';
                    statusEl.style.color = '#16a34a';
                    setTimeout(() => { statusEl.textContent = ''; }, 3000);
                }
            } else {
                alert('Gagal menyimpan: ' + data.message);
            }
        } catch (e) {
            alert('Kesalahan jaringan: ' + e.message);
        }
    },

    async addNewCategory(name) {
        if (!name || !name.trim()) return;
        try {
            const res = await fetch('api/templates.php?action=create_category', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name: name.trim() })
            });
            const data = await res.json();
            if (data.success) {
                this.activeCategory = name.trim();
                await this.loadTemplates();
                this.renderCategorySelect();
                alert('Kategori baru berhasil ditambahkan!');
            } else {
                alert(data.message);
            }
        } catch (e) {
            alert('Gagal menambah kategori: ' + e.message);
        }
    },

    // Interpolate message for a specific prospect item and build WhatsApp URL
    getWhatsAppUrl(item) {
        const cat = item.category || 'Umum / Lainnya';
        const tpl = this.templatesMap[cat] || this.templatesMap['Umum / Lainnya'] || {
            message_body: 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? Kami dari Client Reach AI ingin berbagi info seputar optimasi sales. Boleh sharing singkat kak? Terima kasih!'
        };

        let msg = tpl.message_body || '';
        msg = msg
            .replace(/\{nama_tempat\}/g, item.name || 'Tempat')
            .replace(/\{kategori\}/g, item.category || 'Usaha')
            .replace(/\{alamat\}/g, item.address || 'lokasi')
            .replace(/\{rating\}/g, item.rating || '5.0')
            .replace(/\{telepon\}/g, item.phone || '');

        // Format phone number
        let phone = (item.phone || '').replace(/[^0-9]/g, '');
        if (phone.startsWith('0')) {
            phone = '62' + phone.substring(1);
        } else if (!phone.startsWith('62') && phone.length > 8) {
            phone = '62' + phone;
        }

        const encodedMsg = encodeURIComponent(msg);
        return `https://api.whatsapp.com/send?phone=${phone}&text=${encodedMsg}`;
    },

    getPersonalizedMessage(item) {
        const cat = item.category || 'Umum / Lainnya';
        const tpl = this.templatesMap[cat] || this.templatesMap['Umum / Lainnya'] || {
            message_body: 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? Kami dari Client Reach AI melihat potensi bisnis kakak di {alamat} sangat bagus (Rating {rating} ⭐). Kami ingin sharing solusi singkat untuk optimasi sales & hemat biaya iklan. Boleh izin kirimkan ringkasannya kak? Terima kasih! 🙏'
        };

        let msg = tpl.message_body || '';
        return msg
            .replace(/\{nama_tempat\}/g, item.name || 'Tempat')
            .replace(/\{kategori\}/g, item.category || 'Usaha')
            .replace(/\{alamat\}/g, item.address || 'lokasi')
            .replace(/\{rating\}/g, item.rating || '5.0')
            .replace(/\{telepon\}/g, item.phone || '');
    },

    bindEvents() {
        const selectEl = document.getElementById('template-category-select');
        if (selectEl) {
            selectEl.addEventListener('change', (e) => {
                this.activeCategory = e.target.value;
                this.displayActiveTemplate();
            });
        }

        const bodyInput = document.getElementById('template-body-input');
        if (bodyInput) {
            bodyInput.addEventListener('input', () => this.updateMockupPreview());
        }

        const greetingSelect = document.getElementById('template-greeting-select');
        if (greetingSelect) {
            greetingSelect.addEventListener('change', (e) => {
                const val = e.target.value;
                let greeting = 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? ';
                if (val === 'humas') {
                    greeting = 'Selamat siang bapak/ibu bagian humas & manajemen {nama_tempat}. ';
                } else if (val === 'casual') {
                    greeting = 'Halo kak {nama_tempat}! Salam kenal dari tim Client Reach AI. ';
                }
                if (bodyInput) {
                    bodyInput.value = greeting + bodyInput.value.replace(/^Hallo kak [^\?]+\? |^Selamat siang [^\.]+\. |^Halo kak [^\!]+\! /, '');
                    this.updateMockupPreview();
                }
            });
        }

        // Variable chip click events
        document.querySelectorAll('.var-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                const tag = chip.getAttribute('data-tag');
                if (tag) this.insertVariable(tag);
            });
        });

        // Save button
        const saveBtn = document.getElementById('btn-save-template');
        if (saveBtn) {
            saveBtn.addEventListener('click', () => this.saveCurrentTemplate());
        }

        // Add Category button
        const addCatBtn = document.getElementById('btn-add-category');
        if (addCatBtn) {
            addCatBtn.addEventListener('click', () => {
                const name = prompt('Masukkan nama kategori tempat baru (misal: Apotek, Salon, Bengkel):');
                if (name) this.addNewCategory(name);
            });
        }
    }
};

window.TemplateManager = TemplateManager;
