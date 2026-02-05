-- =============================================================================
-- CREATION DE LA BD pour dashboard
-- =============================================================================

-- Nettoyage (si besoin de tout réinitialiser)
-- DROP TABLE IF EXISTS temps_week CASCADE;
-- DROP TABLE IF EXISTS temps_niveau CASCADE;
-- DROP TABLE IF EXISTS progression CASCADE;
-- DROP TABLE IF EXISTS students CASCADE;

-- =============================================================================
-- TABLE 1 : STUDENTS (Utilisateurs)
-- =============================================================================
CREATE TABLE students (
  id SERIAL PRIMARY KEY,
  user_id VARCHAR(100) UNIQUE NOT NULL,
  email VARCHAR(255),
  username VARCHAR(255),
  tags TEXT,
  created_at TIMESTAMP WITH TIME ZONE,
  last_login_at TIMESTAMP WITH TIME ZONE,
  date_maj TIMESTAMP WITH TIME ZONE DEFAULT NOW(),

-- Indexes pour optimiser les recherches
CONSTRAINT students_user_id_unique UNIQUE (user_id) );

CREATE INDEX idx_students_user_id ON students (user_id);

CREATE INDEX idx_students_email ON students (email);

CREATE INDEX idx_students_date_maj ON students (date_maj);

COMMENT ON
TABLE students IS 'Utilisateurs synchronisés depuis LearnWorlds API';

COMMENT ON COLUMN students.user_id IS 'ID unique depuis LearnWorlds';

COMMENT ON COLUMN students.tags IS 'Tags séparés par virgules';

