/**
 * Client Reach AI - Workflow AI Sales
 * Core Single Page Application Router, Chart.js Dashboard Engine, and State Manager
 */

const App = {
    activePage: 'dashboard',
    charts: {},

    init() {
        this.bindNavigation();
        this.bindModalEvents();
        this.initModules();
        this.initDashboardCharts();
        this.refreshDashboardStats();

        // Listen for browser hash changes
        window.addEventListener('hashchange', () => {
            const page = window.location.hash.replace('#', '') || 'dashboard';
            this.navigateTo(page);
        });

        // Initial route
        const initialPage = window.location.hash.replace('#', '') || 'dashboard';
        this.navigateTo(initialPage);
    },

    bindNavigation() {
        // Desktop nav links
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const page = link.getAttribute('data-page');
                this.navigateTo(page);
            });
        });

        // Mobile bottom nav items
        document.querySelectorAll('.mobile-nav-item').forEach(item => {
            item.addEventListener('click', (e) => {
                e.preventDefault();
                const page = item.getAttribute('data-page');
                this.navigateTo(page);
            });
        });

        // Quick action buttons
        document.querySelectorAll('[data-goto]').forEach(btn => {
            btn.addEventListener('click', () => {
                const target = btn.getAttribute('data-goto');
                if (target) this.navigateTo(target);
            });
        });
    },

    navigateTo(pageId) {
        const validPages = ['dashboard', 'scraper', 'templates', 'archives', 'customers'];
        if (!validPages.includes(pageId)) pageId = 'dashboard';

        // Protected Admin View: customers
        if (pageId === 'customers') {
            if (!window.Auth || !window.Auth.currentUser || window.Auth.currentUser.role !== 'admin') {
                if (window.Auth) {
                    window.Auth.syncAuthGate();
                    window.Auth.setPortal('admin');
                    window.Auth.toast('Silakan masuk sebagai Administrator untuk mengakses Database Customer.', 'warning');
                }
                return;
            }
        }

        this.activePage = pageId;
        window.location.hash = pageId;

        // Toggle page views
        document.querySelectorAll('.page-view').forEach(view => {
            view.classList.toggle('active', view.id === `view-${pageId}`);
        });

        // Toggle nav active states
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.toggle('active', link.getAttribute('data-page') === pageId);
        });

        document.querySelectorAll('.mobile-nav-item').forEach(item => {
            item.classList.toggle('active', item.getAttribute('data-page') === pageId);
        });

        // Invalidate map size if scraper
        if (pageId === 'scraper' && window.mapEngine && window.mapEngine.map) {
            setTimeout(() => {
                window.mapEngine.map.invalidateSize();
            }, 200);
        }

        // If navigating to archives, refresh collections view
        if (pageId === 'archives' && window.ArchiveManager) {
            window.ArchiveManager.loadCollectionsView();
        }

        // If navigating to customers, refresh customer list & stats
        if (pageId === 'customers' && window.CustomerManager) {
            window.CustomerManager.loadCustomers();
            window.CustomerManager.loadStats();
        }

        // If navigating to harvest, refresh queue & stats
        if (pageId === 'harvest' && window.HarvestManager) {
            window.HarvestManager.onPageEnter();
        }

        // If navigating to dashboard, refresh charts & stats
        if (pageId === 'dashboard') {
            this.refreshDashboardStats();
            this.updateChartsData();
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    },

    initModules() {
        if (window.mapEngine) window.mapEngine.init();
        if (window.TemplateManager) window.TemplateManager.init();
        if (window.ArchiveManager) window.ArchiveManager.init();
        if (window.ScraperClient) window.ScraperClient.init();
        if (window.HarvestManager) window.HarvestManager.init();
    },

    // ----------------------------------------------------
    // CHART.JS DASHBOARD INITIALIZATION
    // ----------------------------------------------------
    initDashboardCharts() {
        if (typeof Chart === 'undefined') return;

        // Chart 1: Sales Funnel Area Line Chart
        const funnelCtx = document.getElementById('chart-sales-funnel')?.getContext('2d');
        if (funnelCtx) {
            this.charts.funnel = new Chart(funnelCtx, {
                type: 'line',
                data: {
                    labels: ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'],
                    datasets: [
                        {
                            label: 'Leads Scraped',
                            data: [45, 62, 58, 85, 95, 78, 110],
                            borderColor: '#2563eb',
                            backgroundColor: 'rgba(37, 99, 235, 0.08)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2.5,
                            pointRadius: 3
                        },
                        {
                            label: 'Chat WA Terkirim',
                            data: [28, 40, 35, 54, 60, 48, 72],
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.05)',
                            fill: true,
                            tension: 0.35,
                            borderWidth: 2,
                            pointRadius: 3
                        },
                        {
                            label: 'Prospek Positif (Closing)',
                            data: [8, 12, 11, 19, 22, 16, 26],
                            borderColor: '#f59e0b',
                            backgroundColor: 'transparent',
                            tension: 0.35,
                            borderWidth: 2,
                            borderDash: [4, 4],
                            pointRadius: 3
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: {
                                font: { family: 'Poppins', size: 11, weight: '600' },
                                boxWidth: 12,
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            padding: 10,
                            titleFont: { family: 'Poppins', weight: '700' },
                            bodyFont: { family: 'Poppins' }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: '#f1f5f9' },
                            ticks: { font: { family: 'Poppins', size: 10 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { font: { family: 'Poppins', size: 10 } }
                        }
                    }
                }
            });
        }

        // Chart 2: Category Distribution Donut Chart
        const donutCtx = document.getElementById('chart-category-donut')?.getContext('2d');
        if (donutCtx) {
            this.charts.donut = new Chart(donutCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Cafe & Kopi', 'Sekolah & Edu', 'Restoran & Kuliner', 'Klinik & RS', 'Hotel', 'Lainnya'],
                    datasets: [{
                        data: [35, 22, 18, 12, 8, 5],
                        backgroundColor: ['#2563eb', '#10b981', '#f59e0b', '#ec4899', '#8b5cf6', '#94a3b8'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '70%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                font: { family: 'Poppins', size: 10, weight: '500' },
                                boxWidth: 10,
                                usePointStyle: true
                            }
                        }
                    }
                }
            });
        }
    },

    updateChartsData() {
        if (this.charts.funnel) this.charts.funnel.update();
        if (this.charts.donut) this.charts.donut.update();
    },

    async refreshDashboardStats() {
        let totalScraped = 0;
        let totalProspects = 0;
        let totalArchives = 0;

        try {
            const isStaticHost = window.location.hostname.includes('github.io') || window.location.protocol === 'file:';
            if (!isStaticHost) {
                const fetchFn = window.appFetch || fetch;
                const resArc = await fetchFn('api/archives.php?action=get_view');
                if (resArc.ok) {
                    const dataArc = await resArc.json();
                    totalProspects = dataArc.total_prospects || 0;
                    totalArchives = dataArc.total_system_archives || 0;
                }

                const resHist = await fetchFn('api/history.php?action=list');
                if (resHist.ok) {
                    const dataHist = await resHist.json();
                    if (dataHist.success && dataHist.history) {
                        totalScraped = dataHist.history.reduce((acc, h) => acc + (parseInt(h.total_found) || 0), 0);
                    }
                }
            }
        } catch (e) {
            console.warn('Backend unavailable, using local stats:', e);
        }

        // Static host / offline fallback
        if (totalScraped === 0 && totalArchives === 0) {
            try {
                const staticHistory = JSON.parse(localStorage.getItem('cliento_static_history') || '[]');
                totalScraped = staticHistory.reduce((acc, h) => acc + (parseInt(h.total_found) || 0), 0);

                const staticArchives = JSON.parse(localStorage.getItem('cliento_static_archives') || '[]');
                totalArchives = staticArchives.length;
                totalProspects = staticArchives.reduce((acc, a) => {
                    const p = (a.items || []).filter(i => i.status === 'prospect').length;
                    return acc + p;
                }, 0);
            } catch(e) {}
        }

        const elScraped = document.getElementById('stat-total-scraped');
        const elProspects = document.getElementById('stat-total-prospects');
        const elArchives = document.getElementById('stat-total-archives');
        const elConnected = document.getElementById('stat-total-connected');

        if (elScraped) elScraped.textContent = totalScraped.toLocaleString('id-ID');
        if (elProspects) elProspects.textContent = totalProspects.toLocaleString('id-ID');
        if (elArchives) elArchives.textContent = totalArchives.toLocaleString('id-ID');
        if (elConnected) elConnected.textContent = (totalProspects * 2).toLocaleString('id-ID');
    },

    bindModalEvents() {
        document.querySelectorAll('.modal-backdrop').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });

            modal.querySelectorAll('.btn-close-modal').forEach(closeBtn => {
                closeBtn.addEventListener('click', () => {
                    modal.classList.remove('active');
                });
            });
        });

        const btnConfirmSave = document.getElementById('btn-confirm-save-archive');
        if (btnConfirmSave) {
            btnConfirmSave.addEventListener('click', () => {
                if (window.ScraperClient) window.ScraperClient.confirmSaveArchive();
            });
        }
    }
};

document.addEventListener('DOMContentLoaded', () => {
    App.init();
});

window.App = App;
