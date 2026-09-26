/**
 * Client Reach AI - Customer Management Controller (Admin Only)
 * Handles customer database list, live search, status filters, CRUD modals, and password reset.
 */

const CustomerManager = {
    customers: [],
    stats: {
        total: 0,
        active: 0,
        verified: 0,
        pending_otp: 0
    },
    activeFilter: {
        q: '',
        status: '',
        verified: ''
    },
    editingId: null,

    init() {
        this.bindEvents();
    },

    bindEvents() {
        // Search input with debounce
        const searchInput = document.getElementById('customer-search-input');
        if (searchInput) {
            let debounceTimer = null;
            searchInput.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    this.activeFilter.q = searchInput.value.trim();
                    this.loadCustomers();
                }, 300);
            });
        }

        // Status Filter
        const statusFilter = document.getElementById('customer-status-filter');
        if (statusFilter) {
            statusFilter.addEventListener('change', () => {
                this.activeFilter.status = statusFilter.value;
                this.loadCustomers();
            });
        }

        // Verification Filter
        const verifiedFilter = document.getElementById('customer-verified-filter');
        if (verifiedFilter) {
            verifiedFilter.addEventListener('change', () => {
                this.activeFilter.verified = verifiedFilter.value;
                this.loadCustomers();
            });
        }

        // Add Customer Button
        const btnAdd = document.getElementById('btn-add-customer');
        if (btnAdd) {
            btnAdd.addEventListener('click', () => {
                this.openCustomerModal();
            });
        }

        // Refresh List Button
        const btnRefresh = document.getElementById('btn-refresh-customers');
        if (btnRefresh) {
            btnRefresh.addEventListener('click', () => {
                this.loadCustomers();
                this.loadStats();
            });
        }

        // Customer Form Submit
        const formCustomer = document.getElementById('form-customer-modal');
        if (formCustomer) {
            formCustomer.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleSaveCustomer();
            });
        }

        // Reset Password Form Submit
        const formResetPwd = document.getElementById('form-reset-customer-password');
        if (formResetPwd) {
            formResetPwd.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleSaveResetPassword();
            });
        }
    },

    async loadStats() {
        if (!window.Auth || !window.Auth.token) return;
        try {
            const isStaticHost = window.location.hostname.includes('github.io') || window.location.protocol === 'file:';
            if (!isStaticHost) {
                const res = await fetch('api/customers.php?action=stats', {
                    headers: window.Auth.getHeaders()
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data.success && data.stats) {
                        this.stats = data.stats;
                        this.renderStats();
                        return;
                    }
                }
            }
        } catch (e) {
            console.warn('Customer stats API unavailable, using fallback:', e);
        }

        // Static host fallback
        this.stats = {
            total: 2,
            active: 2,
            verified: 2,
            pending_otp: 0
        };
        this.renderStats();
    },

    renderStats() {
        const elTotal = document.getElementById('stat-cust-total');
        const elActive = document.getElementById('stat-cust-active');
        const elVerified = document.getElementById('stat-cust-verified');
        const elPending = document.getElementById('stat-cust-pending');

        if (elTotal) elTotal.textContent = this.stats.total;
        if (elActive) elActive.textContent = this.stats.active;
        if (elVerified) elVerified.textContent = this.stats.verified;
        if (elPending) elPending.textContent = this.stats.pending_otp;
    },

    async loadCustomers() {
        if (!window.Auth || !window.Auth.token) return;

        const tableBody = document.getElementById('customers-table-body');
        if (tableBody) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center" style="padding: 36px;">
                        <i class="fa-solid fa-circle-notch fa-spin" style="font-size: 24px; color: #2563eb;"></i>
                        <div style="font-size: 13px; color: #64748b; margin-top: 8px;">Memuat basis data pelanggan...</div>
                    </td>
                </tr>
            `;
        }

        try {
            const isStaticHost = window.location.hostname.includes('github.io') || window.location.protocol === 'file:';
            if (!isStaticHost) {
                let url = 'api/customers.php?action=list';
                if (this.activeFilter.q) url += '&q=' + encodeURIComponent(this.activeFilter.q);
                if (this.activeFilter.status) url += '&status=' + encodeURIComponent(this.activeFilter.status);
                if (this.activeFilter.verified !== '') url += '&verified=' + encodeURIComponent(this.activeFilter.verified);

                const res = await fetch(url, {
                    headers: window.Auth.getHeaders()
                });
                if (res.ok) {
                    const data = await res.json();
                    if (data.success) {
                        this.customers = data.customers || [];
                        this.renderCustomersTable();
                        this.loadStats();
                        return;
                    }
                }
            }
        } catch (e) {
            console.warn('Customer list API unavailable, using fallback:', e);
        }

        // Static host fallback
        this.customers = [
            {
                id: 1,
                name: 'Siti Nurhaliza',
                email: 'customer@clientreach.ai',
                phone: '081234567890',
                business_name: 'CV Berkah Mandiri',
                is_active: 1,
                is_email_verified: 1,
                created_at: '2026-09-01 10:00:00'
            },
            {
                id: 2,
                name: 'Budi Santoso',
                email: 'budi@recreative.id',
                phone: '085712345678',
                business_name: 'Digital Reach Agency',
                is_active: 1,
                is_email_verified: 1,
                created_at: '2026-09-15 14:30:00'
            }
        ];
        this.renderCustomersTable();
        this.loadStats();
    },

    renderCustomersTable() {
        const tableBody = document.getElementById('customers-table-body');
        const emptyState = document.getElementById('customers-empty-state');
        const countBadge = document.getElementById('customers-count-badge');

        if (countBadge) countBadge.textContent = `${this.customers.length} Pengguna`;

        if (!this.customers.length) {
            if (tableBody) tableBody.innerHTML = '';
            if (emptyState) emptyState.style.display = 'block';
            return;
        }

        if (emptyState) emptyState.style.display = 'none';
        if (!tableBody) return;

        let html = '';
        this.customers.forEach((c) => {
            const initials = c.name ? c.name.trim().charAt(0).toUpperCase() : 'C';
            const isActive = c.status === 'active';
            const isVerified = parseInt(c.is_verified) === 1;

            const dateStr = c.created_at ? new Date(c.created_at).toLocaleDateString('id-ID', {
                day: 'numeric',
                month: 'short',
                year: 'numeric'
            }) : '-';

            const phoneClean = c.phone ? c.phone.replace(/[^0-9]/g, '') : '';
            const waLink = phoneClean ? `https://wa.me/${phoneClean.startsWith('0') ? '62' + phoneClean.substring(1) : phoneClean}` : '#';

            html += `
                <tr>
                    <td>
                        <div class="customer-cell-user">
                            <div class="user-avatar-badge">${initials}</div>
                            <div>
                                <div class="customer-name">${this.escapeHtml(c.name)}</div>
                                <div class="customer-email">${this.escapeHtml(c.email)}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        ${c.phone ? `
                        <a href="${waLink}" target="_blank" class="customer-phone-link" title="Buka WhatsApp">
                            <i class="fa-brands fa-whatsapp text-success"></i> ${this.escapeHtml(c.phone)}
                        </a>
                        ` : '<span style="color:#94a3b8; font-size:12px;">-</span>'}
                    </td>
                    <td>
                        <span class="status-badge ${isActive ? 'status-active' : 'status-suspended'}">
                            <span class="status-indicator"></span>
                            ${isActive ? 'Aktif' : 'Dinonaktifkan'}
                        </span>
                    </td>
                    <td>
                        <span class="verified-badge ${isVerified ? 'verified-yes' : 'verified-pending'}">
                            <i class="fa-solid ${isVerified ? 'fa-circle-check' : 'fa-clock'}"></i>
                            ${isVerified ? 'Terverifikasi' : 'Pending OTP'}
                        </span>
                    </td>
                    <td>
                        <div class="customer-metrics">
                            <span title="Total Koleksi Arsip"><i class="fa-solid fa-folder-closed text-primary"></i> ${c.total_archives || 0}</span>
                            <span title="Total Scraping"><i class="fa-solid fa-magnifying-glass-chart text-warning"></i> ${c.total_scraping || 0}</span>
                        </div>
                    </td>
                    <td class="customer-date-col">${dateStr}</td>
                    <td class="text-right">
                        <div class="customer-actions-group">
                            <button type="button" class="btn-action-icon btn-edit" title="Edit Data" onclick="CustomerManager.openCustomerModal(${c.id})">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <button type="button" class="btn-action-icon btn-password" title="Reset Password" onclick="CustomerManager.openResetPasswordModal(${c.id}, '${this.escapeHtml(c.name)}')">
                                <i class="fa-solid fa-key"></i>
                            </button>
                            <button type="button" class="btn-action-icon btn-toggle-status" title="${isActive ? 'Nonaktifkan Akun' : 'Aktifkan Akun'}" onclick="CustomerManager.toggleCustomerStatus(${c.id}, '${c.status}')">
                                <i class="fa-solid ${isActive ? 'fa-toggle-on text-success' : 'fa-toggle-off text-muted'}"></i>
                            </button>
                            <button type="button" class="btn-action-icon btn-delete" title="Hapus Customer" onclick="CustomerManager.confirmDeleteCustomer(${c.id}, '${this.escapeHtml(c.name)}')">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });

        tableBody.innerHTML = html;
    },

    openCustomerModal(id = null) {
        this.editingId = id;
        const modal = document.getElementById('modal-customer-form');
        const titleEl = document.getElementById('customer-modal-title');
        const pwdContainer = document.getElementById('customer-password-field-container');
        const pwdLabel = document.getElementById('customer-modal-pwd-label');

        if (!modal) return;

        // Reset inputs
        document.getElementById('cust-form-name').value = '';
        document.getElementById('cust-form-email').value = '';
        document.getElementById('cust-form-phone').value = '';
        document.getElementById('cust-form-password').value = '';
        document.getElementById('cust-form-status').value = 'active';
        document.getElementById('cust-form-verified').checked = true;

        if (id) {
            // Edit mode
            titleEl.textContent = 'Edit Data Customer';
            pwdLabel.textContent = 'Ganti Password (Kosongkan jika tidak diubah)';
            document.getElementById('cust-form-password').required = false;

            const customer = this.customers.find(c => parseInt(c.id) === parseInt(id));
            if (customer) {
                document.getElementById('cust-form-name').value = customer.name || '';
                document.getElementById('cust-form-email').value = customer.email || '';
                document.getElementById('cust-form-phone').value = customer.phone || '';
                document.getElementById('cust-form-status').value = customer.status || 'active';
                document.getElementById('cust-form-verified').checked = parseInt(customer.is_verified) === 1;
            }
        } else {
            // Create mode
            titleEl.textContent = 'Tambah Customer Baru';
            pwdLabel.textContent = 'Kata Sandi Akun *';
            document.getElementById('cust-form-password').required = true;
        }

        modal.classList.add('active');
    },

    async handleSaveCustomer() {
        const name = document.getElementById('cust-form-name').value.trim();
        const email = document.getElementById('cust-form-email').value.trim();
        const phone = document.getElementById('cust-form-phone').value.trim();
        const password = document.getElementById('cust-form-password').value;
        const status = document.getElementById('cust-form-status').value;
        const is_verified = document.getElementById('cust-form-verified').checked ? 1 : 0;
        const btnSave = document.getElementById('btn-save-customer-form');

        if (!name || !email) {
            window.Auth?.toast('Nama dan email wajib diisi.', 'error');
            return;
        }

        if (!this.editingId && (!password || password.length < 6)) {
            window.Auth?.toast('Kata sandi awal minimal 6 karakter.', 'error');
            return;
        }

        try {
            if (btnSave) {
                btnSave.disabled = true;
                btnSave.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Menyimpan...';
            }

            const action = this.editingId ? 'update' : 'create';
            const payload = {
                name,
                email,
                phone,
                status,
                is_verified
            };

            if (this.editingId) {
                payload.id = this.editingId;
                if (password) payload.password = password;
            } else {
                payload.password = password;
            }

            const res = await fetch(`api/customers.php?action=${action}`, {
                method: 'POST',
                headers: window.Auth.getHeaders(),
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                window.Auth?.toast(data.message || 'Data customer berhasil disimpan!', 'success');
                document.getElementById('modal-customer-form')?.classList.remove('active');
                this.loadCustomers();
            } else {
                window.Auth?.toast(data.message || 'Gagal menyimpan customer.', 'error');
            }
        } catch (e) {
            window.Auth?.toast('Kesalahan: ' + e.message, 'error');
        } finally {
            if (btnSave) {
                btnSave.disabled = false;
                btnSave.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Customer';
            }
        }
    },

    openResetPasswordModal(id, customerName) {
        this.editingId = id;
        const modal = document.getElementById('modal-reset-password');
        const targetName = document.getElementById('reset-pwd-customer-name');

        if (!modal) return;
        if (targetName) targetName.textContent = customerName;

        document.getElementById('reset-new-password').value = '';
        document.getElementById('reset-confirm-password').value = '';

        modal.classList.add('active');
    },

    async handleSaveResetPassword() {
        const newPassword = document.getElementById('reset-new-password').value;
        const confirmPassword = document.getElementById('reset-confirm-password').value;
        const btnSave = document.getElementById('btn-submit-reset-pwd');

        if (!newPassword || newPassword.length < 6) {
            window.Auth?.toast('Password baru minimal 6 karakter.', 'error');
            return;
        }

        if (newPassword !== confirmPassword) {
            window.Auth?.toast('Konfirmasi password tidak cocok.', 'error');
            return;
        }

        try {
            if (btnSave) {
                btnSave.disabled = true;
                btnSave.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Mereset...';
            }

            const customer = this.customers.find(c => parseInt(c.id) === parseInt(this.editingId));
            if (!customer) {
                window.Auth?.toast('Customer tidak ditemukan.', 'error');
                return;
            }

            const res = await fetch('api/customers.php?action=update', {
                method: 'POST',
                headers: window.Auth.getHeaders(),
                body: JSON.stringify({
                    id: this.editingId,
                    name: customer.name,
                    email: customer.email,
                    phone: customer.phone,
                    status: customer.status,
                    is_verified: customer.is_verified,
                    password: newPassword
                })
            });
            const data = await res.json();

            if (data.success) {
                window.Auth?.toast('Password customer berhasil direset!', 'success');
                document.getElementById('modal-reset-password')?.classList.remove('active');
            } else {
                window.Auth?.toast(data.message || 'Gagal mereset password.', 'error');
            }
        } catch (e) {
            window.Auth?.toast('Kesalahan: ' + e.message, 'error');
        } finally {
            if (btnSave) {
                btnSave.disabled = false;
                btnSave.innerHTML = '<i class="fa-solid fa-key"></i> Simpan Password Baru';
            }
        }
    },

    async toggleCustomerStatus(id, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'suspended' : 'active';
        const customer = this.customers.find(c => parseInt(c.id) === parseInt(id));
        if (!customer) return;

        try {
            const res = await fetch('api/customers.php?action=update', {
                method: 'POST',
                headers: window.Auth.getHeaders(),
                body: JSON.stringify({
                    id: id,
                    name: customer.name,
                    email: customer.email,
                    phone: customer.phone,
                    status: newStatus,
                    is_verified: customer.is_verified
                })
            });
            const data = await res.json();

            if (data.success) {
                customer.status = newStatus;
                this.renderCustomersTable();
                this.loadStats();
                window.Auth?.toast(`Akun ${customer.name} berhasil di-${newStatus === 'active' ? 'aktifkan' : 'nonaktifkan'}.`, 'success');
            } else {
                window.Auth?.toast(data.message || 'Gagal mengubah status.', 'error');
            }
        } catch (e) {
            window.Auth?.toast('Kesalahan status: ' + e.message, 'error');
        }
    },

    async confirmDeleteCustomer(id, name) {
        if (!confirm(`Apakah Anda yakin ingin menghapus akun customer "${name}" secara permanen dari database?`)) {
            return;
        }

        try {
            const res = await fetch('api/customers.php?action=delete', {
                method: 'POST',
                headers: window.Auth.getHeaders(),
                body: JSON.stringify({ id })
            });
            const data = await res.json();

            if (data.success) {
                window.Auth?.toast(data.message || 'Customer berhasil dihapus.', 'success');
                this.loadCustomers();
            } else {
                window.Auth?.toast(data.message || 'Gagal menghapus customer.', 'error');
            }
        } catch (e) {
            window.Auth?.toast('Kesalahan hapus: ' + e.message, 'error');
        }
    },

    escapeHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
};

document.addEventListener('DOMContentLoaded', () => {
    CustomerManager.init();
});

window.CustomerManager = CustomerManager;