-- =============================================================================
-- TABLE 2 : PROGRESSION (Score d'avancement par niveau en %)
-- =============================================================================

CREATE TABLE progression (
  id SERIAL PRIMARY KEY,
  student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,

-- Progressions par niveau (en %)
"6eme" DECIMAL(5, 2) DEFAULT 0.00,
"5eme" DECIMAL(5, 2) DEFAULT 0.00,
"4eme" DECIMAL(5, 2) DEFAULT 0.00,
"3eme" DECIMAL(5, 2) DEFAULT 0.00,
"2nde" DECIMAL(5, 2) DEFAULT 0.00,
"1ere" DECIMAL(5, 2) DEFAULT 0.00,
term DECIMAL(5, 2) DEFAULT 0.00,
"term-pc" DECIMAL(5, 2) DEFAULT 0.00,
date_maj TIMESTAMP
WITH
    TIME ZONE DEFAULT NOW(),

-- Contrainte : un seul enregistrement par élève
CONSTRAINT progression_student_unique UNIQUE (student_id) );

CREATE INDEX idx_progression_student ON progression (student_id);

CREATE INDEX idx_progression_date_maj ON progression (date_maj);

COMMENT ON
TABLE progression IS 'Score d''avancement par niveau (0-100%)';

COMMENT ON COLUMN progression."6eme" IS 'Progression en % pour le niveau 6ème';

-- =============================================================================
-- TABLE 3 : TEMPS_NIVEAU (Temps total d'apprentissage par niveau)
-- =============================================================================

CREATE TABLE temps_niveau (
  id SERIAL PRIMARY KEY,
  student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,

-- Temps total par niveau (en secondes)
"6eme" INTEGER DEFAULT 0,
"5eme" INTEGER DEFAULT 0,
"4eme" INTEGER DEFAULT 0,
"3eme" INTEGER DEFAULT 0,
"2nde" INTEGER DEFAULT 0,
"1ere" INTEGER DEFAULT 0,
term INTEGER DEFAULT 0,
"term-pc" INTEGER DEFAULT 0,
date_maj TIMESTAMP
WITH
    TIME ZONE DEFAULT NOW(),

-- Contrainte : un seul enregistrement par élève
CONSTRAINT temps_niveau_student_unique UNIQUE (student_id) );

CREATE INDEX idx_temps_niveau_student ON temps_niveau (student_id);

CREATE INDEX idx_temps_niveau_date_maj ON temps_niveau (date_maj);

COMMENT ON
TABLE temps_niveau IS 'Temps total d''apprentissage cumulé par niveau (en secondes)';

COMMENT ON COLUMN temps_niveau."6eme" IS 'Temps total en secondes pour le niveau 6ème';

-- =============================================================================
-- TABLE 4 : TEMPS_WEEK (Temps d'apprentissage hebdomadaire par niveau)
-- =============================================================================

CREATE TABLE temps_week (
  id SERIAL PRIMARY KEY,
  student_id INTEGER NOT NULL REFERENCES students(id) ON DELETE CASCADE,
  semaine VARCHAR(10) NOT NULL,  -- Format: "2025-W01", "2025-W02", etc.

-- Temps de la semaine par niveau (en secondes)
"6eme" INTEGER DEFAULT 0,
"5eme" INTEGER DEFAULT 0,
"4eme" INTEGER DEFAULT 0,
"3eme" INTEGER DEFAULT 0,
"2nde" INTEGER DEFAULT 0,
"1ere" INTEGER DEFAULT 0,
term INTEGER DEFAULT 0,
"term-pc" INTEGER DEFAULT 0,

-- Temps cumulé total (en secondes)
cumul_6eme INTEGER DEFAULT 0,
cumul_5eme INTEGER DEFAULT 0,
cumul_4eme INTEGER DEFAULT 0,
cumul_3eme INTEGER DEFAULT 0,
cumul_2nde INTEGER DEFAULT 0,
cumul_1ere INTEGER DEFAULT 0,
cumul_term INTEGER DEFAULT 0,
"cumul_term-pc" INTEGER DEFAULT 0,

-- Dates de début et fin de semaine
debute_le TIMESTAMP
WITH
    TIME ZONE,
    finit_le TIMESTAMP
WITH
    TIME ZONE,
    date_maj TIMESTAMP
WITH
    TIME ZONE DEFAULT NOW(),

-- Contrainte : un seul enregistrement par élève par semaine
CONSTRAINT temps_week_student_week_unique UNIQUE (student_id, semaine)
);

CREATE INDEX idx_temps_week_student ON temps_week (student_id);

CREATE INDEX idx_temps_week_semaine ON temps_week (semaine);

CREATE INDEX idx_temps_week_student_semaine ON temps_week (student_id, semaine);

CREATE INDEX idx_temps_week_date_maj ON temps_week (date_maj);

COMMENT ON
TABLE temps_week IS 'Temps d''apprentissage hebdomadaire et cumulé par niveau';

COMMENT ON COLUMN temps_week.semaine IS 'Semaine au format ISO (ex: 2025-W01)';

COMMENT ON COLUMN temps_week."6eme" IS 'Temps de cette semaine en secondes pour 6ème';

COMMENT ON COLUMN temps_week.cumul_6eme IS 'Temps total cumulé en secondes pour 6ème';

-- =============================================================================
-- VUE : students_with_email (Pour faciliter les requêtes)
-- Ajoute l'email directement accessible depuis student_id
-- =============================================================================

CREATE VIEW students_with_progress AS
SELECT 
  s.id as student_id,
  s.user_id,
  s.email,
  s.username,
  s.tags,
  s.created_at,
  s.last_login_at,
  p."6eme" as prog_6eme,
  p."5eme" as prog_5eme,
  p."4eme" as prog_4eme,
  p."3eme" as prog_3eme,
  p."2nde" as prog_2nde,
  p."1ere" as prog_1ere,
  p.term as prog_term,
  p."term-pc" as prog_term_pc,
  tn."6eme" as temps_6eme,
  tn."5eme" as temps_5eme,
  tn."4eme" as temps_4eme,
  tn."3eme" as temps_3eme,
  tn."2nde" as temps_2nde,
  tn."1ere" as temps_1ere,
  tn.term as temps_term,
  tn."term-pc" as temps_term_pc
FROM students s
LEFT JOIN progression p ON s.id = p.student_id
LEFT JOIN temps_niveau tn ON s.id = tn.student_id;

COMMENT ON VIEW students_with_progress IS 'Vue complète : élèves avec progression et temps total';

-- =============================================================================
-- FONCTION UTILE : Convertir secondes en format lisible
-- =============================================================================

CREATE OR REPLACE FUNCTION seconds_to_hours(seconds INTEGER)
RETURNS VARCHAR AS $$
BEGIN
  IF seconds < 60 THEN
    RETURN seconds || 'sec';
  ELSIF seconds < 3600 THEN
    RETURN FLOOR(seconds / 60) || 'min';
  ELSE
    RETURN FLOOR(seconds / 3600) || 'h' || LPAD(FLOOR((seconds % 3600) / 60)::TEXT, 2, '0');
  END IF;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

COMMENT ON FUNCTION seconds_to_hours IS 'Convertit des secondes en format lisible (ex: 3665s → 1h01)';

-- =============================================================================
-- FONCTION UTILE : Obtenir le numéro de semaine ISO
-- =============================================================================

CREATE OR REPLACE FUNCTION get_iso_week(date_input TIMESTAMP WITH TIME ZONE)
RETURNS VARCHAR AS $$
BEGIN
  RETURN TO_CHAR(date_input, 'IYYY-"W"IW');
END;
$$ LANGUAGE plpgsql IMMUTABLE;

COMMENT ON FUNCTION get_iso_week IS 'Retourne la semaine ISO (ex: 2025-W01)';

-- =============================================================================
-- FONCTION UTILE : Obtenir lundi et dimanche d'une semaine ISO
-- =============================================================================

CREATE OR REPLACE FUNCTION get_week_range(iso_week VARCHAR)
RETURNS TABLE(monday TIMESTAMP WITH TIME ZONE, sunday TIMESTAMP WITH TIME ZONE) AS $$
DECLARE
  year_part INTEGER;
  week_part INTEGER;
  jan4 DATE;
  week1_start DATE;
  target_monday DATE;
BEGIN
  -- Extraire l'année et le numéro de semaine
  year_part := SPLIT_PART(iso_week, '-W', 1)::INTEGER;
  week_part := SPLIT_PART(iso_week, '-W', 2)::INTEGER;
  
  -- Calculer le lundi de la semaine 1 (première semaine contenant le 4 janvier)
  jan4 := (year_part || '-01-04')::DATE;
  week1_start := jan4 - (EXTRACT(ISODOW FROM jan4)::INTEGER - 1);
  
  -- Calculer le lundi de la semaine cible
  target_monday := week1_start + ((week_part - 1) * 7);
  
  -- Retourner le lundi à 00:00 et le dimanche à 23:59:59
  RETURN QUERY SELECT 
    target_monday::TIMESTAMP WITH TIME ZONE,
    (target_monday + INTERVAL '6 days 23 hours 59 minutes 59 seconds')::TIMESTAMP WITH TIME ZONE;
END;
$$ LANGUAGE plpgsql IMMUTABLE;

COMMENT ON FUNCTION get_week_range IS 'Retourne le lundi et dimanche d''une semaine ISO';

-- =============================================================================
-- TRIGGER : Mise à jour automatique de date_maj
-- =============================================================================

CREATE OR REPLACE FUNCTION update_date_maj()
RETURNS TRIGGER AS $$
BEGIN
  NEW.date_maj = NOW();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Appliquer le trigger sur toutes les tables
CREATE TRIGGER students_update_date_maj
  BEFORE UPDATE ON students
  FOR EACH ROW
  EXECUTE FUNCTION update_date_maj();

CREATE TRIGGER progression_update_date_maj
  BEFORE UPDATE ON progression
  FOR EACH ROW
  EXECUTE FUNCTION update_date_maj();

CREATE TRIGGER temps_niveau_update_date_maj
  BEFORE UPDATE ON temps_niveau
  FOR EACH ROW
  EXECUTE FUNCTION update_date_maj();

CREATE TRIGGER temps_week_update_date_maj
  BEFORE UPDATE ON temps_week
  FOR EACH ROW
  EXECUTE FUNCTION update_date_maj();

-- =============================================================================
-- FIN DU SCRIPT
-- =============================================================================

-- À exécuter dans Supabase SQL Editor
ALTER TABLE students ADD COLUMN is_enrolled BOOLEAN DEFAULT true;

CREATE INDEX idx_students_enrolled ON students (is_enrolled);

COMMENT ON COLUMN students.is_enrolled IS 'Indique si l''utilisateur est inscrit à au moins un cours';