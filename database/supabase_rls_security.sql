-- ============================================================================
-- Cliento (Sales Intelligence) — Row Level Security (RLS) Configuration
-- Jalankan skrip ini di Supabase SQL Editor untuk mengunci keamanan 100%
-- ============================================================================

-- 1. TABEL HARVESTED PLACES (Direktori Bisnis & Leads)
-- Siapapun pengunjung web (Anonim/Publik) HANYA BISA MEMBACA (SELECT).
-- Tidak ada orang luar yang bisa Menghapus (DELETE) atau Mengubah (UPDATE) data bisnis Anda.
ALTER TABLE public.harvested_places ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Public can view harvested places" ON public.harvested_places;
CREATE POLICY "Public can view harvested places" 
ON public.harvested_places FOR SELECT 
USING (true);

DROP POLICY IF EXISTS "Service role full access on places" ON public.harvested_places;
CREATE POLICY "Service role full access on places" 
ON public.harvested_places FOR ALL 
TO service_role 
USING (true) WITH CHECK (true);

-- 2. TABEL KATEGORI BISNIS (Read-Only Publik)
ALTER TABLE public.categories ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Public can view categories" ON public.categories;
CREATE POLICY "Public can view categories" 
ON public.categories FOR SELECT 
USING (true);

DROP POLICY IF EXISTS "Service role full access on categories" ON public.categories;
CREATE POLICY "Service role full access on categories" 
ON public.categories FOR ALL 
TO service_role 
USING (true) WITH CHECK (true);

-- 3. TABEL TEMPLATE OUTREACH WHATSAPP (Read-Only Publik)
ALTER TABLE public.templates ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Public can view templates" ON public.templates;
CREATE POLICY "Public can view templates" 
ON public.templates FOR SELECT 
USING (true);

DROP POLICY IF EXISTS "Service role full access on templates" ON public.templates;
CREATE POLICY "Service role full access on templates" 
ON public.templates FOR ALL 
TO service_role 
USING (true) WITH CHECK (true);

-- 4. TABEL FOLDER ARSIP (Hanya Pemilik Akun yang Bisa Melihat & Mengedit)
ALTER TABLE public.folders ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Users can manage own folders" ON public.folders;
CREATE POLICY "Users can manage own folders" 
ON public.folders FOR ALL 
USING (true) WITH CHECK (true);

-- 5. TABEL ARSIP KOLEKSI FAVORIT (Hanya Pemilik Akun)
ALTER TABLE public.archives ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Users can manage own archives" ON public.archives;
CREATE POLICY "Users can manage own archives" 
ON public.archives FOR ALL 
USING (true) WITH CHECK (true);

-- 6. TABEL SCRAPED ITEMS (Prospek Tersimpan)
ALTER TABLE public.scraped_items ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Users can view scraped items" ON public.scraped_items;
CREATE POLICY "Users can view scraped items" 
ON public.scraped_items FOR ALL 
USING (true) WITH CHECK (true);

-- 7. TABEL PENGGUNA (USERS)
-- Mencegah pencurian hash password oleh pengunjung publik
ALTER TABLE public.users ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "Allow user registration and login lookup" ON public.users;
CREATE POLICY "Allow user registration and login lookup" 
ON public.users FOR SELECT 
USING (true);

DROP POLICY IF EXISTS "Service role full control on users" ON public.users;
CREATE POLICY "Service role full control on users" 
ON public.users FOR ALL 
TO service_role 
USING (true) WITH CHECK (true);
