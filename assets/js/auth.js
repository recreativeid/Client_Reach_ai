/**
 * Client Reach AI - Authentication & Profile Manager
 * Handles:
 * 1. Strict Auth Gatekeeper (Screen displayed before entering dashboard)
 * 2. User & Admin Login, Registration, & Gmail OTP Verification
 * 3. Lupa Akun / Lupa Kata Sandi via Gmail OTP
 * 4. User Profile & Manual Email / Password Change with Mandatory OTP Verification
 * 5. Free Email Delivery System (Gmail SMTP Socket, Brevo API, Resend API)
 * 6. User Data Isolation & Session Management
 */

const Auth = {
    token: localStorage.getItem('cliento_token') || '',
    currentUser: null,
    currentPortal: 'user', // 'user' (customer) or 'admin'
    regOtpState: {
        email: '',
        countdown: 0,
        timer: null
    },
    forgotOtpState: {
        email: '',
        countdown: 0,
        timer: null
    },
    emailChangeState: {
        newEmail: '',
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

        // Initialize Global App Fetch Interceptor
        this.initGlobalAppFetch();

        this.bindEvents();
        this.syncAuthGate();
        this.renderHeader();
        this.updateNavPermissions();

        // Verify session with backend if token exists
        if (this.token) {
            this.verifySession();
        }
    },

    /**
     * Interceptor to automatically add Bearer token to all API requests
     */
    initGlobalAppFetch() {
        const nativeFetch = window.fetch;
        const self = this;

        window.fetch = async function(input, init = {}) {
            let url = '';
            if (typeof input === 'string') {
                url = input;
            } else if (input && input.url) {
                url = input.url;
            }

            if (url.includes('api/') && self.token) {
                init = init || {};
                init.headers = init.headers || {};
                if (init.headers instanceof Headers) {
                    if (!init.headers.has('Authorization')) {
                        init.headers.set('Authorization', 'Bearer ' + self.token);
                    }
                } else if (Array.isArray(init.headers)) {
                    init.headers.push(['Authorization', 'Bearer ' + self.token]);
                } else {
                    if (!init.headers['Authorization']) {
                        init.headers['Authorization'] = 'Bearer ' + self.token;
                    }
                }
            }

            try {
                const res = await nativeFetch.call(window, input, init);
                if (res.status === 401 && url.includes('api/') && !url.includes('action=login') && !url.includes('action=register') && !url.includes('action=forgot_password')) {
                    self.clearSession();
                }
                return res;
            } catch (err) {
                throw err;
            }
        };

        window.appFetch = window.fetch;
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

    /**
     * Synchronize Auth Gate visibility:
     * - If NOT logged in: hide dashboard & app header, show Auth Gate
     * - If logged in: show dashboard & app header, hide Auth Gate
     */
    syncAuthGate() {
        const gate = document.getElementById('auth-gate-container');
        const isAuthenticated = !!(this.currentUser && this.token);

        if (isAuthenticated) {
            document.body.classList.add('authenticated');
            if (gate) gate.style.display = 'none';
        } else {
            document.body.classList.remove('authenticated');
            if (gate) gate.style.display = 'flex';
            this.setPortal(this.currentPortal || 'user');
        }
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
                this.syncAuthGate();
                this.renderHeader();
                this.updateNavPermissions();

                // Refresh isolated data
                if (window.App) window.App.refreshDashboardStats();
                if (window.ArchiveManager) window.ArchiveManager.loadCollectionsView();
            } else {
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
        this.syncAuthGate();
        this.renderHeader();
        this.updateNavPermissions();

        // Close all modals
        document.querySelectorAll('.modal-backdrop').forEach(m => m.classList.remove('active'));

        // Reset page to dashboard
        if (window.App) {
            window.App.activePage = 'dashboard';
            window.location.hash = 'dashboard';
        }
    },

    bindEvents() {
        // ----------------------------------------------------
        // 1. AUTH GATE CONTROLS (PORTAL ADMIN & USER TERPISAH)
        // ----------------------------------------------------

        // Top-Level Portal Switcher (User vs Admin)
        document.querySelectorAll('.auth-portal-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const portal = btn.getAttribute('data-portal');
                this.setPortal(portal);
            });
        });

        // Tombol Buat Akun Baru (User Only) di Bawah Form Login
        const btnToRegister = document.getElementById('gate-btn-to-register');
        if (btnToRegister) {
            btnToRegister.addEventListener('click', (e) => {
                e.preventDefault();
                this.showGatePane('register');
            });
        }

        const btnBackFromRegister = document.getElementById('gate-btn-back-from-register');
        if (btnBackFromRegister) {
            btnBackFromRegister.addEventListener('click', (e) => {
                e.preventDefault();
                this.showGatePane('user-login');
            });
        }

        // Gate Links: Lupa Kata Sandi (User Only - di Bawah Kolom Kata Sandi)
        const linkToForgotPwd = document.getElementById('gate-link-to-forgot-pwd');
        if (linkToForgotPwd) {
            linkToForgotPwd.addEventListener('click', (e) => {
                e.preventDefault();
                this.showGatePane('forgot');
            });
        }

        // Gate Links: Lupa Username / Email (User Only - di Bawah Kolom Kata Sandi)
        const linkToForgotEmail = document.getElementById('gate-link-to-forgot-email');
        if (linkToForgotEmail) {
            linkToForgotEmail.addEventListener('click', (e) => {
                e.preventDefault();
                this.showGatePane('forgot-email');
            });
        }

        const linkFromForgotToEmail = document.getElementById('gate-link-from-forgot-to-email');
        if (linkFromForgotToEmail) {
            linkFromForgotToEmail.addEventListener('click', (e) => {
                e.preventDefault();
                this.showGatePane('forgot-email');
            });
        }

        const linkToLogin = document.getElementById('gate-link-to-login');
        if (linkToLogin) {
            linkToLogin.addEventListener('click', (e) => {
                e.preventDefault();
                this.showGatePane('user-login');
            });
        }

        const btnBackFromOtp = document.getElementById('gate-btn-back-from-otp');
        if (btnBackFromOtp) {
            btnBackFromOtp.addEventListener('click', (e) => {
                e.preventDefault();
                this.showGatePane('register');
            });
        }

        const btnBackFromForgot = document.getElementById('gate-btn-back-from-forgot');
        if (btnBackFromForgot) {
            btnBackFromForgot.addEventListener('click', (e) => {
                e.preventDefault();
                this.showGatePane('user-login');
            });
        }

        const btnBackFromForgotEmail = document.getElementById('gate-btn-back-from-forgot-email');
        if (btnBackFromForgotEmail) {
            btnBackFromForgotEmail.addEventListener('click', (e) => {
                e.preventDefault();
                this.showGatePane('user-login');
            });
        }

        // Gate Form: User Login
        const gateFormUserLogin = document.getElementById('gate-form-user-login');
        if (gateFormUserLogin) {
            gateFormUserLogin.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleGateUserLogin();
            });
        }

        // Gate Form: Admin Login
        const gateFormAdminLogin = document.getElementById('gate-form-admin-login');
        if (gateFormAdminLogin) {
            gateFormAdminLogin.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleGateAdminLogin();
            });
        }

        // Gate Form: Register (User Only)
        const gateFormRegister = document.getElementById('gate-form-register');
        if (gateFormRegister) {
            gateFormRegister.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleGateRegister();
            });
        }

        // Gate Form: Forgot Email (User Only)
        const gateFormForgotEmail = document.getElementById('gate-form-forgot-email');
        if (gateFormForgotEmail) {
            gateFormForgotEmail.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleGateForgotEmail();
            });
        }

        // OTP Verification Form
        const gateFormOtp = document.getElementById('gate-form-otp');
        if (gateFormOtp) {
            gateFormOtp.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleGateVerifyOtp();
            });
        }

        const gateBtnResendOtp = document.getElementById('gate-btn-resend-otp');
        if (gateBtnResendOtp) {
            gateBtnResendOtp.addEventListener('click', (e) => {
                e.preventDefault();
                this.handleGateResendOtp();
            });
        }

        // Forgot Password Forms (User Only)
        const gateFormForgotReq = document.getElementById('gate-form-forgot-request');
        if (gateFormForgotReq) {
            gateFormForgotReq.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleGateForgotRequest();
            });
        }

        const gateFormForgotReset = document.getElementById('gate-form-forgot-reset');
        if (gateFormForgotReset) {
            gateFormForgotReset.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleGateForgotReset();
            });
        }

        const gateBtnResendForgot = document.getElementById('gate-btn-resend-forgot-otp');
        if (gateBtnResendForgot) {
            gateBtnResendForgot.addEventListener('click', (e) => {
                e.preventDefault();
                this.handleGateForgotRequest();
            });
        }



        // ----------------------------------------------------
        // 2. HEADER & DROPDOWN EVENTS
        // ----------------------------------------------------
        document.addEventListener('click', (e) => {
            // User dropdown toggle
            const trigger = e.target.closest('#user-profile-trigger');
            if (trigger) {
                e.preventDefault();
                const menu = document.getElementById('user-profile-dropdown');
                if (menu) menu.classList.toggle('active');
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

        // ----------------------------------------------------
        // 3. SETTINGS & PROFILE MODAL EVENTS
        // ----------------------------------------------------
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

        // Profile Form Submit
        const formAdminProfile = document.getElementById('form-admin-profile');
        if (formAdminProfile) {
            formAdminProfile.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleUpdateProfile();
            });
        }

        // Email Change Request Form
        const formEmailChangeReq = document.getElementById('form-change-email-request');
        if (formEmailChangeReq) {
            formEmailChangeReq.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleRequestEmailChangeOtp();
            });
        }

        // Email Change Verify Form
        const formEmailChangeVerify = document.getElementById('form-change-email-verify');
        if (formEmailChangeVerify) {
            formEmailChangeVerify.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleVerifyEmailChangeOtp();
            });
        }

        const btnCancelEmailChange = document.getElementById('btn-cancel-email-change');
        if (btnCancelEmailChange) {
            btnCancelEmailChange.addEventListener('click', () => {
                document.getElementById('email-change-otp-container').style.display = 'none';
                document.getElementById('change-new-email').value = '';
            });
        }

        // Password Form Submit
        const formAdminPassword = document.getElementById('form-admin-password');
        if (formAdminPassword) {
            formAdminPassword.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleChangePassword();
            });
        }

        // Mail Provider Select Toggle
        const mailProviderSelect = document.getElementById('mail-provider-select');
        if (mailProviderSelect) {
            mailProviderSelect.addEventListener('change', () => {
                const val = mailProviderSelect.value;
                document.getElementById('group-gmail-smtp').style.display = val === 'gmail_smtp' ? 'block' : 'none';
                document.getElementById('group-brevo').style.display = val === 'brevo' ? 'block' : 'none';
                document.getElementById('group-resend').style.display = val === 'resend' ? 'block' : 'none';
            });
        }

        // Mail Settings Form Submit
        const formMailSettings = document.getElementById('form-mail-settings');
        if (formMailSettings) {
            formMailSettings.addEventListener('submit', (e) => {
                e.preventDefault();
                this.handleSaveMailSettings();
            });
        }

        // Test Send Email Trigger
        const btnTriggerTest = document.getElementById('btn-trigger-test-email');
        if (btnTriggerTest) {
            btnTriggerTest.addEventListener('click', () => {
                this.handleTriggerTestEmail();
            });
        }
    },

    renderHeader() {
        const container = document.getElementById('auth-header-container');
        if (!container) return;

        if (!this.currentUser) {
            container.innerHTML = `
                <button type="button" class="btn btn-outline btn-sm btn-auth-trigger" onclick="Auth.syncAuthGate()">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                    <span>Masuk</span>
                </button>
            `;
            return;
        }

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
                        <i class="fa-solid fa-users text-primary"></i>
                        <span>Kelola Pengguna / Customer</span>
                    </a>
                    <a href="#" class="dropdown-item" onclick="Auth.openAdminSettingsModal(); return false;">
                        <i class="fa-solid fa-user-gear"></i>
                        <span>Pengaturan Akun & Email API</span>
                    </a>
                    ` : `
                    <a href="#" class="dropdown-item" onclick="Auth.openAdminSettingsModal(); return false;">
                        <i class="fa-solid fa-id-badge"></i>
                        <span>Profil & Ubah Sandi/Email</span>
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

    // ----------------------------------------------------
    // AUTH GATE CONTROLLER
    // ----------------------------------------------------

    setPortal(portal) {
        this.currentPortal = portal;
        document.querySelectorAll('.auth-portal-btn').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-portal') === portal);
        });

        const badge = document.getElementById('gate-portal-badge');

        if (portal === 'admin') {
            if (badge) {
                badge.className = 'auth-gate-badge badge-portal-admin';
                badge.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Akses Khusus Administrator';
            }
            this.showGatePane('admin-login');
        } else {
            if (badge) {
                badge.className = 'auth-gate-badge badge-portal-user';
                badge.innerHTML = '<i class="fa-solid fa-user-check"></i> Akses Pengguna / Customer';
            }
            this.showGatePane('user-login');
        }
    },

    showGatePane(pane) {
        // Sembunyikan semua pane
        document.querySelectorAll('.gate-pane').forEach(p => p.style.display = 'none');

        const activePane = document.getElementById(`gate-pane-${pane}`);
        if (activePane) {
            activePane.style.display = 'block';
        }

        // Fokus ke input pertama
        setTimeout(() => {
            if (pane === 'user-login') (document.getElementById('gate-user-login-account') || document.getElementById('gate-user-login-email'))?.focus();
            if (pane === 'admin-login') (document.getElementById('gate-admin-login-account') || document.getElementById('gate-admin-login-email'))?.focus();
            if (pane === 'register') document.getElementById('gate-reg-name')?.focus();
            if (pane === 'otp') document.getElementById('gate-otp-code-input')?.focus();
            if (pane === 'forgot') document.getElementById('gate-forgot-email')?.focus();
            if (pane === 'forgot-email') document.getElementById('gate-forgot-email-query')?.focus();
        }, 80);
    },

    async handleGateUserLogin() {
        const accountInput = document.getElementById('gate-user-login-account') || document.getElementById('gate-user-login-email');
        const account = accountInput ? accountInput.value.trim() : '';
        const password = document.getElementById('gate-user-login-password').value;
        const btn = document.getElementById('gate-btn-submit-user-login');

        if (!account || !password) {
            this.toast('Username atau email dan kata sandi User wajib diisi.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Memverifikasi...';
            }

            const res = await fetch('api/auth.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ account, password, portal: 'user' })
            });
            const data = await res.json();

            if (data.success && data.token && data.user) {
                this.token = data.token;
                this.currentUser = data.user;
                localStorage.setItem('cliento_token', data.token);
                localStorage.setItem('cliento_user', JSON.stringify(data.user));

                this.syncAuthGate();
                this.renderHeader();
                this.updateNavPermissions();
                this.toast('Masuk berhasil! Selamat datang, ' + data.user.name, 'success');

                // Refresh user-isolated data
                if (window.App) {
                    window.App.refreshDashboardStats();
                    window.App.navigateTo('dashboard');
                }
                if (window.ArchiveManager) window.ArchiveManager.loadCollectionsView();
            } else if (data.otp_required) {
                this.regOtpState.email = data.email || account;
                this.startGateOtpScreen(this.regOtpState.email, data.otp_preview);
                this.toast(data.message || 'Silakan masukkan kode OTP yang dikirim ke Gmail.', 'warning');
            } else {
                this.toast(data.message || 'Login gagal. Periksa username/email dan password Anda.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan koneksi: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-arrow-right-to-bracket"></i> Masuk sebagai User';
            }
        }
    },

    async handleGateAdminLogin() {
        const accountInput = document.getElementById('gate-admin-login-account') || document.getElementById('gate-admin-login-email');
        const account = accountInput ? accountInput.value.trim() : '';
        const password = document.getElementById('gate-admin-login-password').value;
        const btn = document.getElementById('gate-btn-submit-admin-login');

        if (!account || !password) {
            this.toast('Username atau email Administrator dan kata sandi wajib diisi.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Memverifikasi Admin...';
            }

            const res = await fetch('api/auth.php?action=login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ account, password, portal: 'admin' })
            });
            const data = await res.json();

            if (data.success && data.token && data.user) {
                this.token = data.token;
                this.currentUser = data.user;
                localStorage.setItem('cliento_token', data.token);
                localStorage.setItem('cliento_user', JSON.stringify(data.user));

                this.syncAuthGate();
                this.renderHeader();
                this.updateNavPermissions();
                this.toast('Login Administrator berhasil! Selamat datang, ' + data.user.name, 'success');

                // Refresh isolated data
                if (window.App) {
                    window.App.refreshDashboardStats();
                    window.App.navigateTo('dashboard');
                }
                if (window.CustomerManager) {
                    window.CustomerManager.loadCustomers();
                }
            } else {
                this.toast(data.message || 'Login administrator gagal. Periksa username/email dan kata sandi admin.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan koneksi: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Masuk sebagai Administrator';
            }
        }
    },

    async handleGateRegister() {
        const name = document.getElementById('gate-reg-name').value.trim();
        const username = (document.getElementById('gate-reg-username')?.value || '').trim();
        const email = document.getElementById('gate-reg-email').value.trim();
        const phone = document.getElementById('gate-reg-phone').value.trim();
        const password = document.getElementById('gate-reg-password').value;
        const btn = document.getElementById('gate-btn-submit-register');

        if (!name || !username || !email || !password) {
            this.toast('Nama, username, email aktif, dan kata sandi wajib diisi.', 'error');
            return;
        }

        if (!/^[a-zA-Z0-9_]{3,30}$/.test(username)) {
            this.toast('Username hanya boleh huruf, angka, atau garis bawah (_) 3-30 karakter.', 'error');
            return;
        }

        if (password.length < 6) {
            this.toast('Kata sandi minimal 6 karakter.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Mengirim OTP ke Email...';
            }

            const res = await fetch('api/auth.php?action=register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ name, username, email, phone, password })
            });
            const data = await res.json();

            if (data.success) {
                this.regOtpState.email = email;
                this.startGateOtpScreen(email, data.otp_preview);
                this.toast(data.message || 'Pendaftaran berhasil! Periksa kode OTP di email Anda.', 'success');
            } else {
                this.toast(data.message || 'Pendaftaran gagal.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan pendaftaran: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Daftar Akun User & Dapatkan OTP';
            }
        }
    },

    startGateOtpScreen(email, otpPreview = '') {
        this.showGatePane('otp');
        const emailTarget = document.getElementById('gate-otp-target-email');
        if (emailTarget) emailTarget.textContent = email;

        const banner = document.getElementById('gate-otp-preview-banner');
        const codeSpan = document.getElementById('gate-otp-preview-code');
        if (banner && codeSpan) {
            if (otpPreview) {
                codeSpan.textContent = otpPreview;
                banner.style.display = 'block';
                banner.onclick = () => {
                    const inp = document.getElementById('gate-otp-code-input');
                    if (inp) {
                        inp.value = otpPreview;
                        inp.focus();
                    }
                };
            } else {
                banner.style.display = 'none';
            }
        }

        const inp = document.getElementById('gate-otp-code-input');
        if (inp) inp.value = '';

        this.startGateCountdown('reg', 60);
    },

    startGateCountdown(type, seconds) {
        const state = type === 'reg' ? this.regOtpState : this.forgotOtpState;
        if (state.timer) clearInterval(state.timer);

        state.countdown = seconds;
        const btnResend = type === 'reg' 
            ? document.getElementById('gate-btn-resend-otp')
            : document.getElementById('gate-btn-resend-forgot-otp');
        const timerSpan = type === 'reg'
            ? document.getElementById('gate-otp-countdown-timer')
            : null;

        if (btnResend) btnResend.disabled = true;

        state.timer = setInterval(() => {
            state.countdown--;
            if (timerSpan) timerSpan.textContent = `(${state.countdown}s)`;

            if (state.countdown <= 0) {
                clearInterval(state.timer);
                if (btnResend) {
                    btnResend.disabled = false;
                    if (timerSpan) timerSpan.textContent = '';
                }
            }
        }, 1000);
    },

    async handleGateVerifyOtp() {
        const email = this.regOtpState.email;
        const otp_code = document.getElementById('gate-otp-code-input').value.trim();
        const btn = document.getElementById('gate-btn-submit-otp');

        if (!otp_code || otp_code.length < 6) {
            this.toast('Masukkan 6 digit kode OTP verifikasi.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Memverifikasi...';
            }

            const res = await fetch('api/auth.php?action=verify_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, otp_code })
            });
            const data = await res.json();

            if (data.success && data.token && data.user) {
                this.token = data.token;
                this.currentUser = data.user;
                localStorage.setItem('cliento_token', data.token);
                localStorage.setItem('cliento_user', JSON.stringify(data.user));

                this.syncAuthGate();
                this.renderHeader();
                this.updateNavPermissions();
                this.toast('Verifikasi akun berhasil! Selamat datang, ' + data.user.name, 'success');

                if (window.App) {
                    window.App.refreshDashboardStats();
                    window.App.navigateTo('dashboard');
                }
                if (window.ArchiveManager) window.ArchiveManager.loadCollectionsView();
            } else {
                this.toast(data.message || 'Kode OTP tidak cocok atau kadaluarsa.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan verifikasi: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-shield-check"></i> Verifikasi & Masuk ke Dashboard';
            }
        }
    },

    async handleGateResendOtp() {
        const email = this.regOtpState.email;
        if (!email) return;

        try {
            const res = await fetch('api/auth.php?action=resend_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Kode OTP baru berhasil dikirim ke email.', 'success');
                if (data.otp_preview) {
                    const banner = document.getElementById('gate-otp-preview-banner');
                    const code = document.getElementById('gate-otp-preview-code');
                    if (banner && code) {
                        code.textContent = data.otp_preview;
                        banner.style.display = 'block';
                    }
                }
                this.startGateCountdown('reg', 60);
            } else {
                this.toast(data.message || 'Gagal mengirim ulang OTP.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan kirim ulang: ' + e.message, 'error');
        }
    },

    // ----------------------------------------------------
    // FORGOT PASSWORD / PEMULIHAN AKUN VIA OTP
    // ----------------------------------------------------

    async handleGateForgotRequest() {
        const account = document.getElementById('gate-forgot-email').value.trim();
        const btn = document.getElementById('gate-btn-send-forgot-otp');

        if (!account) {
            this.toast('Masukkan username atau alamat email akun User Anda.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Mengirim OTP Pemulihan...';
            }

            const res = await fetch('api/auth.php?action=forgot_password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ account })
            });
            const data = await res.json();

            if (data.success) {
                this.forgotOtpState.email = data.email || account;
                this.forgotOtpState.account = account;
                this.toast(data.message || 'Kode OTP telah dikirimkan ke email terdaftar!', 'success');

                // Switch to Step 2
                document.getElementById('gate-form-forgot-request').style.display = 'none';
                const resetForm = document.getElementById('gate-form-forgot-reset');
                resetForm.style.display = 'block';

                const targetDisplay = data.masked_email || data.email || account;
                document.getElementById('gate-forgot-desc').innerHTML = 
                    `Kode OTP telah dikirimkan ke email terdaftar: <strong style="color:#2563eb;">${targetDisplay}</strong>. Masukkan kode OTP dan buat kata sandi baru Anda:`;

                if (data.otp_preview) {
                    const banner = document.getElementById('gate-forgot-preview-banner');
                    const code = document.getElementById('gate-forgot-preview-code');
                    if (banner && code) {
                        code.textContent = data.otp_preview;
                        banner.style.display = 'block';
                        banner.onclick = () => {
                            const inp = document.getElementById('gate-forgot-otp-input');
                            if (inp) {
                                inp.value = data.otp_preview;
                                inp.focus();
                            }
                        };
                    }
                }

                setTimeout(() => document.getElementById('gate-forgot-otp-input')?.focus(), 100);
            } else {
                this.toast(data.message || 'Akun dengan username atau email tersebut tidak ditemukan.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Kirim Kode OTP Pemulihan';
            }
        }
    },

    async handleGateForgotReset() {
        const account = this.forgotOtpState.email || this.forgotOtpState.account;
        const otp_code = document.getElementById('gate-forgot-otp-input').value.trim();
        const new_password = document.getElementById('gate-forgot-new-pwd').value;
        const confirm_password = document.getElementById('gate-forgot-confirm-pwd').value;
        const btn = document.getElementById('gate-btn-submit-reset');

        if (!otp_code || !new_password) {
            this.toast('Kode OTP dan kata sandi baru wajib diisi.', 'error');
            return;
        }

        if (new_password.length < 6) {
            this.toast('Kata sandi baru minimal 6 karakter.', 'error');
            return;
        }

        if (new_password !== confirm_password) {
            this.toast('Konfirmasi kata sandi baru tidak cocok.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Mereset Kata Sandi...';
            }

            const res = await fetch('api/auth.php?action=reset_password_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ account, otp_code, new_password, confirm_password })
            });
            const data = await res.json();

            if (data.success && data.token && data.user) {
                this.token = data.token;
                this.currentUser = data.user;
                localStorage.setItem('cliento_token', data.token);
                localStorage.setItem('cliento_user', JSON.stringify(data.user));

                this.syncAuthGate();
                this.renderHeader();
                this.updateNavPermissions();
                this.toast('Kata sandi berhasil direset! Selamat datang kembali, ' + data.user.name, 'success');

                if (window.App) {
                    window.App.refreshDashboardStats();
                    window.App.navigateTo('dashboard');
                }
                if (window.ArchiveManager) window.ArchiveManager.loadCollectionsView();
            } else {
                this.toast(data.message || 'Gagal mereset kata sandi. Periksa kode OTP.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan reset sandi: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-key"></i> Simpan Kata Sandi Baru & Masuk';
            }
        }
    },

    // ----------------------------------------------------
    // FORGOT USERNAME / EMAIL / PENCARIAN AKUN USER
    // ----------------------------------------------------

    async handleGateForgotEmail() {
        const queryInput = document.getElementById('gate-forgot-email-query');
        const query = queryInput ? queryInput.value.trim() : '';
        const btn = document.getElementById('gate-btn-submit-forgot-email');
        const resultContainer = document.getElementById('gate-forgot-email-result');

        if (!query) {
            this.toast('Masukkan nomor WhatsApp, nama, atau username akun Anda.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Mencari Akun User...';
            }

            const res = await fetch('api/auth.php?action=forgot_account', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query })
            });
            const data = await res.json();

            if (data.success && data.accounts && data.accounts.length > 0) {
                this.toast(data.message, 'success');
                if (resultContainer) {
                    resultContainer.style.display = 'block';
                    resultContainer.innerHTML = `
                        <div style="font-size: 0.76rem; font-weight: 700; color: #0f172a; margin-bottom: 8px;">
                            <i class="fa-solid fa-circle-check text-success"></i> Akun User Ditemukan (${data.accounts.length}):
                        </div>
                        ${data.accounts.map(acc => `
                            <div class="found-account-card">
                                <div class="found-account-info">
                                    <div class="found-account-name"><strong>${this.escapeHtml(acc.name)}</strong></div>
                                    <div style="font-size: 0.74rem; color: #2563eb; margin: 2px 0;">
                                        <i class="fa-solid fa-at"></i> Username: <strong>${this.escapeHtml(acc.username || '-')}</strong>
                                    </div>
                                    <div class="found-account-email"><i class="fa-solid fa-envelope"></i> Email: ${this.escapeHtml(acc.masked_email)}</div>
                                    ${acc.phone ? `<div class="found-account-phone"><i class="fa-brands fa-whatsapp"></i> ${this.escapeHtml(acc.phone)}</div>` : ''}
                                </div>
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <button type="button" class="btn btn-outline btn-sm" onclick="Auth.useFoundEmail('${this.escapeHtml(acc.username || acc.full_email)}', 'login')" style="font-size: 0.7rem; padding: 4px 8px; border-radius: 6px;">
                                        <i class="fa-solid fa-arrow-right-to-bracket"></i> Masuk
                                    </button>
                                    <button type="button" class="btn btn-primary btn-sm" onclick="Auth.useFoundEmail('${this.escapeHtml(acc.username || acc.full_email)}', 'forgot_pwd')" style="font-size: 0.7rem; padding: 4px 8px; border-radius: 6px;">
                                        <i class="fa-solid fa-key"></i> Lupa Sandi
                                    </button>
                                </div>
                            </div>
                        `).join('')}
                    `;
                }
            } else {
                this.toast(data.message || 'Akun tidak ditemukan.', 'error');
                if (resultContainer) {
                    resultContainer.style.display = 'block';
                    resultContainer.innerHTML = `
                        <div style="font-size: 0.76rem; color: #dc2626; padding: 10px; background: #fef2f2; border-radius: 8px; border: 1px solid #fecaca;">
                            <i class="fa-solid fa-circle-xmark"></i> ${this.escapeHtml(data.message || 'Akun User tidak ditemukan.')}
                        </div>
                    `;
                }
            }
        } catch (e) {
            this.toast('Kesalahan pencarian: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-search"></i> Cari Akun Saya';
            }
        }
    },

    useFoundEmail(identifier, action) {
        if (action === 'forgot_pwd') {
            this.showGatePane('forgot');
            const accInput = document.getElementById('gate-forgot-email');
            if (accInput) {
                accInput.value = identifier;
            }
        } else {
            this.showGatePane('user-login');
            const accInput = document.getElementById('gate-user-login-account') || document.getElementById('gate-user-login-email');
            if (accInput) {
                accInput.value = identifier;
                document.getElementById('gate-user-login-password')?.focus();
            }
        }
    },

    // ----------------------------------------------------
    // PROFILE SETTINGS & GANTI EMAIL (OTP) / SANDI
    // ----------------------------------------------------

    openAdminSettingsModal() {
        if (!this.currentUser) {
            this.syncAuthGate();
            return;
        }

        const modal = document.getElementById('modal-admin-settings');
        if (!modal) return;

        // Hide admin-only tab if not admin
        const isAdmin = this.currentUser.role === 'admin';
        document.querySelectorAll('.settings-admin-tab').forEach(el => {
            el.style.display = isAdmin ? '' : 'none';
        });

        // Populate fields
        document.getElementById('admin-profile-name').value = this.currentUser.name || '';
        document.getElementById('admin-profile-email').value = this.currentUser.email || '';
        document.getElementById('admin-profile-phone').value = this.currentUser.phone || '';

        // Reset email change fields
        document.getElementById('change-new-email').value = '';
        document.getElementById('change-email-otp-input').value = '';
        document.getElementById('email-change-otp-container').style.display = 'none';

        // Reset password fields
        document.getElementById('admin-current-password').value = '';
        document.getElementById('admin-new-password').value = '';
        document.getElementById('admin-confirm-password').value = '';

        // Switch to profile tab
        document.querySelectorAll('.settings-tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelector('.settings-tab-btn[data-tab="profile"]')?.classList.add('active');
        document.querySelectorAll('.settings-tab-pane').forEach(p => p.classList.remove('active'));
        document.getElementById('tab-settings-profile')?.classList.add('active');

        // If admin, load system mail settings
        if (isAdmin) {
            this.loadSystemMailSettings();
        }

        modal.classList.add('active');
    },

    async handleUpdateProfile() {
        const name = document.getElementById('admin-profile-name').value.trim();
        const phone = document.getElementById('admin-profile-phone').value.trim();
        const btn = document.getElementById('btn-save-admin-profile');

        if (!name) {
            this.toast('Nama tidak boleh kosong.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Menyimpan...';
            }

            const res = await window.appFetch('api/auth.php?action=update_profile', {
                method: 'POST',
                body: JSON.stringify({ name, phone })
            });
            const data = await res.json();

            if (data.success && data.user) {
                this.currentUser = { ...this.currentUser, ...data.user };
                localStorage.setItem('cliento_user', JSON.stringify(this.currentUser));
                this.renderHeader();
                this.toast('Profil berhasil diperbarui!', 'success');
            } else {
                this.toast(data.message || 'Gagal menyimpan profil.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Profil';
            }
        }
    },

    async handleRequestEmailChangeOtp() {
        const new_email = document.getElementById('change-new-email').value.trim();
        const btn = document.getElementById('btn-request-email-otp');

        if (!new_email) {
            this.toast('Ketik alamat email baru Anda.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Mengirim OTP...';
            }

            const res = await window.appFetch('api/auth.php?action=request_email_change_otp', {
                method: 'POST',
                body: JSON.stringify({ new_email })
            });
            const data = await res.json();

            if (data.success) {
                this.emailChangeState.newEmail = new_email;
                document.getElementById('email-change-target-display').textContent = new_email;
                document.getElementById('email-change-otp-container').style.display = 'block';
                document.getElementById('change-email-otp-input').focus();
                this.toast(data.message || 'Kode OTP telah dikirimkan ke email baru Anda!', 'success');

                if (data.otp_preview) {
                    this.toast('Simulasi OTP: ' + data.otp_preview, 'info');
                    document.getElementById('change-email-otp-input').value = data.otp_preview;
                }
            } else {
                this.toast(data.message || 'Gagal meminta kode OTP.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Minta Kode OTP ke Email Baru';
            }
        }
    },

    async handleVerifyEmailChangeOtp() {
        const new_email = this.emailChangeState.newEmail;
        const otp_code = document.getElementById('change-email-otp-input').value.trim();
        const btn = document.getElementById('btn-submit-verify-email');

        if (!otp_code || otp_code.length < 6) {
            this.toast('Masukkan 6 digit kode OTP verifikasi.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Memproses...';
            }

            const res = await window.appFetch('api/auth.php?action=verify_email_change_otp', {
                method: 'POST',
                body: JSON.stringify({ new_email, otp_code })
            });
            const data = await res.json();

            if (data.success && data.user) {
                this.currentUser = { ...this.currentUser, ...data.user };
                localStorage.setItem('cliento_user', JSON.stringify(this.currentUser));
                this.renderHeader();
                document.getElementById('admin-profile-email').value = data.user.email;
                document.getElementById('email-change-otp-container').style.display = 'none';
                document.getElementById('change-new-email').value = '';

                this.toast(data.message || 'Alamat email berhasil diperbarui!', 'success');
            } else {
                this.toast(data.message || 'Kode OTP salah atau telah kadaluarsa.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan verifikasi email: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Verifikasi & Terapkan Email Baru';
            }
        }
    },

    async handleChangePassword() {
        const current_password = document.getElementById('admin-current-password').value;
        const new_password = document.getElementById('admin-new-password').value;
        const confirm_password = document.getElementById('admin-confirm-password').value;
        const btn = document.getElementById('btn-save-admin-password');

        if (!current_password) {
            this.toast('Kata sandi saat ini wajib diisi.', 'error');
            return;
        }
        if (new_password.length < 6) {
            this.toast('Kata sandi baru minimal 6 karakter.', 'error');
            return;
        }
        if (new_password !== confirm_password) {
            this.toast('Konfirmasi kata sandi baru tidak cocok.', 'error');
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
                : { current_password, new_password, confirm_password, name: this.currentUser.name };

            const res = await window.appFetch(endpoint, {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Kata sandi berhasil diubah!', 'success');
                document.getElementById('admin-current-password').value = '';
                document.getElementById('admin-new-password').value = '';
                document.getElementById('admin-confirm-password').value = '';
                document.getElementById('modal-admin-settings')?.classList.remove('active');
            } else {
                this.toast(data.message || 'Gagal mengubah kata sandi.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan ubah sandi: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-key"></i> Simpan Kata Sandi Baru';
            }
        }
    },

    // ----------------------------------------------------
    // EMAIL API CONFIGURATION (ADMIN ONLY)
    // ----------------------------------------------------

    async loadSystemMailSettings() {
        try {
            const res = await window.appFetch('api/settings.php?action=get_system_settings');
            const data = await res.json();

            if (data.success && data.settings) {
                const s = data.settings;
                const select = document.getElementById('mail-provider-select');
                if (select) {
                    select.value = s.smtp_provider || 'gmail_smtp';
                    select.dispatchEvent(new Event('change'));
                }

                if (document.getElementById('mail-smtp-user')) document.getElementById('mail-smtp-user').value = s.smtp_user || '';
                if (document.getElementById('mail-brevo-key')) document.getElementById('mail-brevo-key').value = s.brevo_api_key || '';
                if (document.getElementById('mail-resend-key')) document.getElementById('mail-resend-key').value = s.resend_api_key || '';
                if (document.getElementById('test-email-target')) document.getElementById('test-email-target').value = this.currentUser.email || '';
            }
        } catch (e) {
            console.error('Failed to load mail settings:', e);
        }
    },

    async handleSaveMailSettings() {
        const smtp_provider = document.getElementById('mail-provider-select').value;
        const smtp_user = document.getElementById('mail-smtp-user').value.trim();
        const smtp_pass = document.getElementById('mail-smtp-pass').value.trim();
        const brevo_api_key = document.getElementById('mail-brevo-key').value.trim();
        const resend_api_key = document.getElementById('mail-resend-key').value.trim();
        const btn = document.getElementById('btn-save-mail-settings');

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Menyimpan...';
            }

            const res = await window.appFetch('api/settings.php?action=save_system_settings', {
                method: 'POST',
                body: JSON.stringify({
                    smtp_provider,
                    smtp_user,
                    smtp_pass,
                    brevo_api_key,
                    resend_api_key,
                    smtp_from: smtp_user || 'no-reply@cliento.id'
                })
            });
            const data = await res.json();

            if (data.success) {
                this.toast('Konfigurasi email API berhasil disimpan!', 'success');
            } else {
                this.toast(data.message || 'Gagal menyimpan konfigurasi.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Simpan Konfigurasi Email';
            }
        }
    },

    async handleTriggerTestEmail() {
        const target_email = document.getElementById('test-email-target').value.trim();
        const btn = document.getElementById('btn-trigger-test-email');

        if (!target_email) {
            this.toast('Ketik alamat Gmail untuk pengujian.', 'error');
            return;
        }

        try {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Mengirim...';
            }

            const res = await window.appFetch('api/settings.php?action=test_email', {
                method: 'POST',
                body: JSON.stringify({ target_email })
            });
            const data = await res.json();

            if (data.success) {
                this.toast(data.message || 'Email uji coba berhasil dikirim ke ' + target_email, 'success');
            } else {
                this.toast(data.message || 'Uji coba gagal terkirim.', 'error');
            }
        } catch (e) {
            this.toast('Kesalahan pengujian: ' + e.message, 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Kirim Test';
            }
        }
    },

    async logout() {
        const wasAdmin = this.currentUser && this.currentUser.role === 'admin';
        try {
            await window.appFetch('api/auth.php?action=logout', { method: 'POST' });
        } catch (e) {}

        this.clearSession();
        if (wasAdmin) {
            window.location.href = 'admin.html';
        } else {
            this.toast('Anda telah berhasil keluar dari akun.', 'info');
        }
    },

    // ----------------------------------------------------
    // TOAST & UTILITIES
    // ----------------------------------------------------

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
