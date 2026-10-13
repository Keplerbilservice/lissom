-- godkjent av eieren: 2026-10-13
--
-- Tekstpakken fra 13. oktober 2026 (lissom-tekstendringer.md), den delen som
-- ligger i basen. Eieren godkjente hvert punkt, ett og ett, samme dag.
--
--   1. Hentetid «noen uker» paa alle kurs der man former leire — i kursets
--      egen «Naar er den ferdig» og i standardtekstene per kategori. Paint on
--      Pots beholder «tre uker» (brennes én gang), og roeres ikke.
--   2. Haandbygging: «… og det er klart etter to til fire uker.» → noen uker.
--   3. To nyhetsartikler: «tre uker etter siste kveld» / «tre uker etter
--      kurset» → noen uker. Paint on Pots-artikkelen staar.
--   4. De fire innholdstekstene, der de er lagret med egen verdi.
--   5. Basis 30: «deg som deg som» → «deg som», og punktum til slutt.
--   6. Dreiekurset: «Første kveld …, andre kveld …» → «gang», merket
--      «Nybegynner», og første setning i «Med hjem».
--
-- Bare de noeyaktige setningene byttes, med REPLACE. Er de alt byttet, skjer
-- ingenting — kan kjoeres flere ganger. Mangler en tabell eller kolonne,
-- hoppes den delen over (SELECT 1).

