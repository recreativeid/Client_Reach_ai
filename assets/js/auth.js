/**
 * Client Reach AI - Authentication & Profile Manager
 * Handles Admin & Customer Login, Registration, OTP Verification, Profile Settings, and Session Management.
 */

const Auth = {
    token: localStorage.getItem('cliento_token') || '',
    currentUser: null,
    otpState: {
        email: '',
        countdown: 0,
        timer: null
    },

    init() {
        // Load cached user data if any
        const cachedUser = localStorage.getItem('cliento_user');
        if (cachedUser) {
            try {
                this.currentUser = JSON.parse(cachedUser);
            } catch (e) {
                this.currentUser = null;
            }
        }

        this.bindEvents();
        this.renderHeader();
        this.updateNavPermissions();

        // Verify session with backend if token exists
        if (this.token) {
            this.verifySession();
        }
    },

    getHeaders() {
        const headers = {
            'Content-Type': 'application/json'
        };
        if (this.token) {
            headers['Authorization'] = 'Bearer ' + this.token;
        }
        return headers;
    },

    async verifySession() {
        try {
            const res = await fetch('api/auth.php?action=me', {
                headers: this.getHeaders()
            });
            const data = await res.json();
            if (data.success && data.authenticated && data.user) {
                this.currentUser = data.user;
                localStorage.setItem('cliento_user', JSON.stringify(data.user));
                this.renderHeader();
                this.updateNavPermissions();
            } else {
                // Token expired or invalid
                this.clearSession();
            }
        } catch (e) {
            console.warn('Session verification skipped or offline:', e);
        }
    },

    clearSession() {
        this.token = '';
        this.currentUser = null;
        localStorage.removeItem('cliento_token');
        localStorage.removeItem('cliento_user');
        this.renderHeader();
        this.updateNavPermissions();

        // If currently on admin-only page, redirect to dashboard
        if (window.App && window.App.activePage === 'customers') {
            window.App.navigateTo('dashboard');
        }
    },

    bindEvents() {
        // Header auth button (delegated)
        document.addEventListener('click', (e) => {
            const btnOpenAuth = e.target.closest('#btn-open-auth');
            if (btnOpenAuth) {
                e.preventDefault();
                this.showAuthModal('login');
                return;
            }

            // User dropdown toggle
            const trigger = e.target.closest('#user-profile-trigger');
            if (trigger) {
                e.preventDefault();
                const menu = document.getElementById('user-profile-dropdown');
                if (menu) {
                    menu.classList.toggle('active');
                }
                return;
            }

            // Close dropdown if clicked outside
            if (!e.target.closest('#auth-header-container')) {
                const menu = document.getElementById('user-profile-dropdown');
                if (menu && menu.classList.contains('active')) {
                    menu.classList.remove('active');
                }
            }
        });

        // Tab switcher in Auth Modal
        document.querySelectorAll('.auth-tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetTab = btn.getAttribute('data-tab');
                this.switchAuthTab(targetTab);
            });
        });

        // Login Form submit
        const loginForm = document.getElementById('form-login');
        if (loginForm) {
            loginForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleLogin();
            });
        }

        // Register Form submit
        const registerForm = document.getElementById('form-register');
        if (registerForm) {
            registerForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleRegister();
            });
        }

        // OTP Form submit
        const otpForm = document.getElementById('form-otp-verify');
        if (otpForm) {
            otpForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleVerifyOtp();
            });
        }

        // Resend OTP button
        const btnResend = document.getElementById('btn-resend-otp');
        if (btnResend) {
            btnResend.addEventListener('click', (e) => {
                e.preventDefault();
                this.handleResendOtp();
            });
        }

        // Back to register from OTP
        const btnBackOtp = document.getElementById('btn-back-to-register');
        if (btnBackOtp) {
            btnBackOtp.addEventListener('click', (e) => {
                e.preventDefault();
                this.switchAuthTab('register');
            });
        }

        // Quick Demo Login Buttons
        const btnDemoAdmin = document.getElementById('btn-demo-login-admin');
        if (btnDemoAdmin) {
            btnDemoAdmin.addEventListener('click', () => {
                document.getElementById('login-email').value = 'admin@cliento.id';
                document.getElementById('login-password').value = 'admin123';
                this.handleLogin();
            });
        }

        const btnDemoCustomer = document.getElementById('btn-demo-login-customer');
        if (btnDemoCustomer) {
            btnDemoCustomer.addEventListener('click', () => {
                document.getElementById('login-email').value = 'customer@demo.com';
                document.getElementById('login-password').value = 'customer123';
                this.handleLogin();
            });
        }

        // Toggle Password visibility
        document.querySelectorAll('.btn-toggle-password').forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');
                const input = document.getElementById(targetId);
                if (input) {
                    const isPassword = input.type === 'password';
                    input.type = isPassword ? 'text' : 'password';
                    btn.innerHTML = isPassword ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
                }
            });
        });

        // Admin Profile Form
        const adminProfileForm = document.getElementById('form-admin-profile');
        if (adminProfileForm) {
            adminProfileForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleUpdateAdminProfile();
            });
        }

        // Admin Password Form
        const adminPasswordForm = document.getElementById('form-admin-password');
        if (adminPasswordForm) {
            adminPasswordForm.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleChangeAdminPassword();
            });
        }

        // Admin Settings Tabs
        document.querySelectorAll('.settings-tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const tab = btn.getAttribute('data-tab');
                document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                document.querySelectorAll('.settings-tab-pane').forEach(pane => {
                    pane.classList.toggle('active', pane.id === `tab-settings-${tab}`);
                });
            });
        });
    },

    renderHeader() {
        const container = document.getElementById('auth-header-container');
        if (!container) return;

        if (!this.currentUser) {
            container.innerHTML = `
                <button type="button" class="btn btn-outline btn-sm btn-auth-trigger" id="btn-open-auth">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                    <span>Masuk / Daftar</span>
                </button>
            `;
            return;
        }

        // Get initials from user name
        const names = (this.currentUser.name || 'User').trim().split(' ');
        let initials = names[0].charAt(0).toUpperCase();
        if (names.length > 1) {
            initials += names[names.length - 1].charAt(0).toUpperCase();
        }

        const isAdmin = this.currentUser.role === 'admin';
        const roleLabel = isAdmin ? 'Admin' : 'Customer';
        const roleBadgeClass = isAdmin ? 'badge-role-admin' : 'badge-role-customer';

        container.innerHTML = `
            <div class="user-profile-widget" id="user-profile-trigger" title="${this.currentUser.name} (${roleLabel})">
                <div class="user-avatar-badge">${initials}</div>
                <div class="user-info-text">
                    <span class="user-name">${this.escapeHtml(this.currentUser.name)}</span>
                    <span class="user-role-badge ${roleBadgeClass}">${roleLabel}</span>
                </div>
                <i class="fa-solid fa-chevron-down user-dropdown-arrow"></i>

                <!-- Dropdown Menu -->
                <div class="user-profile-dropdown" id="user-profile-dropdown">
                    <div class="dropdown-header">
                        <div class="dropdown-user-name">${this.escapeHtml(this.currentUser.name)}</div>
                        <div class="dropdown-user-email">${this.escapeHtml(this.currentUser.email)}</div>
                    </div>
                    <div class="dropdown-divider"></div>

                    ${isAdmin ? `
                    <a href="#" class="dropdown-item" onclick="if(window.App) window.App.navigateTo('customers'); return false;">
                        <i class="fa-solid fa-users"></i>
                        <span>Database Customer</span>
                    </a>
                    <a href="#" class="dropdown-item" onclick="Auth.openAdminSettingsModal(); return false;">
                        <i class="fa-solid fa-user-gear"></i>
                        <span>Pengaturan Akun & Profil</span>
                    </a>
                    ` : `
                    <a href="#" class="dropdown-item" onclick="Auth.openAdminSettingsModal(); return false;">
                        <i class="fa-solid fa-id-badge"></i>
                        <span>Profil & Ubah Sandi</span>
                    </a>
                    `}

                    <div class="dropdown-divider"></div>
                    <a href="#" class="dropdown-item text-danger" onclick="Auth.logout(); return false;">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        <span>Keluar (Logout)</span>
                    </a>
                </div>
            </div>
        `;
    },

    updateNavPermissions() {
        const isAdmin = this.currentUser && this.currentUser.role === 'admin';

        // Toggle admin nav links on desktop and mobile
        document.querySelectorAll('.nav-admin-only').forEach(el => {
            el.style.display = isAdmin ? '' : 'none';
        });

        // Refresh customer list if admin is on customers view
        if (isAdmin && window.CustomerManager && window.App && window.App.activePage === 'customers') {
            window.CustomerManager.loadCustomers();
        }
    },

    showAuthModal(tab = 'login') {
        const modal = document.getElementById('modal-auth');
        if (modal) {
            modal.classList.add('active');
            this.switchAuthTab(tab);
        }
    },

    closeAuthModal() {
        const modal = document.getElementById('modal-auth');
        if (modal) modal.classList.remove('active');
    },

    switchAuthTab(tab) {
        document.querySelectorAll('.auth-tab-btn').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-tab') === tab);
        });

        const tabLogin = document.getElementById('tab-auth-login');
        const tabRegister = document.getElementById('tab-auth-register');
        const tabOtp = document.getElementById('tab-auth-otp');

        if (tabLogin) tabLogin.style.display = tab === 'login' ? 'block' : 'none';
        if (tabRegister) tabRegister.style.display = tab === 'register' ? 'block' : 'none';
        if (tabOtp) tabOtp.style.display = tab === 'otp' ? 'block' : 'none';

        // Focus first input
        setTimeout(() => {
            if (tab === 'login') document.getElementById('login-email')?.focus();
            if (tab === 'register') document.getElementById('register-name')?.focus();
            if (tab === 'otp') document.getElementById('otp-code-input')?.focus();
        }, 100);
    },

    async handleLogin() {
        const email = document.getElementById('login-email').value.trim();
        const password = document.getElementById('login-password').value;
        const submitBtn = document.getElementById('btn-submit-login');

        if (!email || !password) {
            this.toast('Email dan password wajib diisi.', 'error');
            return;
        }

        try {
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Memproses...';
            }

            const res = await fetch('api/auth.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password })
            });
            const data = await res.json();

            if (data.success && data.token && data.user) {
                this.token = data.token;
                this.currentUser = data.user;
                localStorage.setItem('cliento_token', data.token);
                localStorage.setItem('cliento_user', JSON.stringify(data.user));

                this.renderHeader();
                this.updateNavPermissions();
                this.closeAuthModal();
                this.toast('Login berhasil! Selamat datang kembali, ' + data.user.name, 'success');

                // If admin, can navigate to customers or refresh
                if (data.user.role === 'admin' && window.CustomerManager) {
                    window.CustomerManager.loadCustomers();
                }
            } else if (data.otp_required) {
                // Account not verified, switch to OTP screen
                this.otpState.email = data.email || email;
                this.startOtpScreen(this.otpState.email, data.otp_preview);
                this.toast(data.message || 'Silakan masukkan kode OTP yang dikirimkan ke email Anda.', 'warning');
            } else {
                this.toast(data.message || 'Login gagal. Periksa email dan password Anda.', 'error');
            }
        } catch (e) {
            this.toast('Terjadi kesalahan koneksi: ' + e.message, 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> Masuk Sekarang';
            }
        }
    },

    async handleRegister() {
        const name = document.getElementById('register-name').value.trim();
        const email = document.getElementById('register-email').value.trim();
        const phone = document.getElementById('register-phone').value.trim();
        const password = document.getElementById('register-password').value;
        const submitBtn = document.getElementById('btn-submit-register');

        if (!name || !email || !password) {
            this.toast('Nama, email, dan kata sandi wajib diisi.', 'error');
            return;
        }

        if (password.length < 6) {
            this.toast('Kata sandi minimal 6 karakter.', 'error');
            return;
        }

        try {
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Mendaftarkan...';
            }

            const res = await fetch('api/auth.php?action=register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, email, phone, password })
            });
            const data = await res.json();

            if (data.success) {
                this.otpState.email = email;
                this.startOtpScreen(email, data.otp_preview);
                this.toast(data.message || 'Pendaftaran berhasil! Periksa kode OTP di email Anda.', 'success');
            } else {
                this.toast(data.message || 'Pendaftaran gagal.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan pendaftaran: ' + e.message, 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-user-plus"></i> Daftar & Dapatkan Kode OTP';
            }
        }
    },

    startOtpScreen(email, otpPreview = '') {
        this.switchAuthTab('otp');
        const emailDisplay = document.getElementById('otp-target-email');
        if (emailDisplay) emailDisplay.textContent = email;

        const previewBanner = document.getElementById('otp-preview-banner');
        const previewCode = document.getElementById('otp-preview-code');
        if (previewBanner && previewCode) {
            if (otpPreview) {
                previewCode.textContent = otpPreview;
                previewBanner.style.display = 'block';
                // Auto-fill button for fast local dev testing
                previewBanner.onclick = () => {
                    const otpInput = document.getElementById('otp-code-input');
                    if (otpInput) {
                        otpInput.value = otpPreview;
                        otpInput.focus();
                    }
                };
            } else {
                previewBanner.style.display = 'none';
            }
        }

        // Reset input
        const input = document.getElementById('otp-code-input');
        if (input) input.value = '';

        // Start 60s countdown for resend
        this.startOtpCountdown(60);
    },

    startOtpCountdown(seconds) {
        if (this.otpState.timer) clearInterval(this.otpState.timer);

        this.otpState.countdown = seconds;
        const btnResend = document.getElementById('btn-resend-otp');
        const timerSpan = document.getElementById('otp-countdown-timer');

        if (btnResend) btnResend.disabled = true;

        this.otpState.timer = setInterval(() => {
            this.otpState.countdown--;
            if (timerSpan) timerSpan.textContent = `(${this.otpState.countdown}s)`;

            if (this.otpState.countdown <= 0) {
                clearInterval(this.otpState.timer);
                if (btnResend) {
                    btnResend.disabled = false;
                    if (timerSpan) timerSpan.textContent = '';
                }
            }
        }, 1000);
    },

    async handleVerifyOtp() {
        const email = this.otpState.email;
        const otpCode = document.getElementById('otp-code-input').value.trim();
        const submitBtn = document.getElementById('btn-submit-otp');

        if (!otpCode || otpCode.length < 6) {
            this.toast('Masukkan 6 digit kode OTP verifikasi.', 'error');
            return;
        }

        try {
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Memverifikasi...';
            }

            const res = await fetch('api/auth.php?action=verify_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, otp_code: otpCode })
            });
            const data = await res.json();

            if (data.success && data.token && data.user) {
                this.token = data.token;
                this.currentUser = data.user;
                localStorage.setItem('cliento_token', data.token);
                localStorage.setItem('cliento_user', JSON.stringify(data.user));

                this.renderHeader();
                this.updateNavPermissions();
                this.closeAuthModal();
                this.toast('Verifikasi akun berhasil! Selamat datang di Cliento, ' + data.user.name, 'success');
            } else {
                this.toast(data.message || 'Kode OTP tidak cocok atau telah kadaluarsa.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan verifikasi: ' + e.message, 'error');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-shield-check"></i> Verifikasi & Masuk';
            }
        }
    },

    async handleResendOtp() {
        const email = this.otpState.email;
        if (!email) {
            this.toast('Email tidak valid.', 'error');
            return;
        }

        try {
            const res = await fetch('api/auth.php?action=resend_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Kode OTP baru berhasil dikirimkan.', 'success');
                if (data.otp_preview) {
                    const previewBanner = document.getElementById('otp-preview-banner');
                    const previewCode = document.getElementById('otp-preview-code');
                    if (previewBanner && previewCode) {
                        previewCode.textContent = data.otp_preview;
                        previewBanner.style.display = 'block';
                    }
                }
                this.startOtpCountdown(60);
            } else {
                this.toast(data.message || 'Gagal mengirim ulang OTP.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan kirim ulang: ' + e.message, 'error');
        }
    },

    async logout() {
        try {
            await fetch('api/auth.php?action=logout', {
                method: 'POST',
                headers: this.getHeaders()
            });
        } catch (e) {
            // Ignore error on logout
        }

        this.clearSession();
        this.toast('Anda telah berhasil keluar dari akun.', 'info');
    },

    openAdminSettingsModal() {
        if (!this.currentUser) {
            this.showAuthModal('login');
            return;
        }

        const modal = document.getElementById('modal-admin-settings');
        if (!modal) return;

        // Populate fields
        document.getElementById('admin-profile-name').value = this.currentUser.name || '';
        document.getElementById('admin-profile-email').value = this.currentUser.email || '';
        document.getElementById('admin-profile-phone').value = this.currentUser.phone || '';

        // Reset password fields
        document.getElementById('admin-current-password').value = '';
        document.getElementById('admin-new-password').value = '';
        document.getElementById('admin-confirm-password').value = '';

        // Switch to first tab
        document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('.settings-tab-btn[data-tab="profile"]')?.classList.add('active');
        document.querySelectorAll('.settings-tab-pane').forEach(p => p.classList.remove('active'));
        document.getElementById('tab-settings-profile')?.classList.add('active');

        modal.classList.add('active');
    },

    async handleUpdateAdminProfile() {
        const name = document.getElementById('admin-profile-name').value.trim();
        const email = document.getElementById('admin-profile-email').value.trim();
        const phone = document.getElementById('admin-profile-phone').value.trim();
        const btn = document.getElementById('btn-save-admin-profile');

        if (!name || !email) {
            this.toast('Nama dan email wajib diisi.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Menyimpan...';
            }

            const endpoint = this.currentUser.role === 'admin' 
                ? 'api/settings.php?action=update_profile'
                : 'api/auth.php?action=update_profile';

            const res = await fetch(endpoint, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify({ name, email, phone })
            });
            const data = await res.json();

            if (data.success) {
                if (data.user) {
                    this.currentUser = { ...this.currentUser, ...data.user };
                    localStorage.setItem('cliento_user', JSON.stringify(this.currentUser));
                }
                this.renderHeader();
                this.toast(data.message || 'Profil berhasil diperbarui!', 'success');
            } else {
                this.toast(data.message || 'Gagal menyimpan profil.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Informasi Profil';
            }
        }
    },

    async handleChangeAdminPassword() {
        const current_password = document.getElementById('admin-current-password').value;
        const new_password = document.getElementById('admin-new-password').value;
        const confirm_password = document.getElementById('admin-confirm-password').value;
        const btn = document.getElementById('btn-save-admin-password');

        if (!current_password) {
            this.toast('Password saat ini wajib diisi.', 'error');
            return;
        }
        if (new_password.length < 6) {
            this.toast('Password baru minimal 6 karakter.', 'error');
            return;
        }
        if (new_password !== confirm_password) {
            this.toast('Konfirmasi password baru tidak cocok.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Mengubah...';
            }

            const endpoint = this.currentUser.role === 'admin'
                ? 'api/settings.php?action=change_password'
                : 'api/auth.php?action=update_profile';

            const payload = this.currentUser.role === 'admin'
                ? { current_password, new_password, confirm_password }
                : { current_password, new_password, confirm_password, name: this.currentUser.name, email: this.currentUser.email };

            const res = await fetch(endpoint, {
                method: 'POST',
                headers: this.getHeaders(),
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Password berhasil diubah!', 'success');
                document.getElementById('admin-current-password').value = '';
                document.getElementById('admin-new-password').value = '';
                document.getElementById('admin-confirm-password').value = '';
                document.getElementById('modal-admin-settings')?.classList.remove('active');
            } else {
                this.toast(data.message || 'Gagal mengubah password.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan ubah password: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-key"></i> Simpan Password Baru';
            }
        }
    },

    toast(message, type = 'info') {
        let toastContainer = document.getElementById('app-toast-container');
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.id = 'app-toast-container';
            toastContainer.className = 'app-toast-container';
            document.body.appendChild(toastContainer);
        }

        const toast = document.createElement('div');
        toast.className = `app-toast toast-${type}`;

        let icon = 'info-circle';
        if (type === 'success') icon = 'circle-check';
        if (type === 'error') icon = 'circle-xmark';
        if (type === 'warning') icon = 'triangle-exclamation';

        toast.innerHTML = `
            <i class="fa-solid fa-${icon} toast-icon"></i>
            <span class="toast-message">${this.escapeHtml(message)}</span>
            <button type="button" class="toast-close" onclick="this.parentElement.remove()">&times;</button>
        `;

        toastContainer.appendChild(toast);

        // Auto remove after 4.5 seconds
        setTimeout(() => {
            if (toast.parentElement) {
                toast.classList.add('fade-out');
                setTimeout(() => toast.remove(), 300);
            }
        }, 4500);
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
    Auth.init();
});

window.Auth = Auth;
