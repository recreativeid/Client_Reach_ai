/**
 * cliento (sales intelligence) - Supabase Integration Client
 * Provides optional client-side Supabase Database & Auth connector.
 */
const SupabaseService = {
    // Supabase Cloud Project URL
    supabaseUrl: window.CLIENTO_SUPABASE_URL || 'https://idvxwrpifquqnekzjtxo.supabase.co',
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