-- ── 1. Hentetid i kursets eget felt (ikke Paint on Pots) ─────────────
SET @har := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'ferdig_tid');
SET @sql := IF(@har > 0,
  'UPDATE courses
      SET ferdig_tid = REPLACE(REPLACE(REPLACE(REPLACE(ferdig_tid,
            ''henting etter tre uker'', ''henting etter noen uker''),
            ''to til fire uker'', ''noen uker''),
            ''2–4 uker'', ''noen uker''),
            ''2-4 uker'', ''noen uker'')
    WHERE (ferdig_tid LIKE ''%henting etter tre uker%'' OR ferdig_tid LIKE ''%to til fire uker%''
           OR ferdig_tid LIKE ''%2–4 uker%'' OR ferdig_tid LIKE ''%2-4 uker%'')
      AND COALESCE(slug, '''') <> ''paint-on-pots''
      AND LOWER(tittel) NOT LIKE ''%paint on pots%''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 1b. Standardtekstene per kategori (Kursoppsett › Standardtekst) ──
-- Paint on Pots faar «tre uker» tilbake i koden (Kursmal::forKurs), ogsaa
-- naar Events-standarden sier «noen uker».
SET @har := (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'innstillinger');
SET @sql := IF(@har > 0,
  'UPDATE innstillinger
      SET verdi = REPLACE(REPLACE(REPLACE(REPLACE(verdi,
            ''henting etter tre uker'', ''henting etter noen uker''),
            ''to til fire uker'', ''noen uker''),
            ''2–4 uker'', ''noen uker''),
            ''2-4 uker'', ''noen uker'')
    WHERE nokkel = ''kurs_standardtekster''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 2. Haandbygging: «det er klart etter to til fire uker» ───────────
SET @har := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'med_hjem');
SET @sql := IF(@har > 0,
  'UPDATE courses
      SET med_hjem = REPLACE(med_hjem, ''og det er klart etter to til fire uker'', ''og det er klart etter noen uker''),
          beskrivelse = REPLACE(beskrivelse, ''og det er klart etter to til fire uker'', ''og det er klart etter noen uker''),
          kort_beskrivelse = REPLACE(kort_beskrivelse, ''og det er klart etter to til fire uker'', ''og det er klart etter noen uker'')
    WHERE (med_hjem LIKE ''%og det er klart etter to til fire uker%''
           OR beskrivelse LIKE ''%og det er klart etter to til fire uker%''
           OR kort_beskrivelse LIKE ''%og det er klart etter to til fire uker%'')
      AND COALESCE(slug, '''') <> ''paint-on-pots''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 3. De to nyhetsartiklene ──────────────────────────────────────────
SET @har := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'articles' AND column_name = 'slug');
SET @sql := IF(@har > 0,
  'UPDATE articles
      SET innhold = REPLACE(REPLACE(innhold,
            ''tre uker etter siste kveld'', ''noen uker etter siste kveld''),
            ''tre uker etter kurset'', ''noen uker etter kurset'')
    WHERE slug IN (''dreiekurs-i-tonsberg-hva-skjer'', ''forste-gang-pa-keramikkurs'')',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 4. Innholdstekstene, der de er lagret med egen verdi ─────────────
-- Uten rad gjelder standardteksten i koden, som alt er endret.
SET @har := (SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = 'content_blocks');
SET @sql := IF(@har > 0,
  'UPDATE content_blocks
      SET verdi = REPLACE(verdi, ''Det tar tre uker før keramikken'', ''Det tar noen uker før keramikken'')
    WHERE nokkel = ''Spørsmål og svar/0/Svar 2''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@har > 0,
  'UPDATE content_blocks
      SET verdi = REPLACE(verdi, ''klart til henting etter tre uker'', ''klart til henting etter noen uker'')
    WHERE nokkel = ''Bedrift og event/1/Kort 2 tekst''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@har > 0,
  'UPDATE content_blocks
      SET verdi = REPLACE(verdi,
            ''Abonnementet løper fra måned til måned og kan sies opp når du vil.'',
            ''Prøv Lissom har ingen binding. Mini 15 og Basis 30 har to måneders binding, deretter én måneds oppsigelse. Årsmedlemskap har tolv måneders binding.'')
    WHERE nokkel = ''Medlemskap/0/Ingress''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@har > 0,
  'UPDATE content_blocks
      SET verdi = REPLACE(verdi, ''Datoene og prisene finner du under Kurs.'', ''Datoene finner du under Kurs.'')
    WHERE nokkel = ''Kursene våre/0/Ingress''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 5. Basis 30: «deg som deg som» ────────────────────────────────────
SET @har := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'membership_plans' AND column_name = 'beskrivelse');
SET @sql := IF(@har > 0,
  'UPDATE membership_plans
      SET beskrivelse = CONCAT(
            REPLACE(TRIM(beskrivelse), ''for deg som deg som bruker'', ''for deg som bruker''),
            IF(TRIM(beskrivelse) LIKE ''%komme og gå fritt'', ''.'', ''''))
    WHERE beskrivelse LIKE ''%for deg som deg som bruker%''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── 6. Dreiekurset ────────────────────────────────────────────────────
-- «kveld» → «gang» i den ene setningen, der den staar (beskrivelse,
-- kortbeskrivelse og SEO-ingressen). Dobbelt mellomrom foer «å trimme»
-- rettes med.
SET @har := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'kort_beskrivelse');
SET @sql := IF(@har > 0,
  'UPDATE courses
      SET beskrivelse = REPLACE(REPLACE(beskrivelse,
            ''Første kveld lærer du å sentrere og dreie, andre kveld lærer du  å trimme foten'', ''Første gang lærer du å sentrere og dreie, andre gang lærer du å trimme foten''),
            ''Første kveld lærer du å sentrere og dreie, andre kveld lærer du å trimme foten'', ''Første gang lærer du å sentrere og dreie, andre gang lærer du å trimme foten''),
          kort_beskrivelse = REPLACE(REPLACE(kort_beskrivelse,
            ''Første kveld lærer du å sentrere og dreie, andre kveld lærer du  å trimme foten'', ''Første gang lærer du å sentrere og dreie, andre gang lærer du å trimme foten''),
            ''Første kveld lærer du å sentrere og dreie, andre kveld lærer du å trimme foten'', ''Første gang lærer du å sentrere og dreie, andre gang lærer du å trimme foten'')
    WHERE beskrivelse LIKE ''%Første kveld lærer du å sentrere og dreie, andre kveld lærer du%''
       OR kort_beskrivelse LIKE ''%Første kveld lærer du å sentrere og dreie, andre kveld lærer du%''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @har := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'seo_ingress');
SET @sql := IF(@har > 0,
  'UPDATE courses
      SET seo_ingress = REPLACE(REPLACE(seo_ingress,
            ''Første kveld lærer du å sentrere og dreie, andre kveld lærer du  å trimme foten'', ''Første gang lærer du å sentrere og dreie, andre gang lærer du å trimme foten''),
            ''Første kveld lærer du å sentrere og dreie, andre kveld lærer du å trimme foten'', ''Første gang lærer du å sentrere og dreie, andre gang lærer du å trimme foten'')
    WHERE seo_ingress LIKE ''%Første kveld lærer du å sentrere og dreie, andre kveld lærer du%''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Merket oeverst: «Nybegynner» paa dreiekurset, bare det (eieren valgte
-- kursets eget felt, ikke malen for alle dreiekurs).
SET @har := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'nivaa_tekst');
SET @sql := IF(@har > 0,
  'UPDATE courses SET nivaa_tekst = ''Nybegynner'' WHERE slug = ''dreiekurs''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- «Med hjem»: første setning, der kurset har sin egen tekst. Uten egen
-- tekst gjelder malen (app/lib/kursmal.php), som er endret likt.
SET @har := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'courses' AND column_name = 'med_hjem');
SET @sql := IF(@har > 0,
  'UPDATE courses
      SET med_hjem = REPLACE(med_hjem, ''Du får med deg kreasjonen du klarer å lage på dreieskiven.'', ''Det du lager på dreieskiven, ferdig glasert og brent.'')
    WHERE med_hjem LIKE ''%Du får med deg kreasjonen du klarer å lage på dreieskiven.%''',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
