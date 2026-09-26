/**
 * cliento (sales intelligence) - Supabase Integration Client
 * Provides optional client-side Supabase Database & Auth connector.
 */
const SupabaseService = {
    // Ganti dengan kredensial Supabase Anda jika ingin mengakses langsung dari frontend
    supabaseUrl: window.CLIENTO_SUPABASE_URL || '',
    supabaseKey: window.CLIENTO_SUPABASE_KEY || '',
    client: null,

    init() {
        if (this.supabaseUrl && this.supabaseKey && window.supabase) {
            try {
                this.client = window.supabase.createClient(this.supabaseUrl, this.supabaseKey);
                console.log('⚡ Supabase Cloud Client Initialized successfully.');
            } catch (err) {
                console.warn('Supabase initialization error:', err);
            }
        }
    },

    isConfigured() {
        return !!this.client;
    }
};

document.addEventListener('DOMContentLoaded', () => {
    SupabaseService.init();
});

window.SupabaseService = SupabaseService;
